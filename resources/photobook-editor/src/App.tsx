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
import React, { useEffect, useMemo, useState } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePages } from './hooks/usePages';
import EditorCanvas from './components/EditorCanvas';
import Sidebar from './components/Sidebar';
import { api } from './api/client';
import type { PageJson } from './api/types';

const qc = new QueryClient();

export default function App() {
  return <QueryClientProvider client={qc}><Root/></QueryClientProvider>;
}

function Root() {
  const [folder, setFolder] = useState<string>('Alben/1');
  const [pageIdx, setPageIdx] = useState(0);
  const [albums, setAlbums] = useState([] as {hash:string;folder:string;count:number;created_at:string}[]);
  useEffect(()=>{ api.getAlbums().then(r=> setAlbums(r.albums || [])).catch(()=>{}); },[]);
  const q = usePages(folder);
  const page = useMemo(() => {
    if (!q.data?.pages?.length) return null;
    return q.data.pages[Math.max(0, Math.min(pageIdx, q.data.pages.length-1))];
  }, [q.data, pageIdx]) as PageJson | null;

  const updateItemObjectPos = (idx:number,xPct:number,yPct:number)=>{
    if (!page) return;
    const clamp=(v:number)=>Math.max(0,Math.min(100,Math.round(v)));
    page.items[idx] = { ...page.items[idx], objectPosition: `${clamp(xPct)}% ${clamp(yPct)}%` };
  };

  const swapItems=(a:number,b:number)=>{
    if (!page) return;
    const arr=[...page.items]; [arr[a],arr[b]]=[arr[b],arr[a]]; page.items=arr;
  };

  const save=async ()=>{
    if (!page) return;
    await api.savePage({
      folder, page: page.n,
      items: page.items.map(it=>({
        slotIndex: it.slotIndex,
        crop: it.crop || 'cover',
        objectPosition: it.objectPosition || '50% 50%',
        scale: it.scale || 1,
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
            <option value={folder}>Select album…</option>
            {albums.map(a=> (
              <option key={a.hash} value={a.folder || a.hash}>{(a.folder||a.hash)} ({a.count})</option>
            ))}
          </select>
          <div className="flex items-center gap-2 ml-6">
            <button disabled={pageIdx<=0} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={()=>setPageIdx(p=>Math.max(0,p-1))}>Prev</button>
            <div className="text-sm">Page {page.n}</div>
            <button disabled={(q.data?.pages?.length||0)<=pageIdx+1} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={()=>setPageIdx(p=>p+1)}>Next</button>
          </div>
          <button className="ml-auto px-3 py-1 rounded bg-blue-600 text-white" onClick={save}>Save</button>
        </header>

        <div className="flex-1 flex gap-4 overflow-hidden">
          <div className="flex-1 flex items-center justify-center overflow-auto">
            <EditorCanvas
              page={page}
              scale={1}
              onSave={async (items)=>{
                await api.savePage({
                  folder, page: page.n,
                  items: items.map(it=>({
                    slotIndex: it.slotIndex,
                    crop: it.crop || 'cover',
                    objectPosition: it.objectPosition || '50% 50%',
                    scale: (typeof it.scale === 'number' && isFinite(it.scale) && it.scale>0) ? it.scale : 1,
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
              }}
            />
          </div>
          <Sidebar page={page} onSwap={swapItems} onReplace={(i)=>alert('TODO: image picker for item '+i)} onTemplateChange={async (tpl)=>{
            if (!page) return;
            page.templateId = tpl;
            await api.overrideTemplate({ folder, page: page.n, templateId: tpl });
            alert('Template set to '+tpl);
          }} />
        </div>
      </main>
    </div>
  );
}
