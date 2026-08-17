/**
 * Work order board — production floor triage, not a table of records.
 *
 * The generic list scaffold (FilterBar + DataTable + Skeleton + Empty) is right
 * for 40 other resources in this ERP, and it was wrong here. A production
 * manager does not read work orders alphabetically or by creation date; they
 * arrive asking three questions in a fixed order:
 *
 *   1. What is late, or about to be?   — schedule risk
 *   2. What is stuck?                  — paused, with a reason someone wrote
 *   3. What is actually running?       — everything else, in the table
 *
 * A paginated table answers none of those without the reader doing the sorting
 * in their head. So the urgent slice is lifted out and rendered as cards where
 * the progress figure — the thing being judged — carries the type scale.
 *
 * The triage is computed from the CURRENT PAGE and says so. That is a real
 * limitation, stated rather than hidden: `planned_end`, `pause_reason` and
 * `quantity_produced` all arrive per row, and there is no server-side "at risk"
 * aggregate to ask for yet. The previous version rendered page-scoped counts as
 * headline KPIs whose `linkTo` re-filtered the whole dataset, so the number
 * changed after you clicked it. Better to scope the claim than to fake the sum.
 */
import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuTriangleAlert, LuCirclePause, LuClock } from '@/lib/icons';
import { workOrdersApi, type WorkOrderListParams } from '@/api/production/workOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatInt } from '@/lib/formatNumber';
import { workOrderStatusVariant as variant } from '@/lib/statusVariants';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';
import type { WorkOrder } from '@/types/production';

const DEFAULT_FILTERS: WorkOrderListParams = {
  page: 1,
  per_page: 25,
  status: 'in_progress',
};

/** Local midnight — "late" means the calendar day the plant is working. */
function startOfToday(): number {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  return d.getTime();
}

type ReasonKind = 'stuck' | 'overdue' | 'late-start';

/**
 * Why this WO needs a decision, at most one reason per row.
 *
 * Order is precedence, not preference: a paused WO that is also overdue is
 * surfaced as paused, because the pause is the actionable fact and the reason
 * text names who stopped it.
 */
function reasonFor(w: WorkOrder, today: number): { kind: ReasonKind; text: string } | null {
  if (w.status === 'completed' || w.status === 'closed' || w.status === 'cancelled') return null;

  const at = (iso: string | null) => (iso ? new Date(iso).getTime() : NaN);

  if (w.status === 'paused') {
    return {
      kind: 'stuck',
      text: w.pause_reason?.trim() || 'Paused with no reason recorded',
    };
  }
  if (at(w.planned_end) < today) {
    return { kind: 'overdue', text: `Due ${w.planned_end?.slice(0, 10) ?? '—'}` };
  }
  if ((w.status === 'planned' || w.status === 'confirmed') && at(w.planned_start) < today) {
    return {
      kind: 'late-start',
      text: `Should have started ${w.planned_start?.slice(0, 10) ?? '—'}`,
    };
  }
  return null;
}

export default function WorkOrdersListPage() {
  const navigate = useNavigate();
  // Bound to the URL so dashboard drill-downs (?status=in_progress) arrive
  // pre-filtered and the browser back button restores the previous view.
  const [filters, setFilters] = useUrlFilters<WorkOrderListParams>(DEFAULT_FILTERS);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['production', 'work-orders', filters],
    queryFn: () => workOrdersApi.list(filters),
    placeholderData: (prev) => prev,
  });
  const { data: workOrderOptions } = useQuery({
    queryKey: ['production', 'work-orders', 'options'],
    queryFn: workOrdersApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const statusLabels = new Map(
    (workOrderOptions?.statuses ?? []).map((option) => [option.value, option.label]),
  );

  // `data.data ?? []` allocates a fresh array on every render, which would make
  // the triage memo below recompute each time. Memo the identity too.
  const rows = useMemo(() => data?.data ?? [], [data]);
  const attention = useMemo(() => {
    const today = startOfToday();
    return rows
      .map((w) => ({ wo: w, reason: reasonFor(w, today) }))
      .filter((x): x is { wo: WorkOrder; reason: { kind: ReasonKind; text: string } } =>
        Boolean(x.reason),
      );
  }, [rows]);

  const columns: Column<WorkOrder>[] = [
    { key: 'wo', header: 'WO #', cell: (r) => <span className="font-mono">{r.wo_number}</span> },
    {
      key: 'product',
      header: 'Product',
      cell: (r) =>
        r.product ? (
          <div>
            <div className="font-mono text-xs">{r.product.part_number}</div>
            <div className="text-muted text-xs">{r.product.name}</div>
          </div>
        ) : (
          '—'
        ),
    },
    {
      key: 'so',
      header: 'SO',
      cell: (r) =>
        r.sales_order ? (
          <span className="font-mono">{r.sales_order.so_number}</span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'machine',
      header: 'Machine',
      cell: (r) =>
        r.machine ? (
          <span className="font-mono text-xs">{r.machine.machine_code}</span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'qty',
      header: 'Target',
      align: 'right',
      cell: (r) => <NumCell>{formatInt(r.quantity_target)}</NumCell>,
    },
    {
      key: 'progress',
      header: 'Progress',
      align: 'right',
      cell: (r) => (
        <div className="flex flex-col items-end gap-0.5 min-w-[120px]">
          <span className="font-mono tabular-nums text-xs">
            {formatInt(r.quantity_produced)} / {formatInt(r.quantity_target)}
          </span>
          <div className="w-full h-1 bg-elevated rounded-full overflow-hidden">
            <div
              className="h-1 bg-accent rounded-full"
              style={{ width: `${Math.min(100, r.progress_percentage)}%` }}
              aria-hidden
            />
          </div>
        </div>
      ),
    },
    {
      key: 'planned',
      header: 'Planned end',
      align: 'right',
      cell: (r) => <NumCell>{r.planned_end?.slice(0, 10) ?? '—'}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={variant[r.status]}>
          {statusLabels.get(r.status) ?? r.status_label ?? r.status}
        </Chip>
      ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(workOrderOptions?.statuses ?? [])],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Work orders"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'WO' : 'WOs'}` : undefined}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search WO number or product…"
      />

      {isLoading && !data && <SkeletonTable columns={8} rows={8} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load work orders"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {attention.length > 0 && (
        <section className="px-5 pt-4" aria-labelledby="wo-attention">
          <div className="flex items-baseline justify-between mb-2">
            <h2 id="wo-attention" className="text-sm font-medium text-primary">
              Needs attention
            </h2>
            {/* Scope stated plainly rather than implied — see the file header. */}
            <span className="text-xs text-muted">
              {attention.length} of {rows.length} on this page
            </span>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            {attention.map(({ wo, reason }) => (
              <AttentionCard
                key={wo.id}
                wo={wo}
                reason={reason}
                onOpen={() => navigate(`/production/work-orders/${wo.id}`)}
              />
            ))}
          </div>
        </section>
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="factory"
          title="No work orders yet"
          description="Work orders are auto-created by the MRP engine when a sales order is confirmed."
        />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="work-orders"
            onRowClick={(r) => navigate(`/production/work-orders/${r.id}`)}
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
          />
        </div>
      )}
    </div>
  );
}

const TONE: Record<ReasonKind, { accent: string; icon: typeof LuTriangleAlert; label: string }> = {
  stuck: { accent: 'text-warning', icon: LuCirclePause, label: 'Paused' },
  overdue: { accent: 'text-danger', icon: LuTriangleAlert, label: 'Overdue' },
  'late-start': { accent: 'text-warning', icon: LuClock, label: 'Late start' },
};

/**
 * One work order that needs a decision.
 *
 * Deliberately not a table row. The reason it surfaced is stated in words, not
 * implied by a red cell, and the left border carries severity alongside an icon
 * and a text label so the meaning survives when colour does not.
 */
function AttentionCard({
  wo,
  reason,
  onOpen,
}: {
  wo: WorkOrder;
  reason: { kind: ReasonKind; text: string };
  onOpen: () => void;
}) {
  const tone = TONE[reason.kind];
  const Icon = tone.icon;
  const pct = Math.min(100, wo.progress_percentage);

  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        'w-full text-left rounded-md border border-default bg-canvas p-3',
        'cursor-pointer hover:bg-elevated transition-colors duration-fast',
        focusRing,
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="font-mono tabular-nums text-sm font-medium">{wo.wo_number}</span>
        <span className={cn('flex shrink-0 items-center gap-1 text-xs', tone.accent)}>
          <Icon className="w-3.5 h-3.5" aria-hidden />
          {tone.label}
        </span>
      </div>

      <div className="mt-1 truncate text-sm">
        {wo.product?.part_number ?? '—'}
        {wo.machine?.machine_code && (
          <span className="text-muted"> · {wo.machine.machine_code}</span>
        )}
      </div>

      <p className="mt-1 text-xs text-secondary line-clamp-2">{reason.text}</p>

      <div className="mt-2 flex items-baseline gap-1.5">
        <span className="font-mono tabular-nums text-lg font-medium">
          {formatInt(wo.quantity_produced)}
        </span>
        <span className="text-xs text-muted">/ {formatInt(wo.quantity_target)}</span>
        <span className="ml-auto font-mono tabular-nums text-xs text-muted">{pct}%</span>
      </div>
      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-elevated">
        <div className="h-full rounded-full bg-accent" style={{ width: `${pct}%` }} aria-hidden />
      </div>
    </button>
  );
}
