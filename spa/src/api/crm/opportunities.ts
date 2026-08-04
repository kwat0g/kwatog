import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Opportunity, CreateOpportunityData, UpdateOpportunityData } from '@/types/crm';

export interface OpportunityListParams extends ListParams {
 stage?: string;
 customer_id?: string;
 assigned_to?: string;
}

export interface OpportunityOptions {
 initial_probability: number;
 stages: Array<{ value: string; label: string }>;
}

export interface QuoteSummary {
 id: string;
 quote_number: string;
 status: string;
 total_amount: string;
}

export const opportunitiesApi = {
 options: () =>
 client.get<{ data: OpportunityOptions }>('/crm/opportunities/options').then((r) => r.data.data),
 list: (params?: OpportunityListParams) =>
 client.get<PaginatedResponse<Opportunity>>('/crm/opportunities', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<Opportunity>>(`/crm/opportunities/${id}`).then((r) => r.data.data),
 create: (data: CreateOpportunityData) =>
 client.post<ApiSuccess<Opportunity>>('/crm/opportunities', data).then((r) => r.data.data),
 update: (id: string, data: UpdateOpportunityData) =>
 client.put<ApiSuccess<Opportunity>>(`/crm/opportunities/${id}`, data).then((r) => r.data.data),
 advance: (id: string) =>
 client.patch<ApiSuccess<Opportunity>>(`/crm/opportunities/${id}/advance`).then((r) => r.data.data),
 win: (id: string) =>
 client.patch<ApiSuccess<Opportunity>>(`/crm/opportunities/${id}/win`).then((r) => r.data.data),
 lose: (id: string, reason: string) =>
 client.patch<ApiSuccess<Opportunity>>(`/crm/opportunities/${id}/lose`, { reason }).then((r) => r.data.data),
 createQuote: (id: string) =>
 client.post<ApiSuccess<QuoteSummary>>(`/crm/opportunities/${id}/create-quote`).then((r) => r.data.data),
};
