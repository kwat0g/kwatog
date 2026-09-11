import { client } from '@/api/client';
import type { ApiSuccess, ListParams, PaginatedResponse } from '@/types';
import type { CustomerPortalUser, InternalDeliverySchedule, SupplierPortalUser } from '@/types/b2b';

export interface PortalAccessListParams extends ListParams {
 status?: 'active' | 'inactive' | 'locked' | 'pending';
 vendor_id?: string;
}

export interface CustomerPortalAccessListParams extends ListParams {
 status?: 'active' | 'inactive' | 'locked' | 'pending';
 customer_id?: string;
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

 // ── Customer portal accounts (same admin surface, tenant table) ──

 listCustomers: (params?: CustomerPortalAccessListParams) =>
  client.get<PaginatedResponse<CustomerPortalUser>>('/b2b/portal-access/customers', { params }).then((r) => r.data),

 inviteCustomer: (customerId: string, data: { name: string; email: string }) =>
  client.post<ApiSuccess<CustomerPortalUser>>(`/b2b/portal-access/customers/${customerId}/invite`, data).then((r) => r.data.data),

 resendCustomer: (id: string) =>
  client.post<ApiSuccess<CustomerPortalUser>>(`/b2b/portal-access/customers/${id}/resend`).then((r) => r.data.data),

 deactivateCustomer: (id: string) =>
  client.patch<ApiSuccess<CustomerPortalUser>>(`/b2b/portal-access/customers/${id}/deactivate`).then((r) => r.data.data),

 reactivateCustomer: (id: string) =>
  client.patch<ApiSuccess<CustomerPortalUser>>(`/b2b/portal-access/customers/${id}/reactivate`).then((r) => r.data.data),

 // ── Delivery schedule review (customer + supplier submissions) ──

 listDeliverySchedules: (params?: { status?: string; source?: string; month?: string; search?: string; page?: number; per_page?: number }) =>
  client.get<PaginatedResponse<InternalDeliverySchedule>>('/b2b/portal-access/delivery-schedules', { params }).then((r) => r.data),

 getDeliverySchedule: (id: string) =>
  client.get<{ data: InternalDeliverySchedule }>(`/b2b/portal-access/delivery-schedules/${id}`).then((r) => r.data.data),

 acknowledgeDeliverySchedule: (id: string) =>
  client.post<{ data: InternalDeliverySchedule; message: string }>(`/b2b/portal-access/delivery-schedules/${id}/acknowledge`).then((r) => r.data),

 rejectDeliverySchedule: (id: string, reason: string) =>
  client.post<{ data: InternalDeliverySchedule; message: string }>(`/b2b/portal-access/delivery-schedules/${id}/reject`, { reason }).then((r) => r.data),
};
