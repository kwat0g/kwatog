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
  entity_id: number | null;
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
