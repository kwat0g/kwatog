import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  SalesOrder,
  SalesOrderChainStep,
  CreateSalesOrderData,
  UpdateSalesOrderData,
} from '@/types/crm';

export interface SalesOrderListParams extends ListParams {
  customer_id?: string;
  status?: string;
  date_from?: string;
  date_to?: string;
}

export const salesOrdersApi = {
  list: (params?: SalesOrderListParams) =>
    client.get<PaginatedResponse<SalesOrder>>('/crm/sales-orders', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<SalesOrder>>(`/crm/sales-orders/${id}`).then((r) => r.data.data),
  create: (data: CreateSalesOrderData) =>
    client.post<ApiSuccess<SalesOrder>>('/crm/sales-orders', data).then((r) => r.data.data),
  update: (id: string, data: UpdateSalesOrderData) =>
    client.put<ApiSuccess<SalesOrder>>(`/crm/sales-orders/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/crm/sales-orders/${id}`),
  confirm: (id: string) =>
    client.post<ApiSuccess<SalesOrder>>(`/crm/sales-orders/${id}/confirm`).then((r) => r.data.data),
  cancel: (id: string, reason?: string) =>
    client.post<ApiSuccess<SalesOrder>>(`/crm/sales-orders/${id}/cancel`, { reason }).then((r) => r.data.data),
  chain: (id: string) =>
    client.get<{ data: SalesOrderChainStep[] }>(`/crm/sales-orders/${id}/chain`).then((r) => r.data.data),
};
