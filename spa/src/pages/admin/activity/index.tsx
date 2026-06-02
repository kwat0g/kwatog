import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { activityApi } from '@/api/admin/activity';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { UserBadge } from '@/components/ui/UserBadge';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import type {
  ActivityEvent,
  ActivitySeverity,
  ActivityFeedParams,
} from '@/types/activity';

const SEVERITY_DOT: Record<ActivitySeverity, string> = {
  info:    'bg-info-bg',
  success: 'bg-success-bg',
  warning: 'bg-warning-bg',
  danger:  'bg-danger-bg',
};

function relTime(iso: string): string {
  const t = new Date(iso).getTime();
  const diffMs = Date.now() - t;
  const m = Math.round(diffMs / 60000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.round(h / 24);
  if (d < 7) return `${d}d ago`;
  return new Date(iso).toLocaleDateString();
}

export default function AdminActivityFeedPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useState<ActivityFeedParams>({
    page: 1,
    per_page: 50,
  });

  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: ['admin', 'activity', filters],
    queryFn: () => activityApi.list(filters),
    placeholderData: (prev) => prev,
    refetchInterval: 60_000, // light polling — websocket upgrade is a future task
  });

  const update = (patch: Partial<ActivityFeedParams>) =>
    setFilters((f) => ({ ...f, ...patch, page: 1 }));

  return (
    <div>
      <PageHeader
        title="System activity"
        subtitle={data ? `${data.meta.total.toLocaleString()} events` : undefined}
        backTo="/admin/users-roles"
        backLabel="Users & Roles"
        breadcrumbs={[
          { label: 'Admin', href: '/admin' },
          { label: 'Users & Roles', href: '/admin/users-roles' },
          { label: 'Activity' },
        ]}
        actions={
          isFetching ? (
            <span className="text-xs text-muted">Refreshing…</span>
          ) : null
        }
      />

      {/* Filters */}
      <div className="px-5 py-3 border-b border-default flex flex-wrap items-end gap-3">
        <Input
          placeholder="Search summary…"
          value={filters.search ?? ''}
          onChange={(e: { target: { value: string } }) => update({ search: e.target.value })}
        />
        <Select
          label="Type"
          value={filters.type ?? ''}
          onChange={(e: { target: { value: string } }) => update({ type: e.target.value || undefined })}
        >
          <option value="">All types</option>
          <option value="transaction">Transaction</option>
          <option value="approval">Approval</option>
          <option value="automation">Automation</option>
          <option value="alert">Alert</option>
          <option value="auth">Auth</option>
        </Select>
        <Select
          label="Severity"
          value={filters.severity ?? ''}
          onChange={(e: { target: { value: string } }) =>
            update({ severity: (e.target.value || undefined) as ActivitySeverity | undefined })
          }
        >
          <option value="">All</option>
          <option value="info">Info</option>
          <option value="success">Success</option>
          <option value="warning">Warning</option>
          <option value="danger">Danger</option>
        </Select>
        <Input
          label="From"
          type="date"
          value={filters.from ?? ''}
          onChange={(e: { target: { value: string } }) => update({ from: e.target.value || undefined })}
        />
        <Input
          label="To"
          type="date"
          value={filters.to ?? ''}
          onChange={(e: { target: { value: string } }) => update({ to: e.target.value || undefined })}
        />
      </div>

      {/* States */}
      {isLoading && !data && (
        <div className="px-5 py-4 space-y-2">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="h-12 bg-elevated rounded-md animate-pulse" />
          ))}
        </div>
      )}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load activity"
          description="Something went wrong."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No activity matches"
          description="Try widening your filters or selecting a longer date range."
        />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <ul className="divide-y divide-subtle border border-default rounded-md bg-canvas">
            {data.data.map((e: ActivityEvent) => (
              <li
                key={e.id}
                onClick={() => e.link && navigate(e.link)}
                className={cn(
                  'flex items-start gap-3 px-3 py-2.5',
                  e.link ? 'cursor-pointer hover:bg-subtle' : '',
                )}
              >
                <span
                  className={cn('mt-1.5 inline-block w-1.5 h-1.5 rounded-full shrink-0', SEVERITY_DOT[e.severity])}
                  aria-hidden
                />
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-0.5">
                    <span className="text-xs font-medium text-primary truncate">{e.summary}</span>
                    <Chip variant={e.severity}>{e.type}</Chip>
                  </div>
                  <div className="text-2xs text-muted flex items-center gap-2">
                    {e.actor ? (
                      <UserBadge name={e.actor.name} role={e.actor.role} />
                    ) : (
                      <span>System</span>
                    )}
                    <span>·</span>
                    <span className="font-mono tabular-nums">{relTime(e.created_at)}</span>
                    {e.action && (
                      <>
                        <span>·</span>
                        <span className="font-mono">{e.action}</span>
                      </>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>

          {/* Pagination */}
          {data.meta.last_page > 1 && (
            <div className="flex items-center justify-between mt-3 text-xs text-muted">
              <span>
                Page <span className="font-mono">{data.meta.current_page}</span> of{' '}
                <span className="font-mono">{data.meta.last_page}</span>
              </span>
              <div className="flex gap-1">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={data.meta.current_page <= 1}
                  onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) - 1 }))}
                >
                  Prev
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={data.meta.current_page >= data.meta.last_page}
                  onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
