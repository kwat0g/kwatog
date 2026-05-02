import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Account, CreateAccountData, UpdateAccountData } from '@/types/accounting';

export interface AccountListParams extends ListParams {
  type?: string;
  is_active?: boolean | string;
}

export const accountsApi = {
  list: (params?: AccountListParams) =>
    client.get<PaginatedResponse<Account>>('/accounts', { params }).then((r) => r.data),
  tree: () =>
    client.get<{ data: Account[] }>('/accounts/tree').then((r) => r.data.data),
  show: (id: string) =>
    client.get<ApiSuccess<Account>>(`/accounts/${id}`).then((r) => r.data.data),
  create: (data: CreateAccountData) =>
    client.post<ApiSuccess<Account>>('/accounts', data).then((r) => r.data.data),
  update: (id: string, data: UpdateAccountData) =>
    client.put<ApiSuccess<Account>>(`/accounts/${id}`, data).then((r) => r.data.data),
  deactivate: (id: string) =>
    client.delete(`/accounts/${id}`),
};
