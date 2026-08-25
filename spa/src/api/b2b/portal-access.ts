import { client } from '@/api/client';
import type { ApiSuccess, ListParams, PaginatedResponse } from '@/types';
import type { SupplierPortalUser } from '@/types/b2b';

export interface PortalAccessListParams extends ListParams {
 status?: 'active' | 'inactive' | 'locked' | 'pending';
 vendor_id?: string;
}

export const portalAccessApi = {
 listSuppliers: (params?: PortalAccessListParams) =>
  client.get<PaginatedResponse<SupplierPortalUser>>('/b2b/portal-access/suppliers', { params }).then((r) => r.data),

 inviteSupplier: (vendorId: string, data: { name: string; email: string }) =>
  client.post<ApiSuccess<SupplierPortalUser>>(`/b2b/portal-access/suppliers/${vendorId}/invite`, data).then((r) => r.data.data),

 resendSupplier: (id: string) =>
  client.post<ApiSuccess<SupplierPortalUser>>(`/b2b/portal-access/suppliers/${id}/resend`).then((r) => r.data.data),

 deactivateSupplier: (id: string) =>
  client.patch<ApiSuccess<SupplierPortalUser>>(`/b2b/portal-access/suppliers/${id}/deactivate`).then((r) => r.data.data),

 reactivateSupplier: (id: string) =>
  client.patch<ApiSuccess<SupplierPortalUser>>(`/b2b/portal-access/suppliers/${id}/reactivate`).then((r) => r.data.data),

 revokeTokens: (id: string) =>
  client.delete<ApiSuccess<SupplierPortalUser>>(`/b2b/portal-access/suppliers/${id}/tokens`).then((r) => r.data.data),
};
