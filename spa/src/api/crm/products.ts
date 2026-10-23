import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Product, CreateProductData, UpdateProductData } from '@/types/crm';

export interface ProductListParams extends ListParams {
  is_active?: boolean | string;
  has_bom?: boolean | string;
}

export type ProductOption = Pick<Product, 'id' | 'part_number' | 'name' | 'unit_of_measure' | 'include_forecast_in_mrp' | 'image_url'>;
export interface ProductCustomerOption {
  id: string;
  name: string;
}

export const productsApi = {
  options: (params?: ProductListParams, signal?: AbortSignal) =>
    client
      .get<PaginatedResponse<ProductOption>>('/crm/products/options', { params, signal })
      .then((r) => r.data),
  customerOptions: (params?: { search?: string }, signal?: AbortSignal) =>
    client.get<{ data: ProductCustomerOption[] }>('/crm/products/customer-options', { params, signal }).then((r) => r.data.data),
  list: (params?: ProductListParams, signal?: AbortSignal) =>
    client.get<PaginatedResponse<Product>>('/crm/products', { params, signal }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Product>>(`/crm/products/${id}`).then((r) => r.data.data),
  create: (data: CreateProductData) =>
    client.post<ApiSuccess<Product>>('/crm/products', data).then((r) => r.data.data),
  update: (id: string, data: UpdateProductData) =>
    client.put<ApiSuccess<Product>>(`/crm/products/${id}`, data).then((r) => r.data.data),
  updateCustomerAccess: (id: string, data: { portal_visibility: 'standard' | 'restricted'; portal_customer_ids: string[] }) =>
    client.put<ApiSuccess<Product>>(`/crm/products/${id}/customer-access`, data).then((r) => r.data.data),
  uploadImage: (id: string, file: File) => {
    const data = new FormData();
    data.append('image', file);
    return client.post(`/crm/products/${id}/image`, data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  },
  proposeFinancial: (
    id: string,
    data: {
      standard_cost: string;
      minimum_selling_price: string | null;
      base_selling_price: string | null;
      notes: string;
    },
  ) => client.post(`/crm/products/${id}/financial-proposal`, data),
  decideFinancial: (
    id: string,
    proposal_id: string,
    decision: 'approve' | 'reject',
    notes: string,
  ) => client.post(`/crm/products/${id}/financial-decision`, { proposal_id, decision, notes }),
  delete: (id: string) => client.delete(`/crm/products/${id}`),
  restore: (id: string) => client.patch(`/crm/products/${id}/restore`),
};
