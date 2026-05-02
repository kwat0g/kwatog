// Sprint 6 — Production types.

export type WorkOrderStatus =
  | 'planned' | 'confirmed' | 'in_progress' | 'paused'
  | 'completed' | 'closed' | 'cancelled';

export type MachineDowntimeCategory =
  | 'breakdown' | 'changeover' | 'material_shortage' | 'no_order' | 'planned_maintenance';

export interface WorkOrderMaterial {
  id: string;
  item: { id: string; code: string; name: string; unit_of_measure: string } | null;
  bom_quantity: string;
  actual_quantity_issued: string;
  variance: string;
}

export interface WorkOrderDefectRow {
  id: string;
  count: number;
  defect_type: { id: string; code: string; name: string } | null;
}

export interface WorkOrderOutput {
  id: string;
  recorded_at: string;
  good_count: number;
  reject_count: number;
  total_count: number;
  shift: string | null;
  batch_code: string | null;
  remarks: string | null;
  recorder?: { id: string; name: string } | null;
  defects?: WorkOrderDefectRow[];
}

export interface WorkOrder {
  id: string;
  wo_number: string;
  product?: { id: string; part_number: string; name: string };
  sales_order?: { id: string; so_number: string } | null;
  machine?: { id: string; machine_code: string; name: string } | null;
  mold?: { id: string; mold_code: string; name: string } | null;
  quantity_target: number;
  quantity_produced: number;
  quantity_good: number;
  quantity_rejected: number;
  progress_percentage: number;
  scrap_rate: string;
  planned_start: string;
  planned_end: string;
  actual_start: string | null;
  actual_end: string | null;
  status: WorkOrderStatus;
  status_label: string;
  pause_reason: string | null;
  priority: number;
  creator?: { id: string; name: string } | null;
  materials?: WorkOrderMaterial[];
  outputs?: WorkOrderOutput[];
  created_at: string;
  updated_at: string;
}

export interface DefectType {
  id: string;
  code: string;
  name: string;
  description: string | null;
  is_active: boolean;
}

export interface CreateWorkOrderData {
  product_id: string;
  sales_order_id?: string;
  machine_id?: string;
  mold_id?: string;
  quantity_target: number;
  planned_start: string;
  planned_end: string;
  priority?: number;
}

export interface RecordOutputData {
  good_count: number;
  reject_count: number;
  shift?: string;
  remarks?: string;
  defects?: { defect_type_id: string; count: number }[];
}

export interface OeeResult {
  availability: number;
  performance: number;
  quality: number;
  oee: number;
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
}

export interface ProductionDashboardPayload {
  kpis: {
    today_output_total: number;
    today_output_good: number;
    today_output_reject: number;
    active_work_orders: number;
    machines_total: number;
    machines_running: number;
    machines_idle: number;
    machines_breakdown: number;
    avg_oee_today: number;
  };
  chain_stage_breakdown: Array<{ label: string; count: number; percent: number; color: string }>;
  machine_utilization: MachineOeeRow[];
  alerts: Array<{ type: string; severity: string; message: string; link: string }>;
  defect_pareto: Array<{ defect_code: string; defect_name: string; count: number; percent: number }>;
  generated_at: string;
}

export interface ChainStep {
  key: string;
  label: string;
  date: string | null;
  state: 'done' | 'active' | 'pending';
}
