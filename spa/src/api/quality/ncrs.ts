import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
 Ncr,
 NcrAction,
 NcrActionType,
 NcrDisposition,
 NcrSeverity,
 NcrSource,
 NcrStatus,
 CreateNcrData,
 EffectivenessStatus,
} from '@/types/quality';

export interface NcrAssignee {
 id: string;
 name: string;
}

export interface BulkCloseResponse {
 summary: { total: number; success: number; skipped: number; failed: number };
 results: Array<{ ncr_id: string; status: 'success' | 'skipped' | 'failed'; message: string }>;
}

export interface NcrListParams extends ListParams {
 source?: NcrSource;
 severity?: NcrSeverity;
  status?: NcrStatus | '';
 disposition?: NcrDisposition;
 product_id?: string;
 inspection_id?: string;
}

export const ncrsApi = {
 options: () => client.get<{ data: { sources: Array<{ value: string; label: string }>; severities: Array<{ value: string; label: string }>; statuses: Array<{ value: string; label: string }>; actions: Array<{ value: string; label: string }>; dispositions: Array<{ value: string; label: string }>; default_disposition: string } }>('/quality/ncrs/options').then((r) => r.data.data),
 list: (params?: NcrListParams) =>
 client.get<PaginatedResponse<Ncr>>('/quality/ncrs', { params }).then((r) => r.data),
 assignees: () =>
 client.get<{ data: NcrAssignee[] }>('/quality/ncrs/assignees').then((r) => r.data.data),
 show: (id: string) =>
 client.get<ApiSuccess<Ncr>>(`/quality/ncrs/${id}`).then((r) => r.data.data),
 create: (data: CreateNcrData) =>
 client.post<ApiSuccess<Ncr>>('/quality/ncrs', data).then((r) => r.data.data),
 addAction: (
 id: string,
 data: { action_type: NcrActionType; description: string; performed_at?: string; owner_id?: string; due_date?: string }
 ) =>
 client.post<ApiSuccess<NcrAction>>(`/quality/ncrs/${id}/actions`, data).then((r) => r.data.data),
 due: (params?: { page?: number; per_page?: number }) =>
 client.get<PaginatedResponse<NcrAction>>('/quality/ncrs/effectiveness/due', { params }).then((r) => r.data),
 verifyAction: (ncrId: string, actionId: string, data: { effectiveness_status: EffectivenessStatus; notes: string }) =>
 client.patch<ApiSuccess<NcrAction>>(`/quality/ncrs/${ncrId}/actions/${actionId}/verify`, data).then((r) => r.data.data),
 setDisposition: (
 id: string,
 data: { disposition: NcrDisposition; root_cause?: string; corrective_action?: string }
 ) =>
 client.patch<ApiSuccess<Ncr>>(`/quality/ncrs/${id}/disposition`, data).then((r) => r.data.data),
 close: (id: string) =>
 client.post<ApiSuccess<Ncr>>(`/quality/ncrs/${id}/close`).then((r) => r.data.data),
 cancel: (id: string, reason?: string) =>
 client.post<ApiSuccess<Ncr>>(`/quality/ncrs/${id}/cancel`, { reason }).then((r) => r.data.data),
 bulkClose: (ncr_ids: string[], resolution_note?: string) =>
 client.post<{ data: BulkCloseResponse }>('/quality/ncrs/bulk-close', { ncr_ids, resolution_note }).then((r) => r.data.data),
};
