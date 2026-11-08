import type { InspectionListParams } from '@/api/quality/inspections';

const root = ['quality', 'inspections'] as const;

export const inspectionQueryKeys = {
  all: root,
  lists: [...root, 'list'] as const,
  list: (params: InspectionListParams) => [...root, 'list', params] as const,
  details: [...root, 'detail'] as const,
  detail: (id: string) => [...root, 'detail', id] as const,
  chain: (id: string) => [...root, 'detail', id, 'chain'] as const,
  options: [...root, 'options'] as const,
};
