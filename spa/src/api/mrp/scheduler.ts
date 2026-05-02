import { client } from '../client';
import type { GanttSnapshot, SchedulerRunResult } from '@/types/mrp';

export const schedulerApi = {
  run: (workOrderIds?: string[]) =>
    client.post<{ data: SchedulerRunResult }>('/mrp/scheduler/run', { work_order_ids: workOrderIds })
      .then((r) => r.data.data),
  confirm: (scheduleIds: string[]) =>
    client.post<{ data: { confirmed_count: number; schedule_ids: string[] } }>('/mrp/scheduler/confirm', { schedule_ids: scheduleIds })
      .then((r) => r.data.data),
  reorder: (scheduleId: string, priorityOrder: number) =>
    client.patch(`/mrp/scheduler/${scheduleId}/reorder`, { priority_order: priorityOrder }),
  reassign: (scheduleId: string, machineId: string, moldId: string) =>
    client.patch(`/mrp/scheduler/${scheduleId}/reassign`, { machine_id: machineId, mold_id: moldId }),
  snapshot: (from?: string, to?: string) =>
    client.get<{ data: GanttSnapshot }>('/mrp/scheduler/snapshot', { params: { from, to } })
      .then((r) => r.data.data),
};
