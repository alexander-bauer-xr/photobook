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

  // When bleed is active, slot coordinates (0–1) map to the TRIM BOX. The
  // EditorCanvas container is trim-sized and positioned at (bleedMm, bleedMm);
  // a CSS scale transform (origin: center) expands it to fill the full bleed
  // viewport so edge photos naturally extend into the bleed zone.
  const PX_PER_MM = 96 / 25.4;
  const hasPrintBox = trimWMm > 0 && trimHMm > 0;
  const hasBleed = bleedMm > 0 && hasPrintBox;
  const scaleX = hasBleed ? (trimWMm + 2 * bleedMm) / trimWMm : 1;
  const scaleY = hasBleed ? (trimHMm + 2 * bleedMm) / trimHMm : 1;
  const trimWPx = Math.round(trimWMm * PX_PER_MM);
  const trimHPx = Math.round(trimHMm * PX_PER_MM);
  // Pass trim dimensions to EditorCanvas so slot coords map to the trim box.
  // When bleed is off, undefined lets EditorCanvas measure its container.
  const pageSizeOverride = hasPrintBox ? { w: trimWPx, h: trimHPx } : undefined;

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
            // Content fills the full enlarged PDF page (bleed area included).
            // The PHP exporter already enlarges the page by 2×bleed_mm per side;
            // adding CSS padding here would shrink content — do NOT add padding.
          }}
        >
          {/* Canvas: trim-sized at bleed offset, scaled out to fill the bleed area */}
          <div
            style={{
              position: 'absolute',
              left: hasPrintBox ? `${bleedMm}mm` : 0,
              top: hasPrintBox ? `${bleedMm}mm` : 0,
              width: hasPrintBox ? `${trimWMm}mm` : '100%',
              height: hasPrintBox ? `${trimHMm}mm` : '100%',
              transformOrigin: 'center center',
              transform: hasBleed ? `scale(${scaleX}, ${scaleY})` : undefined,
              overflow: 'visible',
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
