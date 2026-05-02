import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Holiday, CreateHolidayData, UpdateHolidayData } from '@/types/attendance';

export interface HolidayListParams extends ListParams {
  year?: number;
  type?: string;
  from?: string;
  to?: string;
}

export const holidaysApi = {
  list: (params?: HolidayListParams) =>
    client.get<PaginatedResponse<Holiday>>('/attendance/holidays', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Holiday>>(`/attendance/holidays/${id}`).then((r) => r.data.data),
  create: (data: CreateHolidayData) =>
    client.post<ApiSuccess<Holiday>>('/attendance/holidays', data).then((r) => r.data.data),
  update: (id: string, data: UpdateHolidayData) =>
    client.put<ApiSuccess<Holiday>>(`/attendance/holidays/${id}`, data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/attendance/holidays/${id}`),
};
