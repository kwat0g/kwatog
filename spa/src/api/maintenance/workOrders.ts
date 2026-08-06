import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
 CreateMaintenanceWorkOrderData,
 MaintenancePriority,
 MaintenanceWorkOrder,
 MaintenanceWorkOrderStatus,
 MaintenanceWorkOrderType,
} from '@/types/maintenance';

export interface WorkOrderListParams extends ListParams {
 maintainable_type?: string;
 type?: MaintenanceWorkOrderType;
 priority?: MaintenancePriority;
 status?: MaintenanceWorkOrderStatus;
 assigned_to?: number | string;
}

export interface WorkOrderStatusOption {
 value: MaintenanceWorkOrderStatus;
 label: string;
 is_terminal: boolean;
}

export const workOrdersApi = {
 options: () => client.get<{ data: { maintainable_types: Array<{ value: string; label: string }>; types: Array<{ value: string; label: string }>; priorities: Array<{ value: string; label: string }>; statuses: WorkOrderStatusOption[]; default_type: string; default_priority: string } }>('/maintenance/work-orders/options').then(r => r.data.data),
 list: (params?: WorkOrderListParams) =>
 client.get<PaginatedResponse<MaintenanceWorkOrder>>('/maintenance/work-orders', { params }).then(r => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}`).then(r => r.data.data),
 create: (data: CreateMaintenanceWorkOrderData) =>
 client.post<ApiSuccess<MaintenanceWorkOrder>>('/maintenance/work-orders', data).then(r => r.data.data),
 assign: (id: string, employee_id: number) =>
 client.patch<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/assign`, { employee_id }).then(r => r.data.data),
 start: (id: string) =>
 client.patch<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/start`).then(r => r.data.data),
 complete: (id: string, data: { remarks?: string; downtime_minutes?: number }) =>
 client.patch<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/complete`, data).then(r => r.data.data),
 cancel: (id: string, reason?: string) =>
 client.patch<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/cancel`, { reason }).then(r => r.data.data),
 addLog: (id: string, description: string) =>
 client.post(`/maintenance/work-orders/${id}/logs`, { description }).then(r => r.data),
};
