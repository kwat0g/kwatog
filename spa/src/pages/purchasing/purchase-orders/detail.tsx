import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Send, ThumbsUp, ThumbsDown, Truck, X, FileText, CheckSquare } from 'lucide-react';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { ChainHeader } from '@/components/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { ApprovalRecord, PurchaseOrder, PurchaseOrderStatus } from '@/types/purchasing';
import type { ChainStep } from '@/types/chain';

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

  const [confirm, setConfirm] = useState<'submit' | 'approve' | 'send' | 'close' | null>(null);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);

  const invalidate = () => qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders', id] });
  const errMsg = (e: unknown, fallback: string) =>
    (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

  const submit = useMutation({ mutationFn: () => purchaseOrdersApi.submit(id),
    onSuccess: () => { invalidate(); toast.success('PO submitted for approval.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to submit PO.')) });
  const approve = useMutation({ mutationFn: () => purchaseOrdersApi.approve(id),
    onSuccess: () => { invalidate(); toast.success('PO approved.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to approve PO.')) });
  const reject = useMutation({ mutationFn: (reason: string) => purchaseOrdersApi.reject(id, reason),
    onSuccess: () => { invalidate(); toast.success('PO rejected.'); setRejectOpen(false); },
    onError: (e) => toast.error(errMsg(e, 'Failed to reject PO.')) });
  const send = useMutation({ mutationFn: () => purchaseOrdersApi.send(id),
    onSuccess: () => { invalidate(); toast.success('PO marked as sent.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to mark as sent.')) });
  const cancel = useMutation({ mutationFn: (reason: string) => purchaseOrdersApi.cancel(id, reason),
    onSuccess: () => { invalidate(); toast.success('PO cancelled.'); setCancelOpen(false); },
    onError: (e) => toast.error(errMsg(e, 'Failed to cancel PO.')) });
  const close = useMutation({ mutationFn: () => purchaseOrdersApi.close(id),
    onSuccess: () => { invalidate(); toast.success('PO closed.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to close PO.')) });

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
              <Button size="sm" variant="primary" icon={<Send size={14} />} onClick={() => setConfirm('submit')} loading={submit.isPending}>Submit</Button>
            )}
            {(data.status === 'draft' || data.status === 'pending_approval') && can('purchasing.po.approve') && (
              <>
                <Button size="sm" variant="secondary" icon={<ThumbsDown size={14} />} onClick={() => setRejectOpen(true)} loading={reject.isPending}>Reject</Button>
                <Button size="sm" variant="primary" icon={<ThumbsUp size={14} />} onClick={() => setConfirm('approve')} loading={approve.isPending}>Approve</Button>
              </>
            )}
            {data.status === 'approved' && can('purchasing.po.send') && (
              <Button size="sm" variant="primary" icon={<Truck size={14} />} onClick={() => setConfirm('send')} loading={send.isPending}>Mark as sent</Button>
            )}
            {data.status === 'received' && can('purchasing.po.create') && (
              <Button size="sm" variant="secondary" icon={<CheckSquare size={14} />} onClick={() => setConfirm('close')} loading={close.isPending}>Close</Button>
            )}
            <a href={purchaseOrdersApi.pdfUrl(id)} target="_blank" rel="noreferrer">
              <Button size="sm" variant="secondary" icon={<FileText size={14} />}>PDF</Button>
            </a>
            {!['received', 'closed', 'cancelled'].includes(data.status) && (
              <Button size="sm" variant="secondary" icon={<X size={14} />} onClick={() => setCancelOpen(true)} loading={cancel.isPending}>Cancel</Button>
            )}
          </div>
        }
      />
      <div className="px-5 py-4 space-y-4">
        <Panel title="Procure-to-pay chain">
          <ChainHeader steps={buildChainSteps(data)} />
        </Panel>
      </div>
      <div className="px-5 grid grid-cols-3 gap-4 pb-6">
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

      <ConfirmDialog
        isOpen={confirm === 'submit'}
        onClose={() => setConfirm(null)}
        onConfirm={() => submit.mutate()}
        title="Submit PO for approval?"
        description="The PO enters the approval chain and edits are no longer allowed until it is approved or rejected."
        confirmLabel="Submit"
        variant="primary"
        pending={submit.isPending}
      />
      <ConfirmDialog
        isOpen={confirm === 'approve'}
        onClose={() => setConfirm(null)}
        onConfirm={() => approve.mutate()}
        title="Approve this PO?"
        description="Approval is recorded against your account in the audit log."
        confirmLabel="Approve"
        variant="primary"
        pending={approve.isPending}
      />
      <ConfirmDialog
        isOpen={confirm === 'send'}
        onClose={() => setConfirm(null)}
        onConfirm={() => send.mutate()}
        title="Mark PO as sent to vendor?"
        description="Confirm only after the PDF has actually been emailed or transmitted to the vendor."
        confirmLabel="Mark as sent"
        variant="primary"
        pending={send.isPending}
      />
      <ConfirmDialog
        isOpen={confirm === 'close'}
        onClose={() => setConfirm(null)}
        onConfirm={() => close.mutate()}
        title="Close this PO?"
        description="Closes the PO so no further GRNs or bills can reference it."
        confirmLabel="Close PO"
        variant="primary"
        pending={close.isPending}
      />
      <ReasonDialog
        isOpen={rejectOpen}
        onClose={() => setRejectOpen(false)}
        onConfirm={(reason) => reject.mutate(reason)}
        title="Reject this PO?"
        description="The PO returns to the requester with your reason. Please be specific."
        reasonLabel="Rejection reason"
        reasonPlaceholder="e.g. Vendor not on approved-supplier list for this material"
        minLength={10}
        confirmLabel="Reject"
        variant="danger"
        pending={reject.isPending}
      />
      <ReasonDialog
        isOpen={cancelOpen}
        onClose={() => setCancelOpen(false)}
        onConfirm={(reason) => cancel.mutate(reason)}
        title="Cancel this PO?"
        description="Cancellation is permanent and breaks the procure-to-pay chain. Reason is recorded."
        reasonLabel="Cancellation reason"
        reasonPlaceholder="e.g. Project deferred, no longer needed"
        minLength={10}
        confirmLabel="Yes, cancel PO"
        cancelLabel="Keep PO"
        variant="danger"
        pending={cancel.isPending}
      />
    </div>
  );
}

/** Build the procure-to-pay chain visualization for a PO. */
function buildChainSteps(po: PurchaseOrder): ChainStep[] {
  const status = po.status;
  const isAtOrPast = (target: PurchaseOrderStatus[]): boolean => {
    const order: PurchaseOrderStatus[] = [
      'draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'received', 'closed',
    ];
    const cur = order.indexOf(status);
    return target.some((t) => cur >= order.indexOf(t));
  };
  const stateOf = (target: PurchaseOrderStatus, active?: PurchaseOrderStatus): 'done' | 'active' | 'pending' => {
    if (status === 'cancelled') return 'pending';
    if (status === active) return 'active';
    return isAtOrPast([target]) ? 'done' : 'pending';
  };
  const hasGrn = (po.goods_receipt_notes ?? []).length > 0;
  const hasBill = (po.bills ?? []).length > 0;
  const hasPaidBill = (po.bills ?? []).some((b) => b.status === 'paid');
  return [
    { key: 'pr',       label: po.purchase_request ? `PR ${po.purchase_request.pr_number}` : 'PR',
      date: po.purchase_request ? '✓' : undefined,
      state: po.purchase_request ? 'done' : 'pending' },
    { key: 'po',       label: 'PO Created',  date: formatDate(po.date),
      state: 'done' },
    { key: 'approved', label: 'PO Approved', date: po.approved_at ? formatDate(po.approved_at) : undefined,
      state: stateOf('approved', 'pending_approval') },
    { key: 'sent',     label: 'Sent',        date: po.sent_to_supplier_at ? formatDate(po.sent_to_supplier_at) : undefined,
      state: stateOf('sent', 'approved') },
    { key: 'grn',      label: 'GRN Received',
      state: hasGrn ? 'done' : status === 'sent' || status === 'partially_received' ? 'active' : 'pending' },
    { key: 'bill',     label: 'Bill Created',
      state: hasBill ? 'done' : status === 'received' ? 'active' : 'pending' },
    { key: 'paid',     label: 'Payment',
      state: hasPaidBill ? 'done' : hasBill ? 'active' : 'pending' },
    { key: 'closed',   label: 'Closed',
      state: status === 'closed' ? 'done' : 'pending' },
  ];
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
