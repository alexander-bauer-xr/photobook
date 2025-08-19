// Shared types for the photobook editor and API mapping
// Keep minimal and structural to avoid coupling to backend changes

export type Vec2 = { x: number; y: number };

export type ImageSource = {
  id?: string | number;
  path: string; // storage path or http url
  url?: string; // http fallback
  width?: number; // natural width (px)
  height?: number; // natural height (px)
};

export type LegacyPlacement = {
  // Backend expectations (background-size: cover semantics)
  backgroundPositionX?: number; // 0..100
  backgroundPositionY?: number; // 0..100
};

export type CanonicalPlacement = {
  // Editor canonical, free pan/zoom/rotate
  scale: number; // 1 means fit baseline (cover fit)
  rotation: number; // degrees
  position: Vec2; // percent offsets relative to slot inner box, -100..100 common
  focus?: Vec2; // optional focus point in image (0..1)
};

export type PageItem = {
  id: string;
  slotId: string;
  image: ImageSource;
  // Legacy fields the backend already understands
  legacy?: LegacyPlacement;
  // Canonical for UI
  transform?: CanonicalPlacement;
  // Additional metadata (caption etc)
  caption?: string;
};

export type Page = {
  id: string | number;
  layout: string; // template id
  items: PageItem[];
  isCover?: boolean;
};

export type PhotobookDocument = {
  pages: Page[];
};
