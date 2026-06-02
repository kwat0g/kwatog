import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { CheckCircle2, Play, Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import { stockCountApi } from '@/api/inventory/warehouseWms';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { numberInputProps } from '@/lib/numberInput';
import { onFormInvalid } from '@/lib/formErrors';
import type { StockCountItem } from '@/types/warehouse';

const statusVariant: Record<string, 'warning' | 'success' | 'info' | 'danger' | 'neutral'> = {
  draft: 'neutral', in_progress: 'warning', completed: 'success', cancelled: 'danger',
};

const itemStatusVariant: Record<string, 'warning' | 'success' | 'info' | 'neutral'> = {
  pending: 'neutral', counted: 'info', verified: 'warning', adjusted: 'success',
};

const sessionSchema = z.object({
  title: z.string().trim().min(2, 'Title is required.').max(200),
  scope: z.enum(['full', 'warehouse', 'zone']),
  warehouse_id: z.string().optional(),
  zone_id: z.string().optional(),
});

const countSchema = z.object({
  counted_quantity: z.string().regex(/^\d+(\.\d{1,3})?$/, 'Valid number required'),
  lot_number: z.string().max(50).optional().or(z.literal('')),
  notes: z.string().max(500).optional().or(z.literal('')),
});

export default function StockCountPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.stock_count.manage');

  const [activeSessionId, setActiveSessionId] = useState<string | null>(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [confirmAction, setConfirmAction] = useState<string | null>(null);
  const [countModalItem, setCountModalItem] = useState<StockCountItem | null>(null);
  const [approveTarget, setApproveTarget] = useState<StockCountItem | null>(null);

  const { data: sessions, isLoading, isError, refetch } = useQuery({
    queryKey: ['warehouse', 'stock-counts'],
    queryFn: () => stockCountApi.list(),
  });

  const { data: activeSession, refetch: refetchSession } = useQuery({
    queryKey: ['warehouse', 'stock-count', activeSessionId],
    queryFn: () => stockCountApi.get(activeSessionId!),
    enabled: !!activeSessionId,
  });

  const { data: warehouses } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });

  const inv = () => { qc.invalidateQueries({ queryKey: ['warehouse', 'stock-counts'] }); };

  // ── Create session ──
  const createForm = useForm<z.infer<typeof sessionSchema>>({
    resolver: zodResolver(sessionSchema),
    defaultValues: { scope: 'full' },
  });
  const watchScope = createForm.watch('scope');

  const createSession = useMutation({
    mutationFn: (d: z.infer<typeof sessionSchema>) => {
      const payload: any = { title: d.title, scope: d.scope };
      if (d.scope === 'warehouse' && d.warehouse_id) payload.warehouse_id = d.warehouse_id;
      if (d.scope === 'zone' && d.zone_id) payload.zone_id = d.zone_id;
      return stockCountApi.create(payload);
    },
    onSuccess: (s) => { toast.success('Count session created.'); setShowCreateModal(false); inv(); setActiveSessionId(s.id); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to create session.'),
  });

  // ── Start session ──
  const startSession = useMutation({
    mutationFn: (id: string) => stockCountApi.start(id),
    onSuccess: () => { toast.success('Count session started.'); refetchSession(); inv(); setConfirmAction(null); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to start.'),
  });

  // ── Record count ──
  const countForm = useForm<z.infer<typeof countSchema>>();
  const recordCount = useMutation({
    mutationFn: (d: z.infer<typeof countSchema>) => stockCountApi.recordCount(countModalItem!.id, d),
    onSuccess: () => { toast.success('Count recorded.'); refetchSession(); setCountModalItem(null); countForm.reset(); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to record count.'),
  });

  // ── Approve variance ──
  const approveVariance = useMutation({
    mutationFn: (id: string) => stockCountApi.approveVariance(id),
    onSuccess: () => { toast.success('Variance approved.'); refetchSession(); setApproveTarget(null); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to approve.'),
  });

  // ── Complete session ──
  const completeSession = useMutation({
    mutationFn: (id: string) => stockCountApi.complete(id),
    onSuccess: () => { toast.success('Session completed! Adjustments created.'); refetchSession(); inv(); setConfirmAction(null); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to complete session.'),
  });

  // ── Cancel session ──
  const cancelSession = useMutation({
    mutationFn: (id: string) => stockCountApi.cancel(id),
    onSuccess: () => { inv(); refetchSession(); setConfirmAction(null); toast.success('Session cancelled.'); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to cancel.'),
  });

  const items = activeSession?.items ?? [];

  const pendingItems = items.filter((i) => i.status === 'pending');

  return (
    <div>
      <PageHeader
        title="Stock Count"
        subtitle={sessions ? `${sessions.length} sessions` : undefined}
        actions={canManage ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setShowCreateModal(true)}>
            New count session
          </Button>
        ) : null}
      />

      <div className="px-5 py-4">
        {isLoading && !sessions && <SkeletonTable rows={4} columns={4} />}
        {isError && <EmptyState icon="alert-circle" title="Failed to load" action={<Button onClick={() => refetch()}>Retry</Button>} />}
        {sessions && sessions.length === 0 && (
          <EmptyState
            icon="inbox"
            title="No count sessions"
            description="Create your first physical stock count session."
            action={canManage ? <Button variant="primary" onClick={() => setShowCreateModal(true)}>New session</Button> : undefined}
          />
        )}
        {sessions && sessions.length > 0 && (
          <div className="grid grid-cols-12 gap-4">
            {/* Sessions list */}
            <div className="col-span-3 space-y-1">
              <div className="text-2xs uppercase tracking-wider text-muted font-medium px-1 mb-1">
                Sessions {sessions.length > 0 && `(${sessions.length})`}
              </div>
              {sessions.map((s) => (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => setActiveSessionId(s.id)}
                  className={`w-full text-left px-2 py-1.5 text-xs rounded-md transition-colors ${
                    activeSessionId === s.id ? 'bg-accent/10 text-accent border border-accent/20' : 'text-muted hover:text-primary hover:bg-elevated'
                  }`}
                >
                  <div className="font-mono">{s.session_number}</div>
                  <div className="truncate">{s.title}</div>
                  <div className="flex items-center gap-1 mt-0.5">
                    <Chip variant={statusVariant[s.status]}>{s.status}</Chip>
                    <span className="text-2xs text-muted">{formatDate(s.created_at)}</span>
                  </div>
                </button>
              ))}
            </div>

            {/* Session detail */}
            <div className="col-span-9 space-y-3">
              {activeSession ? (
                <>
                  {/* Session header */}
                  <Panel
                    title={`${activeSession.session_number} — ${activeSession.title}`}
                    actions={
                      canManage && activeSession.status === 'draft' ? (
                        <Button size="sm" variant="primary" icon={<Play size={12} />} onClick={() => setConfirmAction('start')}>
                          Start count
                        </Button>
                      ) : canManage && activeSession.status === 'in_progress' ? (
                        <div className="flex gap-1">
                          <Button size="sm" variant="primary" onClick={() => setConfirmAction('complete')}>
                            Complete session
                          </Button>
                          <Button size="sm" variant="danger" onClick={() => setConfirmAction('cancel')}>
                            Cancel
                          </Button>
                        </div>
                      ) : null
                    }
                  >
                    <div className="text-xs space-y-1">
                      <div className="flex gap-4">
                        <span className="text-muted">Scope: <span className="font-medium text-primary">{activeSession.scope}</span></span>
                        <span className="text-muted">Status: <Chip variant={statusVariant[activeSession.status] || 'neutral'}>{activeSession.status.replace(/_/g, ' ')}</Chip></span>
                        {activeSession.warehouse && <span className="text-muted">Warehouse: <span className="font-medium">{activeSession.warehouse.name}</span></span>}
                        {activeSession.zone && <span className="text-muted">Zone: <span className="font-medium">{activeSession.zone.name}</span></span>}
                      </div>
                    </div>
                  </Panel>

                  {/* Progress stats */}
                  <div className="grid grid-cols-5 gap-2">
                    <StatBox label="Total bins" value={activeSession.total_locations.toString()} />
                    <StatBox label="Counted" value={activeSession.counted_locations.toString()} icon={<CheckCircle2 size={14} />} />
                    <StatBox label="Pending" value={pendingItems.length.toString()} />
                    <StatBox label="Variances" value={activeSession.variance_count.toString()} />
                    <StatBox label="Completed" value={activeSession.completed_at ? formatDate(activeSession.completed_at) : '—'} />
                  </div>

                  {/* Count items table */}
                  {items.length > 0 && (
                    <Panel title={`Items (${items.length})`}>
                      <table className="w-full text-xs">
                        <thead>
                          <tr className="text-2xs uppercase tracking-wider text-muted">
                            <th className="text-left py-1 font-medium w-32">Location</th>
                            <th className="text-left font-medium">Item</th>
                            <th className="text-right font-medium">System</th>
                            <th className="text-right font-medium">Counted</th>
                            <th className="text-right font-medium">Variance</th>
                            <th className="text-right font-medium">%</th>
                            <th className="text-left font-medium">Status</th>
                            <th />
                          </tr>
                        </thead>
                        <tbody>
                          {items.map((item) => (
                            <tr key={item.id} className="h-8 border-t border-subtle">
                              <td className="font-mono">{item.location?.full_code ?? '—'}</td>
                              <td>
                                {item.item
                                  ? <><span className="font-mono">{item.item.code}</span> <span className="text-muted">{item.item.name}</span></>
                                  : <span className="text-muted">—</span>}
                              </td>
                              <td className="text-right font-mono tabular-nums">{Number(item.system_quantity).toFixed(3)}</td>
                              <td className="text-right font-mono tabular-nums">
                                {item.counted_quantity !== null ? Number(item.counted_quantity).toFixed(3) : <span className="text-muted">—</span>}
                              </td>
                              <td className={`text-right font-mono tabular-nums ${Math.abs(Number(item.variance)) > 0.001 ? 'text-warning-fg' : ''}`}>
                                {item.counted_quantity !== null ? (Number(item.variance) > 0 ? '+' : '') + Number(item.variance).toFixed(3) : '—'}
                              </td>
                              <td className={`text-right font-mono tabular-nums ${Math.abs(Number(item.variance_percent)) > 2 ? 'text-danger-fg' : ''}`}>
                                {item.counted_quantity !== null ? `${Number(item.variance_percent).toFixed(1)}%` : ''}
                              </td>
                              <td><Chip variant={itemStatusVariant[item.status]}>{item.status}</Chip></td>
                              <td className="text-right">
                                {activeSession.status === 'in_progress' && item.status === 'pending' && canManage && (
                                  <Button size="sm" variant="secondary" onClick={() => setCountModalItem(item)}>Count</Button>
                                )}
                                {activeSession.status === 'in_progress' && item.status === 'counted' && Math.abs(Number(item.variance_percent)) > 2 && canManage && (
                                  <Button size="sm" variant="danger" onClick={() => setApproveTarget(item)}>Approve</Button>
                                )}
                                {item.status === 'counted' && Math.abs(Number(item.variance_percent)) <= 2 && (
                                  <span className="text-2xs text-muted">Auto</span>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </Panel>
                  )}
                </>
              ) : (
                <div className="text-sm text-muted text-center py-8">Select a session to view details.</div>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Create modal */}
      <Modal isOpen={showCreateModal} onClose={() => setShowCreateModal(false)} title="New count session" size="sm">
        <form onSubmit={createForm.handleSubmit((d) => createSession.mutate(d), onFormInvalid())} className="py-3 space-y-3">
          <Input label="Title" required autoFocus maxLength={200} {...createForm.register('title')} error={createForm.formState.errors.title?.message} />
          <Select label="Scope" required {...createForm.register('scope')}>
            <option value="full">Full count (all locations)</option>
            <option value="warehouse">Single warehouse</option>
            <option value="zone">Single zone</option>
          </Select>
          {(watchScope !== 'full') && (
            <Select label="Warehouse" required {...createForm.register('warehouse_id')}>
              <option value="">Select warehouse…</option>
              {(warehouses ?? []).map((w) => (
                <option key={w.id} value={w.id}>{w.code} — {w.name}</option>
              ))}
            </Select>
          )}
          {watchScope === 'zone' && (
            <Select label="Zone" required {...createForm.register('zone_id')}>
              <option value="">Select zone…</option>
              {(warehouses ?? []).flatMap((w) => (w.zones ?? [])).map((z) => (
                <option key={z.id} value={z.id}>{z.code} — {z.name}</option>
              ))}
            </Select>
          )}
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => setShowCreateModal(false)} disabled={createSession.isPending}>Cancel</Button>
            <Button type="submit" variant="primary" loading={createSession.isPending}>Create session</Button>
          </div>
        </form>
      </Modal>

      {/* Count modal */}
      <Modal isOpen={!!countModalItem} onClose={() => setCountModalItem(null)} title={`Count — ${countModalItem?.location?.full_code ?? ''}`} size="sm">
        {countModalItem && (
          <form onSubmit={countForm.handleSubmit((d) => recordCount.mutate(d), onFormInvalid())} className="py-3 space-y-3">
            <div className="text-xs text-muted mb-1">
              Item: <span className="font-medium text-primary">{countModalItem.item?.code ?? '—'}</span>
              <br />
              System quantity: <span className="font-mono">{Number(countModalItem.system_quantity).toFixed(3)}</span>
            </div>
            <Input
              label="Counted quantity" required autoFocus
              {...countForm.register('counted_quantity')}
              {...numberInputProps()}
              error={countForm.formState.errors.counted_quantity?.message}
              className="font-mono tabular-nums"
            />
            <Input label="Lot number (optional)" maxLength={50} {...countForm.register('lot_number')} className="font-mono" />
            <Input label="Notes (optional)" maxLength={500} {...countForm.register('notes')} />
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="secondary" onClick={() => setCountModalItem(null)} disabled={recordCount.isPending}>Cancel</Button>
              <Button type="submit" variant="primary" loading={recordCount.isPending}>Record</Button>
            </div>
          </form>
        )}
      </Modal>

      {/* Approve variance */}
      <ConfirmDialog
        isOpen={!!approveTarget}
        onClose={() => setApproveTarget(null)}
        onConfirm={() => approveVariance.mutate(approveTarget!.id)}
        title="Approve variance?"
        description={
          approveTarget ? (
            <>
              Variance of <span className="font-mono font-medium text-warning-fg">{Number(approveTarget.variance).toFixed(3)}</span>
              {' '}({approveTarget.variance_percent}%) at <span className="font-mono">{approveTarget.location?.full_code}</span>.
              This will allow it through when counting completes.
            </>
          ) : null
        }
        confirmLabel="Approve"
        variant="warning"
        pending={approveVariance.isPending}
      />

      {/* Confirm dialogs for session actions */}
      <ConfirmDialog
        isOpen={confirmAction === 'start'}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => activeSession && startSession.mutate(activeSession.id)}
        title="Start count session?"
        description="Locations in scope will be frozen for movements during counting."
        confirmLabel="Start counting"
        variant="primary"
        pending={startSession.isPending}
      />
      <ConfirmDialog
        isOpen={confirmAction === 'complete'}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => activeSession && completeSession.mutate(activeSession.id)}
        title="Complete count session?"
        description="All variances >2% must be approved first. Stock adjustments will be created automatically."
        confirmLabel="Complete"
        variant="primary"
        pending={completeSession.isPending}
      />
      <ConfirmDialog
        isOpen={confirmAction === 'cancel'}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => activeSession && cancelSession.mutate(activeSession.id)}
        title="Cancel session?"
        description="All count data will be lost."
        confirmLabel="Cancel session"
        variant="danger"
        pending={cancelSession.isPending}
      />
    </div>
  );
}

function StatBox({ label, value, icon }: { label: string; value: string; icon?: React.ReactNode }) {
  return (
    <div className="bg-surface rounded-md border border-subtle px-3 py-2 text-xs">
      <div className="text-2xs text-muted uppercase tracking-wider">{label}</div>
      <div className="flex items-center gap-1 mt-0.5">
        {icon && <span className="text-success-fg">{icon}</span>}
        <span className="font-mono font-medium text-primary">{value}</span>
      </div>
    </div>
  );
}
