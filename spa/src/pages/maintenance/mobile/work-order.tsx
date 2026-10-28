import { reportMutationError } from '@/lib/formErrors';
import { useState } from 'react';
import { useParams, Link, useLocation } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import toast from 'react-hot-toast';
import { LuArrowLeft, LuPlus, LuTriangleAlert } from '@/lib/icons';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Textarea } from '@/components/ui/Textarea';
import { RecoveryPanel, WorkLogControl, WorkLogContent } from '@/pages/maintenance/work-orders/RecoveryPanel';
import { SparePartSheet } from '@/pages/maintenance/work-orders/SparePartSheet';
import { AssignmentPanel } from '@/pages/maintenance/work-orders/AssignmentPanel';
import { PartReconciliationPanel } from '@/pages/maintenance/work-orders/PartReconciliationPanel';
import {
  TouchCardSkeleton,
  TouchConfirmSheet,
  useTouchSubmitLabel,
} from '@/components/layout/TouchShell';
import { maintenancePriorityVariant, maintenanceStatusVariant } from '@/lib/statusVariants';
import type { SparePartUsage } from '@/types/maintenance';
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';

export default function MobileWorkOrderDetail() {
  const { id: mwoId } = useParams<{ id: string }>();
  const { can, canAny } = usePermission();
  const selfService = useLocation().pathname.startsWith('/self-service/');
  const isSupervisor = useAuthStore((state) => state.user?.role.slug === 'maintenance_supervisor');
  const queryClient = useQueryClient();

  // ── Fetch MWO details ──────────────────────────────────
  const {
    data: wo,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['maintenance', 'work-order', mwoId],
    queryFn: () => workOrdersApi.show(mwoId!),
    enabled: !!mwoId,
    refetchInterval: 15_000,
    refetchOnWindowFocus: true,
  });

  // ── Form state ─────────────────────────────────────────
  const [remarks, setRemarks] = useState('');
  const [showPartSheet, setShowPartSheet] = useState(false);
  const [assignmentDialogOpen, setAssignmentDialogOpen] = useState(false);
  const [confirmingStart, setConfirmingStart] = useState(false);
  const [confirmingComplete, setConfirmingComplete] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [inspectionReason, setInspectionReason] = useState('');
  // ── Start mutation ─────────────────────────────────────
  const startMutation = useMutation({
    mutationFn: () => workOrdersApi.start(mwoId!),
    onSuccess: () => {
      setConfirmingStart(false);
      toast.success('Work order started');
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', mwoId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
    },
    onError: (error) => reportMutationError(error, 'Failed to start work order.'),
  });

  // ── Complete mutation ──────────────────────────────────
  const completeMutation = useMutation({
    mutationFn: () =>
      workOrdersApi.complete(mwoId!, {
        remarks: remarks.trim() || undefined,
      }),
    onSuccess: () => {
      toast.success('Work order completed');
      setConfirmingComplete(false);
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', mwoId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
    },
    onError: () => {
      toast.error('Failed to complete work order.');
      setConfirmingComplete(false);
    },
  });

  // A tech in a plant basement is a likely offline case; say the commit is
  // queued rather than letting the button spin indefinitely.
  const completeLabel = useTouchSubmitLabel(completeMutation.isPending, '', 'Completing…');

  const cancelMutation = useMutation({
    mutationFn: async (reason: string) => {
      if (isSupervisor) {
        const updated = await workOrdersApi.reconcileParts(mwoId!, {
          ...(wo?.parts_readiness?.outstanding.length ? { confirm_consumed: true } : {}),
          inspection_reason: inspectionReason.trim(),
        });
        queryClient.setQueryData(['maintenance', 'work-order', mwoId], updated);
        void queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', mwoId] });
      }
      return workOrdersApi.cancel(mwoId!, reason);
    },
    onSuccess: () => {
      toast.success('Work order cancelled');
      setCancelOpen(false);
      setInspectionReason('');
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', mwoId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
    },
    onError: (error) => reportMutationError(error, 'Failed to cancel work order.'),
  });

  // ── Loading / Error states ─────────────────────────────
  if (isLoading) {
    return <div className="mx-auto max-w-3xl p-4"><TouchCardSkeleton count={2} label="Loading work order" cardClassName="h-48" /></div>;
  }

  if (error || !wo) {
    return (
      <div className="mx-auto max-w-3xl p-4">
      <EmptyState
        icon="alert-circle"
        title="Could not load work order"
        description="Check your connection and try again."
        action={
          <Button
            type="button"
            variant="secondary"
            size="lg"
            className="min-h-hit"
            onClick={() => refetch()}
          >
            Try again
          </Button>
        }
      />
      </div>
    );
  }

  const actions = wo.available_actions ?? [];
  const isTerminal = wo.status === 'completed' || wo.status === 'cancelled';
  const canStart = actions.includes('start') && canAny('maintenance.wo.complete', 'maintenance.wo.assign');
  const canAssign = wo.status !== 'in_progress' && actions.includes('assign') && can('maintenance.wo.assign');
  const canEditParts = wo.can_record_work ?? ((actions.includes('complete') || actions.includes('submit_repair')) && can('maintenance.wo.complete'));
  const canRecordWork = canEditParts && !wo.repair_submitted_at;
  const canCloseWork = wo.closure_readiness?.can_close_work ?? false;
  const canCloseParts = wo.parts_readiness?.can_close_parts ?? wo.spare_parts?.length === 0;
  const canComplete = !wo.verification_required && actions.includes('complete') && canCloseWork && canCloseParts && canAny('maintenance.wo.complete', 'maintenance.wo.assign');

  return (
    <div className="mx-auto max-w-3xl space-y-4 p-4 md:p-6">
      {/* Back link */}
      <Link
        to={selfService ? "/self-service/maintenance-work-orders" : "/maintenance/work-orders"}
        className={cn(
          'inline-flex items-center gap-1.5 text-sm text-secondary min-h-hit rounded',
          focusRing,
        )}
      >
        <LuArrowLeft className="w-4 h-4" />
        Back to list
      </Link>

      {/* MWO summary and current actions */}
      <div className="rounded-md border border-default bg-canvas p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            <h1 className="font-mono tabular-nums text-lg font-medium md:text-2xl">{wo.mwo_number}</h1>
            <Chip variant={maintenanceStatusVariant[wo.status]}>{wo.status_label ?? wo.status.replace('_', ' ')}</Chip>
          </div>
          <div className="flex items-center gap-1.5">
            <Chip variant={maintenancePriorityVariant[wo.priority]} className="gap-1">
              {wo.priority === 'critical' && <LuTriangleAlert className="w-3 h-3" aria-hidden />}
              {wo.priority_label ?? wo.priority}
            </Chip>
          </div>
        </div>

        <div className="mt-2 text-sm font-medium">{wo.maintainable?.name ?? '—'}</div>
        <div className="text-xs text-muted mt-0.5">
          {wo.maintainable?.code ? `(${wo.maintainable.code})` : ''} &middot;{' '}
          <span>{wo.type_label ?? wo.type}</span>
        </div>

        {(canStart || canAssign || canRecordWork || canComplete) && (
          <div className="mt-4 flex flex-wrap gap-2 border-t border-subtle pt-4">
            {canAssign && !isTerminal && (
              <Button
                type="button"
                variant={canStart ? 'secondary' : 'primary'}
                size="sm"
                className="min-h-11"
                onClick={() => setAssignmentDialogOpen(true)}
              >
                {wo.assignee ? 'Change worker' : 'Assign worker'}
              </Button>
            )}
            {canStart && !isTerminal && (!canAssign || Boolean(wo.assignee)) && (
              <Button
                type="button"
                variant="primary"
                size="sm"
                className="min-h-11"
                onClick={() => setConfirmingStart(true)}
                loading={startMutation.isPending}
              >
                Confirm start
              </Button>
            )}
            {canRecordWork && <WorkLogControl order={wo} />}
            {canComplete && (
              <Button type="button" variant="primary" size="sm" className="min-h-11" onClick={() => setConfirmingComplete(true)}>
                Complete work order
              </Button>
            )}
          </div>
        )}

        {wo.assignee && (
          <div className="mt-3 border-t border-subtle pt-3 text-xs text-muted">
            Assigned to: <span className="font-medium text-secondary">{wo.assignee.name}</span>
          </div>
        )}
        <dl className="mt-3 grid grid-cols-2 gap-2 border-t border-subtle pt-3 text-xs text-secondary sm:grid-cols-4">
          <div><dt className="text-muted">Started</dt><dd>{wo.started_at ? formatDateTime(wo.started_at) : '—'}</dd></div>
          <div><dt className="text-muted">Completed</dt><dd>{wo.completed_at ? formatDateTime(wo.completed_at) : '—'}</dd></div>
        </dl>
        {wo.description && (
          <div className="mt-3 border-t border-subtle pt-3">
            <h2 className="text-xs uppercase tracking-wider text-muted">Description</h2>
            <p className="mt-1 text-sm text-secondary whitespace-pre-wrap">{wo.description}</p>
          </div>
        )}
        {wo.remarks && (
          <p className="mt-3 border-t border-subtle pt-3 text-sm text-secondary whitespace-pre-wrap">
            <span className="block text-xs text-muted">{wo.status === 'cancelled' ? 'Cancellation reason' : 'Work performed'}</span>{wo.remarks}
          </p>
        )}
      </div>

      <RecoveryPanel order={wo} />

      <PartReconciliationPanel
        order={wo}
        isSupervisor={isSupervisor}
        inspectionReason={inspectionReason}
        onInspectionReasonChange={setInspectionReason}
        touch
      />

      <Modal
        isOpen={confirmingStart}
        onClose={() => setConfirmingStart(false)}
        closeOnOverlayClick={!startMutation.isPending}
        size="lg"
        title="Confirm maintenance start"
      >
        <div className="space-y-3 pt-2">
          <p className="text-sm text-secondary">
            Confirm {wo.mwo_number}{wo.maintainable?.name ? ` for ${wo.maintainable.name}` : ''}. Downtime starts when this work order begins.
          </p>
          <p className="rounded-md border border-subtle bg-surface px-3 py-2 text-sm">
            {wo.assignee ? `Assigned worker: ${wo.assignee.name}` : 'Assign a worker before starting this maintenance order.'}
          </p>
          <ModalFooter>
            <Button variant="secondary" size="touch" onClick={() => setConfirmingStart(false)} disabled={startMutation.isPending}>
              Cancel
            </Button>
            <Button variant="primary" size="touch" onClick={() => startMutation.mutate()} disabled={!wo.assignee} loading={startMutation.isPending}>
              Confirm & start
            </Button>
          </ModalFooter>
        </div>
      </Modal>

      <Modal
        isOpen={assignmentDialogOpen && wo.status !== 'in_progress'}
        onClose={() => setAssignmentDialogOpen(false)}
        size="lg"
        title={wo.assignee ? 'Change worker' : 'Assign worker'}
      >
        <div className="space-y-3 pt-2">
          <p className="text-sm text-secondary">Confirm physical presence for workers without a verified attendance record. Assignment does not start the work order.</p>
          <AssignmentPanel order={wo} touch onAssigned={() => setAssignmentDialogOpen(false)} />
          <ModalFooter>
            <Button variant="secondary" size="touch" onClick={() => setAssignmentDialogOpen(false)}>
              Cancel
            </Button>
          </ModalFooter>
        </div>
      </Modal>

      {(wo.logs?.length || canEditParts) ? (
        <section className="rounded-md border border-default bg-canvas p-4">
          <div className="mb-3 flex items-center justify-between gap-3">
            <h2 className="text-base font-medium">Work history</h2>
            <span className="text-xs text-muted">{wo.logs?.length ?? 0} {(wo.logs?.length ?? 0) === 1 ? 'entry' : 'entries'}</span>
          </div>
          {!canCloseWork && (actions.includes('complete') || actions.includes('submit_repair')) && <p className="mb-3 text-xs text-muted">{wo.closure_readiness?.blocker}</p>}
          {wo.logs?.length ? (
            <div className="divide-y divide-subtle">
              {wo.logs.map((log) => (
                <div key={log.id} className="py-3 first:pt-0 last:pb-0 text-sm">
                  <WorkLogContent log={log} />
                  <div className="mt-1 text-xs text-muted font-mono tabular-nums">
                    {log.logger?.name ?? 'System'}
                    {log.created_at && ` · ${formatDateTime(log.created_at)}`}
                  </div>
                </div>
              ))}
            </div>
          ) : <p className="text-sm text-muted">No work recorded yet.</p>}
        </section>
      ) : null}

      {/* Parts Used section */}
      {!isTerminal && canEditParts && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-base font-medium">Parts used</h2>
            <Button
              type="button"
              variant="ghost"
              size="lg"
              icon={<LuPlus className="w-4 h-4" />}
              onClick={() => setShowPartSheet(true)}
              className="text-accent"
            >
              Request spare parts
            </Button>
          </div>

          {wo.spare_parts && wo.spare_parts.length > 0 ? (
            <div className="space-y-2">
              {wo.spare_parts.map((sp: SparePartUsage) => (
                <div
                  key={sp.id}
                  className="flex items-center justify-between p-2 rounded bg-surface text-sm"
                >
                  <div>
                    <div className="font-medium">{sp.item?.name ?? '—'}</div>
                    <div className="text-xs text-muted">
                      {sp.item?.code ?? '—'}{sp.stock_movement_id && can('inventory.view') && <Link className="inline-flex min-h-hit items-center text-accent ml-2" to={`/inventory/stock-levels?view=movements&movement_id=${sp.stock_movement_id}`}>Stock movement</Link>}{sp.lot_number ? ` · Lot ${sp.lot_number}` : ''}
                    </div>
                    <div className="mt-1 text-xs text-muted">
                      {sp.work_record ? <>Work record: {sp.work_record.finding} · {sp.work_record.action_taken}</> : 'Work record not captured.'} · Issued by {sp.issuer?.name ?? '—'}
                    </div>
                    <div className="mt-1 text-xs text-muted">
                      Gross: <span className="font-mono tabular-nums">{sp.quantity}</span> · Returned: <span className="font-mono tabular-nums">{sp.returned_quantity}</span> · Net: <span className="font-mono tabular-nums">{(Number(sp.quantity) - Number(sp.returned_quantity)).toFixed(3)}</span>
                    </div>
                  </div>
                  <div className="font-mono tabular-nums text-xs text-muted">
                    <div>Gross {formatPeso(sp.total_cost)}</div>
                    <div>Returned {formatPeso(sp.returned_cost)}</div>
                    <div>Net {formatPeso((Number(sp.total_cost) - Number(sp.returned_cost)).toFixed(2))}</div>
                  </div>
                </div>
              ))}
              <div className="text-right text-xs text-muted pt-1 border-t border-subtle">
                Parts cost:{' '}
                <span className="font-mono tabular-nums font-medium text-primary">
                  {formatPeso(wo.cost)}
                </span>
              </div>
            </div>
          ) : (
            <p className="text-sm text-muted">No parts recorded yet.</p>
          )}
        </div>
      )}

      {/* Read-only parts for terminal states */}
      {!canEditParts && wo.spare_parts && wo.spare_parts.length > 0 && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <h2 className="text-base font-medium mb-3">Parts used</h2>
          <div className="space-y-2">
            {wo.spare_parts.map((sp: SparePartUsage) => (
              <div
                key={sp.id}
                className="flex items-center justify-between p-2 rounded bg-surface text-sm"
              >
                <div>
                  <div className="font-medium">{sp.item?.name ?? '—'}</div>
                  <div className="text-xs text-muted">
                    {sp.stock_movement_id && can('inventory.view') && (
                      <Link className="inline-flex min-h-hit items-center text-accent mr-2" to={`/inventory/stock-levels?view=movements&movement_id=${sp.stock_movement_id}`}>
                        Stock movement
                      </Link>
                    )}
                    {sp.item?.code ?? '—'}{sp.lot_number && <> · Lot {sp.lot_number}</>}
                  </div>
                  <div className="mt-1 text-xs text-muted">
                    {sp.work_record ? <>Work record: {sp.work_record.finding} · {sp.work_record.action_taken}</> : 'Work record not captured.'} · Issued by {sp.issuer?.name ?? '—'}
                  </div>
                  <div className="mt-1 text-xs text-muted">
                    Gross: <span className="font-mono tabular-nums">{sp.quantity}</span> · Returned: <span className="font-mono tabular-nums">{sp.returned_quantity}</span> · Net: <span className="font-mono tabular-nums">{(Number(sp.quantity) - Number(sp.returned_quantity)).toFixed(3)}</span>
                  </div>
                </div>
                <div className="font-mono tabular-nums text-xs text-muted">
                  <div>Gross {formatPeso(sp.total_cost)}</div>
                  <div>Returned {formatPeso(sp.returned_cost)}</div>
                  <div>Net {formatPeso((Number(sp.total_cost) - Number(sp.returned_cost)).toFixed(2))}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <TouchConfirmSheet
        isOpen={confirmingComplete}
        onClose={() => setConfirmingComplete(false)}
        onConfirm={() => completeMutation.mutate()}
        title="Complete this work order?"
        confirmLabel={completeLabel || 'Yes, complete it'}
        variant="primary"
        pending={completeMutation.isPending}
        className="sm:max-w-xl"
      >
        <p>
          Closes{' '}
          <span className="font-mono tabular-nums font-medium text-primary">{wo.mwo_number}</span>
          {wo.maintainable?.name ? ` on ${wo.maintainable.name}` : ''}.
        </p>
        <p>Machine downtime is calculated from the recorded start and completion times.</p>
        <Textarea
          label="Remarks (optional)"
          value={remarks}
          onChange={(event) => setRemarks(event.target.value)}
          rows={3}
          placeholder="Add any final handover note"
        />
      </TouchConfirmSheet>

      {actions.includes('cancel') && canCloseParts && canAny('maintenance.wo.complete', 'maintenance.wo.assign') && (!isSupervisor || inspectionReason.trim().length >= 5) && (
        <Button type="button" variant="danger" size="touch" className="w-full" onClick={() => setCancelOpen(true)}>
          Cancel work order
        </Button>
      )}
      <ReasonDialog
        isOpen={cancelOpen}
        onClose={() => setCancelOpen(false)}
        onConfirm={(reason) => cancelMutation.mutate(reason)}
        title={`Cancel ${wo.mwo_number}?`}
        description={<>Cancelling closes this work order without the maintenance being done. It cannot be reopened. Your reason is kept on the record.{isSupervisor && <p className="mt-2">Supervisor inspection reason is recorded with the parts reconciliation before cancellation.</p>}</>}
        reasonLabel="Cancellation reason"
        minLength={5}
        confirmLabel="Cancel work order"
        cancelLabel="Keep it open"
        variant="danger"
        pending={cancelMutation.isPending}
      />

      <SparePartSheet
        workOrderId={mwoId!}
        logs={wo.logs ?? []}
        isOpen={showPartSheet && canEditParts}
        onClose={() => setShowPartSheet(false)}
        desktop={false}
      />
    </div>
  );
}
