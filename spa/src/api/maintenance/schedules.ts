import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
 CreateMaintenanceScheduleData,
 MaintainableType,
 MaintenanceSchedule,
 MaintenanceScheduleInterval,
 MaintenanceWindowSuggestions,
} from '@/types/maintenance';

export interface ScheduleListParams extends ListParams {
 maintainable_type?: MaintainableType;
 interval_type?: MaintenanceScheduleInterval;
 is_active?: boolean | string;
}

export const schedulesApi = {
 targets: (type: string, search?: string) =>
  client.get<{ data: Array<{ id: string; code: string; name: string }> }>(
   '/maintenance/schedules/target-options', { params: { type, search } }).then(r => r.data.data),
 options: () => client.get<{ data: { maintainable_types: Array<{ value: string; label: string }>; interval_types: Array<{ value: string; label: string }> } }>('/maintenance/schedules/options').then(r => r.data.data),
 list: (params?: ScheduleListParams) =>
 client.get<PaginatedResponse<MaintenanceSchedule>>('/maintenance/schedules', { params }).then(r => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<MaintenanceSchedule>>(`/maintenance/schedules/${id}`).then(r => r.data.data),
 windowSuggestions: (id: string) =>
 client.get<ApiSuccess<MaintenanceWindowSuggestions>>(`/maintenance/schedules/${id}/window-suggestions`).then(r => r.data.data),
 confirmWindow: (workOrderId: string, data: { planned_start_at: string; planned_end_at: string; employee_id: string }) =>
 client.post(`/maintenance/work-orders/${workOrderId}/window`, data).then(r => r.data.data),
 create: (data: CreateMaintenanceScheduleData) =>
 client.post<ApiSuccess<MaintenanceSchedule>>('/maintenance/schedules', data).then(r => r.data.data),
 update: (id: string, data: Partial<CreateMaintenanceScheduleData>) =>
 client.put<ApiSuccess<MaintenanceSchedule>>(`/maintenance/schedules/${id}`, data).then(r => r.data.data),
 destroy: (id: string) =>
  client.delete(`/maintenance/schedules/${id}`),
 restore: (id: string) =>
  client.patch(`/maintenance/schedules/${id}/restore`),
};
