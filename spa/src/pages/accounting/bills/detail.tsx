import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { LuPrinter, LuReceipt, LuBan, LuSend } from '@/lib/icons';
import { billsApi } from '@/api/accounting/bills';
import { accountingOptionsApi } from '@/api/accounting/options';
import { downloadAuthenticatedFile } from '@/api/download';
import { accountsApi } from '@/api/accounting/accounts';
import { threeWayMatchApi } from '@/api/purchasing/purchase-orders';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import type { ChipVariant } from '@/components/ui/Chip';import { EmptyState } from '@/components/ui/EmptyState';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { ChainHeader } from '@/components/chain';
import { buildP2pChain } from '@/lib/chains';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useChainProgress } from '@/hooks/useChainProgress';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { numberInputProps } from '@/lib/numberInput';
import { Td, Th, tableCls, theadTrCls, totalsTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';
import type { PaymentMethod } from '@/types/accounting';

const paymentSchema = z.object({
 cash_account_id: z.string().min(1, 'Required'),
 payment_date: z.string().min(1, 'Required'),
 amount: z.coerce.number().positive('> 0'),
 payment_method: z.string().min(1, 'Required'),
 reference_number: z.string().max(50).optional().or(z.literal('')),
});
type PaymentFormValues = z.infer<typeof paymentSchema>;

// REC-02 — 3-way match per-line status → visual chip variant.
type MatchLineStatus = 'matched' | 'qty_variance' | 'price_variance' | 'both' | 'grn_short' | 'unmatched_bill_line' | 'duplicate_bill_line';
const MATCH_LINE_VARIANT: Record<MatchLineStatus, ChipVariant> = {
 matched: 'success', qty_variance: 'warning', price_variance: 'warning', both: 'warning', grn_short: 'danger', unmatched_bill_line: 'danger', duplicate_bill_line: 'danger',
};

export default function BillDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [showPay, setShowPay] = useState(false);
 const [showCancelConfirm, setShowCancelConfirm] = useState(false);
 const [showPostConfirm, setShowPostConfirm] = useState(false);
 const [showPostOverride, setShowPostOverride] = useState(false);
 const { data: bill, isLoading, isError, refetch } = useQuery({
  queryKey: ['accounting', 'bills', id],
  queryFn: () => billsApi.show(id),
  enabled: !!id,
 });
 // 2026-08-08 — final P2P link: live chain progress (payments / credit-note
 // applications settle the bill in real time).
 useChainProgress('bill', id, ['accounting', 'bills', id]);
 const { data: accountingOptions } = useQuery({ queryKey: ['accounting', 'options'], queryFn: () => accountingOptionsApi.list() });

 const { data: cashAccounts } = useQuery({
 queryKey: ['accounting', 'accounts', 'cash'],
 queryFn: () => accountsApi.list({ per_page: 50, type: 'asset' }),
 enabled: showPay,
 });

 // REC-02 — lazy-load the 3-way match snapshot (only when the bill has a PO).
 const hasMatch = !!bill?.three_way_match_url;
 const { data: match, isLoading: matchLoading, isError: matchError, refetch: refetchMatch } = useQuery({
 queryKey: ['purchasing', 'three-way-match', id],
 queryFn: () => threeWayMatchApi.forBill(id),
 enabled: !!id && hasMatch,
 });

 const { register, handleSubmit, formState: { errors }, reset } = useForm<PaymentFormValues>({
 resolver: zodResolver(paymentSchema),
 defaultValues: { payment_date: new Date().toISOString().slice(0, 10), payment_method: '' },
 });

 const cancelMut = useMutation({
 mutationFn: () => billsApi.cancel(id),
 onSuccess: () => {
 toast.success('Bill cancelled.');
 qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
 },
 onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to cancel.'),
 });
 const postMut = useMutation({
 mutationFn: (data?: { allow_override?: boolean; override_reason?: string }) => billsApi.postDraft(id, data),
 onSuccess: () => {
  toast.success('Draft bill posted to AP + GL.');
  qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
  setShowPostConfirm(false);
  setShowPostOverride(false);
 },
 onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to post bill.'),
 });
 const payMut = useMutation({
 mutationFn: (d: PaymentFormValues) => billsApi.recordPayment(id, {
 cash_account_id: d.cash_account_id,
 payment_date: d.payment_date,
 amount: String(d.amount),
 payment_method: d.payment_method as PaymentMethod,
 reference_number: d.reference_number || undefined,
 }),
 onSuccess: () => {
 toast.success('Payment recorded.');
 qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
 setShowPay(false);
 reset({ payment_date: new Date().toISOString().slice(0, 10), payment_method: '', cash_account_id: '', amount: undefined as unknown as number, reference_number: '' });
 },
 onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to record payment.'),
 });

 if (isLoading || (!bill && !isError)) return <SkeletonDetail />;
 if (isError) return <EmptyState icon="alert-circle" title="Failed to load bill" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
 if (!bill) return null;

 const isOpen = bill.status === 'unpaid' || bill.status === 'partial';
 const cashAccts = (cashAccounts?.data ?? []).filter((a) => a.code.startsWith('10'));

 return (
 <div>
 <PageHeader
 title={
 <div className="flex items-center gap-3">
 <span className="font-mono">{bill.bill_number}</span>
 <Chip variant={chipVariantForStatus(bill.status)}>{bill.status_label ?? bill.status}</Chip>
 {bill.is_overdue && <Chip variant="danger">overdue</Chip>}
 {hasMatch && (
 bill.has_variances
 ? <Chip variant="warning">Has variances</Chip>
 : <Chip variant="success">Matched</Chip>
 )}
 {bill.three_way_overridden && <Chip variant="purple">Overridden</Chip>}
 </div>
 }
 backTo="/accounting/bills"
 backLabel="Bills"
  actions={
   <div className="flex gap-1.5">
   <Button variant="secondary" size="sm" icon={<LuPrinter size={14} />} onClick={() => void downloadAuthenticatedFile(billsApi.pdfUrl(bill.id), { openInNewTab: true, errorMessage: 'Failed to generate bill PDF.' })}>Print</Button>
 {bill.status === 'draft' && can('accounting.bills.create') && (
    <Button
     variant="primary"
     size="sm"
     icon={<LuSend size={14} />}
     onClick={() => {
      if (bill.three_way_review_status === 'manual_review' || match?.overall_status === 'blocked') {
       setShowPostOverride(true);
      } else {
       setShowPostConfirm(true);
      }
     }}
    >Post bill</Button>
   )}
   {isOpen && can('accounting.bills.pay') && (
 <Button variant="primary" size="sm" icon={<LuReceipt size={14} />} onClick={() => setShowPay(true)}>Record payment</Button>
 )}
 {bill.amount_paid === '0.00' && bill.status !== 'cancelled' && can('accounting.bills.update') && (
 <Button variant="danger" size="sm" icon={<LuBan size={14} />} onClick={() => setShowCancelConfirm(true)}>
 Cancel
 </Button>
 )}
 </div>
 }
 />

 <div className="px-5 pt-4">
 <Panel title="Procure-to-Pay">
 {/* 2026-08-08 — compact cross-document stepper: the whole chain at a glance.
 This bill is the Bill step; upstream (PR/PO/GRN) and downstream (Paid)
 stay visible and clickable from here. */}
 <ChainHeader steps={buildP2pChain({
  pr: bill.purchase_order?.purchase_request
  ? { id: bill.purchase_order.purchase_request.id, number: bill.purchase_order.purchase_request.pr_number }
  : null,
  po: bill.purchase_order ? { id: bill.purchase_order.id, number: bill.purchase_order.po_number } : null,
  grns: bill.goods_receipt_notes ?? [],
  bills: [{ id: bill.id, bill_number: bill.bill_number, status: bill.status }],
 })} />
 </Panel>
 </div>

 <div className="px-5 py-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
 <StatCard label="Total" value={formatPeso(bill.total_amount)} />
 <StatCard label="Paid" value={formatPeso(bill.amount_paid)} />
 <StatCard label="Balance" value={formatPeso(bill.balance)} delta={bill.is_overdue ? { value: 'OVERDUE', direction: 'down' } : undefined} />
 <StatCard label="Aging" value={bill.aging_bucket.replace('_', '–').replace('d', '')} />
 </div>

 <div className="px-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
 <div className="col-span-2 space-y-4">
 <Panel title="Details">
 <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Vendor</dt><dd>{bill.vendor?.name}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Date</dt><dd className="font-mono">{formatDate(bill.date)}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Due date</dt><dd className="font-mono">{formatDate(bill.due_date)}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">VAT</dt><dd>{bill.is_vatable ? 'Yes' : 'No'}</dd></div>
 {bill.purchase_order && (
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Purchase order</dt>
 <dd><a className="text-accent hover:underline font-mono" href={`/purchasing/purchase-orders/${bill.purchase_order.id}`}>{bill.purchase_order.po_number}</a></dd>
 </div>
 )}
 {bill.three_way_overridden && bill.three_way_override_reason && (
 <div className="col-span-3"><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Override reason</dt><dd className="text-warning-fg">{bill.three_way_override_reason}</dd></div>
 )}
 {bill.journal_entry && (
 <div className="col-span-2"><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Journal entry</dt>
 <dd><a className="text-accent hover:underline font-mono" href={`/accounting/journal-entries/${bill.journal_entry.id}`}>{bill.journal_entry.entry_number}</a> · {bill.journal_entry.status_label ?? bill.journal_entry.status}</dd>
 </div>
 )}
 </dl>
 </Panel>

 <Panel title="Line items">
 <div className="overflow-x-auto">
 <table className={`${tableCls} min-w-[680px]`}>
 <thead>
 <tr className={theadTrCls}>
 <Th>#</Th>
 <Th>Description</Th>
 <Th>Account</Th>
 <Th align="right">Qty</Th>
 <Th align="right">Unit price</Th>
 <Th align="right">Total</Th>
 </tr>
 </thead>
 <tbody>
 {bill.items?.map((i, idx) => (
 <tr key={i.id} className={trCls}>
 <Td mono className="text-muted">{String(idx + 1).padStart(2, '0')}</Td>
 <Td>{i.description}</Td>
 <Td className="text-muted text-xs">
 {i.expense_account ? <span><span className="font-mono">{i.expense_account.code}</span> · {i.expense_account.name}</span> : '—'}
 </Td>
 <Td align="right" mono>{i.quantity}{i.unit ? ` ${i.unit}` : ''}</Td>
 <Td align="right" mono>{formatPeso(i.unit_price)}</Td>
 <Td align="right" mono className="font-medium">{formatPeso(i.total)}</Td>
 </tr>
 ))}
 <tr className={trCls}><Td align="right" mono className="text-muted" colSpan={5}>Subtotal</Td><Td align="right" mono>{formatPeso(bill.subtotal)}</Td></tr>
 {bill.is_vatable && <tr className={trCls}><Td align="right" mono className="text-muted" colSpan={5}>VAT</Td><Td align="right" mono>{formatPeso(bill.vat_amount)}</Td></tr>}
 <tr className={totalsTrCls}><Td align="right" mono colSpan={5}>Total</Td><Td align="right" mono>{formatPeso(bill.total_amount)}</Td></tr>
 </tbody>
 </table>
 </div>
 </Panel>
 </div>

 <Panel title="Payments" className="col-span-1">
 {(bill.payments?.length ?? 0) === 0 ? (
 <p className="text-sm text-muted">No payments yet.</p>
 ) : (
 <ul className="space-y-2 text-xs">
 {bill.payments!.map((p) => (
 <li key={p.id} className="border-b border-subtle pb-2">
 <div className="flex justify-between font-mono tabular-nums">
 <span>{formatDate(p.payment_date)}</span>
 <span className="font-medium">{formatPeso(p.amount)}</span>
 </div>
 <div className="text-muted">{p.payment_method_label ?? p.payment_method}{p.reference_number ? ` · ${p.reference_number}` : ''}</div>
 </li>
 ))}
 </ul>
 )}
 </Panel>

 {hasMatch && (
 <Panel title="3-way match" className="col-span-3">
 {bill.three_way_review_status === 'manual_review' && (
  <div className="mb-3 rounded-md border border-danger/40 bg-danger-bg/20 px-3 py-2 text-sm text-danger-fg">
   Manual review is required before this draft can post. Confirm the variance against the supplier documents; an override requires an audit reason.
  </div>
 )}
 {matchLoading ? (
 <p className="text-sm text-muted">Loading match snapshot…</p>
 ) : matchError ? (
 <EmptyState icon="alert-circle" title="Failed to load match" action={<Button variant="secondary" size="sm" onClick={() => refetchMatch()}>Retry</Button>} />
 ) : !match || match.lines.length === 0 ? (
 <p className="text-sm text-muted">No match snapshot available.</p>
 ) : (
 <div className="overflow-x-auto">
 <table className={`${tableCls} min-w-[980px]`}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Item</Th>
 <Th align="right">PO qty</Th>
 <Th align="right">PO price</Th>
 <Th align="right">GRN accepted</Th>
 <Th align="right">Bill qty</Th>
 <Th align="right">Bill price</Th>
 <Th align="right">Qty var</Th>
 <Th align="right">Price var</Th>
 <Th>Status</Th>
 </tr>
 </thead>
 <tbody>
 {match.lines.map((l, idx) => {
 const chip = MATCH_LINE_VARIANT[l.status];
 const isBlock = l.severity === 'block';
 return (
 <tr key={`${l.item_id}-${idx}`} className={cn(trCls, isBlock && 'bg-danger-bg/30')}>
 <Td>
 {l.item_code && <span className="font-mono text-xs text-muted mr-1">{l.item_code}</span>}
 {l.description}
 </Td>
 <Td align="right" mono>{l.po_quantity}</Td>
 <Td align="right" mono>{formatPeso(l.po_unit_price)}</Td>
 <Td align="right" mono>{l.grn_quantity_accepted}</Td>
 <Td align="right" mono>{l.bill_quantity}</Td>
 <Td align="right" mono>{formatPeso(l.bill_unit_price)}</Td>
 <Td align="right" mono>{l.quantity_variance_pct.toFixed(2)}%</Td>
 <Td align="right" mono>{l.price_variance_pct.toFixed(2)}%</Td>
 <Td>
 <Chip variant={isBlock ? 'danger' : chip}>{l.status_label ?? l.status}</Chip>
 </Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </div>
 )}
 </Panel>
 )}
 </div>

 <Modal isOpen={showPay} onClose={() => setShowPay(false)} title={`Record payment for ${bill.bill_number}`} size="sm">
 <form onSubmit={handleSubmit((d) => payMut.mutate(d), onFormInvalid<PaymentFormValues>())} className="space-y-3">
 <Select label="Cash account" required {...register('cash_account_id')} error={errors.cash_account_id?.message}>
 <option value="">— Select —</option>
 {cashAccts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
 </Select>
 <Input label="Payment date" type="date" required {...register('payment_date')} error={errors.payment_date?.message} />
 <Input label={`Amount (max ${formatPeso(bill.balance)})`} step="0.01" min="0.01" max={bill.balance}
 className="font-mono tabular-nums text-right" required prefix="₱" {...numberInputProps()}
 {...register('amount')} error={errors.amount?.message} />
 <Select label="Method" required {...register('payment_method')} error={errors.payment_method?.message}>
 {(accountingOptions?.payment_methods ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 <Input label="Reference no." {...register('reference_number')} />
 <ModalFooter>
 <Button type="button" variant="secondary" onClick={() => setShowPay(false)}>Cancel</Button>
 <Button type="submit" variant="primary" loading={payMut.isPending} disabled={payMut.isPending}>Record</Button>
 </ModalFooter>
 </form>
 </Modal>

 <ConfirmDialog
 isOpen={showPostConfirm}
 onClose={() => setShowPostConfirm(false)}
 onConfirm={() => postMut.mutate({})}
 title={`Post draft bill ${bill.bill_number}?`}
 description="Posting records the payable: it builds and posts the AP/expense journal entry (debit expense + VAT input, credit AP) and flips the bill to Unpaid. Review the auto-created amounts before posting."
 confirmLabel="Post bill"
 variant="primary"
 pending={postMut.isPending}
 />

 <ReasonDialog
 isOpen={showPostOverride}
 onClose={() => setShowPostOverride(false)}
 onConfirm={(reason) => postMut.mutate({ allow_override: true, override_reason: reason })}
 title={`Override 3-way match for ${bill.bill_number}?`}
 description="This posts the payable despite a blocking PO, GRN, or supplier-price variance. The reason and your account will be recorded in the bill audit trail."
 reasonLabel="Override reason"
 reasonPlaceholder="e.g. Purchasing approved the documented supplier price change."
 minLength={10}
 confirmLabel="Post with override"
 variant="warning"
 pending={postMut.isPending}
 />

 <ConfirmDialog
 isOpen={showCancelConfirm}
 onClose={() => setShowCancelConfirm(false)}
 onConfirm={() => { cancelMut.mutate(); setShowCancelConfirm(false); }}
 title="Cancel this bill?"
 description="This will reverse the associated journal entry. This action cannot be undone."
 variant="danger"
 confirmLabel="Cancel bill"
 pending={cancelMut.isPending}
 />
 </div>
 );
}
