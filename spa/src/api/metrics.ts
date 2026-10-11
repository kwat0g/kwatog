import { client } from '@/api/client';
import type {
  MetricCatalogEnvelope,
  MetricEnvelope,
  MetricPeriodPreset,
  MetricValue,
} from '@/types/analytics';

export interface MetricQueryParams {
  period?: MetricPeriodPreset;
  /** Required when `period` is `custom`; the API answers 422 for a half-open window. */
  from?: string;
  to?: string;
  /** Only filters a registered metric actually consumes are accepted. */
  machine_id?: number;
}

/**
 * Phase 2 analytics layer.
 *
 * Both endpoints are gated per metric, not per route: `GET /dashboard/metrics`
 * returns only the rows whose gate the caller holds, and `.../{code}` answers
 * 403 for a metric that exists but is refused (404 only when the code is not in
 * the registry). So a page never needs to know a metric's permission — it
 * mounts the panel and the server decides whether the number arrives.
 *
 * The catalog is not fetched eagerly anywhere: it exists for "explain this
 * number" surfaces and for tooling, and pulling it on every dashboard mount
 * would spend a request on data the panel already carries in `meta`.
 */
export const metricsApi = {
  catalog: (module?: string) =>
    client
      .get<MetricCatalogEnvelope>('/dashboard/metrics', { params: { module } })
      .then((response) => response.data),

  get: <TValue = MetricValue>(code: string, params: MetricQueryParams = {}) =>
    client
      .get<MetricEnvelope<TValue>>(`/dashboard/metrics/${code}`, { params })
      .then((response) => response.data),
};
