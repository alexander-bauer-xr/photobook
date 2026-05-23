import { useCallback, useState } from 'react';
import type { Dispatch, MutableRefObject, SetStateAction } from 'react';
import { PB } from '../../../lib/api';
import type { CandidatePhoto } from '../../../lib/api';
import {
  filePathToAssetUrl,
  normalizeAssetRel,
  relAssetUrl,
  webAssetRelFromUrl,
} from '../model/assetUrls';

type UseReplacePhotoArgs = {
  albumHash: string;
  pageIdx: number;
  page: any;
  pageId: string | null;
  coverItemRef: MutableRefObject<any>;
  setCoverPath: (value: string | null) => void;
  setCoverWebSrc: (value: string | null) => void;
  setCoverPhotoPath: (value: string | null) => void;
  setPageVersion: Dispatch<SetStateAction<number>>;
  persistCover: (
    imageRel?: string | null,
    item?: any,
    sourcePath?: string | null
  ) => Promise<void>;
  persistPage: (target: any, itemsOverride?: any[]) => Promise<void>;
  updateStoreItemWith: (pageId: string, itemIndex: number, updater: (item: any) => any) => void;
};

export function useReplacePhoto({
  albumHash,
  pageIdx,
  page,
  pageId,
  coverItemRef,
  setCoverPath,
  setCoverWebSrc,
  setCoverPhotoPath,
  setPageVersion,
  persistCover,
  persistPage,
  updateStoreItemWith,
}: UseReplacePhotoArgs) {
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerIdx, setDrawerIdx] = useState<number | null>(null);
  const [candidates, setCandidates] = useState<CandidatePhoto[]>([]);
  const [candLoading, setCandLoading] = useState(false);
  const [showingAllCandidates, setShowingAllCandidates] = useState(false);

  const openReplace = useCallback(async (itemIndex: number) => {
    if (!albumHash) return;

    setDrawerIdx(itemIndex);
    setDrawerOpen(true);
    setShowingAllCandidates(false);
    setCandLoading(true);
    try {
      const effectivePage = pageIdx === 0 ? 1 : (page?.n || 1);
      const response = await PB.getCandidates(albumHash, effectivePage);
      setCandidates(response.candidates || []);
    } finally {
      setCandLoading(false);
    }
  }, [albumHash, pageIdx, page]);

  const loadAllCandidates = useCallback(async () => {
    if (!albumHash) return;

    setCandLoading(true);
    try {
      const effectivePage = pageIdx === 0 ? 1 : (page?.n || 1);
      const response = await PB.getCandidates(albumHash, effectivePage, true);
      setCandidates(response.candidates || []);
      setShowingAllCandidates(true);
    } finally {
      setCandLoading(false);
    }
  }, [albumHash, pageIdx, page]);

  const applyReplacement = useCallback((
    cand: CandidatePhoto,
    opts?: { preserveCrop?: boolean }
  ) => {
    if (drawerIdx === null) return;

    // Cover: only update the synthetic cover state
    if (pageIdx === 0) {
      const rel = webAssetRelFromUrl(cand.src || null) || normalizeAssetRel(cand.path);
      const web = cand.src || relAssetUrl(albumHash, rel) || filePathToAssetUrl(cand.path) || null;
      if (rel) setCoverPath(rel);
      setCoverWebSrc(web);
      setCoverPhotoPath(cand.path || null);
      const prevItem = coverItemRef.current && typeof coverItemRef.current === 'object' ? coverItemRef.current : {};
      const preserve = opts?.preserveCrop !== false;
      const nextPhoto = cand.path ? {
        path: cand.path,
        ...(cand.filename ? { filename: cand.filename } : {}),
      } : (prevItem.photo ?? null);
      const nextItem = {
        slotIndex: 0,
        fit: preserve && prevItem.fit === 'contain' ? 'contain' : 'cover',
        align: preserve ? (prevItem.align ?? { x: 0, y: 0 }) : { x: 0, y: 0 },
        offset: preserve ? (prevItem.offset ?? { x: 0, y: 0 }) : { x: 0, y: 0 },
        zoom: preserve && Number.isFinite(Number(prevItem.zoom)) && Number(prevItem.zoom) > 0 ? Number(prevItem.zoom) : 1,
        rotation: preserve && Number.isFinite(Number(prevItem.rotation)) ? Number(prevItem.rotation) : 0,
        auto: false,
        crop: preserve && typeof prevItem.crop === 'string' ? prevItem.crop : (preserve && prevItem.fit === 'contain' ? 'contain' : 'cover'),
        scale: preserve
          ? (Number.isFinite(Number(prevItem.scale)) && Number(prevItem.scale) > 0 ? Number(prevItem.scale)
            : (Number.isFinite(Number(prevItem.zoom)) && Number(prevItem.zoom) > 0 ? Number(prevItem.zoom) : 1))
          : 1,
        rotate: preserve
          ? (Number.isFinite(Number(prevItem.rotate)) ? Number(prevItem.rotate)
            : (Number.isFinite(Number(prevItem.rotation)) ? Number(prevItem.rotation) : 0))
          : 0,
        objectPosition: preserve && typeof prevItem.objectPosition === 'string' ? prevItem.objectPosition : '50% 50%',
        photo: nextPhoto,
        src: web,
      };
      coverItemRef.current = nextItem;
      setDrawerOpen(false);
      setPageVersion((version) => version + 1);
      void persistCover(rel, nextItem, cand.path || null).catch(() => {});
      return;
    }

    if (!page || !pageId) return;
    const it = page.items[drawerIdx];
    const derived = cand.src || filePathToAssetUrl(cand.path) || it?.src || null;
    const preserve = !!opts?.preserveCrop;

    const currentItems = Array.isArray(page.items) ? page.items : [];
    const prev = currentItems[drawerIdx];

    if (!prev) return;

    const nextItem = {
      ...prev,
      photo: {
        ...(prev.photo || {}),
        path: cand.path,
        filename: cand.filename,
      },
      src: derived || undefined,
      web: derived || undefined,
      webSrc: derived || undefined,
      objectPosition: preserve ? prev.objectPosition : '50% 50%',
      scale: preserve ? (prev.scale ?? 1) : 1,
      fit: preserve ? (prev.fit || 'cover') : 'cover',
      align: preserve ? (prev.align || { x: 0, y: 0 }) : { x: 0, y: 0 },
      offset: preserve ? (prev.offset || { x: 0, y: 0 }) : { x: 0, y: 0 },
      zoom: preserve ? (Number(prev.zoom) || 1) : 1,
      rotation: preserve ? (Number(prev.rotation) || 0) : 0,
      auto: false,
    };

    const nextItems = currentItems.map((item: any, index: number) =>
      index === drawerIdx ? nextItem : item,
    );

    updateStoreItemWith(pageId, drawerIdx, () => nextItem);

    setDrawerOpen(false);
    setPageVersion((version) => version + 1);

    void persistPage(page, nextItems).catch(() => {});
  }, [
    albumHash,
    coverItemRef,
    drawerIdx,
    pageIdx,
    page,
    pageId,
    persistCover,
    persistPage,
    setCoverPath,
    setCoverPhotoPath,
    setCoverWebSrc,
    setPageVersion,
    updateStoreItemWith,
  ]);

  return {
    drawerOpen,
    setDrawerOpen,
    drawerIdx,
    setDrawerIdx,
    candidates,
    candLoading,
    showingAllCandidates,
    openReplace,
    loadAllCandidates,
    applyReplacement,
  };
}
