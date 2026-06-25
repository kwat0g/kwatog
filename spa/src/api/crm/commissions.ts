import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  CommissionEarning,
  CommissionEarningStatus,
  CommissionRate,
  CreateCommissionRateData,
} from '@/types/commissions';

export interface CommissionEarningListParams extends ListParams {
  status?: CommissionEarningStatus;
}

export const commissionsApi = {
  list: (params?: CommissionEarningListParams) =>
    client.get<PaginatedResponse<CommissionEarning>>('/crm/commissions', { params }).then((r) => r.data),
  calculate: (salesOrderId: string) =>
    client.post<ApiSuccess<CommissionEarning>>(`/crm/commissions/calculate/${salesOrderId}`).then((r) => r.data.data),
  approve: (id: string) =>
    client.post<ApiSuccess<CommissionEarning>>(`/crm/commissions/${id}/approve`).then((r) => r.data.data),
  batchPaid: (ids: string[]) =>
    client.post<{ message: string }>('/crm/commissions/batch-paid', { ids }).then((r) => r.data),
};

export const commissionRatesApi = {
  list: (params?: ListParams) =>
    client.get<PaginatedResponse<CommissionRate>>('/crm/commissions/rates', { params }).then((r) => r.data),
  create: (data: CreateCommissionRateData) =>
    client.post<ApiSuccess<CommissionRate>>('/crm/commissions/rates', data).then((r) => r.data.data),
};
