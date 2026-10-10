import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  CreateMaintenanceWorkOrderData,
  RepairEvidence,
  MaintenancePriority,
  MaintenanceWorkOrder,
  MaintenanceWorkOrderStatus,
  MaintenanceWorkOrderType,
} from '@/types/maintenance';

export interface WorkOrderListParams extends ListParams {
  queue?: 'unassigned' | 'critical' | 'blocked_by_production' | 'awaiting_verification' | 'in_progress' | 'overdue_preventive' | '';
  maintainable_type?: string;
  type?: MaintenanceWorkOrderType;
  priority?: MaintenancePriority;
  status?: MaintenanceWorkOrderStatus | '';
  assigned_to?: string;
}

export interface WorkOrderStatusOption {
  value: MaintenanceWorkOrderStatus;
  label: string;
  is_terminal: boolean;
}

export interface MaintenanceAssigneeCandidate extends MaintenanceAssigneeOption {
  presence: { state: string; reason: string | null; reason_code: string | null };
  active_load: number;
  can_assign: boolean;
  can_attest_presence: boolean;
  blocked_reason: string | null;
}
export interface MaintenanceAssigneeSuggestions {
  suggested_employee_id: string | null;
  evaluated_at: string;
  candidates: MaintenanceAssigneeCandidate[];
}
export interface MaintenanceAssignmentChoice {
  expected_assignee_id: string | null;
  presence_attestation_reason?: string;
  handover_note?: string;
}

export interface MaintenanceAssigneeOption {
  id: string;
  employee_no: string;
  name: string;
}

export interface MaintenanceTargetOption {
  id: string;
  code: string;
  name: string;
}
export interface MaintenancePartOption {
  id: string;
  code: string;
  name: string;
  unit_of_measure: string;
  history_count?: number;
  stock_status?: 'available' | 'no_usable_stock';
  stock?: MaintenancePartStock[];
}
export interface MaintenancePartStock {
  lots?: Array<{ lot_number: string; expiry_date: string | null; available: string }>;
  location: { id: string; code: string };
  available: string;
}

export const workOrdersApi = {
  targets: (type: string, search?: string) =>
    client
      .get<{
        data: MaintenanceTargetOption[];
      }>('/maintenance/target-options', { params: { type, search, available_only: 1 } })
      .then((r) => r.data),
  spareParts: (search: string, work_order_id?: string) =>
    client
      .get<{
        data: MaintenancePartOption[];
      }>('/maintenance/spare-part-options', { params: { search, work_order_id } })
      .then((r) => r.data),
  sparePartStock: (item_id: string, work_order_id?: string) =>
    client
      .get<{
        data: MaintenancePartStock[];
      }>('/maintenance/spare-part-stock', { params: { item_id, work_order_id } })
      .then((r) => r.data),
  options: () =>
    client
      .get<{
        data: {
          maintainable_types: Array<{ value: string; label: string }>;
          types: Array<{ value: string; label: string }>;
          priorities: Array<{ value: string; label: string }>;
          statuses: WorkOrderStatusOption[];
          default_type: string;
          default_priority: string;
        };
      }>('/maintenance/work-orders/options')
      .then((r) => r.data.data),
  assignees: () =>
    client
      .get<{ data: MaintenanceAssigneeOption[] }>('/maintenance/work-orders/assignees')
      .then((r) => r.data.data),
  list: (params?: WorkOrderListParams) =>
    client
      .get<PaginatedResponse<MaintenanceWorkOrder>>('/maintenance/work-orders', { params })
      .then((r) => r.data),
  show: (id: string) =>
    client
      .get<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}`)
      .then((r) => r.data.data),
  create: (data: CreateMaintenanceWorkOrderData) =>
    client
      .post<ApiSuccess<MaintenanceWorkOrder>>('/maintenance/work-orders', data)
      .then((r) => r.data.data),
  assigneeSuggestions: (id: string) =>
    client.get<{ data: MaintenanceAssigneeSuggestions }>(`/maintenance/work-orders/${id}/assignee-suggestions`).then((response) => response.data.data),

  assign: (id: string, employee_id: string, choice?: MaintenanceAssignmentChoice) =>
    client
      .patch<
        ApiSuccess<MaintenanceWorkOrder>
      >(`/maintenance/work-orders/${id}/assign`, { employee_id, ...choice })
      .then((r) => r.data.data),
  start: (id: string) =>
    client
      .patch<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/start`)
      .then((r) => r.data.data),
  complete: (id: string, data: { remarks?: string }) =>
    client
      .patch<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/complete`, data)
      .then((r) => r.data.data),
  reconcileParts: (id: string, data: { confirm_consumed?: true; inspection_reason?: string }) =>
    client
      .post<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/reconcile-parts`, data)
      .then((r) => r.data.data),
  cancel: (id: string, reason?: string) =>
    client
      .patch<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/cancel`, { reason })
      .then((r) => r.data.data),
  submitRepair: (id: string, evidence: RepairEvidence) =>
    client.post<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/submit-repair`, evidence).then((r) => r.data.data),
  verifyRepair: (id: string, data: { decision: 'accept' | 'reject'; reason?: string }) =>
    client.post<ApiSuccess<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/verify`, data).then((r) => r.data.data),
  addLog: (id: string, description: string) =>
    client.post(`/maintenance/work-orders/${id}/logs`, { description }).then((r) => r.data),
  recordWork: (id: string, data: { finding: string; action_taken: string; result: 'in_progress' | 'passed' | 'needs_follow_up' }) =>
    client.post(`/maintenance/work-orders/${id}/work-records`, data).then((r) => r.data.data),
  withdrawPartRequest: (id: string, requestId: string) => client.post(`/maintenance/work-orders/${id}/part-requests/${requestId}/withdraw`),
  returnPart: (id: string, usageId: string, quantity: string, operationKey: string) =>
    client.post(`/maintenance/work-orders/${id}/spare-parts/${usageId}/returns`, {
      quantity, operation_key: operationKey,
    }).then((r) => r.data),
};
