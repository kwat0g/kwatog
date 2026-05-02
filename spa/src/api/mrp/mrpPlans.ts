import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { MrpPlan } from '@/types/mrp';

export interface MrpPlanListParams extends ListParams {
  status?: string;
  sales_order_id?: string;
}

export const mrpPlansApi = {
  list: (params?: MrpPlanListParams) =>
    client.get<PaginatedResponse<MrpPlan>>('/mrp/plans', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<MrpPlan>>(`/mrp/plans/${id}`).then((r) => r.data.data),
  rerun: (id: string) =>
    client.post<ApiSuccess<MrpPlan>>(`/mrp/plans/${id}/rerun`).then((r) => r.data.data),
  forSalesOrder: (soId: string) =>
    client.get<ApiSuccess<MrpPlan> | { data: null }>(`/mrp/sales-orders/${soId}/mrp-plan`).then((r) => r.data.data ?? null),
};
