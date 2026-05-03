import { client } from './client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Clearance, ClearanceStatus, InitiateSeparationData, SeparationReason } from '@/types/separations';

export interface SeparationListParams extends ListParams {
  status?: ClearanceStatus;
  separation_reason?: SeparationReason;
  employee_id?: string;
}

export const separationsApi = {
  list: (params?: SeparationListParams) =>
    client.get<PaginatedResponse<Clearance>>('/clearances', { params }).then(r => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Clearance>>(`/clearances/${id}`).then(r => r.data.data),
  initiate: (employeeId: string, data: InitiateSeparationData) =>
    client.post<ApiSuccess<Clearance>>(`/employees/${employeeId}/separation`, data).then(r => r.data.data),
  signItem: (clearanceId: string, item_key: string, remarks?: string) =>
    client.patch<ApiSuccess<Clearance>>(`/clearances/${clearanceId}/items`, { item_key, remarks }).then(r => r.data.data),
  computeFinalPay: (clearanceId: string) =>
    client.post<ApiSuccess<Clearance>>(`/clearances/${clearanceId}/final-pay/compute`).then(r => r.data.data),
  finalize: (clearanceId: string) =>
    client.patch<ApiSuccess<Clearance>>(`/clearances/${clearanceId}/finalize`).then(r => r.data.data),
};
