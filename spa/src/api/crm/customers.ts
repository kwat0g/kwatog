import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
// Customer type lives in accounting — it's shared by AR, CRM, and B2B modules
import type { Customer, CreateCustomerData, UpdateCustomerData } from '@/types/accounting';

export interface CustomerListParams extends ListParams {
 is_active?: boolean | string;
}

export interface CustomerNameCheck {
 exists: boolean;
 count: number;
 normalized_name: string;
 matches: Array<{ id: string; name: string; code: string }>;
}

export interface CustomerPortalProductAccess {
 id: string;
 part_number: string;
 name: string;
 unit_of_measure: string | null;
 image_url: string | null;
 portal_visibility: 'standard' | 'restricted';
 is_explicitly_authorized: boolean;
 is_authorized: boolean;
  minimum_order_quantity: string;
  maximum_order_quantity: string | null;
}

export interface CustomerProductOrderLimit {
 product_id: string;
 minimum_order_quantity: number;
 maximum_order_quantity: number | null;
}

export interface CustomerProductAccess {
 portal_catalog_mode: 'standard' | 'restricted';
  order_limits_supported: boolean;
 product_ids: string[];
 products: CustomerPortalProductAccess[];
}

export interface CustomerWarrantyOption {
 id: string;
 product: { id: string; part_number: string; name: string; unit_of_measure: string | null };
 label: string;
 warranty_duration_days: number | null;
 warranty_report_window_days: number | null;
 warranty_start_basis: 'delivery' | 'invoice' | null;
 warranty_covered_issues: Array<'manufacturing_defect' | 'shipping_damage' | 'customer_caused_damage'> | null;
 warranty_remedies: Array<'repair' | 'replace' | 'credit' | 'refund'> | null;
 additional_terms: string | null;
}

export const crmCustomersApi = {
 list: (params?: CustomerListParams, signal?: AbortSignal) =>
 client.get<PaginatedResponse<Customer>>('/crm/customers', { params, signal }).then((r) => r.data),
 checkName: (name: string) =>
  client.get<ApiSuccess<CustomerNameCheck>>('/crm/customers/name-check', { params: { name } }).then((r) => r.data.data),
 productOrderLimitCapability: () =>
  client.get<ApiSuccess<{ supported: boolean }>>('/crm/customers/product-order-limit-capability').then((r) => r.data.data),
 show: (id: string) =>
 client.get<ApiSuccess<Customer>>(`/crm/customers/${id}`).then((r) => r.data.data),
 create: (data: CreateCustomerData) =>
 client.post<ApiSuccess<Customer>>('/crm/customers', data).then((r) => r.data.data),
 update: (id: string, data: UpdateCustomerData) =>
 client.put<ApiSuccess<Customer>>(`/crm/customers/${id}`, data).then((r) => r.data.data),
 delete: (id: string) =>
  client.delete(`/crm/customers/${id}`),
 restore: (id: string) =>
  client.patch(`/crm/customers/${id}/restore`),
 productAccess: (id: string) =>
  client.get<ApiSuccess<CustomerProductAccess>>(`/crm/customers/${id}/product-access`).then((r) => r.data.data),
 updateProductAccess: (id: string, data: Pick<CustomerProductAccess, 'portal_catalog_mode' | 'product_ids'> & { product_order_limits?: CustomerProductOrderLimit[] }) =>
  client.put<ApiSuccess<CustomerProductAccess>>(`/crm/customers/${id}/product-access`, data).then((r) => r.data.data),
 warrantyOptions: (id: string) =>
  client.get<{ data: CustomerWarrantyOption[] }>(`/crm/customers/${id}/warranty-terms`).then((r) => r.data.data),
 createWarrantyOption: (customerId: string, productId: string, data: Omit<CustomerWarrantyOption, 'id' | 'product'>) =>
  client.post<ApiSuccess<CustomerWarrantyOption>>(`/crm/customers/${customerId}/warranty-terms/${productId}/options`, data).then((r) => r.data.data),
 updateWarrantyOption: (customerId: string, productId: string, optionId: string, data: Omit<CustomerWarrantyOption, 'id' | 'product'>) =>
  client.put<ApiSuccess<CustomerWarrantyOption>>(`/crm/customers/${customerId}/warranty-terms/${productId}/options/${optionId}`, data).then((r) => r.data.data),
 deleteWarrantyOption: (customerId: string, productId: string, optionId: string) =>
  client.delete(`/crm/customers/${customerId}/warranty-terms/${productId}/options/${optionId}`),
};
