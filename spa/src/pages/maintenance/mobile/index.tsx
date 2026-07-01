import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { useAuthStore } from '@/stores/authStore';
import { RefreshCw, Clock, AlertTriangle } from 'lucide-react';
import { formatDate } from '@/lib/formatDate';
import type {
  MaintenanceWorkOrder,
  MaintenanceWorkOrderStatus,
  MaintenancePriority,
} from '@/types/maintenance';

type TabFilter = 'my_assigned' | 'all_open';

export default function MobileMaintenanceList() {
  const user = useAuthStore(s => s.user);
  const [tab, setTab] = useState<TabFilter>('my_assigned');

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['maintenance', 'mobile-mwos', tab, user?.employee?.id],
    queryFn: () =>
      workOrdersApi.list({
        status: 'open,assigned,in_progress' as unknown as MaintenanceWorkOrderStatus,
        ...(tab === 'my_assigned' && user?.employee?.id
          ? { assigned_to: user.employee.id }
          : {}),
        per_page: 50,
      }),
    placeholderData: (prev) => prev,
    refetchInterval: 30_000,
  });

  if (isLoading) return <Skeleton />;
  if (error) return <ErrorRetry onRetry={refetch} />;

  const orders = (data?.data ?? []) as MaintenanceWorkOrder[];

  return (
    <div className="space-y-3">
      {/* Tab bar */}
      <div className="flex rounded-lg bg-zinc-100 dark:bg-zinc-800 p-1">
        <TabButton active={tab === 'my_assigned'} onClick={() => setTab('my_assigned')}>
          My Assigned
        </TabButton>
        <TabButton active={tab === 'all_open'} onClick={() => setTab('all_open')}>
          All Open
        </TabButton>
      </div>

      {/* Header + refresh */}
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Work Orders</h1>
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

      {/* Empty state */}
      {orders.length === 0 && (
        <div className="py-12 text-center text-zinc-500">
          <p className="text-lg">
            {tab === 'my_assigned' ? 'No work orders assigned to you.' : 'No open work orders.'}
          </p>
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
      )}

      {/* Card list */}
      {orders.map(wo => (
        <Link
          key={wo.id}
          to={`/maintenance/mobile/${wo.id}`}
          className="block rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-zinc-50 dark:active:bg-zinc-800"
        >
          {/* Header: MWO number + priority */}
          <div className="flex items-center justify-between">
            <span className="font-mono text-sm font-medium">{wo.mwo_number}</span>
            <PriorityChip priority={wo.priority} />
          </div>

          {/* Machine / mold + type */}
          <div className="mt-1.5 text-sm font-medium">
            {wo.maintainable?.name ?? 'Unknown target'}
          </div>
          <div className="mt-0.5 flex items-center gap-2 text-xs text-zinc-500">
            <span className="capitalize">{wo.type}</span>
            <span>&middot;</span>
            <StatusChip status={wo.status} />
          </div>

          {/* Assignee + due info */}
          <div className="mt-3 flex items-center justify-between text-xs text-zinc-500">
            <span>
              {wo.assignee
                ? `Assigned: ${wo.assignee.name}`
                : 'Unassigned'}
            </span>
            {wo.created_at && (
              <span className="inline-flex items-center gap-1 font-mono tabular-nums">
                <Clock className="w-3 h-3" />
                {formatDate(wo.created_at)}
              </span>
            )}
          </div>
        </Link>
      ))}
    </div>
  );
}

// ---- Sub-components ----

function TabButton({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex-1 text-sm font-medium py-2 rounded-md min-h-[44px] transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 ${
        active
          ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 shadow-sm'
          : 'text-zinc-500 dark:text-zinc-400'
      }`}
    >
      {children}
    </button>
  );
}

const PRIORITY_CLASSES: Record<MaintenancePriority, string> = {
  critical: 'bg-danger-bg text-danger-fg',
  high:     'bg-warning-bg text-warning-fg',
  medium:   'bg-info-bg text-info-fg',
  low:      'bg-muted text-secondary',
};

function PriorityChip({ priority }: { priority: MaintenancePriority }) {
  const cls = PRIORITY_CLASSES[priority] ?? PRIORITY_CLASSES.medium;
  return (
    <span className={`text-xs px-2 py-0.5 rounded font-medium inline-flex items-center gap-1 ${cls}`}>
      {priority === 'critical' && <AlertTriangle className="w-3 h-3" />}
      {priority}
    </span>
  );
}

const STATUS_CLASSES: Record<MaintenanceWorkOrderStatus, string> = {
  open:        'bg-muted text-secondary',
  assigned:    'bg-info-bg text-info-fg',
  in_progress: 'bg-success-bg text-success-fg',
  completed:   'bg-muted text-secondary',
  cancelled:   'bg-danger-bg text-danger-fg',
};

function StatusChip({ status }: { status: MaintenanceWorkOrderStatus }) {
  const cls = STATUS_CLASSES[status] ?? STATUS_CLASSES.open;
  return (
    <span className={`text-xs px-2 py-0.5 rounded font-medium ${cls}`}>
      {status.replace(/_/g, ' ')}
    </span>
  );
}

function Skeleton() {
  return (
    <div role="status" aria-live="polite" aria-busy="true" className="space-y-3 animate-pulse">
      <span className="sr-only">Loading work orders...</span>
      <div className="h-10 rounded-lg bg-zinc-100 dark:bg-zinc-800" />
      {[0, 1, 2, 3].map(i => (
        <div key={i} className="h-28 rounded-lg bg-zinc-100 dark:bg-zinc-800" />
      ))}
    </div>
  );
}

function ErrorRetry({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="py-12 text-center" role="alert">
      <div className="text-danger mb-2">Could not load work orders.</div>
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
