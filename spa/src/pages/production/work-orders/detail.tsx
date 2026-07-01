import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Pause, Play, StopCircle, Ban, Lock, Activity } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/production/workOrders';
import { woOperationsApi } from '@/api/production/routings';
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
import { useChainProgress } from '@/hooks/useChainProgress';
import { usePermission } from '@/hooks/usePermission';
import { cn } from '@/lib/cn';
import { formatInt } from '@/lib/formatNumber';
import type { WorkOrderStatus } from '@/types/production';
import type { WoOperationStatus } from '@/types/production/routing';

const variant: Record<WorkOrderStatus, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
  planned: 'neutral', confirmed: 'info', in_progress: 'info',
  paused: 'warning', completed: 'success', closed: 'success', cancelled: 'danger',
};

const OP_STATUS_CHIP: Record<WoOperationStatus, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
  pending: 'neutral',
  setup: 'info',
  in_progress: 'warning',
  paused: 'danger',
  completed: 'success',
  skipped: 'neutral',
};

const OP_STATUS_LABEL: Record<WoOperationStatus, string> = {
  pending: 'Pending',
  setup: 'Setup',
  in_progress: 'In Progress',
  paused: 'Paused',
  completed: 'Completed',
  skipped: 'Skipped',
};

type DetailTab = 'details' | 'operations';

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
  const [tab, setTab] = useState<DetailTab>('details');
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
  useChainProgress('work_order', id, ['production', 'work-orders', 'detail', id]);

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

  if (isLoading) return <div>      <PageHeader title="Work order" backTo="/production/work-orders" backLabel="Work orders"
        breadcrumbs={[{ label: 'Production', href: '/production' }, { label: 'Work orders', href: '/production/work-orders' }, { label: 'Loading…' }]} /><SkeletonDetail /></div>;
  if (isError || !data) return (
    <div>
      <PageHeader title="Work order" backTo="/production/work-orders" backLabel="Work orders"
        breadcrumbs={[{ label: 'Production', href: '/production' }, { label: 'Work orders', href: '/production/work-orders' }, { label: 'Error' }]} />
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
        breadcrumbs={[{ label: 'Production', href: '/production' }, { label: 'Work orders', href: '/production/work-orders' }, { label: data.wo_number }]}
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

      {/* Tabs */}
      <div className="px-5 border-b border-default flex gap-4">
        {(['details', 'operations'] as DetailTab[]).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={cn(
              'px-1 pb-2 text-xs uppercase tracking-wider transition-colors',
              tab === t
                ? 'border-b-2 border-accent text-accent font-medium'
                : 'text-muted hover:text-strong'
            )}
          >
            {t === 'details' ? 'Details' : 'Operations'}
          </button>
        ))}
      </div>

      {tab === 'details' && (
      <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-4">
          {/* ADV3 — IATF 16949 Production Batch panel. Visible once the WO has
              been started (batch_number is generated on first start). */}
          {data.batch_number && (
            <Panel title="Production batch" meta="IATF 16949 traceability">
              <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                <dt className="text-muted">Batch no.</dt>
                <dd className="col-span-2 font-mono tabular-nums">{data.batch_number}</dd>
                <dt className="text-muted">Machine / Mold</dt>
                <dd className="col-span-2 font-mono">
                  {data.machine?.machine_code ?? '—'} / {data.mold?.mold_code ?? '—'}
                </dd>
                <dt className="text-muted">Produced</dt>
                <dd className="col-span-2 font-mono tabular-nums">
                  {formatInt(data.quantity_good)} good / {formatInt(data.quantity_rejected)} rejected
                </dd>
              </dl>
              {data.material_lot_references.length > 0 && (
                <div className="mt-3 pt-3 border-t border-subtle">
                  <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
                    Material lots used
                  </div>
                  <table className="w-full text-xs">
                    <thead className="bg-subtle">
                      <tr>
                        <th className="px-2 py-1.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Item</th>
                        <th className="px-2 py-1.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">GRN</th>
                        <th className="px-2 py-1.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Material lot</th>
                        <th className="px-2 py-1.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Supplier ref</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.material_lot_references.map((ref, i) => (
                        <tr key={`${ref.material_lot_number ?? 'lot'}-${i}`} className="border-t border-subtle">
                          <td className="px-2 py-1.5">
                            <div className="font-mono">{ref.item_code ?? '—'}</div>
                            <div className="text-muted">{ref.item_name ?? ''}</div>
                          </td>
                          <td className="px-2 py-1.5 font-mono">{ref.grn_number ?? '—'}</td>
                          <td className="px-2 py-1.5 font-mono">{ref.material_lot_number ?? '—'}</td>
                          <td className="px-2 py-1.5 font-mono">{ref.supplier_lot_reference ?? '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
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
              <dd className="col-span-2 font-mono tabular-nums">{formatInt(data.quantity_produced)} / {formatInt(data.quantity_target)}</dd>
              <dt className="text-muted">Good / Reject</dt>
              <dd className="col-span-2 font-mono tabular-nums">{formatInt(data.quantity_good)} / {formatInt(data.quantity_rejected)} (scrap {Number(data.scrap_rate).toFixed(2)}%)</dd>
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
      )}

      {tab === 'operations' && (
      <div className="px-5 py-4">
        <Panel title="Operations" meta={operations.data ? `${operations.data.length} operations` : undefined} noPadding>
          {operations.isLoading && (
            <div className="p-4 text-sm text-muted">Loading operations...</div>
          )}
          {operations.isError && (
            <div className="p-4">
              <EmptyState
                icon="alert-circle"
                title="Failed to load operations"
                action={<Button variant="secondary" size="sm" onClick={() => operations.refetch()}>Retry</Button>}
              />
            </div>
          )}
          {operations.data && operations.data.length === 0 && (
            <div className="p-4 text-sm text-muted">No operations defined for this work order.</div>
          )}
          {operations.data && operations.data.length > 0 && (
            <table className="w-full text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2 w-14">#</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Operation</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Status</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Operator</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Machine</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Qty progress</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Start</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">End</th>
                </tr>
              </thead>
              <tbody>
                {operations.data.map((op) => (
                  <tr key={op.id} className="border-t border-subtle">
                    <td className="px-2.5 py-2 text-right font-mono tabular-nums">{op.sequence}</td>
                    <td className="px-2.5 py-2">{op.operation_name}</td>
                    <td className="px-2.5 py-2">
                      <Chip variant={OP_STATUS_CHIP[op.status]}>{OP_STATUS_LABEL[op.status]}</Chip>
                    </td>
                    <td className="px-2.5 py-2">
                      {op.operator
                        ? `${op.operator.first_name} ${op.operator.last_name}`
                        : <span className="text-muted">—</span>}
                    </td>
                    <td className="px-2.5 py-2 font-mono">
                      {op.machine?.machine_code ?? <span className="text-muted">—</span>}
                    </td>
                    <td className="px-2.5 py-2 text-right font-mono tabular-nums">
                      {formatInt(op.qty_completed)} / {formatInt(op.qty_planned)}
                    </td>
                    <td className="px-2.5 py-2 font-mono">{op.actual_start?.slice(0, 16) ?? '—'}</td>
                    <td className="px-2.5 py-2 font-mono">{op.actual_end?.slice(0, 16) ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      </div>
      )}

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
        onConfirm={() => {
          if (confirmAction) mut.mutate(confirmAction);
        }}
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
