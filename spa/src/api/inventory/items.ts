import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Item, CreateItemData, UpdateItemData, ItemCategory } from '@/types/inventory';

export interface ItemListParams extends ListParams {
  item_type?: string;
  category_id?: string;
  is_active?: boolean | string;
  is_critical?: boolean | string;
  stock_status?: 'critical' | 'low' | 'ok';
}

export const itemsApi = {
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

export const itemCategoriesApi = {
  list: () =>
    client.get<{ data: ItemCategory[] }>('/inventory/item-categories').then((r) => r.data.data),
  tree: () =>
    client.get<{ data: ItemCategory[] }>('/inventory/item-categories/tree').then((r) => r.data.data),
  create: (data: { name: string; parent_id?: number | null }) =>
    client.post<ApiSuccess<ItemCategory>>('/inventory/item-categories', data).then((r) => r.data.data),
  update: (id: string, data: { name?: string; parent_id?: number | null }) =>
    client.put<ApiSuccess<ItemCategory>>(`/inventory/item-categories/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/inventory/item-categories/${id}`),
};
