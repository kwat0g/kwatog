import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Printer, Receipt, Ban } from 'lucide-react';
import { billsApi } from '@/api/accounting/bills';
import { accountingOptionsApi } from '@/api/accounting/options';
import { downloadAuthenticatedFile } from '@/api/download';
import { accountsApi } from '@/api/accounting/accounts';
import { threeWayMatchApi } from '@/api/purchasing/purchase-orders';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import type { ChipVariant } from '@/components/ui/Chip';import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { ChainHeader } from '@/components/chain';
import type { ChainStep } from '@/types/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
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
type MatchLineStatus = 'matched' | 'qty_variance' | 'price_variance' | 'both' | 'grn_short';
const MATCH_LINE_VARIANT: Record<MatchLineStatus, ChipVariant> = {
 matched: 'success', qty_variance: 'warning', price_variance: 'warning', both: 'warning', grn_short: 'danger',
};

function buildBillChain(bill: { status: string; amount_paid: string; balance: string; date: string; payments?: Array<{ payment_date: string }> }): ChainStep[] {
 const isCancelled = bill.status === 'cancelled';
 const billCreated = !isCancelled;
 const hasPayment = (bill.payments?.length ?? 0) > 0;
 const fullyPaid = parseFloat(bill.balance) <= 0 && parseFloat(bill.amount_paid) > 0;
 return [
 { key: 'bill', label: 'Bill Created', state: billCreated ? 'done' : isCancelled ? 'pending' : 'active', date: bill.date.slice(0, 10) },
 { key: 'pay', label: 'Payment Made', state: fullyPaid ? 'done' : hasPayment ? 'active' : 'pending', date: bill.payments?.[0]?.payment_date.slice(0, 10) },
 { key: 'closed', label: 'Settled', state: fullyPaid ? 'done' : 'pending' },
 ];
}

export default function BillDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [showPay, setShowPay] = useState(false);
 const [showCancelConfirm, setShowCancelConfirm] = useState(false);

 const { data: bill, isLoading, isError, refetch } = useQuery({
 queryKey: ['accounting', 'bills', id],
 queryFn: () => billsApi.show(id),
 enabled: !!id,
 });
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
 breadcrumbs={[
 { label: 'Accounting', href: '/accounting' },
 { label: 'Bills', href: '/accounting/bills' },
 { label: bill.bill_number },
 ]}
 actions={
 <div className="flex gap-1.5">
 <Button variant="secondary" size="sm" icon={<Printer size={14} />} onClick={() => void downloadAuthenticatedFile(billsApi.pdfUrl(bill.id), { openInNewTab: true, errorMessage: 'Failed to generate bill PDF.' })}>Print</Button>
 {isOpen && can('accounting.bills.pay') && (
 <Button variant="primary" size="sm" icon={<Receipt size={14} />} onClick={() => setShowPay(true)}>Record payment</Button>
 )}
 {bill.amount_paid === '0.00' && bill.status !== 'cancelled' && can('accounting.bills.update') && (
 <Button variant="danger" size="sm" icon={<Ban size={14} />} onClick={() => setShowCancelConfirm(true)}>
 Cancel
 </Button>
 )}
 </div>
 }
 />

 <div className="px-5 pt-4">
 <Panel title="Procure-to-Pay">
 <ChainHeader steps={buildBillChain(bill)} />
 </Panel>
 </div>

 <div className="px-5 py-4 grid grid-cols-4 gap-4">
 <StatCard label="Total" value={formatPeso(bill.total_amount)} />
 <StatCard label="Paid" value={formatPeso(bill.amount_paid)} />
 <StatCard label="Balance" value={formatPeso(bill.balance)} delta={bill.is_overdue ? { value: 'OVERDUE', direction: 'down' } : undefined} />
 <StatCard label="Aging" value={bill.aging_bucket.replace('_', '–').replace('d', '')} />
 </div>

 <div className="px-5 grid grid-cols-3 gap-4">
 <div className="col-span-2 space-y-4">
 <Panel title="Details">
 <dl className="grid grid-cols-3 gap-3 text-sm">
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
 <table className={tableCls}>
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
 {matchLoading ? (
 <p className="text-sm text-muted">Loading match snapshot…</p>
 ) : matchError ? (
 <EmptyState icon="alert-circle" title="Failed to load match" action={<Button variant="secondary" size="sm" onClick={() => refetchMatch()}>Retry</Button>} />
 ) : !match || match.lines.length === 0 ? (
 <p className="text-sm text-muted">No match snapshot available.</p>
 ) : (
 <table className={tableCls}>
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
 <Input label={`Amount (max ${formatPeso(bill.balance)})`} type="number" step="0.01" min="0.01" max={bill.balance}
 className="font-mono tabular-nums text-right" required prefix="₱" {...numberInputProps()}
 {...register('amount')} error={errors.amount?.message} />
 <Select label="Method" required {...register('payment_method')} error={errors.payment_method?.message}>
 {(accountingOptions?.payment_methods ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 <Input label="Reference no." {...register('reference_number')} />
 <div className="flex justify-end gap-2 pt-2 border-t border-default">
 <Button type="button" variant="secondary" onClick={() => setShowPay(false)}>Cancel</Button>
 <Button type="submit" variant="primary" loading={payMut.isPending} disabled={payMut.isPending}>Record</Button>
 </div>
 </form>
 </Modal>

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
