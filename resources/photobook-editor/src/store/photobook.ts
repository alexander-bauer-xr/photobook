import { create } from 'zustand';
import type { RenderSpec } from '../types/photobook';

// Local editor item shape (tolerant to missing fields from server)
// v2: align/zoom/rotation/auto/fit are the canonical fields (from RenderSpec).
// objectPosition is kept for backwards-compat rendering fallback only.
type Item = {
  slotIndex: number;
  photo?: any;
  src?: string | null;
  // v2 canonical (mirrors RenderSpec)
  fit?: 'cover' | 'contain';
  align?: { x: number; y: number };   // -1..1
  zoom?: number;                       // 1.0 = no zoom
  rotation?: number;                   // degrees
  auto?: boolean;                      // false = user override
  // legacy fallback (still accepted from server, not written back)
  objectPosition?: string;
  crop?: 'cover' | 'contain';
  scale?: number;
  rotate?: number;
  x?: number; y?: number; width?: number; height?: number;
  caption?: string;
};

type Page = {
  id: string;        // stable id for local edits
  n?: number;        // 1-based page number from server, if present
  templateId?: string;
  slots?: { x:number; y:number; w:number; h:number; ar?: number | null }[];
  items: Item[];
};

type PBState = {
  hash:string;
  pages:Page[];
  past:Page[][];
  future:Page[][];
  setInitial: (hash:string, pages:Page[]) => void;
  updateItem: (pageId:string, idx:number, changes:Partial<Item>) => void;
  /** Mark a slot as manually adjusted by the user (sets auto=false). */
  commitUserCrop: (pageId:string, idx:number, spec: Pick<RenderSpec, 'alignX'|'alignY'|'zoom'|'rotation'>) => void;
  addPageLocal: (page:Page) => void;
  deletePageLocal: (pageId:string) => void;
  undo: () => void;
  redo: () => void;
};

export const usePB = create<PBState>((set, get) => ({
  hash: '',
  pages: [],
  past: [],
  future: [],
  setInitial: (hash, pages) => set({
    hash,
    pages: (pages || []).map((p: any, idx: number) => ({
      id: String(p.id ?? `n-${p.n ?? (idx + 1)}`),
      n: typeof p.n === 'number' ? p.n : (idx + 1),
      templateId: p.templateId ?? p.template ?? undefined,
      slots: Array.isArray(p.slots) ? p.slots.map((s:any)=>({
        x: Number(s.x ?? 0), y: Number(s.y ?? 0), w: Number(s.w ?? 1), h: Number(s.h ?? 1), ar: s.ar ?? null
      })) : [],
      items: (p.items || []).map((it: any) => {
        // v2: prefer RenderSpec fields from server
        const hasRenderSpec = it.alignX !== undefined || it.align !== undefined;
        const alignX = it.alignX ?? it.align?.x ?? 0;
        const alignY = it.alignY ?? it.align?.y ?? 0;
        return {
          slotIndex: it.slotIndex ?? 0,
          photo: it.photo,
          src: it.webSrc ?? it.web ?? it.src ?? null,
          // v2 canonical
          fit: (it.fit === 'contain' || it.crop === 'contain') ? 'contain' : 'cover',
          align: { x: Number(alignX), y: Number(alignY) },
          zoom: (Number.isFinite(it.zoom) && it.zoom > 0) ? Number(it.zoom)
            : (typeof it.scale === 'number' && isFinite(it.scale) && it.scale > 0 ? it.scale : 1),
          rotation: Number.isFinite(it.rotation) ? Number(it.rotation)
            : (typeof it.rotate === 'number' && isFinite(it.rotate) ? it.rotate : 0),
          auto: hasRenderSpec ? Boolean(it.auto ?? true) : true,
          // legacy fallback for objectPosition-only servers
          objectPosition: it.objectPosition,
          x: it.x, y: it.y, width: it.width, height: it.height,
          caption: it.caption,
        };
      }),
    })),
    past: [],
    future: [],
  }),
  updateItem: (pageId, idx, changes) => {
    const { pages, past } = get();
    const next = pages.map(p => p.id===pageId ? ({ ...p, items: p.items.map((it,i)=> i===idx ? {...it, ...changes} : it) }) : p);
    set({ pages: next, past: [...past, pages], future: [] });
  },
  commitUserCrop: (pageId, idx, spec) => {
    const { pages, past } = get();
    const next = pages.map(p => p.id === pageId ? ({
      ...p,
      items: p.items.map((it, i) => i === idx ? {
        ...it,
        align:    { x: spec.alignX, y: spec.alignY },
        zoom:     spec.zoom,
        rotation: spec.rotation,
        auto:     false,  // user override — do not overwrite on re-plan
      } : it),
    }) : p);
    set({ pages: next, past: [...past, pages], future: [] });
  },
  addPageLocal: (page) => {
    const { pages, past } = get();
    set({ pages:[...pages, page], past:[...past, pages], future:[] });
  },
  deletePageLocal: (pageId) => {
    const { pages, past } = get();
    set({ pages: pages.filter(p=>p.id!==pageId), past:[...past, pages], future:[] });
  },
  undo: () => {
    const { past, pages, future } = get();
    if (!past.length) return;
    const prev = past[past.length-1];
    set({ pages: prev, past: past.slice(0,-1), future: [pages, ...future] });
  },
  redo: () => {
    const { past, pages, future } = get();
    if (!future.length) return;
    const next = future[0];
    set({ pages: next, past:[...past, pages], future: future.slice(1) });
  },
}));

