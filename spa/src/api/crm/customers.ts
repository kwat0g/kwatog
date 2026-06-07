import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Customer, CreateCustomerData, UpdateCustomerData } from '@/types/accounting';

export interface CustomerListParams extends ListParams {
  is_active?: boolean | string;
}

export const crmCustomersApi = {
  list: (params?: CustomerListParams) =>
    client.get<PaginatedResponse<Customer>>('/crm/customers', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Customer>>(`/crm/customers/${id}`).then((r) => r.data.data),
  create: (data: CreateCustomerData) =>
    client.post<ApiSuccess<Customer>>('/crm/customers', data).then((r) => r.data.data),
  update: (id: string, data: UpdateCustomerData) =>
    client.put<ApiSuccess<Customer>>(`/crm/customers/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/crm/customers/${id}`),
};
