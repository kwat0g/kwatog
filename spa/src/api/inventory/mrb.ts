import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { MrbRecord, CreateMrbData, ReleaseMrbData } from '@/types/inventory';

export interface MrbQualityOptions {
 inspections: Array<{
  id: string;
  inspection_number: string;
  stage: string;
  stage_label?: string;
  status: string;
  status_label?: string;
  batch_quantity: number;
 }>;
 ncrs: Array<{
  id: string;
  ncr_number: string;
  status: string;
  status_label?: string;
  affected_quantity: number;
  inspection: { id: string; inspection_number: string; stage: string; status: string } | null;
 }>;
}

export const mrbApi = {
 options: () => client.get<{ data: { dispositions: Array<{ value: string; label: string }>; statuses: Array<{ value: string; label: string }> } }>('/inventory/mrb/options').then((r) => r.data.data),
 list: (params?: ListParams & { status?: string; item_id?: string }) =>
 client.get<PaginatedResponse<MrbRecord>>('/inventory/mrb', { params }).then((r) => r.data),
 qualityOptions: (itemId: string, search?: string) =>
 client
 .get<{ data: MrbQualityOptions }>('/inventory/mrb/quality-options', {
 params: { item_id: itemId, search: search || undefined, per_page: 50 },
 })
 .then((r) => r.data.data),
 show: (id: string) =>
 client.get<ApiSuccess<MrbRecord>>(`/inventory/mrb/${id}`).then((r) => r.data.data),
 hold: (data: CreateMrbData, idempotencyKey?: string) =>
 client
 .post<ApiSuccess<MrbRecord>>('/inventory/mrb', data, idempotencyKey ? { headers: { 'Idempotency-Key': idempotencyKey } } : undefined)
 .then((r) => r.data.data),
 release: (id: string, data: ReleaseMrbData) =>
 client.post<ApiSuccess<MrbRecord>>(`/inventory/mrb/${id}/release`, data).then((r) => r.data.data),
};
