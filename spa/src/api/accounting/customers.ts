import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { CreateCustomerData, Customer, UpdateCustomerData } from '@/types/accounting';

export interface CustomerListParams extends ListParams {
  is_active?: boolean | string;
}

export const customersApi = {
  list: (params?: CustomerListParams) =>
    client.get<PaginatedResponse<Customer>>('/customers', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Customer>>(`/customers/${id}`).then((r) => r.data.data),
  create: (data: CreateCustomerData) =>
    client.post<ApiSuccess<Customer>>('/customers', data).then((r) => r.data.data),
  update: (id: string, data: UpdateCustomerData) =>
    client.put<ApiSuccess<Customer>>(`/customers/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/customers/${id}`),
};
