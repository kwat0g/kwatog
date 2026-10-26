import { formatDateTime } from '@/lib/formatDate';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPlay, LuRefreshCw, LuSearch } from '@/lib/icons';
import { schedulerApi } from '@/api/mrp/scheduler';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Tabs } from '@/components/ui/Tabs';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { PageHeader } from '@/components/layout/PageHeader';
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
import type { SchedulerConflict } from '@/types/mrp';

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
        .flatMap((row) => row.bars.map((bar) => ({ ...bar, machine_code: row.machine_code })))
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

  return (
    <div>
      <PageHeader
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
      <div className="space-y-4 px-5 py-4">
        <div className="grid grid-cols-2 items-end gap-3 xl:grid-cols-[minmax(260px,2fr)_minmax(180px,1fr)_minmax(165px,1fr)_150px_150px]">
          <Input
            label="Find schedule"
            placeholder="Work order, sales order, product, or mold…"
            prefix={<LuSearch size={14} />}
            containerClassName="col-span-2 xl:col-span-1"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
            }}
            disabled={busy}
          />
          <Select
            label="Machine"
            searchable
            value={machine}
            onChange={(event) => {
              setMachine(event.target.value);
            }}
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
            onChange={(event) => {
              setStatus(event.target.value);
            }}
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
            onChange={(event) => {
              setFrom(event.target.value);
            }}
            disabled={busy}
          />
          <Input
            type="date"
            label="Through date"
            value={to}
            onChange={(event) => {
              setTo(event.target.value);
            }}
            disabled={busy}
          />
        </div>
        {options.isError && (
          <div
            role="alert"
            className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-danger bg-danger-bg px-4 py-3 text-sm text-danger-fg"
          >
            <span>Schedule options could not be loaded.</span>
            <Button onClick={() => options.refetch()}>Retry options</Button>
          </div>
        )}
        {dateError && (from || to) && (
          <p role="alert" className="text-sm text-danger-fg">
            {dateError}
          </p>
        )}
        {snapshot.isError && (
          <div
            role="alert"
            className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-danger bg-danger-bg px-4 py-3 text-sm text-danger-fg"
          >
            <span>Schedule could not be loaded. Retry to see the latest proposals.</span>
            <Button onClick={() => snapshot.refetch()}>Retry schedule</Button>
          </div>
        )}
        {showError && (
          <p
            role="alert"
            className="rounded-md border border-danger bg-danger-bg p-3 text-sm text-danger-fg"
          >
            {operationError}
          </p>
        )}
        {outcome && (
          <p
            role="status"
            className={`rounded-md border px-4 py-3 text-sm ${outcomeKind === 'danger' ? 'border-danger bg-danger-bg text-danger-fg' : outcomeKind === 'warning' ? 'border-warning bg-warning-bg text-warning-fg' : 'border-success bg-success-bg text-success-fg'}`}
          >
            {outcome}
          </p>
        )}
        {pending.length > 0 && (
          <p className="text-sm text-secondary">
            Review assigned workers and confirm each proposal from its work-order record.
          </p>
        )}
        {displayedConflicts.length > 0 && (
          <Panel
            title="Work orders needing scheduling review"
            meta={`${displayedConflicts.length} conflicts`}
          >
            <div className="space-y-3">
              {displayedConflicts.map((conflict) => (
                <div key={conflict.work_order_id} className="text-sm">
                  <Link
                    className="font-mono text-link hover:underline"
                    to={`/production/work-orders/${conflict.work_order_id}`}
                  >
                    {conflict.wo_number}
                  </Link>
                  <p className="text-muted">
                    {[...new Set(conflict.reasons)]
                      .map((reason) => reason.replaceAll('_', ' '))
                      .join('; ')}
                    {conflict.checked_at && (
                      <span className="block text-2xs">
                        Last checked: {formatDateTime(conflict.checked_at)}
                      </span>
                    )}
                  </p>
                </div>
              ))}
            </div>
          </Panel>
        )}
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted">
          <span>
            {entries.length} schedules · {rows.length} {rows.length === 1 ? 'machine' : 'machines'}{' '}
            · {pending.length} awaiting approval · Times: {timezone}
          </span>
          {(machine || status || search) && (
            <Button variant="ghost" size="sm" onClick={resetFilters} disabled={busy}>
              Clear filters
            </Button>
          )}
        </div>
        <Panel noPadding>
          <Tabs
            label="Schedule views"
            value={view}
            onChange={setView}
            items={[
              { key: 'timeline', label: 'Timeline' },
              { key: 'list', label: 'Schedule list', count: entries.length },
            ]}
          />
          <div role="tabpanel" aria-label={view === 'timeline' ? 'Timeline' : 'Schedule list'}>
            {!snapshot.data && (snapshot.isLoading || options.isLoading) ? (
              <SkeletonTable columns={6} rows={5} />
            ) : !dateError && !snapshot.isError && snapshot.data ? (
              <>
                {view === 'timeline' ? (
                  <>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-default px-4 py-3">
                      <p className="text-xs text-muted">
                        One row per WO. Open a work order to review workers and confirm its
                        schedule.
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
                      rows={rows}
                      from={snapshot.data.from}
                      to={snapshot.data.to}
                      timezone={timezone}
                      viewMode={zoom}
                      onBarClick={(id) => navigate(`/production/work-orders/${id}`)}
                    />
                  </>
                ) : entries.length ? (
                  <>
                    <div className="overflow-x-auto">
                      <table className={`${tableCls} min-w-[960px]`}>
                        <caption className="sr-only">
                          Work order schedules with machine, mold, times, and approval status
                        </caption>
                        <thead>
                          <tr className={theadTrCls}>
                            <Th>Work order / product</Th>
                            <Th>Sales order</Th>
                            <Th align="right">Quantity</Th>
                            <Th>Machine / mold</Th>
                            <Th>Scheduled time</Th>
                            <Th>Schedule</Th>
                            <Th>Work order</Th>
                            <Th>Crew / readiness</Th>
                          </tr>
                        </thead>
                        <tbody>
                          {entries.map((bar) => (
                            <tr key={bar.id} className={trCls}>
                              <Td>
                                {bar.wo_id ? (
                                  <Link
                                    className="font-mono text-link hover:underline"
                                    to={`/production/work-orders/${bar.wo_id}`}
                                  >
                                    {bar.wo_number}
                                  </Link>
                                ) : (
                                  'WO unavailable'
                                )}
                                <div className="max-w-64 text-muted">
                                  {bar.product_part_number ? `${bar.product_part_number} · ` : ''}
                                  {bar.product_name ?? 'Product unavailable'}
                                </div>
                              </Td>
                              <Td>
                                {bar.sales_order ? (
                                  can('crm.sales_orders.view') ? (
                                    <Link
                                      className="font-mono text-link hover:underline"
                                      to={`/crm/sales-orders/${bar.sales_order.id}`}
                                    >
                                      {bar.sales_order.so_number}
                                    </Link>
                                  ) : (
                                    <span className="font-mono">{bar.sales_order.so_number}</span>
                                  )
                                ) : (
                                  '—'
                                )}
                              </Td>
                              <Td align="right" mono>
                                {bar.quantity_target ?? '—'}
                              </Td>
                              <Td>
                                <div className="font-mono">{bar.machine_code}</div>
                                {rows.find((row) => row.machine_code === bar.machine_code)
                                  ?.current_owner && (
                                  <div className="text-2xs text-muted">
                                    Current owner:{' '}
                                    {
                                      rows.find((row) => row.machine_code === bar.machine_code)
                                        ?.current_owner?.wo_number
                                    }
                                  </div>
                                )}
                                <div className="font-mono text-muted">
                                  {bar.mold_code ?? 'No mold assigned'}
                                </div>
                              </Td>
                              <Td>
                                <div className="font-mono">{scheduleDate(bar.start, timezone)}</div>
                                <div className="font-mono text-muted">
                                  {scheduleTime(bar.start, timezone)}–
                                  {scheduleTime(bar.end, timezone)}
                                  {scheduleDateKey(bar.start, timezone) !==
                                  scheduleDateKey(bar.end, timezone)
                                    ? ` · ends ${scheduleDate(bar.end, timezone)}`
                                    : ''}
                                </div>
                              </Td>
                              <Td>
                                <Chip variant={bar.status === 'pending' ? 'warning' : 'info'}>
                                  {bar.status === 'pending'
                                    ? 'Awaiting approval'
                                    : bar.status === 'executed'
                                      ? 'Executed'
                                      : 'Confirmed'}
                                </Chip>
                                {bar.status === 'pending' && bar.wo_status !== 'planned' && (
                                  <p className="mt-1 max-w-40 text-2xs text-danger-fg">
                                    WO already changed. Schedule needs review.
                                  </p>
                                )}
                              </Td>
                              <Td>
                                <span className="text-xs capitalize">
                                  {bar.wo_status?.replaceAll('_', ' ') ?? 'Unavailable'}
                                </span>
                              </Td>
                              <Td>
                                {bar.staffing && (
                                  <div className="max-w-64 text-xs">
                                    <p>
                                      {bar.staffing.worker_count} workers · minimum{' '}
                                      {bar.staffing.minimum_workers}
                                    </p>
                                    <p className="text-muted">
                                      {bar.staffing.worker_names.join(', ') || 'Crew not assigned'}
                                    </p>
                                    {bar.staffing.recorder_name && (
                                      <p>Recorder: {bar.staffing.recorder_name}</p>
                                    )}
                                    {bar.reserves_capacity === false ? (
                                      <p className="text-muted">Capacity released · history</p>
                                    ) : (
                                      [
                                        ...new Set([
                                          ...bar.staffing.blockers,
                                          ...(bar.staffing.execution_blockers ?? []),
                                        ]),
                                      ].map((message) => (
                                        <p key={message} className="mt-1 text-warning-fg">
                                          {message}
                                        </p>
                                      ))
                                    )}
                                  </div>
                                )}
                              </Td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </>
                ) : (
                  <EmptyState
                    icon="calendar"
                    title="No schedules match these filters"
                    description="Change the date range or clear filters. Running the scheduler creates proposals for planned work orders."
                  />
                )}
              </>
            ) : null}
          </div>
        </Panel>
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
    </div>
  );
}
