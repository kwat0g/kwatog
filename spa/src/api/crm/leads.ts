import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Lead, CreateLeadData, UpdateLeadData } from '@/types/crm';

export interface LeadListParams extends ListParams {
 status?: string;
 source?: string;
 assigned_to?: string;
}

export const leadsApi = {
 list: (params?: LeadListParams) =>
 client.get<PaginatedResponse<Lead>>('/crm/leads', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<Lead>>(`/crm/leads/${id}`).then((r) => r.data.data),
 create: (data: CreateLeadData) =>
 client.post<ApiSuccess<Lead>>('/crm/leads', data).then((r) => r.data.data),
 update: (id: string, data: UpdateLeadData) =>
 client.put<ApiSuccess<Lead>>(`/crm/leads/${id}`, data).then((r) => r.data.data),
 qualify: (id: string) =>
 client.patch<ApiSuccess<Lead>>(`/crm/leads/${id}/qualify`).then((r) => r.data.data),
 disqualify: (id: string, reason: string) =>
 client.patch<ApiSuccess<Lead>>(`/crm/leads/${id}/disqualify`, { reason }).then((r) => r.data.data),
 convert: (id: string) =>
 client.post<ApiSuccess<import('@/types/crm').Opportunity>>(`/crm/leads/${id}/convert`).then((r) => r.data.data),
};
