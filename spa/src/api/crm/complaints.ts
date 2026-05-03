import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { CustomerComplaint, CreateComplaintData, ComplaintSeverity, ComplaintStatus } from '@/types/crm';

export interface ComplaintListParams extends ListParams {
  status?: ComplaintStatus;
  severity?: ComplaintSeverity;
  customer_id?: string;
}

export interface EightDPatch {
  d1_team?: string;
  d2_problem?: string;
  d3_containment?: string;
  d4_root_cause?: string;
  d5_corrective_action?: string;
  d6_verification?: string;
  d7_prevention?: string;
  d8_recognition?: string;
}

export const complaintsApi = {
  list: (params?: ComplaintListParams) =>
    client.get<PaginatedResponse<CustomerComplaint>>('/crm/complaints', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<CustomerComplaint>>(`/crm/complaints/${id}`).then((r) => r.data.data),
  create: (data: CreateComplaintData) =>
    client.post<ApiSuccess<CustomerComplaint>>('/crm/complaints', data).then((r) => r.data.data),
  update8D: (id: string, data: EightDPatch) =>
    client.patch<ApiSuccess<CustomerComplaint>>(`/crm/complaints/${id}/8d`, data).then((r) => r.data.data),
  finalize8D: (id: string) =>
    client.post<ApiSuccess<CustomerComplaint>>(`/crm/complaints/${id}/8d/finalize`).then((r) => r.data.data),
  resolve: (id: string) =>
    client.post<ApiSuccess<CustomerComplaint>>(`/crm/complaints/${id}/resolve`).then((r) => r.data.data),
  close: (id: string) =>
    client.post<ApiSuccess<CustomerComplaint>>(`/crm/complaints/${id}/close`).then((r) => r.data.data),
  pdfUrl: (id: string) => `/api/v1/crm/complaints/${id}/8d/pdf`,
};
