import { useQuery } from '@tanstack/react-query';
import { metricsApi, type MetricQueryParams } from '@/api/metrics';
import { isStaleTimestamp } from '@/api/dashboard-layout';
import type { MetricEnvelope, MetricRefresh, MetricValue } from '@/types/analytics';

/**
 * Poll cadence per metric `refresh` bucket.
 *
 * `live` uses 60s — the same interval the role dashboards and the production
 * dashboard already poll on, so a metric panel adds no new request rate.
 * `monthly` is `false` on purpose: those metrics come from `kpi_snapshots`,
 * which `kpi:compute-monthly` rewrites once on the 2nd, so polling one every
 * 60s would refetch a value that cannot have changed. The cadence is a function
 * of the query's own last payload (the API hands back `meta.refresh`), not a
 * fixed guess made before the first response.
 */
const REFRESH_INTERVAL_MS: Record<MetricRefresh, number | false> = {
  live: 60_000,
  hourly: 5 * 60_000,
  daily: 15 * 60_000,
  monthly: false,
};

/** Two missed polls without a successful refetch reads stale, not current. */
const STALE_AFTER_MULTIPLIER = 2;

export interface UseMetricOptions extends MetricQueryParams {
  /** Skip the request entirely (permission unknown, widget collapsed, ...). */
  enabled?: boolean;
}

export interface UseMetricResult<TValue> {
  envelope: MetricEnvelope<TValue> | undefined;
  isLoading: boolean;
  isError: boolean;
  /** `meta.generated_at` is older than a full poll window — show the badge. */
  isStale: boolean;
  refetch: () => void;
}

/**
 * Phase 2 analytics layer — one metric, one window, one viewer.
 *
 * The gate is the server's: `ShowMetricRequest` resolves the route through the
 * metric registry and asks for that metric's own permission, so there is no
 * permission check here and no role name anywhere. A refused metric rejects
 * with a 403 that the shared axios interceptor does not toast (see
 * `interceptorOwnsToast`) — the panel renders its error state and says so.
 */
export function useMetric<TValue = MetricValue>(
  code: string,
  options: UseMetricOptions = {},
): UseMetricResult<TValue> {
  const { enabled = true, period = 'month', from, to, machine_id } = options;

  const query = useQuery({
    queryKey: ['dashboard', 'metric', code, period, from ?? null, to ?? null, machine_id ?? null],
    queryFn: () => metricsApi.get<TValue>(code, { period, from, to, machine_id }),
    enabled: enabled && code !== '',
    refetchInterval: (query) => {
      const payload = query.state.data as MetricEnvelope<TValue> | undefined;
      const value = payload ? REFRESH_INTERVAL_MS[payload.meta.refresh] : 60_000;

      return value;
    },
    placeholderData: (prev) => prev,
  });

  const envelope = query.data;
  const intervalMs = envelope ? REFRESH_INTERVAL_MS[envelope.meta.refresh] : 60_000;

  return {
    envelope,
    isLoading: query.isLoading,
    isError: query.isError,
    isStale:
      intervalMs !== false &&
      isStaleTimestamp(
        new Date(query.dataUpdatedAt).toISOString(),
        intervalMs * STALE_AFTER_MULTIPLIER,
      ) &&
      query.dataUpdatedAt > 0,
    refetch: () => {
      void query.refetch();
    },
  };
}
