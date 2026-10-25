import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { Textarea } from '@/components/ui/Textarea';
import { formatDateTime } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import { usePermission } from '@/hooks/usePermission';
import type { MaintenanceWorkOrder } from '@/types/maintenance';
import { ReturnSparePartButton } from './ReturnSparePartButton';

export function PartReconciliationPanel({
  order,
  isSupervisor,
  inspectionReason,
  onInspectionReasonChange,
  touch = false,
}: {
  order: MaintenanceWorkOrder;
  isSupervisor: boolean;
  inspectionReason: string;
  onInspectionReasonChange: (reason: string) => void;
  touch?: boolean;
}) {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const handoverPending = (order.parts_handover?.pending ?? 0) > 0;
  const parts = order.parts_readiness;
  const outstanding = parts?.outstanding ?? [];
  const canCloseParts = parts?.can_close_parts ?? order.spare_parts?.length === 0;
  const canReconcile = order.can_record_work || (can('maintenance.wo.complete') || can('maintenance.wo.assign'))
    && (order.available_actions?.some((action) => action === 'complete' || action === 'submit_repair' || action === 'cancel') ?? false);
  const canReturn = order.can_record_work ?? (can('maintenance.wo.complete') && !order.repair_submitted_at
    && (order.available_actions?.some((action) => action === 'complete' || action === 'submit_repair') ?? false));
  const mutation = useMutation({
    mutationFn: () => workOrdersApi.reconcileParts(order.id, {
      ...(outstanding.length ? { confirm_consumed: true as const } : {}),
      ...(isSupervisor ? { inspection_reason: inspectionReason.trim() } : {}),
    }),
    onSuccess: (updated) => {
      queryClient.setQueryData(['maintenance', 'work-order', order.id], updated);
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', order.id] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      toast.success('Parts reconciliation recorded.');
    },
    onError: (error) => reportMutationError(error, 'Could not reconcile remaining parts.'),
  });

  return (
    <Panel title="Parts closeout">
      {order.part_requests && order.part_requests.length > 0 && <ul className="mb-4 space-y-2">
        {order.part_requests.map((request) => <li key={request.id} className="rounded-md bg-surface p-3 text-sm">
          <p className="font-medium">{request.item_code} · {request.quantity} {request.unit}</p>
          <p className="text-xs text-muted">{request.location} · {request.status === 'requested' ? 'Awaiting Warehouse handover' : request.status === 'handed_over' ? 'Handed over' : 'Withdrawn'}</p>
          {request.status === 'requested' && order.can_record_work && <Button variant="danger" size="sm" className="mt-2" onClick={async () => {
            try {
              await workOrdersApi.withdrawPartRequest(order.id, request.id);
              await queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', order.id] });
              await queryClient.invalidateQueries({ queryKey: ['inventory', 'material-handovers'] });
              toast.success('Part request withdrawn.');
            } catch (error) { reportMutationError(error, 'Could not withdraw request.'); }
          }}>Withdraw request</Button>}
        </li>)}
      </ul>}
      {handoverPending && <p role="status" className="mb-3 text-sm text-warning-fg">{order.parts_handover?.blocker}</p>}

      {outstanding.length === 0 ? (
        <p className="text-sm text-secondary">No parts to reconcile</p>
      ) : (
        <ul className="space-y-3">
          {outstanding.map((part) => {
            const usage = order.spare_parts?.find((row) => row.id === part.usage_id);
            return (
              <li key={part.usage_id} className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-surface p-3">
                <div className="min-w-0">
                  <p className="text-sm font-medium">{part.item_code} · {part.item_name}</p>
                  <p className="text-xs text-muted">{part.lot_number ? `Lot ${part.lot_number}` : 'No lot recorded'} · Outstanding {part.quantity}</p>
                </div>
                {usage && canReturn && <ReturnSparePartButton workOrderId={order.id} usage={usage} desktop={!touch} />}
              </li>
            );
          })}
        </ul>
      )}

      {isSupervisor && order.available_actions?.includes('cancel') && (
        <div className="mt-3">
          <Textarea
            label="Supervisor inspection reason"
            value={inspectionReason}
            onChange={(event) => onInspectionReasonChange(event.target.value)}
            rows={3}
            required
            maxLength={5000}
            helper="Required for supervisor inspection and cancellation."
          />
        </div>
      )}

      {!canCloseParts && parts?.blocker && <p role="status" className="mt-3 text-sm text-warning-fg">{parts.blocker}</p>}
      {canCloseParts && outstanding.length > 0 && order.parts_reconciled_at && order.parts_reconciled_by && (
        <p className="mt-3 text-sm text-secondary">
          Remaining parts confirmed consumed by {order.parts_reconciled_by.name} · {formatDateTime(order.parts_reconciled_at)}
        </p>
      )}
      {outstanding.length > 0 && !canCloseParts && !handoverPending && canReconcile && (
        <Button
          type="button"
          variant="primary"
          size={touch ? 'lg' : 'sm'}
          className={touch ? 'mt-3 min-h-hit w-full' : 'mt-3'}
          disabled={isSupervisor && inspectionReason.trim().length < 5}
          loading={mutation.isPending}
          onClick={() => mutation.mutate()}
        >
          Confirm remaining parts consumed
        </Button>
      )}
    </Panel>
  );
}
