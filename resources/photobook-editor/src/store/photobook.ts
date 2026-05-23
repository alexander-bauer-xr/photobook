import { create } from 'zustand';
import type { PhotobookItem as Item, PhotobookPage as Page, SlotRect } from '../features/photobook/model/photobook.types';
import { normalizePhotobookItem, normalizePhotobookPage, normalizeSlot } from '../features/photobook/model/photobook.normalizers';

type UserCropSpec = {
  alignX: number;
  alignY: number;
  zoom: number;
  rotation: number;
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
  updateTemplate: (pageId: string, templateId: string, slots?: SlotRect[]) => void;

  commitUserCrop: (pageId: string, idx: number, spec: UserCropSpec) => void;
  updatePageFeedback: (pageId: string, feedback: Page['layoutFeedback']) => void;

  addPageLocal: (page: Page) => void;
  deletePageLocal: (pageId: string) => void;
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
    pages: (pages || []).map(normalizePhotobookPage),
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

          return normalizePhotobookItem({
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

          return normalizePhotobookItem({
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
    set({ pages: [...pages, normalizePhotobookPage(page, pages.length)], past: [...past, pages], future: [] });
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

          return normalizePhotobookItem({
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
        items: items.map(normalizePhotobookItem),
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
          normalizePhotobookItem({
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
        ...(slots ? { slots: slots.map(normalizeSlot) } : {}),
        items: page.items.map((item, index) =>
          normalizePhotobookItem({
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
