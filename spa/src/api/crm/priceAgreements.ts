import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { PriceAgreement, CreatePriceAgreementData, UpdatePriceAgreementData } from '@/types/crm';

export interface PriceAgreementListParams extends ListParams {
  product_id?: string;
  customer_id?: string;
  active_on?: string;
}

export const priceAgreementsApi = {
  list: (params?: PriceAgreementListParams) =>
    client.get<PaginatedResponse<PriceAgreement>>('/crm/price-agreements', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<PriceAgreement>>(`/crm/price-agreements/${id}`).then((r) => r.data.data),
  create: (data: CreatePriceAgreementData) =>
    client.post<ApiSuccess<PriceAgreement>>('/crm/price-agreements', data).then((r) => r.data.data),
  update: (id: string, data: UpdatePriceAgreementData) =>
    client.put<ApiSuccess<PriceAgreement>>(`/crm/price-agreements/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/crm/price-agreements/${id}`),
  forCustomer: (customerId: string) =>
    client.get<{ data: PriceAgreement[] }>(`/crm/customers/${customerId}/price-agreements`).then((r) => r.data.data),
};
