import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Mold } from '@/types/mrp';

export interface MoldListParams extends ListParams {
  product_id?: string;
  status?: string;
  nearing_limit?: boolean | string;
}

export const moldsApi = {
  list: (params?: MoldListParams) =>
    client.get<PaginatedResponse<Mold>>('/mrp/molds', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Mold>>(`/mrp/molds/${id}`).then((r) => r.data.data),
  history: (id: string) =>
    client.get<{ data: Array<{ id: string; event_type: string; description: string | null; event_date: string; shot_count_at_event: number }> }>(`/mrp/molds/${id}/history`).then((r) => r.data.data),
  syncCompatibility: (id: string, machineIds: string[]) =>
    client.post<ApiSuccess<Mold>>(`/mrp/molds/${id}/compatibility`, { machine_ids: machineIds }).then((r) => r.data.data),
};
