import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Product, CreateProductData, UpdateProductData } from '@/types/crm';

export interface ProductListParams extends ListParams {
  is_active?: boolean | string;
  has_bom?: boolean | string;
}

export const productsApi = {
  list: (params?: ProductListParams) =>
    client.get<PaginatedResponse<Product>>('/crm/products', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Product>>(`/crm/products/${id}`).then((r) => r.data.data),
  create: (data: CreateProductData) =>
    client.post<ApiSuccess<Product>>('/crm/products', data).then((r) => r.data.data),
  update: (id: string, data: UpdateProductData) =>
    client.put<ApiSuccess<Product>>(`/crm/products/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/crm/products/${id}`),
};
