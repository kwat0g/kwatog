import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  MaterialHandover,
  MaterialHandoverTask,
  MaterialHandoverTaskDetail,
  WorkOrderReservations,
  MaterialReturnOptions,
  StockAdjustment,
  StockLevel,
  StockMovement,
} from '@/types/inventory';

export const stockLevelsApi = {
  reservations: (params?: ListParams) =>
    client
      .get<PaginatedResponse<WorkOrderReservations>>('/inventory/material-reservations', {
        params: { ...params, group_by: 'work_order' },
      })
      .then((r) => r.data),
  list: (
    params?: ListParams & {
      item_id?: string;
      warehouse_id?: string;
      item_type?: string;
      low_only?: boolean;
    },
  ) =>
    client
      .get<PaginatedResponse<StockLevel>>('/inventory/stock-levels', { params })
      .then((r) => r.data),
};

export const stockMovementsApi = {
  handoverTasks: (params?: ListParams & { status?: MaterialHandoverTask['status'] | ''; work_order_id?: string; maintenance_work_order_id?: string }) =>
    client.get<PaginatedResponse<MaterialHandoverTask>>('/inventory/material-handovers', { params }).then((r) => r.data),
  handoverSources: (params: { work_order_id: string; material_issue_id?: never } | { material_issue_id: string; work_order_id?: never } | { maintenance_work_order_id: string }) =>
    client.get<ApiSuccess<MaterialHandoverTaskDetail>>('/inventory/material-handovers/sources', { params }).then((r) => r.data.data),
  confirmMaintenanceRequest: (requestId: string, data: { received_by_employee_id: string; expected_quantity: string }) =>
    client.post(`/inventory/maintenance-part-requests/${requestId}/handover`, data),
  handoverGroup: (data: {
    received_by_employee_id: string;
    sources: { movement_id: string; expected_quantity: string }[];
  }) => client.post('/inventory/material-handovers/group', data),
  handoverOptions: (id: string) =>
    client
      .get<{
        data: {
          quantity: string;
          unit_of_measure?: string | null;
          default_recipient_id?: string | null;
          recipients: Array<{ value: string; label: string }>;
        };
      }>(`/inventory/stock-movements/${id}/handover-options`)
      .then((r) => r.data.data),
  handover: (id: string, data: { received_by_employee_id: string; expected_quantity: string }) =>
    client
      .post<{ data: MaterialHandover }>(`/inventory/stock-movements/${id}/handover`, data)
      .then((r) => r.data.data),
  options: () =>
    client
      .get<{
        data: { movement_types: Array<{ value: string; label: string }> };
      }>('/inventory/stock-movements/options')
      .then((r) => r.data.data),
  list: (
    params?: ListParams & {
      item_id?: string;
      movement_id?: string;
      movement_type?: string;
      from?: string;
      to?: string;
      reference_type?: string;
    },
  ) =>
    client
      .get<PaginatedResponse<StockMovement>>('/inventory/stock-movements', { params })
      .then((r) => r.data),
  returnOptions: (movementId: string) =>
    client
      .get<{
        data: MaterialReturnOptions;
      }>(`/inventory/stock-movements/${movementId}/return-options`)
      .then((r) => r.data.data),
  returnUnused: (
    movementId: string,
    data: { quantity_returned: string; expected_returned_quantity: string; reason: string },
    idempotencyKey: string,
  ) =>
    client
      .post<
        ApiSuccess<StockMovement>
      >(`/inventory/stock-movements/${movementId}/return-unused`, data, { headers: { 'Idempotency-Key': idempotencyKey } })
      .then((r) => r.data.data),
  retryGlHandoff: (movementId: string) =>
    client
      .post<ApiSuccess<StockMovement>>(`/inventory/stock-movements/${movementId}/retry-gl`)
      .then((r) => r.data.data),
};

// `direction` here is the adjustment direction, not a sort order — the backend
// validates it as in|out and exposes no sort-direction param on this endpoint,
// so the base ListParams meaning is deliberately omitted rather than widened.
export interface StockAdjustmentListParams extends Omit<ListParams, 'direction'> {
  status?: 'pending' | 'approved' | 'rejected' | 'withdrawn' | '';
  direction?: 'in' | 'out';
}

export const stockAdjustmentsApi = {
  options: () =>
    client
      .get<{
        data: {
          statuses: Array<{ value: string; label: string }>;
          directions: Array<{ value: string; label: string }>;
          reasons: Array<{ value: string; label: string }>;
          approval_threshold: string;
        };
      }>('/inventory/stock-adjustments/options')
      .then((r) => r.data.data),
  list: (params?: StockAdjustmentListParams) =>
    client
      .get<PaginatedResponse<StockAdjustment>>('/inventory/stock-adjustments', { params })
      .then((r) => r.data),
  create: (data: {
    item_id: string;
    location_id: string;
    direction: 'in' | 'out';
    quantity: string;
    unit_cost?: string;
    reason_code?: string;
    reason: string;
  }, idempotencyKey?: string) =>
    client
      .post<ApiSuccess<StockAdjustment>>('/inventory/stock-adjustments', data, idempotencyKey ? { headers: { 'Idempotency-Key': idempotencyKey } } : undefined)
      .then((r) => r.data.data),
  approve: (id: string) =>
    client
      .patch<ApiSuccess<StockAdjustment>>(`/inventory/stock-adjustments/${id}/approve`)
      .then((r) => r.data.data),
  reject: (id: string, reason: string) =>
    client
      .patch<ApiSuccess<StockAdjustment>>(`/inventory/stock-adjustments/${id}/reject`, { reason })
      .then((r) => r.data.data),
  withdraw: (id: string) =>
    client
      .patch<ApiSuccess<StockAdjustment>>(`/inventory/stock-adjustments/${id}/withdraw`)
      .then((r) => r.data.data),
};
