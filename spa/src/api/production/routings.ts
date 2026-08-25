// Task 12 — Production routings + WO operations API client.

import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ProductRouting, WoOperation } from '@/types/production/routing';

/**
 * `search` (from ListParams) matches the product part number or name — the
 * backend never had a `product_search` parameter, so the list page's search
 * box silently did nothing while this type advertised one.
 */
export interface RoutingListParams extends ListParams {
 product_id?: string;
 is_active?: string;
}

export const routingsApi = {
 list: (params?: RoutingListParams) =>
 client.get<PaginatedResponse<ProductRouting>>('/production/routings', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<ProductRouting>>(`/production/routings/${id}`).then((r) => r.data.data),
 create: (data: Record<string, unknown>) =>
 client.post<ApiSuccess<ProductRouting>>('/production/routings', data).then((r) => r.data.data),
 update: (id: string, data: Record<string, unknown>) =>
 client.put<ApiSuccess<ProductRouting>>(`/production/routings/${id}`, data).then((r) => r.data.data),
 duplicate: (id: string) =>
 client.post<ApiSuccess<ProductRouting>>(`/production/routings/${id}/duplicate`).then((r) => r.data.data),
 /** Put a superseded version back into service. */
 activate: (id: string) =>
 client.post<ApiSuccess<ProductRouting>>(`/production/routings/${id}/activate`).then((r) => r.data.data),
};

export const woOperationsApi = {
 list: (workOrderId: string) =>
 client.get<{ data: WoOperation[] }>(`/production/work-orders/${workOrderId}/operations`).then((r) => r.data.data),
 show: (id: string) =>
 client.get<ApiSuccess<WoOperation>>(`/production/operations/${id}`).then((r) => r.data.data),
 startSetup: (id: string, operatorId: string) =>
 client.post<ApiSuccess<WoOperation>>(`/production/operations/${id}/start-setup`, { operator_id: operatorId }).then((r) => r.data.data),
 endSetup: (id: string) =>
 client.post<ApiSuccess<WoOperation>>(`/production/operations/${id}/end-setup`).then((r) => r.data.data),
 start: (id: string, operatorId: string) =>
 client.post<ApiSuccess<WoOperation>>(`/production/operations/${id}/start`, { operator_id: operatorId }).then((r) => r.data.data),
 pause: (id: string) =>
 client.post<ApiSuccess<WoOperation>>(`/production/operations/${id}/pause`).then((r) => r.data.data),
 resume: (id: string, operatorId: string) =>
 client.post<ApiSuccess<WoOperation>>(`/production/operations/${id}/resume`, { operator_id: operatorId }).then((r) => r.data.data),
 recordOutput: (id: string, data: { qty: number; scrap?: number; scrap_reason?: string }) =>
 client.post<ApiSuccess<WoOperation>>(`/production/operations/${id}/output`, data).then((r) => r.data.data),
 complete: (id: string) =>
 client.post<ApiSuccess<WoOperation>>(`/production/operations/${id}/complete`).then((r) => r.data.data),
 skip: (id: string, reason: string, operatorId: string) =>
 client.post<ApiSuccess<WoOperation>>(`/production/operations/${id}/skip`, { reason, operator_id: operatorId }).then((r) => r.data.data),
};
