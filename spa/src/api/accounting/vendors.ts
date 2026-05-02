import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { CreateVendorData, UpdateVendorData, Vendor } from '@/types/accounting';

export interface VendorListParams extends ListParams {
  is_active?: boolean | string;
}

export const vendorsApi = {
  list: (params?: VendorListParams) =>
    client.get<PaginatedResponse<Vendor>>('/vendors', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Vendor>>(`/vendors/${id}`).then((r) => r.data.data),
  create: (data: CreateVendorData) =>
    client.post<ApiSuccess<Vendor>>('/vendors', data).then((r) => r.data.data),
  update: (id: string, data: UpdateVendorData) =>
    client.put<ApiSuccess<Vendor>>(`/vendors/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/vendors/${id}`),
};
