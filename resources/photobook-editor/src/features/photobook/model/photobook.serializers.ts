import type { PhotobookItem, PhotobookPage } from './photobook.types';
import { clamp, normalizePhotobookItem, objectPositionFromAlign } from './photobook.normalizers';

export function serializeItemForPagesJson(rawItem: unknown) {
  const item = normalizePhotobookItem(rawItem);
  const fit = item.fit === 'contain' ? 'contain' : 'cover';
  const align = {
    x: clamp(Number(item.align?.x ?? 0), -1, 1),
    y: clamp(Number(item.align?.y ?? 0), -1, 1),
  };
  const offset = {
    x: Number.isFinite(Number(item.offset?.x)) ? Number(item.offset?.x) : 0,
    y: Number.isFinite(Number(item.offset?.y)) ? Number(item.offset?.y) : 0,
  };
  const zoom = Number.isFinite(Number(item.zoom)) && Number(item.zoom) > 0 ? Number(item.zoom) : 1;
  const rotation = Number.isFinite(Number(item.rotation))
    ? ((Number(item.rotation) % 360) + 360) % 360
    : 0;
  const caption =
    typeof item.caption === 'string'
      ? item.caption
      : typeof (item as any).caption === 'number'
        ? String((item as any).caption)
        : undefined;
  const src = (item as any).web ?? (item as any).webSrc ?? (item as any).src ?? null;

  return {
    slotIndex: item.slotIndex,
    fit,
    align,
    offset,
    zoom,
    rotation,
    auto: item.auto === true,
    ...(item.photo?.path
      ? {
          photo: {
            path: item.photo.path,
            ...(item.photo.filename ? { filename: item.photo.filename } : {}),
          },
        }
      : {}),
    ...(caption !== undefined ? { caption } : {}),
    crop: fit,
    objectPosition: objectPositionFromAlign(align),
    scale: zoom,
    rotate: rotation,
    ...(src ? { src } : {}),
  };
}

export function serializeItemsForPagesJson(items: unknown[]) {
  return items
    .map(normalizePhotobookItem)
    .slice()
    .sort((a, b) => a.slotIndex - b.slotIndex)
    .map(serializeItemForPagesJson);
}

export function serializeItemsForLegacySave(items: unknown[]) {
  return items.map((rawItem) => {
    const item = normalizePhotobookItem(rawItem);
    const align = {
      x: clamp(Number(item.align?.x ?? 0), -1, 1),
      y: clamp(Number(item.align?.y ?? 0), -1, 1),
    };
    const fit = item.fit === 'contain' ? 'contain' : 'cover';
    const zoom = Number.isFinite(Number(item.zoom)) && Number(item.zoom) > 0 ? Number(item.zoom) : 1;
    const rotation = Number.isFinite(Number(item.rotation)) ? Number(item.rotation) : 0;
    const src = (item as any).web ?? (item as any).webSrc ?? (item as any).src ?? null;

    return {
      slotIndex: item.slotIndex,
      crop: fit,
      objectPosition: objectPositionFromAlign(align),
      scale: zoom,
      rotate: rotation,
      photo: item.photo?.path
        ? {
            path: item.photo.path,
            ...(item.photo.filename ? { filename: item.photo.filename } : {}),
          }
        : null,
      src,
    };
  });
}

export function buildOverridesPayload(
  pageNumber: number,
  templateId: string | undefined | null,
  items: unknown[],
) {
  return {
    pages: {
      [String(pageNumber)]: {
        ...(templateId ? { templateId } : {}),
        items: serializeItemsForPagesJson(items),
      },
    },
  };
}

export function serializePageForSave(rawPage: PhotobookPage | any, itemsOverride?: unknown[]) {
  const isCover = rawPage?.id === 'cover' || rawPage?.templateId === 'cover' || rawPage?.template === 'cover';
  const n = isCover ? 0 : Math.max(1, Number(rawPage?.n || 1));
  const items = Array.isArray(itemsOverride) ? itemsOverride : Array.isArray(rawPage?.items) ? rawPage.items : [];

  return {
    ...rawPage,
    id: isCover ? 'cover' : String(rawPage?.id || `page-${n}`),
    n,
    templateId: isCover ? 'cover' : (rawPage?.templateId || rawPage?.template || null),
    slots: Array.isArray(rawPage?.slots) ? rawPage.slots : [],
    items: items.map(serializeItemForPagesJson),
    ...(rawPage?.layoutFeedback ? { layoutFeedback: rawPage.layoutFeedback } : {}),
  };
}
