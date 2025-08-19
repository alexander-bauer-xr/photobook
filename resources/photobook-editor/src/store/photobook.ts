import { create } from 'zustand';

// Local editor item shape (tolerant to missing fields from server)
type Item = {
  slotIndex: number;
  photo?: any;
  src?: string | null;
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
      items: (p.items || []).map((it: any) => ({
        slotIndex: it.slotIndex ?? 0,
        photo: it.photo,
    src: it.web ?? it.webSrc ?? it.src ?? null,
    // Prefer canonical when present, else legacy
    objectPosition: it.objectPosition ?? (typeof it.align === 'object' && it.align
      ? `${Math.round(50 + Math.max(-1, Math.min(1, Number(it.align.x ?? 0))) * 50)}% ${Math.round(50 + Math.max(-1, Math.min(1, Number(it.align.y ?? 0))) * 50)}%`
      : '50% 50%'),
    crop: (it.fit === 'contain' || it.crop === 'contain') ? 'contain' : 'cover',
    scale: (Number.isFinite(it.zoom) && it.zoom > 0) ? Number(it.zoom)
      : (typeof it.scale === 'number' && isFinite(it.scale) && it.scale > 0 ? it.scale : 1),
    rotate: Number.isFinite(it.rotation) ? Number(it.rotation)
      : (typeof it.rotate === 'number' && isFinite(it.rotate) ? it.rotate : 0),
        x: it.x, y: it.y, width: it.width, height: it.height,
        caption: it.caption,
      })),
    })),
    past: [],
    future: [],
  }),
  updateItem: (pageId, idx, changes) => {
    const { pages, past } = get();
    const next = pages.map(p => p.id===pageId ? ({ ...p, items: p.items.map((it,i)=> i===idx ? {...it, ...changes} : it) }) : p);
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
