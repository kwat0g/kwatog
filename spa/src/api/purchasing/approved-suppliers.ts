import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ApprovedSupplier } from '@/types/purchasing';

export interface ApprovedSupplierOptions {
  items: Array<{ value: string; label: string }>;
  vendors: Array<{ value: string; label: string }>;
  qualification_statuses: Array<{ value: string; label: string }>;
}

export const approvedSuppliersApi = {
 list: (params?: ListParams & { item_id?: string; vendor_id?: string; is_preferred?: boolean | string; qualification_status?: string }) =>
 client.get<PaginatedResponse<ApprovedSupplier>>('/purchasing/approved-suppliers', { params }).then((r) => r.data),
 options: () =>
 client.get<{ data: ApprovedSupplierOptions }>('/purchasing/approved-suppliers/options').then((r) => r.data.data),
 create: (data: { item_id: string; vendor_id: string; is_preferred?: boolean; lead_time_days?: number; last_price?: string }) =>
 client.post<ApiSuccess<ApprovedSupplier>>('/purchasing/approved-suppliers', data).then((r) => r.data.data),
 update: (id: string, data: Partial<{ is_preferred: boolean; lead_time_days: number; last_price: string }>) =>
 client.put<ApiSuccess<ApprovedSupplier>>(`/purchasing/approved-suppliers/${id}`, data).then((r) => r.data.data),
 delete: (id: string) => client.delete(`/purchasing/approved-suppliers/${id}`),
 restore: (id: string) => client.patch(`/purchasing/approved-suppliers/${id}/restore`),
};
