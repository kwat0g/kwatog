import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  Item,
  ItemType,
  ItemOption,
  CreateItemData,
  UpdateItemData,
  ItemCategory,
  ItemQualityPlan,
  QualityPlanParameter,
} from '@/types/inventory';

export interface ItemListParams extends ListParams {
  item_type?: string;
  category_id?: string;
  is_active?: boolean | string;
  is_critical?: boolean | string;
  stock_status?: 'critical' | 'low' | 'ok';
  below_reorder?: boolean | string;
}

export const itemsApi = {
  options: () =>
    client
      .get<{
        data: {
          replenishment_basis?: {
            demand_history_days: number;
            coverage_buffer_ratio: number;
            default_lead_time_days: number;
          };
          item_types: Array<{ value: string; label: string }>;
          reorder_methods: Array<{ value: string; label: string }>;
          adjustment_directions: Array<{ value: string; label: string }>;
          stock_statuses: Array<{ value: string; label: string }>;
        };
      }>('/inventory/items/options')
      .then((r) => r.data.data),
  catalog: (params?: ItemListParams) =>
    client
      .get<PaginatedResponse<ItemOption>>('/inventory/items/catalog', { params })
      .then((r) => r.data),
  list: (params?: ItemListParams) =>
    client.get<PaginatedResponse<Item>>('/inventory/items', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Item>>(`/inventory/items/${id}`).then((r) => r.data.data),
  create: (data: CreateItemData) =>
    client.post<ApiSuccess<Item>>('/inventory/items', data).then((r) => r.data.data),
  update: (id: string, data: UpdateItemData) =>
    client.put<ApiSuccess<Item>>(`/inventory/items/${id}`, data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/inventory/items/${id}`),
  restore: (id: string) => client.patch(`/inventory/items/${id}/restore`),
};

export const itemQualityPlansApi = {
  options: () =>
    client
      .get<{
        data: {
          checklist_capture_enabled?: boolean;
          sampling_methods: Array<{ value: string; label: string }>;
          parameter_types: Array<{ value: string; label: string }>;
          default_aql_level: string;
        };
      }>('/inventory/quality-plans/options')
      .then((r) => r.data.data),
  list: (itemId: string) =>
    client
      .get<{ data: ItemQualityPlan[] }>(`/inventory/items/${itemId}/quality-plans`)
      .then((r) => r.data.data),
  createRevision: (
    itemId: string,
    data: {
      capture_method?: 'measurement' | 'checklist';
      vendor_id?: string | null;
      sampling_method: 'aql' | 'fixed' | 'full';
      fixed_sample_size?: number | null;
      aql_level?: string | null;
      effective_from?: string;
      effective_to?: string | null;
      notes?: string | null;
      parameters: QualityPlanParameter[];
    },
  ) =>
    client
      .post<{ data: ItemQualityPlan }>(`/inventory/items/${itemId}/quality-plans`, data)
      .then((r) => r.data.data),
  deactivate: (id: string) =>
    client
      .patch<{ data: ItemQualityPlan }>(`/inventory/quality-plans/${id}/deactivate`)
      .then((r) => r.data.data),
};

export const itemCategoriesApi = {
  list: (params?: { trashed?: 'with' | 'only' }) =>
    client
      .get<{ data: ItemCategory[] }>('/inventory/item-categories', { params })
      .then((r) => r.data.data),
  tree: (params?: { trashed?: 'with' | 'only' }) =>
    client
      .get<{ data: ItemCategory[] }>('/inventory/item-categories/tree', { params })
      .then((r) => r.data.data),
  create: (data: { name: string; parent_id?: string | null; item_type?: ItemType }) =>
    client
      .post<ApiSuccess<ItemCategory>>('/inventory/item-categories', data)
      .then((r) => r.data.data),
  update: (id: string, data: { name?: string; parent_id?: string | null; item_type?: ItemType }) =>
    client
      .put<ApiSuccess<ItemCategory>>(`/inventory/item-categories/${id}`, data)
      .then((r) => r.data.data),
  delete: (id: string) => client.delete(`/inventory/item-categories/${id}`),
  restoreCategory: (id: string) => client.patch(`/inventory/item-categories/${id}/restore`),
};
