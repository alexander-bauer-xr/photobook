import { useEffect, useMemo, useState } from 'react';
import { PB } from '../../../lib/api';
import type { AlbumRecord } from '../model/photobook.types';

export const isAlbumHash = (value?: string | null) => /^[a-f0-9]{40}$/i.test(value || '');

export const albumMatchesKey = (album: AlbumRecord, key: string) => {
  if (!key) return false;
  return album.hash === key || album.folder === key;
};

export const albumPrimaryKey = (album: AlbumRecord) => album.folder || album.hash;

export function useAlbumSelection(initialAlbumKey = '') {
  const [folder, setFolder] = useState(initialAlbumKey);
  const [albums, setAlbums] = useState<AlbumRecord[]>([]);

  const refreshAlbums = async () => {
    const response = await PB.getAlbums();
    const nextAlbums = response?.albums || [];
    setAlbums(nextAlbums);
    return nextAlbums;
  };

  useEffect(() => {
    refreshAlbums().catch(() => {});
  }, []);

  useEffect(() => {
    if (albums.length === 0) return;
    const matched = albums.find((album) => albumMatchesKey(album, folder));
    if (matched && isAlbumHash(folder) && matched.folder) {
      setFolder(matched.folder);
      return;
    }
    if (!folder) {
      const first = albums[0];
      if (first) setFolder(albumPrimaryKey(first));
    }
  }, [albums, folder]);

  const currentAlbum = useMemo(
    () => albums.find((album) => albumMatchesKey(album, folder)) || null,
    [albums, folder],
  );

  const albumHash = currentAlbum?.hash || (isAlbumHash(folder) ? folder : '');
  const albumFolder = currentAlbum?.folder || (!isAlbumHash(folder) ? folder : '');
  const pagesKey = albumHash || (isAlbumHash(folder) ? folder : '');

  return {
    folder,
    setFolder,
    albums,
    refreshAlbums,
    currentAlbum,
    albumHash,
    albumFolder,
    pagesKey,
  };
}
