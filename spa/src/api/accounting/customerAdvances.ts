import { client } from '@/api/client';
import type { ApiSuccess, PaginatedResponse } from '@/types';

export interface CustomerAdvance {
  id: string;
  sales_order_id: string;
  sales_order_number: string;
  customer_id: string;
  customer_name: string;
  attempt_number: number;
  order_total_snapshot: string;
  required_amount: string;
  percentage: string | null;
  submitted_amount: string | null;
  refunded_amount: string;
  unapplied_amount?: string;
  status: string;
  sending_bank: string | null;
  reference_number: string | null;
  payment_date: string | null;
  proof_file_name: string | null;
  review_reason: string | null;
  refund_reference: string | null;
  refund_reason: string | null;
  verified_at: string | null;
  reviewed_at: string | null;
  created_at: string;
}

export interface CustomerAdvanceListParams {
  status?: string;
  page?: number;
  per_page?: number;
}

export const customerAdvancesApi = {
  list: (params?: CustomerAdvanceListParams) =>
    client.get<PaginatedResponse<CustomerAdvance>>('/crm/sales-order-advances', { params }).then((response) => response.data),
  verify: (id: string, bank_account_id: string) =>
    client.post<ApiSuccess<CustomerAdvance>>(`/crm/sales-order-advances/${id}/verify`, { bank_account_id }).then((response) => response.data.data),
  reject: (id: string, reason: string) =>
    client.post<ApiSuccess<CustomerAdvance>>(`/crm/sales-order-advances/${id}/reject`, { reason }).then((response) => response.data.data),
  waive: (id: string, reason: string) =>
    client.post<ApiSuccess<CustomerAdvance>>(`/crm/sales-order-advances/${id}/waive`, { reason }).then((response) => response.data.data),
  refund: (id: string, data: { bank_account_id: string; reference_number: string; reason: string }) =>
    client.post<ApiSuccess<CustomerAdvance>>(`/crm/sales-order-advances/${id}/refund`, data).then((response) => response.data.data),
  proofPath: (id: string) => `/sales-order-advances/${id}/proof`,
};
