import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { SupplierItemListing, SupplierListingStatus } from '@/types/purchasing';

export const supplierListingsApi = {
  list: (params?: ListParams & { status?: SupplierListingStatus | '' }) =>
    client.get<PaginatedResponse<SupplierItemListing>>('/purchasing/supplier-listings', { params }).then((r) => r.data),
  approve: (id: string) =>
    client.patch<ApiSuccess<SupplierItemListing>>(`/purchasing/supplier-listings/${id}/approve`).then((r) => r.data.data),
  reject: (id: string, reason: string) =>
    client.patch<ApiSuccess<SupplierItemListing>>(`/purchasing/supplier-listings/${id}/reject`, { reason }).then((r) => r.data.data),
};
