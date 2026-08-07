import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ContactInquiry, ContactInquiryStatus } from '@/types/crm';

export interface InquiryListParams extends ListParams {
 status?: string;
}

export const inquiriesApi = {
 list: (params?: InquiryListParams) =>
 client.get<PaginatedResponse<ContactInquiry>>('/crm/inquiries', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<ContactInquiry>>(`/crm/inquiries/${id}`).then((r) => r.data.data),
 updateStatus: (id: string, status: ContactInquiryStatus) =>
 client.patch<ApiSuccess<ContactInquiry>>(`/crm/inquiries/${id}/status`, { status }).then((r) => r.data.data),
};
