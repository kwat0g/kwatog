import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { LuSend, LuThumbsUp, LuThumbsDown, LuTruck, LuX, LuFileText, LuSquareCheck, LuScale, LuReceipt, LuPackage as PackageIcon, LuTriangleAlert } from '@/lib/icons';
import { billsApi } from '@/api/accounting/bills';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { ChainHeader, ApprovalTimeline, LinkedRecords } from '@/components/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useChainProgress } from '@/hooks/useChainProgress';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { buildP2pChain } from '@/lib/chains';
import { fromApprovalRecords } from '@/lib/approvals';
import type { PurchaseOrderStatus, SupplierDispatchStatus } from '@/types/purchasing';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const variant: Record<PurchaseOrderStatus, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
 draft: 'neutral', pending_approval: 'info', approved: 'success', sent: 'info',
 partially_received: 'warning', received: 'success', closed: 'neutral', cancelled: 'danger',
};
const dispatchVariant: Record<SupplierDispatchStatus, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
 pending: 'info', portal_available: 'info', manual_required: 'warning', confirmed: 'success', failed: 'danger', cancelled: 'neutral',
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
 const { data: purchaseOrderOptions } = useQuery({
 queryKey: ['purchasing', 'purchase-orders', 'options'],
 queryFn: purchaseOrdersApi.options,
 staleTime: 300_000,
 });
 const statusLabel = purchaseOrderOptions?.statuses.find((option) => option.value === data?.status)?.label;

 // Series C — Task C4. Real-time chain progress.
 useChainProgress('purchase_order', id, ['purchasing', 'purchase-orders', id]);

 const [confirm, setConfirm] = useState<'submit' | 'approve' | 'send' | 'close' | null>(null);
 const [rejectOpen, setRejectOpen] = useState(false);
 const [cancelOpen, setCancelOpen] = useState(false);
 const [postBillId, setPostBillId] = useState<string | null>(null);

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
 const acknowledgeBudget = useMutation({ mutationFn: () => purchaseOrdersApi.acknowledgeBudget(id),
 onSuccess: () => { invalidate(); toast.success('Budget warning acknowledged.'); },
 onError: (e) => toast.error(errMsg(e, 'Failed to acknowledge budget warning.')) });
 // 2026-08-08 — post an auto-created draft bill straight from the chain view.
 const postBill = useMutation({
 mutationFn: (billId: string) => billsApi.postDraft(billId),
 onSuccess: () => {
  invalidate();
  qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
  toast.success('Draft bill posted to AP + GL.');
  setPostBillId(null);
 },
 onError: (e) => toast.error(errMsg(e, 'Failed to post bill.')),
 });

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
 <Chip variant={variant[data.status]}>{statusLabel ?? data.status}</Chip>
 {data.requires_vp_approval && <Chip variant="warning">VP req.</Chip>}
 {data.is_auto_generated && (
 <span title="Auto-generated for critical stock"><Chip variant="info">Auto</Chip></span>
 )}
 {/* ADV5 — 3-way match indicator */}
 {data.bills && data.bills.length > 0 && data.bills.some((b) => 'has_variances' in b) && (
 <Chip
 variant={data.bills.some((b) => b.has_variances && !b.three_way_overridden) ? 'warning' : 'success'}
 >
 <LuScale size={12} className="mr-0.5" />
 {data.bills.some((b) => b.has_variances && !b.three_way_overridden) ? 'Variance' : 'Matched'}
 </Chip>
 )}
 {data.status === 'draft' && can('purchasing.po.create') && (
 <Button size="sm" variant="primary" icon={<LuSend size={14} />} onClick={() => setConfirm('submit')} loading={submit.isPending}>Submit</Button>
 )}
 {(data.status === 'draft' || data.status === 'pending_approval') && can('purchasing.po.approve') && (
 <>
 <Button size="xs" variant="secondary" icon={<LuThumbsDown size={14} />} onClick={() => setRejectOpen(true)} loading={reject.isPending}>Reject</Button>
 <Button size="xs" variant="primary" icon={<LuThumbsUp size={14} />} onClick={() => setConfirm('approve')} loading={approve.isPending}>Approve</Button>
 </>
 )}
 {data.status === 'approved' && can('purchasing.po.send') && (
 <Button size="sm" variant="primary" icon={<LuTruck size={14} />} onClick={() => setConfirm('send')} loading={send.isPending}>Mark as sent</Button>
 )}
 {data.status === 'received' && can('purchasing.po.create') && (
 <Button size="sm" variant="secondary" icon={<LuSquareCheck size={14} />} onClick={() => setConfirm('close')} loading={close.isPending}>Close</Button>
 )}
 <Button size="sm" variant="secondary" icon={<LuFileText size={14} />}
 onClick={() => void downloadAuthenticatedFile(purchaseOrdersApi.pdfUrl(id), { openInNewTab: true, errorMessage: 'Failed to generate purchase order PDF.' })}>PDF</Button>
 {!['received', 'closed', 'cancelled'].includes(data.status) && (
 <Button size="sm" variant="secondary" icon={<LuX size={14} />} onClick={() => setCancelOpen(true)} loading={cancel.isPending}>Cancel</Button>
 )}
 </div>
 }
 />
 <div className="px-5 py-4 space-y-4">
 {data.budget_warning_level && (
 <div className="flex items-center justify-between gap-4 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm">
 <div>
 <div className="font-medium">Budget {data.budget_warning_level}</div>
 <div className="text-muted">{data.budget_warning_message}</div>
 </div>
 {['exhausted', 'overdrawn'].includes(data.budget_warning_level) && !data.budget_acknowledged_at && can('budgeting.approve') && (
 <Button size="sm" variant="secondary" onClick={() => acknowledgeBudget.mutate()} loading={acknowledgeBudget.isPending}>Finance acknowledge</Button>
 )}
 {data.budget_acknowledged_at && <Chip variant="success">Finance acknowledged</Chip>}
 </div>
 )} <Panel title="Procure-to-pay chain">
  {/* 2026-08-08 — compact cross-document stepper: the whole chain at a glance.
   Approval detail lives in the approval timeline below. */}
  <ChainHeader steps={buildP2pChain({
   pr: data.purchase_request ? { id: data.purchase_request.id, number: data.purchase_request.pr_number } : null,
   po: { id: data.id, number: data.po_number, date: data.date },
   grns: data.goods_receipt_notes ?? [],
   bills: data.bills ?? [],
  })} />
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
 {data.supplier_dispatch && (
 <Panel title="Supplier dispatch">
 <div className="space-y-2 text-sm">
 <div className="flex items-center justify-between gap-3">
 <Chip variant={dispatchVariant[data.supplier_dispatch.status]}>
 {data.supplier_dispatch.status_label ?? data.supplier_dispatch.status.replace(/_/g, ' ')}
 </Chip>
 <span className="text-2xs text-muted">
 {data.supplier_dispatch.channel ?? '—'} · {data.supplier_dispatch.attempts} attempt{data.supplier_dispatch.attempts === 1 ? '' : 's'}
 </span>
 </div>
 {data.supplier_dispatch.status === 'portal_available' && (
 <p className="text-muted">The approved PO is visible to active supplier portal users. Confirm only after the supplier has actually been notified.</p>
 )}
 {data.supplier_dispatch.status === 'manual_required' && (
 <p className="text-warning-fg">No active portal recipient exists. Send the PDF through an approved channel, then use “Mark as sent.”</p>
 )}
 {data.supplier_dispatch.status === 'failed' && data.supplier_dispatch.last_error && (
 <p className="text-danger-fg">{data.supplier_dispatch.last_error}</p>
 )}
 {data.supplier_dispatch.status === 'confirmed' && data.supplier_dispatch.confirmed_at && (
 <p className="text-muted">Confirmed {formatDate(data.supplier_dispatch.confirmed_at)}.</p>
 )}
 {data.supplier_dispatch.status === 'cancelled' && (
 <p className="text-muted">The purchase order was cancelled, so no further supplier transmission is actionable.</p>
 )}
 </div>
 </Panel>
 )}
 <Panel title="Line items">
 <div className="overflow-x-auto">
 <table className={`${tableCls} min-w-[720px]`}>
 <thead><tr className={theadTrCls}>
 <Th>Item</Th>
 <Th>Description</Th>
 <Th align="right">Qty</Th>
 <Th align="right">Received</Th>
 <Th>Unit</Th>
 <Th align="right">Unit price</Th>
 <Th align="right">Total</Th>
 </tr></thead>
 <tbody>
 {data.items?.map((l) => (
 <tr key={l.id} className={trCls}>
 <Td mono>{l.item.code}</Td>
 <Td>{l.description}</Td>
 <Td align="right" mono>{Number(l.quantity).toFixed(2)}</Td>
 <Td align="right" mono>{Number(l.quantity_received).toFixed(2)}</Td>
 <Td>{l.unit ?? l.item.unit_of_measure}</Td>
 <Td align="right" mono>{Number(l.unit_price).toFixed(2)}</Td>
 <Td align="right" mono className="font-medium">{Number(l.total).toFixed(2)}</Td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>
 </Panel>
 </div>
 <div className="space-y-4">
 <Panel title="Approval chain">
 <ApprovalTimeline steps={fromApprovalRecords(data.approval_records)} />
 </Panel>
 {/* ADV5 — Billing process panel: GRN → 3-way match → Bill → Payment. */}
 {(data.status !== 'draft' && data.status !== 'pending_approval' && data.status !== 'cancelled') && (
 <Panel
 title={<span className="inline-flex items-center gap-1.5"><LuReceipt size={14} className="text-accent" />Billing</span>}
 meta={(() => {
 const grnCount = data.goods_receipt_notes?.length ?? 0;
 const billCount = data.bills?.length ?? 0;
 return `${grnCount} GRN${grnCount === 1 ? '' : 's'} · ${billCount} bill${billCount === 1 ? '' : 's'}`;
 })()}
 >
 <div className="space-y-3 text-sm">
 {/* Step 1 — Receiving (GRN) */}
 <div className="flex items-start gap-2">
 <PackageIcon size={14} className="mt-0.5 text-muted shrink-0" />
 <div className="flex-1">
 <div className="text-2xs uppercase tracking-wider text-muted">Receiving</div>
 {(data.goods_receipt_notes?.length ?? 0) === 0 ? (
 <div className="text-muted">No GRN yet — awaiting goods receipt.</div>
 ) : (
 <div>
 {data.goods_receipt_notes!.map((g) => (
 <div key={g.id} className="flex items-center justify-between">
 <Link to={`/inventory/grn/${g.id}`} className="font-mono text-accent hover:underline">{g.grn_number}</Link>
 <span className="text-2xs text-muted">{formatDate(g.received_date)} · {g.status_label ?? g.status.replace(/_/g, ' ')}</span>
 </div>
 ))}
 </div>
 )}
 </div>
 </div>

 {/* Step 2 — 3-way match */}
 <div className="flex items-start gap-2">
 <LuScale size={14} className="mt-0.5 text-muted shrink-0" />
 <div className="flex-1">
 <div className="text-2xs uppercase tracking-wider text-muted">3-way match</div>
 {(data.bills?.length ?? 0) === 0 ? (
 <div className="text-muted">Pending bill</div>
 ) : data.bills!.some((b) => b.has_variances && !b.three_way_overridden) ? (
 <div className="inline-flex items-center gap-1 text-warning-fg">
 <LuTriangleAlert size={12} /> Variance detected — review required
 </div>
 ) : data.bills!.some((b) => b.three_way_overridden) ? (
 <span className="text-info-fg">Variance overridden by Finance</span>
 ) : (
 <span className="text-success-fg">Matched within tolerance</span>
 )}
 </div>
 </div>

 {/* Step 3 — Billing */}
 <div className="flex items-start gap-2">
 <LuReceipt size={14} className="mt-0.5 text-muted shrink-0" />
 <div className="flex-1">
 <div className="text-2xs uppercase tracking-wider text-muted">Bills</div>
 {(data.bills?.length ?? 0) === 0 ? (
 <div className="flex items-center justify-between">
 <span className="text-muted">No bill received from supplier yet</span>
 {can('accounting.bills.create') && (data.goods_receipt_notes?.length ?? 0) > 0 && (
 <Link to="/accounting/bills" className="text-2xs text-accent hover:underline">Create bill →</Link>
 )}
 </div>  ) : (
  <ul className="space-y-1">
  {data.bills!.map((b) => (
  <li key={b.id} className="flex items-center justify-between gap-2">
  <div className="flex items-center gap-1.5 min-w-0">
  <Link to={`/accounting/bills/${b.id}`} className="font-mono text-accent hover:underline">{b.bill_number}</Link>
  <span className="font-mono tabular-nums text-2xs">{formatPeso(b.total_amount)}</span>
  <Chip
  variant={b.status === 'paid' ? 'success' : b.status === 'partial' ? 'info' : b.status === 'draft' ? 'neutral' : 'warning'}
  >
  {b.status_label ?? b.status}
  </Chip>
  {b.status === 'draft' && (
  <span className="text-2xs text-muted">auto-created from GRN</span>
  )}
  </div>
  {b.status === 'draft' && can('accounting.bills.create') && (
  <Button
  variant="secondary"
  size="xs"
  icon={<LuSend size={12} />}
  onClick={() => setPostBillId(b.id)}
  >
  Post bill
  </Button>
  )}
  </li>
  ))}
  </ul>
  )}
 </div>
 </div>
 </div>
 </Panel>
 )}
 {/* Sprint P2 — unified Linked records panel (Procure-to-Pay). */}
 {((data.goods_receipt_notes?.length ?? 0) > 0 || (data.bills?.length ?? 0) > 0 || data.purchase_request) && (
 <Panel title="Linked records">
 <LinkedRecords
 groups={[
 ...(data.purchase_request ? [{
 label: 'Source PR',
 items: [{
 id: data.purchase_request.pr_number,
 href: `/purchasing/purchase-requests/${data.purchase_request.id}`,
 }],
 }] : []),
 ...((data.goods_receipt_notes?.length ?? 0) > 0 ? [{
 label: 'GRNs',
 items: data.goods_receipt_notes!.map((g) => ({
 id: g.grn_number,
 href: `/inventory/grn/${g.id}`,
 meta: formatDate(g.received_date),
 chip: {
 variant: (g.status === 'accepted' ? 'success'
 : g.status === 'pending_qc' ? 'warning'
 : g.status === 'rejected' ? 'danger'
 : 'info') as 'success' | 'warning' | 'info' | 'danger',
 text: g.status_label ?? g.status.replace(/_/g, ' '),
 },
 })),
 }] : []),
 ...((data.bills?.length ?? 0) > 0 ? [{
 label: 'Bills',
 items: data.bills!.map((b) => {
 const matchChip = b.has_variances
 ? b.three_way_overridden
 ? { variant: 'info' as const, text: 'overridden' }
 : { variant: 'warning' as const, text: 'variance' }
 : undefined;
 return {
 id: b.bill_number,
 href: `/accounting/bills/${b.id}`,
 meta: formatPeso(b.total_amount),
 chip: matchChip ?? {
 variant: (b.status === 'paid' ? 'success'
 : b.status === 'partial' ? 'info'
 : 'warning') as 'success' | 'info' | 'warning',
 text: b.status,
 },
 };
 }),
 }] : []),
 ]}
 />
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
 minLength={10}  confirmLabel="Yes, cancel PO"
  cancelLabel="Keep PO"
  variant="danger"
  pending={cancel.isPending}
  />

  <ConfirmDialog
  isOpen={postBillId !== null}
  onClose={() => setPostBillId(null)}
  onConfirm={() => { if (postBillId) postBill.mutate(postBillId); }}
  title="Post draft bill to AP + GL?"
  description="Posting records the payable: it builds and posts the AP/expense journal entry (debit expense + VAT input, credit AP) and flips the bill to Unpaid. Review the auto-created amounts before posting."
  confirmLabel="Post bill"
  variant="primary"
  pending={postBill.isPending}
  />
  </div>
  );
 }
