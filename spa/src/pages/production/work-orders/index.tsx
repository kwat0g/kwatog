import { useMemo } from 'react';
import { usePermission } from '@/hooks/usePermission';
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
import { productionDateTime, scheduleDate } from '@/components/production/scheduleDates';
import { workOrderStatusVariant as variant } from '@/lib/statusVariants';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';
import type { WorkOrder } from '@/types/production';

const DEFAULT_FILTERS: WorkOrderListParams = {
  page: 1,
  per_page: 25,
  status: '',
  sort: 'created_at',
  direction: 'desc',
};

/** Local midnight — "late" means the calendar day the plant is working. */
function startOfToday(): number {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  return d.getTime();
}

type ReasonKind = 'stuck' | 'overdue' | 'late-start' | 'rework';

const REWORK_LABELS = {
  required: 'Rework Required',
  in_progress: 'Rework In Progress',
  awaiting_qc: 'Rework Awaiting QC',
};

/**
 * Why this WO needs a decision, at most one reason per row.
 *
 * Order is precedence, not preference: a paused WO that is also overdue is
 * surfaced as paused, because the pause is the actionable fact and the reason
 * text names who stopped it.
 */
function reasonFor(w: WorkOrder, today: number): { kind: ReasonKind; text: string } | null {
  if (w.status === 'closed' || w.status === 'cancelled') return null;
  if (w.rework_status && w.rework_status.state !== 'awaiting_qc') {
    return {
      kind: 'rework',
      text: `${formatInt(w.rework_status.quantity)} pieces ${w.rework_status.state === 'required' ? 'need repair. Open the work order to start rework.' : 'under repair. Open the work order to continue rework.'}`,
    };
  }
  if (w.status === 'completed') return null;

  const at = (iso: string | null) => (iso ? new Date(iso).getTime() : NaN);

  if (w.status === 'paused') {
    return {
      kind: 'stuck',
      text: w.pause_reason?.trim() || 'Paused with no reason recorded',
    };
  }
  const timing = w.schedule_status ?? w.schedule_warning;
  if (timing && timing.kind !== 'awaiting_confirmation' && timing.kind !== 'start_available') {
    return {
      kind: timing.kind === 'start_overdue' ? 'late-start' : 'overdue',
      text: timing.message,
    };
  }
  if (at(w.planned_end) < today) {
    return {
      kind: 'overdue',
      text: `Production due ${scheduleDate(w.planned_end, 'Asia/Manila')}`,
    };
  }
  if (
    !w.mrp_generated &&
    (w.status === 'planned' || w.status === 'confirmed') &&
    at(w.planned_start) < today
  ) {
    return {
      kind: 'late-start',
      text: `Planned start ${scheduleDate(w.planned_start, 'Asia/Manila')}`,
    };
  }
  return null;
}

export default function WorkOrdersListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  // Bound to the URL so dashboard drill-downs (?status=in_progress) arrive
  // pre-filtered and the browser back button restores the previous view.
  const [filters, setFilters] = useUrlFilters<WorkOrderListParams>(DEFAULT_FILTERS);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['production', 'work-orders', filters],
    queryFn: () => workOrdersApi.list(filters),
    placeholderData: (prev) => prev,
    refetchInterval: 60_000,
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
    {
      key: 'wo',
      header: 'WO #',
      cell: (r) => {
        const timing = r.schedule_status ?? r.schedule_warning;
        const informational =
          timing?.kind === 'awaiting_confirmation' || timing?.kind === 'start_available';
        const label =
          timing?.kind === 'awaiting_confirmation'
            ? 'Awaiting confirmation'
            : timing?.kind === 'start_available'
              ? 'Start available'
              : timing?.kind === 'proposal_expired'
                ? 'Proposal passed'
                : timing?.kind === 'start_overdue'
                  ? 'Start overdue'
                  : 'Schedule passed';
        return (
          <div className="flex flex-col items-start gap-1 py-1">
            <span className="font-mono">{r.wo_number}</span>
            {r.production_purpose === 'stock' && <Chip variant="info">For stock</Chip>}
            {timing && (
              <span title={timing.message}>
                <Chip variant={informational ? 'info' : 'warning'}>
                  {!informational && <LuTriangleAlert size={12} className="mr-1" aria-hidden />}
                  {label}
                </Chip>
              </span>
            )}
          </div>
        );
      },
    },
    {
      key: 'created_at',
      header: 'Created',
      sortable: true,
      cell: (r) => <NumCell>{productionDateTime(r.created_at)}</NumCell>,
    },
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
          {r.qc_workflow === 'finish_first_v1' && r.status === 'completed' && (
            <span className="text-xs text-muted">Production finished</span>
          )}
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
      key: 'planned_end',
      header: 'Production due',
      sortable: true,
      align: 'right',
      cell: (r) => (
        <NumCell>{r.planned_end ? scheduleDate(r.planned_end, 'Asia/Manila') : '—'}</NumCell>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip
          variant={
            r.rework_status && !['closed', 'cancelled'].includes(r.status)
              ? 'warning'
              : variant[r.status]
          }
        >
          {r.rework_status && !['closed', 'cancelled'].includes(r.status)
            ? REWORK_LABELS[r.rework_status.state]
            : (statusLabels.get(r.status) ?? r.status_label ?? r.status)}
        </Chip>
      ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'production_purpose', label: 'Purpose', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'stock', label: 'For stock' }, { value: 'order', label: 'Sales Order' }, { value: 'internal', label: 'Internal' },
    ] },
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
        actions={can('production.wo.create') ? <Button onClick={() => navigate('/production/work-orders/create')}>Produce for stock</Button> : undefined}
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
            {/* Attention is computed from the current table page, not every work order. */}
            <span className="text-xs text-muted">
              {attention.length} of {rows.length} on this page
            </span>
          </div>
          <div
            role="region"
            aria-label="Work orders needing attention"
            tabIndex={0}
            className={cn(
              'grid max-h-52 grid-cols-1 gap-2 overflow-y-auto overscroll-contain p-1 md:grid-cols-2 xl:grid-cols-3',
              focusRing,
            )}
          >
            {attention.map(({ wo, reason }) => (
              <AttentionCard
                key={wo.id}
                wo={wo}
                reason={reason}
                onOpen={() => navigate(`/production/work-orders/${wo.id}`)}
              />
            ))}
          </div>
          {attention.length > 3 && (
            <p className="mt-1 text-xs text-muted">Scroll inside to see more.</p>
          )}
        </section>
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="factory"
          title="No work orders yet"
          description="Plan production for inventory, or confirm a Sales Order to produce its shortage."
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
            onSort={(sort, direction) => setFilters((f) => ({ ...f, sort, direction, page: 1 }))}
            currentSort={filters.sort}
            currentDirection={filters.direction}
          />
        </div>
      )}
    </div>
  );
}

const TONE: Record<ReasonKind, { accent: string; icon: typeof LuTriangleAlert; label: string }> = {
  rework: { accent: 'text-warning', icon: LuTriangleAlert, label: 'Rework' },
  stuck: { accent: 'text-warning', icon: LuCirclePause, label: 'Paused' },
  overdue: { accent: 'text-danger', icon: LuTriangleAlert, label: 'Overdue' },
  'late-start': { accent: 'text-warning', icon: LuClock, label: 'Late start' },
};

/** Keep each decision compact; full progress and details remain in the table and record. */
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
  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        'w-full min-w-0 self-start text-left rounded-md border border-default bg-canvas px-3 py-2',
        'cursor-pointer hover:bg-elevated transition-colors duration-fast',
        focusRing,
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span
          className="min-w-0 truncate font-mono tabular-nums text-xs font-medium"
          title={wo.wo_number}
        >
          {wo.wo_number}
        </span>
        <span className={cn('flex shrink-0 items-center gap-1 text-xs', tone.accent)}>
          <Icon className="w-3.5 h-3.5" aria-hidden />
          {tone.label}
        </span>
      </div>

      <div className="mt-1 flex items-center justify-between gap-2 text-xs">
        <span className="min-w-0 truncate">
          {wo.product?.part_number ?? '—'}
          {wo.machine?.machine_code && (
            <span className="text-muted"> · {wo.machine.machine_code}</span>
          )}
        </span>
        <span className="shrink-0 font-mono tabular-nums text-muted">
          {formatInt(wo.quantity_produced)} / {formatInt(wo.quantity_target)}
        </span>
      </div>

      <p className="mt-1 text-xs text-secondary line-clamp-2">{reason.text}</p>
    </button>
  );
}
