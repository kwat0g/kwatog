import { client } from './client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import type { WorkOrder, WorkOrderOutput, RecordOutputData } from '@/types/production';
import type { CreateInspectionData } from '@/types/quality';

export const factoryApi = {
  // Active work orders assigned to current operator's machine
  activeOrders: (params?: { machine_id?: string }) =>
    client.get<PaginatedResponse<WorkOrder>>('/production/work-orders', {
      params: { ...params, status: 'in_progress,confirmed,paused', per_page: 50 },
    }).then(r => r.data),

  // Record output for a work order
  recordOutput: (woId: string, data: RecordOutputData, idempotencyKey?: string) =>
    client.post<ApiSuccess<WorkOrderOutput>>(`/production/work-orders/${woId}/outputs`, data, {
      headers: idempotencyKey ? { 'X-Idempotency-Key': idempotencyKey } : undefined,
    }).then(r => r.data.data),

  // List outputs for a WO
  listOutputs: (woId: string) =>
    client.get<{ data: WorkOrderOutput[] }>(`/production/work-orders/${woId}/outputs`).then(r => r.data.data),

  // Start/pause/resume/complete work order
  start: (id: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/start`).then(r => r.data.data),
  pause: (id: string, reason: string, category: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/pause`, { reason, category }).then(r => r.data.data),
  resume: (id: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/resume`).then(r => r.data.data),
  complete: (id: string) =>
    client.post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/complete`).then(r => r.data.data),

  // Quick QC check — creates an inspection with minimal fields
  quickQcCheck: (data: CreateInspectionData) =>
    client.post<ApiSuccess<{ id: string }>>('/quality/inspections', data).then(r => r.data.data),
};
