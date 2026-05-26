import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { Dispatch, MutableRefObject, SetStateAction } from 'react';
import {
  normalizeAssetRel,
  relAssetUrl,
  webAssetRelFromUrl,
} from '../model/assetUrls';

const defaultCoverDateText = () => new Date().toLocaleString('en-US', { month: 'long', year: 'numeric' });

const isCoverPage = (page: any) => {
  if (!page) return false;
  const id = (page.id || '').toString().toLowerCase();
  const tpl = (page.templateId || page.template || '').toString().toLowerCase();
  return id === 'cover' || tpl === 'cover';
};

const parseAlign = (candidate: any, fallbackPos?: string): { x: number; y: number } => {
  if (candidate && typeof candidate === 'object' && Number.isFinite(Number(candidate.x)) && Number.isFinite(Number(candidate.y))) {
    return {
      x: Math.max(-1, Math.min(1, Number(candidate.x))),
      y: Math.max(-1, Math.min(1, Number(candidate.y))),
    };
  }
  if (typeof fallbackPos === 'string') {
    const parts = fallbackPos.trim().split(/\s+/, 2);
    const px = Number((parts[0] || '').replace('%', ''));
    const py = Number((parts[1] || '').replace('%', ''));
    if (Number.isFinite(px) && Number.isFinite(py)) {
      return {
        x: Math.max(-1, Math.min(1, (px - 50) / 50)),
        y: Math.max(-1, Math.min(1, (py - 50) / 50)),
      };
    }
  }
  return { x: 0, y: 0 };
};

const pickString = (...values: any[]) => {
  for (const value of values) {
    if (typeof value === 'string') {
      const trimmed = value.trim();
      if (trimmed.length) return trimmed;
    }
  }
  return '';
};

const pickBool = (...values: any[]): boolean | null => {
  for (const value of values) {
    if (typeof value === 'boolean') return value;
    if (value === 0 || value === 1) return !!value;
  }
  return null;
};

type UseCoverEditorArgs = {
  queryData: any;
  pages: any[];
  folder: string;
  albumHash: string;
  setPageVersion: Dispatch<SetStateAction<number>>;
  suppressNextCanvasDirty: MutableRefObject<boolean>;
};

export function useCoverEditor({
  queryData,
  pages,
  folder: _folder,
  albumHash,
  setPageVersion,
  suppressNextCanvasDirty,
}: UseCoverEditorArgs) {
  const [coverTitle, setCoverTitle] = useState('');
  const [coverPath, setCoverPath] = useState<string | null>(null);
  const [coverWebSrc, setCoverWebSrc] = useState<string | null>(null);
  const [coverPhotoPath, setCoverPhotoPath] = useState<string | null>(null);
  const [coverSubtitle, setCoverSubtitle] = useState('');
  const [coverDateText, setCoverDateText] = useState('');
  const [coverShowDate, setCoverShowDate] = useState(false);
  const coverItemRef = useRef<any>(null);

  useEffect(() => {
    const cov = queryData?.cover;
    const opts = (queryData?.options ?? {}) as Record<string, any>;

    const subtitleVal = pickString(cov?.cover_subtitle, cov?.subtitle, opts.cover_subtitle, opts.subtitle);
    const rawDate = pickString(cov?.cover_date, cov?.date, opts.cover_date, opts.date);
    const showDatePref = pickBool(cov?.cover_show_date, cov?.show_date, opts.cover_show_date, opts.show_date);
    const defaultShowDate = showDatePref ?? (rawDate !== '');
    const fallbackDateText = defaultShowDate ? defaultCoverDateText() : '';
    const computedDateText = rawDate || fallbackDateText;
    setCoverSubtitle(subtitleVal);
    setCoverDateText(computedDateText);
    setCoverShowDate(defaultShowDate);

    const fallbackTitle = pickString(cov?.title, opts.cover_title, opts.title);

    const coverPage = Array.isArray(queryData?.pages)
      ? queryData.pages.find((page: any) => isCoverPage(page))
      : null;
    const coverItem = Array.isArray(coverPage?.items) ? coverPage.items[0] : null;
    const itemWebSrc = coverItem?.webSrc || coverItem?.web || coverItem?.src || null;
    const itemImageRel =
      normalizeAssetRel(coverItem?.rel)
      || webAssetRelFromUrl(itemWebSrc)
      || normalizeAssetRel(coverItem?.photo?.path);
    const coverImageRel =
      normalizeAssetRel(cov?.image)
      || webAssetRelFromUrl(cov?.webSrc)
      || itemImageRel;
    const sourcePath = pickString(cov?.sourcePath, cov?.source_path, coverItem?.photo?.path, coverImageRel) || null;
    const resolvedSource = sourcePath;
    const resolvedFilename = resolvedSource ? ((resolvedSource.split('/') || []).pop() || undefined) : undefined;

    if (cov || coverItem || fallbackTitle) {
      const resolvedWebSrc =
        cov?.webSrc
        || itemWebSrc
        || relAssetUrl(albumHash, coverImageRel);
      setCoverTitle(cov?.title || fallbackTitle || '');
      setCoverPath(coverImageRel || null);
      setCoverPhotoPath(resolvedSource || null);
      setCoverWebSrc((prev) => {
        const nextWebSrc = resolvedWebSrc || null;

        if (nextWebSrc !== prev) {
          suppressNextCanvasDirty.current = true;
          setPageVersion((version) => version + 1);
        }
        return nextWebSrc;
      });

      const align = parseAlign(cov?.align ?? coverItem?.align, cov?.objectPosition ?? coverItem?.objectPosition);
      const offsetSource = (cov?.offset && typeof cov.offset === 'object') ? cov.offset : coverItem?.offset;
      const offset = (offsetSource && typeof offsetSource === 'object') ? {
        x: Number.isFinite(Number(offsetSource.x)) ? Number(offsetSource.x) : 0,
        y: Number.isFinite(Number(offsetSource.y)) ? Number(offsetSource.y) : 0,
      } : { x: 0, y: 0 };
      const zoomVal = Number.isFinite(Number(cov?.zoom)) && Number(cov?.zoom) > 0
        ? Number(cov?.zoom)
        : (Number.isFinite(Number(coverItem?.zoom)) && Number(coverItem?.zoom) > 0
          ? Number(coverItem?.zoom)
          : (Number.isFinite(Number(cov?.scale)) && Number(cov?.scale) > 0
            ? Number(cov?.scale)
            : (Number.isFinite(Number(coverItem?.scale)) && Number(coverItem?.scale) > 0 ? Number(coverItem?.scale) : 1)));
      const rotationVal = Number.isFinite(Number(cov?.rotation))
        ? Number(cov?.rotation)
        : (Number.isFinite(Number(coverItem?.rotation))
          ? Number(coverItem?.rotation)
          : (Number.isFinite(Number(cov?.rotate)) ? Number(cov?.rotate)
            : (Number.isFinite(Number(coverItem?.rotate)) ? Number(coverItem?.rotate) : 0)));
      const coverFit = (cov?.fit === 'contain' || cov?.crop === 'contain' || coverItem?.fit === 'contain' || coverItem?.crop === 'contain') ? 'contain' : 'cover';
      const objectPosition = typeof (cov?.objectPosition ?? coverItem?.objectPosition) === 'string' && String(cov?.objectPosition ?? coverItem?.objectPosition).trim() !== ''
        ? String(cov?.objectPosition ?? coverItem?.objectPosition).trim()
        : `${Math.round(50 + align.x * 50)}% ${Math.round(50 + align.y * 50)}%`;
      coverItemRef.current = {
        slotIndex: 0,
        fit: coverFit,
        align,
        offset,
        zoom: zoomVal,
        rotation: rotationVal,
        auto: (cov?.auto ?? coverItem?.auto) === true,
        crop: coverFit,
        scale: Number.isFinite(Number(cov?.scale)) && Number(cov?.scale) > 0
          ? Number(cov?.scale)
          : (Number.isFinite(Number(coverItem?.scale)) && Number(coverItem?.scale) > 0 ? Number(coverItem?.scale) : zoomVal),
        rotate: Number.isFinite(Number(cov?.rotate))
          ? Number(cov?.rotate)
          : (Number.isFinite(Number(coverItem?.rotate)) ? Number(coverItem?.rotate) : rotationVal),
        objectPosition,
        photo: resolvedSource ? {
          path: resolvedSource,
          ...(resolvedFilename ? { filename: resolvedFilename } : {}),
        } : null,
        src: resolvedWebSrc || null,
      };
    } else if (queryData !== undefined) {
      // Only reset cover state when the query has actually returned data with no cover.
      // Don't clear when data is undefined (query still loading / pagesKey transition).
      setCoverTitle(fallbackTitle || '');
      setCoverPath(null);
      setCoverWebSrc(null);
      setCoverPhotoPath(null);
      coverItemRef.current = null;
    }
  }, [albumHash, queryData, setPageVersion, suppressNextCanvasDirty]);

  const coverPageItem = useMemo(() => {
    const coverPage = Array.isArray(pages) ? pages.find((page) => isCoverPage(page)) : null;
    return Array.isArray(coverPage?.items) ? coverPage.items[0] : null;
  }, [pages]);

  const buildCoverPersistencePayload = useCallback((imageRel: string | null, opts?: { item?: any; sourcePath?: string | null }) => {
    if (!imageRel) return null;
    const normalizedTitle = (coverTitle || '').trim();
    const normalizedSubtitle = (coverSubtitle || '').trim();
    const normalizedDate = (coverDateText || '').trim();
    const showDateFlag = coverShowDate && normalizedDate !== '';
    const payload: Record<string, any> = {
      image: imageRel,
      title: normalizedTitle,
      subtitle: normalizedSubtitle,
      date: showDateFlag ? normalizedDate : '',
      show_date: showDateFlag,
      ...((opts?.sourcePath || coverPhotoPath) ? { source_path: opts?.sourcePath || coverPhotoPath } : {}),
    };
    const item = opts?.item ?? coverItemRef.current ?? null;
    if (item && typeof item === 'object') {
      const fit = item.fit === 'contain' ? 'contain' : 'cover';
      payload.fit = fit;
      payload.crop = fit;
      if (typeof item.crop === 'string') payload.crop = item.crop;
      const alignX = Number.isFinite(Number(item.align?.x)) ? Math.max(-1, Math.min(1, Number(item.align.x))) : 0;
      const alignY = Number.isFinite(Number(item.align?.y)) ? Math.max(-1, Math.min(1, Number(item.align.y))) : 0;
      payload.align = { x: alignX, y: alignY };
      const offX = Number.isFinite(Number(item.offset?.x)) ? Number(item.offset.x) : 0;
      const offY = Number.isFinite(Number(item.offset?.y)) ? Number(item.offset.y) : 0;
      payload.offset = { x: offX, y: offY };
      if (Number.isFinite(Number(item.zoom)) && Number(item.zoom) > 0) payload.zoom = Number(item.zoom);
      if (Number.isFinite(Number(item.rotation))) payload.rotation = Number(item.rotation);
      if (typeof item.auto === 'boolean') payload.auto = item.auto;
      const legacyScale = Number.isFinite(Number(item.scale)) && Number(item.scale) > 0 ? Number(item.scale) : undefined;
      if (legacyScale !== undefined) payload.scale = legacyScale;
      if (Number.isFinite(Number(item.rotate))) payload.rotate = Number(item.rotate);
      const objectPosition = typeof item.objectPosition === 'string' && item.objectPosition.trim() !== ''
        ? item.objectPosition.trim()
        : `${Math.round(50 + alignX * 50)}% ${Math.round(50 + alignY * 50)}%`;
      payload.object_position = objectPosition;
      if (!payload.source_path && item.photo?.path) {
        payload.source_path = item.photo.path;
      }
    }
    return payload;
  }, [coverDateText, coverPhotoPath, coverShowDate, coverSubtitle, coverTitle]);

  const coverMetaForEditor = useMemo(() => {
    const title = (coverTitle || '').trim();
    const subtitle = (coverSubtitle || '').trim();
    const rawDate = (coverDateText || '').trim();
    const date = coverShowDate && rawDate ? rawDate : null;
    return {
      title: title ? title : null,
      subtitle: subtitle ? subtitle : null,
      date: date ? date : null,
      hasPhoto: !!(coverWebSrc || coverPath || coverPhotoPath || coverPageItem?.src || coverPageItem?.photo?.path),
    };
  }, [coverDateText, coverPath, coverPhotoPath, coverShowDate, coverSubtitle, coverTitle, coverWebSrc, coverPageItem]);

  const currentCoverItem = useCallback(() => coverItemRef.current ?? coverPageItem ?? null, [coverPageItem]);

  const currentCoverImageRel = useCallback(() => {
    const item = currentCoverItem();
    return (
      coverPath
      || webAssetRelFromUrl(coverWebSrc)
      || normalizeAssetRel(item?.rel)
      || webAssetRelFromUrl(item?.webSrc || item?.web || item?.src)
      || normalizeAssetRel(item?.photo?.path)
      || null
    );
  }, [coverPath, coverWebSrc, currentCoverItem]);

  return {
    coverTitle,
    setCoverTitle,
    coverPath,
    setCoverPath,
    coverWebSrc,
    setCoverWebSrc,
    coverPhotoPath,
    setCoverPhotoPath,
    coverSubtitle,
    setCoverSubtitle,
    coverDateText,
    setCoverDateText,
    coverShowDate,
    setCoverShowDate,
    coverItemRef,
    buildCoverPersistencePayload,
    coverMetaForEditor,
    currentCoverItem,
    currentCoverImageRel,
  };
}
