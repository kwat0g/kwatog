import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Send, ThumbsUp, ThumbsDown, Truck, X, FileText, CheckSquare } from 'lucide-react';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { ApprovalRecord, PurchaseOrderStatus } from '@/types/purchasing';

const variant: Record<PurchaseOrderStatus, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  draft: 'neutral', pending_approval: 'info', approved: 'success', sent: 'info',
  partially_received: 'warning', received: 'success', closed: 'neutral', cancelled: 'danger',
};

export default function PurchaseOrderDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', id],
    queryFn: () => purchaseOrdersApi.show(id),
    enabled: !!id,
  });

  const submit = useMutation({ mutationFn: () => purchaseOrdersApi.submit(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders', id] }); toast.success('Submitted.'); } });
  const approve = useMutation({ mutationFn: () => purchaseOrdersApi.approve(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders', id] }); toast.success('Approved.'); } });
  const reject = useMutation({ mutationFn: () => purchaseOrdersApi.reject(id, prompt('Reason?') ?? ''),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders', id] }); toast.success('Rejected.'); } });
  const send = useMutation({ mutationFn: () => purchaseOrdersApi.send(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders', id] }); toast.success('Marked as sent.'); } });
  const cancel = useMutation({ mutationFn: () => purchaseOrdersApi.cancel(id, prompt('Reason for cancellation?') ?? ''),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders', id] }); toast.success('Cancelled.'); } });
  const close = useMutation({ mutationFn: () => purchaseOrdersApi.close(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders', id] }); toast.success('Closed.'); } });

  if (isLoading) return <SkeletonTable rows={6} columns={5} />;
  if (isError || !data) return (
    <EmptyState icon="alert-circle" title="Failed to load PO" action={<Button onClick={() => refetch()}>Retry</Button>} />
  );

  return (
    <div>
      <PageHeader
        title={<span className="font-mono">{data.po_number}</span>}
        backTo="/purchasing/purchase-orders" backLabel="Purchase orders"
        actions={
          <div className="flex items-center gap-2 flex-wrap">
            <Chip variant={variant[data.status]}>{data.status.replace(/_/g, ' ')}</Chip>
            {data.requires_vp_approval && <Chip variant="warning">VP req.</Chip>}
            {data.status === 'draft' && can('purchasing.po.create') && (
              <Button size="sm" variant="primary" icon={<Send size={14} />} onClick={() => submit.mutate()} loading={submit.isPending}>Submit</Button>
            )}
            {(data.status === 'draft' || data.status === 'pending_approval') && can('purchasing.po.approve') && (
              <>
                <Button size="sm" variant="secondary" icon={<ThumbsDown size={14} />} onClick={() => reject.mutate()} loading={reject.isPending}>Reject</Button>
                <Button size="sm" variant="primary" icon={<ThumbsUp size={14} />} onClick={() => approve.mutate()} loading={approve.isPending}>Approve</Button>
              </>
            )}
            {data.status === 'approved' && can('purchasing.po.send') && (
              <Button size="sm" variant="primary" icon={<Truck size={14} />} onClick={() => send.mutate()} loading={send.isPending}>Mark as sent</Button>
            )}
            {data.status === 'received' && can('purchasing.po.create') && (
              <Button size="sm" variant="secondary" icon={<CheckSquare size={14} />} onClick={() => close.mutate()} loading={close.isPending}>Close</Button>
            )}
            <a href={purchaseOrdersApi.pdfUrl(id)} target="_blank" rel="noreferrer">
              <Button size="sm" variant="secondary" icon={<FileText size={14} />}>PDF</Button>
            </a>
            {!['received', 'closed', 'cancelled'].includes(data.status) && (
              <Button size="sm" variant="secondary" icon={<X size={14} />} onClick={() => cancel.mutate()}>Cancel</Button>
            )}
          </div>
        }
      />
      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <div className="grid grid-cols-4 gap-3">
            <StatCard label="Subtotal" value={formatPeso(data.subtotal)} />
            <StatCard label="VAT" value={formatPeso(data.vat_amount)} />
            <StatCard label="Total" value={formatPeso(data.total_amount)} />
            <StatCard label="Received" value={`${data.quantity_received_pct.toFixed(0)}%`} />
          </div>
          <Panel title="Header">
            <dl className="grid grid-cols-3 gap-y-3 gap-x-6 text-sm">
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Vendor</dt><dd>{data.vendor?.name ?? '—'}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Date</dt><dd className="font-mono">{formatDate(data.date)}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Expected</dt><dd className="font-mono">{data.expected_delivery_date ? formatDate(data.expected_delivery_date) : '—'}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Approved by</dt><dd>{data.approver?.name ?? '—'}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Approved at</dt><dd className="font-mono">{data.approved_at ? formatDate(data.approved_at) : '—'}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Sent at</dt><dd className="font-mono">{data.sent_to_supplier_at ? formatDate(data.sent_to_supplier_at) : '—'}</dd></div>
              {data.purchase_request && (
                <div className="col-span-3"><dt className="text-2xs uppercase tracking-wider text-muted">From PR</dt>
                  <dd><Link to={`/purchasing/purchase-requests/${data.purchase_request.id}`} className="font-mono text-accent">{data.purchase_request.pr_number}</Link></dd>
                </div>
              )}
              {data.remarks && <div className="col-span-3"><dt className="text-2xs uppercase tracking-wider text-muted">Remarks</dt><dd>{data.remarks}</dd></div>}
            </dl>
          </Panel>
          <Panel title="Line items">
            <table className="w-full text-xs">
              <thead><tr className="text-2xs uppercase tracking-wider text-muted">
                <th className="text-left py-1">Item</th>
                <th>Description</th>
                <th className="text-right">Qty</th>
                <th className="text-right">Received</th>
                <th>Unit</th>
                <th className="text-right">Unit price</th>
                <th className="text-right">Total</th>
              </tr></thead>
              <tbody>
                {data.items?.map((l) => (
                  <tr key={l.id} className="h-8 border-t border-subtle">
                    <td className="font-mono">{l.item.code}</td>
                    <td>{l.description}</td>
                    <td className="text-right font-mono tabular-nums">{Number(l.quantity).toFixed(2)}</td>
                    <td className="text-right font-mono tabular-nums">{Number(l.quantity_received).toFixed(2)}</td>
                    <td>{l.unit ?? l.item.unit_of_measure}</td>
                    <td className="text-right font-mono tabular-nums">{Number(l.unit_price).toFixed(2)}</td>
                    <td className="text-right font-mono tabular-nums font-medium">{Number(l.total).toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
        </div>
        <div className="space-y-4">
          <Panel title="Approval chain"><ApprovalChain records={data.approval_records ?? []} /></Panel>
          {data.goods_receipt_notes && data.goods_receipt_notes.length > 0 && (
            <Panel title="Linked GRNs">
              <ul className="text-xs divide-y divide-subtle">
                {data.goods_receipt_notes.map((g) => (
                  <li key={g.id} className="py-1.5">
                    <Link to={`/inventory/grn/${g.id}`} className="font-mono text-accent">{g.grn_number}</Link>
                    <span className="text-muted ml-2">{formatDate(g.received_date)}</span>
                    <Chip variant={g.status === 'accepted' ? 'success' : g.status === 'pending_qc' ? 'warning' : 'info'}>
                      {g.status.replace(/_/g, ' ')}
                    </Chip>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
          {data.bills && data.bills.length > 0 && (
            <Panel title="Linked bills">
              <ul className="text-xs divide-y divide-subtle">
                {data.bills.map((b) => (
                  <li key={b.id} className="py-1.5">
                    <Link to={`/accounting/bills/${b.id}`} className="font-mono text-accent">{b.bill_number}</Link>
                    <span className="ml-2 font-mono tabular-nums">{formatPeso(b.total_amount)}</span>
                    <Chip variant={b.status === 'paid' ? 'success' : b.status === 'partial' ? 'info' : 'warning'}>{b.status}</Chip>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
        </div>
      </div>
    </div>
  );
}

function ApprovalChain({ records }: { records: ApprovalRecord[] }) {
  if (records.length === 0) return <div className="text-sm text-muted">No approval workflow yet.</div>;
  return (
    <ol className="text-xs space-y-2">
      {records.map((r) => (
        <li key={r.step_order} className="flex items-start gap-2">
          <span className={'w-2 h-2 rounded-full mt-1.5 ' +
            (r.action === 'approved' ? 'bg-success' : r.action === 'rejected' ? 'bg-danger' : r.action === 'skipped' ? 'bg-elevated' : 'bg-warning')} />
          <div className="flex-1">
            <div className="font-medium">Step {r.step_order} — {r.role_slug.replace(/_/g, ' ')}</div>
            <div>
              <Chip variant={r.action === 'approved' ? 'success' : r.action === 'rejected' ? 'danger' : r.action === 'skipped' ? 'neutral' : 'warning'}>{r.action}</Chip>
              {r.acted_at && <span className="ml-2 font-mono text-muted">{new Date(r.acted_at).toLocaleString()}</span>}
            </div>
            {r.remarks && <div className="text-muted italic mt-1">"{r.remarks}"</div>}
          </div>
        </li>
      ))}
    </ol>
  );
}
