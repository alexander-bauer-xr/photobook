import { create } from 'zustand';
import type { RenderSpec } from '../types/photobook';

type Fit = 'cover' | 'contain';

type Align = {
  x: number;
  y: number;
};

type Offset = {
  x: number;
  y: number;
}

type UserCropSpec = {
  alignX: number;
  alignY: number;
  zoom: number;
  rotation: number;
};

type Item = {
  id?: string | number;
  slotIndex: number;

  photo?: any;

  src?: string | null;

  web?: string | null;
  webSrc?: string | null;

  fit?: Fit;
  align?: Align;
  offset?: Offset;
  zoom?: number;
  rotation?: number;
  auto?: boolean;

  objectPosition?: string;
  crop?: Fit;
  scale?: number;
  rotate?: number;

  x?: number;
  y?: number;
  width?: number;
  height?: number;

  caption?: string;

  _iw?: number;
  _ih?: number;
  _error?: boolean;
}

type Page = {
  id: string;        // stable id for local edits
  n?: number;        // 1-based page number from server, if present
  templateId?: string;
  slots?: { x: number; y: number; w: number; h: number; ar?: number | null }[];
  items: Item[];
  layoutFeedback?: {
    preferred?: boolean;
    templateId?: string | null;
    reason?: string | null;
    updated_at?: string;
  } | null;
};

type PBState = {
  hash: string;
  pages: Page[];
  past: Page[][];
  future: Page[][];

  setInitial: (hash: string, pages: Page[]) => void;

  updateItem: (pageId: string, idx: number, changes: Partial<Item>) => void;
  updateItemWith: (pageId: string, idx: number, updater: (item: Item) => Item) => void;
  replacePageItems: (pageId: string, items: Item[]) => void;
  swapItems: (pageId: string, from: number, to: number) => void;
  updateTemplate: (
    pageId: string,
    templateId: string,
    slots?: { x: number; y: number; w: number; h: number; ar?: number | null }[]
  ) => void;

  commitUserCrop: (pageId: string, idx: number, spec: UserCropSpec) => void;
  updatePageFeedback: (pageId: string, feedback: Page['layoutFeedback']) => void;

  addPageLocal: (page: Page) => void;
  deletePageLocal: (pageId: string) => void;
  undo: () => void;
  redo: () => void;
};

function clamp(value: number, min: number, max: number) {
  return Math.max(Math.min(value, max), min);
}

function alignFromObjectPosition(objectPosition?: string): Align {
  if (!objectPosition) return { x: 0, y: 0 };

  const parts = String(objectPosition).trim().split(/\s+/, 2);
  const px = Number((parts[0] || '').replace('%', ''));
  const py = Number((parts[1] || '').replace('%', ''));

  if (!Number.isFinite(px) || !Number.isFinite(py)) {
    return { x: 0, y: 0 };
  }

  return {
    x: clamp((px - 50) / 50, -1, 1),
    y: clamp((py - 50) / 50, -1, 1),
  };
}

function objectPositionFromAlign(align?: Align) {
  const x = clamp(Number(align?.x ?? 0), -1, 1);
  const y = clamp(Number(align?.y ?? 0), -1, 1);

  return `${Math.round(50 + x * 50)}% ${Math.round(50 + y * 50)}%`;
}

function normalizeItem(it: any): Item {
  const fit: Fit = it.fit === 'contain' || it.crop === 'contain'
    ? 'contain'
    : 'cover';

  const align = it.align && typeof it.align === 'object'
    ? {
      x: clamp(Number(it.align.x ?? 0), -1, 1),
      y: clamp(Number(it.align.y ?? 0), -1, 1),
    }
    : alignFromObjectPosition(it.objectPosition);

  const offset = it.offset && typeof it.offset === 'object'
    ? {
      x: Number.isFinite(Number(it.offset.x)) ? Number(it.offset.x) : 0,
      y: Number.isFinite(Number(it.offset.y)) ? Number(it.offset.y) : 0,
    }
    : { x: 0, y: 0 };

  const zoom = Number.isFinite(Number(it.zoom)) && Number(it.zoom) > 0
    ? Number(it.zoom)
    : Number.isFinite(Number(it.scale)) && Number(it.scale) > 0
      ? Number(it.scale)
      : 1;

  const rotation = Number.isFinite(Number(it.rotation))
    ? Number(it.rotation)
    : Number.isFinite(Number(it.rotate))
      ? Number(it.rotate)
      : 0;

  const caption =
    typeof it.caption === 'string'
      ? it.caption
      : typeof it.caption === 'number'
        ? String(it.caption)
        : undefined;

  return {
    id: it.id,
    slotIndex: Number.isFinite(Number(it.slotIndex)) ? Number(it.slotIndex) : 0,

    photo: it.photo,
    src: it.webSrc ?? it.web ?? it.src ?? null,
    web: it.web ?? null,
    webSrc: it.webSrc ?? null,

    fit,
    align,
    offset,
    zoom,
    rotation,
    auto: it.auto === true,

    crop: fit,
    objectPosition: it.objectPosition ?? objectPositionFromAlign(align),
    scale: zoom,
    rotate: rotation,

    x: it.x,
    y: it.y,
    width: it.width,
    height: it.height,

    caption,
  };
}

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
      slots: Array.isArray(p.slots) ? p.slots.map((s: any) => ({
        x: Number(s.x ?? 0), y: Number(s.y ?? 0), w: Number(s.w ?? 1), h: Number(s.h ?? 1), ar: s.ar ?? null
      })) : [],
      items: (p.items || []).map(normalizeItem),
    })),
    past: [],
    future: [],
  }),
  updateItem: (pageId, idx, changes) => {
    const { pages, past } = get();

    const next = pages.map((page) => {
      if (page.id !== pageId) return page;

      return {
        ...page,
        items: page.items.map((item, itemIdx) => {
          if (itemIdx !== idx) return item;

          return normalizeItem({
            ...item,
            ...changes,
            auto: false,
          });
        }),
      };
    });

    set({
      pages: next,
      past: [...past, pages],
      future: [],
    });
  },
  commitUserCrop: (pageId, idx, spec) => {
    const { pages, past } = get();

    const next = pages.map((page) => {
      if (page.id !== pageId) return page;

      return {
        ...page,
        items: page.items.map((item, itemIdx) => {
          if (itemIdx !== idx) return item;

          return normalizeItem({
            ...item,
            align: {
              x: spec.alignX,
              y: spec.alignY,
            },
            zoom: spec.zoom,
            rotation: spec.rotation,
            auto: false,
          });
        }),
      };
    });

    set({
      pages: next,
      past: [...past, pages],
      future: [],
    });
  },
  addPageLocal: (page) => {
    const { pages, past } = get();
    set({ pages: [...pages, page], past: [...past, pages], future: [] });
  },
  deletePageLocal: (pageId) => {
    const { pages, past } = get();
    set({ pages: pages.filter(p => p.id !== pageId), past: [...past, pages], future: [] });
  },
  undo: () => {
    const { past, pages, future } = get();
    if (!past.length) return;
    const prev = past[past.length - 1];
    set({ pages: prev, past: past.slice(0, -1), future: [pages, ...future] });
  },
  redo: () => {
    const { past, pages, future } = get();
    if (!future.length) return;
    const next = future[0];
    set({ pages: next, past: [...past, pages], future: future.slice(1) });
  },
  updateItemWith: (pageId, idx, updater) => {
    const { pages, past } = get();

    const next = pages.map((page) => {
      if (page.id !== pageId) return page;

      return {
        ...page,
        items: page.items.map((item, itemIdx) => {
          if (itemIdx !== idx) return item;

          return normalizeItem({
            ...updater({ ...item }),
            auto: false,
          });
        }),
      };
    });

    set({
      pages: next,
      past: [...past, pages],
      future: [],
    });
  },

  replacePageItems: (pageId, items) => {
    const { pages, past } = get();

    const next = pages.map((page) => {
      if (page.id !== pageId) return page;

      return {
        ...page,
        items: items.map(normalizeItem),
      };
    });

    set({
      pages: next,
      past: [...past, pages],
      future: [],
    });
  },

  swapItems: (pageId, from, to) => {
    const { pages, past } = get();

    const next = pages.map((page) => {
      if (page.id !== pageId) return page;

      const items = [...page.items];
      const moved = items.splice(from, 1)[0];

      if (!moved) return page;

      items.splice(to, 0, moved);

      return {
        ...page,
        items: items.map((item, index) =>
          normalizeItem({
            ...item,
            slotIndex: index,
            auto: false,
          })
        ),
      };
    });

    set({
      pages: next,
      past: [...past, pages],
      future: [],
    });
  },

  updateTemplate: (pageId, templateId, slots) => {
    const { pages, past } = get();

    const next = pages.map((page) => {
      if (page.id !== pageId) return page;

      return {
        ...page,
        templateId,
        ...(slots
          ? {
            slots: slots.map((slot) => ({
              x: Number(slot.x ?? 0),
              y: Number(slot.y ?? 0),
              w: Number(slot.w ?? 1),
              h: Number(slot.h ?? 1),
              ...(slot.ar ? { ar: slot.ar } : {}),
            })),
          }
          : {}),
        items: page.items.map((item, index) =>
          normalizeItem({
            ...item,
            slotIndex: index,
            auto: false,
          })
        ),
      };
    });

    set({
      pages: next,
      past: [...past, pages],
      future: [],
    });
  },

  updatePageFeedback: (pageId, feedback) => {
    const { pages } = get();

    const next = pages.map((page) => {
      if (page.id !== pageId) return page;
      return { ...page, layoutFeedback: feedback ?? null };
    });

    // Feedback changes are not tracked in undo history
    set({ pages: next });
  },
}));

