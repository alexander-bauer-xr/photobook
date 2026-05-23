import { useCallback, useEffect, useRef, useState } from 'react';
import { PB } from '../../../lib/api';

type UseBuildPhotobookArgs = {
  folder: string;
  albumHash: string;
  albumFolder: string;
  coverTitle: string;
  coverSubtitle: string;
  coverDateText: string;
  coverShowDate: boolean;
  currentCoverImageRel: () => string | null;
  currentCoverItem: () => any;
  buildCoverPersistencePayload: (
    imageRel: string | null,
    opts?: { item?: any; sourcePath?: string | null }
  ) => Record<string, any> | null;
  setFolder: (value: string) => void;
  refreshAlbums: () => Promise<any[]>;
  refetchPages: () => void;
};

export function useBuildPhotobook({
  folder,
  albumHash,
  albumFolder,
  coverTitle,
  coverSubtitle,
  coverDateText,
  coverShowDate,
  currentCoverImageRel,
  currentCoverItem,
  buildCoverPersistencePayload,
  setFolder,
  refreshAlbums,
  refetchPages,
}: UseBuildPhotobookArgs) {
  const [isBuilding, setIsBuilding] = useState(false);
  const [buildProgress, setBuildProgress] = useState(0);
  const [buildMessage, setBuildMessage] = useState('');
  const progressTimer = useRef<number | null>(null);

  const clearProgressTimer = useCallback(() => {
    if (progressTimer.current) {
      window.clearInterval(progressTimer.current);
      progressTimer.current = null;
    }
  }, []);

  useEffect(() => () => {
    clearProgressTimer();
  }, [clearProgressTimer]);

  const handleBuild = useCallback(async () => {
    const buildFolder = albumFolder || folder;
    if (!buildFolder) return;

    const hasAlbumHash = !!(albumHash && /^[a-f0-9]{40}$/i.test(albumHash));
    const coverImageRelForApi = currentCoverImageRel();
    const coverPayloadForApi = buildCoverPersistencePayload(coverImageRelForApi, { item: currentCoverItem() });

    try {
      if (hasAlbumHash && coverPayloadForApi) {
        try {
          await PB.setCover(albumHash, coverPayloadForApi);
        } catch {}
      }

      setIsBuilding(true);
      setBuildProgress(0);
      setBuildMessage('Starting…');

      const payload: Record<string, string> = { folder: buildFolder };
      if (coverTitle) payload.title = coverTitle;
      if (coverImageRelForApi) payload.cover_image = coverImageRelForApi;

      const trimmedSubtitle = (coverSubtitle || '').trim();
      const trimmedDate = (coverDateText || '').trim();
      if (trimmedSubtitle) payload.cover_subtitle = trimmedSubtitle;
      if (trimmedDate) payload.cover_date = trimmedDate;
      payload.cover_show_date = coverShowDate ? '1' : '0';

      let buildHash = albumHash;
      if (hasAlbumHash) {
        await PB.build(albumHash, payload);
      } else {
        const response = await PB.buildByFolder(payload);
        buildHash = response?.hash || '';
        if (buildHash) setFolder(buildHash);
      }

      clearProgressTimer();

      const pollHash = buildHash;
      progressTimer.current = window.setInterval(async () => {
        try {
          const response: any = await PB.progress(pollHash);
          const progress = response?.status?.progress ?? 0;
          const message = response?.status?.step || response?.status?.state || '';
          setBuildProgress(progress);
          setBuildMessage(message);

          if (progress >= 100 || response?.status?.state === 'finished') {
            clearProgressTimer();
            setIsBuilding(false);
            setBuildMessage('');

            const nextAlbums = await refreshAlbums().catch(() => [] as any[]);
            const builtAlbum = nextAlbums.find((album: any) => album.hash === pollHash);
            if (builtAlbum) {
              setFolder(builtAlbum.folder || builtAlbum.hash);
            }

            refetchPages();
          }
        } catch {
          clearProgressTimer();
          setIsBuilding(false);
          setBuildMessage('');
          refetchPages();
        }
      }, 1000);
    } catch {
      clearProgressTimer();
      setIsBuilding(false);
      setBuildMessage('Build failed to start');
    }
  }, [
    albumFolder,
    albumHash,
    buildCoverPersistencePayload,
    clearProgressTimer,
    coverDateText,
    coverShowDate,
    coverSubtitle,
    coverTitle,
    currentCoverImageRel,
    currentCoverItem,
    folder,
    refreshAlbums,
    refetchPages,
    setFolder,
  ]);

  return {
    isBuilding,
    buildProgress,
    buildMessage,
    handleBuild,
  };
}
