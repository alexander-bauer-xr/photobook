/*
Copilot prompt:
usePages(folder?) using TanStack Query. Key: ['pages', folder||'default'].
*/
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';

export function usePages(folder?: string) {
  const f = folder || '';
  return useQuery({
    queryKey: ['pages', f || 'default'],
    queryFn: () => api.getPages(f),
    enabled: !!f,
  });
}
