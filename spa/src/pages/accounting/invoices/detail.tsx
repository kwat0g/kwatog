import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { LuPrinter, LuCoins, LuBan, LuCircleCheck } from '@/lib/icons';
import { invoicesApi } from '@/api/accounting/invoices';
import { accountingOptionsApi } from '@/api/accounting/options';
import { downloadAuthenticatedFile } from '@/api/download';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { ChainHeader } from '@/components/chain';
import { buildO2cChain } from '@/lib/chains';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useChainProgress } from '@/hooks/useChainProgress';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { numberInputProps } from '@/lib/numberInput';
import { Td, Th, tableCls, theadTrCls, totalsTrCls, trCls } from '@/components/ui/table-cells';
import type { PaymentMethod } from '@/types/accounting';

const collectionSchema = z.object({
 cash_account_id: z.string().min(1, 'Required'),
 collection_date: z.string().min(1, 'Required'),
 amount: z.coerce.number().positive('> 0'),
 payment_method: z.string().min(1, 'Required'),
 reference_number: z.string().max(50).optional().or(z.literal('')),
});
type CollectionFormValues = z.infer<typeof collectionSchema>;

export default function InvoiceDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [showCollect, setShowCollect] = useState(false);
 const [showFinalize, setShowFinalize] = useState(false);
 const [showCancelConfirm, setShowCancelConfirm] = useState(false);

 const { data: invoice, isLoading, isError, refetch } = useQuery({
 queryKey: ['accounting', 'invoices', id],
 queryFn: () => invoicesApi.show(id),
 enabled: !!id,
 });

 // 2026-08-08 — live chain progress (finalize / collections) on the invoice page.
 useChainProgress('invoice', id, ['accounting', 'invoices', id]);
 const { data: accountingOptions } = useQuery({ queryKey: ['accounting', 'options'], queryFn: () => accountingOptionsApi.list() });

 const { data: cashAccounts } = useQuery({
 queryKey: ['accounting', 'accounts', 'cash'],
 queryFn: () => accountsApi.list({ per_page: 50, type: 'asset' }),
 enabled: showCollect,
 });

 const { register, handleSubmit, formState: { errors }, reset } = useForm<CollectionFormValues>({
 resolver: zodResolver(collectionSchema),
 defaultValues: { collection_date: new Date().toISOString().slice(0, 10), payment_method: '' },
 });

 const finalizeMut = useMutation({
 mutationFn: () => invoicesApi.finalize(id),
 onSuccess: (inv) => {
 toast.success(`Invoice ${inv.invoice_number} finalized.`);
 qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
 },
 onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to finalize.'),
 });
 const cancelMut = useMutation({
 mutationFn: () => invoicesApi.cancel(id),
 onSuccess: () => {
 toast.success('Invoice cancelled.');
 qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
 },
 onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to cancel.'),
 });
 const collectMut = useMutation({
 mutationFn: (d: CollectionFormValues) => invoicesApi.recordCollection(id, {
 cash_account_id: d.cash_account_id, collection_date: d.collection_date,
 amount: String(d.amount), payment_method: d.payment_method as PaymentMethod,
 reference_number: d.reference_number || undefined,
 }),
 onSuccess: () => {
 toast.success('Collection recorded.');
 qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
 setShowCollect(false);
 reset({ collection_date: new Date().toISOString().slice(0, 10), payment_method: '', cash_account_id: '', amount: undefined as unknown as number, reference_number: '' });
 },
 onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to record collection.'),
 });

 if (isLoading || (!invoice && !isError)) return <SkeletonDetail />;
 if (isError) return <EmptyState icon="alert-circle" title="Failed to load invoice" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
 if (!invoice) return null;

 const isDraft = invoice.status === 'draft';
 const isOpen = invoice.status === 'finalized' || invoice.status === 'partial';
 const cashAccts = (cashAccounts?.data ?? []).filter((a: { code: string }) => a.code.startsWith('10'));

 return (
 <div>
 <PageHeader
 title={
 <div className="flex items-center gap-3">
 <span className="font-mono">{invoice.invoice_number ?? 'DRAFT'}</span>
 <Chip variant={chipVariantForStatus(invoice.display_status)}>{invoice.display_status}</Chip>
 {invoice.is_overdue && <Chip variant="danger">overdue</Chip>}
 </div>
 }
 backTo="/accounting/invoices"
 backLabel="Invoices"
 breadcrumbs={[
 { label: 'Accounting' },
 { label: 'Invoices', href: '/accounting/invoices' },
 { label: invoice.invoice_number ?? 'Draft' },
 ]}
 actions={
 <div className="flex gap-1.5">
 <Button variant="secondary" size="sm" icon={<LuPrinter size={14} />} onClick={() => void downloadAuthenticatedFile(invoicesApi.pdfUrl(invoice.id), { openInNewTab: true, errorMessage: 'Failed to generate invoice PDF.' })}>Print</Button>
 {isDraft && can('accounting.invoices.create') && (
 <Button variant="primary" size="sm" icon={<LuCircleCheck size={14} />} onClick={() => setShowFinalize(true)} disabled={finalizeMut.isPending}>
 Finalize
 </Button>
 )}
 {isOpen && can('accounting.invoices.collect') && (
 <Button variant="primary" size="sm" icon={<LuCoins size={14} />} onClick={() => setShowCollect(true)}>Record collection</Button>
 )}
 {invoice.amount_paid === '0.00' && invoice.status !== 'cancelled' && can('accounting.invoices.update') && (
 <Button variant="danger" size="sm" icon={<LuBan size={14} />} onClick={() => setShowCancelConfirm(true)}>Cancel</Button>
 )}
 </div>
 }
 />

 <div className="px-5 pt-4">
 <Panel title="Order-to-Cash">
 {/* 2026-08-08 — compact cross-document stepper: the whole chain at a glance.
 This invoice is the Invoice step; upstream (SO/Delivery) and downstream
 (Payment) stay visible and clickable from here. */}
 <ChainHeader steps={buildO2cChain({
  so: invoice.sales_order ? { id: invoice.sales_order.id, number: invoice.sales_order.so_number } : null,
  delivery: invoice.delivery ? { id: invoice.delivery.id, number: invoice.delivery.delivery_number } : null,
  invoices: [{ id: invoice.id, invoice_number: invoice.invoice_number ?? '', status: invoice.status }],
 })} />
 </Panel>
 </div>

 <div className="px-5 py-4 grid grid-cols-4 gap-4">
 <StatCard label="Total" value={formatPeso(invoice.total_amount)} />
 <StatCard label="Collected" value={formatPeso(invoice.amount_paid)} />
 <StatCard label="Balance" value={formatPeso(invoice.balance)} delta={invoice.is_overdue ? { value: 'OVERDUE', direction: 'down' } : undefined} />
 <StatCard label="Aging" value={invoice.aging_bucket.replace('d', '').replace('_', '–')} />
 </div>

 <div className="px-5 grid grid-cols-3 gap-4">
 <div className="col-span-2 space-y-4">
 <Panel title="Details">
 <dl className="grid grid-cols-3 gap-3 text-sm">
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Customer</dt><dd>{invoice.customer?.name}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Date</dt><dd className="font-mono">{formatDate(invoice.date)}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Due date</dt><dd className="font-mono">{formatDate(invoice.due_date)}</dd></div>
 <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">VAT</dt><dd>{invoice.is_vatable ? 'Yes' : 'No'}</dd></div>
 {invoice.journal_entry && (
 <div className="col-span-2"><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Journal entry</dt>
 <dd><a className="text-accent hover:underline font-mono" href={`/accounting/journal-entries/${invoice.journal_entry.id}`}>{invoice.journal_entry.entry_number}</a> · {invoice.journal_entry.status_label ?? invoice.journal_entry.status}</dd>
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
 {invoice.items?.map((i, idx) => (
 <tr key={i.id} className={trCls}>
 <Td mono className="text-muted">{String(idx + 1).padStart(2, '0')}</Td>
 <Td>{i.description}</Td>
 <Td className="text-muted text-xs">{i.revenue_account ? <span><span className="font-mono">{i.revenue_account.code}</span> · {i.revenue_account.name}</span> : '—'}</Td>
 <Td align="right" mono>{i.quantity}{i.unit ? ` ${i.unit}` : ''}</Td>
 <Td align="right" mono>{formatPeso(i.unit_price)}</Td>
 <Td align="right" mono className="font-medium">{formatPeso(i.total)}</Td>
 </tr>
 ))}
 <tr className={trCls}><Td align="right" mono className="text-muted" colSpan={5}>Subtotal</Td><Td align="right" mono>{formatPeso(invoice.subtotal)}</Td></tr>
 {invoice.is_vatable && <tr className={trCls}><Td align="right" mono className="text-muted" colSpan={5}>VAT</Td><Td align="right" mono>{formatPeso(invoice.vat_amount)}</Td></tr>}
 <tr className={totalsTrCls}><Td align="right" mono colSpan={5}>Total</Td><Td align="right" mono>{formatPeso(invoice.total_amount)}</Td></tr>
 </tbody>
 </table>
 </div>
 </Panel>
 </div>

 <Panel title="Collections" className="col-span-1">
 {(invoice.collections?.length ?? 0) === 0 ? (
 <p className="text-sm text-muted">No collections yet.</p>
 ) : (
 <ul className="space-y-2 text-xs">
 {invoice.collections!.map((c) => (
 <li key={c.id} className="border-b border-subtle pb-2">
 <div className="flex justify-between font-mono tabular-nums">
 <span>{formatDate(c.collection_date)}</span>
 <span className="font-medium">{formatPeso(c.amount)}</span>
 </div>
 <div className="text-muted">{c.payment_method_label ?? c.payment_method}{c.reference_number ? ` · ${c.reference_number}` : ''}</div>
 </li>
 ))}
 </ul>
 )}
 </Panel>
 </div>

 <Modal isOpen={showCollect} onClose={() => setShowCollect(false)} title={`Record collection · ${invoice.invoice_number ?? 'DRAFT'}`} size="sm">
 <form onSubmit={handleSubmit((d) => collectMut.mutate(d), onFormInvalid<CollectionFormValues>())} className="space-y-3">
 <Select label="Cash account" required {...register('cash_account_id')} error={errors.cash_account_id?.message}>
 <option value="">— Select —</option>
 {cashAccts.map((a: { id: string; code: string; name: string }) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
 </Select>
 <Input label="Collection date" type="date" required {...register('collection_date')} error={errors.collection_date?.message} />
 <Input label={`Amount (max ${formatPeso(invoice.balance)})`} step="0.01" min="0.01" max={invoice.balance}
 className="font-mono tabular-nums text-right" required prefix="₱" {...numberInputProps()}
 {...register('amount')} error={errors.amount?.message} />
 <Select label="Method" required {...register('payment_method')} error={errors.payment_method?.message}>
 {(accountingOptions?.payment_methods ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 <Input label="Reference no." {...register('reference_number')} />
 <ModalFooter>
 <Button type="button" variant="secondary" onClick={() => setShowCollect(false)}>Cancel</Button>
 <Button type="submit" variant="primary" loading={collectMut.isPending} disabled={collectMut.isPending}>Record</Button>
 </ModalFooter>
 </form>
 </Modal>

 <ConfirmDialog
 isOpen={showFinalize}
 onClose={() => setShowFinalize(false)}
 onConfirm={() => { finalizeMut.mutate(); setShowFinalize(false); }}
 title="Finalize invoice?"
 description="Once finalized, this invoice will be sent to the customer and cannot be edited."
 confirmLabel="Finalize"
 variant="warning"
 pending={finalizeMut.isPending}
 />
 <ConfirmDialog
 isOpen={showCancelConfirm}
 onClose={() => setShowCancelConfirm(false)}
 onConfirm={() => { cancelMut.mutate(); setShowCancelConfirm(false); }}
 title="Cancel this invoice?"
 description="This action cannot be undone."
 confirmLabel="Cancel invoice"
 variant="danger"
 pending={cancelMut.isPending}
 />
 </div>
 );
}
