import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { MrbRecord, CreateMrbData, ReleaseMrbData } from '@/types/inventory';

export const mrbApi = {
 options: () => client.get<{ data: { dispositions: Array<{ value: string; label: string }>; statuses: Array<{ value: string; label: string }> } }>('/inventory/mrb/options').then((r) => r.data.data),
 list: (params?: ListParams & { status?: string; item_id?: string }) =>
 client.get<PaginatedResponse<MrbRecord>>('/inventory/mrb', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<MrbRecord>>(`/inventory/mrb/${id}`).then((r) => r.data.data),
 hold: (data: CreateMrbData) =>
 client.post<ApiSuccess<MrbRecord>>('/inventory/mrb', data).then((r) => r.data.data),
 release: (id: string, data: ReleaseMrbData) =>
 client.post<ApiSuccess<MrbRecord>>(`/inventory/mrb/${id}/release`, data).then((r) => r.data.data),
};
