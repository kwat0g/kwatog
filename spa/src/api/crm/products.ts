import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Product, ProductBlueprint, CreateProductData, UpdateProductData, UpdateProductSellingPricesData } from '@/types/crm';

export interface ProductListParams extends ListParams {
  is_active?: boolean | string;
  has_bom?: boolean | string;
}

export type ProductOption = Pick<Product, 'id' | 'part_number' | 'name' | 'unit_of_measure' | 'include_forecast_in_mrp' | 'image_url' | 'primary_image_url' | 'images' | 'specifications' | 'is_customizable'>;
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
  updateSellingPrices: (id: string, data: UpdateProductSellingPricesData) =>
    client.put<ApiSuccess<Product>>(`/crm/products/${id}/selling-prices`, data).then((r) => r.data.data),
  uploadImage: (id: string, file: File) => {
    const data = new FormData();
    data.append('image', file);
    return client.post(`/crm/products/${id}/image`, data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  },
  uploadImages: (id: string, files: File[], altTexts: string[] = []) => {
    const data = new FormData();
    files.forEach((file) => data.append('images[]', file));
    altTexts.forEach((altText) => data.append('alt_texts[]', altText));
    return client.post(`/crm/products/${id}/images`, data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then((response) => response.data.data);
  },
  setPrimaryImage: (productId: string, imageId: string) =>
    client.patch(`/crm/products/${productId}/images/${imageId}/primary`).then((response) => response.data.data),
  deleteImage: (productId: string, imageId: string) =>
    client.delete(`/crm/products/${productId}/images/${imageId}`),
  uploadBlueprint: (id: string, input: { title: string; revision: string; notes?: string; file: File }) => {
    const data = new FormData();
    data.append('title', input.title);
    data.append('revision', input.revision);
    if (input.notes) data.append('notes', input.notes);
    data.append('file', input.file);
    return client.post<{ data: ProductBlueprint }>(`/crm/products/${id}/blueprints`, data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then((response) => response.data.data);
  },
  deleteBlueprint: (productId: string, blueprintId: string) =>
    client.delete(`/crm/products/${productId}/blueprints/${blueprintId}`),
  proposeFinancial: (
    id: string,
    data: {
      standard_cost: string;
      notes: string;
    },
  ) => client.post<ApiSuccess<Product>>(`/crm/products/${id}/financial-proposal`, data).then((r) => r.data.data),
  decideFinancial: (
    id: string,
    proposal_id: string,
    decision: 'approve' | 'reject',
    notes: string,
  ) => client.post<ApiSuccess<Product>>(`/crm/products/${id}/financial-decision`, { proposal_id, decision, notes }).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/crm/products/${id}`),
  restore: (id: string) => client.patch(`/crm/products/${id}/restore`),
};
