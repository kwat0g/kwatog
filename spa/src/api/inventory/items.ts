import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Item, CreateItemData, UpdateItemData, ItemCategory, ItemQualityPlan, QualityPlanParameter } from '@/types/inventory';

export interface ItemListParams extends ListParams {
  item_type?: string;
  category_id?: string;
  is_active?: boolean | string;
  is_critical?: boolean | string;
  stock_status?: 'critical' | 'low' | 'ok';
}

export const itemsApi = {
  options: () => client.get<{ data: { item_types: Array<{ value: string; label: string }>; reorder_methods: Array<{ value: string; label: string }>; adjustment_directions: Array<{ value: string; label: string }>; stock_statuses: Array<{ value: string; label: string }> } }>('/inventory/items/options').then((r) => r.data.data),
  list: (params?: ItemListParams) =>
    client.get<PaginatedResponse<Item>>('/inventory/items', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Item>>(`/inventory/items/${id}`).then((r) => r.data.data),
  create: (data: CreateItemData) =>
    client.post<ApiSuccess<Item>>('/inventory/items', data).then((r) => r.data.data),
  update: (id: string, data: UpdateItemData) =>
    client.put<ApiSuccess<Item>>(`/inventory/items/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/inventory/items/${id}`),
};

export const itemQualityPlansApi = {
  options: () => client.get<{ data: { sampling_methods: Array<{ value: string; label: string }>; parameter_types: Array<{ value: string; label: string }>; default_aql_level: string } }>('/inventory/quality-plans/options').then((r) => r.data.data),
  list: (itemId: string) =>
    client.get<{ data: ItemQualityPlan[] }>(`/inventory/items/${itemId}/quality-plans`).then((r) => r.data.data),
  createRevision: (itemId: string, data: {
    vendor_id?: string | null;
    sampling_method: 'aql' | 'fixed' | 'full';
    fixed_sample_size?: number | null;
    aql_level?: string | null;
    effective_from?: string;
    effective_to?: string | null;
    notes?: string | null;
    parameters: QualityPlanParameter[];
  }) => client.post<{ data: ItemQualityPlan }>(`/inventory/items/${itemId}/quality-plans`, data).then((r) => r.data.data),
  deactivate: (id: string) =>
    client.patch<{ data: ItemQualityPlan }>(`/inventory/quality-plans/${id}/deactivate`).then((r) => r.data.data),
};

export const itemCategoriesApi = {
  list: () =>
    client.get<{ data: ItemCategory[] }>('/inventory/item-categories').then((r) => r.data.data),
  tree: () =>
    client.get<{ data: ItemCategory[] }>('/inventory/item-categories/tree').then((r) => r.data.data),
  create: (data: { name: string; parent_id?: string | null }) =>
    client.post<ApiSuccess<ItemCategory>>('/inventory/item-categories', data).then((r) => r.data.data),
  update: (id: string, data: { name?: string; parent_id?: string | null }) =>
    client.put<ApiSuccess<ItemCategory>>(`/inventory/item-categories/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/inventory/item-categories/${id}`),
};
