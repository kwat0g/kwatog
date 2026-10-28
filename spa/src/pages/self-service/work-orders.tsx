import { TrialProductionPanel } from '@/components/production/TrialProductionPanel';
import { MaterialHandoverNotice } from '@/components/production/MaterialHandoverNotice';
import { MaterialHandoverExceptionDetails } from '@/components/production/MaterialHandoverExceptionDetails';
import { productionDateTime } from '@/components/production/scheduleDates';
import { useCallback, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { workOrdersApi } from '@/api/production/workOrders';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { StickyActionBar } from '@/components/ui/StickyActionBar';
import { Textarea } from '@/components/ui/Textarea';
import { LuTrash2 } from '@/lib/icons';
import { useAuthStore } from '@/stores/authStore';
import { workerCountError } from '@/lib/productionCrewValidation';
import type { WorkerOutputData } from '@/types/production-crew';
import { LateProductionEntry } from '@/components/production/LateProductionReports';
import { useFormDraftAutosave } from '@/hooks/useFormDraftAutosave';
import { usePendingProductionSubmission } from '@/hooks/usePendingProductionSubmission';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { DraftRestoreBanner } from '@/components/ui/DraftRestoreBanner';
import { createRequestKey } from '@/lib/requestKey';
import { workOrderStatusVariant } from '@/lib/statusVariants';

export default function MyWorkOrdersPage() {
  const userId = useAuthStore((state) => state.user?.id);
  const employeeId = useAuthStore((state) => state.user?.employee?.id);
  const [page, setPage] = useState(1);
  const [historyPage, setHistoryPage] = useState(1);
  const orders = useQuery({
    queryKey: ['my-work-orders', userId, page],
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
    queryFn: () => workOrdersApi.myWorkOrders(page),
  });
  const history = useQuery({
    queryKey: ['my-work-order-history', userId, historyPage],
    queryFn: () => workOrdersApi.myWorkOrderHistory(historyPage),
  });
  return (
    <div>
      <PageHeader title="My work orders" backTo="/self-service" backLabel="My dashboard" />
      <div className="px-5 py-4">
        <Panel
          title="Assigned work"
          meta={orders.data ? `${orders.data.meta.total} assignments` : undefined}
        >
          {orders.isLoading && <p role="status">Loading work orders…</p>}
          {orders.isError && (
            <p role="alert">
              Work orders failed to load. <Button onClick={() => orders.refetch()}>Retry</Button>
            </p>
          )}
          {orders.data?.data.length === 0 && (
            <p className="text-sm text-muted">No work orders assigned.</p>
          )}
          <ul className="max-h-[28rem] overflow-y-auto divide-y divide-default">
            {orders.data?.data.map((order) => (
              <li
                key={`${order.id}-${order.repair?.ncr_id ?? 'production'}`}
                className="relative py-3 pl-2 hover:bg-elevated"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <Link
                    className="font-mono text-accent font-medium after:absolute after:inset-0 focus-visible:after:ring-2 focus-visible:after:ring-accent"
                    to={`/self-service/work-orders/${order.id}${order.repair ? `?rework_ncr_id=${order.repair.ncr_id}` : ''}`}
                  >
                    {order.wo_number}
                  </Link>
                  {order.repair && <Chip variant="purple">Repair</Chip>}
                  <Chip variant={workOrderStatusVariant[order.status]}>
                    {order.status.replaceAll('_', ' ')}
                  </Chip>
                </div>
                <p className="mt-1 text-sm">
                  <span className="font-mono">{order.product.part_number}</span> ·{' '}
                  {order.product.name}
                </p>
                <p className="text-sm text-muted">
                  {order.crew.recorder_employee_id === employeeId ||
                  order.can_record ||
                  order.can_record_trial ||
                  order.can_report_late_production
                    ? 'Output recorder'
                    : 'Crew member · View only'}{' '}
                  ·{' '}
                  {order.qc_workflow === 'finish_first_v1'
                    ? (order.remaining_produced ?? order.remaining_good)
                    : order.remaining_good}{' '}
                  {order.qc_workflow === 'finish_first_v1' ? 'pieces' : 'good pieces'} remaining
                </p>
                {order.blocker && (
                  <p className="text-sm text-warning-fg">
                    {order.blocker.message} · {order.blocker.owner}
                  </p>
                )}
              </li>
            ))}
          </ul>
          {orders.data && orders.data.meta.last_page > 1 && (
            <nav
              aria-label="Assigned work pages"
              className="mt-4 flex flex-wrap items-center justify-end gap-2"
            >
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Previous
              </Button>
              <Button
                variant="secondary"
                disabled={page >= orders.data.meta.last_page}
                onClick={() => setPage(page + 1)}
              >
                Next
              </Button>
              <span className="text-xs text-muted">
                Page {page} of {orders.data.meta.last_page}
              </span>
            </nav>
          )}
        </Panel>
        <Panel
          title="Work history"
          meta={history.data ? `${history.data.meta.total} crew assignments` : undefined}
          className="mt-4"
        >
          <p className="mb-2 text-sm text-muted">
            Finished work not recorded on time? Open its crew assignment to report earlier
            production.
          </p>
          {history.isLoading && <p role="status">Loading work history…</p>}
          {history.isError && (
            <p role="alert">
              Work history failed to load. <Button onClick={() => history.refetch()}>Retry</Button>
            </p>
          )}
          {history.data?.data.length === 0 && (
            <p className="text-sm text-muted">No production history.</p>
          )}
          <ul className="max-h-[28rem] overflow-y-auto divide-y divide-default">
            {history.data?.data.map((entry) => (
              <li key={`${entry.work_order.id}-${entry.crew.id}`} className="py-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Link
                    className="font-mono font-medium text-accent hover:underline"
                    to={`/self-service/work-orders/${entry.work_order.id}?crew_id=${entry.crew.id}`}
                  >
                    {entry.work_order.wo_number}
                  </Link>
                  <Chip variant={workOrderStatusVariant[entry.work_order.status]}>
                    {entry.work_order.status.replaceAll('_', ' ')}
                  </Chip>
                </div>
                <p className="mt-1 text-sm text-muted">{entry.crew.members.length} crew members</p>
                <p className="text-sm">
                  {entry.late_production_report_count ?? entry.late_production_reports.length}{' '}
                  earlier production reports ·{' '}
                  {entry.can_report_late_production
                    ? 'You are this crew’s recorder'
                    : 'View your own reports'}
                </p>
              </li>
            ))}
          </ul>
          {history.data && history.data.meta.last_page > 1 && (
            <nav
              aria-label="Work history pages"
              className="mt-3 flex flex-wrap items-center justify-end gap-2"
            >
              <Button
                variant="secondary"
                disabled={historyPage <= 1}
                onClick={() => setHistoryPage(historyPage - 1)}
              >
                Previous history
              </Button>
              <span className="text-xs text-muted">
                Page {historyPage} of {history.data.meta.last_page}
              </span>
              <Button
                variant="secondary"
                disabled={historyPage >= history.data.meta.last_page}
                onClick={() => setHistoryPage(historyPage + 1)}
              >
                Next history
              </Button>
            </nav>
          )}
        </Panel>
      </div>
    </div>
  );
}
export function MyWorkOrderDetailPage() {
  const userId = useAuthStore((state) => state.user?.id);
  const { id } = useParams();
  const [params] = useSearchParams();
  return (
    <MyWorkOrderDetail
      key={`${userId}:${id}:${params.get('rework_ncr_id')}:${params.get('crew_id')}`}
    />
  );
}
function MyWorkOrderDetail() {
  const userId = useAuthStore((state) => state.user?.id);
  const { id } = useParams<{ id: string }>();
  const [params] = useSearchParams();
  const ncrId = params.get('rework_ncr_id') ?? undefined;
  const crewId = params.get('crew_id') ?? undefined;
  const qc = useQueryClient();
  const order = useQuery({
    queryKey: ['my-work-orders', userId, id, ncrId, crewId],
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
    queryFn: () => workOrdersApi.myWorkOrder(id!, ncrId, crewId),
    enabled: !!id,
  });
  const options = useQuery({
    queryKey: ['my-output-options', userId, id, ncrId],
    queryFn: () => workOrdersApi.myOutputOptions(id!, ncrId),
    enabled:
      !!order.data &&
      (!!order.data.repair || order.data.qc_workflow !== 'finish_first_v1') &&
      !order.data.historical_report_only &&
      !order.isError,
  });
  const receipts = useQuery({
    queryKey: ['my-outputs', userId, id, ncrId],
    queryFn: () => workOrdersApi.myOutputs(id!, ncrId),
    enabled: !!order.data && !order.data.historical_report_only && !order.isError,
  });
  const [good, setGood] = useState('');
  const [manualReject, setReject] = useState('0');
  const repairRemaining = order.data?.repair?.remaining_quantity;
  const reject =
    repairRemaining !== undefined
      ? /^\d+$/.test(good) && Number.isSafeInteger(Number(good)) && Number(good) <= repairRemaining
        ? String(repairRemaining - Number(good))
        : '0'
      : manualReject;
  const [defectRows, setDefectRows] = useState<{ defect_type_id: string; count: string }[]>([]);
  const [remarks, setRemarks] = useState('');
  const recovery = usePendingProductionSubmission<WorkerOutputData>(
    `${userId}:${id}:${ncrId ?? 'production'}:output`,
  );
  const { pending } = recovery;
  const [uncertain, setUncertain] = useState(!!pending);
  const [receiptMessage, setReceiptMessage] = useState('');
  const [latePending, setLatePending] = useState(false);
  const dirty = good !== '' || reject !== '0' || defectRows.length > 0 || remarks !== '';
  const values = useRef({ good, reject, defectRows, remarks });
  values.current = { good, reject, defectRows, remarks };
  const getValues = useCallback(() => values.current, []);
  const restoreValues = useCallback((value: Record<string, unknown>) => {
    if (typeof value.good === 'string') setGood(value.good);
    if (typeof value.reject === 'string') setReject(value.reject);
    if (typeof value.remarks === 'string') setRemarks(value.remarks);
    if (Array.isArray(value.defectRows))
      setDefectRows(
        value.defectRows.filter(
          (row) => typeof row?.defect_type_id === 'string' && typeof row?.count === 'string',
        ),
      );
  }, []);
  const draft = useFormDraftAutosave({
    formKey: `worker-output:${id}:${ncrId ?? 'production'}:${order.data?.crew.id ?? crewId ?? 'loading'}`,
    userId,
    getValues,
    setValues: restoreValues,
    enabled: !pending && dirty,
  });
  useUnsavedChangesGuard(dirty || !!pending, true);
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['my-work-orders'] });
    void qc.invalidateQueries({ queryKey: ['my-work-order-history'] });
    void qc.invalidateQueries({ queryKey: ['my-outputs', userId, id, ncrId] });
  };
  const completed = (batch: string) => {
    setReceiptMessage(`Output ${batch} recorded.`);
    draft.discard();
    recovery.clear();
    setUncertain(false);
    setGood('');
    setReject('0');
    setDefectRows([]);
    setRemarks('');
    refresh();
  };
  const submit = useMutation({
    mutationFn: (submission: { data: WorkerOutputData; key: string }) =>
      workOrdersApi.recordMyOutput(id!, submission.data, submission.key, ncrId),
    onSuccess: (receipt) => completed(receipt.batch_code),
    onError: (error) => {
      refresh();
      if (!(error instanceof AxiosError) || !error.response || error.response.status >= 500)
        setUncertain(true);
      else if (!uncertain) {
        recovery.clear();
        setUncertain(false);
      }
    },
  });
  const check = useMutation({
    mutationFn: () => workOrdersApi.myOutputReceipt(id!, pending!.key),
    onSuccess: (receipt) => {
      if (receipt) completed(receipt.batch_code);
      else setReceiptMessage('No receipt found yet. Retry the same recording to resolve it.');
    },
  });
  const data = order.isError ? undefined : order.data;
  const finishFirst = !data?.repair && data?.qc_workflow === 'finish_first_v1';
  const remaining =
    data?.repair?.remaining_quantity ??
    (finishFirst ? (data?.remaining_produced ?? data?.remaining_good) : data?.remaining_good) ??
    0;
  const invalid = finishFirst
    ? !/^\d+$/.test(good) || Number(good) < 1 || !Number.isSafeInteger(Number(good))
      ? 'Enter a positive whole quantity.'
      : workerCountError(good, '0', '', remaining, false)
    : (workerCountError(
        good,
        reject,
        Number(reject) > 0 && defectRows.length > 0 ? 'selected' : '',
        remaining,
        !!data?.repair,
      ) ??
      (defectRows.some(
        (row) =>
          !row.defect_type_id ||
          !/^\d+$/.test(row.count) ||
          Number(row.count) < 1 ||
          !Number.isSafeInteger(Number(row.count)),
      ) || defectRows.reduce((total, row) => total + Number(row.count), 0) !== Number(reject)
        ? 'Defect counts must be positive integers and total the reject count.'
        : new Set(defectRows.map((row) => row.defect_type_id)).size !== defectRows.length
          ? 'Choose each defect type once.'
          : undefined));
  const handoverPending =
    data?.warehouse_handover?.pending ?? data?.warehouse_handover_pending ?? 0;
  const blocked =
    latePending ||
    handoverPending > 0 ||
    !data?.can_record ||
    !!data.blocker ||
    (!finishFirst && !!data.startup_trial && data.startup_trial.state !== 'released') ||
    (!finishFirst && !options.data) ||
    order.isError ||
    (!finishFirst && options.isError);
  const earlierReport = data &&
    (data.can_report_late_production || data.historical_report_only) && (
      <LateProductionEntry
        collapsed={data.can_record && !data.historical_report_only}
        workOrderId={id!}
        key={`${userId}-${id}-${data.crew.id}`}
        crew={data.crew}
        canSubmit={!!data.can_report_late_production && !pending}
        onPendingChange={setLatePending}
        recordingPending={!!pending}
        workOrderStatus={data.status}
        finishFirst={finishFirst}
        reportInterval={data.report_interval}
        batchNumber={data.batch_number}
      />
    );
  return (
    <div>
      <PageHeader
        title={data?.wo_number ?? 'Work order'}
        backTo="/self-service/work-orders"
        backLabel="My work orders"
      />
      <div className="mx-auto max-w-5xl px-4 py-4 space-y-4 sm:px-5">
        {order.isLoading && <p role="status">Loading work order…</p>}
        {order.isError && (
          <p role="alert">
            Work order failed to load.{' '}
            <Button onClick={() => order.refetch()}>Retry work order</Button>
          </p>
        )}
        {recovery.storageError && (
          <p role="alert" className="text-sm text-danger-fg">
            {recovery.storageError}
          </p>
        )}
        {uncertain && pending && (
          <p role="alert" className="text-sm text-warning-fg">
            {'produced_count' in pending.data
              ? `Unconfirmed recording: ${pending.data.produced_count} produced.`
              : `Unconfirmed recording: ${pending.data.good_count} good · ${pending.data.reject_count} rejected.`}{' '}
            Check its receipt or retry the same recording. Do not enter it again.
          </p>
        )}
        {submit.isError && !uncertain && (
          <p role="alert" className="text-sm text-danger-fg">
            {submit.error instanceof AxiosError
              ? (submit.error.response?.data?.message ??
                'Recording failed. Refresh assignment and try again.')
              : 'Recording failed.'}
          </p>
        )}
        {uncertain && pending && (
          <div className="flex flex-wrap gap-2">
            <Button disabled={check.isPending || submit.isPending} onClick={() => check.mutate()}>
              Check receipt
            </Button>
            <Button
              disabled={submit.isPending || check.isPending}
              onClick={() => submit.mutate(pending)}
            >
              Retry same recording
            </Button>
          </div>
        )}
        {check.isError && <p role="alert">Receipt check failed. Retry receipt check.</p>}
        {receiptMessage && <p role="status">{receiptMessage}</p>}
        {latePending && (
          <p role="alert" className="text-sm text-warning-fg">
            Resolve the earlier production report below before recording another batch.
          </p>
        )}
        {data && (
          <>
            <Panel
              title={data.repair ? 'Repair assignment' : 'Production assignment'}
              meta={
                <Chip variant={workOrderStatusVariant[data.status]}>
                  {data.status.replaceAll('_', ' ')}
                </Chip>
              }
              actions={
                <Button
                  className="min-h-11"
                  variant="secondary"
                  onClick={refresh}
                  disabled={order.isFetching}
                >
                  Refresh assignment
                </Button>
              }
            >
              <p className="font-medium">
                <span className="font-mono">{data.product.part_number}</span> · {data.product.name}
              </p>
              <div className="mt-3 grid grid-cols-3 gap-3 border-y border-default py-3 text-sm">
                <div>
                  <p className="text-xs text-muted">
                    {finishFirst ? 'Remaining to produce' : 'Remaining good'}
                  </p>
                  <p className="mt-1 font-mono text-lg">{remaining}</p>
                </div>
                <div>
                  <p className="text-xs text-muted">{finishFirst ? 'Produced' : 'Recorded good'}</p>
                  <p className="mt-1 font-mono text-lg">
                    {finishFirst
                      ? (data.produced_quantity ?? data.quantity_good)
                      : data.quantity_good}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted">{finishFirst ? 'Awaiting QC' : 'Rejected'}</p>
                  <p className="mt-1 font-mono text-lg">
                    {finishFirst ? (data.awaiting_qc_quantity ?? '—') : data.quantity_rejected}
                  </p>
                </div>
              </div>
              {data.report_interval?.started_at && data.report_interval.finished_at && (
                <p className="mt-3 text-xs text-muted tabular-nums">
                  WO schedule: {productionDateTime(data.report_interval.started_at)} –{' '}
                  {productionDateTime(data.report_interval.finished_at)} · Asia/Manila
                </p>
              )}
              <details className="mt-3 border-t border-default pt-2">
                <summary className="min-h-11 cursor-pointer py-2 text-sm text-muted focus-visible:outline-accent">
                  Crew and equipment
                </summary>
                <p className="text-sm">
                  Machine:{' '}
                  {data.machine ? (
                    <>
                      <span className="font-mono">{data.machine.code}</span> · {data.machine.name}
                    </>
                  ) : (
                    'None'
                  )}{' '}
                  · Mold:{' '}
                  {data.mold ? (
                    <>
                      <span className="font-mono">{data.mold.code}</span> · {data.mold.name}
                    </>
                  ) : (
                    'None'
                  )}
                </p>
                <ul className="mt-3 space-y-1 text-sm">
                  {data.crew.members.map((member) => (
                    <li key={member.id}>
                      {member.name} · {member.shift?.name ?? 'No HR shift'}
                      {member.id === data.crew.recorder_employee_id
                        ? ' · Output recorder'
                        : ' · View only'}
                    </li>
                  ))}
                </ul>
              </details>
              {(!data.startup_trial || finishFirst) &&
                data.blocker?.code !== 'material_handover_pending' && (
                  <MaterialHandoverNotice pending={handoverPending} />
                )}
              {data.blocker && (
                <p role="alert" className="mt-3 text-sm text-warning-fg">
                  {data.blocker.message} · Contact {data.blocker.owner}
                </p>
              )}
              {data.can_report_late_production &&
                !data.can_record &&
                !data.can_record_trial &&
                !data.blocker &&
                !data.report_interval?.blocker && (
                  <p className="mt-3 text-sm text-warning-fg">
                    {['completed', 'closed'].includes(data.status)
                      ? 'Production finished. Missing output? Submit an earlier production report below.'
                      : 'Live recording unavailable. Report finished pieces below, or ask your manager to extend the run.'}
                  </p>
                )}
              {!data.can_record &&
                !data.can_record_trial &&
                !data.can_report_late_production &&
                !data.blocker && (
                  <p className="mt-3 text-sm text-muted">
                    View only. Only the assigned output recorder can record quantities.
                  </p>
                )}
            </Panel>
            {data.startup_trial && !finishFirst && !data.repair && data.can_record_trial && (
              <TrialProductionPanel
                key={`${id}-${userId}-${data.crew.id}-${data.startup_trial.inspection_id}`}
                workOrderId={id!}
                trial={data.startup_trial}
                crew={data.crew}
                canRecord={data.can_record_trial ?? data.can_record}
                handoverPending={handoverPending}
                blocked={data.status !== 'in_progress' || !!pending || latePending || order.isError}
              />
            )}
            {(data.can_record ||
              ((data.can_report_late_production || data.can_record_trial) &&
                !data.historical_report_only &&
                (finishFirst ||
                  !data.startup_trial ||
                  data.startup_trial.state === 'released'))) && (
              <form
                className="space-y-3"
                onSubmit={(event) => {
                  event.preventDefault();
                  if (blocked || invalid || pending || order.isFetching) return;
                  const outputData: WorkerOutputData =
                    finishFirst && !data.repair
                      ? {
                          crew_id: data.crew.id,
                          produced_count: Number(good),
                          remarks: remarks || undefined,
                        }
                      : {
                          crew_id: data.crew.id,
                          good_count: Number(good),
                          reject_count: Number(reject),
                          defects: defectRows.map((row) => ({
                            defect_type_id: row.defect_type_id,
                            count: Number(row.count),
                          })),
                          remarks: remarks || undefined,
                        };
                  const submission = { key: createRequestKey(), data: outputData };
                  if (recovery.remember(submission)) submit.mutate(submission);
                }}
              >
                <Panel
                  title={finishFirst ? 'Record production' : 'Record output'}
                  meta={
                    <>
                      <span className="font-mono tabular-nums">{remaining}</span>
                      {` ${finishFirst ? 'pieces' : 'good pieces'} remaining`}
                    </>
                  }
                >
                  <p className="mb-3 text-sm text-muted">
                    Enter only this batch’s new pieces. Previously saved output stays in receipts
                    below.
                  </p>
                  {draft.hasDraft && !pending && (
                    <DraftRestoreBanner
                      ageMs={draft.draftAge}
                      onRestore={draft.restore}
                      onDiscard={draft.discard}
                    />
                  )}
                  <fieldset disabled={!!pending || blocked} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                      <Input
                        aria-label={
                          finishFirst && !data.repair
                            ? 'Quantity made'
                            : data.repair
                              ? 'Repaired pieces'
                              : 'Good count'
                        }
                        label={
                          finishFirst && !data.repair
                            ? 'Quantity made'
                            : data.repair
                              ? 'Repaired pieces'
                              : 'Good count'
                        }
                        type="number"
                        min={0}
                        max={data.repair ? remaining : undefined}
                        step={1}
                        required
                        containerClassName="[&>div]:h-11 sm:[&>div]:h-8"
                        value={good}
                        onChange={(event) => {
                          setGood(event.target.value);
                          if (data.repair && Number(event.target.value) === remaining)
                            setDefectRows([]);
                        }}
                      />
                      {(!finishFirst || data.repair) && (
                        <Input
                          label={data.repair ? 'Unrepairable pieces' : 'Reject count'}
                          aria-label={data.repair ? 'Unrepairable pieces' : 'Reject count'}
                          type="number"
                          min={0}
                          step={1}
                          required
                          readOnly={!!data.repair}
                          containerClassName="[&>div]:h-11 sm:[&>div]:h-8"
                          helper={
                            data.repair
                              ? 'Remaining repair pieces minus repaired pieces.'
                              : undefined
                          }
                          value={reject}
                          onChange={(event) => setReject(event.target.value)}
                        />
                      )}
                    </div>
                    {(!finishFirst || data.repair) && Number(reject) > 0 && (
                      <div className="space-y-2">
                        <Button
                          type="button"
                          variant="secondary"
                          className="min-h-11 sm:min-h-8"
                          onClick={() =>
                            setDefectRows([...defectRows, { defect_type_id: '', count: '' }])
                          }
                        >
                          Add defect
                        </Button>
                        {defectRows.map((row, index) => (
                          <div
                            key={index}
                            className="grid grid-cols-[1fr_auto] gap-2 sm:grid-cols-[1fr_6rem_auto]"
                          >
                            <Select
                              containerClassName="col-span-2 sm:col-span-1 [&>div]:h-11 sm:[&>div]:h-8"
                              label={`Defect type ${index + 1}`}
                              value={row.defect_type_id}
                              onChange={(event) =>
                                setDefectRows(
                                  defectRows.map((item, i) =>
                                    i === index
                                      ? { ...item, defect_type_id: event.target.value }
                                      : item,
                                  ),
                                )
                              }
                            >
                              <option value="">Choose defect</option>
                              {options.data?.defect_types.map((type) => (
                                <option
                                  key={type.id}
                                  value={type.id}
                                  disabled={defectRows.some(
                                    (other, otherIndex) =>
                                      otherIndex !== index && other.defect_type_id === type.id,
                                  )}
                                >
                                  {type.code} · {type.name}
                                </option>
                              ))}
                            </Select>
                            <Input
                              containerClassName="[&>div]:h-11 sm:[&>div]:h-8"
                              label={`Defect count ${index + 1}`}
                              type="number"
                              min={1}
                              step={1}
                              value={row.count}
                              onChange={(event) =>
                                setDefectRows(
                                  defectRows.map((item, i) =>
                                    i === index ? { ...item, count: event.target.value } : item,
                                  ),
                                )
                              }
                            />
                            <Button
                              type="button"
                              variant="ghost"
                              iconOnly
                              icon={<LuTrash2 size={16} aria-hidden />}
                              aria-label={`Remove defect ${index + 1}`}
                              className="min-h-11 min-w-11 self-end sm:min-h-8 sm:min-w-8"
                              onClick={() =>
                                setDefectRows(defectRows.filter((_, i) => i !== index))
                              }
                            />
                          </div>
                        ))}
                      </div>
                    )}
                    <details open={remarks !== '' || undefined}>
                      <summary className="min-h-11 cursor-pointer py-2 text-sm text-muted focus-visible:outline-accent">
                        Add remarks (optional)
                      </summary>
                      <Textarea
                        label="Remarks"
                        maxLength={500}
                        value={remarks}
                        onChange={(event) => setRemarks(event.target.value)}
                      />
                    </details>
                    {(!finishFirst || data.repair) &&
                      Number(reject) > 0 &&
                      defectRows.length === 0 && (
                        <p className="text-sm text-muted">
                          Defect count: <span className="font-mono tabular-nums">{reject}</span>.
                          Classify every rejected piece.
                        </p>
                      )}
                  </fieldset>
                  {options.isLoading && <p role="status">Loading output options…</p>}
                  {options.isError && (
                    <p role="alert">
                      Output options failed to load.{' '}
                      <Button type="button" onClick={() => options.refetch()}>
                        Retry output options
                      </Button>
                    </p>
                  )}
                  {invalid && dirty && (
                    <p role="alert" className="text-sm text-danger-fg">
                      {invalid}
                    </p>
                  )}
                  {order.isFetching && (
                    <p role="status" className="text-xs text-muted">
                      Checking assignment…
                    </p>
                  )}
                </Panel>
                <StickyActionBar offset="tabs" className="md:border-t md:border-default md:pt-4">
                  <Button
                    type="submit"
                    variant="primary"
                    className="min-h-11 w-full md:ml-auto md:w-auto"
                    disabled={blocked || !!invalid || !!pending || order.isFetching}
                    loading={submit.isPending}
                  >
                    {finishFirst ? 'Record production' : 'Record output'}
                  </Button>
                </StickyActionBar>
              </form>
            )}
          </>
        )}
        {data && !data.can_record && earlierReport}
        {data && !data.historical_report_only && (
          <Panel
            title="Output receipts"
            meta={receipts.data?.length ? `${receipts.data.length} recent` : undefined}
          >
            {receipts.isLoading && <p role="status">Loading receipts…</p>}
            {receipts.isError && (
              <p role="alert">
                Receipts failed to load.{' '}
                <Button onClick={() => receipts.refetch()}>Retry receipts</Button>
              </p>
            )}
            {receipts.data?.length === 0 && (
              <p className="text-sm text-muted">No output receipts.</p>
            )}
            {!!receipts.data?.length && (
              <p className="mb-2 text-xs text-muted">
                Latest 20 saved receipts. These quantities are already recorded.
              </p>
            )}
            <ul className="max-h-80 overflow-y-auto divide-y divide-default">
              {receipts.data?.map((receipt) => (
                <li key={receipt.id} className="py-2 text-sm tabular-nums">
                  <MaterialHandoverExceptionDetails
                    exception={receipt.material_handover_exception}
                  />
                  <span className="font-mono">{receipt.batch_code}</span> ·{' '}
                  {!!receipt.trial_quantity && (
                    <>
                      {receipt.trial_quantity} trial pieces ·{' '}
                      {receipt.trial_settled_at ? 'Classified samples' : 'Awaiting QC'} ·{' '}
                    </>
                  )}
                  {finishFirst
                    ? `${receipt.good_count} produced`
                    : `${receipt.good_count} good · ${receipt.reject_count} rejected`}
                  {!!receipt.held_quantity && <> · {receipt.held_quantity} held for QC</>} ·{' '}
                  {receipt.held_quantity
                    ? 'Awaiting QC'
                    : ['not_required', 'generated'].includes(receipt.receipt_status)
                      ? 'Saved'
                      : receipt.receipt_status === 'pending'
                        ? 'Receipt pending'
                        : receipt.receipt_status.replaceAll('_', ' ')}{' '}
                  · Entered{' '}
                  {receipt.recorded_at && !Number.isNaN(Date.parse(receipt.recorded_at))
                    ? productionDateTime(receipt.recorded_at)
                    : '—'}
                  {receipt.production_started_at && receipt.production_finished_at && (
                    <div className="text-xs text-muted">
                      Produced {productionDateTime(receipt.production_started_at)} –{' '}
                      {productionDateTime(receipt.production_finished_at)}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </Panel>
        )}
        {data?.can_record && earlierReport}
      </div>
    </div>
  );
}
