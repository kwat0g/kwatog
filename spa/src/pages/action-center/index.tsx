import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle,
  BellRing,
  ChevronRight,
  ClipboardCheck,
  Factory,
  ListChecks,
  RefreshCw,
  Search,
  ShieldCheck,
  Truck,
  Wrench,
  type LucideIcon,
} from 'lucide-react';
import { actionCenterApi } from '@/api/actionCenter';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { filterActionItems } from '@/lib/actionCenter';
import { cn } from '@/lib/cn';
import { formatDateTime, formatRelative } from '@/lib/formatDate';
import type { ActionCategory, ActionPriority } from '@/types/actionCenter';

const CATEGORY_META: Record<ActionCategory, { label: string; icon: LucideIcon }> = {
  approval: { label: 'Approvals', icon: ClipboardCheck },
  alert: { label: 'Alerts', icon: BellRing },
  quality: { label: 'Quality', icon: ShieldCheck },
  maintenance: { label: 'Maintenance', icon: Wrench },
  production: { label: 'Production', icon: Factory },
  supply_chain: { label: 'Supply chain', icon: Truck },
};

const PRIORITY_VARIANT: Record<ActionPriority, ChipVariant> = {
  critical: 'danger', high: 'warning', medium: 'info', low: 'neutral',
};

const FILTERS: Array<{ value: ActionCategory | 'all'; label: string }> = [
  { value: 'all', label: 'All work' },
  ...Object.entries(CATEGORY_META).map(([value, meta]) => ({
    value: value as ActionCategory,
    label: meta.label,
  })),
];

export default function ActionCenterPage() {
  const navigate = useNavigate();
  const [category, setCategory] = useState<ActionCategory | 'all'>('all');
  const [search, setSearch] = useState('');
  const query = useQuery({
    queryKey: ['action-center'],
    queryFn: actionCenterApi.get,
    refetchInterval: 60_000,
  });

  const visibleItems = useMemo(
    () => filterActionItems(query.data?.items ?? [], category, search),
    [query.data?.items, category, search],
  );

  const summary = query.data?.summary;

  return (
    <div>
      <PageHeader
        title="Action Center"
        subtitle="One prioritized queue for approvals, quality, and operational exceptions."
        refreshingQueryKey={['action-center']}
        actions={(
          <Button variant="secondary" size="sm" onClick={() => query.refetch()} disabled={query.isFetching}>
            <RefreshCw size={13} className={cn(query.isFetching && 'animate-spin')} />
            Refresh
          </Button>
        )}
      />

      {query.isLoading && <SkeletonTable columns={1} rows={7} />}

      {query.isError && (
        <EmptyState
          icon="alert-circle"
          title="Could not load your action queue"
          description="Your source records are unchanged. Try loading the queue again."
          action={<Button variant="secondary" onClick={() => query.refetch()}>Retry</Button>}
        />
      )}

      {query.data && (
        <div className="p-5 space-y-4">
          <section className="grid grid-cols-2 lg:grid-cols-4 gap-2" aria-label="Action summary">
            <SummaryCard label="Open actions" value={summary?.total ?? 0} icon={ListChecks} />
            <SummaryCard label="Critical" value={summary?.critical ?? 0} icon={AlertTriangle} tone="danger" />
            <SummaryCard label="High priority" value={summary?.high ?? 0} icon={BellRing} tone="warning" />
            <SummaryCard label="Overdue" value={summary?.overdue ?? 0} icon={RefreshCw} tone="danger" />
          </section>

          <section className="rounded-md border border-default bg-canvas">
            <div className="p-3 border-b border-subtle flex flex-col lg:flex-row lg:items-center gap-3">
              <div className="flex gap-1.5 flex-wrap flex-1">
                {FILTERS.map((filter) => {
                  const count = filter.value === 'all'
                    ? summary?.total
                    : summary?.by_category[filter.value];
                  return (
                    <button
                      key={filter.value}
                      type="button"
                      onClick={() => setCategory(filter.value)}
                      aria-pressed={category === filter.value}
                      className={cn(
                        'px-2.5 py-1.5 rounded-md text-xs border transition-colors',
                        category === filter.value
                          ? 'bg-elevated border-default text-default font-medium'
                          : 'border-transparent text-muted hover:text-default hover:bg-elevated',
                      )}
                    >
                      {filter.label}{typeof count === 'number' ? ` · ${count}` : ''}
                    </button>
                  );
                })}
              </div>
              <Input
                aria-label="Search action queue"
                placeholder="Search work, reference, or owner…"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                prefix={<Search size={12} />}
                containerClassName="w-full lg:w-72"
              />
            </div>

            {visibleItems.length === 0 ? (
              <EmptyState
                icon="check-circle"
                title={query.data.items.length === 0 ? 'You are all caught up' : 'No matching actions'}
                description={query.data.items.length === 0
                  ? 'There is no pending work for your current permissions.'
                  : 'Change the category or search term to see more work.'}
              />
            ) : (
              <div className="divide-y divide-subtle">
                {visibleItems.map((item) => {
                  const meta = CATEGORY_META[item.category];
                  const Icon = meta.icon;
                  return (
                    <button
                      type="button"
                      key={item.id}
                      onClick={() => navigate(item.link)}
                      className={cn(
                        'w-full text-left px-3 py-3 flex items-start gap-3 hover:bg-elevated/60 transition-colors group',
                        item.priority === 'critical' && 'border-l-2 border-l-danger',
                        item.priority === 'high' && 'border-l-2 border-l-warning',
                      )}
                    >
                      <span className="mt-0.5 p-1.5 rounded-md bg-elevated text-muted shrink-0">
                        <Icon size={14} />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="flex items-center gap-2 flex-wrap">
                          <span className="text-sm font-medium text-default">{item.title}</span>
                          <Chip variant={PRIORITY_VARIANT[item.priority]}>{item.priority}</Chip>
                          {item.is_overdue && <Chip variant="danger">overdue</Chip>}
                          <Chip variant="neutral">{meta.label}</Chip>
                        </span>
                        <span className="block text-xs text-muted mt-1 line-clamp-2">{item.description}</span>
                        <span className="flex items-center gap-x-3 gap-y-1 flex-wrap mt-1.5 text-2xs text-text-subtle">
                          {item.reference && <span className="font-mono">{item.reference}</span>}
                          <span>{item.status_label}</span>
                          {item.assigned_to
                            ? <span>Assigned: {item.assigned_to.name}</span>
                            : item.owner_label && <span>Source owner: {item.owner_label}</span>}
                          <span>{item.task_state}</span>
                          {item.created_at && (
                            <span title={formatDateTime(item.created_at)}>{formatRelative(item.created_at)}</span>
                          )}
                          {item.due_at && <span title={formatDateTime(item.due_at)}>Due {formatRelative(item.due_at)}</span>}
                        </span>
                      </span>
                      <ChevronRight size={14} className="mt-2 shrink-0 text-text-subtle group-hover:text-default" />
                    </button>
                  );
                })}
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  );
}

function SummaryCard({
  label,
  value,
  icon: Icon,
  tone = 'default',
}: {
  label: string;
  value: number;
  icon: LucideIcon;
  tone?: 'default' | 'danger' | 'warning';
}) {
  return (
    <div className="rounded-md border border-default bg-canvas p-3 flex items-center gap-3">
      <span className={cn(
        'p-2 rounded-md bg-elevated text-muted',
        tone === 'danger' && 'bg-danger-bg text-danger-fg',
        tone === 'warning' && 'bg-warning-bg text-warning-fg',
      )}>
        <Icon size={15} />
      </span>
      <span>
        <span className="block text-xl font-display font-medium text-primary tabular-nums">{value}</span>
        <span className="block text-2xs text-muted">{label}</span>
      </span>
    </div>
  );
}
