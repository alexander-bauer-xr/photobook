import React, { useEffect, useRef, useState } from 'react';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { PB } from '../lib/api';
import { usePB } from '../store/photobook';
import EditorCanvas from './EditorCanvas';

const qc = new QueryClient();

/**
 * PrintView — mounted when data-print="true" on #photobook-root.
 *
 * Loads pages via API, renders every page as a .print-page div,
 * then sets window.__printReady = true so Playwright knows it can print.
 */
export default function PrintView({ hash }: { hash: string }) {
  return (
    <QueryClientProvider client={qc}>
      <PrintRoot hash={hash} />
    </QueryClientProvider>
  );
}

function PrintRoot({ hash }: { hash: string }) {
  const setInitial = usePB(s => s.setInitial);
  const pages = usePB(s => s.pages);
  const [imagesLoaded, setImagesLoaded] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const { data, isSuccess } = useQuery({
    queryKey: ['print-pages', hash],
    queryFn: () => PB.getPages(hash) as any,
    enabled: !!hash,
  });

  useEffect(() => {
    if (isSuccess && data?.pages) {
      setInitial(hash, data.pages);
    }
  }, [isSuccess, data]);

  // Wait for all images inside the container to finish loading
  useEffect(() => {
    if (pages.length === 0) return;

    const check = () => {
      const imgs = containerRef.current?.querySelectorAll('img') ?? [];
      if (imgs.length === 0) {
        setImagesLoaded(true);
        return;
      }
      const allDone = Array.from(imgs).every(img => img.complete && img.naturalWidth > 0);
      if (allDone) {
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
  }, [pages.length]);

  // Signal Playwright once everything is ready
  useEffect(() => {
    if (imagesLoaded && pages.length > 0) {
      (window as any).__printReady = true;
    }
  }, [imagesLoaded, pages.length]);

  if (pages.length === 0) {
    return <div style={{ color: '#999', padding: 32 }}>Lade Seiten…</div>;
  }

  return (
    <div ref={containerRef}>
      {pages.map((page, i) => (
        <div
          key={page.id}
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
          <EditorCanvas
            page={page as any}
          />
        </div>
      ))}
    </div>
  );
}
