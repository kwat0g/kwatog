import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { MaterialIssueSlip } from '@/types/inventory';

export const materialIssuesApi = {
  list: (params?: ListParams & { status?: string; from?: string; to?: string }) =>
    client.get<PaginatedResponse<MaterialIssueSlip>>('/inventory/material-issues', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<MaterialIssueSlip>>(`/inventory/material-issues/${id}`).then((r) => r.data.data),
  create: (data: {
    work_order_id?: number | null;
    issued_date: string;
    reference_text?: string;
    remarks?: string;
    items: Array<{
      item_id: string;
      location_id: string;
      quantity_issued: string;
      material_reservation_id?: number;
      remarks?: string;
    }>;
  }) =>
    client.post<ApiSuccess<MaterialIssueSlip>>('/inventory/material-issues', data).then((r) => r.data.data),
};
