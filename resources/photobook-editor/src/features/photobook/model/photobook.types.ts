export type FitMode = 'cover' | 'contain';

export type Vec2 = {
  x: number;
  y: number;
};

export type PhotoRef = {
  path: string;
  filename?: string;
  width?: number | null;
  height?: number | null;
  ratio?: number | null;
  takenAt?: string | null;
};

export type SlotRect = {
  x: number;
  y: number;
  w: number;
  h: number;
  ar?: number | null;
};

export type LayoutFeedback = {
  preferred?: boolean;
  templateId?: string | null;
  reason?: string | null;
  updated_at?: string;
} | null;

export type PhotobookItem = {
  id?: string | number;
  slotIndex: number;

  photo?: PhotoRef | null;

  src?: string | null;
  web?: string | null;
  webSrc?: string | null;

  fit?: FitMode;
  align?: Vec2;
  offset?: Vec2;
  zoom?: number;
  rotation?: number;
  auto?: boolean;

  objectPosition?: string;
  crop?: FitMode;
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
};

export type PhotobookPage = {
  id: string;
  n?: number;
  templateId?: string | null;
  template?: string | null;
  slots?: SlotRect[];
  items: PhotobookItem[];
  layoutFeedback?: LayoutFeedback;
};

export type AlbumRecord = {
  hash: string;
  folder: string;
  count: number;
  created_at: string;
};
