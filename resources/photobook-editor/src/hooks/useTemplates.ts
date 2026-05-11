import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import type { LayoutTemplateGroups } from '../api/types';

export function useTemplates() {
  return useQuery<LayoutTemplateGroups>({
    queryKey: ['layout-templates'],
    queryFn: () => api.getTemplates(),
    staleTime: 5 * 60 * 1000,
  });
}