import React, { useEffect, useMemo, useRef, useState } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePages } from './hooks/usePages';
import EditorCanvas from './components/EditorCanvas';
import Sidebar from './components/Sidebar';
import ReplaceDrawer from './components/ReplaceDrawer';
import PdfReadyModal from './components/PdfReadyModal';
import { api } from './api/client';
import { useTemplates } from './hooks/useTemplates';
import { PB } from './lib/api';
// Using dynamic page typing to allow merged overrides shape

const qc = new QueryClient();

export default function App() {
  return <QueryClientProvider client={qc}><Root /></QueryClientProvider>;
}

function Root() {
  const [folder, setFolder] = useState('');
  const [pageIdx, setPageIdx] = useState(0);
  const [albums, setAlbums] = useState([] as { hash: string; folder: string; count: number; created_at: string }[]);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerIdx, setDrawerIdx] = useState(null as number | null);
  const [candidates, setCandidates] = useState([] as { path: string; filename: string; src?: string | null }[]);
  const [candLoading, setCandLoading] = useState(false);
  const [pageVersion, setPageVersion] = useState(0);
  const [coverTitle, setCoverTitle] = useState('');
  const [coverPath, setCoverPath] = useState<string | null>(null);
  const [coverWebSrc, setCoverWebSrc] = useState<string | null>(null);
  const [isBuilding, setIsBuilding] = useState(false);
  const [buildProgress, setBuildProgress] = useState(0);
  const [buildMessage, setBuildMessage] = useState('');
  const [latestPdfUrl, setLatestPdfUrl] = useState<string | null>(null);
  const progressTimer = useRef<number | null>(null);
  const templatesQ = useTemplates();
  useEffect(() => { api.getAlbums().then(r => setAlbums(r?.albums || [])).catch(() => { }); }, []);
  useEffect(() => {
    if (albums.length === 0) return;
    const has = albums.some(a => (a.folder || a.hash) === folder);
    if (!folder || !has) {
      const first = albums[0];
      if (first) setFolder(first.folder || first.hash);
    }
  }, [albums]);
  const { pagesQ: q, pages } = usePages(folder);
  // Build a web URL for cached assets from an absolute path like .../_cache/<hash>/<rel>
  const filePathToAssetUrl = (p?: string | null): string | null => {
    if (!p) return null;
    const norm = String(p).replace(/^[a-z]+:\/\//i, '').replace(/\\/g, '/');
    const m = norm.match(/\/_cache\/([^/]+)\/(.+)$/);
    if (!m) return null;
    const hash = m[1];
    const rel = m[2];
    // Encode each segment but keep slashes in place
    const encRel = rel.split('/').map(encodeURIComponent).join('/');
    return `/photobook/asset/${encodeURIComponent(hash)}/${encRel}`;
  };
  // Parse relative cache path from a /photobook/asset/{hash}/{rel} URL
  const webAssetRelFromUrl = (u?: string | null): string | null => {
    if (!u) return null;
    const m = String(u).match(/\/photobook\/asset\/[^/]+\/(.+)$/);
    return m ? decodeURIComponent(m[1]) : null;
  };
  // Build a web URL for relative cache asset like images/foo.jpg using album hash
  const relAssetUrl = (hash: string, rel?: string | null): string | null => {
    if (!hash || !rel) return null;
    const encRel = String(rel).split('/').map(encodeURIComponent).join('/');
    return `/photobook/asset/${encodeURIComponent(hash)}/${encRel}`;
  };
  // Hydrate cover from REST if present
  useEffect(() => {
    const data: any = (q as any)?.data;
    const cov = data?.cover;
    const currentAlbum = albums.find(a => (a.folder || a.hash) === folder) || null;
    const ah = currentAlbum?.hash || '';
    if (cov && (cov.image || cov.title)) {
      setCoverTitle(cov.title || '');
      setCoverPath(cov.image || null);
      // Use webSrc from API if available, otherwise generate it
      setCoverWebSrc(cov.webSrc || relAssetUrl(ah, cov.image) || null);
    } else {
      setCoverTitle(''); setCoverPath(null); setCoverWebSrc(null);
    }
  }, [albums, folder, (q as any)?.data]);
  const displayPages = useMemo(() => {
    const qPages: any[] = ((q as any)?.data?.pages || []) as any[];
    const arr: any[] = (Array.isArray(pages) && pages.length ? pages : qPages) as any[];
    // If album already contains a real page 0 cover, use it as-is
    if (Array.isArray(arr) && arr.length && (arr[0]?.n === 0 || arr[0]?.id === 'cover' || arr[0]?.templateId === 'cover' || arr[0]?.template === 'cover')) {
      return arr;
    }
    // Otherwise, synthesize a page 0 cover from top-level cover info
    const data: any = (q as any)?.data;
    const cov = data?.cover;
    const coverPg: any = {
      id: 'cover', n: 0, templateId: 'cover',
      slots: [{ x: 0, y: 0, w: 1, h: 1 }],
      items: [{
        slotIndex: 0,
        src: coverWebSrc || undefined,
        photo: coverPath ? { path: coverPath, filename: (coverPath.split('/') || []).pop() } : null,
        // legacy
        objectPosition: cov?.objectPosition || '50% 50%',
        crop: 'cover',
        scale: (typeof cov?.zoom === 'number' && cov?.zoom > 0) ? cov?.zoom : (cov?.scale || 1),
        rotate: (Number.isFinite(cov?.rotation) ? Number(cov?.rotation) : (cov?.rotate || 0)),
        // canonical
        align: cov?.align || undefined,
        offset: cov?.offset || { x: 0, y: 0 },
        zoom: (typeof cov?.zoom === 'number' && cov?.zoom > 0) ? Number(cov?.zoom) : (cov?.scale || 1),
        rotation: Number.isFinite(cov?.rotation) ? Number(cov?.rotation) : (cov?.rotate || 0),
        auto: cov?.auto === true
      }],
    };
    return [coverPg, ...arr];
  }, [pages, (q as any)?.data, coverWebSrc, coverPath]);

  const page = useMemo(() => {
    if (!displayPages.length) return null;
    return displayPages[Math.max(0, Math.min(pageIdx, displayPages.length - 1))] as any;
  }, [displayPages, pageIdx]) as any;

  const currentAlbum = useMemo(() => albums.find(a => (a.folder || a.hash) === folder) || null, [albums, folder]);
  const albumHash = currentAlbum?.hash || '';

  const updateItemObjectPos = (idx: number, xPct: number, yPct: number) => {
    if (!page) return;
    const clampPct = (v: number) => Math.max(0, Math.min(100, Math.round(v)));
    const px = clampPct(xPct);
    const py = clampPct(yPct);
    const ax = (px - 50) / 50; // -> [-1..1]
    const ay = (py - 50) / 50;

    const prev = page.items[idx];
    page.items[idx] = {
      ...prev,
      // legacy mirror
      objectPosition: `${px}% ${py}%`,
      // canonical (what PHP prefers)
      align: { x: ax, y: ay },
      auto: false,
    };
    setPageVersion(v => v + 1);
  };

  const swapItems = (a: number, b: number) => {
    if (!page) return;
    const arr = [...page.items];
    // swap array positions
    [arr[a], arr[b]] = [arr[b], arr[a]];
    // reassign slotIndex to match new order (item i goes to slot i)
    page.items = arr.map((it, i) => ({ ...it, slotIndex: i }));
    setPageVersion(v => v + 1);
  };

  const clamp01 = (v: number, lo = -1, hi = 1) => Math.max(lo, Math.min(hi, v));
  const isPos = (v: any) => Number.isFinite(v) && v > 0;

  const save = async () => {
    if (!page) return;

    await api.savePage({
      folder,
      page: page.n,
      items: page.items.map((it: any) => {
        // canonical (preferred by PHP builder)
        const fit = it.fit === 'contain' ? 'contain' : 'cover';
        const align = {
          x: clamp01(Number(it.align?.x ?? 0)),
          y: clamp01(Number(it.align?.y ?? 0)),
        };
        const offset = {
          x: Number.isFinite(Number(it.offset?.x)) ? Number(it.offset?.x) : 0,
          y: Number.isFinite(Number(it.offset?.y)) ? Number(it.offset?.y) : 0,
        };
        const zoom = isPos(it.zoom) ? Number(it.zoom) : (isPos(it.scale) ? Number(it.scale) : 1);
        const rotation = Number.isFinite(Number(it.rotation))
          ? Number(it.rotation)
          : (Number.isFinite(Number(it.rotate)) ? Number(it.rotate) : 0);
        const auto = !!it.auto;
        const caption =
          typeof it.caption === 'string'
            ? it.caption
            : typeof it.caption === 'number'
              ? String(it.caption)
              : undefined;

        // legacy (keep for back-compat)
        const objectPosition = `${Math.round(50 + align.x * 50)}% ${Math.round(50 + align.y * 50)}%`;

        return {
          slotIndex: it.slotIndex,

          // --- canonical ---
          fit, align, offset, zoom, rotation, auto,
          ...(it.photo?.path ? { photo: { path: it.photo.path, ...(it.photo.filename ? { filename: it.photo.filename } : {}) } } : {}),
          ...(it.src ? { src: it.src } : {}),
          ...(caption !== undefined ? { caption } : {}),

          // --- legacy ---
          crop: fit,
          objectPosition,
          scale: zoom,
          rotate: rotation,
        };
      }),
      templateId: page.templateId || null,
    });

    alert('Saved page overrides');
  };

  // open replace drawer
  const openReplace = async (i: number) => {
    setDrawerIdx(i);
    setDrawerOpen(true);
    setCandLoading(true);
    try {
      // For cover (index 0), use page 1 candidates
      const effectivePage = pageIdx === 0 ? 1 : (page?.n || 1);
      const r = await api.getCandidates(folder, effectivePage);
      setCandidates(r.candidates || []);
    } finally {
      setCandLoading(false);
    }
  };

  const applyReplacement = (
    cand: { path: string; filename: string; src?: string | null },
    opts?: { preserveCrop?: boolean }
  ) => {
    if (drawerIdx === null) return;

    // Cover: only update the synthetic cover state
    if (pageIdx === 0) {
      const web = cand.src || null;
      const rel = webAssetRelFromUrl(web);
      setCoverPath(rel);
      setCoverWebSrc(web);
      setDrawerOpen(false);
      setPageVersion(v => v + 1);
      return;
    }

    if (!page) return;
    const it = page.items[drawerIdx];
    const derived = cand.src || filePathToAssetUrl(cand.path) || it.src || null;

    const preserve = !!opts?.preserveCrop;

    page.items[drawerIdx] = {
      ...it,
      photo: { ...(it.photo || {} as any), path: cand.path, filename: cand.filename },
      src: derived || undefined,

      // legacy
      objectPosition: preserve ? it.objectPosition : '50% 50%',
      scale: preserve ? (it.scale ?? 1) : 1,

      // canonical (important!)
      fit: preserve ? (it.fit || 'cover') : 'cover',
      align: preserve ? (it.align || { x: 0, y: 0 }) : { x: 0, y: 0 },
      offset: preserve ? (it.offset || { x: 0, y: 0 }) : { x: 0, y: 0 },
      zoom: preserve ? (Number(it.zoom) || 1) : 1,
      rotation: preserve ? (Number(it.rotation) || 0) : 0,
      auto: false,
    };

    try {
      (page.items[drawerIdx] as any).web = derived || undefined;
      (page.items[drawerIdx] as any).webSrc = derived || undefined;
    } catch { }

    setDrawerOpen(false);
    setPageVersion(v => v + 1);
  };


  if (q.isLoading) return <div className="p-6">Loading…</div>;
  if (q.isError) return <div className="p-6 text-red-600">Failed to load pages.json</div>;
  if (!page) return <div className="p-6">No pages.json yet. Folder: {folder}</div>;

  return (
    <div className="h-screen flex">
      <main className="flex-1 p-4 flex flex-col gap-3">
        <header className="flex items-center gap-3">
          <input className="border border-neutral-300 rounded px-2 py-1" value={folder} onChange={e => setFolder(e.target.value)} placeholder="Folder" />
          <button className="px-3 py-1 bg-neutral-800 text-white rounded" onClick={() => q.refetch()}>Load</button>
          <select aria-label="Albums" className="border border-neutral-300 rounded px-2 py-1" value={folder} onChange={e => { setFolder(e.target.value); setPageIdx(0); }}>
            <option value="">Select album…</option>
            {albums.map(a => (
              <option key={a.hash} value={a.folder || a.hash}>{(a.folder || a.hash)} ({a.count})</option>
            ))}
          </select>
          <div className="flex items-center gap-2 ml-6">
            <button disabled={pageIdx <= 0} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={() => setPageIdx(p => Math.max(0, p - 1))}>Prev</button>
            <div className="text-sm">{pageIdx === 0 ? 'Cover' : `Page ${page.n}`}</div>
            <button disabled={displayPages.length <= pageIdx + 1} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={() => setPageIdx(p => p + 1)}>Next</button>
          </div>
          <button className="ml-auto px-3 py-1 rounded bg-blue-600 text-white" onClick={async () => {
            if (pageIdx === 0) {
              if (!page) return;
              // Persist cover choice into page 1 overrides (legacy save)
              const it0: any = page.items?.[0] || {};
              const fit = it0.fit === 'contain' ? 'contain' : 'cover';
              const align = {
                x: Number.isFinite(Number(it0.align?.x)) ? Math.max(-1, Math.min(1, Number(it0.align.x))) : 0,
                y: Number.isFinite(Number(it0.align?.y)) ? Math.max(-1, Math.min(1, Number(it0.align.y))) : 0,
              };
              const offset = {
                x: Number.isFinite(Number(it0.offset?.x)) ? Number(it0.offset.x) : 0,
                y: Number.isFinite(Number(it0.offset?.y)) ? Number(it0.offset.y) : 0,
              };
              const zoom = Number.isFinite(Number(it0.zoom)) && Number(it0.zoom) > 0
                ? Number(it0.zoom)
                : (Number.isFinite(Number(it0.scale)) && Number(it0.scale) > 0 ? Number(it0.scale) : 1);
              const rotation = Number.isFinite(Number(it0.rotation))
                ? Number(it0.rotation)
                : (Number.isFinite(Number(it0.rotate)) ? Number(it0.rotate) : 0);
              const objectPosition = `${Math.round(50 + align.x * 50)}% ${Math.round(50 + align.y * 50)}%`;

              await api.savePage({
                folder, page: 1,
                items: [{
                  slotIndex: 0,
                  // canonical
                  fit, align, offset, zoom, rotation, auto: !!it0.auto,
                  ...(it0.photo?.path ? { photo: { path: it0.photo.path, ...(it0.photo.filename ? { filename: it0.photo.filename } : {}) } } : {}),
                  src: (coverWebSrc || it0.src || null) as any,
                  // legacy
                  crop: fit,
                  objectPosition,
                  scale: zoom,
                  rotate: rotation,
                }],
                templateId: 'cover',
              });
              alert('Saved cover');
            } else {
              await save();
            }
          }}>{pageIdx === 0 ? 'Save cover' : 'Save'}</button>
          <button
            className="px-3 py-1 rounded bg-green-600 text-white disabled:opacity-60"
            disabled={!folder || isBuilding}
            onClick={async () => {
              if (!folder) {
                alert('Select a folder to build');
                return;
              }
              const hasAlbumHash = !!(albumHash && /^[a-f0-9]{40}$/i.test(albumHash));
              try {
                // Persist cover via REST if available and albumHash exists
                if (hasAlbumHash) {
                  try { await PB.setCover(albumHash, { title: coverTitle || '', image: coverPath || null }); } catch { }
                }

                setIsBuilding(true); setBuildProgress(0); setBuildMessage('Starting build...');

                // Build payload
                const payload: Record<string, string> = { folder };
                if (coverTitle) payload.title = coverTitle;
                if (coverPath) payload.cover_image = coverPath;

                // Start build and determine which hash to poll
                let buildHash = albumHash;
                if (hasAlbumHash) {
                  await PB.build(albumHash, payload);
                } else {
                  const r = await PB.buildByFolder(payload);
                  buildHash = r?.hash || '';
                }

                setBuildMessage('Build started successfully');
                if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                const pollHash = buildHash; // capture for closure
                progressTimer.current = window.setInterval(async () => {
                  try {
                    const r: any = await PB.progress(pollHash);
                    const p = r?.status?.progress ?? 0;
                    const msg = r?.status?.step || r?.status?.message || r?.status?.state || '';
                    setBuildProgress(p);
                    setBuildMessage(msg);
                      if (p >= 100) {
                      if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                      setIsBuilding(false);
                      setBuildMessage('Build complete!');
                        try {
                          // Ask backend for latest PDF URL
                          const lr = await fetch('/photobook/latest-pdf.json', { credentials: 'same-origin' });
                          if (lr.ok) {
                            const j = await lr.json();
                            const url = j?.ok && j?.url ? String(j.url) : null;
                            if (url) {
                              setLatestPdfUrl(url);
                              // Attempt to open in a new tab as well (some browsers may block if not user-initiated)
                              try { window.open(url, '_blank', 'noopener'); } catch {}
                            }
                          }
                        } catch {}
                        setTimeout(() => setBuildMessage(''), 2000);
                      q.refetch();
                    }
                  } catch (e) {
                    if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                    setIsBuilding(false);
                    setBuildMessage('Build completed (progress unavailable)');
                    try {
                      const lr = await fetch('/photobook/latest-pdf.json', { credentials: 'same-origin' });
                      if (lr.ok) {
                        const j = await lr.json();
                        const url = j?.ok && j?.url ? String(j.url) : null;
                        if (url) {
                          setLatestPdfUrl(url);
                          try { window.open(url, '_blank', 'noopener'); } catch {}
                        }
                      }
                    } catch {}
                    setTimeout(() => setBuildMessage(''), 2000);
                  }
                }, 1000);

              } catch (e) {
                if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                setIsBuilding(false);
                setBuildMessage('Build failed to start');
                setTimeout(() => setBuildMessage(''), 2000);
              }
            }}
          >{isBuilding ? `Building… ${Math.round(buildProgress)}%` : 'Build'}</button>
        </header>
        {isBuilding && (
          <div className="-mt-2 mb-2 flex items-center gap-3 text-sm text-neutral-700">
            <div className="w-64 h-2 bg-neutral-200 rounded overflow-hidden">
              <div className="h-full bg-green-600" style={{ width: `${Math.max(0, Math.min(100, buildProgress))}%` }} />
            </div>
            <span>{buildMessage}</span>
          </div>
        )}
        {albumHash && pageIdx === 0 && (
          <div className="mt-2 flex items-center gap-2">
            <label className="text-sm">Title</label>
            <input className="border border-neutral-300 rounded px-2 py-1 w-80" value={coverTitle} onChange={e => setCoverTitle(e.target.value)} placeholder="Cover title" />
            <button className="px-3 py-1 rounded bg-neutral-200" onClick={() => openReplace(0)}>Choose cover photo…</button>
            {coverWebSrc ? <img src={coverWebSrc} alt="cover" className="h-10 rounded border" /> : <span className="text-xs text-neutral-500">No image</span>}
          </div>
        )}

        <div className="flex-1 flex gap-4 overflow-hidden">
          <div className="flex-1 flex items-center justify-center overflow-auto">
            <EditorCanvas
              page={page}
              version={pageVersion}
              onChange={(items) => { if (page) { page.items = items as any; setPageVersion(v => v + 1); } }}
              onSave={async (items: any[]) => {
                if (!page) return;
                await api.savePage({
                  folder,
                  page: page.n,
                  items: items.map((it: any) => {
                    const fit = it.fit === 'contain' ? 'contain' : 'cover';
                    const align = { x: clamp01(Number(it.align?.x ?? 0)), y: clamp01(Number(it.align?.y ?? 0)) };
                    const offset = { x: Number(it.offset?.x) || 0, y: Number(it.offset?.y) || 0 };
                    const zoom = isPos(it.zoom) ? Number(it.zoom) : 1;
                    const rotation = Number.isFinite(Number(it.rotation)) ? Number(it.rotation) : 0;
                    const objectPosition = `${Math.round(50 + align.x * 50)}% ${Math.round(50 + align.y * 50)}%`;
                    const caption =
                      typeof it.caption === 'string'
                        ? it.caption
                        : typeof it.caption === 'number'
                          ? String(it.caption)
                          : undefined;

                    return {
                      slotIndex: it.slotIndex,

                      // canonical
                      fit, align, offset, zoom, rotation, auto: !!it.auto,
                      ...(it.photo?.path ? { photo: { path: it.photo.path, ...(it.photo.filename ? { filename: it.photo.filename } : {}) } } : {}),
                      ...(it.src ? { src: it.src } : {}),
                      ...(caption !== undefined ? { caption } : {}),

                      // legacy
                      crop: fit,
                      objectPosition,
                      scale: zoom,
                      rotate: rotation,
                    };
                  }),
                  templateId: page.templateId || null,
                });
                alert('Saved page overrides');
              }}

            />
          </div>
          <Sidebar page={page} onSwap={swapItems} onReplace={openReplace} onUpdateItem={(idx, changes) => {
            if (!page) return;
            const existing = page.items?.[idx];
            if (!existing) return;
            page.items[idx] = { ...existing, ...changes };
            setPageVersion(v => v + 1);
          }} onTemplateChange={async (tpl) => {
            if (!page) return;
            if (pageIdx === 0) return; // no template selection for cover
            // Apply slots from selected template immediately in UI
            try {
              const groups = templatesQ.data || {} as any;
              const count = Array.isArray(page.items) ? page.items.length : 0;
              const arr = (groups[String(count)] || groups[count] || []) as any[];
              const match = arr.find((t:any) => t.id === tpl);
              if (match && Array.isArray(match.slots)) {
                page.templateId = tpl;
                // Replace slots with the chosen template's geometry
                page.slots = match.slots.map((s:any)=>({ x: s.x, y: s.y, w: s.w, h: s.h, ...(s.ar?{ ar: s.ar }: {}) }));
                // Reassign items' slotIndex to sequential indices matching new slots
                page.items = (page.items || []).map((it:any, i:number) => ({ ...it, slotIndex: i }));
                setPageVersion(v => v + 1);
              } else {
                // Fallback: still set templateId so backend override persists
                page.templateId = tpl;
                setPageVersion(v => v + 1);
              }
            } catch {}
            await api.overrideTemplate({ folder, page: page.n, templateId: tpl });
            alert('Template set to ' + tpl);
          }} />
        </div>
        <ReplaceDrawer open={drawerOpen} onClose={() => setDrawerOpen(false)} loading={candLoading} candidates={candidates} onPick={(c, o) => applyReplacement(c, o)} />
      </main>
      {latestPdfUrl && (
        <PdfReadyModal url={latestPdfUrl} onClose={() => setLatestPdfUrl(null)} />
      )}
    </div>
  );
}
