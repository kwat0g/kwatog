import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { PriceAgreement, CreatePriceAgreementData, UpdatePriceAgreementData } from '@/types/crm';

export interface PriceAgreementListParams extends ListParams {
 agreement?: string;
 product_id?: string;
 customer_id?: string;
 active_on?: string;
 trashed?: 'with' | 'only';
}

export interface RequestPriceAgreementTerminationData {
 last_valid_date?: string;
 reason: string;
 coverage_gap_acknowledged?: boolean;
}

export interface PriceAgreementTerminationPreview {
 scheduled: boolean;
 coverage_date: string;
 has_coverage: boolean;
 coverage_source: 'agreement' | 'base' | 'none';
}

export interface PriceAgreementTerminationHistoryEntry {
 status: 'awaiting_customer_signature' | 'request_cancelled' | 'ended_early' | 'cancelled_before_start';
 reason: string | null;
 occurred_at: string | null;
 actor_type: string | null;
 signed_name: string | null;
 kind: 'early_termination' | 'scheduled_cancellation' | null;
 last_valid_date: string | null;
 document_id: string | null;
}

export interface PriceAgreementTerminationHistory {
 entries: PriceAgreementTerminationHistoryEntry[];
 has_older_entries: boolean;
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
 previewTermination: (id: string, lastValidDate?: string) =>
 client.get<ApiSuccess<PriceAgreementTerminationPreview>>(`/crm/price-agreements/${id}/termination-preview`, {
  params: lastValidDate ? { last_valid_date: lastValidDate } : undefined,
 }).then((r) => r.data.data),
 terminationHistory: (id: string) =>
 client.get<ApiSuccess<PriceAgreementTerminationHistory>>(`/crm/price-agreements/${id}/termination-history`).then((r) => r.data.data),
 requestTermination: (id: string, data: RequestPriceAgreementTerminationData) =>
 client.post<ApiSuccess<PriceAgreement>>(`/crm/price-agreements/${id}/termination-requests`, data).then((r) => r.data.data),
 cancelTerminationRequest: (id: string, documentId: string) =>
 client.post<ApiSuccess<PriceAgreement>>(`/crm/price-agreements/${id}/termination-requests/cancel`, { document_id: documentId }).then((r) => r.data.data),
 restore: (id: string) =>
  client.patch(`/crm/price-agreements/${id}/restore`),
 forCustomer: (customerId: string) =>
 client.get<{ data: PriceAgreement[] }>(`/crm/customers/${customerId}/price-agreements`).then((r) => r.data.data),
};
