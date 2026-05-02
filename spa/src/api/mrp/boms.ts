import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Bom } from '@/types/mrp';

export interface BomListParams extends ListParams {
  product_id?: string;
  is_active?: boolean | string;
}

export const bomsApi = {
  list: (params?: BomListParams) =>
    client.get<PaginatedResponse<Bom>>('/mrp/boms', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Bom>>(`/mrp/boms/${id}`).then((r) => r.data.data),
  forProduct: (productId: string) =>
    client.get<ApiSuccess<Bom> | { data: null }>(`/mrp/products/${productId}/bom`).then((r) => r.data.data ?? null),
  delete: (id: string) =>
    client.delete(`/mrp/boms/${id}`),
};
