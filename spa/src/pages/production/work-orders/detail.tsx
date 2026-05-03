import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Pause, Play, StopCircle, Ban, Lock, Activity } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/production/workOrders';
import { machinesApi } from '@/api/mrp/machines';
import { moldsApi } from '@/api/mrp/molds';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader, LinkedRecords, ActivityStream } from '@/components/chain';
import { useEcho } from '@/hooks/useEcho';
import { usePermission } from '@/hooks/usePermission';
import type { WorkOrderStatus } from '@/types/production';

const variant: Record<WorkOrderStatus, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
  planned: 'neutral', confirmed: 'info', in_progress: 'info',
  paused: 'warning', completed: 'success', closed: 'success', cancelled: 'danger',
};

type LifecycleAction = 'confirm' | 'start' | 'pause' | 'resume' | 'complete' | 'close' | 'cancel';

export default function WorkOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canLifecycle = can('production.work_orders.lifecycle');
  const canConfirm   = can('production.wo.confirm');
  const canRecord    = can('production.wo.record');

  const [confirmAction, setConfirmAction] = useState<LifecycleAction | null>(null);
  const [showConfirmDialog, setShowConfirmDialog] = useState(false);
  const [selectedMachineId, setSelectedMachineId] = useState<string>('');
  const [selectedMoldId, setSelectedMoldId] = useState<string>('');

  const machineList = useQuery({
    queryKey: ['mrp', 'machines', 'all'],
    queryFn: () => machinesApi.list({ per_page: 100 }),
    enabled: showConfirmDialog,
  });
  const moldList = useQuery({
    queryKey: ['mrp', 'molds', 'all'],
    queryFn: () => moldsApi.list({ per_page: 100 }),
    enabled: showConfirmDialog,
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['production', 'work-orders', 'detail', id],
    queryFn: () => workOrdersApi.show(id!),
    enabled: !!id,
  });
  const chain = useQuery({
    queryKey: ['production', 'work-orders', 'chain', id],
    queryFn: () => workOrdersApi.chain(id!),
    enabled: !!id,
  });

  // Live updates from output recordings.
  useEcho(`production.wo.${id}`, '.output.recorded', () => {
    qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
  });

  const mut = useMutation({
    mutationFn: async (action: LifecycleAction) => {
      switch (action) {
        case 'confirm':  return workOrdersApi.confirm(id!, selectedMachineId || undefined, selectedMoldId || undefined);
        case 'start':    return workOrdersApi.start(id!);
        case 'pause':    return workOrdersApi.pause(id!, 'Manual pause', 'breakdown');
        case 'resume':   return workOrdersApi.resume(id!);
        case 'complete': return workOrdersApi.complete(id!);
        case 'close':    return workOrdersApi.close(id!);
        case 'cancel':   return workOrdersApi.cancel(id!);
      }
    },
    onSuccess: (wo) => {
      qc.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'chain', id] });
      toast.success(`Work order ${wo?.wo_number} updated.`);
      setConfirmAction(null);
      setShowConfirmDialog(false);
      setSelectedMachineId('');
      setSelectedMoldId('');
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Action failed.');
    },
  });

  if (isLoading) return <div><PageHeader title="Work order" backTo="/production/work-orders" backLabel="Work orders" /><SkeletonDetail /></div>;
  if (isError || !data) return (
    <div>
      <PageHeader title="Work order" backTo="/production/work-orders" backLabel="Work orders" />
      <EmptyState icon="alert-circle" title="Failed to load work order"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
    </div>
  );

  const showConfirm  = data.status === 'planned' && canConfirm;
  const showStart    = data.status === 'confirmed' && canLifecycle;
  const showPause    = data.status === 'in_progress' && canLifecycle;
  const showResume   = data.status === 'paused' && canLifecycle;
  const showComplete = data.status === 'in_progress' && canLifecycle;
  const showClose    = data.status === 'completed' && canLifecycle;
  const showCancel   = !['completed', 'closed', 'cancelled', 'in_progress'].includes(data.status) && canLifecycle;
  const showRecord   = data.status === 'in_progress' && canRecord;

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{data.wo_number}</span>
            <Chip variant={variant[data.status]}>{data.status_label}</Chip>
          </div>
        }
        subtitle={data.product ? `${data.product.part_number} — ${data.product.name}` : undefined}
        backTo="/production/work-orders"
        backLabel="Work orders"
        actions={
          <div className="flex gap-1.5">
            {showConfirm  && <Button size="sm" variant="primary"   icon={<Check size={14} />}      onClick={() => {
              setSelectedMachineId(data.machine?.id ?? '');
              setSelectedMoldId(data.mold?.id ?? '');
              setShowConfirmDialog(true);
            }}>Confirm</Button>}
            {showStart    && <Button size="sm" variant="primary"   icon={<Play size={14} />}        onClick={() => setConfirmAction('start')}>Start</Button>}
            {showPause    && <Button size="sm" variant="secondary" icon={<Pause size={14} />}      onClick={() => setConfirmAction('pause')}>Pause</Button>}
            {showResume   && <Button size="sm" variant="primary"   icon={<Play size={14} />}        onClick={() => setConfirmAction('resume')}>Resume</Button>}
            {showRecord   && <Button size="sm" variant="primary"   icon={<Activity size={14} />}    onClick={() => navigate(`/production/work-orders/${data.id}/record-output`)}>Record output</Button>}
            {showComplete && <Button size="sm" variant="secondary" icon={<StopCircle size={14} />} onClick={() => setConfirmAction('complete')}>Complete</Button>}
            {showClose    && <Button size="sm" variant="secondary" icon={<Lock size={14} />}        onClick={() => setConfirmAction('close')}>Close</Button>}
            {showCancel   && <Button size="sm" variant="secondary" icon={<Ban size={14} />}         onClick={() => setConfirmAction('cancel')}>Cancel</Button>}
          </div>
        }
        bottom={chain.data ? <ChainHeader steps={chain.data} className="mt-2" /> : null}
      />

      <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-4">
          <Panel title="Overview">
            <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <dt className="text-muted">Product</dt>
              <dd className="col-span-2"><span className="font-mono">{data.product?.part_number}</span> — {data.product?.name}</dd>
              <dt className="text-muted">Sales order</dt>
              <dd className="col-span-2">{data.sales_order
                ? <Link to={`/crm/sales-orders/${data.sales_order.id}`} className="font-mono text-accent hover:underline">{data.sales_order.so_number}</Link>
                : <span className="text-muted">—</span>}</dd>
              <dt className="text-muted">Machine</dt>
              <dd className="col-span-2 font-mono">{data.machine?.machine_code ?? '—'}</dd>
              <dt className="text-muted">Mold</dt>
              <dd className="col-span-2 font-mono">{data.mold?.mold_code ?? '—'}</dd>
              <dt className="text-muted">Target / Produced</dt>
              <dd className="col-span-2 font-mono tabular-nums">{data.quantity_produced.toLocaleString()} / {data.quantity_target.toLocaleString()}</dd>
              <dt className="text-muted">Good / Reject</dt>
              <dd className="col-span-2 font-mono tabular-nums">{data.quantity_good.toLocaleString()} / {data.quantity_rejected.toLocaleString()} (scrap {Number(data.scrap_rate).toFixed(2)}%)</dd>
              <dt className="text-muted">Planned</dt>
              <dd className="col-span-2 font-mono">{data.planned_start?.slice(0, 16)} → {data.planned_end?.slice(0, 16)}</dd>
              <dt className="text-muted">Actual</dt>
              <dd className="col-span-2 font-mono">{data.actual_start ? `${data.actual_start.slice(0, 16)} → ${data.actual_end?.slice(0, 16) ?? '…'}` : '—'}</dd>
              {data.pause_reason && <>
                <dt className="text-muted">Pause reason</dt>
                <dd className="col-span-2 text-warning-fg">{data.pause_reason}</dd>
              </>}
            </dl>
          </Panel>

          <Panel title="Materials" meta={`${data.materials?.length ?? 0} lines`} noPadding>
            {data.materials?.length ? (
              <table className="w-full text-xs">
                <thead className="bg-subtle">
                  <tr>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Item</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">BOM qty</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Issued</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Variance</th>
                  </tr>
                </thead>
                <tbody>
                  {data.materials.map((m) => (
                    <tr key={m.id} className="border-t border-subtle">
                      <td className="px-2.5 py-2">
                        <div className="font-mono">{m.item?.code}</div>
                        <div className="text-muted">{m.item?.name}</div>
                      </td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{Number(m.bom_quantity).toFixed(3)}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{Number(m.actual_quantity_issued).toFixed(3)}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{Number(m.variance).toFixed(3)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <div className="p-4 text-sm text-muted">No materials defined (no active BOM).</div>
            )}
          </Panel>

          <Panel title="Recent outputs" meta={`${data.outputs?.length ?? 0} entries`} noPadding>
            {data.outputs?.length ? (
              <table className="w-full text-xs">
                <thead className="bg-subtle">
                  <tr>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Recorded</th>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Batch</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Good</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Reject</th>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Defects</th>
                  </tr>
                </thead>
                <tbody>
                  {data.outputs.map((o) => (
                    <tr key={o.id} className="border-t border-subtle">
                      <td className="px-2.5 py-2 font-mono">{o.recorded_at?.slice(0, 16)}</td>
                      <td className="px-2.5 py-2 font-mono">{o.batch_code ?? '—'}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{o.good_count}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{o.reject_count}</td>
                      <td className="px-2.5 py-2 text-xs">
                        {o.defects?.length
                          ? o.defects.map((d) => `${d.defect_type?.code} ×${d.count}`).join(', ')
                          : <span className="text-muted">—</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
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
                ...(data.sales_order ? [{
                  label: 'Sales Order',
                  items: [{
                    id: data.sales_order.so_number,
                    href: `/crm/sales-orders/${data.sales_order.id}`,
                  }],
                }] : []),
                ...(data.machine || data.mold ? [{
                  label: 'Resources',
                  items: [
                    ...(data.machine ? [{
                      id: data.machine.machine_code,
                      href: `/mrp/machines/${data.machine.id}`,
                      meta: data.machine.name,
                    }] : []),
                    ...(data.mold ? [{
                      id: data.mold.mold_code,
                      href: `/mrp/molds/${data.mold.id}`,
                      meta: data.mold.name,
                    }] : []),
                  ],
                }] : []),
                ...(data.materials && data.materials.length > 0 ? [{
                  label: 'Materials',
                  items: data.materials.map((m) => ({
                    id: m.item?.code ?? '—',
                    meta: `${Number(m.actual_quantity_issued).toFixed(3)} / ${Number(m.bom_quantity).toFixed(3)} ${m.item?.unit_of_measure ?? ''}`,
                  })),
                }] : []),
                {
                  label: 'Quality',
                  items: [{ id: 'Inspections', meta: 'Sprint 7 — in-process + outgoing AQL' }],
                },
              ]}
            />
          </Panel>
          <Panel title="Activity">
            <ActivityStream
              items={[
                { dot: 'success' as const, text: <>Work order <span className="font-mono">{data.wo_number}</span> created.</>, time: data.created_at?.slice(0, 10) ?? '' },
                ...(data.actual_start ? [{
                  dot: 'info' as const,
                  text: <>Production started.</>,
                  time: data.actual_start.slice(0, 10),
                }] : []),
                ...(data.actual_end ? [{
                  dot: 'success' as const,
                  text: <>Production completed.</>,
                  time: data.actual_end.slice(0, 10),
                }] : []),
                ...(data.pause_reason ? [{
                  dot: 'warning' as const,
                  text: <>Paused: {data.pause_reason}</>,
                  time: data.updated_at?.slice(0, 10) ?? '',
                }] : []),
              ]}
            />
          </Panel>
        </div>
      </div>

      <Modal
        isOpen={showConfirmDialog}
        onClose={() => setShowConfirmDialog(false)}
        title={<>Confirm work order <span className="font-mono">{data.wo_number}</span></>}
        size="md"
      >
        <div className="px-5 py-4 space-y-4">
          <p className="text-sm text-muted">
            Confirming a work order requires both a machine and a mold. The system will reserve materials based on the BOM once you confirm.
          </p>
          <Select
            label="Machine"
            required
            value={selectedMachineId}
            onChange={(e) => setSelectedMachineId(e.target.value)}
          >
            <option value="">Select a machine…</option>
            {machineList.data?.data.map((m) => (
              <option key={m.id} value={m.id}>
                {m.machine_code} — {m.name} ({m.tonnage}t)
              </option>
            ))}
          </Select>
          <Select
            label="Mold"
            required
            value={selectedMoldId}
            onChange={(e) => setSelectedMoldId(e.target.value)}
          >
            <option value="">Select a mold…</option>
            {moldList.data?.data
              .filter((m) => !data.product || !m.product || m.product.id === data.product.id)
              .map((m) => (
                <option key={m.id} value={m.id}>
                  {m.mold_code} — {m.name} (cavity {m.cavity_count})
                </option>
              ))}
          </Select>
          <div className="flex items-center justify-end gap-2 pt-2 border-t border-default">
            <Button variant="secondary" onClick={() => setShowConfirmDialog(false)}>Cancel</Button>
            <Button
              variant="primary"
              icon={<Check size={14} />}
              disabled={!selectedMachineId || !selectedMoldId || mut.isPending}
              onClick={() => mut.mutate('confirm')}
            >
              {mut.isPending ? 'Confirming…' : 'Confirm work order'}
            </Button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        isOpen={!!confirmAction}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => confirmAction && mut.mutate(confirmAction)}
        title={confirmAction ? `${confirmAction[0].toUpperCase()}${confirmAction.slice(1)} this work order?` : ''}
        description={confirmAction
          ? <>This will run the <span className="font-mono">{confirmAction}</span> lifecycle action on <span className="font-mono">{data.wo_number}</span>.</>
          : null}
        confirmLabel={confirmAction ? `${confirmAction[0].toUpperCase()}${confirmAction.slice(1)}` : 'Confirm'}
        variant={confirmAction === 'cancel' ? 'danger' : 'primary'}
        pending={mut.isPending}
      />
    </div>
  );
}
