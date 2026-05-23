import { useQuery } from '@tanstack/react-query';
import { PB } from '../lib/api';
import type { LayoutTemplateGroups } from '../lib/api';

export function useTemplates() {
  return useQuery<LayoutTemplateGroups>({
    queryKey: ['layout-templates'],
    queryFn: () => PB.getTemplates(),
    staleTime: 5 * 60 * 1000,
  });
}