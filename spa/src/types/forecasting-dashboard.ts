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
