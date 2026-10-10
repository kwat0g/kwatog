/** Sprint 8 — Task 69. Maintenance WO detail. Action buttons gated by status + permission. */
import { useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { LuPlus } from '@/lib/icons';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import { maintenanceStatusVariant as STATUS_CHIP } from '@/lib/statusVariants';
import type { ChainStep } from '@/types/chain';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { formatPeso } from '@/lib/formatNumber';
import { reportMutationError } from '@/lib/formErrors';
import { RecoveryPanel, WorkLogControl, WorkLogContent } from './RecoveryPanel';
import { SparePartSheet } from './SparePartSheet';
import { AssignmentPanel } from './AssignmentPanel';
import { PartReconciliationPanel } from './PartReconciliationPanel';
import { useAuthStore } from '@/stores/authStore';

export default function MaintenanceWorkOrderDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const { can, canAny } = usePermission();
  const selfService = useLocation().pathname.startsWith('/self-service/');
  const isSupervisor = useAuthStore((state) => state.user?.role.slug === 'maintenance_supervisor');
  const qc = useQueryClient();
  const [completeOpen, setCompleteOpen] = useState(false);
  const [completeRemarks, setCompleteRemarks] = useState('');
  const [confirmStart, setConfirmStart] = useState(false);
  // The cancellation reason is part of the work order's history, so it is
  // collected in ReasonDialog rather than window.prompt.
  const [cancelOpen, setCancelOpen] = useState(false);
  const [partOpen, setPartOpen] = useState(false);
  const [inspectionReason, setInspectionReason] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['maintenance', 'work-order', id],
    queryFn: () => workOrdersApi.show(id),
    refetchInterval: 15_000,
    refetchOnWindowFocus: true,
  });
  const { data: options } = useQuery({
    queryKey: ['maintenance', 'work-order-options'],
    queryFn: workOrdersApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const startMutation = useMutation({
    mutationFn: () => workOrdersApi.start(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', id] });
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      setConfirmStart(false);
      toast.success('Work order started.');
    },
    onError: (error) => reportMutationError(error, 'Could not start the work order.'),
  });
  const completeMutation = useMutation({
    mutationFn: () =>
      workOrdersApi.complete(id, {
        remarks: completeRemarks || undefined,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', id] });
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      toast.success('Work order completed.');
      setCompleteOpen(false);
    },
    onError: (error) => reportMutationError(error, 'Could not complete the work order.'),
  });
  const cancelMutation = useMutation({
    mutationFn: async (reason: string) => {
      if (isSupervisor) {
        const updated = await workOrdersApi.reconcileParts(id, {
          ...(data?.parts_readiness?.outstanding.length ? { confirm_consumed: true } : {}),
          inspection_reason: inspectionReason.trim(),
        });
        qc.setQueryData(['maintenance', 'work-order', id], updated);
        void qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', id] });
      }
      return workOrdersApi.cancel(id, reason);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', id] });
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      toast.success('Work order cancelled.');
      setCancelOpen(false);
      setInspectionReason('');
    },
    onError: (error) => reportMutationError(error, 'Could not cancel the work order.'),
  });
  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  }

  const statusOptions = (options?.statuses ?? []).filter((status) => status.value !== 'cancelled');
  const statusLabel = new Map(statusOptions.map((status) => [status.value, status.label]));
  const currentStep = statusOptions.findIndex((status) => status.value === data.status);
  const chainSteps: ChainStep[] = statusOptions.map((status, idx) => {
    const s = status.value;
    const state: ChainStep['state'] =
      data.status === 'cancelled'
        ? s === 'open'
          ? 'done'
          : 'pending'
        : idx < currentStep
          ? 'done'
          : idx === currentStep
            ? 'active'
            : 'pending';
    return { key: s, label: statusLabel.get(s) ?? s.replace('_', ' '), state };
  });

  const hasAction = (action: NonNullable<typeof data.available_actions>[number]) =>
    data.available_actions?.includes(action) ?? false;
  const canCloseWork = data.closure_readiness?.can_close_work ?? false;
  const canCloseParts = data.parts_readiness?.can_close_parts ?? data.spare_parts?.length === 0;

  return (
    <div>
      <PageHeader
        title={data.mwo_number}
        subtitle={
          data.maintainable ? `${data.maintainable.code ?? data.maintainable_type} · ${data.maintainable.name}` : undefined
        }
        backTo={selfService ? "/self-service/maintenance-work-orders" : "/maintenance/work-orders"}
        backLabel="Work orders"
        bottom={<ChainHeader steps={chainSteps} />}
        actions={
          <div className="flex flex-wrap gap-1.5 sm:max-w-64 xl:max-w-none">
            <Chip variant={STATUS_CHIP[data.status]}>
              {data.status_label ?? statusLabel.get(data.status) ?? data.status}
            </Chip>
            {hasAction('start') && canAny('maintenance.wo.complete', 'maintenance.wo.assign') && (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => setConfirmStart(true)}
                loading={startMutation.isPending}
              >
                Start
              </Button>
            )}
            <WorkLogControl order={data} />
            {!data.verification_required && hasAction('complete') && canCloseWork && canCloseParts && canAny('maintenance.wo.complete', 'maintenance.wo.assign') && (
              <Button variant="primary" size="sm" onClick={() => setCompleteOpen(true)}>
                Complete
              </Button>
            )}
            {hasAction('cancel') && canCloseParts && canAny('maintenance.wo.complete', 'maintenance.wo.assign') && (!isSupervisor || inspectionReason.trim().length >= 5) && (
              <Button
                variant="danger"
                size="sm"
                onClick={() => setCancelOpen(true)}
                loading={cancelMutation.isPending}
              >
                Cancel
              </Button>
            )}
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 px-5 py-5 xl:grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        <div className="min-w-0 space-y-4">
          <Panel title="Description">
            <p className="text-sm whitespace-pre-line">{data.description}</p>
            {data.remarks && (
              <div className="mt-3 pt-3 border-t border-subtle text-sm">
                <span className="text-xs uppercase tracking-wider text-muted block mb-1">
                  {data.status === 'cancelled' ? 'Cancellation reason' : 'Work performed'}
                </span>
                <span className="text-secondary whitespace-pre-line">{data.remarks}</span>
              </div>
            )}
          </Panel>

          <Panel title="Work history" meta={data.logs?.length ? `${data.logs.length}` : undefined}>
            {!canCloseWork && (hasAction('complete') || hasAction('submit_repair')) && <p className="mb-3 text-xs text-muted">{data.closure_readiness?.blocker}</p>}
            {data.logs && data.logs.length > 0 ? (
              <ul className="divide-y divide-subtle">
                {data.logs.map((log) => (
                  <li key={log.id} className="py-2">
                    <WorkLogContent log={log} />
                    <div className="text-xs text-muted font-mono">
                      {log.created_at ? formatDateTime(log.created_at) : '—'}
                      {log.logger ? ` · ${log.logger.name}` : ''}
                    </div>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted">No work recorded yet.</p>
            )}
          </Panel>

          <PartReconciliationPanel
            order={data}
            isSupervisor={isSupervisor}
            inspectionReason={inspectionReason}
            onInspectionReasonChange={setInspectionReason}
          />

          <Panel
            title="Spare parts"
            meta={data.spare_parts?.length ? `${data.spare_parts.length}` : undefined}
            actions={(data.can_record_work ?? ((hasAction('complete') || hasAction('submit_repair')) && can('maintenance.wo.complete'))) ? (
              <Button variant="secondary" size="sm" icon={<LuPlus size={14} />} onClick={() => setPartOpen(true)}>
                Request spare parts
              </Button>
            ) : undefined}
          >
            {data.spare_parts && data.spare_parts.length > 0 ? (
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Item</Th>
                    <Th align="right">Gross / returned / net qty</Th>
                    <Th align="right">Unit cost</Th>
                    <Th align="right">Gross / returned / net cost</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.spare_parts.map((sp) => (
                    <tr key={sp.id} className={trCls}>
                      <Td>
                        <div>
                          {sp.item ? (
                            <>
                              <span className="font-mono">{sp.item.code}</span>
                              <span className="ml-2 text-muted">{sp.item.name}</span>
                            </>
                          ) : '—'}
                          {sp.stock_movement_id && can('inventory.view') && <Link className="ml-2 text-accent" to={`/inventory/stock-levels?view=movements&movement_id=${sp.stock_movement_id}`}>Stock movement</Link>}
                          {sp.lot_number && <span className="ml-2 text-muted">Lot {sp.lot_number}</span>}
                          <div className="mt-1 text-xs text-muted">
                            {sp.work_record ? <>Work record: {sp.work_record.finding} · {sp.work_record.action_taken}</> : 'Work record not captured.'}
                            {' · Issued by '}{sp.issuer?.name ?? '—'}
                          </div>
                        </div>
                      </Td>
                      <Td align="right" mono>
                        {sp.quantity} / {sp.returned_quantity} / {(Number(sp.quantity) - Number(sp.returned_quantity)).toFixed(3)}
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(sp.unit_cost)}
                      </Td>
                      <Td align="right" mono className="font-medium">
                        {formatPeso(sp.total_cost)} / {formatPeso(sp.returned_cost)} /{' '}
                        {formatPeso((Number(sp.total_cost) - Number(sp.returned_cost)).toFixed(2))}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="text-sm text-muted">No spare parts consumed.</p>
            )}
          </Panel>
        </div>

        <aside className="min-w-0 space-y-4">
          <RecoveryPanel order={data} />
          <Panel title="Details">
            <dl className="text-sm divide-y divide-subtle">
              <Detail label="Type">{data.type_label ?? data.type}</Detail>
              <Detail label="Priority">{data.priority_label ?? data.priority}</Detail>
              <Detail label="Assignee">{data.assignee?.name ?? '—'}</Detail>
              <Detail label="Started">
                {data.started_at ? formatDateTime(data.started_at) : '—'}
              </Detail>
              <Detail label="Completed">
                {data.completed_at ? formatDateTime(data.completed_at) : '—'}
              </Detail>
            </dl>
          </Panel>
          {hasAction('assign') && can('maintenance.wo.assign') && (
            <AssignmentPanel order={data} />
          )}
        </aside>
      </div>

      <Modal
        isOpen={completeOpen}
        onClose={() => setCompleteOpen(false)}
        size="sm"
        title="Complete work order"
      >
        <div className="py-3 space-y-3">
          <Textarea
            label="Remarks (optional)"
            value={completeRemarks}
            onChange={(e) => setCompleteRemarks(e.target.value)}
            rows={3}
          />
          <p className="text-xs text-muted">Downtime is calculated from the machine downtime record.</p>
        </div>
        <ModalFooter>
          <Button variant="secondary" onClick={() => setCompleteOpen(false)}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={() => completeMutation.mutate()}
            loading={completeMutation.isPending}
          >
            {completeMutation.isPending ? 'Completing…' : 'Confirm complete'}
          </Button>
        </ModalFooter>
      </Modal>

      <ConfirmDialog
        isOpen={confirmStart}
        onClose={() => setConfirmStart(false)}
        onConfirm={() => startMutation.mutate()}
        title="Start work order?"
        description={
          <>
            Work starts on <span className="font-mono">{data.mwo_number}</span>
            {data.maintainable ? <> for {data.maintainable.name}</> : null}. Downtime uses the recorded equipment downtime start.
          </>
        }
        variant="primary"
        confirmLabel="Start"
        pending={startMutation.isPending}
      />

      <ReasonDialog
        isOpen={cancelOpen}
        onClose={() => setCancelOpen(false)}
        onConfirm={(reason) => cancelMutation.mutate(reason)}
        title={`Cancel ${data.mwo_number}?`}
        description={
          <>
            Cancelling closes this work order without the maintenance being done
            {data.maintainable ? <> on {data.maintainable.name}</> : null}
            {Number(data.cost) > 0 ? <> ({formatPeso(data.cost)} of parts already booked)</> : null}
            . It cannot be reopened — raise a new work order instead. Your reason is kept on the
            record.
            {isSupervisor && <p className="mt-2">Supervisor inspection reason is recorded with the parts reconciliation before cancellation.</p>}
          </>
        }
        reasonLabel="Cancellation reason"
        reasonPlaceholder="e.g. Duplicate of MWO-202608-0031; machine already repaired"
        minLength={5}
        confirmLabel="Cancel work order"
        cancelLabel="Keep it open"
        variant="danger"
        pending={cancelMutation.isPending}
      />
      <SparePartSheet
        workOrderId={id}
        logs={data.logs ?? []}
        isOpen={partOpen && (data.can_record_work ?? ((hasAction('complete') || hasAction('submit_repair')) && can('maintenance.wo.complete')))}
        onClose={() => setPartOpen(false)}
        desktop
      />
    </div>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between py-1.5">
      <span className="text-xs uppercase tracking-wider text-muted">{label}</span>
      <span className="text-sm">{children}</span>
    </div>
  );
}
