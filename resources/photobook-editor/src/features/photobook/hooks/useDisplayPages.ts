import { useMemo } from 'react';
import type { MutableRefObject } from 'react';

type UseDisplayPagesArgs = {
  queryData: any;
  pages: any[];
  coverWebSrc: string | null;
  coverPath: string | null;
  coverPhotoPath: string | null;
  coverItemRef: MutableRefObject<any>;
};

const isCoverPage = (page: any) => {
  if (!page) return false;
  const id = (page.id || '').toString().toLowerCase();
  const tpl = (page.templateId || page.template || '').toString().toLowerCase();
  return id === 'cover' || tpl === 'cover';
};

export function useDisplayPages({
  queryData,
  pages,
  coverWebSrc,
  coverPath,
  coverPhotoPath,
  coverItemRef,
}: UseDisplayPagesArgs): any[] {
  return useMemo(() => {
    const qPages: any[] = ((queryData?.pages || []) as any[]);
    const arr: any[] = (Array.isArray(pages) && pages.length ? pages : qPages) as any[];
    const cov = queryData?.cover;
    const topRatio = 0.649;
    const synthesizedSrc = coverWebSrc || cov?.webSrc || null;
    const originalPath = coverPhotoPath || cov?.sourcePath || null;
    const originalFilename = originalPath ? (originalPath.split('/') || []).pop() : undefined;

    // If album already contains a real page 0 cover, use it as-is
    if (Array.isArray(arr) && arr.length && isCoverPage(arr[0])) {
      const existing = arr[0] || {};
      const clonedItems = Array.isArray(existing.items) ? existing.items.map((it: any, idx: number) => {
        if (idx === 0) {
          const next: any = { ...it };
          if (synthesizedSrc) next.src = synthesizedSrc;
          if (originalPath) {
            next.photo = {
              ...(next.photo || {}),
              path: originalPath,
              ...(originalFilename ? { filename: originalFilename } : {}),
            };
          }
          // Preserve runtime image dimensions so drag/zoom work after pageVersion bumps
          if (coverItemRef.current?._iw) {
            next._iw = coverItemRef.current._iw;
            next._ih = coverItemRef.current._ih;
          }
          return next;
        }
        return { ...it };
      }) : [];
      const coverSlots = [{ x: 0, y: 0, w: 1, h: topRatio }];
      const first = {
        ...existing,
        slots: coverSlots,
        items: clonedItems,
      };
      const rest = arr.slice(1).filter((page: any) => !isCoverPage(page));
      return [first, ...rest];
    }

    // Otherwise, synthesize a page 0 cover from top-level cover info
    const coverSlots = [{ x: 0, y: 0, w: 1, h: topRatio }];
    const coverPg: any = {
      id: 'cover', n: 0, templateId: 'cover',
      slots: coverSlots,
      items: [{
        // Preserve runtime image dimensions so drag/zoom work after pageVersion bumps
        ...(coverItemRef.current?._iw ? { _iw: coverItemRef.current._iw, _ih: coverItemRef.current._ih } : {}),
        slotIndex: 0,
        src: synthesizedSrc || undefined,
        photo: originalPath ? { path: originalPath, ...(originalFilename ? { filename: originalFilename } : {}) } : null,
        // legacy
        objectPosition: cov?.objectPosition || '50% 50%',
        crop: 'cover',
        scale: (typeof cov?.zoom === 'number' && cov?.zoom > 0) ? cov?.zoom : (cov?.scale || 1),
        rotate: (Number.isFinite(cov?.rotation) ? Number(cov?.rotation) : (cov?.rotate || 0)),
        // canonical
        align: cov?.align || undefined,
        offset: cov?.offset || { x: 0, y: 0 },
        zoom: (typeof cov?.zoom === 'number' && cov?.zoom > 0) ? Number(cov?.zoom) : (cov?.scale || 1),
        rotation: Number.isFinite(cov?.rotation) ? Number(cov?.rotation) : (cov?.rotate || 0),
        auto: cov?.auto === true,
      }],
    };
    const rest = Array.isArray(arr) ? arr.filter((page: any) => !isCoverPage(page)) : [];
    return [coverPg, ...rest];
  }, [pages, queryData, coverWebSrc, coverPath, coverPhotoPath, coverItemRef]);
}
