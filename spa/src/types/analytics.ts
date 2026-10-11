/**
 * Phase 2 analytics layer — the SPA side of the metric contract.
 *
 * Mirrors `api/app/Modules/Dashboard/Support/Analytics/MetricDefinition`,
 * `AnalyticsPeriod::toMeta()` and `AnalyticsService::resolve()`. The metric
 * dictionary (`docs/dashboards/METRICS.md`) is generated from the same registry
 * these types describe, so a new metric needs no SPA schema work until a page
 * mounts it — the envelope shape is identical for every metric, and only
 * `value` is metric-specific.
 */

export type MetricUnit = 'percent' | 'currency' | 'count' | 'hours' | 'days' | 'ratio';

export type MetricDirection = 'higher_better' | 'lower_better' | 'neutral';

/** Matches `Dashboard\Enums\RenderKind`. Drives which body the panel draws. */
export type MetricRenderKind = 'scalar' | 'trend' | 'breakdown' | 'table' | 'gauge';

export type MetricRefresh = 'live' | 'hourly' | 'daily' | 'monthly';

/** Matches `AnalyticsPeriod::PRESETS`. `custom` needs both `from` and `to`. */
export type MetricPeriodPreset = 'today' | 'week' | 'month' | 'quarter' | 'ytd' | 'custom';

/** Brand-hued semantics, same vocabulary as the widget breakdown tones. */
export type MetricSegmentTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

/**
 * One dictionary row. `permission` is the ONLY gate — candidacy is the
 * permission, never a role name — and `caveats` travels with the number so a
 * tile can state its own limits instead of a doc doing it somewhere else.
 */
export interface MetricDefinition {
  code: string;
  label: string;
  module: string;
  unit: MetricUnit;
  direction: MetricDirection;
  render_kind: MetricRenderKind;
  permission: string;
  /** Prose mirror of the SQL. */
  aggregation: string;
  /** Every `table.column` the query touches. */
  sources: string[];
  /** Indexes the query relies on (asserted against the schema server-side). */
  indexes: string[];
  refresh: MetricRefresh;
  caveats: string[];
  /** null = TTL comes from the `dashboard.analytics.cache_ttl_seconds` setting. */
  cache_ttl_seconds: number | null;
}

/** The window a metric was resolved over, plus its equal-length comparison. */
export interface MetricPeriodMeta {
  preset: MetricPeriodPreset;
  from: string;
  to: string;
  days: number;
  compared_from: string;
  compared_to: string;
  timezone: string;
}

export interface MetricEnvelopeMeta {
  generated_at: string;
  metric: string;
  period: MetricPeriodMeta;
  cache_ttl: number;
  /** True when the payload came from cache rather than a fresh aggregation. */
  cached: boolean;
  cache_key: string;
  /** `company` or `department:<id>` — the row scope the number was computed under. */
  scope: string;
  module: string;
  refresh: MetricRefresh;
  sources: string[];
  indexes: string[];
  caveats: string[];
}

export interface MetricCatalogMeta {
  generated_at: string;
  count: number;
  module: string | null;
  /** Content hash of the whole registry — printed in the generated doc header. */
  fingerprint: string;
  document: string;
}

/**
 * The value is the ONLY metric-specific part of the envelope: `resolve()` puts
 * the definition and the number side by side and every `meta` key is shared.
 * `Record<string, unknown>` is the honest default — the API publishes no value
 * schema — and a page that needs a field types it with its own interface.
 */
export type MetricValue = Record<string, unknown>;

export interface MetricEnvelope<TValue = MetricValue> {
  data: {
    definition: MetricDefinition;
    value: TValue;
  };
  meta: MetricEnvelopeMeta;
}

export interface MetricCatalogEnvelope {
  data: MetricDefinition[];
  meta: MetricCatalogMeta;
}

/** `returns.pipeline` — `docs/dashboards/METRICS.md` § Definitions. */
export interface ReturnsPipelineValue {
  total: number;
  open_total: number;
  awaiting_approval: number;
  oldest_open_days: number | null;
  /** Zero-count statuses are omitted server-side, so this can be short. */
  segments: Array<{ label: string; value: number; tone: MetricSegmentTone }>;
  by_type: Array<{ label: string; value: number }>;
  handoff: { awaiting_inspection: number; in_inspection: number };
}

/** `production.wo_lead_time`. `target_hours` is null — no target exists in settings. */
export interface WorkOrderLeadTimeValue {
  unit: 'hours';
  points: Array<{ label: string; value: number }>;
  completed: number;
  avg_hours: number | null;
  min_hours: number | null;
  target_hours: number | null;
  machine_id: number | null;
}

/** The fields the generic panel body reads, across every render kind. */
export interface MetricRenderableValue extends MetricValue {
  total?: number;
  points?: Array<{ label: string; value: number }>;
  segments?: Array<{ label: string; value: number; tone: MetricSegmentTone }>;
  unit?: string;
}
