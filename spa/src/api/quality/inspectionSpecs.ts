import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { InspectionSpec, UpsertInspectionSpecData } from '@/types/quality';

export interface InspectionSpecListParams extends ListParams {
  product_id?: string;
  is_active?: boolean | string;
}

export const inspectionSpecsApi = {
  list: (params?: InspectionSpecListParams) =>
    client.get<PaginatedResponse<InspectionSpec>>('/quality/inspection-specs', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<InspectionSpec>>(`/quality/inspection-specs/${id}`).then((r) => r.data.data),
  forProduct: (productId: string) =>
    client.get<{ data: InspectionSpec | null }>(`/quality/products/${productId}/inspection-spec`).then((r) => r.data.data ?? null),
  upsert: (data: UpsertInspectionSpecData) =>
    client.post<ApiSuccess<InspectionSpec>>('/quality/inspection-specs', data).then((r) => r.data.data),
  deactivate: (id: string) =>
    client.delete<ApiSuccess<InspectionSpec>>(`/quality/inspection-specs/${id}`).then((r) => r.data.data),
};
