import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
 SalesOrder,
 SalesOrderChainStep,
 SoChainResult,
 CreateSalesOrderData,
 UpdateSalesOrderData,
} from '@/types/crm';

export interface SalesOrderListParams extends ListParams {
 customer_id?: string;
 /** Single status or an array (serialised as status[]=…, accepted by the list endpoint). */
 status?: string | string[];
 date_from?: string;
 date_to?: string;
}

export const salesOrdersApi = {
 fulfillment: (id: string) => client.get<{ data: import('@/pages/crm/sales-orders/FulfillmentDialog').FulfillmentPreview }>(`/crm/sales-orders/${id}/fulfillment`).then((r) => r.data.data),
 updateFulfillment: (id: string, data: { revision: string; request_key: string; reason: string; lines: Array<{ id: string; policy: string }> }) => client.put(`/crm/sales-orders/${id}/fulfillment`, data).then((r) => r.data.data),
 options: () => client.get<{ data: { statuses: Array<{ value: string; label: string; next_statuses: Array<{ value: string; label: string }> }> } }>('/crm/sales-orders/options').then((r) => r.data.data),
 list: (params?: SalesOrderListParams, signal?: AbortSignal) =>
 client.get<PaginatedResponse<SalesOrder>>('/crm/sales-orders', { params, signal }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<SalesOrder>>(`/crm/sales-orders/${id}`).then((r) => r.data.data),
 create: (data: CreateSalesOrderData) =>
 client.post<ApiSuccess<SalesOrder>>('/crm/sales-orders', data).then((r) => r.data.data),
 update: (id: string, data: UpdateSalesOrderData) =>
 client.put<ApiSuccess<SalesOrder>>(`/crm/sales-orders/${id}`, data).then((r) => r.data.data),
 delete: (id: string) =>
  client.delete(`/crm/sales-orders/${id}`),
 restore: (id: string) =>
  client.patch(`/crm/sales-orders/${id}/restore`),
 proposeDeliveryDate: (id: string, form: { proposed_delivery_date: string; notes: string }) =>
 client.post(`/crm/sales-orders/${id}/propose-delivery-date`, form).then((r) => r.data),
 confirm: (id: string) =>
 client.post<{ data: SalesOrder; chain_result: SoChainResult }>(`/crm/sales-orders/${id}/confirm`).then((r) => r.data),
 cancel: (id: string, reason?: string) =>
 client.post<ApiSuccess<SalesOrder>>(`/crm/sales-orders/${id}/cancel`, { reason }).then((r) => r.data.data),
 acceptResponse: (responseId: string, notes?: string) =>
 client.patch(`/crm/sales-order-responses/${responseId}/accept`, { notes }).then((r) => r.data.data),
 rejectResponse: (responseId: string, reason: string) =>
 client.patch(`/crm/sales-order-responses/${responseId}/reject`, { reason }).then((r) => r.data.data),
 chain: (id: string) =>
 client.get<{ data: SalesOrderChainStep[] }>(`/crm/sales-orders/${id}/chain`).then((r) => r.data.data),
};
