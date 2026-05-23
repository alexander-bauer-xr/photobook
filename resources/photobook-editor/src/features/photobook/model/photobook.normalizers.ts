import type { FitMode, PhotobookItem, PhotobookPage, SlotRect, Vec2 } from './photobook.types';

export function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value));
}

export function isPositiveFinite(value: unknown): boolean {
  return Number.isFinite(Number(value)) && Number(value) > 0;
}

export function normalizeFit(value?: unknown, fallback?: unknown): FitMode {
  return value === 'contain' || fallback === 'contain' ? 'contain' : 'cover';
}

export function alignFromObjectPosition(objectPosition?: string | null): Vec2 {
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

export function normalizeAlign(rawAlign?: unknown, objectPosition?: string | null): Vec2 {
  if (rawAlign && typeof rawAlign === 'object') {
    const align = rawAlign as Partial<Vec2>;

    return {
      x: clamp(Number(align.x ?? 0), -1, 1),
      y: clamp(Number(align.y ?? 0), -1, 1),
    };
  }

  return alignFromObjectPosition(objectPosition);
}

export function normalizeOffset(rawOffset?: unknown): Vec2 {
  if (!rawOffset || typeof rawOffset !== 'object') {
    return { x: 0, y: 0 };
  }

  const offset = rawOffset as Partial<Vec2>;

  return {
    x: Number.isFinite(Number(offset.x)) ? Number(offset.x) : 0,
    y: Number.isFinite(Number(offset.y)) ? Number(offset.y) : 0,
  };
}

export function objectPositionFromAlign(align?: Vec2 | null): string {
  const x = clamp(Number(align?.x ?? 0), -1, 1);
  const y = clamp(Number(align?.y ?? 0), -1, 1);

  return `${Math.round(50 + x * 50)}% ${Math.round(50 + y * 50)}%`;
}

export function normalizeZoom(zoom?: unknown, scale?: unknown): number {
  if (isPositiveFinite(zoom)) return Number(zoom);
  if (isPositiveFinite(scale)) return Number(scale);

  return 1;
}

export function normalizeRotation(rotation?: unknown, rotate?: unknown): number {
  if (Number.isFinite(Number(rotation))) return Number(rotation);
  if (Number.isFinite(Number(rotate))) return Number(rotate);

  return 0;
}

export function normalizeCaption(caption?: unknown): string | undefined {
  if (typeof caption === 'string') return caption;
  if (typeof caption === 'number') return String(caption);

  return undefined;
}

export function normalizeSlot(slot: unknown): SlotRect {
  const raw = slot && typeof slot === 'object' ? slot as Partial<SlotRect> : {};
  const ar = raw.ar ?? null;

  return {
    x: Number(raw.x ?? 0),
    y: Number(raw.y ?? 0),
    w: Number(raw.w ?? 1),
    h: Number(raw.h ?? 1),
    ...(ar ? { ar } : {}),
  };
}

export function normalizePhotobookItem(raw: any): PhotobookItem {
  const fit = normalizeFit(raw?.fit, raw?.crop);
  const align = normalizeAlign(raw?.align, raw?.objectPosition);
  const offset = normalizeOffset(raw?.offset);
  const zoom = normalizeZoom(raw?.zoom, raw?.scale);
  const rotation = normalizeRotation(raw?.rotation, raw?.rotate);
  const caption = normalizeCaption(raw?.caption);

  return {
    id: raw?.id,
    slotIndex: Number.isFinite(Number(raw?.slotIndex)) ? Number(raw.slotIndex) : 0,

    photo: raw?.photo ?? null,
    src: raw?.webSrc ?? raw?.web ?? raw?.src ?? null,
    web: raw?.web ?? null,
    webSrc: raw?.webSrc ?? null,

    fit,
    align,
    offset,
    zoom,
    rotation,
    auto: raw?.auto === true,

    crop: fit,
    objectPosition: raw?.objectPosition ?? objectPositionFromAlign(align),
    scale: zoom,
    rotate: rotation,

    x: raw?.x,
    y: raw?.y,
    width: raw?.width,
    height: raw?.height,

    caption,

    _iw: raw?._iw,
    _ih: raw?._ih,
    _error: raw?._error,
  };
}

export function normalizePhotobookPage(raw: any, index: number): PhotobookPage {
  const n = typeof raw?.n === 'number' ? raw.n : index + 1;
  const id = String(raw?.id ?? `n-${n}`);

  return {
    id,
    n,
    templateId: raw?.templateId ?? raw?.template ?? undefined,
    template: raw?.template ?? undefined,
    slots: Array.isArray(raw?.slots) ? raw.slots.map(normalizeSlot) : [],
    items: Array.isArray(raw?.items) ? raw.items.map(normalizePhotobookItem) : [],
    layoutFeedback: raw?.layoutFeedback ?? null,
  };
}
