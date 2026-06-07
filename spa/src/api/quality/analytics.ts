import { client } from '../client';
import type { ParetoResult } from '@/types/quality';

export interface ParetoFilters {
  from?: string;
  to?: string;
  product_id?: string;
  stage?: 'incoming' | 'in_process' | 'outgoing';
  limit?: number;
}

export interface ParetoDrillRow {
  id: string;
  inspection_number: string;
  stage: string;
  status: string;
  defect_count: number;
  completed_at: string | null;
  product: { id: string; part_number: string; name: string } | null;
}

export interface SpcCapabilityItem {
  parameter_name: string;
  unit: string | null;
  cp: number;
  cpk: number;
  cpu: number;
  cpl: number;
  mean: number;
  std_dev: number;
  sample_count: number;
  usl: number;
  lsl: number;
}

export const analyticsApi = {
  defectPareto: (filters?: ParetoFilters) =>
    client.get<{ data: ParetoResult }>('/quality/analytics/defect-pareto', { params: filters }).then((r) => r.data.data),
  paretoDrillDown: (parameter_name: string, filters?: ParetoFilters) =>
    client
      .get<{ data: ParetoDrillRow[] }>('/quality/analytics/defect-pareto/drill', {
        params: { ...filters, parameter_name },
      })
      .then((r) => r.data.data),
  spcForSpec: (specId: string) =>
    client
      .get<{ data: Record<string, SpcCapabilityItem> }>(`/quality/inspection-specs/${specId}/spc`)
      .then((r) => r.data.data),
};

