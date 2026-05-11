/*
Copilot prompt:
usePhotobook(hash):
- Loads /api/photobook/pages/{hash} and hydrates Zustand store with normalized items (objectPosition, crop, scale, rotate, src/webSrc).
- Exposes store pages so UI reflects overrides immediately without waiting for refetch.
- Provides mutations for JSON-Patch, add/delete page, build trigger, and progress polling.
Next run:
- Add saveItem/page helpers that also optimistically update the store and append to overrides.json (server route).
*/
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PB } from '../lib/api';
import { api as clientApi } from '../api/client';
import { useEffect } from 'react';
import { usePB } from '../store/photobook';

export function usePhotobook(key: string) {
  const qc = useQueryClient();
  const setInitial = usePB(s=>s.setInitial);
  const pages = usePB(s=>s.pages);

  const pagesQ = useQuery({
    queryKey: ['pages', key],
    // Accept either folder or hash; detect 40-hex hash to use the new API, else legacy folder endpoint
    queryFn: () => (/^[a-f0-9]{40}$/i.test(key) ? PB.getPages(key) : clientApi.getPages(key)) as any,
    enabled: !!key,
  });
  useEffect(() => {
    const data: any = (pagesQ as any)?.data;
    if (data && data.pages) setInitial(key, data.pages);
  }, [key, (pagesQ as any)?.data]);

  const patch = useMutation({
    mutationFn: (patch:any) => PB.patchPages(key, patch),
    onSuccess: () => qc.invalidateQueries({ queryKey:['pages', key] }),
  });

  const addPage = useMutation({
    mutationFn: (page:any) => PB.addPage(key, page),
    onSuccess: () => qc.invalidateQueries({ queryKey:['pages', key] }),
  });

  const delPage = useMutation({
    mutationFn: (pageId:string) => PB.deletePage(key, pageId),
    onSuccess: () => qc.invalidateQueries({ queryKey:['pages', key] }),
  });

  const build = useMutation({
    mutationFn: (payload:any) => PB.build(key, payload),
  });

  const progressQ = useQuery({
    queryKey: ['progress', key],
    queryFn: () => PB.progress(key),
    enabled: false,
    refetchInterval: (data:any) => data?.status?.progress < 100 ? 1000 : false,
  });
  useEffect(() => {
    const data: any = (progressQ as any)?.data;
    if (data?.status?.progress >= 100) {
      qc.invalidateQueries({ queryKey:['pages', key] });
    }
  }, [key, (progressQ as any)?.data]);

  return { pagesQ, pages, patch, addPage, delPage, build, progressQ };
}

// Back-compat alias so components importing { usePages } keep working
export const usePages = usePhotobook;
