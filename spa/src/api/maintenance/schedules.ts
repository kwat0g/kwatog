import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  CreateMaintenanceScheduleData,
  MaintainableType,
  MaintenanceSchedule,
  MaintenanceScheduleInterval,
} from '@/types/maintenance';

export interface ScheduleListParams extends ListParams {
  maintainable_type?: MaintainableType;
  interval_type?: MaintenanceScheduleInterval;
  is_active?: boolean | string;
}

export const schedulesApi = {
  list: (params?: ScheduleListParams) =>
    client.get<PaginatedResponse<MaintenanceSchedule>>('/maintenance/schedules', { params }).then(r => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<MaintenanceSchedule>>(`/maintenance/schedules/${id}`).then(r => r.data.data),
  create: (data: CreateMaintenanceScheduleData) =>
    client.post<ApiSuccess<MaintenanceSchedule>>('/maintenance/schedules', data).then(r => r.data.data),
  update: (id: string, data: Partial<CreateMaintenanceScheduleData>) =>
    client.put<ApiSuccess<MaintenanceSchedule>>(`/maintenance/schedules/${id}`, data).then(r => r.data.data),
  destroy: (id: string) =>
    client.delete(`/maintenance/schedules/${id}`),
};
