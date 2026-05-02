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
