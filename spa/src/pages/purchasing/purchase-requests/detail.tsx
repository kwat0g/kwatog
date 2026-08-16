import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { LuSend, LuThumbsUp, LuThumbsDown, LuX, LuShoppingCart, LuFileText, LuTriangleAlert, LuZap, LuSparkles } from '@/lib/icons';
import { billsApi } from '@/api/accounting/bills';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { ChainHeader, ApprovalTimeline, LinkedRecords } from '@/components/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { fromApprovalRecords } from '@/lib/approvals';
import { buildP2pChain } from '@/lib/chains';
import type { PurchaseRequest, PurchaseRequestStatus } from '@/types/purchasing';
import type { ChainStep } from '@/types/chain';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const errMsg = (e: unknown, fallback: string) =>
 (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

const statusVariant: Record<PurchaseRequestStatus, 'neutral' | 'warning' | 'info' | 'success' | 'danger'> = {
 draft: 'neutral', pending: 'info', approved: 'success', rejected: 'danger',
 converted: 'neutral', cancelled: 'neutral',
};

export default function PurchaseRequestDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const nav = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();

 const [confirm, setConfirm] = useState<'submit' | 'approve' | 'cancel' | null>(null);
 const [rejectOpen, setRejectOpen] = useState(false);
 const [postBillId, setPostBillId] = useState<string | null>(null);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['purchasing', 'purchase-requests', id],
 queryFn: () => purchaseRequestsApi.show(id),
 enabled: !!id,
 });

 const detailKey = ['purchasing', 'purchase-requests', id];

 function useOptimisticAction<TVar = void>(
 fn: (v: TVar) => Promise<unknown>,
 nextStatus: string,
 opts: { successMsg: string; errorMsg: string; afterSuccess?: () => void },
 ) {
 return useMutation<unknown, unknown, TVar, { prev?: unknown }>({
 mutationFn: fn,
 onMutate: async () => {
 await qc.cancelQueries({ queryKey: detailKey });
 const prev = qc.getQueryData(detailKey);
 qc.setQueryData(detailKey, (old: typeof data) => old ? { ...old, status: nextStatus } : old);
 return { prev };
 },
 onError: (e, _v, ctx) => {
 if (ctx?.prev) qc.setQueryData(detailKey, ctx.prev);
 toast.error(errMsg(e, opts.errorMsg));
 },
 onSuccess: () => { toast.success(opts.successMsg); opts.afterSuccess?.(); },
 onSettled: () => { qc.invalidateQueries({ queryKey: detailKey }); },
 });
 }

 const submit = useOptimisticAction(() => purchaseRequestsApi.submit(id), 'pending', { successMsg: 'Submitted for approval.', errorMsg: 'Failed to submit.', afterSuccess: () => setConfirm(null) });
 const approve = useOptimisticAction(() => purchaseRequestsApi.approve(id), 'approved', { successMsg: 'Purchase request approved.', errorMsg: 'Failed to approve.', afterSuccess: () => setConfirm(null) });
 const reject = useOptimisticAction<string>((reason) => purchaseRequestsApi.reject(id, reason), 'rejected', { successMsg: 'Purchase request rejected.', errorMsg: 'Failed to reject.', afterSuccess: () => setRejectOpen(false) });
 const cancel = useOptimisticAction(() => purchaseRequestsApi.cancel(id), 'cancelled', { successMsg: 'Purchase request cancelled.', errorMsg: 'Failed to cancel.', afterSuccess: () => setConfirm(null) }); const acknowledgeBudget = useMutation({
  mutationFn: () => purchaseRequestsApi.acknowledgeBudget(id),
  onSuccess: () => { qc.invalidateQueries({ queryKey: detailKey }); toast.success('Budget warning acknowledged.'); },
  onError: (e) => toast.error(errMsg(e, 'Failed to acknowledge budget warning.')),
 });
 // 2026-08-08 — post an auto-created draft bill straight from the chain view.
 const postBill = useMutation({
  mutationFn: (billId: string) => billsApi.postDraft(billId),
  onSuccess: () => {
   qc.invalidateQueries({ queryKey: detailKey });
   qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
   toast.success('Draft bill posted to AP + GL.');
   setPostBillId(null);
  },
  onError: (e) => toast.error(errMsg(e, 'Failed to post bill.')),
 });

 if (isLoading) return <SkeletonTable rows={6} columns={5} />;
 if (isError || !data) return (
 <EmptyState icon="alert-circle" title="Failed to load PR" action={<Button onClick={() => refetch()}>Retry</Button>} />
 );

 return (
 <div>
 <PageHeader
 title={<span className="font-mono">{data.pr_number}</span>}
 backTo="/purchasing/purchase-requests" backLabel="Purchase requests"
 actions={
 <div className="flex items-center gap-2">
 <Chip variant={statusVariant[data.status]}>{data.status_label ?? data.status}</Chip>
 {data.is_auto_generated && <Chip variant="warning">AUTO</Chip>}
 {data.is_urgent && <Chip variant="danger"><LuZap size={12} className="inline mr-0.5" />URGENT</Chip>}
 {data.status === 'draft' && can('purchasing.pr.create') && (
 <Button size="sm" variant="primary" icon={<LuSend size={14} />} onClick={() => setConfirm('submit')} loading={submit.isPending}>Submit</Button>
 )}
 {data.status === 'pending' && can('purchasing.pr.approve') && (
 <>
 <Button size="xs" variant="secondary" icon={<LuThumbsDown size={14} />} onClick={() => setRejectOpen(true)} loading={reject.isPending}>Reject</Button>
 <Button size="xs" variant="primary" icon={<LuThumbsUp size={14} />} onClick={() => setConfirm('approve')} loading={approve.isPending}>Approve</Button>
 </>
 )}
 {data.status === 'approved' && can('purchasing.po.create') && (
 <Button size="sm" variant="primary" icon={<LuShoppingCart size={14} />} onClick={() => nav(`/purchasing/purchase-orders/create?pr_id=${data.id}`)}>Convert to PO</Button>
 )}
 <Button size="sm" variant="secondary" icon={<LuFileText size={14} />}
 onClick={() => void downloadAuthenticatedFile(purchaseRequestsApi.pdfUrl(data.id), { openInNewTab: true, errorMessage: 'Failed to generate purchase request PDF.' })}>PDF</Button>
 {(data.status === 'draft' || data.status === 'pending') && (
 <Button size="sm" variant="secondary" icon={<LuX size={14} />} onClick={() => setConfirm('cancel')} loading={cancel.isPending}>Cancel</Button>
 )}
 </div>
 }
 />
 <div className="px-5 py-4 space-y-4">
  {data.status === 'approved' && data.po_conversion_status === 'manual_required' && (
  <div className="flex items-center gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm">
  <LuTriangleAlert size={16} className="shrink-0 text-warning-fg" />
  <div className="flex-1">
  <div className="font-medium">Manual PO conversion required</div>
  <div className="text-muted">{data.po_conversion_note ?? 'Automatic conversion could not complete. Review the request and convert it manually.'}</div>
  </div>
  <Chip variant="warning">Manual action</Chip>
  </div>
  )}
  {data.status === 'approved' && data.po_conversion_status === 'pending' && (
  <div className="flex items-center gap-3 rounded-md border border-info/40 bg-info-bg/10 px-4 py-3 text-sm">
  <LuShoppingCart size={16} className="shrink-0 text-info-fg" />
  <div>
  <div className="font-medium">Automatic PO conversion pending</div>
  <div className="text-muted">The approved request is queued for purchase-order creation.</div>
  </div>
  </div>
  )}
  {data.status === 'converted' && data.purchase_orders?.some((po) => po.is_auto_generated) && (
  <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
  <LuSparkles size={16} className="shrink-0 text-success-fg" />
  <div>
  <div className="font-medium">Auto-converted to purchase order</div>
  <div className="text-muted">
  This PR was approved and its purchase orders were created automatically.
  {data.purchase_orders.filter((po) => po.is_auto_generated).map((po) => (
  <Link
  key={po.id}
  to={`/purchasing/purchase-orders/${po.id}`}
  className="font-mono font-medium text-accent hover:underline"
  >{po.po_number}</Link>
  ))}
  </div>
  </div>
  </div>
  )}
  {/* 2026-08-08 — auto-bill chain: the whole PR → PO → GRN → Bill path is
  visible from the PR; a draft supplier bill on any linked PO can be posted here. */}
  {data.purchase_orders?.some((po) => po.bill?.status === 'draft') && (
  <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
  <LuFileText size={16} className="shrink-0 text-success-fg" />
  <div className="flex-1">
  <div className="font-medium">Supplier bill auto-created</div>
  <div className="text-muted">
  Draft AP bills were staged from the receipts on these purchase orders —
  {data.purchase_orders.filter((po) => po.bill?.status === 'draft').map((po) => (
  <span key={po.id} className="inline-flex items-center gap-1.5">
  <Link
  to={`/accounting/bills/${po.bill!.id}`}
  className="font-mono text-accent hover:underline"
  >{po.bill!.bill_number}</Link>
  <span className="font-mono tabular-nums">{formatPeso(po.bill!.total_amount)}</span>
  <Chip variant="neutral">{po.bill!.status_label ?? po.bill!.status}</Chip>
  <span className="text-2xs text-muted">on <Link to={`/purchasing/purchase-orders/${po.id}`} className="font-mono text-accent hover:underline">{po.po_number}</Link></span>
  {can('accounting.bills.create') && (
  <Button variant="secondary" size="xs" icon={<LuSend size={11} />} onClick={() => setPostBillId(po.bill!.id)}>
  Post
  </Button>
  )}
  </span>
  ))}
  </div>
  </div>
  </div>
  )}
 {data.purchase_orders && data.purchase_orders.length > 0 && (
  <Panel title="Procure-to-pay chain">
  {/* 2026-08-08 — compact cross-document stepper: PR → PO → GRN → Bill → Paid,
   so the PR page shows downstream completion at a glance. */}
  <ChainHeader steps={buildP2pChain({
   pr: { id: data.id, number: data.pr_number },
   po: data.purchase_orders[0]
   ? { id: data.purchase_orders[0].id, number: data.purchase_orders[0].po_number }
   : null,
   grns: data.purchase_orders.flatMap((po) => po.grns ?? []),
   bills: data.purchase_orders.flatMap((po) =>
   po.bill ? [{ id: po.bill.id, bill_number: po.bill.bill_number, status: po.bill.status }] : [],
   ),
  })} />
  </Panel>
 )}
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
 )}
 <Panel title="Approval chain">
 <ChainHeader steps={buildPrChainSteps(data)} />
 </Panel>
 </div>
 <div className="px-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 pb-6">
 <div className="col-span-2 space-y-4">
 <Panel title="Header">
 <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-3 gap-x-6 text-sm">
 <div><dt className="text-2xs uppercase tracking-wider text-muted">Date</dt><dd className="font-mono">{formatDate(data.date)}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted">Priority</dt><dd className="flex items-center gap-1">{data.priority_label ?? data.priority}{data.is_urgent && <span title={data.urgency_reason ?? ''}><LuTriangleAlert size={12} className="text-danger-fg" /></span>}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted">Department</dt><dd>{data.department?.name ?? '—'}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted">Template</dt><dd>{data.template?.name ?? '—'}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted">Requester</dt><dd>{data.requester?.name ?? '—'}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted">Total estimate</dt><dd className="font-mono tabular-nums">{formatPeso(data.total_estimated_amount)}</dd></div>
 {data.reason && <div className="col-span-3"><dt className="text-2xs uppercase tracking-wider text-muted">Reason</dt><dd>{data.reason}</dd></div>}
 </dl>
 </Panel>
 <Panel title="Line items">
 <table className={tableCls}>
 <thead><tr className={theadTrCls}>
 <Th>Item</Th>
 <Th>Description</Th>
 <Th align="right">Qty</Th>
 <Th>Unit</Th>
 <Th align="right">Est. price</Th>
 <Th align="right">Total</Th>
 </tr></thead>
 <tbody>
 {data.items?.map((l) => (
 <tr key={l.id} className={trCls}>
 <Td mono>{l.item?.code ?? '—'}</Td>
 <Td>{l.description}</Td>
 <Td align="right" mono>{Number(l.quantity).toFixed(2)}</Td>
 <Td>{l.unit}</Td>
 <Td align="right" mono>{l.estimated_unit_price ? Number(l.estimated_unit_price).toFixed(2) : '—'}</Td>
 <Td align="right" mono className="font-medium">
 {l.estimated_total}
 {l.suggested_vendor && <div className="text-2xs text-muted mt-0.5">Vendor: {l.suggested_vendor.name}</div>}
 </Td>
 </tr>
 ))}
 </tbody>
 </table>
 </Panel>
 </div>
 <div className="space-y-4">
 <Panel title="Approval chain">
 <ApprovalTimeline steps={fromApprovalRecords(data.approval_records)} />
 </Panel>
 {/* Sprint P2 — unified Linked records panel. */}
 {data.purchase_orders && data.purchase_orders.length > 0 && (
 <Panel title="Linked records">
 <LinkedRecords
 groups={[
 {
 label: 'Purchase orders',
 items: data.purchase_orders.map((po) => ({
 id: po.po_number,
 href: `/purchasing/purchase-orders/${po.id}`,
 meta: `${po.vendor?.name ?? ''} · ${formatPeso(po.total_amount)}`,
 chip: {
 variant: (po.status === 'received' || po.status === 'closed' ? 'success'
 : po.status === 'cancelled' ? 'danger'
 : po.status === 'partially_received' ? 'warning'
 : 'info') as 'success' | 'danger' | 'warning' | 'info',
 text: po.status_label ?? po.status.replace('_', ' '),
 },
 })),
 },
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
 title="Submit for approval?"
 description={<>Once submitted, the PR enters the approval workflow and edits are no longer allowed.</>}
 confirmLabel="Submit"
 variant="primary"
 pending={submit.isPending}
 />
 <ConfirmDialog
 isOpen={confirm === 'approve'}
 onClose={() => setConfirm(null)}
 onConfirm={() => approve.mutate()}
 title="Approve this PR?"
 description={<>Approving advances the workflow. The action is recorded against your account in the audit log.</>}
 confirmLabel="Approve"
 variant="primary"
 pending={approve.isPending}
 />
 <ConfirmDialog
 isOpen={confirm === 'cancel'}
 onClose={() => setConfirm(null)}
 onConfirm={() => cancel.mutate()}
 title="Cancel this PR?"
 description={<>Cancellation is permanent. A cancelled PR cannot be re-submitted.</>}
 confirmLabel="Yes, cancel PR"
 cancelLabel="Keep PR"
 variant="danger"
 pending={cancel.isPending}
 />
 <ReasonDialog
 isOpen={rejectOpen}
 onClose={() => setRejectOpen(false)}
 onConfirm={(reason) => reject.mutate(reason)}
 title="Reject this PR?"
 description="Rejection is recorded in the approval workflow. Provide a clear reason for the requester."
 reasonLabel="Rejection reason"
 reasonPlaceholder="e.g. Budget exceeded, please re-scope and re-submit"
 minLength={10}  confirmLabel="Reject"
  variant="danger"
  pending={reject.isPending}
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

 /** PR chain: Draft → Submitted → each approval step → Approved → Converted. */
 // eslint-disable-next-line react-refresh/only-export-components -- exported for pure chain-state tests
export function buildPrChainSteps(pr: PurchaseRequest): ChainStep[] {
 const steps: ChainStep[] = [
 { key: 'draft', label: 'Draft', date: formatDate(pr.date),
 state: pr.status === 'draft' ? 'active' : 'done' },
 { key: 'submit', label: 'Submitted', date: pr.submitted_at ? formatDate(pr.submitted_at) : undefined,
 state: pr.submitted_at ? 'done' : pr.status === 'draft' ? 'pending' : 'active' },
 ];
 let rejectionSeen = false;
 const approvalRecords = [...(pr.approval_records ?? [])].sort((a, b) => a.step_order - b.step_order);
 for (const r of approvalRecords) {
  const isRejected = r.action === 'rejected';
  const isSkipped = r.action === 'skipped' || (rejectionSeen && r.action === 'pending');
  const actionLabel = isRejected ? 'Rejected' : isSkipped ? 'Skipped' : r.action === 'approved' ? 'Approved' : 'Pending';
  const remark = r.remarks?.trim();
  const description = [
   actionLabel,
   r.acted_at ? formatDate(r.acted_at) : null,
   remark ? `${isRejected ? 'Reason' : 'Remarks'}: ${remark}` : null,
   rejectionSeen && r.action === 'pending' ? 'Not reached after an earlier rejection.' : null,
  ].filter(Boolean).join(' · ');
  steps.push({
  key: `step-${r.step_order}`,
  label: r.role_slug.replace(/_/g, ' '),
  date: r.acted_at ? formatDate(r.acted_at) : undefined,
  state: isRejected ? 'rejected' : isSkipped ? 'skipped' : r.action === 'approved' ? 'done' : 'active',
  description,
  });
  rejectionSeen = rejectionSeen || isRejected;
 }
 steps.push({
 key: 'approved', label: 'Approved',
 date: pr.approved_at ? formatDate(pr.approved_at) : undefined,
 state: pr.status === 'approved' ? 'active' : pr.status === 'converted' ? 'done' : 'pending',
 });
 steps.push({
 key: 'converted', label: 'Converted to PO',
 state: pr.status === 'converted' ? 'done' : 'pending',
 });
 return steps;
}
