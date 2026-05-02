import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Machine } from '@/types/mrp';

export interface MachineListParams extends ListParams {
  status?: string;
}

export const machinesApi = {
  list: (params?: MachineListParams) =>
    client.get<PaginatedResponse<Machine>>('/mrp/machines', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Machine>>(`/mrp/machines/${id}`).then((r) => r.data.data),
  transitionStatus: (id: string, to: string, reason?: string) =>
    client.patch<ApiSuccess<Machine>>(`/mrp/machines/${id}/transition-status`, { to, reason }).then((r) => r.data.data),
};
