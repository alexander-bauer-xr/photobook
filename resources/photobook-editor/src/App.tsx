/*
Copilot prompt:
Main app:
- React Query provider
- folder input + load
- prev/next page
- EditorCanvas center, Sidebar right
- drag to update objectPosition, swap reordering
- Save -> POST /photobook/save-page
*/
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePages } from './hooks/usePages';
import EditorCanvas from './components/EditorCanvas';
import Sidebar from './components/Sidebar';
import ReplaceDrawer from './components/ReplaceDrawer';
import { api } from './api/client';
import { PB } from './lib/api';
// Using dynamic page typing to allow merged overrides shape

const qc = new QueryClient();

export default function App() {
  return <QueryClientProvider client={qc}><Root/></QueryClientProvider>;
}

function Root() {
  const [folder, setFolder] = useState('');
  const [pageIdx, setPageIdx] = useState(0);
  const [albums, setAlbums] = useState([] as {hash:string;folder:string;count:number;created_at:string}[]);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerIdx, setDrawerIdx] = useState(null as number | null);
  const [candidates, setCandidates] = useState([] as { path: string; filename: string; src?: string | null }[]);
  const [candLoading, setCandLoading] = useState(false);
  const [pageVersion, setPageVersion] = useState(0);
  // Single editor mode (EditorCanvas only)
  // Synthetic cover page state
  const [coverTitle, setCoverTitle] = useState('');
  const [coverPath, setCoverPath] = useState<string | null>(null);
  const [coverWebSrc, setCoverWebSrc] = useState<string | null>(null);
  const [isBuilding, setIsBuilding] = useState(false);
  const [buildProgress, setBuildProgress] = useState(0);
  const [buildMessage, setBuildMessage] = useState('');
  const progressTimer = useRef<number | null>(null);
  useEffect(()=>{ api.getAlbums().then(r=> setAlbums(r?.albums || [])).catch(()=>{}); },[]);
  useEffect(()=>{
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
  useEffect(()=>{
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
        objectPosition: cov?.objectPosition || '50% 50%',
        crop: 'cover',
        scale: cov?.scale || 1,
        rotate: cov?.rotate || 0
      }],
    };
    return [coverPg, ...arr];
  }, [pages, (q as any)?.data, coverWebSrc, coverPath]);

  const page = useMemo(() => {
    if (!displayPages.length) return null;
    return displayPages[Math.max(0, Math.min(pageIdx, displayPages.length-1))] as any;
  }, [displayPages, pageIdx]) as any;

  const currentAlbum = useMemo(()=> albums.find(a => (a.folder || a.hash) === folder) || null, [albums, folder]);
  const albumHash = currentAlbum?.hash || '';

  const updateItemObjectPos = (idx:number,xPct:number,yPct:number)=>{
    if (!page) return;
    const clamp=(v:number)=>Math.max(0,Math.min(100,Math.round(v)));
    page.items[idx] = { ...page.items[idx], objectPosition: `${clamp(xPct)}% ${clamp(yPct)}%` };
  };

  const swapItems=(a:number,b:number)=>{
    if (!page) return;
    const arr=[...page.items];
    // swap array positions
    [arr[a],arr[b]]=[arr[b],arr[a]];
    // reassign slotIndex to match new order (item i goes to slot i)
    page.items = arr.map((it,i)=> ({ ...it, slotIndex: i }));
  setPageVersion(v=>v+1);
  };

  const save=async ()=>{
    if (!page) return;
    await api.savePage({
      folder, page: page.n,
      items: page.items.map(it => ({
        slotIndex: it.slotIndex,
        crop: it.crop || 'cover',
        objectPosition: it.objectPosition || '50% 50%',
        scale: (typeof it.scale === 'number' && isFinite(it.scale) && it.scale > 0) ? it.scale : 1,
  rotate: (typeof (it as any).rotate === 'number' && isFinite((it as any).rotate)) ? (it as any).rotate : 0,
        photo: it.photo ? {
          path: it.photo.path, filename: it.photo.filename,
          width: it.photo.width ?? null, height: it.photo.height ?? null,
          ratio: it.photo.ratio ?? null, takenAt: it.photo.takenAt ?? null
        } : null,
        src: it.src,
      })),
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

  const applyReplacement = (cand: { path: string; filename: string; src?: string | null }, opts?: { preserveCrop?: boolean }) => {
    if (drawerIdx === null) return;
    // Cover selection updates only synthetic cover state
    if (pageIdx === 0) {
      // Restrict to candidates; take their asset URL and infer relative path for saving
      const web = cand.src || null;
      const rel = webAssetRelFromUrl(web);
      setCoverPath(rel);
      setCoverWebSrc(web);
      setDrawerOpen(false);
      setPageVersion(v=>v+1);
      return;
    }
    if (!page) return;
    const it = page.items[drawerIdx];
    const derived = cand.src || filePathToAssetUrl(cand.path) || it.src || null;
    page.items[drawerIdx] = {
      ...it,
      photo: { ...(it.photo||{} as any), path: cand.path, filename: cand.filename },
      src: derived || undefined,
      objectPosition: opts?.preserveCrop ? it.objectPosition : '50% 50%',
      scale: opts?.preserveCrop ? (it.scale ?? 1) : 1,
    };
    try { (page.items[drawerIdx] as any).web = derived || undefined; (page.items[drawerIdx] as any).webSrc = derived || undefined; } catch {}
    setDrawerOpen(false);
    setPageVersion(v=>v+1);
  };

  if (q.isLoading) return <div className="p-6">Loading…</div>;
  if (q.isError) return <div className="p-6 text-red-600">Failed to load pages.json</div>;
  if (!page) return <div className="p-6">No pages.json yet. Folder: {folder}</div>;

  return (
    <div className="h-screen flex">
      <main className="flex-1 p-4 flex flex-col gap-3">
        <header className="flex items-center gap-3">
          <input className="border border-neutral-300 rounded px-2 py-1" value={folder} onChange={e=>setFolder(e.target.value)} placeholder="Folder" />
          <button className="px-3 py-1 bg-neutral-800 text-white rounded" onClick={()=>q.refetch()}>Load</button>
          <select aria-label="Albums" className="border border-neutral-300 rounded px-2 py-1" value={folder} onChange={e=>{ setFolder(e.target.value); setPageIdx(0); }}>
            <option value="">Select album…</option>
            {albums.map(a=> (
              <option key={a.hash} value={a.folder || a.hash}>{(a.folder||a.hash)} ({a.count})</option>
            ))}
          </select>
          <div className="flex items-center gap-2 ml-6">
            <button disabled={pageIdx<=0} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={()=>setPageIdx(p=>Math.max(0,p-1))}>Prev</button>
            <div className="text-sm">{pageIdx===0 ? 'Cover' : `Page ${page.n}`}</div>
            <button disabled={displayPages.length<=pageIdx+1} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={()=>setPageIdx(p=>p+1)}>Next</button>
          </div>
          <button className="ml-auto px-3 py-1 rounded bg-blue-600 text-white" onClick={async ()=>{
            if (pageIdx===0) {
              if (!page) return;
              // Persist cover choice into page 1 overrides (legacy save)
              await api.savePage({
                folder, page: 1,
                items: [{
                  slotIndex: 0,
                  crop: 'cover', objectPosition: '50% 50%', scale: 1, rotate: 0,
                  photo: page.items?.[0]?.photo || null,
                  src: coverWebSrc || page.items?.[0]?.src || null,
                }],
                templateId: page.templateId || null,
              });
              alert('Saved cover');
            } else {
              await save();
            }
          }}>{pageIdx===0 ? 'Save cover' : 'Save'}</button>
          <button
            className="px-3 py-1 rounded bg-green-600 text-white disabled:opacity-60"
            disabled={!folder || isBuilding}
            onClick={async ()=>{
              if (!folder) {
                alert('Select a folder to build');
                return;
              }
              try {
                // Persist cover via REST if available and albumHash exists
                if (albumHash && /^[a-f0-9]{40}$/i.test(albumHash)) {
                  try { await PB.setCover(albumHash, { title: coverTitle || '', image: coverPath || null }); } catch {}
                }
                
                // Use the existing build endpoint that already works
                setIsBuilding(true); setBuildProgress(0); setBuildMessage('Starting build...');
                
                const formData = new FormData();
                formData.append('folder', folder);
                if (coverTitle) formData.append('title', coverTitle);
                if (coverPath) formData.append('cover_image', coverPath);
                
                // Add CSRF token
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                if (csrfToken) formData.append('_token', csrfToken);
                
                const buildResponse = await fetch('/photobook/build', {
                  method: 'POST',
                  body: formData,
                  headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                  },
                  credentials: 'same-origin'
                });
                
                if (!buildResponse.ok) {
                  throw new Error('Build request failed');
                }
                
                setBuildMessage('Build started successfully');
                
                // If we have albumHash, poll for progress
                if (albumHash && /^[a-f0-9]{40}$/i.test(albumHash)) {
                  if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                  progressTimer.current = window.setInterval(async () => {
                    try {
                      const r: any = await PB.progress(albumHash);
                      const p = r?.status?.progress ?? 0;
                      const msg = r?.status?.step || r?.status?.message || r?.status?.state || '';
                      setBuildProgress(p);
                      setBuildMessage(msg);
                      if (p >= 100) {
                        if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                        setIsBuilding(false);
                        setBuildMessage('Build complete!');
                        setTimeout(()=> setBuildMessage(''), 2000);
                        q.refetch();
                      }
                    } catch (e) {
                      if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                      setIsBuilding(false);
                      setBuildMessage('Build completed (progress unavailable)');
                      setTimeout(()=> setBuildMessage(''), 2000);
                    }
                  }, 1000);
                } else {
                  // No progress tracking for legacy folders, just show success after delay
                  setTimeout(() => {
                    setIsBuilding(false);
                    setBuildMessage('Build started - check logs');
                    setTimeout(()=> setBuildMessage(''), 2000);
                  }, 2000);
                }
                
              } catch (e) {
                setIsBuilding(false);
                setBuildMessage('Build failed to start');
                setTimeout(()=> setBuildMessage(''), 2000);
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
        {albumHash && pageIdx===0 && (
          <div className="mt-2 flex items-center gap-2">
            <label className="text-sm">Title</label>
            <input className="border border-neutral-300 rounded px-2 py-1 w-80" value={coverTitle} onChange={e=>setCoverTitle(e.target.value)} placeholder="Cover title" />
            <button className="px-3 py-1 rounded bg-neutral-200" onClick={()=>openReplace(0)}>Choose cover photo…</button>
            {coverWebSrc ? <img src={coverWebSrc} alt="cover" className="h-10 rounded border" /> : <span className="text-xs text-neutral-500">No image</span>}
          </div>
        )}

        <div className="flex-1 flex gap-4 overflow-hidden">
          <div className="flex-1 flex items-center justify-center overflow-auto">
            <EditorCanvas
              page={page}
              scale={1}
              version={pageVersion}
              onChange={(items)=>{ if (page) { page.items = items as any; setPageVersion(v=>v+1); } }}
              onSave={async (items)=>{
                if (!page) return;
                await api.savePage({
                  folder, page: page.n,
                  items: items.map(it=>({
                    slotIndex: it.slotIndex,
                    crop: it.crop || 'cover',
                    objectPosition: it.objectPosition || '50% 50%',
                    scale: (typeof it.scale === 'number' && isFinite(it.scale) && it.scale > 0) ? it.scale : 1,
                    rotate: (typeof (it as any).rotate === 'number' && isFinite((it as any).rotate)) ? (it as any).rotate : 0,
                    photo: it.photo ? {
                      path: it.photo.path, filename: it.photo.filename,
                      width: it.photo.width ?? null, height: it.photo.height ?? null,
                      ratio: it.photo.ratio ?? null, takenAt: it.photo.takenAt ?? null
                    } : null,
                    src: (it as any).webSrc || (it as any).web || it.src || null,
                  })),
                  templateId: page.templateId || null,
                });
                alert('Saved page overrides');
              }}
            />
          </div>
          <Sidebar page={page} onSwap={swapItems} onReplace={openReplace} onTemplateChange={async (tpl)=>{
            if (!page) return;
            if (pageIdx===0) return; // no template selection for cover
            page.templateId = tpl;
            await api.overrideTemplate({ folder, page: page.n, templateId: tpl });
            alert('Template set to '+tpl);
          }} />
        </div>
  <ReplaceDrawer open={drawerOpen} onClose={()=>setDrawerOpen(false)} loading={candLoading} candidates={candidates} onPick={(c, o)=>applyReplacement(c, o)} />
      </main>
    </div>
  );
}
