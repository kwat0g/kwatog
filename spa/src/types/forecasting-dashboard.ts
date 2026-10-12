/**
 * Phase 3 — Forecasting dashboard panel types.
 * Shared by Headcount, Revenue, and Defect Rate forecast panels.
 */

export interface ForecastPoint {
 year: number;
 month: number;
 value: number;
 /** Only present in forecast points (not historical). */
 confidence?: number | null;
}

export interface InspectionForecastPoint extends ForecastPoint {
 total?: number;
 defects?: number;
}

export type TrendDirection = 'up' | 'down' | 'stable';

export interface ForecastPanelData {
 historical: ForecastPoint[] | InspectionForecastPoint[];
 forecast: ForecastPoint[];
 trend: TrendDirection;
 kpi: {
 label: string;
 value: string;
 unit: string;
 trend: TrendDirection;
 };
}

/**
 * Phase 3 (Task 6) — Cash-flow forecast month. The engine projects flat
 * run-rates, so every month carries the split behind the net figure.
 */
export interface CashFlowMonth {
 year: number;
 month: number;
 inflow: number;
 outflow: number;
 net: number;
 /** Only present in forecast months (not historical). */
 confidence?: number | null;
}

/** Phase 3 (Task 6) — cash-flow payload: the ForecastPanel net series plus the per-month split. */
export interface CashFlowForecastData extends ForecastPanelData {
  months: CashFlowMonth[];
  confidence: number | null;
}

/**
 * Phase 3 (Task 7) — Capacity forecast month. Plant-level aggregate: open
 * work-order hours against OEE-derated machine hours for the month.
 */
export interface CapacityMonth {
  year: number;
  month: number;
  load_hours: number;
  capacity_hours: number;
  util_pct: number;
  /** Only present in forecast months (not historical). */
  confidence?: number | null;
}

/** Phase 3 (Task 7) — capacity payload: the ForecastPanel util series plus load/capacity split and horizon breach flag. */
export interface CapacityForecastData extends ForecastPanelData {
  months: CapacityMonth[];
  breach: boolean;
  confidence: number | null;
}

/** Phase 3 (Task 8) — per-month attendance risk from the approved leave pipeline. */
export type AttendanceRisk = 'low' | 'medium' | 'high';

/**
 * Phase 3 (Task 8) — Attendance forecast month. Expected attendance rate is
 * the flat run-rate of the historical present share; leave load is approved
 * leave-days overlapping the month as a share of headcount × days in month.
 */
export interface AttendanceMonth {
  year: number;
  month: number;
  expected_rate: number;
  leave_load: number;
  risk: AttendanceRisk;
  /** Only present in forecast months (not historical). */
  confidence?: number | null;
}

/** Phase 3 (Task 8) — attendance payload: the ForecastPanel rate series plus per-month leave load and horizon risk flag. */
export interface AttendanceForecastData extends ForecastPanelData {
  months: AttendanceMonth[];
  risk: AttendanceRisk;
  confidence: number | null;
}
