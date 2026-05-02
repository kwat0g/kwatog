import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Bom } from '@/types/mrp';

export interface BomListParams extends ListParams {
  product_id?: string;
  is_active?: boolean | string;
}

export interface CreateBomItemRow {
  item_id: string;
  quantity_per_unit: string;
  unit: string;
  waste_factor?: string;
  sort_order?: number;
}

export interface CreateBomData {
  product_id: string;
  items: CreateBomItemRow[];
}

export const bomsApi = {
  list: (params?: BomListParams) =>
    client.get<PaginatedResponse<Bom>>('/mrp/boms', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Bom>>(`/mrp/boms/${id}`).then((r) => r.data.data),
  forProduct: (productId: string) =>
    client.get<ApiSuccess<Bom> | { data: null }>(`/mrp/products/${productId}/bom`).then((r) => r.data.data ?? null),
  // Sprint 6 audit §3.4: BOM authoring entry point. Creates a new BOM
  // version and supersedes any prior active BOM for the same product
  // (BomService::create() handles the version bump in a transaction).
  create: (data: CreateBomData) =>
    client.post<ApiSuccess<Bom>>('/mrp/boms', data).then((r) => r.data.data),
  update: (id: string, data: CreateBomData) =>
    client.put<ApiSuccess<Bom>>(`/mrp/boms/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/mrp/boms/${id}`),
};
