// Sprint 7 — Quality types. IDs are hash strings; decimals come back as strings.

export type InspectionParameterType = 'dimensional' | 'visual' | 'functional';

export interface InspectionSpecItem {
  id: string;
  parameter_name: string;
  parameter_type: InspectionParameterType;
  unit_of_measure: string | null;
  nominal_value: string | null;
  tolerance_min: string | null;
  tolerance_max: string | null;
  is_critical: boolean;
  sort_order: number;
  notes: string | null;
}

export interface InspectionSpec {
  id: string;
  version: number;
  is_active: boolean;
  notes: string | null;
  item_count: number;
  product?: { id: string; part_number: string; name: string } | null;
  creator?: { id: string; name: string } | null;
  items?: InspectionSpecItem[];
  created_at: string;
  updated_at: string;
}

// ─── Sprint 7 Task 60 — Inspections ───────────────────────────────────

export type InspectionStage = 'incoming' | 'in_process' | 'outgoing';
export type InspectionStatus = 'draft' | 'in_progress' | 'passed' | 'failed' | 'cancelled';
export type InspectionEntityType = 'grn' | 'work_order' | 'delivery';

export interface InspectionMeasurement {
  id: string;
  sample_index: number;
  parameter_name: string;
  parameter_type: InspectionParameterType;
  unit_of_measure: string | null;
  nominal_value: number | null;
  tolerance_min: number | null;
  tolerance_max: number | null;
  measured_value: number | null;
  is_critical: boolean;
  is_pass: boolean | null;
  notes: string | null;
}

export interface Inspection {
  id: string;
  inspection_number: string;
  stage: InspectionStage;
  status: InspectionStatus;
  entity_type: InspectionEntityType | null;
  entity_hash_id: string | null;
  batch_quantity: number;
  sample_size: number;
  aql_code: string | null;
  accept_count: number;
  reject_count: number;
  defect_count: number;
  started_at: string | null;
  completed_at: string | null;
  notes: string | null;
  product?: { id: string; part_number: string; name: string } | null;
  inspector?: { id: string; name: string } | null;
  spec?: { id: string; version: number; is_active: boolean } | null;
  measurements?: InspectionMeasurement[];
  created_at: string;
  updated_at: string;
}

export interface CreateInspectionData {
  stage: InspectionStage;
  product_id: string;
  batch_quantity: number;
  entity_type?: InspectionEntityType | null;
  entity_id?: string | null;
  notes?: string;
}

export interface RecordMeasurementsData {
  measurements: Array<{
    id: string;
    measured_value?: number | null;
    is_pass?: boolean | null;
    notes?: string | null;
  }>;
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

export interface NcrAction {
  id: string;
  action_type: NcrActionType;
  description: string;
  performed_at: string | null;
  performer?: { id: string; name: string } | null;
}

export interface Ncr {
  id: string;
  ncr_number: string;
  source: NcrSource;
  severity: NcrSeverity;
  status: NcrStatus;
  disposition: NcrDisposition | null;
  defect_description: string;
  affected_quantity: number;
  root_cause: string | null;
  corrective_action: string | null;
  closed_at: string | null;
  product?: { id: string; part_number: string; name: string } | null;
  inspection?: { id: string; inspection_number: string; stage: string; status: string } | null;
  creator?: { id: string; name: string } | null;
  assignee?: { id: string; name: string } | null;
  closer?: { id: string; name: string } | null;
  replacement_work_order?: { id: string; wo_number: string; status: string; quantity_target: number } | null;
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

export interface UpsertInspectionSpecData {
  product_id: string;
  notes?: string;
  items: Array<{
    parameter_name: string;
    parameter_type: InspectionParameterType;
    unit_of_measure?: string;
    nominal_value?: string;
    tolerance_min?: string;
    tolerance_max?: string;
    is_critical?: boolean;
    sort_order?: number;
    notes?: string;
  }>;
}
