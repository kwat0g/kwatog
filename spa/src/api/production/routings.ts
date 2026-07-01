// Task 12 — Production routings + WO operations API client.

import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ProductRouting, WoOperation } from '@/types/production/routing';

export interface RoutingListParams extends ListParams {
  product_search?: string;
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
};

export const woOperationsApi = {
  list: (workOrderId: string) =>
    client.get<{ data: WoOperation[] }>(`/production/work-orders/${workOrderId}/operations`).then((r) => r.data.data),
  show: (id: string) =>
    client.get<ApiSuccess<WoOperation>>(`/production/operations/${id}`).then((r) => r.data.data),
  startSetup: (id: string, operatorId: string) =>
    client.post(`/production/operations/${id}/start-setup`, { operator_id: operatorId }),
  endSetup: (id: string) =>
    client.post(`/production/operations/${id}/end-setup`),
  start: (id: string, operatorId: string) =>
    client.post(`/production/operations/${id}/start`, { operator_id: operatorId }),
  pause: (id: string) =>
    client.post(`/production/operations/${id}/pause`),
  resume: (id: string, operatorId: string) =>
    client.post(`/production/operations/${id}/resume`, { operator_id: operatorId }),
  recordOutput: (id: string, data: { qty: number; scrap?: number; scrap_reason?: string }) =>
    client.post(`/production/operations/${id}/output`, data),
  complete: (id: string) =>
    client.post(`/production/operations/${id}/complete`),
  skip: (id: string, reason: string) =>
    client.post(`/production/operations/${id}/skip`, { reason }),
};
