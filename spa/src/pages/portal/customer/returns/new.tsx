import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import type { PortalReturnSourceOptions } from '@/types/b2b';

type SourceKind = 'invoice' | 'sales_order' | 'delivery';
type ReturnableLine = {
  key: string;
  source: SourceKind;
  sourceId: string;
  sourceLabel: string;
  lineId: string;
  label: string;
  remaining: string;
  hasInventoryItem: boolean;
};

function returnableLines(options: PortalReturnSourceOptions | undefined): ReturnableLine[] {
  if (!options) return [];
  const customer = options.customer;

  return [
    ...customer.invoices.flatMap((source) => source.lines.map((line) => ({
      key: `invoice:${line.id}`,
      source: 'invoice' as const,
      sourceId: source.id,
      sourceLabel: source.label,
      lineId: line.id,
      label: line.label,
      remaining: line.remaining_quantity,
      hasInventoryItem: line.item_id !== null,
    }))),
    ...customer.salesOrders.flatMap((source) => source.lines.map((line) => ({
      key: `sales_order:${line.id}`,
      source: 'sales_order' as const,
      sourceId: source.id,
      sourceLabel: source.label,
      lineId: line.id,
      label: line.label,
      remaining: line.remaining_quantity,
      hasInventoryItem: line.item_id !== null,
    }))),
    ...customer.deliveries.flatMap((source) => source.lines.map((line) => ({
      key: `delivery:${line.id}`,
      source: 'delivery' as const,
      sourceId: source.id,
      sourceLabel: source.label,
      lineId: line.id,
      label: line.label,
      remaining: line.remaining_quantity,
      hasInventoryItem: line.item_id !== null,
    }))),
  ];
}

export default function CustomerCreateReturnPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [lineKey, setLineKey] = useState('');
  const [quantity, setQuantity] = useState('1.000');
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const options = useQuery({
    queryKey: ['portal', 'customer', 'return-source-options'],
    queryFn: () => customerPortalApi.returnSourceOptions(),
  });
  const lines = useMemo(() => returnableLines(options.data), [options.data]);
  const selectedLine = lines.find((line) => line.key === lineKey);

  const createMutation = useMutation({
    mutationFn: () => {
      if (!selectedLine) throw new Error('Choose an eligible source line.');
      return customerPortalApi.createReturnRequest({
        reason_description: reason.trim() || undefined,
        customer_notes: notes.trim() || undefined,
        items: [{
          quantity,
          // The case-level description allows 1,000 characters; an RMA line
          // reason allows 500, so keep the full explanation on the request and
          // send a valid line-sized summary.
          reason: reason.trim().slice(0, 500) || undefined,
          source_invoice_item_id: selectedLine.source === 'invoice' ? selectedLine.lineId : undefined,
          source_sales_order_item_id: selectedLine.source === 'sales_order' ? selectedLine.lineId : undefined,
          source_delivery_item_id: selectedLine.source === 'delivery' ? selectedLine.lineId : undefined,
        }],
      });
    },
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'returns'] });
      toast.success(result.message);
      navigate(`/portal/customer/returns/${result.data.id}`);
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? error.message ?? 'Could not submit the return request.'),
  });

  if (options.isLoading) return <SkeletonForm />;
  if (options.isError) return <EmptyState icon="alert-circle" title="Could not load returnable items" action={<Button variant="secondary" onClick={() => options.refetch()}>Retry</Button>} />;

  return <div>
    <PageHeader title="New return request" backTo="/portal/customer/returns" backLabel="Returns & RMAs" />
    <div className="px-5 py-4 max-w-3xl space-y-4">
      {lines.length === 0 && <EmptyState icon="package" title="No returnable lines found" description="Only delivered invoice, order, or delivery quantities can be returned. Contact sales if you need help." />}
      {lines.length > 0 && <>
        <Panel title="Return details">
          <label className="block text-xs text-muted">Delivered item <span className="text-danger-fg">*</span>
            <select className="mt-1 h-9 w-full rounded-md border border-default bg-canvas px-3 text-sm text-primary" value={lineKey} onChange={(event) => setLineKey(event.target.value)}>
              <option value="">Choose an invoice, order, or delivery line…</option>
              {lines.map((line) => <option key={line.key} value={line.key} disabled={!line.hasInventoryItem || Number(line.remaining) <= 0}>
                {line.sourceLabel} · {line.label} · remaining {line.remaining}{!line.hasInventoryItem ? ' · unavailable for stock return' : ''}
              </option>)}
            </select>
          </label>
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            <Input label="Quantity to return" type="number" min="0.001" step="0.001" value={quantity} onChange={(event) => setQuantity(event.target.value)} />
            <p className="self-end pb-2 text-xs text-muted">Available to return: <span className="font-mono tabular-nums">{selectedLine?.remaining ?? '—'}</span></p>
          </div>
          <label className="mt-3 block text-xs text-muted">Reason
            <textarea className="mt-1 min-h-20 w-full rounded-md border border-default bg-canvas p-3 text-sm text-primary" maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} />
          </label>
          <label className="mt-3 block text-xs text-muted">Additional notes
            <textarea className="mt-1 min-h-20 w-full rounded-md border border-default bg-canvas p-3 text-sm text-primary" maxLength={2000} value={notes} onChange={(event) => setNotes(event.target.value)} />
          </label>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => navigate('/portal/customer/returns')}>Cancel</Button>
          <Button variant="primary" disabled={!selectedLine || !selectedLine.hasInventoryItem || Number(quantity) <= 0 || Number(quantity) > Number(selectedLine.remaining) || createMutation.isPending} loading={createMutation.isPending} onClick={() => createMutation.mutate()}>
            Submit return request
          </Button>
        </div>
      </>}
    </div>
  </div>;
}
