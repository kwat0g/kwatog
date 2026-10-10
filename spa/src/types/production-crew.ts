import type {
  LateProductionReport,
  MaterialHandoverException,
  StartupTrial,
} from '@/types/production';

export interface WorkerPresence {
  state: 'present' | 'unknown' | 'blocked';
  reason: string | null;
  reason_code?: string | null;
  clocked_in?: boolean;
  time_in?: string | null;
  shift_start?: string | null;
  shift_end?: string | null;
  work_date: string;
}
export interface EmployeeOption {
  presence?: WorkerPresence;
  schedule_attendance?:
    | (Omit<WorkerPresence, 'state'> & {
        state: WorkerPresence['state'] | 'upcoming';
      })
    | null;
  id: string;
  employee_number: string;
  name: string;
  has_active_account: boolean;
  can_assign?: boolean;
  blocked_reason?: string | null;
  shift: {
    id: string;
    name: string;
    scheduled_start?: string | null;
    scheduled_end?: string | null;
    work_date?: string | null;
  } | null;
}
export interface Crew {
  id: string;
  members: EmployeeOption[];
  recorder_employee_id: string;
  reason: string;
  assigned_at: string;
  ended_at: string | null;
  rework_ncr_id: string | null;
}
export interface CrewContext {
  server_time?: string;
  preferred_production_date?: string | null;
  delivery_date?: string | null;
  production_deadline?: string | null;
  can_approve_late_production?: boolean;
  presence?: (WorkerPresence & { employee_id: string; name: string })[];
  schedule?: AssignmentSuggestion['schedule'];
  current: Crew | null;
  history: Crew[];
  minimum_workers: number;
  maximum_workers: number | null;
  can_assign: boolean;
  can_review_slot?: boolean;
}
export interface AssignCrewData {
  presence_confirmed_employee_ids?: string[];
  employee_ids: string[];
  recorder_employee_id: string;
  minimum_workers: number;
  maximum_workers: number | null;
  expected_crew_id: string | null;
  reason: string;
  rework_ncr_id?: string | null;
}
export interface AssignedWorkOrder {
  batch_number?: string | null;
  report_interval?: {
    started_at: string | null;
    finished_at: string | null;
    blocker: string | null;
  };
  qc_workflow?: 'finish_first_v1' | string | null;
  produced_quantity?: number;
  qc_accepted_quantity?: number;
  qc_defective_quantity?: number;
  awaiting_qc_quantity?: number;
  quality_state?: string | null;
  historical_report_only?: boolean;
  can_report_late_production?: boolean;
  late_production_reports?: LateProductionReport[];
  startup_trial?: StartupTrial | null;
  can_record_trial?: boolean;
  warehouse_handover?: { total: number; handed_over: number; pending: number };
  warehouse_handover_pending?: number;
  id: string;
  wo_number: string;
  status: string;
  product: { part_number: string; name: string; unit_of_measure: string };
  machine: { code: string; name: string } | null;
  mold: { code: string; name: string } | null;
  quantity_target: number;
  quantity_good: number;
  quantity_rejected: number;
  remaining_good: number;
  remaining_produced?: number;
  crew: Crew;
  can_record: boolean;
  blocker: { code: string; message: string; owner: string } | null;
  repair: { ncr_id: string; remaining_quantity: number; started: boolean } | null;
}

export interface HistoricalWorkOrderEntry {
  work_order: {
    id: string;
    wo_number: string;
    status: string;
    actual_start: string | null;
    actual_end: string | null;
    qc_workflow?: 'finish_first_v1' | string | null;
  };
  crew: Crew;
  can_report_late_production: boolean;
  late_production_reports: LateProductionReport[];
  late_production_report_count?: number;
}
export interface OutputReceipt {
  held_quantity?: number;
  production_started_at?: string | null;
  production_finished_at?: string | null;
  trial_inspection_id?: string | null;
  trial_quantity?: number | null;
  trial_settled_at?: string | null;
  material_handover_exception?: MaterialHandoverException | null;
  id: string;
  batch_code: string;
  good_count: number;
  reject_count: number;
  recorded_at: string;
  receipt_status: string;
}
interface WorkerOutputBase {
  crew_id: string;
  remarks?: string;
}

export type WorkerOutputData = WorkerOutputBase &
  (
    | { produced_count: number; good_count?: never; reject_count?: never; defects?: never }
    | {
        produced_count?: never;
        good_count: number;
        reject_count: number;
        defects: { defect_type_id: string; count: number }[];
      }
  );

export interface AssignmentSuggestion {
  preferred_production_date?: string | null;
  preferred_day_message?: string;
  readiness_blockers?: { code: string; message: string; owner: string }[];
  schedule: {
    id: string;
    status?: string;
    scheduled_start: string;
    scheduled_end: string;
    updated_at: string;
    machine_id: string | null;
    mold_id: string | null;
    machine?: { machine_code: string; name: string } | null;
    mold?: { mold_code: string; name: string } | null;
  } | null;
  members: EmployeeOption[];
  recorder_employee_id: string | null;
  minimum_workers: number;
  maximum_workers: number | null;
  expected_crew_id: string | null;
  blockers: string[];
  eligible_workers?: EmployeeOption[];
  worker_options?: EmployeeOption[];
  staffing_blockers?: string[];
  equipment_blockers?: string[];
}
export interface StaffingLimits {
  minimum_workers: number;
  maximum_workers: number | null;
}
export interface ExecutionReadiness {
  can_assign_crew?: boolean;
  start_mode?: 'scheduled' | 'early' | 'late' | 'extend' | null;
  estimated_finish?: string | null;
  requires_late_production_approval?: boolean;
  requires_late_start_acknowledgement?: boolean;
  execution_start?: string | null;
  execution_end?: string | null;
  production_deadline?: string | null;
  can_approve_late_production?: boolean;
  ready: boolean;
  needs_reschedule: boolean;
  can_review_slot: boolean;
  blockers: { code: string; message: string }[];
  presence: NonNullable<CrewContext['presence']>;
  expected_crew_id: string | null;
  schedule: AssignmentSuggestion['schedule'];
}
export interface RescheduleSuggestion {
  repair_quantity?: number;
  machine_id?: string | null;
  mold_id?: string | null;
  production_deadline?: string | null;
  requires_late_production_approval?: boolean;
  preferred_day_message?: string;
  scheduled_start: string | null;
  scheduled_end: string | null;
  expected_schedule_id: string | null;
  expected_schedule_updated_at: string | null;
  expected_crew_id: string | null;
  members: EmployeeOption[];
  recorder_employee_id: string | null;
  machine?: { machine_code: string; name: string } | null;
  mold?: { mold_code: string; name: string } | null;
  blockers: string[];
}
export interface ApplyAssignmentData extends AssignCrewData {
  preferred_production_date?: string | null;
  schedule_id: string;
  schedule_updated_at: string;
}
