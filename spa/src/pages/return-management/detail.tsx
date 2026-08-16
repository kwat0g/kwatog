import { useState, lazy, Suspense } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { returnManagementApi } from '@/api/returnManagement';
import { creditNotesApi } from '@/api/accounting/credit-notes';
import { LuTriangleAlert, LuFileText, LuPackageCheck, LuRefreshCw } from '@/lib/icons';
import { warehouseApi } from '@/api/inventory/warehouse';
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { formatPeso, formatInt } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

const DisposeDialog = lazy(() => import('./dispose'));

const STATUS_VARIANT: Record<string, ChipVariant> = {
 draft: 'neutral',
 pending_approval: 'warning',
 approved: 'info',
 received: 'info',
 inspected: 'purple',
 completed: 'success',
 rejected: 'danger',
 cancelled: 'neutral',
};

/** Design-token dot class for each timeline event. */
const TIMELINE_DOT: Record<string, string> = {
 created: 'bg-strong',
 approved: 'bg-info-bg',
 received: 'bg-accent',
 inspected: 'bg-purple-bg',
 completed: 'bg-success-bg',
 rejected: 'bg-danger-bg',
 cancelled: 'bg-strong',
};

const errMsg = (e: unknown, fallback: string) =>
 (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

export default function ReturnRequestDetailPage() {
 const { id } = useParams<{ id: string }>();
 const queryClient = useQueryClient();
 const { can } = usePermission();

 const [confirm, setConfirm] = useState<'submit' | 'approve' | 'receive' | 'inspect' | 'complete' | 'cancel' | null>(null);
 const [rejectOpen, setRejectOpen] = useState(false);
 const [locationId, setLocationId] = useState('');
 const [finalizeCnId, setFinalizeCnId] = useState<string | null>(null);
 const [receivedQty, setReceivedQty] = useState<Record<string, string>>({});
 const [showDispose, setShowDispose] = useState(false);

 const { data: rma, isLoading, isError, refetch } = useQuery({
 queryKey: ['return-request', id],
 queryFn: () => returnManagementApi.get(id!),
 enabled: !!id,
 });
 const { data: warehouses } = useQuery({
 queryKey: ['warehouse-tree'],
 queryFn: () => warehouseApi.tree(),
 staleTime: 5 * 60 * 1000,
 });
 const locations = (warehouses ?? []).flatMap((w) =>
 (w.zones ?? []).flatMap((z) =>
 (z.locations ?? []).map((l) => ({
 id: l.id,
 label: `${w.code}-${z.code}-${l.code}`,
 sub: `${w.name} / ${z.name}`,
 })),
 ),
 );
 const { data: options } = useQuery({
 queryKey: ['return-management', 'options'],
 queryFn: returnManagementApi.options,
 staleTime: 5 * 60 * 1000,
 });
 const reasonLabel = new Map((options?.reasons ?? []).map((option) => [option.value, option.label]));
 const resolutionLabel = new Map((options?.resolutions ?? []).map((option) => [option.value, option.label]));
 const conditionLabel = new Map((options?.conditions ?? []).map((option) => [option.value, option.label]));
 const dispositionLabel = new Map((options?.dispositions ?? []).map((option) => [option.value, option.label]));

 const invalidate = () => queryClient.invalidateQueries({ queryKey: ['return-request', id] });

 const submitMut = useMutation({
 mutationFn: () => returnManagementApi.submit(id!),
 onSuccess: () => { invalidate(); toast.success('RMA submitted for approval.'); setConfirm(null); },
 onError: (e) => toast.error(errMsg(e, 'Failed to submit RMA.')),
 });

 const approveMut = useMutation({
 mutationFn: () => returnManagementApi.approve(id!),
 onSuccess: () => { invalidate(); toast.success('RMA approved.'); setConfirm(null); },
 onError: (e) => toast.error(errMsg(e, 'Failed to approve RMA.')),
 });

 const receiveMut = useMutation({
 // Without per-line counts the backend falls back to the claimed quantity,
 // so a short return would be credited in full.
 mutationFn: () => returnManagementApi.receive(
 id!,
 Object.fromEntries(
 Object.entries(receivedQty)
 .filter(([, v]) => v !== '')
 .map(([k, v]) => [k, Number(v)]),
 ),
 ),
 onSuccess: () => { invalidate(); toast.success('Receipt recorded.'); setConfirm(null); setReceivedQty({}); },
 onError: (e) => toast.error(errMsg(e, 'Failed to record receipt.')),
 });

 const inspectMut = useMutation({
 mutationFn: () => returnManagementApi.inspect(id!),
 onSuccess: (updated) => {
  invalidate();
  toast.success(updated.inspection_handoff?.status === 'manual_required'
   ? 'Quality inspection needs attention.'
   : 'Quality inspection staged.');
  setConfirm(null);
 },
 onError: (e) => toast.error(errMsg(e, 'Failed to complete inspection.')),
 });

 const retryInspectionMut = useMutation({
  mutationFn: () => returnManagementApi.retryInspection(id!),
  onSuccess: (updated) => {
   invalidate();
   toast.success(updated.inspection_handoff?.status === 'manual_required'
    ? 'Quality handoff still needs attention.'
    : 'Quality inspection staged.');
  },
  onError: (e) => toast.error(errMsg(e, 'Failed to retry Quality inspection.')),
 });

 const completeMut = useMutation({
 mutationFn: (locId: string) => returnManagementApi.complete(id!, locId),
 onSuccess: () => { invalidate(); toast.success('RMA completed.'); setConfirm(null); setLocationId(''); },
 onError: (e) => toast.error(errMsg(e, 'Failed to complete RMA.')),
 });

 const rejectMut = useMutation({
 mutationFn: (reason: string) => returnManagementApi.reject(id!, reason),
 onSuccess: () => { invalidate(); toast.success('RMA rejected.'); setRejectOpen(false); },
 onError: (e) => toast.error(errMsg(e, 'Failed to reject RMA.')),
 });

 const cancelMut = useMutation({
 mutationFn: () => returnManagementApi.cancel(id!),
 onSuccess: () => { invalidate(); toast.success('RMA cancelled.'); setConfirm(null); },
 onError: (e) => toast.error(errMsg(e, 'Failed to cancel RMA.')),
 });

 // 2026-08-08 — finalize the draft credit note staged from the returned lines.
 const finalizeCn = useMutation({
 mutationFn: (cnId: string) => creditNotesApi.finalize(cnId),
 onSuccess: () => {
 toast.success('Credit note finalized and posted to the GL.');
 setFinalizeCnId(null);
 invalidate();
 queryClient.invalidateQueries({ queryKey: ['accounting', 'credit-notes'] });
 },
 onError: (e) => toast.error(errMsg(e, 'Failed to finalize credit note.')),
 });

 const availableActions = (status?: string): Array<{ key: string; label: string; variant?: 'primary' | 'danger' | 'default' }> => {
 if (!status) return [];
 switch (status) {
 case 'draft': return [
 { key: 'submit', label: 'Submit for Approval', variant: 'primary' },
 { key: 'cancel', label: 'Cancel', variant: 'danger' },
 ];
 case 'pending_approval': return [
 { key: 'approve', label: 'Approve', variant: 'primary' },
 { key: 'reject', label: 'Reject', variant: 'danger' },
 ];
 case 'approved': return [
 { key: 'receive', label: 'Record Receipt', variant: 'primary' },
 { key: 'reject', label: 'Reject', variant: 'danger' },
 ];
 case 'received': return [
 { key: 'inspect', label: 'Complete Inspection', variant: 'primary' },
 { key: 'reject', label: 'Reject', variant: 'danger' },
 ];
 case 'inspected': {
 const disposed = rma?.disposition_status === 'disposed';
 return [
 // Disposition decides scrap vs restock and issues the credit note;
 // completing before it would close the RMA with neither.
 ...(disposed ? [] : [{ key: 'dispose', label: 'Dispose Items', variant: 'primary' as const }]),
 ...(disposed ? [{ key: 'complete', label: 'Complete RMA', variant: 'primary' as const }] : []),
 // Once disposed the credit / debit memo is live — rejection is no
 // longer a clean unwind, so the backend refuses it.
 ...(disposed ? [] : [{ key: 'reject', label: 'Reject', variant: 'danger' as const }]),
 ];
 }
 default: return [];
 }
 };

 const handleAction = (key: string) => {
 if (key === 'reject') {
 setRejectOpen(true);
 } else if (key === 'dispose') {
 setShowDispose(true);
 } else if (key === 'complete') {
 setConfirm('complete');
 } else if (key === 'receive') {
 setConfirm('receive');
 } else {
 setConfirm(key as typeof confirm);
 }
 };

 const executeConfirm = () => {
 switch (confirm) {
 case 'submit': submitMut.mutate(); break;
 case 'approve': approveMut.mutate(); break;
 case 'inspect': inspectMut.mutate(); break;
 case 'cancel': cancelMut.mutate(); break;
 default: break;
 }
 };

 const confirmPending =
 confirm === 'submit' ? submitMut.isPending
 : confirm === 'approve' ? approveMut.isPending
 : confirm === 'receive' ? receiveMut.isPending
 : confirm === 'inspect' ? inspectMut.isPending
 : confirm === 'cancel' ? cancelMut.isPending
 : false;

 const CONFIRM_META: Record<string, { title: string; description: string; label: string; variant: 'primary' | 'danger' }> = {
 submit: { title: 'Submit RMA for approval?', description: 'The RMA enters the approval chain. Edits are no longer allowed until approved or rejected.', label: 'Submit', variant: 'primary' },
 approve: { title: 'Approve this RMA?', description: 'Approval is recorded against your account in the audit log.', label: 'Approve', variant: 'primary' },
 receive: { title: 'Record receipt of returned items?', description: 'Marks the items as physically received and ready for inspection.', label: 'Record Receipt', variant: 'primary' },
 inspect: { title: 'Complete inspection?', description: 'Marks the inspection as done. Items can then be disposed and the RMA completed.', label: 'Complete Inspection', variant: 'primary' },
 cancel: { title: 'Cancel this RMA?', description: 'Cancellation is permanent. The RMA cannot be reopened.', label: 'Yes, cancel RMA', variant: 'danger' },
 };

 if (isLoading) return <SkeletonDetail />;

 if (isError || !rma) {
 return <EmptyState icon="alert-circle" title="Failed to load return request" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
 }

 // Approve / reject sit behind their own permission — the approval chain
 // routes them to department heads and managers, who deliberately do not
 // hold `manage`. Gating every action on `manage` hid the approve button // from the only people allowed to press it.
 const canManage = can('return_management.manage');
 const canApprove = can('return_management.approve');
 const actions = availableActions(rma.status).filter((action) =>
  action.key === 'approve' || action.key === 'reject' ? canApprove : canManage,
 );

 // 2026-08-08 — a supplier line still needs a location at completion only when
 // it wasn't shipped at dispose (legacy RMA disposed before the change, or an
 // all-scrap supplier return where nothing ever moved).
 const pendingSupplierShip = rma.type === 'supplier_return' && (rma.items ?? []).some(
  (i) => i.disposition === 'return_to_supplier' && !(Number(i.moved_quantity) > 0),
 );

 // Build timeline entries from timestamp fields
 const timeline: Array<{ key: string; label: string; at: string | null | undefined; by?: { name: string } | null }> = [
 { key: 'created', label: 'Created', at: rma.created_at, by: rma.creator },
 { key: 'approved', label: 'Approved', at: rma.approved_at, by: rma.approved_by },
 { key: 'received', label: 'Received', at: rma.received_at },
 { key: 'inspected', label: 'Inspected', at: rma.inspected_at },
 { key: 'completed', label: 'Completed', at: rma.completed_at, by: rma.approved_by },
 { key: 'rejected', label: 'Rejected', at: rma.rejected_at },
 { key: 'cancelled', label: 'Cancelled', at: rma.cancelled_at },
 ];

 return (
 <div>
 <PageHeader
 title={
 <div className="flex items-center gap-3">
 <span className="font-mono">{rma.rma_number}</span>
 <Chip variant={STATUS_VARIANT[rma.status] ?? 'neutral'}>{rma.status_label}</Chip>
 </div>
 }
 subtitle={rma.type_label}
 backTo="/return-management"
 backLabel="Return Management"
 actions={
 <div className="flex gap-1.5">
 {rma.inspection_handoff?.status === 'manual_required' && canManage && (
  <Button
   size="sm"
   variant="secondary"
   icon={<LuRefreshCw size={13} className={retryInspectionMut.isPending ? 'animate-spin' : ''} />}
   loading={retryInspectionMut.isPending}
   onClick={() => retryInspectionMut.mutate()}
  >
   Retry Quality handoff
  </Button>
 )}
 {actions.map((action) => (
 <Button
 key={action.key}
 size="sm"
 variant={action.variant === 'danger' ? 'danger' : 'primary'}
 onClick={() => handleAction(action.key)}
 >
 {action.label}
 </Button>
 ))}
 </div>
 }
 />

 <div className="px-5 py-4 space-y-4">
 <div className="grid gap-4 lg:grid-cols-3">
 <div className="lg:col-span-2 space-y-4">
 {/* Details Panel */}
 <Panel title="RMA Details">
 <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-3 gap-x-6 text-sm mt-2">
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Type</dt>
 <dd>{rma.type_label}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Status</dt>
 <dd><Chip variant={STATUS_VARIANT[rma.status] ?? 'neutral'}>{rma.status_label}</Chip></dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Return Date</dt>
 <dd className="font-mono tabular-nums">{formatDate(rma.return_date)}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Source</dt>
 <dd className="flex flex-col gap-0.5">
 {rma.customer && <span>Customer: {rma.customer.name}</span>}
 {rma.vendor && <span>Vendor: {rma.vendor.name}</span>}
 {rma.sales_order && (
 <Link to={`/crm/sales-orders/${rma.sales_order.id}`} className="text-accent hover:underline font-mono">
 SO: {rma.sales_order.so_number}
 </Link>
 )}
 {rma.invoice && (
 <Link to={`/accounting/invoices/${rma.invoice.id}`} className="text-accent hover:underline font-mono">
 Invoice: {rma.invoice.invoice_number}
 </Link>
 )}
 {rma.purchase_order && (
 <Link to={`/purchasing/purchase-orders/${rma.purchase_order.id}`} className="text-accent hover:underline font-mono">
 PO: {rma.purchase_order.po_number}
 </Link>
 )}
 {rma.bill && (
 <Link to={`/accounting/bills/${rma.bill.id}`} className="text-accent hover:underline font-mono">
 Bill: {rma.bill.bill_number}
 </Link>
 )}
 {!rma.customer && !rma.vendor && !rma.sales_order && !rma.invoice && !rma.purchase_order && !rma.bill && (
 <span className="text-muted">—</span>
 )}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Reason</dt>
 <dd>{reasonLabel.get(rma.reason_code ?? '') || rma.reason_code || '—'}</dd>
 {rma.reason_description && (
 <dd className="text-muted text-xs mt-0.5">{rma.reason_description}</dd>
 )}
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Resolution</dt>
 <dd>{resolutionLabel.get(rma.resolution ?? '') || rma.resolution || '—'}</dd>
 </div>
 </dl>

 {rma.customer_notes && (
 <div className="mt-3">
 <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">Customer Notes</div>
 <div className="text-sm bg-elevated p-2 rounded">{rma.customer_notes}</div>
 </div>
 )}

 {rma.internal_notes && (
 <div className="mt-3">
 <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">Internal Notes</div>
 <div className="text-sm bg-elevated p-2 rounded">{rma.internal_notes}</div>
 </div>
 )}

 {rma.refund_amount && (
 <div className="mt-3">
 <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">Refund Amount</div>
 <div className="text-sm font-medium font-mono tabular-nums">{formatPeso(rma.refund_amount)}</div>
 </div>
 )}
 </Panel>

 {rma.inspection_handoff?.status === 'manual_required' && (
  <div className="flex items-start gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm">
   <LuTriangleAlert size={16} className="mt-0.5 shrink-0 text-warning-fg" />
   <div className="flex-1">
    <div className="font-medium">Quality inspection handoff needs attention</div>
    <div className="text-muted">
     {rma.inspection_handoff.message || 'The return cannot be disposed or completed until Quality inspection staging succeeds.'}
    </div>
   </div>
   {canManage && (
    <Button
     variant="secondary"
     size="sm"
     icon={<LuRefreshCw size={13} />}
     loading={retryInspectionMut.isPending}
     onClick={() => retryInspectionMut.mutate()}
    >
     Retry
    </Button>
   )}
  </div>
 )}

 {/* Items */}
 <Panel title={`Items (${rma.items?.length ?? 0})`}>
 {!rma.items || rma.items.length === 0 ? (
 <div className="text-muted text-sm py-2">No items.</div>
 ) : (
 <table className={cn(tableCls, 'mt-2')}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Product</Th>
 <Th align="right">Qty</Th>
 <Th align="right">Returned</Th>
 <Th align="right">Unit Price</Th>   <Th>Condition</Th>
   <Th>Reason</Th>
   <Th>Disposition</Th>
   <Th>{rma.type === 'supplier_return' ? 'Return' : 'Restock'}</Th>
   </tr>
   </thead>
   <tbody>
   {rma.items.map((item) => (
    <tr key={item.id} className={trCls}>
    <Td mono>
     {item.product
     ? `${item.product.part_number} — ${item.product.name}`
     : item.item
     ? `${item.item.code} — ${item.item.name}`
     : '—'}
    </Td>
    <Td align="right" mono>{formatInt(item.quantity)}</Td>
    <Td align="right" mono>{formatInt(item.returned_quantity)}</Td>
    <Td align="right" mono>{formatPeso(item.unit_price)}</Td>
    <Td>{conditionLabel.get(item.condition ?? '') || item.condition || '—'}</Td>
    <Td>{item.reason || '—'}</Td>
    <Td>
     {item.disposition
     ? <Chip variant={item.disposition === 'restock' ? 'success' : item.disposition === 'scrap' ? 'danger' : 'warning'}>{item.disposition_label ?? dispositionLabel.get(item.disposition) ?? item.disposition}</Chip>
     : '—'}
    </Td>
    <Td>
     {item.moved_quantity && Number(item.moved_quantity) > 0
     ? rma.type === 'supplier_return'
      ? <Chip variant="danger">{formatInt(item.moved_quantity)} out</Chip>
      : <Chip variant="success">✓ {formatInt(item.moved_quantity)}</Chip>
     : '—'}
    </Td>
    </tr>
   ))}
   </tbody>
 </table>
 )}
 </Panel>
 </div>
 <div className="space-y-4">
 {/* Timeline */}
 <Panel title="Timeline">
 <div className="space-y-2 text-sm mt-2">
 {timeline
 .filter((t) => t.at)
 .map((t) => (
 <div key={t.key} className="flex items-center gap-2">
 <div className={`h-2 w-2 rounded-full shrink-0 ${TIMELINE_DOT[t.key] ?? 'bg-strong'}`} />
 <span className="text-muted text-xs">{t.label}</span>
 <span className="font-mono tabular-nums">{formatDateTime(t.at)}</span>
 {t.by && <span className="text-muted">by {t.by.name}</span>}
 </div>
 ))}
 </div>
 </Panel>

 {/* Outcome — the documents disposition produced. These were returned by
     the API but never rendered, so there was no way to tell from the UI
     whether a customer had actually been credited. */}
 {(rma.credit_note || rma.replacement_purchase_order || rma.inspection || rma.disposition_status || rma.inspection_handoff) && (
 <>
 {/* 2026-08-08 — auto-credit chain: dispose() stages a draft customer credit
  note from the returned lines; review and finalize it here (mirrors the
  auto-bill / auto-invoice review-then-post pattern). */}
 {rma.credit_note && rma.credit_note.status === 'draft' && (
 <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
 <LuFileText size={16} className="shrink-0 text-success-fg" />
 <div className="flex-1">
 <div className="font-medium">Credit note auto-created</div>
 <div className="text-muted">
 A draft customer credit was staged from the returned lines —{' '}
 <Link to={`/accounting/credit-notes/${rma.credit_note.id}`} className="font-mono text-accent hover:underline">
 {rma.credit_note.credit_note_number ?? '(draft)'}
 </Link>
 {' '}· {formatPeso(rma.credit_note.total_amount)}. Review and finalize to post the AR credit to the GL.
 </div>
 </div> {can('accounting.credit_notes.manage') && (
   <Button variant="secondary" size="sm" icon={<LuFileText size={14} />}
    onClick={() => setFinalizeCnId(rma.credit_note!.id)} loading={finalizeCn.isPending}>Finalize</Button>
  )}
  </div>
  )}

  {/* 2026-08-08 — dispose-time movement: customer restock lines come back into
      stock, supplier return_to_supplier lines ship out. Show exactly how many
      units moved and where, so the physical flow is as visible as the credit. */}
  {rma.moved_quantity && Number(rma.moved_quantity) > 0 && (
   <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
    <LuPackageCheck size={16} className="shrink-0 text-success-fg" />
    <div className="flex-1">
     <div className="font-medium">
      {rma.type === 'supplier_return' ? 'Goods shipped back to supplier' : 'Goods restocked into inventory'}
     </div>
     <div className="text-muted">
      {rma.type === 'supplier_return'
       ? `${formatInt(rma.moved_quantity)} units left stock${rma.stock_movement?.from_location ? ` at ${rma.stock_movement.from_location.code}` : ''} when the disposition was recorded.`
       : `${formatInt(rma.moved_quantity)} units received back into stock${rma.stock_movement?.to_location ? ` at ${rma.stock_movement.to_location.code}` : ''} when the disposition was recorded.`}
      {' '}
      <Link to="/inventory/stock-levels?view=movements" className="text-accent hover:underline">
       View stock movements
      </Link>
     </div>
    </div>
   </div>
  )}

 <Panel title="Outcome">
 <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-3 gap-x-6 text-sm mt-2">
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Disposition</dt>
 <dd>
 {rma.disposition_status === 'disposed'
 ? <Chip variant="success">Disposed</Chip>
 : <Chip variant="warning">Pending</Chip>}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Credit Note</dt>
 <dd>
 {rma.credit_note ? (
 <Link to={`/accounting/credit-notes/${rma.credit_note.id}`} className="text-accent hover:underline font-mono">
 {rma.credit_note.credit_note_number} · {formatPeso(rma.credit_note.total_amount)}
 </Link>
 ) : <span className="text-muted">—</span>}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Replacement PO</dt>
 <dd>
 {rma.replacement_purchase_order ? (
 <Link to={`/purchasing/purchase-orders/${rma.replacement_purchase_order.id}`} className="text-accent hover:underline font-mono">
 {rma.replacement_purchase_order.po_number}
 </Link>
 ) : <span className="text-muted">—</span>}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Inspection</dt>
 <dd>
 {rma.inspection ? (
 <Link to={`/quality/inspections/${rma.inspection.id}`} className="text-accent hover:underline font-mono">
 {rma.inspection.inspection_number}
 </Link>
 ) : <span className="text-muted">—</span>}
 </dd>
 </div>
 <div>
  <dt className="text-2xs uppercase tracking-wider text-muted">Quality handoff</dt>
  <dd>
   <Chip variant={rma.inspection_handoff?.status === 'manual_required' ? 'warning' : rma.inspection_handoff?.status === 'generated' ? 'success' : 'neutral'}>
    {rma.inspection_handoff?.status_label ?? rma.inspection_handoff?.status ?? '—'}
   </Chip>
  </dd>
 </div>
 </dl>
 </Panel>
 </>
 )}


 </div>

 </div>
 </div>

 {/* Confirm dialogs for simple actions (submit, approve, inspect, cancel).
     Receive and complete have their own dialogs — they need input. */}
 {confirm && confirm !== 'complete' && confirm !== 'receive' && CONFIRM_META[confirm] && (
 <ConfirmDialog
 isOpen
 onClose={() => setConfirm(null)}
 onConfirm={executeConfirm}
 title={CONFIRM_META[confirm].title}
 description={CONFIRM_META[confirm].description}
 confirmLabel={CONFIRM_META[confirm].label}
 variant={CONFIRM_META[confirm].variant === 'danger' ? 'danger' : 'primary'}
 pending={confirmPending}
 />
 )}

 {/* 2026-08-08 — finalize the draft credit note from the return page. */}
 <ConfirmDialog
 isOpen={!!finalizeCnId}
 onClose={() => setFinalizeCnId(null)}
 onConfirm={() => { if (finalizeCnId) finalizeCn.mutate(finalizeCnId); }}
 title="Finalize draft credit note?"
 description="Finalizing assigns the credit note number and posts the VAT-reversing journal entry (DR sales revenue + VAT output, CR AR). Review the auto-created lines before posting."
 confirmLabel="Finalize credit note"
 pending={finalizeCn.isPending}
 />

 {/* Reject dialog (requires reason) */}
 <ReasonDialog
 isOpen={rejectOpen}
 onClose={() => setRejectOpen(false)}
 onConfirm={(reason) => rejectMut.mutate(reason)}
 title="Reject this return request?"
 description="The RMA is returned to the requester with your reason. Please be specific."
 reasonLabel="Rejection reason"
 reasonPlaceholder="e.g. Items were not received within the return window"
 minLength={10}
 confirmLabel="Reject"
 variant="danger"
 pending={rejectMut.isPending}
 />

 {/* Complete with location picker */}
 <Modal
 isOpen={confirm === 'complete'}
 onClose={() => setConfirm(null)}
 title="Complete RMA"
 >   <div className="space-y-3">
   {pendingSupplierShip ? (
    <>
     <p className="text-sm text-muted">
      Select the warehouse location the returned goods ship out from.
     </p>
     {/* Was a free-text "Location ID (optional)" box against a required
         backend field — nothing in the UI exposed a valid value. */}
     <Select
      label="Warehouse location"
      required
      value={locationId}
      onChange={(e) => setLocationId(e.target.value)}
     >
      <option value="">— Select location —</option>
      {locations.map((l) => (
       <option key={l.id} value={l.id}>
        {l.label} · {l.sub}
       </option>
      ))}
     </Select>
    </>
   ) : (
    <p className="text-sm text-muted">
     {rma.type === 'supplier_return'
      ? 'Returned lines were already shipped back when the disposition was recorded. Completing closes the RMA.'
      : 'Restock and rework lines were already received back into stock when the disposition was recorded. Completing closes the RMA.'}
    </p>
   )}
   <div className="flex justify-end gap-2">
    <Button variant="secondary" onClick={() => setConfirm(null)}>Cancel</Button>
    <Button
     variant="primary"
     loading={completeMut.isPending}
     disabled={completeMut.isPending || (pendingSupplierShip && !locationId)}
     onClick={() => completeMut.mutate(locationId)}
    >
     Confirm Complete
    </Button>
   </div>
   </div>
 </Modal>

 {/* Receive with per-line quantities */}
 <Modal
 isOpen={confirm === 'receive'}
 onClose={() => setConfirm(null)}
 title="Record receipt"
 >
 <div className="space-y-3">
 <p className="text-sm text-muted">
 Enter how many units actually came back on each line. Leave a line blank
 to accept the full requested quantity.
 </p>
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Line</Th>
 <Th align="right">Requested</Th>
 <Th align="right">Received</Th>
 </tr>
 </thead>
 <tbody>
 {(rma.items ?? []).map((item) => (
 <tr key={item.id} className={trCls}>
 <Td mono>
 {item.product
 ? `${item.product.part_number} — ${item.product.name}`
 : item.item
 ? `${item.item.code} — ${item.item.name}`
 : '—'}
 </Td>
 <Td align="right" mono>{formatInt(item.quantity)}</Td>
 <Td align="right">
 <Input
 type="number"
 step="0.001"
 min="0"
 max={item.quantity}
 className="font-mono tabular-nums text-right"
 value={receivedQty[item.id] ?? ''}
 onChange={(e) => setReceivedQty((prev) => ({ ...prev, [item.id]: e.target.value }))}
 />
 </Td>
 </tr>
 ))}
 </tbody>
 </table>
 <div className="flex justify-end gap-2">
 <Button variant="secondary" onClick={() => setConfirm(null)}>Cancel</Button>
 <Button
 variant="primary"
 loading={receiveMut.isPending}
 onClick={() => receiveMut.mutate()}
 >
 Record Receipt
 </Button>
 </div>
 </div>
 </Modal>

 {/* Dispose Items Dialog */}
 {rma && showDispose && (
 <Suspense fallback={null}>
 <DisposeDialog rma={rma} isOpen={showDispose} onClose={() => setShowDispose(false)} />
 </Suspense>
 )}
 </div>
 );
}
