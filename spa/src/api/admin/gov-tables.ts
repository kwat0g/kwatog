import { client } from '../client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import type { ContributionAgency, GovernmentTable } from '@/types/payroll';

export interface UpdateGovTableData {
  bracket_min?: string;
  bracket_max?: string;
  ee_amount?: string;
  er_amount?: string;
  effective_date?: string;
  is_active?: boolean;
}

export const govTablesApi = {
  /**
   * Government tables aren't paginated server-side (small fixed data set per
   * agency) but we still expect the standard PaginatedResponse envelope from
   * the controller. Some installs may have it return a flat list — accept both.
   */
  list: (agency: ContributionAgency) =>
    client
      .get<PaginatedResponse<GovernmentTable> | ApiSuccess<GovernmentTable[]>>('/admin/gov-tables', {
        params: { agency },
      })
      .then((r) => {
        const body = r.data as PaginatedResponse<GovernmentTable> | ApiSuccess<GovernmentTable[]>;
        return Array.isArray((body as PaginatedResponse<GovernmentTable>).data)
          ? ((body as PaginatedResponse<GovernmentTable>).data as GovernmentTable[])
          : [];
      }),
  update: (id: string, data: UpdateGovTableData) =>
    client.put<ApiSuccess<GovernmentTable>>(`/admin/gov-tables/${id}`, data).then((r) => r.data.data),
  deactivate: (id: string) =>
    client.patch<ApiSuccess<GovernmentTable>>(`/admin/gov-tables/${id}/deactivate`).then((r) => r.data.data),
  activate: (id: string) =>
    client.patch<ApiSuccess<GovernmentTable>>(`/admin/gov-tables/${id}/activate`).then((r) => r.data.data),
};
