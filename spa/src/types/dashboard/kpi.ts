export interface KpiDefinitionSummary {
  id: string;
  code: string;
  name: string;
  module: string;
  unit: 'percentage' | 'count' | 'currency' | 'days' | 'ratio';
  direction: 'higher_is_better' | 'lower_is_better';
  target_value: string | null;
  warning_threshold: string | null;
}

export interface KpiSnapshotSummary {
  actual_value: string;
  target_value: string;
  previous_value: string | null;
  trend: 'up' | 'down' | 'flat';
  status: 'on_target' | 'warning' | 'off_target';
  computed_at: string | null;
}

export interface KpiScorecardItem {
  definition: KpiDefinitionSummary;
  snapshot: KpiSnapshotSummary | null;
}

export interface KpiTrendPoint {
  period: string;
  value: string;
  target: string;
  status: string;
}
