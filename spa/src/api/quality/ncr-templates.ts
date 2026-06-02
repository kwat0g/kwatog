import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { NcrTemplate, CreateNcrTemplateData } from '@/types/quality';

export const ncrTemplatesApi = {
  list: (params?: ListParams) =>
    client.get<PaginatedResponse<NcrTemplate>>('/quality/ncr-templates', { params }).then((r) => r.data),

  active: () =>
    client
      .get<PaginatedResponse<NcrTemplate>>('/quality/ncr-templates/active', {
        params: { per_page: 200 },
      })
      .then((r) => r.data.data),

  show: (id: string) =>
    client.get<ApiSuccess<NcrTemplate>>(`/quality/ncr-templates/${id}`).then((r) => r.data.data),

  create: (data: CreateNcrTemplateData) =>
    client.post<ApiSuccess<NcrTemplate>>('/quality/ncr-templates', data).then((r) => r.data.data),

  update: (id: string, data: Partial<CreateNcrTemplateData> & { is_active?: boolean }) =>
    client.patch<ApiSuccess<NcrTemplate>>(`/quality/ncr-templates/${id}`, data).then((r) => r.data.data),

  destroy: (id: string) =>
    client.delete<ApiSuccess<NcrTemplate>>(`/quality/ncr-templates/${id}`).then((r) => r.data.data),
};
