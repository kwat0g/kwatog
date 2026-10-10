// Sprint 6 — Production types.

export type WorkOrderStatus =
  | 'planned'
  | 'confirmed'
  | 'in_progress'
  | 'paused'
  | 'completed'
  | 'closed'
  | 'cancelled';

export type MachineDowntimeCategory =
  | 'breakdown'
  | 'changeover'
  | 'material_shortage'
  | 'no_order'
  | 'planned_maintenance';

export interface MachineDowntimeCategoryOption {
  value: MachineDowntimeCategory;
  label: string;
  is_planned: boolean;
}

/** Material usage values are string decimals and shared by live WOs/output snapshots. */
export interface WorkOrderMaterialUsageBreakdown {
  item_id?: string | null;
  bom_quantity?: string;
  actual_quantity_issued?: string;
  actual_cost?: string;
  auto_quantity_issued?: string;
  auto_actual_cost?: string;
  auto_gross_quantity_issued?: string;
  auto_gross_actual_cost?: string;
  auto_returned_quantity?: string;
  auto_returned_cost?: string;
  manual_quantity_issued?: string;
  manual_actual_cost?: string;
  manual_gross_quantity_issued?: string;
  manual_gross_actual_cost?: string;
  manual_returned_quantity?: string;
  manual_returned_cost?: string;
  returned_quantity?: string;
  returned_cost?: string;
}

export interface WorkOrderMaterial extends WorkOrderMaterialUsageBreakdown {
  id: string;
  item: { id: string; code: string; name: string; unit_of_measure: string } | null;
  bom_quantity: string;
  standard_unit_cost: string;
  standard_cost: string;
  actual_quantity_issued: string;
  actual_cost: string;
  cost_variance: string;
  variance: string;
}

export interface WorkOrderDefectRow {
  id: string;
  count: number;
  defect_type: { id: string; code: string; name: string } | null;
}

export type LateProductionReportStatus = 'pending' | 'approved' | 'rejected' | 'correction';

export interface LateProductionReport {
  produced_count?: number;
  id: string;
  work_order_id: string;
  wo_number: string;
  work_order_status: WorkOrderStatus;
  crew_id: string;
  source_reference: string;
  production_started_at: string;
  production_finished_at: string;
  good_count: number;
  reject_count: number;
  quantity_scope: 'additional_unreported_physical_pieces';
  quantity_warning: string;
  defects: Array<{ defect_type_id: string; count: number }>;
  reason: string;
  crew_snapshot: {
    crew_id: string;
    recorder_employee_id: string;
    assigned_at: string;
    ended_at: string | null;
    members: Array<{ employee_id: string; employee_number: string | null; name: string | null }>;
  };
  evidence_summary: {
    crew: {
      state: 'verified' | 'missing' | 'conflicting';
      assigned_at: string;
      ended_at: string | null;
    };
    attendance: {
      state: 'verified' | 'missing' | 'conflicting';
      members: Array<{
        name: string;
        state: string;
        shift?: { starts_at: string; ends_at: string };
      }>;
    };
    execution: { state: 'verified' | 'missing' | 'conflicting'; source: string };
    resources: {
      state: 'verified' | 'missing' | 'conflicting';
      source: string | null;
      machine_id?: string | null;
      mold_id?: string | null;
      machine_code?: string | null;
      mold_code?: string | null;
    };
  };
  status: LateProductionReportStatus;
  recorded_by: string;
  recorded_by_name: string | null;
  recorded_at: string;
  reviewed_by: string | null;
  reviewer_name: string | null;
  reviewed_at: string | null;
  review_reason: string | null;
  work_order_output_id: string | null;
  output_summary: {
    physical_count: number;
    good_count: number;
    reject_count: number;
    held_quantity: number;
  } | null;
  can_approve: boolean;
  owner: string | null;
}

interface LateProductionReportBase {
  crew_id: string;
  source_reference: string;
  production_started_at: string;
  production_finished_at: string;
  reason: string;
}

export type LateProductionReportSubmission = LateProductionReportBase &
  (
    | { produced_count: number; good_count?: never; reject_count?: never; defects?: never }
    | {
        produced_count?: never;
        good_count: number;
        reject_count: number;
        defects: Array<{ defect_type_id: string; count: number }>;
      }
  );

export type ProductionReceiptHandoffStatus =
  | 'not_started'
  | 'generated'
  | 'manual_required'
  | 'not_required';

export interface ProductionReceiptHandoff {
  status: ProductionReceiptHandoffStatus;
  status_label: string | null;
  message: string | null;
  at: string | null;
  movement_id?: string | null;
}

export type GeneralLedgerHandoffStatus = 'generated' | 'manual_required' | 'not_required';

export interface GeneralLedgerHandoff {
  status: GeneralLedgerHandoffStatus;
  status_label: string | null;
  message: string | null;
  at: string | null;
  movement_id: string;
}

export interface MaterialHandoverException {
  manager_name?: string;
  manager_id: string;
  recorded_at: string;
  reason: string;
  sources: Array<{ movement_id: string; remaining_quantity: string }>;
}

export interface StartupTrial {
  inspection_id: string;
  sample_size: number;
  recorded_quantity: number;
  settled: boolean;
  state: 'awaiting_samples' | 'awaiting_qc' | 'failed' | 'released';
  captured_at: string | null;
  output_id: string | null;
}

export interface RecordTrialData {
  trial_quantity: number;
  crew_id: string;
  recording_reason?: string;
  material_handover_acknowledged?: boolean;
  material_handover_reason?: string;
}

export interface WorkOrderOutput {
  held_quantity?: number;
  production_started_at?: string | null;
  production_finished_at?: string | null;
  trial_inspection_id?: string | null;
  trial_quantity?: number | null;
  trial_settled_at?: string | null;
  material_handover_exception?: MaterialHandoverException | null;
  crew_snapshot?: {
    members: Array<{ id: string; name: string }>;
    recorder_employee_id: string;
  } | null;
  recording_reason?: string | null;
  is_rework?: boolean;
  id: string;
  recorded_at: string;
  good_count: number;
  reject_count: number;
  total_count: number;
  shift: string | null;
  batch_code: string | null;
  remarks: string | null;
  production_receipt_handoff?: ProductionReceiptHandoff;
  general_ledger_handoff?: GeneralLedgerHandoff | null;
  material_lineage?: {
    work_order_class?: string | null;
    exception_reason?: string | null;
    authorized_by?: string | null;
    material_plan_source?: string | null;
    materials?: WorkOrderMaterialUsageBreakdown[];
  } | null;
  recorder?: { id: string; name: string } | null;
  defects?: WorkOrderDefectRow[];
}

/** ADV3 — IATF 16949 material lot reference snapshot per work order. */
export interface WorkOrderMaterialLotReference {
  item_id: string | null;
  item_code: string | null;
  item_name: string | null;
  grn_number: string | null;
  material_lot_number: string | null;
  supplier_lot_reference: string | null;
  quantity_used: string | null;
}

export interface WorkOrder {
  production_purpose?: 'stock' | 'order' | 'internal' | null;
  qc_workflow?: 'finish_first_v1' | string | null;
  produced_quantity?: number;
  qc_accepted_quantity?: number;
  qc_defective_quantity?: number;
  awaiting_qc_quantity?: number;
  quality_state?: string | null;
  startup_trial?: StartupTrial | null;
  execution_history?: Array<{
    id: string;
    summary: string;
    created_at: string;
    actor: { name: string } | null;
    detail: {
      mode?: string;
      approved_start?: string;
      approved_end?: string;
      scheduled_start?: string;
      scheduled_end?: string;
      late_start_authorized?: boolean;
      reason?: string;
      late_production_reason?: string;
    };
  }>;
  staffing_warning?: string[];
  schedule_status?: {
    kind:
      | 'awaiting_confirmation'
      | 'proposal_expired'
      | 'start_available'
      | 'start_overdue'
      | 'schedule_passed';
    message: string;
    scheduled_start?: string;
    scheduled_end: string;
  } | null;
  schedule_warning?: {
    kind?: 'proposal_expired' | 'start_overdue' | 'schedule_passed';
    message: string;
    scheduled_start?: string;
    scheduled_end: string;
  } | null;
  warehouse_handover_pending?: number;
  warehouse_handover?: { total: number; handed_over: number; pending: number };
  production_released_at?: string | null;
  bom_id?: string | null;
  bom_version?: number | null;
  unit_standard_cost?: string | null;
  cost_source?: string | null;
  cost_snapshot_at?: string | null;
  short_completion_reason?: string | null;
  quality_summary?: {
    approved_quantity: number;
    rejected_quantity: number;
    open_defects: number;
    missing_process_evidence: boolean;
    recovery_work_orders: Array<{
      id: string;
      wo_number: string;
      status: string;
      quantity_target: number;
    }>;
    replacement_pending: boolean;
  };
  production_completion_blocker?: string | null;
  quality_close_blocker?: string | null;
  process_quality_blocker?: string | null;
  rework?: WorkOrderRework[];
  rework_status?: { state: 'required' | 'in_progress' | 'awaiting_qc'; quantity: number } | null;
  id: string;
  wo_number: string;
  /** ADV3 — Production batch number (BATCH-YYYYMM-NNNN). Set when the WO is started. */
  batch_number: string | null;
  /** ADV3 — Snapshot of the supplier lots used by this batch. */
  material_lot_references: WorkOrderMaterialLotReference[];
  work_order_class: 'standard' | 'service' | 'non_stock' | 'prototype' | string;
  exception_reason: string | null;
  exception_authorized_by: string | null;
  material_plan_source: string | null;
  product?: { id: string; part_number: string; name: string };
  parent?: { id: string; wo_number: string } | null;
  children?: Array<{
    id: string;
    wo_number: string;
    product_id: string | null;
    quantity_target: number;
    quantity_good: number;
    status: WorkOrderStatus;
  }>;
  production_readiness?: {
    ready: boolean;
    blocking_work_orders: Array<{
      id: string;
      wo_number: string;
      status: WorkOrderStatus;
      quantity_target: number;
      quantity_good: number;
    }>;
  };
  sales_order?: { id: string; so_number: string } | null;
  sales_order_line?: {
    requested_delivery_date: string | null;
    delivery_date: string | null;
  } | null;
  machine?: { id: string; machine_code: string; name: string } | null;
  mold?: { id: string; mold_code: string; name: string } | null;
  quantity_target: number;
  quantity_produced: number;
  quantity_good: number;
  quantity_rejected: number;
  progress_percentage: number;
  scrap_rate: string;
  preferred_production_date?: string | null;
  mrp_generated?: boolean;
  planned_start: string;
  planned_end: string;
  actual_start: string | null;
  actual_end: string | null;
  status: WorkOrderStatus;
  status_label: string;
  next_statuses?: Array<{ value: string; label: string }>;
  pause_reason: string | null;
  priority: number;
  creator?: { id: string; name: string } | null;
  materials?: WorkOrderMaterial[];
  material_cost_summary?: {
    standard_cost: string;
    actual_cost: string;
    cost_variance: string;
  } | null;
  outputs?: WorkOrderOutput[];
  inspections?: Array<{
    id: string;
    inspection_number: string;
    stage: string;
    stage_label?: string;
    status: string;
    status_label?: string;
    completed_at: string | null;
  }>;
  created_at: string;
  updated_at: string;
}

export interface WorkOrderRework {
  id: string;
  ncr_number: string;
  defect_description: string;
  status: string;
  quantity: number;
  recorded_quantity: number;
  remaining_quantity: number;
  started_at: string | null;
  started_by: { id: string; name: string } | null;
  finished_at: string | null;
  finished_by: { id: string; name: string } | null;
  inspections: Array<{ id: string; inspection_number: string; status: string }>;
}

export interface DefectType {
  id: string;
  code: string;
  name: string;
  description: string | null;
  is_active: boolean;
}

export interface CreateWorkOrderData {
  production_purpose?: 'stock' | 'order' | 'internal';
  request_key?: string;
  preferred_production_date?: string;
  minimum_workers?: number;
  maximum_workers?: number;
  product_id: string;
  sales_order_id?: string;
  machine_id?: string;
  mold_id?: string;
  quantity_target: number;
  planned_start: string;
  planned_end: string;
  priority?: number;
  work_order_class?: 'standard' | 'service' | 'non_stock' | 'prototype';
  exception_reason?: string;
}

interface RecordOutputBase {
  material_handover_acknowledged?: boolean;
  material_handover_reason?: string;
  crew_id?: string;
  recording_reason?: string;
  shift?: string;
  remarks?: string;
}

export type RecordOutputData = RecordOutputBase &
  (
    | { produced_count: number; good_count?: never; reject_count?: never; defects?: never }
    | {
        produced_count?: never;
        good_count: number;
        reject_count: number;
        defects?: { defect_type_id: string; count: number }[];
      }
  );

export interface OeeResult {
  availability: number | null;
  performance: number | null;
  quality: number | null;
  oee: number | null;
  diagnostics: {
    scheduled_minutes: number;
    planned_downtime: number;
    unplanned_downtime: number;
    available_time: number;
    run_time: number;
    good_count: number;
    reject_count: number;
    ideal_cycle_seconds: number;
    performance_capped: boolean;
  };
  period_from: string;
  period_to: string;
}

export interface MachineOeeRow extends OeeResult {
  machine_id: string;
  machine_code: string;
  name: string;
  tonnage: number | null;
  status: string;
  status_label?: string;
}

/**
 * Sprint P10 — full OEE report payload.
 * Matches `OeeService::report()` output 1:1.
 */
export interface OeeReport {
  benchmark_pct?: number;
  display_policy?: { world_class_ratio: number; on_track_ratio: number };
  range: { from: string; to: string };
  overall: {
    availability: number | null;
    performance: number | null;
    quality: number | null;
    oee: number | null;
  };
  machines: MachineOeeRow[];
  trend: Array<{ date: string; oee: number | null }>;
  downtime_breakdown: Array<{ category: string; minutes: number }>;
}

export interface ProductionDashboardPayload {
  display_policy?: { world_class_ratio: number; on_track_ratio: number };
  defect_history_days: number;
  kpis: {
    today_output_total: number;
    today_output_good: number;
    today_output_reject: number;
    active_work_orders: number;
    machines_total: number;
    machines_running: number;
    machines_idle: number;
    machines_breakdown: number;
    avg_oee_today: number | null;
  };
  chain_stage_breakdown: Array<{ label: string; count: number; percent: number; color: string }>;
  machine_utilization: MachineOeeRow[];
  alerts: Array<{
    type: string;
    type_label?: string;
    severity: string;
    message: string;
    link: string;
  }>;
  defect_pareto: Array<{
    defect_code: string;
    defect_name: string;
    count: number;
    percent: number;
  }>;
  generated_at: string;
}

export interface ChainStep {
  key: string;
  label: string;
  date: string | null;
  state: 'done' | 'active' | 'pending';
}
