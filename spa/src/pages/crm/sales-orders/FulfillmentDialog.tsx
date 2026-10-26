import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';

export interface FulfillmentPreview {
  revision: string;
  editable: boolean;
  as_of: string;
  lines: Array<{ id: string; part_number: string; name: string; policy: 'stock_first' | 'produce_new'; demand: string; allocated_stock: string; eligible_free_stock: string; proposed_stock: string; production_shortage: string; production_commitments: string }>;
}
export function FulfillmentDialog({ orderId, onClose }: { orderId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [policies, setPolicies] = useState<Record<string, 'stock_first' | 'produce_new'>>({});
  const [reason, setReason] = useState('');
  const [requestKey, setRequestKey] = useState(() => crypto.randomUUID());
  const submitted = useRef<Parameters<typeof salesOrdersApi.updateFulfillment>[1] | null>(null);
  const preview = useQuery({ queryKey: ['sales-order-fulfillment', orderId], queryFn: () => salesOrdersApi.fulfillment(orderId) });
  const save = useMutation({ mutationFn: () => { submitted.current ??= {
    revision: preview.data!.revision, request_key: requestKey, reason: reason.trim(),
    lines: preview.data!.lines.map((line) => ({ id: line.id, policy: policies[line.id] ?? line.policy })),
  }; return salesOrdersApi.updateFulfillment(orderId, submitted.current); }, onError: (failure) => {
    if (failure instanceof AxiosError && failure.response?.status === 422) { submitted.current = null; setRequestKey(crypto.randomUUID()); }
  }, onSuccess: () => { void queryClient.invalidateQueries(); onClose(); } });
  const locked = save.isPending || !!submitted.current;
  const message = save.error instanceof AxiosError ? save.error.response?.data?.message : save.error?.message;
  return <Modal isOpen onClose={() => { if (!save.isPending) onClose(); }} title="Review fulfillment" size="xl" closeOnOverlayClick={false}>
    <div className="space-y-5 p-5">
      <p className="text-sm text-muted">Use approved stock first. Production covers any shortage. Confirmed production and dispatched shipments remain committed.</p>
      {preview.isLoading && <p>Checking supply…</p>}
      {preview.isError && <p role="alert" className="text-danger-fg">Unable to load supply. Refresh to try again.</p>}
      {preview.data?.lines.map((line) => <div key={line.id} className="space-y-3 border-b border-border pb-4">
        <p>{line.part_number} · {line.name}</p>
        <Select label="Fulfillment policy" value={policies[line.id] ?? line.policy} disabled={locked || !preview.data?.editable} onChange={(event) => setPolicies({ ...policies, [line.id]: event.target.value as 'stock_first' | 'produce_new' })}>
          <option value="stock_first">Approved stock first; produce shortage</option><option value="produce_new">Fresh production</option>
        </Select>
        <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-5">
          <div><dt className="text-muted">Ordered</dt><dd>{line.demand} pcs</dd></div>
          <div><dt className="text-muted">Stock held</dt><dd>{line.allocated_stock} pcs</dd></div>
          <div><dt className="text-muted">Approved free</dt><dd>{line.eligible_free_stock} pcs</dd></div>
          <div><dt className="text-muted">Planned production</dt><dd>{line.production_commitments} pcs</dd></div>
          <div><dt className="text-muted">Additional production needed</dt><dd>{(policies[line.id] ?? line.policy) === line.policy ? `${line.production_shortage} pcs` : 'Recalculated on save'}</dd></div>
        </dl>
        <p className="text-xs text-muted">Planned production shows pieces remaining on existing work orders.</p>
      </div>)}
      <Textarea label="Planning reason" required value={reason} maxLength={2000} disabled={locked} onChange={(event) => setReason(event.target.value)} />
      {message && <p role="alert" className="text-danger-fg">{message}</p>}
      <div className="flex flex-wrap justify-end gap-2"><Button variant="secondary" disabled={locked} onClick={() => { void preview.refetch(); }}>Refresh supply</Button>
        <Button variant="secondary" disabled={save.isPending} onClick={onClose}>Cancel</Button>
        <Button variant="primary" disabled={!preview.data?.editable || !reason.trim() || save.isPending || preview.isFetching} loading={save.isPending} onClick={() => save.mutate()}>Confirm fulfillment</Button></div>
    </div>
  </Modal>;
}
