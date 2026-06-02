import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { CheckCircle2, Plus, XCircle } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { transferOrderApi } from '@/api/inventory/warehouseWms';
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
import type { TransferOrder } from '@/types/warehouse';
import type { WarehouseLocation } from '@/types/inventory';

const statusVariant: Record<string, 'warning' | 'success' | 'info' | 'danger' | 'neutral'> = {
  pending: 'warning', transferred: 'success', cancelled: 'danger',
};

const createSchema = z.object({
  from_location_id: z.string().min(1, 'Source location required'),
  to_location_id: z.string().min(1, 'Destination location required'),
  item_id: z.string().min(1, 'Item required'),
  quantity: z.string().regex(/^\d+(\.\d{1,3})?$/, 'Valid number required'),
  reason: z.string().max(200).optional().or(z.literal('')),
});

export default function TransferOrdersPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.adjust');

  const [activeId, setActiveId] = useState<string | null>(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [executeTarget, setExecuteTarget] = useState<TransferOrder | null>(null);
  const [cancelTarget, setCancelTarget] = useState<TransferOrder | null>(null);

  const { data: transfers, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'transfer-orders'],
    queryFn: () => transferOrderApi.list(),
  });

  const { data: activeTransfer, refetch: refetchDetail } = useQuery({
    queryKey: ['inventory', 'transfer-order', activeId],
    queryFn: () => transferOrderApi.get(activeId!),
    enabled: !!activeId,
  });

  const { data: locations } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });

  const allLocations: WarehouseLocation[] = (locations ?? []).flatMap((w) =>
    (w.zones ?? []).flatMap((z) => (z.locations ?? [])),
  );

  const inv = () => { qc.invalidateQueries({ queryKey: ['inventory', 'transfer-orders'] }); };

  const createForm = useForm<z.infer<typeof createSchema>>({
    resolver: zodResolver(createSchema),
  });

  const createTransfer = useMutation({
    mutationFn: (d: z.infer<typeof createSchema>) => transferOrderApi.create(d),
    onSuccess: (t) => { toast.success('Transfer order created.'); setShowCreateModal(false); inv(); setActiveId(t.id); createForm.reset(); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to create.'),
  });

  const executeTransfer = useMutation({
    mutationFn: (id: string) => transferOrderApi.execute(id),
    onSuccess: () => { toast.success('Transfer executed!'); refetch(); refetchDetail(); inv(); setExecuteTarget(null); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to execute.'),
  });

  const cancelTransfer = useMutation({
    mutationFn: (id: string) => transferOrderApi.cancel(id),
    onSuccess: () => { toast.success('Transfer cancelled.'); refetch(); refetchDetail(); inv(); setCancelTarget(null); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to cancel.'),
  });

  return (
    <div>
      <PageHeader
        title="Transfer Orders"
        subtitle={transfers ? `${transfers.length} transfers` : undefined}
        actions={canManage ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setShowCreateModal(true)}>
            New transfer
          </Button>
        ) : null}
      />

      <div className="px-5 py-4">
        {isLoading && !transfers && <SkeletonTable rows={4} columns={4} />}
        {isError && <EmptyState icon="alert-circle" title="Failed to load" action={<Button onClick={() => refetch()}>Retry</Button>} />}
        {transfers && transfers.length === 0 && (
          <EmptyState
            icon="inbox"
            title="No transfer orders"
            description="Create a stock transfer between locations."
            action={canManage ? <Button variant="primary" onClick={() => setShowCreateModal(true)}>New transfer</Button> : undefined}
          />
        )}
        {transfers && transfers.length > 0 && (
          <div className="grid grid-cols-12 gap-4">
            {/* List */}
            <div className="col-span-3 space-y-1">
              <div className="text-2xs uppercase tracking-wider text-muted font-medium px-1 mb-1">
                Transfers ({transfers.length})
              </div>
              {transfers.map((t) => (
                <button
                  key={t.id}
                  type="button"
                  onClick={() => setActiveId(t.id)}
                  className={`w-full text-left px-2 py-1.5 text-xs rounded-md transition-colors ${
                    activeId === t.id ? 'bg-accent/10 text-accent border border-accent/20' : 'text-muted hover:text-primary hover:bg-elevated'
                  }`}
                >
                  <div className="font-mono">{t.transfer_number}</div>
                  <div className="truncate text-2xs text-muted">
                    {t.from_location?.full_code ?? '?'} → {t.to_location?.full_code ?? '?'}
                  </div>
                  <div className="flex items-center gap-1 mt-0.5">
                    <Chip variant={statusVariant[t.status] || 'neutral'}>{t.status}</Chip>
                    <span className="text-2xs text-muted">{formatDate(t.created_at)}</span>
                  </div>
                </button>
              ))}
            </div>

            {/* Detail */}
            <div className="col-span-9">
              {activeTransfer ? (
                <Panel
                  title={`${activeTransfer.transfer_number} — ${activeTransfer.item?.code ?? 'Unknown'}`}
                  meta={`${activeTransfer.item?.name ?? ''}`}
                  actions={
                    activeTransfer.status === 'pending' && canManage ? (
                      <div className="flex gap-1">
                        <Button size="sm" variant="primary" icon={<CheckCircle2 size={14} />} onClick={() => setExecuteTarget(activeTransfer)}>
                          Execute
                        </Button>
                        <Button size="sm" variant="danger" icon={<XCircle size={14} />} onClick={() => setCancelTarget(activeTransfer)}>
                          Cancel
                        </Button>
                      </div>
                    ) : null
                  }
                >
                  <div className="text-xs space-y-2">
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1">From</div>
                        <div className="font-mono">{activeTransfer.from_location?.full_code ?? '—'}</div>
                      </div>
                      <div>
                        <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1">To</div>
                        <div className="font-mono">{activeTransfer.to_location?.full_code ?? '—'}</div>
                      </div>
                    </div>
                    <div className="flex gap-4">
                      <span className="text-muted">Item: <span className="font-medium text-primary">{activeTransfer.item?.code} — {activeTransfer.item?.name}</span></span>
                      <span className="text-muted">Quantity: <span className="font-mono font-medium">{Number(activeTransfer.quantity).toFixed(3)}</span></span>
                      <span className="text-muted">Status: <Chip variant={statusVariant[activeTransfer.status] || 'neutral'}>{activeTransfer.status}</Chip></span>
                    </div>
                    {activeTransfer.reason && (
                      <div className="text-muted">Reason: {activeTransfer.reason}</div>
                    )}
                    {activeTransfer.transferred_at && (
                      <div className="text-muted">
                        Transferred by {activeTransfer.transferred_by?.name ?? '?'} on {formatDate(activeTransfer.transferred_at)}
                      </div>
                    )}
                  </div>
                </Panel>
              ) : (
                <div className="text-sm text-muted text-center py-8">Select a transfer to view details.</div>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Create modal */}
      <Modal isOpen={showCreateModal} onClose={() => setShowCreateModal(false)} title="New transfer order" size="sm">
        <form onSubmit={createForm.handleSubmit((d) => createTransfer.mutate(d), onFormInvalid())} className="py-3 space-y-3">
          <Select label="From location" required {...createForm.register('from_location_id')}>
            <option value="">Select source…</option>
            {allLocations.map((l) => (
              <option key={l.id} value={l.id}>
                {l.full_code || l.code || l.id}
              </option>
            ))}
          </Select>
          <Select label="To location" required {...createForm.register('to_location_id')}>
            <option value="">Select destination…</option>
            {allLocations.map((l) => (
              <option key={l.id} value={l.id}>
                {l.full_code || l.code || l.id}
              </option>
            ))}
          </Select>
          <Input
            label="Item ID" required
            placeholder="Paste item ID or select from search"
            {...createForm.register('item_id')}
            error={createForm.formState.errors.item_id?.message}
          />
          <Input
            label="Quantity" required
            {...createForm.register('quantity')}
            {...numberInputProps()}
            error={createForm.formState.errors.quantity?.message}
            className="font-mono tabular-nums"
          />
          <Input label="Reason (optional)" maxLength={200} {...createForm.register('reason')} />
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => setShowCreateModal(false)} disabled={createTransfer.isPending}>Cancel</Button>
            <Button type="submit" variant="primary" loading={createTransfer.isPending}>Create & execute</Button>
          </div>
        </form>
      </Modal>

      {/* Execute confirm */}
      <ConfirmDialog
        isOpen={!!executeTarget}
        onClose={() => setExecuteTarget(null)}
        onConfirm={() => executeTransfer.mutate(executeTarget!.id)}
        title="Execute transfer?"
        description={
          executeTarget ? (
            <>Move <span className="font-mono font-medium">{Number(executeTarget.quantity).toFixed(3)}</span> from{' '}
            <span className="font-mono">{executeTarget.from_location?.full_code}</span> to{' '}
            <span className="font-mono">{executeTarget.to_location?.full_code}</span>. A stock movement will be recorded.</>
          ) : null
        }
        confirmLabel="Execute"
        variant="primary"
        pending={executeTransfer.isPending}
      />

      {/* Cancel confirm */}
      <ConfirmDialog
        isOpen={!!cancelTarget}
        onClose={() => setCancelTarget(null)}
        onConfirm={() => cancelTransfer.mutate(cancelTarget!.id)}
        title="Cancel transfer?"
        description="This will cancel the transfer order. No stock movement will be recorded."
        confirmLabel="Cancel transfer"
        variant="danger"
        pending={cancelTransfer.isPending}
      />
    </div>
  );
}
