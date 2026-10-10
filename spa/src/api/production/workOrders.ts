import type {
  CrewContext,
  AssignmentSuggestion,
  ApplyAssignmentData,
  AssignCrewData,
  EmployeeOption,
  AssignedWorkOrder,
  OutputReceipt,
  WorkerOutputData,
  StaffingLimits,
  ExecutionReadiness,
  RescheduleSuggestion,
  HistoricalWorkOrderEntry,
} from '@/types/production-crew';
import { client } from '../client';
import { isAxiosError } from 'axios';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ChainStep } from '@/types/production';
import type {
  CreateWorkOrderData,
  MachineDowntimeCategoryOption,
  RecordOutputData,
  RecordTrialData,
  LateProductionReport,
  LateProductionReportSubmission,
  WorkOrder,
  WorkOrderOutput,
} from '@/types/production';

export interface WorkOrderListParams extends ListParams {
  status?: string;
  production_purpose?: 'stock' | 'order' | 'internal' | '';
  sales_order_id?: string;
  machine_id?: string;
}

interface ModulePage<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface WorkOrderFormOptions {
  products: Array<{ id: string; part_number: string; name: string; stock_production?: { eligible: boolean; blockers: string[] } }>;
  machines: Array<{
    id: string;
    machine_code: string;
    name: string;
    tonnage: number | null;
    status: string;
  }>;
  molds: Array<{
    id: string;
    mold_code: string;
    name: string;
    product: { id: string; part_number: string; name: string } | null;
    cavity_count: number | null;
    status: string;
  }>;
  shifts: Array<{ id: string; name: string }>;
}

export const workOrdersApi = {
  extendRun: (
    id: string,
    data: {
      expected_crew_id: string;
      expected_schedule_id: string;
      expected_schedule_updated_at: string;
      scheduled_end: string;
      reason: string;
      presence_confirmed_employee_ids?: string[];
      late_production_authorized?: boolean;
      late_production_reason?: string;
    },
  ) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/extend-run`, data)
      .then((r) => r.data.data),
  executionReadiness: (id: string) =>
    client
      .get<ApiSuccess<ExecutionReadiness>>(`/production/work-orders/${id}/execution-readiness`)
      .then((r) => r.data.data),
  rescheduleSuggestion: (id: string, preferredDate?: string) =>
    client
      .get<ApiSuccess<RescheduleSuggestion>>(
        `/production/work-orders/${id}/reschedule-suggestion`,
        {
          params: { preferred_date: preferredDate },
        },
      )
      .then((r) => r.data.data),
  generateAssignmentSuggestion: (
    id: string,
    limits?: StaffingLimits & { preferred_production_date?: string | null },
  ) =>
    client
      .post<
        ApiSuccess<AssignmentSuggestion>
      >(`/production/work-orders/${id}/assignment-suggestion`, limits)
      .then((r) => r.data.data),
  assignmentSuggestion: (id: string, ncrId?: string, limits?: StaffingLimits) =>
    client
      .get<
        ApiSuccess<AssignmentSuggestion>
      >(`/production/work-orders/${id}/assignment-suggestion`, { params: { rework_ncr_id: ncrId, ...limits, ...(limits && { maximum_workers: limits.maximum_workers ?? '' }) } })
      .then((r) => r.data.data),
  applyAssignment: (id: string, data: ApplyAssignmentData) =>
    client
      .post<ApiSuccess<CrewContext>>(`/production/work-orders/${id}/assignment-apply`, data)
      .then((r) => r.data.data),
  reviewSlot: (
    id: string,
    data: {
      preferred_production_date?: string | null;
      scheduled_start: string;
      scheduled_end: string;
      reason: string;
      expected_schedule_id: string | null;
      expected_schedule_updated_at: string | null;
      machine_id?: string;
      mold_id?: string;
      employee_ids?: string[];
      recorder_employee_id?: string;
      expected_crew_id?: string | null;
      holiday_authorized?: boolean;
      late_production_authorized?: boolean;
      late_production_reason?: string;
    },
    ncrId?: string,
  ) =>
    client
      .post<
        ApiSuccess<WorkOrder>
      >(`/production/work-orders/${id}${ncrId ? `/rework/${ncrId}` : ''}/review-slot`, data)
      .then((r) => r.data.data),
  repairSuggestion: (
    id: string,
    ncrId: string,
    options: {
      minimum_workers: number;
      manual_repair: boolean;
      preferred_production_date?: string | null;
    },
  ) =>
    client
      .get<
        ApiSuccess<RescheduleSuggestion>
      >(`/production/work-orders/${id}/rework/${ncrId}/suggestion`, { params: { ...options, manual_repair: options.manual_repair ? 1 : 0 } })
      .then((r) => r.data.data),
  releaseResources: (id: string, reason: string) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/release-resources`, { reason })
      .then((r) => r.data.data),
  crew: (id: string, ncrId?: string) =>
    client
      .get<
        ApiSuccess<CrewContext>
      >(`/production/work-orders/${id}/crew`, { params: { rework_ncr_id: ncrId } })
      .then((r) => r.data.data),
  crewOptions: (
    id: string,
    search: string,
    ncrId?: string,
    timing?: { scheduled_start: string; scheduled_end: string; holiday_authorized?: boolean },
  ) =>
    client
      .get<ApiSuccess<EmployeeOption[]>>(`/production/work-orders/${id}/crew-options`, {
        params: {
          search,
          rework_ncr_id: ncrId,
          ...timing,
          ...(timing?.holiday_authorized !== undefined
            ? { holiday_authorized: timing.holiday_authorized ? 1 : 0 }
            : {}),
        },
      })
      .then((r) => r.data.data),
  assignCrew: (id: string, data: AssignCrewData) =>
    client
      .post<ApiSuccess<CrewContext>>(`/production/work-orders/${id}/crew`, data)
      .then((r) => r.data.data),
  myWorkOrders: (page = 1) =>
    client
      .get<PaginatedResponse<AssignedWorkOrder>>('/production/my-work-orders', { params: { page } })
      .then((r) => r.data),
  myWorkOrderHistory: (page = 1) =>
    client
      .get<
        ModulePage<HistoricalWorkOrderEntry>
      >('/production/my-work-orders/history', { params: { page } })
      .then((r) => r.data),
  myWorkOrder: (id: string, ncrId?: string, crewId?: string) =>
    client
      .get<
        ApiSuccess<AssignedWorkOrder>
      >(`/production/my-work-orders/${id}`, { params: { rework_ncr_id: ncrId, crew_id: crewId } })
      .then((r) => r.data.data),
  myLateProductionReports: (id: string, page = 1) =>
    client
      .get<
        ModulePage<LateProductionReport>
      >(`/production/my-work-orders/${id}/late-production-reports`, { params: { per_page: 50, page } })
      .then((r) => r.data),
  myLateProductionReportOptions: (id: string, crewId: string) =>
    client
      .get<
        ApiSuccess<{ defect_types: { id: string; code: string; name: string }[] }>
      >(`/production/my-work-orders/${id}/late-production-report-options`, { params: { crew_id: crewId } })
      .then((r) => r.data.data),
  proposeLateProduction: (id: string, data: LateProductionReportSubmission, key: string) =>
    client
      .post<ApiSuccess<LateProductionReport>>(
        `/production/my-work-orders/${id}/late-production-reports`,
        data,
        {
          headers: { 'X-Idempotency-Key': key },
        },
      )
      .then((r) => r.data.data),
  lateProductionReports: (workOrderId: string, status?: string, page = 1) =>
    client
      .get<ModulePage<LateProductionReport>>('/production/late-production-reports', {
        params: { work_order_id: workOrderId, status, per_page: 50, page },
      })
      .then((r) => r.data),
  approveLateProductionReport: (id: string, reviewReason?: string) =>
    client
      .post<ApiSuccess<LateProductionReport>>(`/production/late-production-reports/${id}/approve`, {
        review_reason: reviewReason || undefined,
      })
      .then((r) => r.data.data),
  rejectLateProductionReport: (id: string, reason: string) =>
    client
      .post<
        ApiSuccess<LateProductionReport>
      >(`/production/late-production-reports/${id}/reject`, { reason })
      .then((r) => r.data.data),
  myOutputOptions: (id: string, ncrId?: string) =>
    client
      .get<
        ApiSuccess<{ defect_types: { id: string; code: string; name: string }[] }>
      >(`/production/my-work-orders/${id}/output-options`, { params: { rework_ncr_id: ncrId } })
      .then((r) => r.data.data),
  myOutputs: (id: string, ncrId?: string) =>
    client
      .get<
        ApiSuccess<OutputReceipt[]>
      >(`/production/my-work-orders/${id}/outputs`, { params: { rework_ncr_id: ncrId } })
      .then((r) => r.data.data),
  myOutputReceipt: (id: string, key: string) =>
    client
      .get<ApiSuccess<OutputReceipt | null>>(`/production/my-output-receipts/${id}`, {
        params: { idempotency_key: key },
      })
      .then((r) => r.data.data)
      .catch((error: unknown) => {
        if (isAxiosError(error) && error.response?.status === 404) return null;
        throw error;
      }),
  recordMyOutput: (id: string, data: WorkerOutputData, key: string, ncrId?: string) =>
    client
      .post<
        ApiSuccess<OutputReceipt>
      >(`/production/my-work-orders/${id}${ncrId ? `/rework/${ncrId}` : ''}/outputs`, data, { headers: { 'X-Idempotency-Key': key } })
      .then((r) => r.data.data),
  startRework: (
    id: string,
    ncrId: string,
    machineId?: string,
    moldId?: string,
    execution?: {
      expected_crew_id: string;
      presence_confirmed_employee_ids?: string[];
      expected_schedule_id?: string;
      expected_schedule_updated_at?: string;
      late_start_authorized?: boolean;
    },
  ) =>
    client
      .post<
        ApiSuccess<WorkOrder>
      >(`/production/work-orders/${id}/rework/${ncrId}/start`, { machine_id: machineId, mold_id: moldId, ...execution })
      .then((r) => r.data.data),
  recordRework: (id: string, ncrId: string, data: RecordOutputData, key: string) =>
    client
      .post<
        ApiSuccess<WorkOrderOutput>
      >(`/production/work-orders/${id}/rework/${ncrId}/outputs`, data, { headers: { 'X-Idempotency-Key': key } })
      .then((r) => r.data.data),
  finishRework: (id: string, ncrId: string) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/rework/${ncrId}/finish`)
      .then((r) => r.data.data),
  formOptions: () =>
    client
      .get<ApiSuccess<WorkOrderFormOptions>>('/production/work-orders/form-options')
      .then((r) => r.data.data),
  options: () =>
    client
      .get<{
        data: {
          statuses: Array<{
            value: string;
            label: string;
            next_statuses: Array<{ value: string; label: string }>;
          }>;
          operation_statuses: Array<{ value: string; label: string }>;
        };
      }>('/production/work-orders/options')
      .then((r) => r.data.data),
  downtimeCategories: () =>
    client
      .get<ApiSuccess<MachineDowntimeCategoryOption[]>>('/production/downtime-categories')
      .then((r) => r.data.data),
  list: (params?: WorkOrderListParams) =>
    client
      .get<PaginatedResponse<WorkOrder>>('/production/work-orders', { params })
      .then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}`).then((r) => r.data.data),
  create: (data: CreateWorkOrderData) =>
    client.post<ApiSuccess<WorkOrder>>('/production/work-orders', data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/production/work-orders/${id}`),
  restore: (id: string) => client.patch(`/production/work-orders/${id}/restore`),
  confirm: (
    id: string,
    review: {
      schedule_id: string;
      schedule_updated_at: string;
      expected_crew_id: string;
      machine_id?: string;
      mold_id?: string;
    },
  ) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/confirm`, review)
      .then((r) => r.data.data),
  start: (
    id: string,
    execution?: {
      expected_crew_id: string;
      presence_confirmed_employee_ids?: string[];
      expected_schedule_id?: string;
      expected_schedule_updated_at?: string;
      late_start_authorized?: boolean;
    },
  ) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/start`, execution)
      .then((r) => r.data.data),
  pause: (id: string, reason: string, category: string) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/pause`, { reason, category })
      .then((r) => r.data.data),
  resume: (
    id: string,
    execution?: {
      expected_crew_id: string;
      presence_confirmed_employee_ids?: string[];
      expected_schedule_id?: string;
      expected_schedule_updated_at?: string;
      late_start_authorized?: boolean;
    },
  ) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/resume`, execution)
      .then((r) => r.data.data),
  complete: (
    id: string,
    data?: { short_completion_reason?: string; production_finished_at?: string },
  ) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/complete`, data)
      .then((r) => r.data.data),
  close: (id: string) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/close`)
      .then((r) => r.data.data),
  cancel: (id: string, reason?: string) =>
    client
      .post<ApiSuccess<WorkOrder>>(`/production/work-orders/${id}/cancel`, { reason })
      .then((r) => r.data.data),
  chain: (id: string) =>
    client
      .get<{ data: ChainStep[] }>(`/production/work-orders/${id}/chain`)
      .then((r) => r.data.data),
  recordTrial: (id: string, data: RecordTrialData, key: string) =>
    client
      .post<
        ApiSuccess<WorkOrderOutput>
      >(`/production/work-orders/${id}/trial-outputs`, data, { headers: { 'X-Idempotency-Key': key } })
      .then((r) => r.data.data),
  recordMyTrial: (id: string, data: RecordTrialData, key: string) =>
    client
      .post<
        ApiSuccess<OutputReceipt>
      >(`/production/my-work-orders/${id}/trial-outputs`, data, { headers: { 'X-Idempotency-Key': key } })
      .then((r) => r.data.data),
  recordOutput: (id: string, data: RecordOutputData, idempotencyKey?: string) =>
    client
      .post<ApiSuccess<WorkOrderOutput>>(`/production/work-orders/${id}/outputs`, data, {
        headers: idempotencyKey ? { 'X-Idempotency-Key': idempotencyKey } : undefined,
      })
      .then((r) => r.data.data),
  listOutputs: (id: string) =>
    client
      .get<{ data: WorkOrderOutput[] }>(`/production/work-orders/${id}/outputs`)
      .then((r) => r.data.data),
  retryProductionReceipt: (workOrderId: string, outputId: string) =>
    client
      .post<
        ApiSuccess<WorkOrderOutput>
      >(`/production/work-orders/${workOrderId}/outputs/${outputId}/retry-receipt`)
      .then((r) => r.data.data),
};
