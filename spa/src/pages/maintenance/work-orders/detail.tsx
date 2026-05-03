/** Sprint 8 — Task 69. Maintenance WO detail. Action buttons gated by status + permission. */
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import type { ChainStep } from '@/types/chain';
import type { MaintenanceWorkOrderStatus } from '@/types/maintenance';

const STATUS_FLOW: MaintenanceWorkOrderStatus[] = ['open', 'assigned', 'in_progress', 'completed'];
const STATUS_CHIP: Record<MaintenanceWorkOrderStatus, 'success' | 'warning' | 'info' | 'danger' | 'neutral'> = {
  open: 'warning', assigned: 'info', in_progress: 'info', completed: 'success', cancelled: 'neutral',
};

export default function MaintenanceWorkOrderDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const { can } = usePermission();
  const qc = useQueryClient();
  const [completeOpen, setCompleteOpen] = useState(false);
  const [completeRemarks, setCompleteRemarks] = useState('');
  const [completeDowntime, setCompleteDowntime] = useState<number>(0);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['maintenance', 'work-order', id],
    queryFn: () => workOrdersApi.show(id),
  });

  const startMutation = useMutation({
    mutationFn: () => workOrdersApi.start(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', id] }); toast.success('Work order started.'); },
    onError: () => toast.error('Failed to start.'),
  });
  const completeMutation = useMutation({
    mutationFn: () => workOrdersApi.complete(id, { remarks: completeRemarks || undefined, downtime_minutes: completeDowntime }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', id] });
      toast.success('Work order completed.');
      setCompleteOpen(false);
    },
    onError: () => toast.error('Failed to complete.'),
  });
  const cancelMutation = useMutation({
    mutationFn: (reason: string) => workOrdersApi.cancel(id, reason),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', id] }); toast.success('Work order cancelled.'); },
    onError: () => toast.error('Failed to cancel.'),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) {
    return <EmptyState icon="alert-circle" title="Failed to load" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  }

  const chainSteps: ChainStep[] = STATUS_FLOW.map((s) => {
    const idx = STATUS_FLOW.indexOf(s);
    const currIdx = STATUS_FLOW.indexOf(data.status);
    const state: ChainStep['state'] = data.status === 'cancelled'
      ? (s === 'open' ? 'done' : 'pending')
      : (idx < currIdx ? 'done' : idx === currIdx ? 'active' : 'pending');
    return { key: s, label: s.replace('_', ' '), state };
  });

  const isActionable = data.status !== 'completed' && data.status !== 'cancelled';

  return (
    <div>
      <PageHeader
        title={data.mwo_number}
        subtitle={data.maintainable ? `${data.maintainable_type} · ${data.maintainable.name}` : undefined}
        backTo="/maintenance/work-orders"
        backLabel="Work orders"
        actions={
          <div className="flex gap-1.5">
            <Chip variant={STATUS_CHIP[data.status]}>{data.status.replace('_', ' ')}</Chip>
            {isActionable && data.status !== 'in_progress' && can('maintenance.wo.complete') && (
              <Button variant="secondary" size="sm" onClick={() => startMutation.mutate()} loading={startMutation.isPending}>
                Start
              </Button>
            )}
            {isActionable && can('maintenance.wo.complete') && (
              <Button variant="primary" size="sm" onClick={() => setCompleteOpen(true)}>
                Complete
              </Button>
            )}
            {isActionable && can('maintenance.wo.complete') && (
              <Button variant="danger" size="sm" onClick={() => {
                const reason = window.prompt('Reason for cancellation (optional):') ?? undefined;
                cancelMutation.mutate(reason ?? '');
              }} loading={cancelMutation.isPending}>
                Cancel
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 pt-3 pb-4">
        <ChainHeader steps={chainSteps} />
      </div>

      <div className="px-5 pb-6 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Description">
            <p className="text-sm whitespace-pre-line">{data.description}</p>
            {data.remarks && (
              <div className="mt-3 pt-3 border-t border-subtle text-sm">
                <span className="text-xs uppercase tracking-wider text-muted block mb-1">Remarks</span>
                <span className="text-secondary whitespace-pre-line">{data.remarks}</span>
              </div>
            )}
          </Panel>

          <Panel title="Logs" meta={data.logs?.length ? `${data.logs.length}` : undefined}>
            {data.logs && data.logs.length > 0 ? (
              <ul className="divide-y divide-subtle">
                {data.logs.map((log) => (
                  <li key={log.id} className="py-2">
                    <div className="text-sm">{log.description}</div>
                    <div className="text-xs text-muted font-mono">
                      {log.created_at ? formatDateTime(log.created_at) : '—'}
                      {log.logger ? ` · ${log.logger.name}` : ''}
                    </div>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted">No log entries yet.</p>
            )}
          </Panel>

          <Panel title="Spare parts" meta={data.spare_parts?.length ? `${data.spare_parts.length}` : undefined}>
            {data.spare_parts && data.spare_parts.length > 0 ? (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-subtle">
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Item</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Qty</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Unit cost</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {data.spare_parts.map((sp) => (
                    <tr key={sp.id} className="border-b border-subtle">
                      <td className="py-1.5">
                        {sp.item ? <span><span className="font-mono">{sp.item.code}</span><span className="ml-2 text-muted">{sp.item.name}</span></span> : '—'}
                      </td>
                      <td className="py-1.5 text-right font-mono tabular-nums">{sp.quantity}</td>
                      <td className="py-1.5 text-right font-mono tabular-nums">₱{sp.unit_cost}</td>
                      <td className="py-1.5 text-right font-mono tabular-nums font-medium">₱{sp.total_cost}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="text-sm text-muted">No spare parts consumed.</p>
            )}
          </Panel>
        </div>

        <aside className="space-y-3">
          <Panel title="Details">
            <dl className="text-sm divide-y divide-subtle">
              <Detail label="Type">{data.type}</Detail>
              <Detail label="Priority">{data.priority}</Detail>
              <Detail label="Assignee">{data.assignee?.name ?? '—'}</Detail>
              <Detail label="Started">{data.started_at ? formatDateTime(data.started_at) : '—'}</Detail>
              <Detail label="Completed">{data.completed_at ? formatDateTime(data.completed_at) : '—'}</Detail>
              <Detail label="Downtime"><span className="font-mono tabular-nums">{data.downtime_minutes}</span> min</Detail>
              <Detail label="Cost"><span className="font-mono tabular-nums">₱{data.cost}</span></Detail>
            </dl>
          </Panel>
        </aside>
      </div>

      <Modal isOpen={completeOpen} onClose={() => setCompleteOpen(false)} size="sm" title="Complete work order">
        <div className="py-3 space-y-3">
          <Textarea label="Remarks (optional)" value={completeRemarks} onChange={(e) => setCompleteRemarks(e.target.value)} rows={3} />
          <label className="text-xs text-muted font-medium block">Downtime (minutes)</label>
          <input type="number" min={0} value={completeDowntime} onChange={(e) => setCompleteDowntime(Number(e.target.value))}
            className="h-8 px-3 rounded-md border border-default bg-canvas text-sm w-full" />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button variant="secondary" onClick={() => setCompleteOpen(false)}>Cancel</Button>
          <Button variant="primary" onClick={() => completeMutation.mutate()} loading={completeMutation.isPending}>
            {completeMutation.isPending ? 'Completing…' : 'Confirm complete'}
          </Button>
        </div>
      </Modal>
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
