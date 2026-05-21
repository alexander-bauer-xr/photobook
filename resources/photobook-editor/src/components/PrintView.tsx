import React, { useEffect, useMemo, useRef, useState } from 'react';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { PB } from '../lib/api';
import EditorCanvas from './EditorCanvas';

const qc = new QueryClient();

interface PrintSettings {
  bleed_mm?: number;
  crop_marks?: boolean;
  spine_margin_mm?: number;
  safe_zone_mm?: number;
  page_frame_mm?: number;
  width_mm?: number;
  height_mm?: number;
}

/**
 * PrintView — mounted by the shared frontend shell in print mode.
 *
 * Loads pages via API, renders every page as a .print-page div,
 * then sets window.__printReady = true so Playwright knows it can print.
 */
export default function PrintView({ hash, printSettings }: { hash: string; printSettings?: PrintSettings }) {
  return (
    <QueryClientProvider client={qc}>
      <PrintRoot hash={hash} printSettings={printSettings} />
    </QueryClientProvider>
  );
}

function PrintRoot({ hash, printSettings }: { hash: string; printSettings?: PrintSettings }) {
  const [imagesLoaded, setImagesLoaded] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const bleedMm = printSettings?.bleed_mm ?? 0;
  const cropMarks = printSettings?.crop_marks ?? false;
  const trimWMm = printSettings?.width_mm ?? 0;
  const trimHMm = printSettings?.height_mm ?? 0;

  const PX_PER_MM = 96 / 25.4;
  const hasPrintBox = trimWMm > 0 && trimHMm > 0;

  const safeMm = Math.max(
    0,
    Number(printSettings?.safe_zone_mm ?? printSettings?.page_frame_mm ?? 0)
  );

  const spineMm = Math.max(
    0,
    Number(printSettings?.spine_margin_mm ?? 0)
  );

  const { data, isSuccess } = useQuery({
    queryKey: ['print-pages', hash],
    queryFn: () => PB.getPages(hash) as any,
    enabled: !!hash,
  });

  const pages = useMemo(() => Array.isArray(data?.pages) ? data.pages : [], [data]);
  const coverMeta = useMemo(() => {
    const cover = (data?.cover ?? {}) as Record<string, unknown>;

    const pickString = (...values: unknown[]) => {
      for (const value of values) {
        if (typeof value === 'string') {
          const trimmed = value.trim();
          if (trimmed.length > 0) return trimmed;
        }
      }
      return null;
    };

    const pickBool = (...values: unknown[]) => {
      for (const value of values) {
        if (typeof value === 'boolean') return value;
        if (value === 0 || value === 1) return !!value;
      }
      return null;
    };

    return {
      title: pickString(cover.title),
      subtitle: pickString(cover.cover_subtitle, cover.subtitle),
      date: pickString(cover.cover_date, cover.date),
      hasPhoto: pickBool(cover.hasPhoto) ?? !!pickString(cover.image, cover.webSrc),
    };
  }, [data]);

  const getPagePrintBox = (page: any, index: number) => {
    if (!hasPrintBox) return null;
    const id = String(page?.id ?? '').toLowerCase();
    const templateId = String(page?.templateId ?? page?.template ?? '').toLowerCase();
    const printMode = String(page?.printMode ?? page?.print_mode ?? '').toLowerCase();

    const isCover = id === 'cover' || templateId === 'cover';
    const isFullBleed =
      isCover ||
      printMode === 'full_bleed' ||
      printMode === 'full-bleed' ||
      templateId.includes('full-bleed');

    if (isFullBleed) {
      return {
        leftMm: -bleedMm,
        topMm: -bleedMm,
        widthMm: trimWMm + 2 * bleedMm,
        heightMm: trimHMm + 2 * bleedMm,
      };
    }

    const pageNo = Math.max(1, Number(page?.n ?? index + 1));

    const isRightHandPage = pageNo % 2 === 1;

    const marginTopMm = safeMm;
    const marginBottomMm = safeMm;
    const marginLeftMm = safeMm + (isRightHandPage ? spineMm : 0);
    const marginRightMm = safeMm + (!isRightHandPage ? spineMm : 0);

    return {
      leftMm: marginLeftMm,
      topMm: marginTopMm,
      widthMm: Math.max(1, trimWMm - marginLeftMm - marginRightMm),
      heightMm: Math.max(1, trimHMm - marginTopMm - marginBottomMm),
    };
  };

  // Wait for all images inside the container to finish loading
  useEffect(() => {
    if (pages.length === 0) return;
    setImagesLoaded(false);
    (window as any).__printReady = false;

    const check = () => {
      const root = containerRef.current;
      const pageEls = root?.querySelectorAll('.print-page') ?? [];
      const pagesPainted = pageEls.length === pages.length && Array.from(pageEls).every((pageEl) => {
        const pageRect = pageEl.getBoundingClientRect();
        const canvasRect = pageEl.firstElementChild?.getBoundingClientRect();
        return pageRect.width > 0 && pageRect.height > 0 && !!canvasRect && canvasRect.width > 0 && canvasRect.height > 0;
      });
      const imgs = root?.querySelectorAll('img') ?? [];
      if (imgs.length === 0) {
        if (!pagesPainted) return;
        setImagesLoaded(true);
        return;
      }
      const allDone = Array.from(imgs).every((img) => {
        const rect = img.getBoundingClientRect();
        return img.complete && img.naturalWidth > 0 && rect.width > 0 && rect.height > 0;
      });
      if (pagesPainted && allDone) {
        setImagesLoaded(true);
      }
    };

    // Poll until all images are ready (max 25s)
    const interval = setInterval(check, 200);
    const timeout = setTimeout(() => {
      clearInterval(interval);
      setImagesLoaded(true); // proceed even if some images failed
    }, 25_000);

    check();
    return () => { clearInterval(interval); clearTimeout(timeout); };
  }, [pages]);

  // Signal Playwright once everything is ready
  useEffect(() => {
    if (imagesLoaded && pages.length > 0) {
      (window as any).__printReady = true;
    }
  }, [imagesLoaded, pages.length]);

  if (!isSuccess || pages.length === 0) {
    return <div style={{ color: '#999', padding: 32 }}>Lade Seiten…</div>;
  }

  return (
    <div ref={containerRef}>
      {pages.map((page: any, i: number) => (
        <div
          key={`${page.id ?? 'page'}-${i}`}
          className="print-page"
          style={{
            width: '100%',
            height: '100vh',
            position: 'relative',
            overflow: 'hidden',
            pageBreakAfter: i < pages.length - 1 ? 'always' : 'avoid',
            breakAfter: i < pages.length - 1 ? 'page' : 'avoid',
            background: '#fff',
          }}
        >
          {/* Canvas: trim-sized at bleed offset, scaled out to fill the bleed area */}
          <div
            style={(() => {
              const box = getPagePrintBox(page, i);

              const pageSizeOverride = box
                ? {
                  w: Math.round(box.widthMm * PX_PER_MM),
                  h: Math.round(box.heightMm * PX_PER_MM),
                }
                : undefined;

              return (
                <div
                  data-print-content
                  style={{
                    position: 'absolute',
                    left: box ? `${bleedMm + box.leftMm}mm` : 0,
                    top: box ? `${bleedMm + box.topMm}mm` : 0,
                    width: box ? `${box.widthMm}mm` : '100%',
                    height: box ? `${box.heightMm}mm` : '100%',
                    overflow: 'hidden',
                  }}
                >
                  <EditorCanvas
                    page={page as any}
                    showHud={false}
                    interactive={false}
                    gapPx={0}
                    coverMeta={coverMeta}
                    pageSizeOverride={pageSizeOverride}
                  />
                </div>
              );
            })()}
          >
            <EditorCanvas
              page={page as any}
              showHud={false}
              interactive={false}
              gapPx={0}
              coverMeta={coverMeta}
              pageSizeOverride={pageSizeOverride}
            />
          </div>
          {cropMarks && bleedMm > 0 && (
            <CropMarks bleedMm={bleedMm} />
          )}
        </div>
      ))}
    </div>
  );
}

/** Four-corner crop mark lines rendered in the bleed area. */
function CropMarks({ bleedMm }: { bleedMm: number }) {
  const b = `${bleedMm}mm`;
  const lineLen = `${Math.max(3, bleedMm * 0.6)}mm`;
  const lineStyle: React.CSSProperties = { position: 'absolute', background: '#000', pointerEvents: 'none' };

  return (
    <>
      {/* Top-left */}
      <div style={{ ...lineStyle, top: b, left: 0, width: lineLen, height: '0.25px' }} />
      <div style={{ ...lineStyle, top: 0, left: b, width: '0.25px', height: lineLen }} />
      {/* Top-right */}
      <div style={{ ...lineStyle, top: b, right: 0, width: lineLen, height: '0.25px' }} />
      <div style={{ ...lineStyle, top: 0, right: b, width: '0.25px', height: lineLen }} />
      {/* Bottom-left */}
      <div style={{ ...lineStyle, bottom: b, left: 0, width: lineLen, height: '0.25px' }} />
      <div style={{ ...lineStyle, bottom: 0, left: b, width: '0.25px', height: lineLen }} />
      {/* Bottom-right */}
      <div style={{ ...lineStyle, bottom: b, right: 0, width: lineLen, height: '0.25px' }} />
      <div style={{ ...lineStyle, bottom: 0, right: b, width: '0.25px', height: lineLen }} />
    </>
  );
}
