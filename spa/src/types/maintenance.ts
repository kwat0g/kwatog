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
 estimated_duration_minutes: number;
 running_hours_baseline: string | null;
 last_performed_at: string | null;
 next_due_at: string | null;
 progress: { current: number | null; target: number; unit: 'hours' | 'shots' } | null;
 is_active: boolean;
 work_orders_count?: number;
 created_at: string | null;
 updated_at: string | null;
}

export interface MaintenanceWorkOrderLog {
 id: string;
 description: string;
 finding?: string | null;
 action_taken?: string | null;
 result?: 'in_progress' | 'passed' | 'needs_follow_up' | null;
 logger?: { id: string; name: string } | null;
 created_at: string | null;
}

export interface SparePartUsage {
 handover?: { recipient_name: string; handed_over_at: string } | null;
 stock_movement_id?: string | null;
 id: string;
 item: { id: string; code: string; name: string; unit: string } | null;
 work_record: { id: string; finding: string; action_taken: string } | null;
 issuer: { id: string; name: string } | null;
 quantity: string;
 lot_number: string | null;
 unit_cost: string;
 total_cost: string;
 returned_quantity: string;
 returned_cost: string;
 created_at: string | null;
}

export interface MaintenanceWorkOrder {
 verification_required?: boolean;
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
 type_label?: string;
 priority: MaintenancePriority;
 priority_label?: string;
 description: string;
 status: MaintenanceWorkOrderStatus;
 status_label?: string;
 can_record_work?: boolean;
 parts_handover?: { pending: number; blocker: string | null };
 part_requests?: Array<{ id: string; status: 'requested' | 'handed_over' | 'withdrawn'; quantity: string;
   item_code: string; item_name: string; unit: string; location: string; requested_at: string; handed_over_at: string | null }>;

 available_actions?: Array<'assign' | 'start' | 'complete' | 'cancel' | 'submit_repair' | 'verify_repair'>;
 parts_reconciled_at?: string | null;
 parts_reconciled_by?: { id: string; name: string } | null;
 parts_readiness?: {
   outstanding: Array<{
     usage_id: string;
     item_code: string;
     item_name: string;
     lot_number: string | null;
     quantity: string;
   }>;
   can_close_parts: boolean;
   blocker: string | null;
 };
 closure_readiness?: {
   latest_work_result: MaintenanceWorkOrderLog['result'];
   can_close_work: boolean;
   blocker: string | null;
 };
 repair_evidence?: RepairEvidence | null;
 repair_submitted_at?: string | null;
 submitted_by?: { id: string; name: string } | null;
 verified_at?: string | null;
 verifier?: { id: string; name: string } | null;
 open_downtime_started_at?: string | null;
 server_time?: string;
 response_minutes?: number | null;
 technician_repair_minutes?: number | null;
 maintenance_elapsed_minutes?: number | null;
 maintenance_elapsed_provisional?: boolean;
 readiness?: {
 equipment_status: string | null;
 can_start: boolean;
 production_work_order: { id: string; wo_number: string; status: string; link: string | null } | null;
 blockers: Array<{ code: string; message: string; required_action: string }>;
 };
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
  maintainable_id: string;
  description: string;
 interval_type: MaintenanceScheduleInterval;
 interval_value: number;
 estimated_duration_minutes?: number;
 last_performed_at?: string | null;
 is_active?: boolean;
}

export interface MaintenanceWindowSuggestion {
 planned_start_at: string;
 planned_end_at: string;
 technician: { id: string; name: string; employee_no?: string };
}

export interface MaintenanceWindowSuggestions {
 work_order_id: string | null;
 confirmed_window: MaintenanceWindowSuggestion | null;
 suggestions: MaintenanceWindowSuggestion[];
 blockers: { schedule: string[]; production: string[]; maintenance: string[]; staffing: string[] };
}

export interface CreateMaintenanceWorkOrderData {
  maintainable_type: MaintainableType;
  maintainable_id: string;
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
 source_label?: string;
 notes: string | null;
 created_at: string | null;
}

export interface MachineHealthSnapshot {
 metric: ConditionMetric;
 value: number | null;
 unit: string;
 recorded_at: string | null;
 status: HealthStatus;
 min_threshold: number | null;
 max_threshold: number | null;
 breach_window: number;
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
 resolved_breakdown_count: number;
 open_breakdown_count: number;
 mtbf_hours: number | null;
 mttr_minutes: number | null;
 availability_pct: number | null;
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
 id: string;
 code: string;
 name: string;
 };
 summary: DowntimeSummary;
}

export interface RepairEvidence {
 diagnosis: string;
 repair_action: string;
 functional_test_result: string;
}
