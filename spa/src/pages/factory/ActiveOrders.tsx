import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { factoryApi } from '@/api/factory';
import { LuRefreshCw } from '@/lib/icons';
import type { WorkOrder } from '@/types/production';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { workOrderStatusVariant } from '@/lib/statusVariants';
import { TouchCardSkeleton } from '@/components/layout/TouchShell';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';

export default function ActiveOrders() {
  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: ['factory', 'active-orders'],
    queryFn: () => factoryApi.activeOrders(),
    refetchInterval: 30_000, // Auto-refresh every 30s
  });

  const orders = (data?.data ?? []) as WorkOrder[];

  const refresh = (
    <Button
      variant="ghost"
      size="lg"
      className="min-h-hit text-secondary"
      icon={<LuRefreshCw className={cn('w-4 h-4', isFetching && 'animate-spin')} />}
      disabled={isFetching}
      onClick={() => refetch()}
    >
      Refresh
    </Button>
  );

  return (
    <div className="space-y-3 touch-manipulation">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-medium text-primary">Active work orders</h1>
        {refresh}
      </div>

      {isLoading && (
        <TouchCardSkeleton count={4} label="Loading work orders" cardClassName="h-40" />
      )}

      {isError && !isLoading && (
        <EmptyState
          icon="alert-circle"
          title="Could not load work orders"
          description="Check the shop-floor connection and try again."
          action={
            <Button
              variant="secondary"
              size="lg"
              className="min-h-hit"
              onClick={() => refetch()}
            >
              Try again
            </Button>
          }
        />
      )}

      {!isLoading && !isError && orders.length === 0 && (
        <EmptyState
          icon="factory"
          title="No active work orders"
          description="Nothing is running on your machines right now."
          action={
            <Button
              variant="secondary"
              size="lg"
              className="min-h-hit"
              icon={<LuRefreshCw className="w-4 h-4" />}
              onClick={() => refetch()}
            >
              Refresh
            </Button>
          }
        />
      )}

      {orders.map((wo) => (
        <Link
          key={wo.id}
          to={`/factory/${wo.id}/output`}
          className={cn(
            'block rounded-md border border-default bg-canvas p-4 active:bg-subtle',
            focusRing,
          )}
        >
          <div className="flex items-center justify-between gap-2">
            <span className="font-mono tabular-nums text-sm font-medium">{wo.wo_number}</span>
            <Chip variant={workOrderStatusVariant[wo.status]}>
              {wo.status_label ?? wo.status.replace(/_/g, ' ')}
            </Chip>
          </div>

          <div className="mt-1.5 text-sm font-medium">{wo.product?.name ?? '—'}</div>
          <div className="mt-0.5 text-xs text-muted">
            {wo.machine?.name ?? '—'}{' '}
            {wo.machine?.machine_code ? `(${wo.machine.machine_code})` : ''}
          </div>

          <div className="mt-3">
            <div className="flex items-baseline justify-between text-xs text-muted mb-1">
              <span>Progress</span>
              <span className="font-mono tabular-nums text-sm text-primary">
                {wo.quantity_good} / {wo.quantity_target}
              </span>
            </div>
            <div
              className="h-2 rounded-full bg-subtle overflow-hidden"
              role="progressbar"
              aria-valuenow={wo.progress_percentage}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label={`${wo.wo_number} progress`}
            >
              <div
                className="h-full rounded-full bg-accent transition-[width]"
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
