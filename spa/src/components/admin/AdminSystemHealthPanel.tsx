import type { ReactNode } from 'react';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { formatDateTime } from '@/lib/formatDate';
import type { SystemHealth } from '@/api/admin/settings';

interface AdminSystemHealthPanelProps {
  health?: SystemHealth;
  isLoading: boolean;
  isError: boolean;
  onRetry: () => void;
}

export function AdminSystemHealthPanel({
  health,
  isLoading,
  isError,
  onRetry,
}: AdminSystemHealthPanelProps) {
  const persistentRedis = health?.redis_persistent;

  return (
    <Panel title="Real-time and cache health" meta="Automatic refresh every 30 seconds">
      {isLoading && <p className="text-sm text-muted" role="status">Loading system health…</p>}
      {isError && (
        <div className="flex flex-wrap items-center justify-between gap-3" role="alert">
          <p className="text-sm text-muted">System health could not be loaded.</p>
          <Button variant="secondary" size="sm" onClick={onRetry}>
            Retry health check
          </Button>
        </div>
      )}
      {health && (
        <>
          <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 xl:grid-cols-3">
            <HealthMetric label="Real-time delivery">
              <HealthChip status={health.realtime.delivery_status} />
            </HealthMetric>
            <HealthMetric label="Reverb TCP">
              <HealthChip status={health.reverb_tcp_status} />
            </HealthMetric>
            <HealthMetric label="Queue and outbox">
              <span className="text-sm text-primary">
                {health.realtime.broadcast_queue_pending === null ||
                health.realtime.outbox_pending === null ||
                health.realtime.outbox_failed === null
                  ? 'Unknown'
                  : `${health.realtime.broadcast_queue_pending} queue pending · ${health.realtime.outbox_pending} outbox pending · ${health.realtime.outbox_failed} failed`}
              </span>
              {health.realtime.oldest_pending_seconds !== null && (
                <span className="block text-xs text-muted">
                  Oldest pending event: {health.realtime.oldest_pending_seconds}s
                </span>
              )}
            </HealthMetric>
            <HealthMetric label="Cache Redis memory">
              <span className="text-sm text-primary">
                {health.redis_cache.status !== 'available' || health.redis_cache.used_memory_bytes === null
                  ? 'Unknown'
                  : `${formatMebibytes(health.redis_cache.used_memory_bytes)} / ${health.redis_cache.max_memory_bytes === null ? 'limit not set' : formatMebibytes(health.redis_cache.max_memory_bytes)}`}
              </span>
            </HealthMetric>
            <HealthMetric label="Persistent Redis memory">
              <span className="text-sm text-primary">
                {!persistentRedis || persistentRedis.status !== 'available' || persistentRedis.used_memory_bytes === null
                  ? 'Unknown'
                  : `${formatMebibytes(persistentRedis.used_memory_bytes)} / ${persistentRedis.max_memory_bytes === null ? 'limit not set' : formatMebibytes(persistentRedis.max_memory_bytes)}`}
              </span>
            </HealthMetric>
            <HealthMetric label="Latest cache audit">
              <HealthChip status={health.cache_audit.status} />
              {health.cache_audit.completed_at && (
                <span className="block text-xs text-muted">
                  {formatDateTime(health.cache_audit.completed_at)}
                </span>
              )}
            </HealthMetric>
            <HealthMetric label="Cache audit findings">
              <span className="text-sm text-primary">
                {health.cache_audit.entries === null
                  ? 'Unknown'
                  : `${health.cache_audit.entries} entries audited`}
              </span>
              <span className="block text-xs text-muted">
                {health.cache_audit.without_ttl === null ||
                health.cache_audit.oversized === null ||
                health.cache_audit.unknown_size === null
                  ? 'Findings unavailable'
                  : `${health.cache_audit.without_ttl} without TTL · ${health.cache_audit.oversized} oversized · ${health.cache_audit.unknown_size} size unknown`}
              </span>
            </HealthMetric>
          </dl>
          <p className="mt-4 text-xs text-muted">Updated {formatDateTime(health.generated_at)}</p>
        </>
      )}
    </Panel>
  );
}

function HealthMetric({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="mb-1 text-xs font-medium text-muted">{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

function HealthChip({ status }: { status: string }) {
  const normalized = status.replaceAll('_', ' ');
  const variant: ChipVariant =
    status === 'healthy' || status === 'reachable' || status === 'clean'
      ? 'success'
      : status === 'unknown' || status === 'unconfigured' || status === 'unavailable'
        ? 'neutral'
        : status === 'degraded' || status === 'attention'
          ? 'warning'
          : 'danger';

  return <Chip variant={variant}>{normalized[0].toUpperCase() + normalized.slice(1)}</Chip>;
}

function formatMebibytes(bytes: number): string {
  return `${(bytes / 1024 / 1024).toFixed(2)} MiB`;
}
