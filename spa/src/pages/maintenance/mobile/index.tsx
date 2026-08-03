import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { useAuthStore } from '@/stores/authStore';
import { RefreshCw, Clock, AlertTriangle } from 'lucide-react';
import { formatDate } from '@/lib/formatDate';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { TouchCardSkeleton } from '@/components/layout/TouchShell';
import { maintenancePriorityVariant, maintenanceStatusVariant } from '@/lib/statusVariants';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';
import type { MaintenanceWorkOrder, MaintenanceWorkOrderStatus } from '@/types/maintenance';

type TabFilter = 'my_assigned' | 'all_open';

const TABS = [
  { value: 'my_assigned' as const, label: 'My assigned' },
  { value: 'all_open' as const, label: 'All open' },
];

export default function MobileMaintenanceList() {
  const user = useAuthStore((s) => s.user);
  const [tab, setTab] = useState<TabFilter>('my_assigned');

  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: ['maintenance', 'mobile-mwos', tab, user?.employee?.id],
    queryFn: () =>
      workOrdersApi.list({
        status: 'open,assigned,in_progress' as unknown as MaintenanceWorkOrderStatus,
        ...(tab === 'my_assigned' && user?.employee?.id ? { assigned_to: user.employee.id } : {}),
        per_page: 50,
      }),
    placeholderData: (prev) => prev,
    refetchInterval: 30_000,
  });

  const orders = (data?.data ?? []) as MaintenanceWorkOrder[];

  return (
    <div className="space-y-3">
      <SegmentedControl
        label="Work order filter"
        size="touch"
        fullWidth
        options={TABS}
        value={tab}
        onChange={setTab}
      />

      <div className="flex items-center justify-between">
        <h1 className="text-lg font-medium text-primary">Work orders</h1>
        <Button
          variant="ghost"
          size="lg"
          className="min-h-[44px] text-secondary"
          icon={<RefreshCw className={cn('w-4 h-4', isFetching && 'animate-spin')} />}
          disabled={isFetching}
          onClick={() => refetch()}
        >
          Refresh
        </Button>
      </div>

      {isLoading && <TouchCardSkeleton count={4} label="Loading work orders" />}

      {isError && !isLoading && (
        <EmptyState
          icon="alert-circle"
          title="Could not load work orders"
          description="Check your connection and try again."
          action={
            <Button variant="secondary" size="lg" className="min-h-[44px]" onClick={() => refetch()}>
              Try again
            </Button>
          }
        />
      )}

      {!isLoading && !isError && orders.length === 0 && (
        <EmptyState
          icon="wrench"
          title={tab === 'my_assigned' ? 'Nothing assigned to you' : 'No open work orders'}
          description={
            tab === 'my_assigned'
              ? 'Switch to All open to pick up unassigned work.'
              : 'Every maintenance job is closed out.'
          }
          action={
            <Button
              variant="secondary"
              size="lg"
              className="min-h-[44px]"
              icon={<RefreshCw className="w-4 h-4" />}
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
          to={`/maintenance/mobile/${wo.id}`}
          className={cn('block rounded-md border border-default bg-canvas p-4 active:bg-subtle', focusRing)}
        >
          <div className="flex items-center justify-between gap-2">
            <span className="font-mono tabular-nums text-sm font-medium">{wo.mwo_number}</span>
            <Chip variant={maintenancePriorityVariant[wo.priority]} className="gap-1">
              {wo.priority === 'critical' && <AlertTriangle className="w-3 h-3" aria-hidden />}
              {wo.priority_label ?? wo.priority}
            </Chip>
          </div>

          <div className="mt-1.5 text-sm font-medium">{wo.maintainable?.name ?? '—'}</div>
          <div className="mt-0.5 flex items-center gap-2 text-xs text-muted">
            <span>{wo.type_label ?? wo.type}</span>
            <span aria-hidden>&middot;</span>
            <Chip variant={maintenanceStatusVariant[wo.status]}>{wo.status_label ?? wo.status}</Chip>
          </div>

          <div className="mt-3 flex items-center justify-between text-xs text-muted">
            <span>{wo.assignee ? `Assigned: ${wo.assignee.name}` : 'Unassigned'}</span>
            {wo.created_at && (
              <span className="inline-flex items-center gap-1 font-mono tabular-nums">
                <Clock className="w-3 h-3" aria-hidden />
                {formatDate(wo.created_at)}
              </span>
            )}
          </div>
        </Link>
      ))}
    </div>
  );
}
