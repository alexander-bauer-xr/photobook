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
