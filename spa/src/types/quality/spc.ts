// SPC (Statistical Process Control) types. IDs are hash strings; decimals come back as strings.

export type SpcChartType = 'xbar_r' | 'imr' | 'p_chart';
export type SpcChartStatus = 'active' | 'monitoring' | 'suspended';
export type SpcAlertRule =
  | 'rule_1_beyond_3sigma'
  | 'rule_2_two_of_three_beyond_2sigma'
  | 'rule_3_four_of_five_beyond_1sigma'
  | 'rule_4_eight_same_side';

export interface SpcControlChart {
  id: string;
  chart_type: SpcChartType;
  status: SpcChartStatus;
  subgroup_size: number;
  center_line: string | null;
  ucl: string | null;
  lcl: string | null;
  center_range: string | null;
  ucl_range: string | null;
  lcl_range: string | null;
  limits_locked: boolean;
  limits_sample_count: number | null;
  product?: { id: string; part_number: string; name: string } | null;
  spec_item?: {
    id: string;
    parameter_name: string;
    nominal_value: string | null;
    tolerance_min: string | null;
    tolerance_max: string | null;
    unit_of_measure: string | null;
  } | null;
  data_points?: SpcDataPoint[];
  unresolved_alert_count?: number;
  created_at: string;
  updated_at: string;
}

export interface SpcDataPoint {
  id: string;
  subgroup_number: number;
  subgroup_mean: string | null;
  subgroup_range: string | null;
  subgroup_std_dev: string | null;
  individual_value: string | null;
  moving_range: string | null;
  sample_values: number[] | null;
  alerts: string[] | null;
  inspection_ids: string[] | null;
  recorded_at: string | null;
  created_at: string;
}

export interface SpcAlert {
  id: string;
  rule_code: SpcAlertRule;
  severity: string;
  notes: string | null;
  acknowledged_at: string | null;
  resolved_at: string | null;
  chart?: {
    id: string;
    chart_type: SpcChartType;
  } | null;
  data_point?: {
    id: string;
    subgroup_number: number;
    subgroup_mean: string | null;
  } | null;
  acknowledged_by?: {
    id: string;
    name: string;
  } | null;
  created_at: string;
}

export interface CreateSpcChartData {
  product_id: string;
  spec_item_id: string;
  chart_type: SpcChartType;
  subgroup_size?: number;
}

export interface SpcCapabilityResult {
  cp: number;
  cpk: number;
  cpu: number;
  cpl: number;
  mean: number;
  std_dev: number;
  sample_count: number;
  usl: number;
  lsl: number;
  histogram: {
    bins: number[];
    bin_edges: number[];
    lsl: number;
    usl: number;
  };
}

export interface RunCapabilityData {
  product_id: string;
  spec_item_id: string;
}
