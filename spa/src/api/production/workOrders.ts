import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ChainStep } from '@/types/production';
import type { CreateWorkOrderData, RecordOutputData, WorkOrder, WorkOrderOutput } from '@/types/production';

export interface WorkOrderListParams extends ListParams {
  status?: string;
  sales_order_id?: string;
  machine_id?: string;
}

export const workOrdersApi = {
  list: (params?: WorkOrderListParams) =>
    client.get<PaginatedResponse<WorkOrder>>('/production/work-orders', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}`).then((r) => r.data.data),
  create: (data: CreateWorkOrderData) =>
    client.post<ApiSuccess<WorkOrder>>('/production/work-orders', data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/production/work-orders/${id}`),
  confirm: (id: string, machineId?: string, moldId?: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/confirm`, { machine_id: machineId, mold_id: moldId }).then((r) => r.data.data),
  start: (id: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/start`).then((r) => r.data.data),
  pause: (id: string, reason: string, category: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/pause`, { reason, category }).then((r) => r.data.data),
  resume: (id: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/resume`).then((r) => r.data.data),
  complete: (id: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/complete`).then((r) => r.data.data),
  close: (id: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/close`).then((r) => r.data.data),
  cancel: (id: string, reason?: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/cancel`, { reason }).then((r) => r.data.data),
  chain: (id: string) =>
    client.get<{ data: ChainStep[] }>(`/production/work-orders/${id}/chain`).then((r) => r.data.data),
  recordOutput: (id: string, data: RecordOutputData, idempotencyKey?: string) =>
    client.post<ApiSuccess<WorkOrderOutput>>(`/production/work-orders/${id}/outputs`, data, {
      headers: idempotencyKey ? { 'X-Idempotency-Key': idempotencyKey } : undefined,
    }).then((r) => r.data.data),
  listOutputs: (id: string) =>
    client.get<{ data: WorkOrderOutput[] }>(`/production/work-orders/${id}/outputs`).then((r) => r.data.data),
};
