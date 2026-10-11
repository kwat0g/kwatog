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
