#!/usr/bin/env bash
# bootstrap_editor_in_laravel.sh
# Adds a React+TS editor inside the current Laravel project using Laravel's Vite.
set -euo pipefail

echo "==> Laravel project: $(pwd)"
test -f artisan || { echo "Run this from your Laravel project root (where artisan lives)."; exit 1; }

# --- npm deps (installed in the Laravel root) ---
echo "==> Installing npm deps (React, TS, Vite plugins)"
npm pkg set type="module" >/dev/null 2>&1 || true
npm i -D @vitejs/plugin-react typescript @types/node
npm i react react-dom

# Tailwind if missing
if [ ! -f tailwind.config.js ]; then
  echo "==> Tailwind not found, installing"
  npm i -D tailwindcss postcss autoprefixer
  npx tailwindcss init -p
  # minimal CSS entry if not present
  mkdir -p resources/css
  if ! grep -q "@tailwind base" resources/css/app.css 2>/dev/null; then
    cat > resources/css/app.css <<'CSS'
@tailwind base;
@tailwind components;
@tailwind utilities;
CSS
  fi
fi

# Ensure tailwind content includes editor files
if [ -f tailwind.config.js ] && ! grep -q "photobook-editor" tailwind.config.js; then
  echo "==> Patching tailwind.config.js content globs"
  awk '1; /content:/ && !x {print "  content: [\"./resources/**/*.blade.php\",\"./resources/**/*.js\",\"./resources/**/*.ts\",\"./resources/**/*.tsx\",\"./resources/photobook-editor/**/*.{ts,tsx,html,php}\","; getline; x=1; next}' tailwind.config.js > .tailwind.tmp || true
  if [ -s .tailwind.tmp ]; then mv .tailwind.tmp tailwind.config.js; else rm -f .tailwind.tmp; fi
fi

# --- Vite config: add React + multi-input with Laravel plugin preserved if present ---
if [ -f vite.config.ts ]; then
  VCONF="vite.config.ts"
else
  VCONF="vite.config.js"
fi

if [ ! -f "$VCONF" ]; then
cat > vite.config.ts <<'TS'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import laravel from 'laravel-vite-plugin'

export default defineConfig({
  plugins: [
    react(),
    laravel({
      input: ['resources/js/app.js','resources/photobook-editor/main.tsx'],
      refresh: true,
    }),
  ],
})
TS
else
  # Idempotently inject editor input if laravel plugin present
  if grep -q "laravel-vite-plugin" "$VCONF" && ! grep -q "resources/photobook-editor/main.tsx" "$VCONF"; then
    echo "==> Updating $VCONF to include photobook-editor entry"
    sed -i.bak 's/input:\s*\[\([^]]*\)\]/input: [\1, "resources\/photobook-editor\/main.tsx"]/' "$VCONF" || true
    # If pattern didn’t match, append a second laravel() with editor input
    if ! grep -q "resources/photobook-editor/main.tsx" "$VCONF"; then
      awk 'BEGIN{added=0}
        /laravel\(\{/ && !added {print; print "      input: [\"resources/js/app.js\",\"resources/photobook-editor/main.tsx\"],"; added=1; next}
        {print}' "$VCONF" > .vtmp && mv .vtmp "$VCONF"
    fi
  fi
fi

# --- React app files inside resources/photobook-editor ---
BASE="resources/photobook-editor"
mkdir -p "$BASE/src/components" "$BASE/src/api" "$BASE/src/hooks" "$BASE/src/state"

# index.html-like container is Blade-driven; we only need TSX entry + styles
cat > "$BASE/main.tsx" <<'TSX'
import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './src/App'
import './src/index.css'

ReactDOM.createRoot(document.getElementById('photobook-root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
TSX

cat > "$BASE/src/index.css" <<'CSS'
@tailwind base;
@tailwind components;
@tailwind utilities;

html, body { height: 100%; }
#photobook-root { height: 100%; }
body { @apply bg-neutral-50 text-neutral-900; }
CSS

# Types and lightweight API client with Copilot prompts
cat > "$BASE/src/api/types.ts" <<'TS'
/*
Copilot prompt:
These types mirror pages.json from Laravel's photobook cache.
Keep them strict. Add optional "scale?: number" on PageItem for zoom.
*/
export type Photo = {
  path: string;
  filename: string;
  width?: number | null;
  height?: number | null;
  ratio?: number | null;
  takenAt?: string | null;
};

export type SlotRect = { x: number; y: number; w: number; h: number; ar?: number | null };

export type PageItem = {
  slotIndex: number;
  crop?: 'cover' | 'contain';
  objectPosition?: string;
  src?: string;
  photo?: Photo | null;
  scale?: number;
};

export type PageJson = {
  n: number;
  template?: string | null;
  templateId?: string | null;
  slots: SlotRect[];
  items: PageItem[];
};

export type PagesFile = {
  folder: string;
  created_at: string;
  count: number;
  pages: PageJson[];
};

export type OverridePayload = { folder?: string; page: number; templateId?: string };
export type SavePagePayload = { folder?: string; page: number; items: PageItem[]; templateId?: string | null };
TS

cat > "$BASE/src/api/client.ts" <<'TS'
/*
Copilot prompt:
Tiny fetch wrapper for:
- GET /photobook/pages?folder=...
- POST /photobook/override
- POST /photobook/save-page
Return JSON, throw on !ok. No external libs.
*/
import type { PagesFile, OverridePayload, SavePagePayload } from './types';

async function okJson<T>(res: Response): Promise<T> {
  if (!res.ok) throw new Error(`HTTP ${res.status} ${await res.text().catch(()=> '')}`);
  return res.json() as Promise<T>;
}

export const api = {
  async getPages(folder?: string): Promise<PagesFile | null> {
    const resp = await fetch('/photobook/pages?folder=' + encodeURIComponent(folder || ''), { headers: { Accept: 'application/json' }});
    if (resp.status === 404) return null;
    return okJson<PagesFile>(resp);
  },
  async overrideTemplate(payload: OverridePayload) {
    return okJson<{ok: boolean}>(await fetch('/photobook/override', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)}));
  },
  async savePage(payload: SavePagePayload) {
    return okJson<{ok: boolean}>(await fetch('/photobook/save-page', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)}));
  },
};
TS

cat > "$BASE/src/state/selection.ts" <<'TS'
/*
Copilot prompt:
Zustand store to track selected item key ("page:item").
Expose selectedItemKey and setSelected().
*/
import { create } from 'zustand';

type SelState = { selectedItemKey: string | null; setSelected: (k: string | null) => void; };
export const useSelection = create<SelState>((set)=>({ selectedItemKey:null, setSelected:(k)=>set({selectedItemKey:k}) }));
TS

cat > "$BASE/src/hooks/usePages.ts" <<'TS'
/*
Copilot prompt:
usePages(folder?) using TanStack Query. Key: ['pages', folder||'default'].
*/
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';

export function usePages(folder?: string) {
  return useQuery({ queryKey: ['pages', folder || 'default'], queryFn: () => api.getPages(folder) });
}
TS

cat > "$BASE/src/components/EditorCanvas.tsx" <<'TSX'
/*
Copilot prompt:
Render a page with absolutely positioned slots. Support drag to update objectPosition. Selected item shows a ring.
Scale via background-size percentage when item.scale is set.
*/
import React, { useRef, useState } from 'react';
import type { PageJson } from '../api/types';
import { useSelection } from '../state/selection';

type Props = { page: PageJson; onDragObject?: (idx:number,xPct:number,yPct:number)=>void; scale?: number; };

export default function EditorCanvas({ page, onDragObject, scale=1 }: Props) {
  const rootRef = useRef<HTMLDivElement>(null);
  const { selectedItemKey, setSelected } = useSelection();
  const [dragIdx, setDragIdx] = useState<number|null>(null);
  const keyFor = (i:number)=>`${page.n}:${i}`;

  return (
    <div ref={rootRef} className="relative bg-white shadow border border-neutral-200" style={{ width: 900*scale, height: 600*scale }}>
      {page.items.map((it,i)=>{
        const s = page.slots[it.slotIndex] || {x:0,y:0,w:1,h:1};
        const isSel = selectedItemKey === keyFor(i);
        const pos = it.objectPosition || '50% 50%';
        const zoom = it.scale && it.scale>0 ? it.scale : 1;
        return (
          <div key={i}
               className={`absolute overflow-hidden ${isSel?'ring-2 ring-blue-500':'ring-1 ring-neutral-200'}`}
               style={{ left:`${s.x*100}%`, top:`${s.y*100}%`, width:`${s.w*100}%`, height:`${s.h*100}%` }}
               onMouseDown={()=>setSelected(keyFor(i))}>
            <div className="w-full h-full"
                 style={{
                   backgroundImage: it.src?`url(${it.src})`:'none',
                   backgroundSize: zoom!==1?`${100*zoom}% auto`:(it.crop==='contain'?'contain':'cover'),
                   backgroundPosition: pos, backgroundRepeat:'no-repeat', cursor:'grab'
                 }}
                 onMouseDown={()=>setDragIdx(i)}
                 onMouseMove={(e)=>{
                   if (dragIdx===i && onDragObject) {
                     const r=(e.currentTarget as HTMLDivElement).getBoundingClientRect();
                     const rx=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width));
                     const ry=Math.max(0,Math.min(1,(e.clientY-r.top)/r.height));
                     onDragObject(i, rx*100, ry*100);
                   }
                 }}
                 onMouseUp={()=>setDragIdx(null)}
                 onMouseLeave={()=>setDragIdx(null)}
            />
          </div>
        );
      })}
    </div>
  );
}
TSX

cat > "$BASE/src/components/Sidebar.tsx" <<'TSX'
/*
Copilot prompt:
Simple sidebar with page info, swap item up/down, replace stub, and quick template change.
*/
import React from 'react';
import type { PageJson } from '../api/types';

type Props = { page: PageJson; onSwap: (a:number,b:number)=>void; onReplace:(i:number)=>void; onTemplateChange:(id:string)=>void };

export default function Sidebar({ page, onSwap, onReplace, onTemplateChange }: Props) {
  return (
    <aside className="w-72 p-3 bg-white border-l border-neutral-200 flex flex-col gap-3">
      <div>
        <div className="text-sm text-neutral-500">Page</div>
        <div className="font-semibold">#{page.n}</div>
        <div className="text-xs text-neutral-500 truncate">Template: {page.templateId || page.template || 'generic'}</div>
      </div>

      <div className="flex gap-2">
        <button className="px-3 py-1 rounded bg-neutral-800 text-white text-sm" onClick={()=>onTemplateChange('4/quad')}>4/quad</button>
        <button className="px-3 py-1 rounded bg-neutral-200 text-sm" onClick={()=>onTemplateChange('4/hero-row')}>4/hero-row</button>
      </div>

      <div>
        <div className="text-sm font-medium">Items</div>
        <ul className="mt-2 flex flex-col gap-2">
          {page.items.map((it,i)=>(
            <li key={i} className="flex items-center gap-2">
              <div className="w-12 h-10 bg-neutral-100 bg-cover bg-center rounded" style={{ backgroundImage:`url(${it.src||''})` }} />
              <div className="text-xs flex-1">
                <div>slot {it.slotIndex}</div>
                <div className="text-neutral-500">{it.photo?.filename || '—'}</div>
              </div>
              <button className="text-xs px-2 py-1 bg-neutral-200 rounded" onClick={()=>onReplace(i)}>Replace</button>
              {i>0 && <button className="text-xs px-2 py-1 bg-neutral-800 text-white rounded" onClick={()=>onSwap(i,i-1)}>↑</button>}
              {i<page.items.length-1 && <button className="text-xs px-2 py-1 bg-neutral-800 text-white rounded" onClick={()=>onSwap(i,i+1)}>↓</button>}
            </li>
          ))}
        </ul>
      </div>
    </aside>
  );
}
TSX

cat > "$BASE/src/App.tsx" <<'TSX'
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
import React, { useMemo, useState } from 'react';
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
  const q = usePages(folder);
  const page = useMemo<PageJson|null>(() => {
    if (!q.data?.pages?.length) return null;
    return q.data.pages[Math.max(0, Math.min(pageIdx, q.data.pages.length-1))];
  }, [q.data, pageIdx]);

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
          <div className="flex items-center gap-2 ml-6">
            <button disabled={pageIdx<=0} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={()=>setPageIdx(p=>Math.max(0,p-1))}>Prev</button>
            <div className="text-sm">Page {page.n}</div>
            <button disabled={(q.data?.pages?.length||0)<=pageIdx+1} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={()=>setPageIdx(p=>p+1)}>Next</button>
          </div>
          <button className="ml-auto px-3 py-1 rounded bg-blue-600 text-white" onClick={save}>Save</button>
        </header>

        <div className="flex-1 flex gap-4 overflow-hidden">
          <div className="flex-1 flex items-center justify-center overflow-auto">
            <EditorCanvas page={page} onDragObject={updateItemObjectPos} scale={1} />
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
TSX

# --- Blade view + route to mount the editor ---
mkdir -p resources/views/photobook
cat > resources/views/photobook/editor.blade.php <<'BLADE'
@extends('layouts.app')

@section('content')
  <div id="photobook-root" style="height: calc(100vh - 2rem);"></div>
@endsection

@vite('resources/photobook-editor/main.tsx')
BLADE

ROUTES_FILE="routes/web.php"
if ! grep -q "PHOTOBOOK_EDITOR_UI" "$ROUTES_FILE"; then
  cat >> "$ROUTES_FILE" <<'PHP'

// ======= PHOTOBOOK_EDITOR_UI =======
Route::get('/photobook/editor', function () {
    return view('photobook.editor');
});
// ======= /PHOTOBOOK_EDITOR_UI =======
PHP
fi

# --- Optional JSON endpoints (add if missing) ---
if ! grep -q "PHOTOBOOK_EDITOR_ROUTES" "$ROUTES_FILE"; then
  cat >> "$ROUTES_FILE" <<'PHP'

// ======= PHOTOBOOK_EDITOR_ROUTES (auto-added) =======
use Illuminate\Http\Request;

Route::get('/photobook/pages', function (Request $r) {
    $folder = (string) $r->query('folder', config('photobook.folder'));
    $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
    $pagesPath = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';
    if (!is_file($pagesPath)) {
        return response()->json(['ok'=>false, 'error'=>'pages.json missing'], 404);
    }
    $json = @file_get_contents($pagesPath) ?: '';
    $data = json_decode($json, true);
    return response()->json($data);
});

Route::post('/photobook/save-page', function (Request $r) {
    $folder = (string) $r->input('folder', config('photobook.folder'));
    $pageNo = (int) $r->input('page', 0);
    $items  = $r->input('items');
    $templateId = $r->input('templateId');

    if ($pageNo < 1 || !is_array($items)) {
        return response()->json(['ok'=>false, 'error'=>'Invalid payload'], 422);
    }

    $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
    if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0775, true);
    $ovPath = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';

    $ov = [];
    if (is_file($ovPath)) {
        $ov = json_decode(@file_get_contents($ovPath), true) ?: [];
    }
    $ov['pages'] = $ov['pages'] ?? [];
    $ov['pages'][(string)$pageNo] = [
        'templateId' => $templateId,
        'items' => $items,
        'updated_at' => date(DATE_ATOM),
    ];

    @file_put_contents($ovPath, json_encode($ov, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    return response()->json(['ok'=>true]);
});
// ======= /PHOTOBOOK_EDITOR_ROUTES =======
PHP
fi

echo ""
echo "✅ Embedded editor wired up."
echo "Run:"
echo "  php artisan serve"
echo "  npm run dev"
echo ""
echo "Open: http://localhost:8000/photobook/editor"
