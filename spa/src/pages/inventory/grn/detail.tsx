import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { CheckCircle2, XCircle } from 'lucide-react';
import { grnApi } from '@/api/inventory/grn';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { ChainHeader } from '@/components/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { ChainStep } from '@/types/chain';

export default function GrnDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [confirmAccept, setConfirmAccept] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'grn', id],
    queryFn: () => grnApi.show(id),
    enabled: !!id,
  });

  const accept = useMutation({
    mutationFn: () => grnApi.accept(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      toast.success('GRN accepted, stock updated.');
      setConfirmAccept(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to accept GRN.'),
  });
  const reject = useMutation({
    mutationFn: (reason: string) => grnApi.reject(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      toast.success('GRN rejected.');
      setRejectOpen(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to reject GRN.'),
  });

  if (isLoading) return <SkeletonTable rows={6} columns={5} />;
  if (isError || !data) return (
    <EmptyState icon="alert-circle" title="Failed to load GRN" action={<Button onClick={() => refetch()}>Retry</Button>} />
  );

  const variant = ({ pending_qc: 'warning', accepted: 'success', partial_accepted: 'info', rejected: 'danger' } as const)[data.status];

  return (
    <div>
      <PageHeader
        title={<span className="font-mono">{data.grn_number}</span>}
        backTo="/inventory/grn" backLabel="GRNs"
        actions={
          <div className="flex items-center gap-2">
            <Chip variant={variant}>{data.status.replace(/_/g, ' ')}</Chip>
            {data.status === 'pending_qc' && can('inventory.grn.create') && (
              <>
                <Button variant="secondary" size="sm" icon={<XCircle size={14} />} onClick={() => setRejectOpen(true)}>Reject</Button>
                <Button variant="primary" size="sm" icon={<CheckCircle2 size={14} />} onClick={() => setConfirmAccept(true)}
                        loading={accept.isPending} disabled={accept.isPending}>Accept</Button>
              </>
            )}
          </div>
        }
      />
      <div className="px-5 py-4 space-y-4">
        <Panel title="Procure-to-pay chain">
          <ChainHeader steps={[
            { key: 'po',  label: data.purchase_order ? `PO ${data.purchase_order.po_number}` : 'PO',
              state: data.purchase_order ? 'done' : 'pending' },
            { key: 'grn', label: 'GRN Created', date: formatDate(data.received_date), state: 'done' },
            { key: 'qc',  label: 'QC',
              state: data.status === 'pending_qc' ? 'active' : data.status === 'rejected' ? 'pending' : 'done' },
            { key: 'stock', label: 'Stock Updated',
              state: data.status === 'accepted' || data.status === 'partial_accepted' ? 'done' : 'pending' },
            { key: 'bill', label: 'Bill', state: 'pending' },
            { key: 'paid', label: 'Paid',  state: 'pending' },
          ] satisfies ChainStep[]} />
        </Panel>
        <Panel title="Header">
          <dl className="grid grid-cols-4 gap-y-3 gap-x-6 text-sm">
            <div><dt className="text-2xs uppercase tracking-wider text-muted">PO</dt><dd className="font-mono">{data.purchase_order?.po_number ?? '—'}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Vendor</dt><dd>{data.vendor?.name ?? '—'}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Received</dt><dd className="font-mono">{formatDate(data.received_date)}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Received by</dt><dd>{data.receiver?.name ?? '—'}</dd></div>
            {data.accepted_at && <div><dt className="text-2xs uppercase tracking-wider text-muted">Accepted</dt><dd className="font-mono">{formatDate(data.accepted_at)} · {data.acceptor?.name}</dd></div>}
            {data.rejected_reason && <div className="col-span-4"><dt className="text-2xs uppercase tracking-wider text-muted">Rejection reason</dt><dd className="text-danger-fg">{data.rejected_reason}</dd></div>}
          </dl>
        </Panel>
        <Panel title="Line items">
          <table className="w-full text-xs">
            <thead><tr className="text-2xs uppercase tracking-wider text-muted">
              <th className="text-left py-1">Item</th>
              <th>Location</th>
              <th className="text-right">Received</th>
              <th className="text-right">Accepted</th>
              <th className="text-right">Unit cost</th>
              <th className="text-right">Total</th>
            </tr></thead>
            <tbody>
              {data.items?.map((l) => (
                <tr key={l.id} className="h-8 border-t border-subtle">
                  <td>
                    <span className="font-mono">{l.item?.code}</span>
                    <div className="text-2xs text-muted">{l.item?.name}</div>
                  </td>
                  <td className="font-mono">{l.location?.full_code}</td>
                  <td className="text-right font-mono tabular-nums">{Number(l.quantity_received).toFixed(3)}</td>
                  <td className="text-right font-mono tabular-nums">{Number(l.quantity_accepted).toFixed(3)}</td>
                  <td className="text-right font-mono tabular-nums">{Number(l.unit_cost).toFixed(4)}</td>
                  <td className="text-right font-mono tabular-nums">{(Number(l.quantity_received) * Number(l.unit_cost)).toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      </div>

      <ConfirmDialog
        isOpen={confirmAccept}
        onClose={() => setConfirmAccept(false)}
        onConfirm={() => accept.mutate()}
        title="Accept this GRN?"
        description="Accepting will post stock movements to update inventory levels and weighted-average cost. This cannot be undone."
        confirmLabel="Accept GRN"
        variant="primary"
        pending={accept.isPending}
      />

      <ReasonDialog
        isOpen={rejectOpen}
        onClose={() => setRejectOpen(false)}
        onConfirm={(reason) => reject.mutate(reason)}
        title="Reject this GRN?"
        description="The vendor delivery will be flagged as rejected. Reason is recorded for audit."
        reasonLabel="Rejection reason"
        reasonPlaceholder="e.g. Material failed incoming inspection (mould flash on pin 3)"
        minLength={10}
        confirmLabel="Reject"
        variant="danger"
        pending={reject.isPending}
      />
    </div>
  );
}
