import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Position, CreatePositionData, UpdatePositionData } from '@/types/hr';

export interface PositionListParams extends ListParams {
  department_id?: string;
}

export const positionsApi = {
  list: (params?: PositionListParams) =>
    client.get<PaginatedResponse<Position>>('/hr/positions', { params }).then((r) => r.data),

  show: (id: string) =>
    client.get<ApiSuccess<Position>>(`/hr/positions/${id}`).then((r) => r.data.data),

  create: (data: CreatePositionData) =>
    client.post<ApiSuccess<Position>>('/hr/positions', data).then((r) => r.data.data),

  update: (id: string, data: UpdatePositionData) =>
    client.put<ApiSuccess<Position>>(`/hr/positions/${id}`, data).then((r) => r.data.data),

  delete: (id: string) => client.delete(`/hr/positions/${id}`),
};
