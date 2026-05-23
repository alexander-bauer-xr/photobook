import { useCallback } from 'react';
import { PB } from '../../../lib/api';
import { serializePageForSave } from '../model/photobook.serializers';

export type PersistWithStatus = (task: () => Promise<any>) => Promise<any>;

type UsePagePersistenceArgs = {
  albumHash: string;
  persistWithStatus: PersistWithStatus;
};

export function usePagePersistence({ albumHash, persistWithStatus }: UsePagePersistenceArgs) {
  const persistPage = useCallback(async (target: any, itemsOverride?: any[]) => {
    if (!albumHash || !target) throw new Error('Album hash is not ready yet.');
    const payload = serializePageForSave(target, itemsOverride);
    await persistWithStatus(() => PB.savePage(albumHash, payload));
  }, [albumHash, persistWithStatus]);

  return {
    persistPage,
  };
}
