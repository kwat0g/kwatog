// Task 12 — Production routing + WO operation types.

export interface RoutingOperation {
  id: string;
  sequence: number;
  operation_name: string;
  work_center: string | null;
  machine: { id: string; machine_code: string; name: string } | null;
  mold: { id: string; mold_code: string; name: string } | null;
  setup_time_minutes: string;
  cycle_time_minutes: string;
  description: string | null;
  qc_required: boolean;
}

export interface ProductRouting {
  id: string;
  product: { id: string; part_number: string; name: string } | null;
  version: number;
  is_active: boolean;
  total_cycle_time: string;
  notes: string | null;
  operations: RoutingOperation[];
  created_at: string;
  updated_at: string;
}

export type WoOperationStatus =
  | 'pending' | 'setup' | 'in_progress' | 'paused' | 'completed' | 'skipped';

export interface WoOperation {
  id: string;
  sequence: number;
  operation_name: string;
  status: WoOperationStatus;
  machine: { id: string; machine_code: string; name: string } | null;
  mold: { id: string; mold_code: string; name: string } | null;
  operator: { id: string; first_name: string; last_name: string } | null;
  planned_start: string | null;
  planned_end: string | null;
  actual_start: string | null;
  actual_end: string | null;
  setup_start: string | null;
  setup_end: string | null;
  qty_planned: string;
  qty_completed: string;
  qty_scrapped: string;
  scrap_reason: string | null;
  downtime_minutes: string;
  notes: string | null;
}
