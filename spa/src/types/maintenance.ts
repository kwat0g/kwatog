/** Sprint 8 — Task 69. Maintenance domain types. */

export type MaintainableType = 'machine' | 'mold';
export type MaintenanceScheduleInterval = 'hours' | 'days' | 'shots';
export type MaintenanceWorkOrderType = 'preventive' | 'corrective';
export type MaintenanceWorkOrderStatus = 'open' | 'assigned' | 'in_progress' | 'completed' | 'cancelled';
export type MaintenancePriority = 'critical' | 'high' | 'medium' | 'low';

export interface MaintainableSummary {
  id: string;
  code: string | null;
  name: string;
}

export interface MaintenanceSchedule {
  id: string;
  maintainable_type: MaintainableType;
  maintainable_id: string | null;
  maintainable: MaintainableSummary | null;
  schedule_type: string;
  description: string;
  interval_type: MaintenanceScheduleInterval;
  interval_value: number;
  last_performed_at: string | null;
  next_due_at: string | null;
  is_active: boolean;
  work_orders_count?: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface MaintenanceWorkOrderLog {
  id: string;
  description: string;
  logger?: { id: string; name: string } | null;
  created_at: string | null;
}

export interface SparePartUsage {
  id: string;
  item: { id: string; code: string; name: string; unit: string } | null;
  quantity: string;
  unit_cost: string;
  total_cost: string;
  created_at: string | null;
}

export interface MaintenanceWorkOrder {
  id: string;
  mwo_number: string;
  maintainable_type: MaintainableType;
  maintainable: MaintainableSummary | null;
  schedule?: {
    id: string;
    description: string;
    interval_type: MaintenanceScheduleInterval;
    interval_value: number;
  } | null;
  type: MaintenanceWorkOrderType;
  priority: MaintenancePriority;
  description: string;
  status: MaintenanceWorkOrderStatus;
  started_at: string | null;
  completed_at: string | null;
  downtime_minutes: number;
  cost: string;
  remarks: string | null;
  assignee?: { id: string; employee_no: string; name: string } | null;
  creator?: { id: string; name: string } | null;
  logs?: MaintenanceWorkOrderLog[];
  spare_parts?: SparePartUsage[];
  created_at: string | null;
  updated_at: string | null;
}

export interface CreateMaintenanceScheduleData {
  maintainable_type: MaintainableType;
  maintainable_id: number;
  description: string;
  interval_type: MaintenanceScheduleInterval;
  interval_value: number;
  last_performed_at?: string | null;
  is_active?: boolean;
}

export interface CreateMaintenanceWorkOrderData {
  maintainable_type: MaintainableType;
  maintainable_id: number;
  type: MaintenanceWorkOrderType;
  priority: MaintenancePriority;
  description: string;
}

/* ── ADV8 — Condition Readings ─────────────────────────────── */

export type ConditionMetric = 'temperature' | 'vibration' | 'pressure' | 'current' | 'oil_quality';
export type ConditionSource = 'manual' | 'iot_sensor' | 'plc' | 'api';
export type HealthStatus = 'ok' | 'warning' | 'critical';

export interface MachineConditionReading {
  id: string;
  machine_id: string | null;
  metric: ConditionMetric;
  value: string;
  unit: string;
  recorded_at: string | null;
  source: ConditionSource;
  notes: string | null;
  created_at: string | null;
}

export interface MachineHealthSnapshot {
  metric: ConditionMetric;
  value: number | null;
  unit: string;
  recorded_at: string | null;
  status: HealthStatus;
}

export interface ConditionTrendPoint {
  recorded_at: string;
  value: number;
}

export interface RecordConditionReadingData {
  machine_id: string;
  metric: ConditionMetric;
  value: number;
  unit?: string;
  recorded_at?: string;
  source?: ConditionSource;
  notes?: string;
}

export interface ConditionReadingResult {
  data: MachineConditionReading;
  triggered: boolean;
  reason: string | null;
  work_order: { id: string; mwo_number: string } | null;
}

/* ── ADV8 — Downtime Analytics ─────────────────────────────── */

export interface DowntimeCategoryBreakdown {
  category: string;
  minutes: number;
  count: number;
}

export interface DowntimeSummary {
  total_downtime_minutes: number;
  breakdown_count: number;
  mtbf_hours: number | null;
  mttr_minutes: number | null;
  availability_pct: number;
  category_breakdown: DowntimeCategoryBreakdown[];
}

export interface DailyDowntimeTrend {
  date: string;
  total_minutes: number;
  breakdown_minutes: number;
}

export interface TopMachineDowntime {
  machine_id: string;
  machine_code: string;
  name: string;
  downtime_minutes: number;
  breakdown_count: number;
}

export interface MachineDowntimeSummary {
  machine: {
    id: number;
    code: string;
    name: string;
  };
  summary: DowntimeSummary;
}
