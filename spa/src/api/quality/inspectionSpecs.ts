import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { InspectionSpec, InspectionSpecRevision, UpsertInspectionSpecData } from '@/types/quality';

export interface InspectionSpecListParams extends ListParams {
 product_id?: string;
 is_active?: boolean | string;
 search?: string;
 trashed?: 'with' | 'only';
}

/** SPC capability indices for a single inspection parameter. */
export interface SpcResult {
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

export interface InspectionSpecSpcResponse {
 data: Record<string, SpcResult>;
 meta?: {
  population_policy?: 'current_revision_only' | string;
  revision_id?: string | null;
  revision_version?: number | null;
 };
}

export const inspectionSpecsApi = {
 options: () => client.get<{ data: { parameter_types: Array<{ value: string; label: string }> } }>('/quality/inspection-specs/options').then((r) => r.data.data),
 list: (params?: InspectionSpecListParams) =>
 client.get<PaginatedResponse<InspectionSpec>>('/quality/inspection-specs', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<InspectionSpec>>(`/quality/inspection-specs/${id}`).then((r) => r.data.data),
 revisions: (id: string) =>
 client.get<{ data: InspectionSpecRevision[] }>(`/quality/inspection-specs/${id}/revisions`).then((r) => r.data.data),
 revision: (id: string, revisionId: string) =>
 client.get<ApiSuccess<InspectionSpecRevision>>(`/quality/inspection-specs/${id}/revisions/${revisionId}`).then((r) => r.data.data),
 forProduct: (productId: string) =>
 client.get<{ data: InspectionSpec | null }>(`/quality/products/${productId}/inspection-spec`).then((r) => r.data.data ?? null),
 upsert: (data: UpsertInspectionSpecData) =>
 client.post<ApiSuccess<InspectionSpec>>('/quality/inspection-specs', data).then((r) => r.data.data),
 deactivate: (id: string) =>
 client.delete<ApiSuccess<InspectionSpec>>(`/quality/inspection-specs/${id}`).then((r) => r.data.data),
 restore: (id: string) =>
 client.patch<ApiSuccess<InspectionSpec>>(`/quality/inspection-specs/${id}/restore`).then((r) => r.data.data),
 spc: (id: string) =>
 client.get<InspectionSpecSpcResponse>(`/quality/inspection-specs/${id}/spc`).then((r) => r.data),
};
