/**
 * Series F — Task F4. Supplier performance types.
 */

export interface SupplierPerformanceSnapshot {
  period_year: number;
  period_month: number;
  on_time_delivery_rate: string | null;
  quality_pass_rate: string | null;
  incoming_quality_rate: string | null;
  in_process_quality_rate: string | null;
  outgoing_quality_rate: string | null;
  ncr_rate: string | null;
  price_variance_pct: string | null;
  lead_time_variance_days: string | null;
  overall_score: string | null;
  tier?: string | null;
  po_count: number;
  grn_count: number;
  computed_at: string | null;
}

export interface SupplierPerformanceTrendPoint {
  period_year: number;
  period_month: number;
  overall_score: string | null;
  tier?: string | null;
  on_time_delivery_rate: string | null;
  quality_pass_rate: string | null;
  incoming_quality_rate: string | null;
  ncr_rate: string | null;
}

export interface SupplierPerformance {
  vendor: { id: string; name: string };
  latest: SupplierPerformanceSnapshot | null;
  trend: SupplierPerformanceTrendPoint[];
  policy: {
    trend_months: number;
    on_time_target: number;
    quality_target: number;
    price_variance_target: number;
    lead_time_variance_target: number;
  };
}

export interface SupplierPerformanceResponse {
  data: SupplierPerformance;
}

export type SupplierPerformanceTier = 'A' | 'B' | 'C' | 'D';

export interface SupplierRankingRow {
  vendor: { id: string | null; name: string | null };
  period_year: number;
  period_month: number;
  overall_score: string | null;
  tier: SupplierPerformanceTier | null;
  on_time_delivery_rate: string | null;
  quality_pass_rate: string | null;
  ncr_rate: string | null;
  po_count: number;
  grn_count: number;
  computed_at: string | null;
}

export interface SupplierRankingResponse {
  data: SupplierRankingRow[];
  meta: {
    period_year: number;
    period_month: number;
    count: number;
    tier: SupplierPerformanceTier | null;
    limit: number;
  };
}
