import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { factoryApi } from '@/api/factory';
import { RefreshCw } from 'lucide-react';
import type { WorkOrder, WorkOrderStatus } from '@/types/production';

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
      <div className="py-12 text-center text-zinc-500">
        <p className="text-lg">No active work orders.</p>
        <p className="text-sm mt-1">Pull down or tap refresh to check again.</p>
        <button
          type="button"
          onClick={() => refetch()}
          className="mt-4 inline-flex items-center gap-2 text-sm text-indigo-600 dark:text-indigo-400 min-h-[44px] px-4 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          <RefreshCw className="w-4 h-4" />
          Refresh
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-3 touch-manipulation">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Active Work Orders</h1>
        <button
          type="button"
          onClick={() => refetch()}
          disabled={isFetching}
          className="inline-flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400 min-h-[44px] px-3 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
        >
          <RefreshCw className={`w-4 h-4 ${isFetching ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {orders.map(wo => (
        <Link
          key={wo.id}
          to={`/factory/${wo.id}/output`}
          className="block rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-zinc-50 dark:active:bg-zinc-800"
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
          <div className="mt-0.5 text-xs text-zinc-500">
            {wo.machine?.name ?? 'No machine'} {wo.machine?.machine_code ? `(${wo.machine.machine_code})` : ''}
          </div>

          {/* Progress */}
          <div className="mt-3">
            <div className="flex items-baseline justify-between text-xs text-zinc-500 mb-1">
              <span>Progress</span>
              <span className="font-mono tabular-nums text-sm text-zinc-900 dark:text-zinc-100">
                {wo.quantity_good} / {wo.quantity_target}
              </span>
            </div>
            <div className="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
              <div
                className="h-full rounded-full bg-indigo-500 transition-all"
                style={{ width: `${Math.min(wo.progress_percentage, 100)}%` }}
              />
            </div>
            <div className="text-right text-xs text-zinc-400 mt-0.5 font-mono tabular-nums">
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
  planned:     'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200',
  confirmed:   'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
  in_progress: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
  paused:      'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
  completed:   'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200',
  closed:      'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200',
  cancelled:   'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
};

function StatusChip({ status }: { status: WorkOrderStatus }) {
  const cls = STATUS_CLASSES[status] ?? 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200';
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
        <div key={i} className="h-32 rounded-lg bg-zinc-100 dark:bg-zinc-800" />
      ))}
    </div>
  );
}

// ─── Error state ────────────────────────────────────────────────────────────

function ErrorRetry({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="py-12 text-center" role="alert">
      <div className="text-red-600 dark:text-red-400 mb-2">Could not load work orders.</div>
      <button
        type="button"
        onClick={onRetry}
        className="text-sm underline min-h-[44px] px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded"
      >
        Try again
      </button>
    </div>
  );
}
