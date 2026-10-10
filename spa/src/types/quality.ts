// Sprint 7 — Quality types. IDs are hash strings; decimals come back as strings.
export type { WorkOrderFinalInspection, FinalInspectionEvidence, FinalInspectionManifest, FinalInspectionSubmission } from './quality-final';

export type InspectionParameterType = 'dimensional' | 'visual' | 'functional';

export interface InspectionSpecItem {
  id: string;
  acceptance_criterion?: string | null;
  parameter_name: string;
  parameter_type: InspectionParameterType | null;
  parameter_type_label?: string;
  unit_of_measure: string | null;
  nominal_value: string | null;
  tolerance_min: string | null;
  tolerance_max: string | null;
  is_critical: boolean;
  sort_order: number;
  notes: string | null;
  deleted_at?: string | null;
}

export interface InspectionSpecRevision {
  capture_method?: 'measurement' | 'checklist';
  id: string;
  version: number;
  is_current?: boolean;
  notes: string | null;
  creator?: { id: string; name: string } | null;
  items: InspectionSpecItem[];
  created_at: string | null;
  updated_at: string | null;
}

export interface InspectionSpec {
  capture_method?: 'measurement' | 'checklist';
  id: string;
  version: number;
  is_active: boolean;
  notes: string | null;
  item_count: number;
  product?: {
    id: string;
    part_number: string;
    name: string;
    is_active?: boolean;
    deleted_at?: string | null;
  } | null;
  creator?: { id: string; name: string } | null;
  items?: InspectionSpecItem[];
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
  current_revision?: {
    id: string;
    version: number;
    notes: string | null;
    created_at: string | null;
    creator?: { id: string; name: string } | null;
  } | null;
  revisions?: InspectionSpecRevision[];
}

// ─── Sprint 7 Task 60 — Inspections ───────────────────────────────────

export type InspectionStage =
  | 'incoming'
  | 'in_process'
  | 'outgoing'
  | 'supplier_return'
  | 'customer_return';
export type InspectionStatus =
  | 'draft'
  | 'in_progress'
  | 'awaiting_review'
  | 'passed'
  | 'failed'
  | 'cancelled';
export type InspectionEntityType = 'grn' | 'work_order' | 'delivery' | 'return_request';
export type InspectionMode = 'per_unit' | 'lot_checklist';

export interface InspectionMeasurement {
  checklist_key?: string;
  item_result?: 'passed' | 'failed' | null;
  item_problem_description?: string | null;
  id: string;
  sample_index: number;
  acceptance_criterion?: string | null;
  parameter_name: string;
  parameter_type: InspectionParameterType | null;
  parameter_type_label?: string;
  evaluation_mode?: 'numeric' | 'manual';
  unit_of_measure: string | null;
  nominal_value: string | number | null;
  tolerance_min: string | number | null;
  tolerance_max: string | number | null;
  measured_value: string | number | null;
  is_critical: boolean;
  is_pass: boolean | null;
  notes: string | null;
}

export interface CountedInspectionSubmission {
  expected_result_version: number; coverage_confirmed: boolean; inspected_quantity: number; sample_defect_count?: number; physical_defects_confirmed?: boolean;
  problem_description?: string; requirement_problem?: boolean;
  measurements: Array<{ id: string; measured_value: string }>;
}

export interface Inspection {
  final_work_order_id?: string | null;
  work_order_quality?: {
    state: 'checking' | 'awaiting_review' | 'failed' | 'released';
    produced_quantity: number;
    defective_quantity: number;
    accepted_quantity: number;
    repaired_accepted_quantity: number;
  } | null;
  defective_quantity?: number;
  described_defective_quantity?: number;
  missing_readings?: number;
  historical_held?: boolean;
  counted_capture?: {
    version: 1 | 2 | 3;
    required_quantity: number;
    result_policy: 'zero_defects';
    dimensional_quantity?: number;
    workflow?: 'finish_first_v1';
    evidence_method?: 'confirmed_checks';
  } | null;
  coverage_confirmed_at?: string | null;
  checks_confirmed_at?: string | null;
  startup_trial_required?: boolean;
  startup_trial?: { required_quantity: number; recorded_quantity: number; settled: boolean; captured_at: string | null; output_id: string | null } | null;
  ncr?: { id: string; ncr_number: string; status: string; status_label: string; disposition: string | null; disposition_label: string | null; href: string | null } | null;
  incoming_acceptance?: { grn_item_id: string; grn_id: string; grn_number: string; quantity_received: string; quantity_accepted: string; unit: string; status: string; status_label: string } | null;
  can_replace_checklist?: boolean;
  replaces_inspection_id?: string | null;
  result_version?: number;
  source_quantity?: string | null;
  source_uom_code?: string | null;
  sampling_basis?: 'piece' | 'lot' | null;
  capture_method?: 'measurement' | 'checklist';
  count_only_incoming?: boolean;
  can_manage?: boolean;
  id: string;
  inspection_number: string;
  stage: InspectionStage;
  stage_label?: string;
  status: InspectionStatus;
  status_label?: string;
  proposed_result?: 'passed' | 'failed' | null;
  recorded_result?: 'passed' | 'failed' | null;
  problem_description?: string | null;
  affected_quantity?: number | null;
  sorting_approved_at?: string | null;
  /** Server-computed: awaiting review, holds the review permission, and is not the inspector. */
  can_review?: boolean;
  reviewed_at?: string | null;
  coc_unavailable_reason?: string | null;
  review_remarks?: string | null;
  entity_type: InspectionEntityType | null;
  entity_hash_id: string | null;
  entity_context?: {
    id: string;
    type: InspectionEntityType;
    reference: string | null;
    status: string;
    status_label?: string | null;
    href: string | null;
  } | null;
  batch_quantity: number;
  accepted_quantity: number;
  sample_size: number;
  aql_code: string | null;
  accept_count: number;
  reject_count: number;
  defect_count: number;
  inspection_mode?: InspectionMode;
  sample_defect_count?: number | null;
  inspected_quantity?: number | null;
  critical_defect?: boolean | null;
  defects_removed_confirmed?: boolean | null;
  started_at: string | null;
  completed_at: string | null;
  notes: string | null;
  product?: { id: string; part_number: string; name: string } | null;
  item?: { id: string; code: string; name: string } | null;
  inspector?: { id: string; name: string } | null;
  result_authors?: Array<{ id: string; name: string }>;
  reviewer?: { id: string; name: string } | null;
  work_order_output?: {
    id: string;
    batch_code: string | null;
    good_count: number;
    work_order?: { id: string; wo_number: string } | null;
  } | null;
  quality_plan?: {
    id: string;
    version: number;
    sampling_method: string;
  } | null;
  spec?: {
    id: string;
    version: number | null;
    is_active: boolean;
    revision_id?: string | null;
    revision_status?: 'pinned' | 'legacy_unknown' | string;
    revision_notes?: string | null;
  } | null;
  spec_revision?: InspectionSpecRevision | null;
  measurements?: InspectionMeasurement[];
  created_at: string;
  updated_at: string;
}

export interface CreateInspectionData {
  stage: InspectionStage;
  product_id: string;
  batch_quantity: number;
  work_order_output_id?: string | null;
  entity_type?: InspectionEntityType | null;
  entity_id?: string | null;
  notes?: string;
}

export interface WorkOrderOutputOption {
  id: string;
  batch_code: string | null;
  good_count: number;
  recorded_at: string | null;
  work_order: { id: string; wo_number: string } | null;
}

export interface RecordInspectionItemResultsData {
  expected_result_version?: number;
  items: Array<{ id: string; result: 'passed' | 'failed'; problem_description?: string; measured_value?: string }>;
  submit: boolean;
}

export interface RecordInspectionMeasurementsData {
  measurements: Array<{ id: string; measured_value: string | number | null }>;
}

export interface AqlPlan {
  code: string;
  sample_size: number;
  accept: number;
  reject: number;
}

// ─── Sprint 7 Task 61 — NCR ────────────────────────────────────────────

export type NcrSource = 'inspection_fail' | 'customer_complaint';
export type NcrSeverity = 'low' | 'medium' | 'high' | 'critical';
export type NcrStatus = 'open' | 'in_progress' | 'closed' | 'cancelled';
export type NcrDisposition = 'scrap' | 'rework' | 'use_as_is' | 'return_to_supplier';
export type NcrActionType = 'containment' | 'corrective' | 'preventive';
export type EffectivenessStatus =
  | 'pending_verification'
  | 'effective'
  | 'ineffective'
  | 'not_applicable';

export interface NcrAction {
  id: string;
  action_type: NcrActionType;
  action_type_label?: string;
  description: string;
  performed_at: string | null;
  performer?: { id: string; name: string } | null;
  owner?: { id: string; name: string } | null;
  due_date: string | null;
  effectiveness_status: EffectivenessStatus | null;
  effectiveness_status_label?: string | null;
  effectiveness_notes: string | null;
  effectiveness_check_count: number;
  next_effectiveness_check_at: string | null;
  verified_at: string | null;
  verifier?: { id: string; name: string } | null;
  ncr?: { id: string; ncr_number: string } | null;
}

export interface Ncr {
  full_piece_inspection?: boolean;
  startup_trial?: boolean;
  historical_held?: boolean;
  process_recovery_quantity?: number | null;
  id: string;
  ncr_number: string;
  source: NcrSource;
  source_label?: string;
  severity: NcrSeverity;
  severity_label?: string;
  status: NcrStatus;
  status_label?: string;
  disposition: NcrDisposition | null;
  disposition_label?: string;
  defect_description: string;
  affected_quantity: number;
  is_auto_generated: boolean;
  is_incoming_mrb?: boolean;
  is_outgoing_recovery?: boolean;
  is_process_recovery?: boolean;
  late_incoming?: boolean;
  late_hold_quantity?: string | null;
  concession_approval_reference?: string | null;
  material_review_records?: Array<{
    id: string;
    mrb_number: string;
    status: string;
    quantity: string | number;
  }>;
  sorting_required_quantity?: string | number | null;
  sorting_inspected_quantity?: string | null;
  sorting_evidence_reference?: string | null;
  sorting_approved_at?: string | null;
  mrb_accepted_quantity?: string | null;
  mrb_decided_at?: string | null;
  mrb_decider?: { id: string; name: string } | null;
  root_cause: string | null;
  corrective_action: string | null;
  closed_at: string | null;
  product?: { id: string; part_number: string; name: string } | null;
  inspection?: {
    id: string;
    inspection_number: string;
    batch_quantity?: number;
    source_quantity?: string | null;
    source_uom_code?: string | null;
    sampling_basis?: 'piece' | 'lot' | null;
    accepted_quantity?: number;
    sample_defect_count?: number | null;
    inspected_quantity?: number | null;
    inspector?: { id: string; name: string } | null;
    stage: string;
    stage_label?: string;
    status: string;
    status_label?: string;
  } | null;
  creator?: { id: string; name: string } | null;
  assignee?: { id: string; name: string } | null;
  closer?: { id: string; name: string } | null;
  is_inline_rework?: boolean;
  confirmed_rework_quantity?: number | null;
  rework_started_at?: string | null;
  rework_finished_at?: string | null;
  rework_work_order?: {
    id: string;
    wo_number: string;
    status: string;
    status_label?: string;
    quantity_target: number;
  } | null;
  replacement_work_order?: {
    id: string;
    wo_number: string;
    status: string;
    status_label?: string;
    quantity_target: number;
  } | null;
  recurrence_of_ncr?: { id: string; ncr_number: string } | null;
  effectiveness_status: EffectivenessStatus | null;
  effectiveness_status_label?: string | null;
  effectiveness_closed_at: string | null;
  actions?: NcrAction[];
  created_at: string;
  updated_at: string;
}

export interface CreateNcrData {
  source: NcrSource;
  severity: NcrSeverity;
  product_id?: string | null;
  inspection_id?: string | null;
  defect_description: string;
  affected_quantity?: number;
  assigned_to?: string | null;
}

export interface SetNcrDispositionData {
  disposition: NcrDisposition;
  root_cause?: string;
  corrective_action?: string;
  mrb_accepted_quantity?: string;
  sorting_evidence?: {
    defects_segregated?: boolean;
    trial_scrapped_quantity?: number;
    trial_defects?: Array<{ defect_type_id: string; count: number }>;
    concession_approval_reference?: string;
    inspected_quantity: string;
    evidence_reference: string;
    performed_by: string;
    recovery_passed: boolean;
  };
}

// ─── Sprint 7 Task 63 — Defect Pareto ──────────────────────────────────

export interface ParetoRow {
  parameter_name: string;
  defect_count: number;
  percentage: number;
  cumulative_percentage: number;
  is_critical: boolean;
}

export interface ParetoResult {
  from: string;
  to: string;
  total_defects: number;
  rows: ParetoRow[];
}

// ─── OGAMI-016 — Calibration register ────────────────────────────────

export type CalibrationStatus = 'active' | 'due' | 'overdue' | 'retired';

export interface CalibrationRecord {
  id: string;
  equipment_code: string;
  name: string;
  location: string | null;
  last_calibration_date: string | null;
  next_calibration_date: string | null;
  frequency_days: number;
  status: CalibrationStatus;
  status_label?: string;
  responsible: string | null;
  remarks: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface CalibrationRecordData {
  equipment_code: string;
  name: string;
  location?: string;
  last_calibration_date?: string;
  next_calibration_date?: string;
  frequency_days: number;
  status?: CalibrationStatus;
  responsible?: string;
  remarks?: string;
}

// ─── ADV7 — NCR Templates ────────────────────────────────────────────

export interface NcrTemplate {
  id: string;
  name: string;
  source: NcrSource;
  source_label?: string;
  severity: NcrSeverity;
  severity_label?: string;
  defect_description: string | null;
  notes: string | null;
  is_active: boolean;
  product?: { id: string; part_number: string; name: string } | null;
  creator?: { id: string; name: string } | null;
  created_at: string;
  updated_at: string;
}

export interface CreateNcrTemplateData {
  name: string;
  source: NcrSource;
  severity: NcrSeverity;
  product_id?: string | null;
  defect_description?: string;
  notes?: string;
}

export interface UpsertInspectionSpecData {
  capture_method?: 'measurement' | 'checklist';
  product_id: string;
  notes?: string;
  items: Array<{
    acceptance_criterion?: string | null;
    parameter_name: string;
    parameter_type: InspectionParameterType | null;
    unit_of_measure?: string;
    nominal_value?: string;
    tolerance_min?: string;
    tolerance_max?: string;
    is_critical?: boolean;
    sort_order?: number;
    notes?: string;
  }>;
}

export interface RecordInspectionBatchResultData {
  result: 'passed' | 'failed';
  coverage_confirmed: true;
  inspected_quantity: number;
  sample_defect_count: number;
  problem_description?: string;
  critical_defect: boolean;
  defects_removed_confirmed: boolean;
}

export interface ChecklistSubmission {
  expected_result_version: number;
  items: Array<{ id: string; result: 'passed' | 'failed'; problem_description?: string; measured_value?: string }>;
  coverage_confirmed: true;
  inspected_quantity?: number;
  physical_defects_confirmed?: boolean;
  sample_defect_count?: number;
  defects_removed_confirmed?: boolean;
  problem_description?: string;
}
