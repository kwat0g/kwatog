import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { factoryApi } from '@/api/factory';
import { RefreshCw } from 'lucide-react';
import type { WorkOrder, WorkOrderStatus } from '@/types/production';
import { Button } from '@/components/ui/Button';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';

export default function ActiveOrders() {
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['factory', 'active-orders'],
    queryFn: () => factoryApi.activeOrders(),
    refetchInterval: 30_000, // Auto-refresh every 30s
  });

  if (isLoading) return <Skeleton />;
  if (error) return <ErrorRetry onRetry={refetch} />;

  const orders = (data?.data ?? []) as WorkOrder[];
  if (orders.length === 0) {
    return (
      <div className="py-12 text-center text-muted">
        <p className="text-lg">No active work orders.</p>
        <p className="text-sm mt-1">Pull down or tap refresh to check again.</p>
        <Button
          variant="secondary"
          size="lg"
          className="mt-4 min-h-[44px]"
          icon={<RefreshCw className="w-4 h-4" />}
          onClick={() => refetch()}
        >
          Refresh
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-3 touch-manipulation">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-medium">Active Work Orders</h1>
        <Button
          variant="ghost"
          size="lg"
          className="min-h-[44px] text-secondary"
          icon={<RefreshCw className={`w-4 h-4 ${isFetching ? 'animate-spin' : ''}`} />}
          disabled={isFetching}
          onClick={() => refetch()}
        >
          Refresh
        </Button>
      </div>

      {orders.map(wo => (
        <Link
          key={wo.id}
          to={`/factory/${wo.id}/output`}
          className={cn('block rounded-md border border-default bg-canvas p-4 active:bg-subtle', focusRing)}
        >
          {/* Header: WO number + status */}
          <div className="flex items-center justify-between">
            <span className="font-mono text-sm font-medium">{wo.wo_number}</span>
            <StatusChip status={wo.status} />
          </div>

          {/* Product + machine */}
          <div className="mt-1.5 text-sm font-medium">
            {wo.product?.name ?? 'Unknown product'}
          </div>
          <div className="mt-0.5 text-xs text-muted">
            {wo.machine?.name ?? 'No machine'} {wo.machine?.machine_code ? `(${wo.machine.machine_code})` : ''}
          </div>

          {/* Progress */}
          <div className="mt-3">
            <div className="flex items-baseline justify-between text-xs text-muted mb-1">
              <span>Progress</span>
              <span className="font-mono tabular-nums text-sm text-primary">
                {wo.quantity_good} / {wo.quantity_target}
              </span>
            </div>
            <div className="h-2 rounded-full bg-elevated overflow-hidden">
              <div
                className="h-full rounded-full bg-accent transition-all"
                style={{ width: `${Math.min(wo.progress_percentage, 100)}%` }}
              />
            </div>
            <div className="text-right text-xs text-muted mt-0.5 font-mono tabular-nums">
              {wo.progress_percentage}%
            </div>
          </div>
        </Link>
      ))}
    </div>
  );
}

// ─── Status chip ────────────────────────────────────────────────────────────

const STATUS_CLASSES: Record<WorkOrderStatus, string> = {
  planned:     'bg-muted text-secondary',
  confirmed:   'bg-info-bg text-info-fg',
  in_progress: 'bg-success-bg text-success-fg',
  paused:      'bg-warning-bg text-warning-fg',
  completed:   'bg-muted text-secondary',
  closed:      'bg-muted text-secondary',
  cancelled:   'bg-danger-bg text-danger-fg',
};

function StatusChip({ status }: { status: WorkOrderStatus }) {
  const cls = STATUS_CLASSES[status] ?? 'bg-muted text-secondary';
  return (
    <span className={`text-xs px-2 py-0.5 rounded font-medium ${cls}`}>
      {status.replace(/_/g, ' ')}
    </span>
  );
}

// ─── Loading skeleton ───────────────────────────────────────────────────────

function Skeleton() {
  return (
    <div role="status" aria-live="polite" aria-busy="true" className="space-y-3 animate-pulse">
      <span className="sr-only">Loading work orders...</span>
      {[0, 1, 2, 3].map(i => (
        <div key={i} className="h-32 rounded-md bg-elevated" />
      ))}
    </div>
  );
}

// ─── Error state ────────────────────────────────────────────────────────────

function ErrorRetry({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="py-12 text-center" role="alert">
      <div className="text-danger mb-2">Could not load work orders.</div>
      <Button variant="secondary" size="lg" className="min-h-[44px]" onClick={onRetry}>
        Try again
      </Button>
    </div>
  );
}
