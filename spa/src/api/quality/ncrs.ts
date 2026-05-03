import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  Ncr,
  NcrAction,
  NcrActionType,
  NcrDisposition,
  NcrSeverity,
  NcrSource,
  NcrStatus,
  CreateNcrData,
} from '@/types/quality';

export interface NcrListParams extends ListParams {
  source?: NcrSource;
  severity?: NcrSeverity;
  status?: NcrStatus;
  disposition?: NcrDisposition;
  product_id?: string;
  inspection_id?: string;
}

export const ncrsApi = {
  list: (params?: NcrListParams) =>
    client.get<PaginatedResponse<Ncr>>('/quality/ncrs', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Ncr>>(`/quality/ncrs/${id}`).then((r) => r.data.data),
  create: (data: CreateNcrData) =>
    client.post<ApiSuccess<Ncr>>('/quality/ncrs', data).then((r) => r.data.data),
  addAction: (
    id: string,
    data: { action_type: NcrActionType; description: string; performed_at?: string }
  ) =>
    client.post<ApiSuccess<NcrAction>>(`/quality/ncrs/${id}/actions`, data).then((r) => r.data.data),
  setDisposition: (
    id: string,
    data: { disposition: NcrDisposition; root_cause?: string; corrective_action?: string }
  ) =>
    client.patch<ApiSuccess<Ncr>>(`/quality/ncrs/${id}/disposition`, data).then((r) => r.data.data),
  close: (id: string) =>
    client.post<ApiSuccess<Ncr>>(`/quality/ncrs/${id}/close`).then((r) => r.data.data),
  cancel: (id: string, reason?: string) =>
    client.post<ApiSuccess<Ncr>>(`/quality/ncrs/${id}/cancel`, { reason }).then((r) => r.data.data),
};
