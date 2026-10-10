import { MaterialHandoverNotice } from '@/components/production/MaterialHandoverNotice';
import { MaterialHandoverExceptionDetails } from '@/components/production/MaterialHandoverExceptionDetails';
import { formatDateTime } from '@/lib/formatDate';
import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  LuCheck,
  LuPause,
  LuPlay,
  LuCircleStop,
  LuBan,
  LuLock,
  LuActivity,
  LuRefreshCw,
  LuTriangleAlert,
} from '@/lib/icons';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/production/workOrders';
import { woOperationsApi } from '@/api/production/routings';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { productionDateTime, scheduleDate } from '@/components/production/scheduleDates';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader, LinkedRecords, ActivityStream } from '@/components/chain';
import { useEcho } from '@/hooks/useEcho';
import { useChainProgress } from '@/hooks/useChainProgress';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';
import { formatInt, formatPeso } from '@/lib/formatNumber';
import { formatDate, formatDateIso } from '@/lib/formatDate';
import { workOrderStatusVariant as variant } from '@/lib/statusVariants';
import type { MachineDowntimeCategory } from '@/types/production';
import type { WoOperationStatus } from '@/types/production/routing';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { Tabs } from '@/components/ui/Tabs';
import { AssignmentReview, CrewPanel } from './CrewPanel';
import { ReworkPanel } from './ReworkPanel';
import { StartProductionDialog } from './StartProductionDialog';
import { LateProductionReviewQueue } from '@/components/production/LateProductionReports';

const OP_STATUS_CHIP: Record<
  WoOperationStatus,
  'success' | 'info' | 'warning' | 'danger' | 'neutral'
> = {
  pending: 'neutral',
  setup: 'info',
  in_progress: 'warning',
  paused: 'danger',
  completed: 'success',
  skipped: 'neutral',
};

type DetailTab = 'details' | 'operations';

type LifecycleAction = 'pause' | 'complete' | 'close' | 'cancel';
type OperationDialog = {
  kind: 'record' | 'skip';
  operation: NonNullable<Awaited<ReturnType<typeof woOperationsApi.list>>>[number];
} | null;

export default function WorkOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const actorEmployeeId = useAuthStore((state) => state.user?.employee?.id);
  const operationCrew = useQuery({
    queryKey: ['production', 'crew', id],
    queryFn: () => workOrdersApi.crew(id!),
    enabled: !!id,
  });
  const employeeId = operationCrew.data?.current?.recorder_employee_id;
  const canLifecycle = can('production.work_orders.lifecycle');
  const canCancelStock = can('production.wo.cancel_stock');
  const canConfirm = can('production.wo.confirm');
  const canRecord = can('production.wo.record');
  const canInventoryView = can('inventory.view');

  const [executionAction, setExecutionAction] = useState<'start' | 'resume' | 'extend' | null>(
    null,
  );
  const [confirmAction, setConfirmAction] = useState<LifecycleAction | null>(null);
  const [showConfirmDialog, setShowConfirmDialog] = useState(false);
  const [showPauseDialog, setShowPauseDialog] = useState(false);
  const [releaseOpen, setReleaseOpen] = useState(false);
  const [releaseReason, setReleaseReason] = useState('');
  const [pauseReason, setPauseReason] = useState('');
  const [cancellationReason, setCancellationReason] = useState('');
  const [shortCompletionReason, setShortCompletionReason] = useState('');
  const [productionFinishedAt, setProductionFinishedAt] = useState('');
  const [pauseCategory, setPauseCategory] = useState<MachineDowntimeCategory | ''>('');
  const [tab, setTab] = useState<DetailTab>('details');
  const [reworkDialogId, setReworkDialogId] = useState<string | null>(null);
  const [operationDialog, setOperationDialog] = useState<OperationDialog>(null);
  const [operationQty, setOperationQty] = useState('');
  const [operationScrap, setOperationScrap] = useState('0');
  const [operationScrapReason, setOperationScrapReason] = useState('');
  const [operationRecordingReason, setOperationRecordingReason] = useState('');
  const [operationSkipReason, setOperationSkipReason] = useState('');
  const [operationHandoverAcknowledged, setOperationHandoverAcknowledged] = useState(false);
  const [operationHandoverReason, setOperationHandoverReason] = useState('');
  const { data: workOrderOptions } = useQuery({
    queryKey: ['production', 'work-orders', 'options'],
    queryFn: () => workOrdersApi.options(),
    staleTime: 5 * 60 * 1000,
  });
  const operationStatusLabels = new Map(
    (workOrderOptions?.operation_statuses ?? []).map((status) => [status.value, status.label]),
  );
  const downtimeCategories = useQuery({
    queryKey: ['production', 'downtime-categories'],
    queryFn: workOrdersApi.downtimeCategories,
    enabled: showPauseDialog,
  });

  const { data, isLoading, isFetching, isError, refetch } = useQuery({
    queryKey: ['production', 'work-orders', 'detail', id],
    queryFn: () => workOrdersApi.show(id!),
    enabled: !!id,
    refetchInterval: 60_000,
  });
  const finishFirst = data?.qc_workflow === 'finish_first_v1';
  const physicalQuantity = finishFirst ? data?.produced_quantity ?? data?.quantity_produced ?? 0 : data?.quantity_good ?? 0;
  const handoverPending = data?.warehouse_handover?.pending ?? data?.warehouse_handover_pending ?? 0;
  const operationHandoverBlocked = handoverPending > 0 && !(canRecord && canLifecycle && operationHandoverAcknowledged && operationHandoverReason.trim() && operationHandoverReason.trim().length <= 500);
  const chain = useQuery({
    queryKey: ['production', 'work-orders', 'chain', id],
    queryFn: () => workOrdersApi.chain(id!),
    enabled: !!id,
  });

  const operations = useQuery({
    queryKey: ['production', 'work-orders', 'operations', id],
    queryFn: () => woOperationsApi.list(id!),
    enabled: !!id && tab === 'operations',
  });

  // Live updates from output recordings.
  useEcho(`production.wo.${id}`, '.output.recorded', () => {
    qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
  });

  // Series C — Task C4. Real-time chain progress (status transitions).
  useChainProgress(
    'work_order',
    id,
    ['production', 'work-orders', 'detail', id],
    [
      ['production', 'work-orders', 'chain', id],
      ['production', 'work-orders', 'operations', id],
    ],
  );

  const mut = useMutation({
    mutationFn: async (action: LifecycleAction) => {
      switch (action) {
        case 'pause':
          return workOrdersApi.pause(id!, pauseReason.trim(), pauseCategory);
        case 'complete':
          if (finishFirst && productionFinishedAt && !Number.isFinite(new Date(`${productionFinishedAt}:00+08:00`).getTime())) {
            throw new Error('Enter a valid production finish time.');
          }
          return workOrdersApi.complete(
            id!,
            { ...(data && Number(physicalQuantity) < Number(data.quantity_target) ? { short_completion_reason: shortCompletionReason.trim() } : {}),
              ...(finishFirst && productionFinishedAt ? { production_finished_at: new Date(`${productionFinishedAt}:00+08:00`).toISOString() } : {}) },
          );
        case 'close':
          return workOrdersApi.close(id!);
        case 'cancel':
          return workOrdersApi.cancel(id!, data?.production_purpose === 'stock' ? cancellationReason.trim() : undefined);
      }
    },
    onSuccess: (wo, action) => {
      qc.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'chain', id] });
      if (action === 'cancel') {
        qc.invalidateQueries({ queryKey: ['production'] });
        qc.invalidateQueries({ queryKey: ['mrp', 'scheduler'] });
        qc.invalidateQueries({ queryKey: ['inventory'] });
        qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests'] });
      }
      toast.success(`Work order ${wo?.wo_number} updated.`);
      setConfirmAction(null);
      setShowConfirmDialog(false);
      setShowPauseDialog(false);
      setPauseReason('');
      setCancellationReason('');
      setShortCompletionReason('');
      setPauseCategory('');
    },
    onError: (e: AxiosError<{ message?: string }>, action) => {
      if (action === 'cancel' && data?.production_purpose === 'stock') return;
      toast.error(e.response?.data?.message ?? 'Action failed.');
    },
  });

  const release = useMutation({
    mutationFn: () => workOrdersApi.releaseResources(id!, releaseReason.trim()),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['production'] });
      setReleaseOpen(false);
      setReleaseReason('');
      toast.success('Paused resources released. Review schedule before resuming.');
    },
  });

  const retryReceipt = useMutation({
    mutationFn: (outputId: string) => workOrdersApi.retryProductionReceipt(id!, outputId),
    onSuccess: (output) => {
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'outputs', id] });
      toast.success(
        output.production_receipt_handoff?.status === 'generated'
          ? 'Finished-goods receipt posted.'
          : 'Receipt handoff remains pending Inventory setup.',
      );
    },
    onError: (error: AxiosError<{ message?: string }>) => {
      toast.error(error.response?.data?.message ?? 'Finished-goods receipt could not be posted.');
    },
  });

  const operationMut = useMutation({
    mutationFn: async ({
      action,
      operationId,
    }: {
      action: 'startSetup' | 'endSetup' | 'start' | 'pause' | 'resume' | 'complete';
      operationId: string;
    }) => {
      switch (action) {
        case 'startSetup':
          if (!employeeId) throw new Error('Assign a crew with an output recorder first.');
          return woOperationsApi.startSetup(operationId, employeeId);
        case 'start':
          if (!employeeId) throw new Error('Assign a crew with an output recorder first.');
          return woOperationsApi.start(operationId, employeeId);
        case 'resume':
          if (!employeeId) throw new Error('Assign a crew with an output recorder first.');
          return woOperationsApi.resume(operationId, employeeId);
        case 'endSetup':
          return woOperationsApi.endSetup(operationId);
        case 'pause':
          return woOperationsApi.pause(operationId);
        case 'complete':
          if (finishFirst && productionFinishedAt && !Number.isFinite(new Date(`${productionFinishedAt}:00+08:00`).getTime())) {
            throw new Error('Enter a valid production finish time.');
          }
          return woOperationsApi.complete(operationId);
      }
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'operations', id] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
      toast.success('Operation updated.');
    },
    onError: (error: AxiosError<{ message?: string }> | Error) => {
      toast.error(
        error instanceof AxiosError
          ? (error.response?.data?.message ?? error.message)
          : error.message,
      );
    },
  });

  const operationDialogMut = useMutation({
    mutationFn: async () => {
      if (!operationDialog) throw new Error('Choose an operation first.');
      if (operationDialog.kind === 'skip') {
        return woOperationsApi.skip(
          operationDialog.operation.id,
          operationSkipReason.trim(),
          actorEmployeeId ?? '',
        );
      }
      if (operationHandoverBlocked) throw new Error('Warehouse handover is pending. Ask Warehouse to confirm before recording output.');
      return woOperationsApi.recordOutput(operationDialog.operation.id, {
        ...(handoverPending > 0 && canRecord && canLifecycle ? { material_handover_acknowledged: operationHandoverAcknowledged, material_handover_reason: operationHandoverReason.trim() } : {}),
        qty: Number(operationQty),
        scrap: Number(operationScrap || 0),
        scrap_reason: operationScrapReason.trim() || undefined,
        recording_reason: operationRecordingReason.trim() || undefined,
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'operations', id] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
      toast.success(
        operationDialog?.kind === 'skip' ? 'Operation skipped.' : 'Operation output recorded.',
      );
      setOperationDialog(null);
      setOperationQty('');
      setOperationScrap('0');
      setOperationScrapReason('');
      setOperationRecordingReason('');
      setOperationSkipReason('');
      setOperationHandoverAcknowledged(false);
      setOperationHandoverReason('');
    },
    onError: (error: AxiosError<{ message?: string }> | Error) => {
      toast.error(
        error instanceof AxiosError
          ? (error.response?.data?.message ?? error.message)
          : error.message,
      );
    },
  });

  if (isLoading)
    return (
      <div>
        {' '}
        <PageHeader title="Work order" backTo="/production/work-orders" backLabel="Work orders" />
        <SkeletonDetail />
      </div>
    );
  if (isError || !data)
    return (
      <div>
        <PageHeader title="Work order" backTo="/production/work-orders" backLabel="Work orders" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load work order"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      </div>
    );

  const pendingRepair = data.rework?.find(
    (repair) => !['closed', 'cancelled'].includes(repair.status),
  );
  const qualityStatus =
    pendingRepair && !pendingRepair.finished_at
      ? pendingRepair.started_at
        ? 'Rework in progress'
        : 'Rework required'
      : 'Production finished — awaiting quality';
  const processQcBlocked = Boolean(data.process_quality_blocker) || (!finishFirst && !!data.startup_trial && data.startup_trial.state !== 'released');
  const setupBlocked = handoverPending > 0 || (processQcBlocked && !['awaiting_samples', 'awaiting_qc'].includes(data.startup_trial?.state ?? ''));

  const showConfirm = data.status === 'planned' && canConfirm;
  const showStart = data.status === 'confirmed' && canLifecycle;
  const showPause = data.status === 'in_progress' && canLifecycle;
  const showResume = data.status === 'paused' && canLifecycle;
  const targetProduced = finishFirst && Number(physicalQuantity) >= Number(data.quantity_target);
  const showComplete = data.status === 'in_progress' && !targetProduced && !processQcBlocked && handoverPending === 0 && Number(data.quantity_produced) > 0 && canLifecycle;
  const showClose = data.status === 'completed' && !data.quality_close_blocker && canLifecycle;
  const stockProduction = data.production_purpose === 'stock';
  const showCancel = (stockProduction
    ? ['planned', 'confirmed'].includes(data.status) && !data.actual_start && !data.parent && (canLifecycle || canCancelStock)
    : !['completed', 'closed', 'cancelled', 'in_progress'].includes(data.status) && canLifecycle) &&
    Number(data.quantity_produced) === 0 &&
    !data.outputs?.length;
  const showRecord = data.status === 'in_progress' && !targetProduced && !processQcBlocked && handoverPending === 0 && canRecord;

  const scheduleStatus = data.schedule_status ?? data.schedule_warning;
  const informationalSchedule =
    scheduleStatus?.kind === 'awaiting_confirmation' || scheduleStatus?.kind === 'start_available';

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{data.wo_number}</span>
            <Chip variant={data.status === 'completed' ? 'warning' : variant[data.status]}>
              {data.status === 'completed' ? qualityStatus : data.status_label}
            </Chip>
          </div>
        }
        subtitle={data.product ? `${data.product.part_number} — ${data.product.name}` : undefined}
        backTo="/production/work-orders"
        backLabel="Work orders"
        actions={
          <div className="flex flex-wrap gap-1.5">
            {data.rework?.map((repair) => {
              const viewOnly = ['closed', 'cancelled'].includes(repair.status) || !!repair.finished_at || data.status !== 'completed' || (repair.started_at ? !canLifecycle && !canRecord : !canLifecycle);
              return (
                <Button
                  key={repair.id}
                  size="sm"
                  variant={viewOnly ? 'secondary' : 'primary'}
                  icon={<LuRefreshCw size={14} />}
                  title={`${repair.ncr_number} · ${repair.quantity} pieces`}
                  onClick={() => setReworkDialogId(repair.id)}
                >
                  {viewOnly ? 'View Rework' : repair.started_at ? 'Continue Rework' : 'Start Rework'}
                  {data.rework!.length > 1 && ` · ${repair.ncr_number}`}
                </Button>
              );
            })}
            {showConfirm && (
              <Button
                size="sm"
                variant="primary"
                icon={<LuCheck size={14} />}
                disabled={operationCrew.isFetching || !operationCrew.data?.can_assign}
                onClick={() => setShowConfirmDialog(true)}
              >
                Confirm
              </Button>
            )}
            {showStart && (
              <Button
                size="sm"
                variant="primary"
                icon={<LuPlay size={14} />}
                onClick={() => setExecutionAction('start')}
              >
                Start
              </Button>
            )}
            {showPause && (
              <Button size="sm" variant="secondary" onClick={() => setExecutionAction('extend')}>
                Extend run
              </Button>
            )}
            {showPause && (
              <Button
                size="sm"
                variant="secondary"
                icon={<LuPause size={14} />}
                onClick={() => setShowPauseDialog(true)}
              >
                Pause
              </Button>
            )}
            {showResume && (
              <Button
                size="sm"
                variant="primary"
                icon={<LuPlay size={14} />}
                onClick={() => setExecutionAction('resume')}
              >
                Resume
              </Button>
            )}
            {showResume && !data.production_released_at && (
              <Button size="sm" variant="secondary" onClick={() => setReleaseOpen(true)}>
                Release resources
              </Button>
            )}
            {showRecord && (
              <Button
                size="sm"
                variant="primary"
                icon={<LuActivity size={14} />}
                onClick={() => navigate(`/production/work-orders/${data.id}/record-output`)}
              >
                {finishFirst ? 'Record production' : 'Record output'}
              </Button>
            )}
            {showComplete && (
              <Button
                size="sm"
                variant="secondary"
                icon={<LuCircleStop size={14} />}
                onClick={() => setConfirmAction('complete')}
              >
                {finishFirst ? 'Finish production' : 'Complete'}
              </Button>
            )}
            {showClose && (
              <Button
                size="sm"
                variant="secondary"
                icon={<LuLock size={14} />}
                onClick={() => setConfirmAction('close')}
              >
                Close
              </Button>
            )}
            {showCancel && (
              <Button
                size="sm"
                variant="secondary"
                icon={<LuBan size={14} />}
                onClick={() => { mut.reset(); setCancellationReason(''); setConfirmAction('cancel'); }}
              >
                {stockProduction ? 'Cancel WO' : 'Cancel'}
              </Button>
            )}
          </div>
        }
        bottom={chain.data ? <ChainHeader steps={chain.data} className="mt-2" /> : null}
      />

      {showPause &&
        operationCrew.data?.schedule &&
        Date.parse(operationCrew.data.schedule.scheduled_end) <= Date.now() && (
          <div
            role="alert"
            className="mx-5 mt-4 rounded-md border border-default bg-warning-bg p-3 text-warning-fg"
          >
            Run reservation ended. Use Extend run to check remaining time and restore recorder
            access.
          </div>
        )}
      {!!data.staffing_warning?.length && (
        <div
          role="alert"
          className="mx-5 mt-4 rounded-md border border-default bg-warning-bg p-3 text-warning-fg"
        >
          <p className="text-sm font-medium">
            Crew needs review. Change workers before starting new operations.
          </p>
          {data.staffing_warning.map((message) => (
            <p key={message} className="mt-1 text-sm">
              {message}
            </p>
          ))}
        </div>
      )}
      {scheduleStatus && (
        <div className="px-5 pt-4">
          <div
            role="status"
            className={`flex items-start gap-2 rounded border p-3 ${informationalSchedule ? 'border-info-fg/30 bg-info-bg text-info-fg' : 'border-warning-fg/30 bg-warning-bg text-warning-fg'}`}
          >
            {!informationalSchedule && (
              <LuTriangleAlert size={16} className="shrink-0 mt-0.5" aria-hidden />
            )}
            <div className="min-w-0">
              <p className="text-sm font-medium">{scheduleStatus.message}</p>
              <p className="text-xs mt-1">
                {scheduleStatus.kind !== 'schedule_passed' &&
                scheduleStatus.kind !== 'proposal_expired' &&
                scheduleStatus.scheduled_start
                  ? `Scheduled start: ${productionDateTime(scheduleStatus.scheduled_start)}`
                  : `Scheduled finish: ${productionDateTime(scheduleStatus.scheduled_end)}`}{' '}
                (Asia/Manila)
              </p>
            </div>
          </div>
        </div>
      )}

      {chain.isError && (
        <div role="alert" className="px-5 py-3 text-sm text-danger-fg">
          Work order progress could not be loaded.{' '}
          <Button variant="secondary" size="sm" onClick={() => chain.refetch()}>
            Retry work order progress
          </Button>
        </div>
      )}

      {data.production_completion_blocker && (
        <div role="alert" className="mx-5 mt-4 rounded-md border border-default bg-warning-bg p-3 text-warning-fg">
          <p className="text-sm font-medium">Target produced. Completion pending.</p>
          <p className="mt-1 text-sm">{data.production_completion_blocker}</p>
        </div>
      )}

      {canLifecycle && canRecord && (
        <div className="px-5 pt-4">
          <LateProductionReviewQueue workOrderId={id!} workOrderStatus={data.status} />
        </div>
      )}

      {/* Tabs */}
      <Tabs
        className="px-5"
        label="Work order sections"
        value={tab}
        onChange={setTab}
        items={[
          { key: 'details', label: 'Details' },
          { key: 'operations', label: 'Operations' },
        ]}
      />

      {finishFirst && ['completed', 'closed'].includes(data.status) && <div className="px-5 pt-4"><Panel title="Final quality inspection">
        <p className="text-sm">{data.produced_quantity ?? data.quantity_produced} produced · {data.qc_accepted_quantity ?? 0} QC accepted · {data.awaiting_qc_quantity ?? 0} awaiting QC.</p>
        {can('quality.inspections.view') && <Button className="mt-3" variant="secondary" onClick={() => navigate(`/quality/work-orders/${data.id}/final-inspection`)}>Open final inspection</Button>}
      </Panel></div>}
      {data.status === 'in_progress' && processQcBlocked && (
        <div className="px-5 pt-4">
          <Panel title="Production blocked by quality">
            <p role="alert" className="text-sm text-warning-fg">
              {data.process_quality_blocker ?? 'QC approval is required before normal production.'}
            </p>
            {data.startup_trial && can('quality.inspections.view') && (
              <Link
                className="mt-3 inline-block text-sm text-accent underline"
                to={`/quality/inspections/${data.startup_trial.inspection_id}`}
              >
                Open quality inspection
              </Link>
            )}
            <Button
              type="button"
              variant="secondary"
              size="sm"
              disabled={isFetching}
              loading={isFetching}
              onClick={() => void refetch()}
            >
              Recheck QC
            </Button>
          </Panel>
        </div>
      )}

      {tab === 'details' && (
        <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
          <div className="lg:col-span-2 min-w-0 space-y-4">
            {['completed', 'closed'].includes(data.status) && (
              <Panel
                title={data.status === 'closed' ? 'Current quality obligations' : 'Quality release'}
              >
                <p className="text-sm text-muted">
                  {data.quality_close_blocker ??
                    (data.status === 'closed'
                      ? 'Production history is closed. Current quality holds and recovery remain separate obligations.'
                      : 'All checks settled. Automatic closure pending.')}
                </p>
                {data.quality_summary && (
                  <div className="mt-3 space-y-2 text-sm">
                    <p className="font-mono tabular-nums">
                      Source: {formatInt(data.quantity_good)} · QC approved:{' '}
                      {formatInt(data.quality_summary.approved_quantity)} · Rejected:{' '}
                      {formatInt(data.quality_summary.rejected_quantity)}
                    </p>
                    {data.quality_summary.open_defects > 0 && (
                      <p className="text-warning-fg">
                        {data.quality_summary.open_defects} open defect records. Current stock
                        release follows their approved disposition.
                      </p>
                    )}
                    {data.quality_summary.missing_process_evidence && (
                      <p className="text-warning-fg">
                        Historical process evidence is missing. No inspection result has been
                        inferred.
                      </p>
                    )}
                    {data.quality_summary.replacement_pending && (
                      <p className="text-warning-fg">
                        Replacement fulfillment remains pending. Closing this production run does
                        not fulfill replacement demand.
                      </p>
                    )}
                    {data.quality_summary.recovery_work_orders.map((recovery) => (
                      <p key={recovery.id}>
                        <Link
                          className="font-mono text-accent hover:underline"
                          to={`/production/work-orders/${recovery.id}`}
                        >
                          {recovery.wo_number}
                        </Link>{' '}
                        · {recovery.status.replaceAll('_', ' ')} ·{' '}
                        {formatInt(recovery.quantity_target)} pieces to recover
                      </p>
                    ))}
                  </div>
                )}
                {data.short_completion_reason && (
                  <p className="mt-2 text-sm">
                    Short-completion reason: {data.short_completion_reason}
                  </p>
                )}
                <p className="mt-1 text-xs text-muted">
                  Work order closes automatically after QC, defect handling and stock receipts are
                  resolved.
                </p>
              </Panel>
            )}
            {/* ADV3 — IATF 16949 Production Batch panel. Visible once the WO has
 been started (batch_number is generated on first start). */}
            {data.batch_number && (
              <Panel title="Production batch" meta="IATF 16949 traceability">
                <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3 text-sm">
                  <dt className="text-muted">Batch no.</dt>
                  <dd className="sm:col-span-2 font-mono tabular-nums">{data.batch_number}</dd>
                  <dt className="text-muted">Machine / Mold</dt>
                  <dd className="sm:col-span-2 font-mono">
                    {data.machine?.machine_code ?? '—'} / {data.mold?.mold_code ?? '—'}
                  </dd>
                  <dt className="text-muted">Produced</dt>
                  <dd className="sm:col-span-2 font-mono tabular-nums">
                    {formatInt(data.quantity_good)} good / {formatInt(data.quantity_rejected)}{' '}
                    rejected
                  </dd>
                </dl>
                {data.material_lot_references.length > 0 && (
                  <div className="mt-3 pt-3 border-t border-subtle">
                    <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
                      Material lots used
                    </div>
                    <div className="overflow-x-auto">
                      <table className={`${tableCls} min-w-[640px]`}>
                        <thead>
                          <tr className={theadTrCls}>
                            <Th>Item</Th>
                            <Th>GRN</Th>
                            <Th>Material lot</Th>
                            <Th>Supplier ref</Th>
                          </tr>
                        </thead>
                        <tbody>
                          {data.material_lot_references.map((ref, i) => (
                            <tr key={`${ref.material_lot_number ?? 'lot'}-${i}`} className={trCls}>
                              <Td>
                                <div className="font-mono">{ref.item_code ?? '—'}</div>
                                <div className="text-muted">{ref.item_name ?? ''}</div>
                              </Td>
                              <Td mono>{ref.grn_number ?? '—'}</Td>
                              <Td mono>{ref.material_lot_number ?? '—'}</Td>
                              <Td mono>{ref.supplier_lot_reference ?? '—'}</Td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}
                <div className="mt-3 text-xs">
                  <Link
                    to={`/quality/traceability?term=${encodeURIComponent(data.batch_number)}`}
                    className="text-accent hover:underline"
                  >
                    View full traceability →
                  </Link>
                </div>
              </Panel>
            )}
            {(data.warehouse_handover?.total ?? handoverPending) > 0 && <div role="status" className={`rounded-md border p-3 text-sm ${handoverPending > 0 ? 'border-warning bg-warning-bg text-warning-fg' : 'border-default text-success-fg'}`}>
              {handoverPending > 0 ? `Material handover pending: ${handoverPending} lines.` : 'Materials handed over.'}
              {data.warehouse_handover && data.warehouse_handover.handed_over > 0 && handoverPending > 0 && <span className="ml-2">{data.warehouse_handover.handed_over} of {data.warehouse_handover.total} lines handed over.</span>}
              {can('inventory.picking.view') ? <Link className="ml-2 text-link underline" to={`/inventory/picking?work_order_id=${data.id}`}>Review handover</Link> : handoverPending > 0 && <p className="mt-1">Ask Warehouse to confirm material handover.</p>}
            </div>}
            {['completed', 'closed'].includes(data.status) && can('inventory.view') && (
              <div className="text-sm text-muted">
                Review unused materials when Production returns them. Warehouse must confirm the
                actual received quantity.
                <Link
                  className="ml-2 text-link underline"
                  to={`/inventory/stock-levels?view=movements&work_order_id=${data.id}`}
                >
                  Review unused returns
                </Link>
              </div>
            )}
            {data.production_readiness && !data.production_readiness.ready && (
              <div className="rounded-md border border-warning bg-warning-bg px-3 py-2 text-sm">
                <div className="font-medium text-warning-fg">
                  Waiting for subassembly production
                </div>
                <div className="mt-1 text-muted">
                  {data.production_readiness.blocking_work_orders.map((child) => (
                    <span key={child.id} className="mr-2 inline-block font-mono">
                      {child.wo_number} ({formatInt(child.quantity_good)} /{' '}
                      {formatInt(child.quantity_target)} good)
                    </span>
                  ))}
                </div>
              </div>
            )}
            <Panel title="Details">
              <dl className="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-3 text-sm">
                <dt className="text-muted">Product</dt>
                <dd className="sm:col-span-2">
                  <span className="font-mono">{data.product?.part_number}</span> —{' '}
                  {data.product?.name}
                </dd>
                <dt className="text-muted">Sales order</dt>
                <dd className="sm:col-span-2">
                  {data.sales_order ? (
                    <span className="font-mono">{data.sales_order.so_number}</span>
                  ) : (
                    <span className="text-muted">—</span>
                  )}
                </dd>
                {data.sales_order_line && (
                  <>
                    <dt className="text-muted">Requested delivery</dt>
                    <dd className="sm:col-span-2">
                      {formatDate(data.sales_order_line.requested_delivery_date)}
                    </dd>
                    <dt className="text-muted">Current delivery</dt>
                    <dd className="sm:col-span-2">
                      {formatDate(data.sales_order_line.delivery_date)}
                    </dd>
                  </>
                )}
                <dt className="text-muted">Machine</dt>
                <dd className="sm:col-span-2 font-mono">{data.machine?.machine_code ?? '—'}</dd>
                <dt className="text-muted">Mold</dt>
                <dd className="sm:col-span-2 font-mono">{data.mold?.mold_code ?? '—'}</dd>
                <dt className="text-muted">Assigned workers</dt>
                <dd className="sm:col-span-2 flex flex-col xl:flex-row items-start justify-between gap-3">
                  {operationCrew.isPending ? (
                    <span className="text-muted">Loading workers…</span>
                  ) : operationCrew.isError ? (
                    <span role="alert">
                      Workers could not be loaded.{' '}
                      <button
                        type="button"
                        className="underline"
                        onClick={() => operationCrew.refetch()}
                      >
                        Retry workers
                      </button>
                    </span>
                  ) : operationCrew.data?.current?.members.length ? (
                    <div className="min-w-0 w-full xl:w-auto flex-1 flex flex-wrap gap-x-4 gap-y-1">
                      {operationCrew.data.current.members.map((worker) => (
                        <span key={worker.id}>
                          {worker.name}
                          {worker.id === operationCrew.data.current?.recorder_employee_id && (
                            <span className="ml-1.5 text-xs text-muted">(Output recorder)</span>
                          )}
                        </span>
                      ))}
                    </div>
                  ) : (
                    <span className="text-muted">Not assigned</span>
                  )}
                  <div className="ml-auto shrink-0">
                    <CrewPanel
                      actionOnly
                      allowSlotReview={false}
                      status={data.status}
                      woId={data.id}
                      readOnly={['completed', 'closed', 'cancelled'].includes(data.status)}
                    />
                  </div>
                </dd>
                <dt className="text-muted">Target / Produced</dt>
                <dd className="sm:col-span-2 font-mono tabular-nums">
                  {formatInt(data.quantity_target)} / {formatInt(data.quantity_produced)}
                </dd>
                <dt className="text-muted">{finishFirst ? "QC accepted / Defective" : "Good / Reject"}</dt>
                <dd className="sm:col-span-2 font-mono tabular-nums">
                  {finishFirst ? `${formatInt(data.qc_accepted_quantity ?? 0)} / ${formatInt(data.qc_defective_quantity ?? 0)}` : <>{formatInt(data.quantity_good)} / {formatInt(data.quantity_rejected)} (scrap {Number(data.scrap_rate).toFixed(2)}%)</>}
                </dd>
                <dt className="text-muted">
                  {data.mrp_generated ? 'Planning reference' : 'Planned start'}
                </dt>
                <dd className="sm:col-span-2 font-mono">
                  {data.planned_start ? productionDateTime(data.planned_start) : '—'}
                </dd>
                {data.preferred_production_date && (
                  <>
                    <dt className="text-muted">Preferred production day</dt>
                    <dd className="sm:col-span-2">
                      {scheduleDate(
                        data.preferred_production_date + 'T00:00:00+08:00',
                        'Asia/Manila',
                      )}
                    </dd>
                  </>
                )}
                <dt className="text-muted">Production due</dt>
                <dd className="sm:col-span-2">
                  {data.planned_end ? productionDateTime(data.planned_end) : '—'}
                  <span className="ml-2 text-xs text-muted">Planning deadline</span>
                </dd>
                <dt className="text-muted">Actual</dt>
                <dd className="sm:col-span-2 font-mono">
                  {data.actual_start
                    ? `${productionDateTime(data.actual_start)} – ${data.actual_end ? productionDateTime(data.actual_end) : '…'}`
                    : '—'}
                </dd>
                {data.pause_reason && (
                  <>
                    <dt className="text-muted">{data.status === 'cancelled' ? 'Cancellation reason' : 'Pause reason'}</dt>
                    <dd className="sm:col-span-2 text-warning-fg">{data.pause_reason}</dd>
                  </>
                )}
                {operationCrew.data?.schedule && (
                  <>
                    <dt className="text-muted">Scheduled production</dt>
                    <dd className="sm:col-span-2">
                      {productionDateTime(operationCrew.data.schedule.scheduled_start)} –{' '}
                      {productionDateTime(operationCrew.data.schedule.scheduled_end)} (Asia/Manila)
                    </dd>
                  </>
                )}
              </dl>
            </Panel>

            <Panel
              title="Materials"
              meta={`${data.materials?.length ?? 0} lines`}
              noPadding
              actions={
                can('inventory.view') ? (
                  <Link to={`/inventory/stock-levels?view=reservations&work_order_id=${data.id}`}>
                    <Button size="sm" variant="secondary">
                      View reservations
                    </Button>
                  </Link>
                ) : undefined
              }
            >
              {data.material_cost_summary && (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 border-b border-default px-4 py-3 text-sm">
                  <div>
                    <div className="text-2xs uppercase text-muted">Standard</div>
                    <div className="mt-1 font-mono">
                      {formatPeso(data.material_cost_summary.standard_cost)}
                    </div>
                  </div>
                  <div>
                    <div className="text-2xs uppercase text-muted">Actual issued</div>
                    <div className="mt-1 font-mono">
                      {formatPeso(data.material_cost_summary.actual_cost)}
                    </div>
                  </div>
                  <div>
                    <div className="text-2xs uppercase text-muted">Cost variance</div>
                    <div
                      className={`mt-1 font-mono ${Number(data.material_cost_summary.cost_variance) > 0 ? 'text-danger-fg' : 'text-success-fg'}`}
                    >
                      {formatPeso(data.material_cost_summary.cost_variance)}
                    </div>
                  </div>
                </div>
              )}
              {data.materials?.length ? (
                <div className="overflow-x-auto">
                  <table className={`${tableCls} min-w-[520px]`}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Item</Th>
                        <Th align="right">BOM qty</Th>
                        <Th align="right">Issued</Th>
                        <Th align="right">Variance</Th>
                        <Th align="right">Standard</Th>
                        <Th align="right">Actual</Th>
                        <Th align="right">Cost variance</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.materials.map((m) => (
                        <tr key={m.id} className={trCls}>
                          <Td>
                            <div className="font-mono">{m.item?.code}</div>
                            <div className="text-muted">{m.item?.name}</div>
                          </Td>
                          <Td align="right" mono>
                            {Number(m.bom_quantity).toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {Number(m.actual_quantity_issued).toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {Number(m.variance).toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(m.standard_cost)}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(m.actual_cost)}
                          </Td>
                          <Td
                            align="right"
                            mono
                            className={
                              Number(m.cost_variance) > 0 ? 'text-danger-fg' : 'text-success-fg'
                            }
                          >
                            {formatPeso(m.cost_variance)}
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="p-4 text-sm text-muted">No materials defined (no active BOM).</div>
              )}
            </Panel>

            <Panel title="Recent outputs" meta={`${data.outputs?.length ?? 0} entries`} noPadding>
              {data.outputs?.length ? (
                <div className="overflow-x-auto">
                  <table className={`${tableCls} min-w-[920px]`}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Recorded</Th>
                        <Th>Batch</Th>
                        <Th>Produced by / recorded by</Th>
                        <Th align="right">{finishFirst ? "Produced" : "Good"}</Th>
                        <Th align="right">{finishFirst ? "QC defects" : "Reject"}</Th>
                        <Th>FG receipt</Th>
                        <Th>General Ledger</Th>
                        <Th>Defects</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.outputs.map((o) => (
                        <tr key={o.id} className={trCls}>
                          <Td mono>{formatDateTime(o.recorded_at)}</Td>
                          <Td mono>
                            {o.batch_code ?? '—'}
                            {!!o.held_quantity && <span className="ml-2 text-xs text-warning-fg">{o.held_quantity} pieces held for QC</span>}
                            {o.production_started_at && o.production_finished_at && <div className="text-xs text-muted">Produced {formatDateTime(o.production_started_at)} – {formatDateTime(o.production_finished_at)}</div>}
                            {!!o.trial_quantity && <span className="ml-2 text-xs text-muted">{o.trial_quantity} trial pieces · {o.trial_settled_at ? 'Classified samples' : 'Awaiting QC'}</span>}
                            {o.is_rework && <span className="ml-2 text-xs text-muted">Rework</span>}
                          </Td>
                          <Td>
                            <div>
                              {o.crew_snapshot?.members.map((member) => member.name).join(', ') ||
                                'Crew not recorded'}
                            </div>
                            <div className="text-xs text-muted">
                              Recorded by {o.recorder?.name ?? '—'}
                            </div>
                            <MaterialHandoverExceptionDetails exception={o.material_handover_exception} />
                            {o.recording_reason && (
                              <div className="text-xs text-muted break-words">
                                Recording reason: {o.recording_reason}
                              </div>
                            )}
                          </Td>
                          <Td align="right" mono>
                            {o.good_count}
                          </Td>
                          <Td align="right" mono>
                            {finishFirst && !o.is_rework ? 'See final QC' : o.reject_count}
                          </Td>
                          <Td>
                            {o.held_quantity || (o.trial_quantity && !o.trial_settled_at) ? <Chip variant="warning">Awaiting QC</Chip> : o.production_receipt_handoff ? (
                              <div className="flex items-center gap-2">
                                <span title={o.production_receipt_handoff.message ?? undefined}>
                                  <Chip
                                    variant={
                                      o.production_receipt_handoff.status === 'generated' ||
                                      o.production_receipt_handoff.status === 'not_required'
                                        ? 'success'
                                        : o.production_receipt_handoff.status === 'manual_required'
                                          ? 'warning'
                                          : 'neutral'
                                    }
                                  >
                                    {o.production_receipt_handoff.status_label ??
                                      o.production_receipt_handoff.status.replace('_', ' ')}
                                  </Chip>
                                </span>
                                {canRecord &&
                                  o.production_receipt_handoff.status === 'manual_required' && (
                                    <Button
                                      type="button"
                                      variant="ghost"
                                      size="sm"
                                      icon={
                                        <LuRefreshCw
                                          size={13}
                                          className={retryReceipt.isPending ? 'animate-spin' : ''}
                                        />
                                      }
                                      disabled={retryReceipt.isPending}
                                      onClick={() => retryReceipt.mutate(o.id)}
                                    >
                                      Retry
                                    </Button>
                                  )}
                              </div>
                            ) : (
                              <span className="text-muted">—</span>
                            )}
                          </Td>
                          <Td>
                            {o.general_ledger_handoff ? (
                              <div className="space-y-1">
                                <span title={o.general_ledger_handoff.message ?? undefined}>
                                  <Chip
                                    variant={
                                      o.general_ledger_handoff.status === 'manual_required'
                                        ? 'warning'
                                        : o.general_ledger_handoff.status === 'generated'
                                          ? 'success'
                                          : 'neutral'
                                    }
                                  >
                                    {o.general_ledger_handoff.status_label ??
                                      o.general_ledger_handoff.status.replace('_', ' ')}
                                  </Chip>
                                </span>
                                {o.general_ledger_handoff.status === 'manual_required' && (
                                  <div className="max-w-[18rem] text-xs text-warning-fg">
                                    <p>
                                      {o.general_ledger_handoff.message ??
                                        'Accounting must resolve this stock movement before delivery.'}
                                    </p>
                                    <p className="mt-1">
                                      Ask Accounting to retry the linked movement.
                                    </p>
                                    {canInventoryView && (
                                      <Link
                                        className="mt-1 inline-block text-primary hover:underline"
                                        to={`/inventory/stock-levels?view=movements&movement_id=${encodeURIComponent(o.general_ledger_handoff.movement_id)}`}
                                      >
                                        Open stock movement
                                      </Link>
                                    )}
                                  </div>
                                )}
                              </div>
                            ) : (
                              <span className="text-muted">No receipt movement</span>
                            )}
                          </Td>
                          <Td className="text-xs">
                            {o.defects?.length ? (
                              o.defects.map((d) => `${d.defect_type?.code} ×${d.count}`).join(', ')
                            ) : (
                              <span className="text-muted">—</span>
                            )}
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="p-4 text-sm text-muted">No output recorded yet.</div>
              )}
            </Panel>
          </div>

          <div className="space-y-4">
            <Panel title="Linked records">
              {/* Sprint 6 audit §3.2: replace the inline list with the proper
 LinkedRecords component so the WO detail right panel matches
 the SO detail panel and the documented design system. */}
              <LinkedRecords
                groups={[
                  ...(data.sales_order
                    ? [
                        {
                          label: 'Sales Order',
                          items: [
                            {
                              id: data.sales_order.so_number,
                              href: `/crm/sales-orders/${data.sales_order.id}`,
                            },
                          ],
                        },
                      ]
                    : []),
                  ...(data.parent
                    ? [
                        {
                          label: 'Parent work order',
                          items: [
                            {
                              id: data.parent.wo_number,
                              href: `/production/work-orders/${data.parent.id}`,
                            },
                          ],
                        },
                      ]
                    : []),
                  ...(data.children && data.children.length > 0
                    ? [
                        {
                          label: 'Subassembly work orders',
                          items: data.children.map((child) => ({
                            id: child.wo_number,
                            href: `/production/work-orders/${child.id}`,
                            meta: `qty ${child.quantity_target}`,
                          })),
                        },
                      ]
                    : []),
                  ...(data.machine || data.mold
                    ? [
                        {
                          label: 'Resources',
                          items: [
                            ...(data.machine
                              ? [
                                  {
                                    id: data.machine.machine_code,
                                    href: `/mrp/machines/${data.machine.id}`,
                                    meta: data.machine.name,
                                  },
                                ]
                              : []),
                            ...(data.mold
                              ? [
                                  {
                                    id: data.mold.mold_code,
                                    href: `/mrp/molds/${data.mold.id}`,
                                    meta: data.mold.name,
                                  },
                                ]
                              : []),
                          ],
                        },
                      ]
                    : []),
                  ...(data.materials && data.materials.length > 0
                    ? [
                        {
                          label: 'Materials',
                          items: data.materials.map((m) => ({
                            id: m.item?.code ?? '—',
                            meta: `${Number(m.actual_quantity_issued).toFixed(3)} / ${Number(m.bom_quantity).toFixed(3)} ${m.item?.unit_of_measure ?? ''}`,
                          })),
                        },
                      ]
                    : []),
                  ...(data.inspections && data.inspections.length > 0
                    ? [
                        {
                          label: 'Quality inspections',
                          items: data.inspections.map((inspection) => ({
                            id: inspection.inspection_number,
                            href: `/quality/inspections/${inspection.id}`,
                            meta: inspection.stage_label ?? inspection.stage.replace('_', ' '),
                            chip: {
                              variant:
                                inspection.status === 'passed'
                                  ? ('success' as const)
                                  : inspection.status === 'failed'
                                    ? ('danger' as const)
                                    : inspection.status === 'in_progress'
                                      ? ('info' as const)
                                      : ('neutral' as const),
                              text: inspection.status_label ?? inspection.status.replace('_', ' '),
                            },
                          })),
                        },
                      ]
                    : []),
                ]}
              />
            </Panel>
            <Panel title="Activity">
              <ActivityStream
                items={[
                  ...(data.execution_history ?? []).map((event) => ({
                    dot: 'info' as const,
                    text: (
                      <>
                        <span>{event.summary}</span>
                        {event.actor && (
                          <span className="block text-xs text-muted">
                            Approved by {event.actor.name}
                          </span>
                        )}
                        <details className="mt-1 text-xs">
                          <summary className="cursor-pointer text-muted">Timing details</summary>
                          {event.detail.approved_start && (
                            <p>
                              Previous: {productionDateTime(event.detail.approved_start)} –{' '}
                              {event.detail.approved_end
                                ? productionDateTime(event.detail.approved_end)
                                : '—'}
                            </p>
                          )}
                          {event.detail.scheduled_start && (
                            <p>
                              Reserved: {productionDateTime(event.detail.scheduled_start)} –{' '}
                              {event.detail.scheduled_end
                                ? productionDateTime(event.detail.scheduled_end)
                                : '—'}
                            </p>
                          )}
                          {event.detail.reason && <p>{event.detail.reason}</p>}
                          {event.detail.late_production_reason && (
                            <p>Delivery impact: {event.detail.late_production_reason}</p>
                          )}
                        </details>
                      </>
                    ),
                    time: productionDateTime(event.created_at),
                  })),
                  {
                    dot: 'success' as const,
                    text: (
                      <>
                        Work order <span className="font-mono">{data.wo_number}</span> created.
                      </>
                    ),
                    time: formatDateIso(data.created_at, ''),
                  },
                  ...(data.actual_start
                    ? [
                        {
                          dot: 'info' as const,
                          text: <>Production started.</>,
                          time: data.actual_start.slice(0, 10),
                        },
                      ]
                    : []),
                  ...(data.actual_end
                    ? [
                        {
                          dot: 'success' as const,
                          text: <>Production completed.</>,
                          time: data.actual_end.slice(0, 10),
                        },
                      ]
                    : []),
                  ...(data.pause_reason
                    ? [
                        {
                          dot: 'warning' as const,
                          text: <>Paused: {data.pause_reason}</>,
                          time: formatDateIso(data.updated_at, ''),
                        },
                      ]
                    : []),
                ]}
              />
            </Panel>
            <Panel title="Production cost snapshot">
              <dl className="grid gap-x-4 gap-y-3 text-sm sm:grid-cols-[auto_1fr]">
                <dt className="text-muted">BOM used</dt>
                <dd>
                  {data.bom_id ? (
                    <Link to={`/mrp/boms/${data.bom_id}`} className="text-accent hover:underline">
                      Version {data.bom_version}
                    </Link>
                  ) : (
                    'No historical version recorded'
                  )}
                </dd>
                <dt className="text-muted">Inventory value / unit</dt>
                <dd className="font-mono tabular-nums">
                  {formatPeso(data.unit_standard_cost, 'Cost setup required')}
                </dd>
                <dt className="text-muted">Basis</dt>
                <dd>
                  {data.cost_source === 'product_standard_cost'
                    ? 'Product standard cost'
                    : 'Not recorded'}
                </dd>
                <dt className="text-muted">Frozen at</dt>
                <dd>{data.cost_snapshot_at ? formatDateTime(data.cost_snapshot_at) : 'Pending'}</dd>
              </dl>
              <p className="mt-4 text-xs text-muted">
                Standard valuation is an estimate. Materials shows standard and actual usage; repair
                receipts preserve the original lot value.
              </p>
            </Panel>
          </div>
        </div>
      )}

      {tab === 'operations' && (
        <div className="px-5 py-4">
          <p className="mb-3 text-sm text-muted">
            Operation operator:{' '}
            {operationCrew.data?.current?.members.find((member) => member.id === employeeId)
              ?.name ?? 'Assign a crew first'}{' '}
            (designated output recorder).
          </p>
          <Panel
            title="Operations"
            meta={operations.data ? `${operations.data.length} operations` : undefined}
            noPadding
          >
            {operations.isLoading && (
              <div className="p-4 text-sm text-muted">Loading operations...</div>
            )}
            {operations.isError && (
              <div className="p-4">
                <EmptyState
                  icon="alert-circle"
                  title="Failed to load operations"
                  action={
                    <Button variant="secondary" size="sm" onClick={() => operations.refetch()}>
                      Retry
                    </Button>
                  }
                />
              </div>
            )}
            {operations.data && operations.data.length === 0 && (
              <div className="p-4 text-sm text-muted">
                No operations defined for this work order.
              </div>
            )}
            {operations.data && operations.data.length > 0 && (
              <div className="overflow-x-auto">
                <table className={`${tableCls} min-w-[980px]`}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th align="right" className="w-14">
                        #
                      </Th>
                      <Th>Operation</Th>
                      <Th>Status</Th>
                      <Th>Operator</Th>
                      <Th>Machine</Th>
                      <Th align="right">Qty progress</Th>
                      <Th>Start</Th>
                      <Th>End</Th>
                      <Th>Actions</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {operations.data.map((op) => (
                      <tr key={op.id} className={trCls}>
                        <Td align="right" mono>
                          {op.sequence}
                        </Td>
                        <Td>{op.operation_name}{op.logs?.filter((log) => log.material_handover_exception).map((log) => <MaterialHandoverExceptionDetails key={log.id} exception={log.material_handover_exception} />)}</Td>
                        <Td>
                          <Chip variant={OP_STATUS_CHIP[op.status]}>
                            {operationStatusLabels.get(op.status) ?? op.status}
                          </Chip>
                        </Td>
                        <Td>
                          {op.operator ? (
                            `${op.operator.first_name} ${op.operator.last_name}`
                          ) : (
                            <span className="text-muted">—</span>
                          )}
                        </Td>
                        <Td mono>
                          {op.machine?.machine_code ?? <span className="text-muted">—</span>}
                        </Td>
                        <Td align="right" mono>
                          {formatInt(op.qty_completed)} / {formatInt(op.qty_planned)}
                        </Td>
                        <Td mono>{formatDateTime(op.actual_start)}</Td>
                        <Td mono>{formatDateTime(op.actual_end)}</Td>
                        <Td>
                          {data.status !== 'in_progress' ? (
                            <span className="text-xs text-muted">Available after WO start</span>
                          ) : (
                            <div className="flex flex-wrap gap-1">
                              {op.status === 'pending' && (
                                <>
                                  {canLifecycle && (
                                    <Button
                                      size="xs"
                                      variant="secondary"
                                      disabled={
                                        !employeeId || operationMut.isPending || setupBlocked
                                      }
                                      onClick={() =>
                                        operationMut.mutate({
                                          action: 'startSetup',
                                          operationId: op.id,
                                        })
                                      }
                                    >
                                      Setup
                                    </Button>
                                  )}
                                  {canLifecycle && (
                                    <Button
                                      size="xs"
                                      variant="primary"
                                      disabled={
                                        !employeeId || operationMut.isPending || processQcBlocked
                                      }
                                      onClick={() =>
                                        operationMut.mutate({ action: 'start', operationId: op.id })
                                      }
                                    >
                                      Start
                                    </Button>
                                  )}
                                </>
                              )}
                              {op.status === 'setup' && (
                                <>
                                  {canLifecycle && (
                                    <Button
                                      size="xs"
                                      variant="secondary"
                                      disabled={operationMut.isPending || setupBlocked}
                                      onClick={() =>
                                        operationMut.mutate({
                                          action: 'endSetup',
                                          operationId: op.id,
                                        })
                                      }
                                    >
                                      End setup
                                    </Button>
                                  )}
                                  {canLifecycle && (
                                    <Button
                                      size="xs"
                                      variant="primary"
                                      disabled={
                                        !employeeId || operationMut.isPending || processQcBlocked
                                      }
                                      onClick={() =>
                                        operationMut.mutate({ action: 'start', operationId: op.id })
                                      }
                                    >
                                      Start
                                    </Button>
                                  )}
                                </>
                              )}
                              {op.status === 'in_progress' && (
                                <>
                                  {canLifecycle && (
                                    <Button
                                      size="xs"
                                      variant="secondary"
                                      disabled={operationMut.isPending}
                                      onClick={() =>
                                        operationMut.mutate({ action: 'pause', operationId: op.id })
                                      }
                                    >
                                      Pause
                                    </Button>
                                  )}
                                  {canRecord && (
                                    <Button
                                      size="xs"
                                      variant="primary"
                                      disabled={
                                        !employeeId ||
                                        operationDialogMut.isPending ||
                                        processQcBlocked
                                      }
                                      onClick={() =>
                                        { setOperationHandoverAcknowledged(false); setOperationHandoverReason(''); setOperationDialog({ kind: 'record', operation: op }); }
                                      }
                                    >
                                      Output
                                    </Button>
                                  )}
                                  {canLifecycle && (
                                    <Button
                                      size="xs"
                                      variant="secondary"
                                      disabled={operationMut.isPending || processQcBlocked}
                                      onClick={() =>
                                        operationMut.mutate({
                                          action: 'complete',
                                          operationId: op.id,
                                        })
                                      }
                                    >
                                      Complete
                                    </Button>
                                  )}
                                </>
                              )}
                              {op.status === 'paused' && canLifecycle && (
                                <Button
                                  size="xs"
                                  variant="primary"
                                  disabled={
                                    !employeeId || operationMut.isPending || processQcBlocked
                                  }
                                  onClick={() =>
                                    operationMut.mutate({ action: 'resume', operationId: op.id })
                                  }
                                >
                                  Resume
                                </Button>
                              )}
                              {canLifecycle && !['completed', 'skipped'].includes(op.status) && (
                                <Button
                                  size="xs"
                                  variant="ghost"
                                  disabled={
                                    !employeeId || operationDialogMut.isPending || processQcBlocked
                                  }
                                  onClick={() =>
                                    setOperationDialog({ kind: 'skip', operation: op })
                                  }
                                >
                                  Skip
                                </Button>
                              )}
                            </div>
                          )}
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        </div>
      )}

      {showConfirmDialog && operationCrew.data && (
        <AssignmentReview
          woId={data.id}
          context={operationCrew.data}
          generate
          confirm
          onClose={() => setShowConfirmDialog(false)}
        />
      )}

      <Modal
        isOpen={showPauseDialog}
        onClose={() => setShowPauseDialog(false)}
        title={
          <>
            Pause work order <span className="font-mono">{data.wo_number}</span>
          </>
        }
        size="md"
      >
        <div className="px-5 py-4 space-y-4">
          <Select
            label="Downtime category"
            required
            value={pauseCategory}
            onChange={(event) => setPauseCategory(event.target.value as MachineDowntimeCategory)}
            error={downtimeCategories.isError ? 'Could not load downtime categories.' : undefined}
          >
            <option value="">Select the actual cause…</option>
            {downtimeCategories.data?.map((category) => (
              <option key={category.value} value={category.value}>
                {category.label}
                {category.is_planned ? ' (planned)' : ''}
              </option>
            ))}
          </Select>
          <Textarea
            label="Reason"
            required
            maxLength={200}
            rows={3}
            value={pauseReason}
            onChange={(event) => setPauseReason(event.target.value)}
            placeholder="Describe what stopped production."
          />
          <p className="text-xs text-muted">
            This reason and category feed the machine downtime and OEE reports.
          </p>
          <ModalFooter>
            <Button variant="secondary" onClick={() => setShowPauseDialog(false)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              icon={<LuPause size={14} />}
              disabled={
                !pauseCategory || !pauseReason.trim() || mut.isPending || downtimeCategories.isError
              }
              onClick={() => mut.mutate('pause')}
            >
              {mut.isPending ? 'Pausing…' : 'Pause work order'}
            </Button>
          </ModalFooter>
        </div>
      </Modal>

      <Modal
        isOpen={operationDialog !== null}
        onClose={() => {
          if (!operationDialogMut.isPending) setOperationDialog(null);
        }}
        title={operationDialog?.kind === 'skip' ? 'Skip operation' : 'Record operation output'}
        size="md"
      >
        <div className="space-y-4">
          {operationDialog?.kind === 'skip' ? (
            <>
              <p className="text-sm text-muted">
                Skip{' '}
                <span className="font-medium text-primary">
                  {operationDialog.operation.operation_name}
                </span>{' '}
                only when the routing step will not run.
              </p>
              <Textarea
                label="Reason"
                required
                rows={3}
                maxLength={500}
                value={operationSkipReason}
                onChange={(event) => setOperationSkipReason(event.target.value)}
                placeholder="Explain why this operation is being skipped."
              />
            </>
          ) : (
            <>
              <p className="text-sm text-muted">
                Record output for{' '}
                <span className="font-medium text-primary">
                  {operationDialog?.operation.operation_name}
                </span>
                . The server enforces the routing quantity policy.
              </p>
              <div className="grid grid-cols-2 gap-3">
                <Input
                  label="Quantity"
                  required
                  type="number"
                  min={0.0001}
                  step="0.0001"
                  value={operationQty}
                  onChange={(event) => setOperationQty(event.target.value)}
                  className="font-mono text-right"
                />
                <Input
                  label="Scrap"
                  type="number"
                  min={0}
                  step="0.0001"
                  value={operationScrap}
                  onChange={(event) => setOperationScrap(event.target.value)}
                  className="font-mono text-right"
                />
              </div>
              <Textarea
                label="Scrap reason"
                rows={2}
                maxLength={500}
                value={operationScrapReason}
                onChange={(event) => setOperationScrapReason(event.target.value)}
                placeholder="Optional reason for scrap."
              />
              <MaterialHandoverNotice pending={handoverPending} canAcknowledge={canRecord && canLifecycle} acknowledged={operationHandoverAcknowledged} reason={operationHandoverReason} onAcknowledged={setOperationHandoverAcknowledged} onReason={setOperationHandoverReason} disabled={operationDialogMut.isPending} />
              <Textarea
                label="Earlier output reason (if attendance changed)"
                rows={2}
                maxLength={500}
                value={operationRecordingReason}
                onChange={(event) => setOperationRecordingReason(event.target.value)}
                placeholder="Explain when this output was produced."
              />
            </>
          )}
          <ModalFooter>
            <Button
              variant="secondary"
              disabled={operationDialogMut.isPending}
              onClick={() => setOperationDialog(null)}
            >
              Cancel
            </Button>
            <Button
              variant={operationDialog?.kind === 'skip' ? 'danger' : 'primary'}
              disabled={
                operationDialogMut.isPending ||
                !employeeId ||
                (operationDialog?.kind === 'skip'
                  ? !operationSkipReason.trim()
                  : operationHandoverBlocked || !(Number(operationQty) > 0) ||
                    Number(operationScrap) < 0 ||
                    Number(operationScrap) > Number(operationQty))
              }
              loading={operationDialogMut.isPending}
              onClick={() => operationDialogMut.mutate()}
            >
              {operationDialog?.kind === 'skip' ? 'Skip operation' : 'Record output'}
            </Button>
          </ModalFooter>
        </div>
      </Modal>

      <Modal
        isOpen={releaseOpen}
        onClose={() => setReleaseOpen(false)}
        title="Release paused resources"
      >
        <p className="text-sm">
          Release this work order’s machine, mold and worker runtime responsibility. Output stays
          unchanged. PPC must review a new slot before resume.
        </p>
        <Textarea
          className="mt-4"
          label="Release reason"
          aria-label="Release reason"
          value={releaseReason}
          onChange={(event) => setReleaseReason(event.target.value)}
          required
        />
        {release.isError && (
          <p role="alert" className="mt-3 text-sm text-danger-fg">
            {release.error instanceof AxiosError
              ? (release.error.response?.data?.message ?? 'Release failed. Try again.')
              : 'Release failed. Try again.'}
          </p>
        )}
        <ModalFooter>
          <Button variant="secondary" onClick={() => setReleaseOpen(false)}>
            Cancel
          </Button>
          <Button
            disabled={!releaseReason.trim() || release.isPending}
            loading={release.isPending}
            onClick={() => release.mutate()}
          >
            Release resources
          </Button>
        </ModalFooter>
      </Modal>
      {executionAction && (
        <StartProductionDialog
          woId={data.id}
          woNumber={data.wo_number}
          action={executionAction}
          onClose={() => setExecutionAction(null)}
        />
      )}
      {data.rework?.map((repair) => (
        <ReworkPanel
          key={repair.id}
          woId={data.id}
          woNumber={data.wo_number}
          isOpen={reworkDialogId === repair.id}
          onClose={() => setReworkDialogId(null)}
          workOrderStatus={data.status}
          rework={repair}
          canLifecycle={canLifecycle && data.status === 'completed'}
          canRecord={canRecord && data.status === 'completed'}
          canViewQc={can('quality.inspections.view')}
          canViewNcr={can('quality.ncr.view')}
        />
      ))}
      <ConfirmDialog
        isOpen={!!confirmAction}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => {
          if (confirmAction) mut.mutate(confirmAction);
        }}
        title={
          confirmAction === 'complete' && finishFirst ? 'Finish production?' : confirmAction
            ? `${confirmAction[0].toUpperCase()}${confirmAction.slice(1)} this work order?`
            : ''
        }
        description={
          confirmAction ? (
            <>
              {confirmAction === 'cancel' && stockProduction ? (
                <div className="space-y-3 text-sm">
                  <p>Cancel <span className="font-mono">{data.wo_number}</span> and release its unused commitments. Work order and assignment history remain available.</p>
                  {(data.machine || data.mold || operationCrew.data?.current || operationCrew.data?.schedule) && (
                    <div className="rounded-md border border-default bg-canvas p-3 text-xs space-y-1">
                      {data.machine && <p>Machine: {data.machine.machine_code}</p>}
                      {data.mold && <p>Mold: {data.mold.mold_code}</p>}
                      {operationCrew.data?.schedule && <p>Schedule: {productionDateTime(operationCrew.data.schedule.scheduled_start)} – {productionDateTime(operationCrew.data.schedule.scheduled_end)} (Manila)</p>}
                      {operationCrew.data?.current && <p>Workers: {operationCrew.data.current.members.map(member => member.name).join(', ')}</p>}
                    </div>
                  )}
                  <ul className="list-disc pl-4 space-y-1">
                    <li>Machine, mold and schedule reservations</li>
                    <li>Active worker assignments</li>
                    <li>Unissued material holds</li>
                    <li>Untouched subassemblies and automatic draft purchase requests</li>
                  </ul>
                  <p>Issued materials, started production or committed purchasing block cancellation. If blocked, nothing changes.</p>
                  <Textarea label="Cancellation reason" required maxLength={500} value={cancellationReason}
                    onChange={event => setCancellationReason(event.target.value)}
                    error={cancellationReason.length > 0 && !cancellationReason.trim() ? 'Enter a cancellation reason.' : cancellationReason.trim().length > 500 ? 'Use 500 characters or fewer.' : undefined}
                    helper="Required; saved with cancellation." />
                  {mut.isError && <p role="alert" className="text-danger-fg">{mut.error.response?.data?.message ?? 'Cancellation failed. Try again.'}</p>}
                </div>
              ) : confirmAction === 'complete' && finishFirst ? 'Finish this run and send its produced pieces to final QC.' : <>This will run the <span className="font-mono">{confirmAction}</span> lifecycle action on <span className="font-mono">{data.wo_number}</span>.</>}
              {confirmAction === 'complete' && finishFirst && <Input className="mt-3" label="Actual production finish (Asia/Manila)" type="datetime-local"
                value={productionFinishedAt} onChange={event => setProductionFinishedAt(event.target.value)} helper="Leave blank to finish now. Use the real time for finished production reported late." />}
              {confirmAction === 'complete' &&
                Number(physicalQuantity) < Number(data.quantity_target) && (
                  <Textarea
                    className="mt-3"
                    label="Short-completion reason"
                    required
                    maxLength={1000}
                    value={shortCompletionReason}
                    onChange={(event) => setShortCompletionReason(event.target.value)}
                    helper={`Produced ${physicalQuantity} pieces against target ${data.quantity_target}. Explain the shortfall before finishing production.`}
                  />
                )}
            </>
          ) : null
        }
        confirmLabel={
          confirmAction === 'cancel' && stockProduction ? 'Cancel WO' : confirmAction === 'complete' && finishFirst ? 'Finish production' : confirmAction ? `${confirmAction[0].toUpperCase()}${confirmAction.slice(1)}` : 'Confirm'
        }
        cancelLabel={confirmAction === 'cancel' ? 'Keep work order' : 'Cancel'}
        variant={confirmAction === 'cancel' ? 'danger' : 'primary'}
        pending={mut.isPending}
        disabled={
          (confirmAction === 'cancel' && stockProduction && (!cancellationReason.trim() || cancellationReason.trim().length > 500)) ||
          confirmAction === 'complete' &&
          Number(physicalQuantity) < Number(data.quantity_target) &&
          !shortCompletionReason.trim()
        }
      />
    </div>
  );
}
