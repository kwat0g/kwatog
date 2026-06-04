/**
 * ADV11 — Demand & Sales Forecasting types.
 */

export type ForecastMethod = 'moving_avg' | 'weighted_avg' | 'manual';

export interface DemandForecast {
  id: string;
  forecast_year: number;
  forecast_month: number;
  method: ForecastMethod;
  forecasted_quantity: number;
  confidence_level: number | null;
  actual_quantity: number | null;
  variance: number | null;
  product?: { id: string; part_number: string; name: string } | null;
  customer?: { id: string; name: string } | null;
  creator?: { id: string; name: string } | null;
  created_at?: string;
  updated_at?: string;
}

export interface HistoricalDemandPoint {
  year: number;
  month: number;
  qty: number;
}

export type StockOutRisk = 'critical' | 'high' | 'medium' | 'low' | 'ok';
export type DemandSource = 'forecast' | 'historical' | 'none';

export interface StockOutRow {
  item_id: number;
  code: string;
  name: string;
  unit_of_measure: string;
  available: number;
  safety_stock: number;
  reorder_point: number;
  lead_time_days: number;
  daily_demand: number;
  demand_source: DemandSource;
  days_until_stockout: number | null;
  reorder_date: string | null;
  suggested_qty: number | null;
  risk: StockOutRisk;
}

export interface StockOutResponse {
  data: StockOutRow[];
  meta: { horizon_days: number; generated_at: string };
}

export interface ForecastAccuracyMonth {
  year: number;
  month: number;
  forecast: number;
  actual: number;
  variance: number;
  ape: number;
}

export interface ForecastAccuracy {
  mape: number | null;
  bias: number | null;
  periods_evaluated: number;
  monthly: ForecastAccuracyMonth[];
}
