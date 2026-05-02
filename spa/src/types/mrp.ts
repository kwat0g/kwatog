// Sprint 6 — MRP types. IDs are hash strings; decimals come back as strings.

export type MachineStatus = 'running' | 'idle' | 'maintenance' | 'breakdown' | 'offline';
export type MoldStatus = 'available' | 'in_use' | 'maintenance' | 'retired';
export type MrpPlanStatus = 'active' | 'superseded' | 'cancelled';

export interface BomItem {
  id: string;
  item: { id: string; code: string; name: string; unit_of_measure: string; item_type: string } | null;
  quantity_per_unit: string;
  unit: string;
  waste_factor: string;
  effective_quantity: string;
  sort_order: number;
}

export interface Bom {
  id: string;
  product?: { id: string; part_number: string; name: string; unit_of_measure: string };
  version: number;
  is_active: boolean;
  item_count: number;
  items?: BomItem[];
  created_at: string;
  updated_at: string;
}

export interface Machine {
  id: string;
  machine_code: string;
  name: string;
  tonnage: number | null;
  machine_type: string;
  operators_required: string;
  available_hours_per_day: string;
  status: MachineStatus;
  status_label: string;
  is_available_now: boolean;
  compatible_molds_count: number;
  compatible_molds?: Array<{ id: string; mold_code: string; name: string }>;
  created_at: string;
  updated_at: string;
}

export interface Mold {
  id: string;
  mold_code: string;
  name: string;
  product?: { id: string; part_number: string; name: string; unit_of_measure: string } | null;
  cavity_count: number;
  cycle_time_seconds: number;
  output_rate_per_hour: number;
  setup_time_minutes: number;
  current_shot_count: number;
  max_shots_before_maintenance: number;
  shot_percentage: number;
  nearing_limit: boolean;
  lifetime_total_shots: number;
  lifetime_max_shots: number;
  status: MoldStatus;
  status_label: string;
  location: string | null;
  compatible_machines_count: number;
  compatible_machines?: Array<{ id: string; machine_code: string; name: string; tonnage: number | null }>;
  created_at: string;
  updated_at: string;
}

export interface MrpPlanDiagnostic {
  item_id: number;
  item_code: string;
  gross: number;
  on_hand: number;
  reserved: number;
  in_transit: number;
  net: number;
  action: 'sufficient' | 'pr_created';
  order_by?: string;
  priority?: string;
  lead_time_days?: number;
}

export interface MrpPlan {
  id: string;
  mrp_plan_no: string;
  sales_order?: { id: string; so_number: string; customer?: { id: string; name: string } | null };
  version: number;
  status: MrpPlanStatus;
  total_lines: number;
  shortages_found: number;
  auto_pr_count: number;
  draft_wo_count: number;
  diagnostics: MrpPlanDiagnostic[];
  generator?: { id: string; name: string };
  work_orders?: Array<{ id: string; wo_number: string; quantity_target: number; status: string; planned_start: string }>;
  purchase_requests?: Array<{ id: string; pr_number: string; priority: string; status: string; is_auto_generated: boolean; date: string }>;
  generated_at: string;
  created_at: string;
  updated_at: string;
}

// Scheduler payloads
export interface SchedulerProposalRow {
  id: string;
  work_order_id: string;
  wo_number: string;
  machine_id: number;
  mold_id: number;
  scheduled_start: string;
  scheduled_end: string;
  priority_order: number;
  status: string;
}

export interface SchedulerConflict {
  work_order_id: string;
  wo_number: string;
  reasons: string[];
}

export interface SchedulerRunResult {
  scheduled: SchedulerProposalRow[];
  conflicts: SchedulerConflict[];
}

export interface GanttBar {
  id: string;
  wo_id: string | null;
  wo_number: string | null;
  product_name: string | null;
  mold_code: string | null;
  start: string;
  end: string;
  status: string;
  wo_status: string | null;
}

export interface GanttRow {
  machine_id: string;
  machine_code: string;
  name: string;
  tonnage: number | null;
  status: MachineStatus;
  bars: GanttBar[];
}

export interface GanttSnapshot {
  from: string;
  to: string;
  rows: GanttRow[];
}
