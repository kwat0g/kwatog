import { formatDateTime } from '@/lib/formatDate';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPlay, LuRefreshCw, LuSearch } from '@/lib/icons';
import { schedulerApi } from '@/api/mrp/scheduler';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Tabs } from '@/components/ui/Tabs';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { GanttChart } from '@/components/production/GanttChart';
import {
  offsetScheduleDate,
  scheduleDate,
  scheduleDateKey,
  scheduleTime,
} from '@/components/production/scheduleDates';
import { useDebounce } from '@/hooks/useDebounce';
import { usePermission } from '@/hooks/usePermission';
import { localIsoDate } from '@/lib/formatDate';
import { formatQuantity } from '@/lib/formatNumber';
import type { GanttBar, GanttRow, SchedulerConflict } from '@/types/mrp';

type ScheduleEntry = GanttBar & {
  machine_code: string;
  current_owner?: GanttRow['current_owner'];
};

function errorMessage(error: unknown, fallback: string): string {
  return (
    (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
    (error instanceof Error ? error.message : fallback)
  );
}

export default function ProductionSchedulePage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canRun = can('mrp.schedule');
  const [view, setView] = useState<'timeline' | 'list'>('timeline');
  const [zoom, setZoom] = useState<'Day' | 'Week' | 'Month'>('Week');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [machine, setMachine] = useState('');
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [runReview, setRunReview] = useState(false);
  const [conflicts, setConflicts] = useState<SchedulerConflict[]>([]);
  const [outcomeKind, setOutcomeKind] = useState('success');
  const [operationError, setOperationError] = useState<string | null>(null);
  const [outcome, setOutcome] = useState<string | null>(null);
  const options = useQuery({
    queryKey: ['mrp', 'scheduler', 'options'],
    queryFn: schedulerApi.options,
    staleTime: 300_000,
  });
  useEffect(() => {
    if (!from && !to && options.data) {
      const today = localIsoDate();
      setFrom(today);
      setTo(offsetScheduleDate(today, options.data.default_horizon_days));
    }
  }, [from, to, options.data]);
  const span =
    from && to ? (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000 : 0;
  const dateError =
    !from || !to
      ? 'Choose both dates to load the schedule.'
      : to < from
        ? 'End date must be on or after start date.'
        : span > 92
          ? 'Choose a date range of 93 days or fewer.'
          : null;
  const snapshot = useQuery({
    queryKey: ['mrp', 'scheduler', 'snapshot', from, to],
    queryFn: () => schedulerApi.snapshot(from, offsetScheduleDate(to, 1)),
    enabled: !dateError,
    refetchInterval: 60_000,
  });
  const timezone = snapshot.data?.timezone ?? 'Asia/Manila';
  const rows = useMemo(
    () =>
      (snapshot.data?.rows ?? [])
        .filter((row) => !machine || row.machine_id === machine)
        .map((row) => ({
          ...row,
          bars: row.bars.filter(
            (bar) =>
              (!status || bar.status === status) &&
              [
                bar.wo_number,
                bar.product_name,
                bar.product_part_number,
                bar.mold_code,
                bar.sales_order?.so_number,
                row.machine_code,
              ]
                .join(' ')
                .toLowerCase()
                .includes(debouncedSearch.trim().toLowerCase()),
          ),
        }))
        .filter((row) => row.bars.length > 0),
    [snapshot.data, machine, status, debouncedSearch],
  );
  const entries = useMemo(
    () =>
      rows
        .flatMap((row) => row.bars.map((bar) => ({
          ...bar,
          machine_code: row.machine_code,
          current_owner: row.current_owner,
        })))
        .sort((a, b) => a.start.localeCompare(b.start)),
    [rows],
  );
  const pending = entries.filter(
    (bar) =>
      bar.status === 'pending' &&
      bar.wo_status === 'planned' &&
      bar.wo_id &&
      bar.reserves_capacity !== false,
  );
  const displayedConflicts = snapshot.data?.conflicts ?? conflicts;
  const ready = !!snapshot.data && !snapshot.isError && !snapshot.isFetching && !dateError;
  const run = useMutation({
    mutationFn: () => schedulerApi.run(),
    onSuccess: (result) => {
      setRunReview(false);
      setConflicts(result.conflicts);
      setOutcomeKind(
        result.conflicts.length ? (result.scheduled.length ? 'warning' : 'danger') : 'success',
      );
      setOperationError(null);
      setOutcome(
        `${result.scheduled.length} schedules proposed; ${result.conflicts.length} work orders need attention. Proposals still need approval.`,
      );
      queryClient.invalidateQueries({ queryKey: ['mrp', 'scheduler', 'snapshot'] });
    },
    onError: (error) =>
      setOperationError(
        errorMessage(error, 'Scheduler could not finish. Refresh schedules before trying again.'),
      ),
  });
  const busy = run.isPending;
  const resetFilters = () => {
    setMachine('');
    setStatus('');
    setSearch('');
  };
  const showError = operationError && !runReview;

  const columns: Column<ScheduleEntry>[] = [
    {
      key: 'work-order', header: 'Work order / product', pinned: 'left', togglable: false,
      width: 280, minWidth: 220, maxWidth: 560,
      cell: (bar) => (
        <div className="min-w-0 space-y-1">
          {bar.wo_id ? (
            <Link className="inline-flex min-h-11 max-w-full items-center truncate whitespace-nowrap font-mono font-medium tabular-nums text-link hover:underline xl:min-h-0" title={bar.wo_number ?? undefined} to={`/production/work-orders/${bar.wo_id}`}>
              {bar.wo_number ?? 'Work order unavailable'}
            </Link>
          ) : <span className="truncate whitespace-nowrap font-mono tabular-nums" title={bar.wo_number ?? undefined}>{bar.wo_number ?? 'Work order unavailable'}</span>}
          <p
            className="truncate whitespace-nowrap text-secondary"
            title={[bar.product_part_number, bar.product_name].filter(Boolean).join(' · ')}
          >
            {bar.product_part_number ? `${bar.product_part_number} · ` : ''}
            {bar.product_name ?? 'Product unavailable'}
          </p>
        </div>
      ),
    },
    {
      key: 'sales-order', header: 'Sales order', width: 150, minWidth: 130, maxWidth: 240,
      cell: (bar) => bar.sales_order ? (
        can('crm.sales_orders.view') ? (
          <Link className="inline-flex min-h-11 max-w-full items-center truncate whitespace-nowrap font-mono tabular-nums text-link hover:underline xl:min-h-0" title={bar.sales_order.so_number} to={`/crm/sales-orders/${bar.sales_order.id}`}>
            {bar.sales_order.so_number}
          </Link>
        ) : <span className="truncate whitespace-nowrap font-mono tabular-nums" title={bar.sales_order.so_number}>{bar.sales_order.so_number}</span>
      ) : <span className="text-secondary">—</span>,
    },
    {
      key: 'quantity', header: 'Quantity', align: 'right', width: 140, minWidth: 112, maxWidth: 180,
      cell: (bar) => <span className="tabular-nums">{formatQuantity(bar.quantity_target)}</span>,
    },
    {
      key: 'machine-mold', header: 'Machine / mold', width: 180, minWidth: 160, maxWidth: 260,
      cell: (bar) => (
        <div className="min-w-0 space-y-1" title={[bar.machine_code, bar.mold_code ?? 'No mold assigned', bar.current_owner && `Current owner: ${bar.current_owner.wo_number}`].filter(Boolean).join(' · ')}>
          <div className="truncate whitespace-nowrap font-mono tabular-nums">
            {bar.machine_code} · {bar.mold_code ?? 'No mold assigned'}
          </div>
          {bar.current_owner && (
            <div className="truncate whitespace-nowrap text-caption text-secondary">Current owner: {bar.current_owner.wo_number}</div>
          )}
        </div>
      ),
    },
    {
      key: 'scheduled-time', header: 'Scheduled time', width: 190, minWidth: 175, maxWidth: 260,
      cell: (bar) => (
        <div className="tabular-nums">
          <div className="whitespace-nowrap">{scheduleDate(bar.start, timezone)}</div>
          <div className="whitespace-nowrap text-secondary">
            {scheduleTime(bar.start, timezone)}–{scheduleTime(bar.end, timezone)}
            {scheduleDateKey(bar.start, timezone) !== scheduleDateKey(bar.end, timezone)
              ? ` · ends ${scheduleDate(bar.end, timezone)}` : ''}
          </div>
        </div>
      ),
    },
    {
      key: 'schedule-status', header: 'Schedule status', width: 160, minWidth: 145, maxWidth: 240,
      cell: (bar) => (
        <div className="space-y-1">
          <Chip variant={bar.status === 'pending' ? 'warning' : 'info'}>
            {bar.status === 'pending' ? 'Awaiting approval' : bar.status === 'executed' ? 'Executed' : 'Confirmed'}
          </Chip>
          {bar.status === 'pending' && bar.wo_status !== 'planned' && (
            <p className="max-w-40 truncate whitespace-nowrap text-caption text-danger-fg" title="WO already changed. Schedule needs review.">WO changed · review</p>
          )}
        </div>
      ),
    },
    {
      key: 'work-order-status', header: 'Work order', width: 140, minWidth: 120, maxWidth: 220,
      cell: (bar) => <span className="block truncate whitespace-nowrap capitalize text-secondary" title={bar.wo_status?.replaceAll('_', ' ') ?? 'Unavailable'}>{bar.wo_status?.replaceAll('_', ' ') ?? 'Unavailable'}</span>,
    },
    {
      key: 'crew-readiness', header: 'Crew / readiness', width: 260, minWidth: 220, maxWidth: 500,
      cell: (bar) => bar.staffing ? (() => {
        const blockers = [...new Set([...bar.staffing.blockers, ...(bar.staffing.execution_blockers ?? [])])];
        const workerNames = bar.staffing.worker_names.join(', ') || 'Crew not assigned';
        const detail = [
          `${bar.staffing.worker_count} workers · minimum ${bar.staffing.minimum_workers}`,
          workerNames,
          bar.staffing.recorder_name && `Recorder: ${bar.staffing.recorder_name}`,
          bar.reserves_capacity === false && 'Capacity released · history',
          ...blockers,
        ].filter(Boolean).join(' · ');
        return (
          <div className="min-w-0 max-w-80 space-y-1" title={detail}>
            <p className={`truncate whitespace-nowrap ${blockers.length ? 'text-warning-fg' : ''}`}>
              {bar.staffing.worker_count} / {bar.staffing.minimum_workers} workers
              {blockers.length ? ' · Needs review' : bar.reserves_capacity === false ? ' · History' : ''}
            </p>
            <p className="truncate whitespace-nowrap text-secondary">{workerNames}</p>
          </div>
        );
      })() : <span className="text-secondary">Readiness unavailable</span>,
    },
  ];

  return (
    <PageShell viewport className="flex flex-col gap-4 max-md:overflow-y-auto">
      <PageHeader
        inset={false}
        title="Production schedule"
        subtitle="Review machine capacity, production timing, and worker readiness."
        refreshingQueryKey={['mrp', 'scheduler', 'snapshot']}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              icon={<LuRefreshCw size={14} />}
              disabled={busy || !!dateError}
              loading={snapshot.isFetching}
              onClick={() => snapshot.refetch()}
            >
              Refresh
            </Button>
            {canRun && (
              <Button
                variant="primary"
                icon={<LuPlay size={14} />}
                disabled={busy || !ready}
                onClick={() => {
                  setOperationError(null);
                  setRunReview(true);
                }}
              >
                Run scheduler
              </Button>
            )}
          </div>
        }
      />
      <div
        data-layout="filter-bar"
        role="search"
        className="grid shrink-0 grid-cols-2 items-end gap-3 xl:grid-cols-6"
      >
        <Input
          label="Find schedule"
          placeholder="Work order, sales order, product, or mold…"
          prefix={<LuSearch size={14} />}
          containerClassName="col-span-2 xl:col-span-2"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          disabled={busy}
        />
        <Select
          label="Machine"
          searchable
          value={machine}
          onChange={(event) => setMachine(event.target.value)}
          disabled={busy || !snapshot.data}
        >
          <option value="">All machines</option>
          {(snapshot.data?.rows ?? []).map((row) => (
            <option key={row.machine_id} value={row.machine_id}>
              {row.machine_code} — {row.name}
            </option>
          ))}
        </Select>
        <Select
          label="Schedule status"
          value={status}
          onChange={(event) => setStatus(event.target.value)}
          disabled={busy}
        >
          <option value="">All statuses</option>
          <option value="pending">Awaiting approval</option>
          <option value="confirmed">Confirmed</option>
          <option value="executed">Executed</option>
        </Select>
        <Input
          type="date"
          label="From date"
          value={from}
          onChange={(event) => setFrom(event.target.value)}
          disabled={busy}
        />
        <Input
          type="date"
          label="Through date"
          value={to}
          onChange={(event) => setTo(event.target.value)}
          disabled={busy}
        />
      </div>
      {(options.isError || (dateError && (from || to)) || snapshot.isError || showError || outcome) && (
        <div className="flex shrink-0 flex-col gap-2">
          {options.isError && (
            <div role="alert" className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-danger bg-danger-bg px-4 py-3 text-sm text-danger-fg">
              <span>Schedule options could not be loaded.</span>
              <Button onClick={() => options.refetch()}>Retry options</Button>
            </div>
          )}
          {dateError && (from || to) && <p role="alert" className="text-sm text-danger-fg">{dateError}</p>}
          {snapshot.isError && (
            <div role="alert" className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-danger bg-danger-bg px-4 py-3 text-sm text-danger-fg">
              <span>Schedule could not be loaded. Retry to see the latest proposals.</span>
              <Button onClick={() => snapshot.refetch()}>Retry schedule</Button>
            </div>
          )}
          {showError && <p role="alert" className="rounded-md border border-danger bg-danger-bg p-3 text-sm text-danger-fg">{operationError}</p>}
          {outcome && (
            <p role="status" className={`rounded-md border px-4 py-3 text-sm ${outcomeKind === 'danger' ? 'border-danger bg-danger-bg text-danger-fg' : outcomeKind === 'warning' ? 'border-warning bg-warning-bg text-warning-fg' : 'border-success bg-success-bg text-success-fg'}`}>
              {outcome}
            </p>
          )}
        </div>
      )}
      {pending.length > 0 && (
        <p className="shrink-0 text-sm text-secondary">
          Review assigned workers and confirm each proposal from its work-order record.
        </p>
      )}
      {displayedConflicts.length > 0 && (
        <Panel
          className="shrink-0"
          bodyClassName="max-h-36 overflow-y-auto"
          title="Work orders needing scheduling review"
          meta={`${displayedConflicts.length} conflicts`}
        >
          <div className="space-y-3">
            {displayedConflicts.map((conflict) => (
              <div key={conflict.work_order_id} className="text-sm">
                <Link
                  className="inline-flex min-h-11 items-center font-mono text-link hover:underline"
                  to={`/production/work-orders/${conflict.work_order_id}`}
                >
                  {conflict.wo_number}
                </Link>
                <p className="text-muted">
                  {[...new Set(conflict.reasons)].map((reason) => reason.replaceAll('_', ' ')).join('; ')}
                  {conflict.checked_at && (
                    <span className="block text-caption">
                      Last checked: {formatDateTime(conflict.checked_at)}
                    </span>
                  )}
                </p>
              </div>
            ))}
          </div>
        </Panel>
      )}
      <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 text-caption text-muted">
        <span className="tabular-nums">
          {entries.length.toLocaleString('en-PH')} schedules · {rows.length.toLocaleString('en-PH')} {rows.length === 1 ? 'machine' : 'machines'}
          {' '}· {pending.length.toLocaleString('en-PH')} awaiting approval · Times: {timezone}
        </span>
        {(machine || status || search) && (
          <Button variant="ghost" size="sm" onClick={resetFilters} disabled={busy}>
            Clear filters
          </Button>
        )}
      </div>
      <div className="flex min-h-0 flex-1 flex-col max-md:flex-none">
        <Tabs
          className="shrink-0"
          label="Schedule views"
          value={view}
          onChange={setView}
          items={[
            { key: 'timeline', label: 'Timeline' },
            { key: 'list', label: 'Schedule list', count: entries.length },
          ]}
        />
        <div
          role="tabpanel"
          aria-label={view === 'timeline' ? 'Timeline' : 'Schedule list'}
          className="flex min-h-0 flex-1 flex-col pt-3 max-md:flex-none"
        >
          {!snapshot.data && (snapshot.isLoading || options.isLoading) ? (
            <div className="min-h-0 h-full overflow-auto rounded-md border border-default">
              <SkeletonTable columns={6} rows={5} />
            </div>
          ) : !dateError && !snapshot.isError && snapshot.data ? (
            view === 'timeline' ? (
              <div className="flex h-full min-h-0 flex-col overflow-hidden rounded-md border border-default bg-canvas max-md:h-auto max-md:min-h-0">
                <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-default px-4 py-3">
                  <p className="text-sm text-secondary">
                    One row per WO. Open a work order to review workers and confirm its schedule.
                  </p>
                  <SegmentedControl
                    label="Timeline zoom"
                    value={zoom}
                    onChange={setZoom}
                    options={[
                      { value: 'Day', label: 'Day' },
                      { value: 'Week', label: 'Week' },
                      { value: 'Month', label: 'Month' },
                    ]}
                  />
                </div>
                <GanttChart
                  className="h-full max-md:h-auto max-md:flex-none"
                  fillHeight
                  rows={rows}
                  from={snapshot.data.from}
                  to={snapshot.data.to}
                  timezone={timezone}
                  viewMode={zoom}
                  onBarClick={(id) => navigate(`/production/work-orders/${id}`)}
                />
              </div>
            ) : entries.length ? (
              <DataTable
                tableKey="production-schedule"
                fillHeight
                fixedColumnLayout
                columns={columns}
                data={entries}
                emptyState={<EmptyState size="compact" icon="calendar" title="No schedules match these filters" />}
              />
            ) : (
              <div className="flex h-full items-center justify-center rounded-md border border-default bg-canvas">
                <EmptyState
                  icon="calendar"
                  title="No schedules match these filters"
                  description="Change the date range or clear filters. Running the scheduler creates proposals for planned work orders."
                />
              </div>
            )
          ) : dateError ? (
            <div className="flex h-full items-center justify-center rounded-md border border-default bg-canvas">
              <EmptyState icon="calendar" title="Choose a valid date range" description={dateError} />
            </div>
          ) : null}
        </div>
      </div>
      <ConfirmDialog
        isOpen={runReview}
        onClose={() => {
          if (!busy) {
            setRunReview(false);
            setOperationError(null);
          }
        }}
        title="Recalculate production schedules?"
        confirmLabel="Run scheduler"
        pending={run.isPending}
        onConfirm={() => {
          if (!busy) run.mutate();
        }}
        description={
          <div className="space-y-3">
            <p>
              This evaluates all planned work orders and replaces their pending proposals. Page
              filters do not limit this action. Confirmed schedules remain committed.
            </p>
            <p>Confirm existing proposals from their work-order records; no rerun is needed.</p>
            {operationError && (
              <p role="alert" className="text-danger-fg">
                {operationError}
              </p>
            )}
          </div>
        }
      />
    </PageShell>
  );
}
