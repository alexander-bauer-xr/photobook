/*
Copilot prompt:
usePages(folder?) using TanStack Query. Key: ['pages', folder||'default'].
*/
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';

export function usePages(folder?: string) {
  return useQuery({ queryKey: ['pages', folder || 'default'], queryFn: () => api.getPages(folder) });
}
