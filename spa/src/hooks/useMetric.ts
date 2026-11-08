import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { metricsApi, type MetricQueryParams } from '@/api/metrics';
import type { MetricEnvelope, MetricRefresh, MetricValue } from '@/types/analytics';

/**
 * Stale-indicator threshold per metric refresh bucket. Queries refresh from
 * their source events through the shared invalidation map; RealtimeProvider
 * owns visibility-aware catch-up when delivery is degraded. These thresholds
 * only update the stale label and never send network requests.
 */
const STALE_AFTER_MS: Record<MetricRefresh, number | false> = {
  live: 2 * 60_000,
  hourly: 10 * 60_000,
  daily: 30 * 60_000,
  monthly: false,
};

export interface UseMetricOptions extends MetricQueryParams {
  /** Skip the request entirely (permission unknown, widget collapsed, ...). */
  enabled?: boolean;
}

export interface UseMetricResult<TValue> {
  envelope: MetricEnvelope<TValue> | undefined;
  isLoading: boolean;
  isError: boolean;
  /** The active query has not received fresh data within its event-freshness window. */
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
  });

  const envelope = query.data;
  const staleAfterMs = envelope ? STALE_AFTER_MS[envelope.meta.refresh] : false;
  const [freshnessNow, setFreshnessNow] = useState(() => Date.now());

  useEffect(() => {
    if (query.dataUpdatedAt <= 0 || staleAfterMs === false) return;

    const delay = query.dataUpdatedAt + staleAfterMs - Date.now();
    if (delay <= 0) {
      setFreshnessNow(Date.now());
      return;
    }

    const timeout = window.setTimeout(() => setFreshnessNow(Date.now()), delay);
    return () => window.clearTimeout(timeout);
  }, [query.dataUpdatedAt, staleAfterMs]);

  return {
    envelope,
    isLoading: query.isLoading,
    isError: query.isError,
    isStale: staleAfterMs !== false
      && query.dataUpdatedAt > 0
      && freshnessNow >= query.dataUpdatedAt + staleAfterMs,
    refetch: () => {
      void query.refetch();
    },
  };
}
