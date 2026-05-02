import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { Printer, Coins, Ban, CheckCircle2 } from 'lucide-react';
import { invoicesApi } from '@/api/accounting/invoices';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { numberInputProps } from '@/lib/numberInput';

const collectionSchema = z.object({
  cash_account_id:  z.string().min(1, 'Required'),
  collection_date:  z.string().min(1, 'Required'),
  amount:           z.coerce.number().positive('> 0'),
  payment_method:   z.enum(['cash', 'check', 'bank_transfer', 'online']),
  reference_number: z.string().max(50).optional().or(z.literal('')),
});
type CollectionFormValues = z.infer<typeof collectionSchema>;

export default function InvoiceDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [showCollect, setShowCollect] = useState(false);

  const { data: invoice, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'invoices', id],
    queryFn: () => invoicesApi.show(id),
    enabled: !!id,
  });

  const { data: cashAccounts } = useQuery({
    queryKey: ['accounting', 'accounts', 'cash'],
    queryFn: () => accountsApi.list({ per_page: 50, type: 'asset' }),
    enabled: showCollect,
  });

  const { register, handleSubmit, formState: { errors }, reset } = useForm<CollectionFormValues>({
    resolver: zodResolver(collectionSchema),
    defaultValues: { collection_date: new Date().toISOString().slice(0, 10), payment_method: 'bank_transfer' },
  });

  const finalizeMut = useMutation({
    mutationFn: () => invoicesApi.finalize(id),
    onSuccess: (inv) => {
      toast.success(`Invoice ${inv.invoice_number} finalized.`);
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
    },
    onError: (e: any) => toast.error(e.response?.data?.message ?? 'Failed to finalize.'),
  });
  const cancelMut = useMutation({
    mutationFn: () => invoicesApi.cancel(id),
    onSuccess: () => {
      toast.success('Invoice cancelled.');
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
    },
    onError: (e: any) => toast.error(e.response?.data?.message ?? 'Failed to cancel.'),
  });
  const collectMut = useMutation({
    mutationFn: (d: CollectionFormValues) => invoicesApi.recordCollection(id, {
      cash_account_id: d.cash_account_id, collection_date: d.collection_date,
      amount: String(d.amount), payment_method: d.payment_method,
      reference_number: d.reference_number || undefined,
    }),
    onSuccess: () => {
      toast.success('Collection recorded.');
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
      setShowCollect(false);
      reset({ collection_date: new Date().toISOString().slice(0, 10), payment_method: 'bank_transfer', cash_account_id: '', amount: 0, reference_number: '' });
    },
    onError: (e: any) => toast.error(e.response?.data?.message ?? 'Failed to record collection.'),
  });

  if (isLoading || (!invoice && !isError)) return <SkeletonDetail />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load invoice" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  if (!invoice) return null;

  const isDraft = invoice.status === 'draft';
  const isOpen = invoice.status === 'finalized' || invoice.status === 'partial';
  const cashAccts = (cashAccounts?.data ?? []).filter((a: any) => a.code.startsWith('10'));

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
        actions={
          <div className="flex gap-1.5">
            <a href={invoicesApi.pdfUrl(invoice.id)} target="_blank" rel="noreferrer">
              <Button variant="secondary" size="sm" icon={<Printer size={14} />}>Print</Button>
            </a>
            {isDraft && can('accounting.invoices.create') && (
              <Button variant="primary" size="sm" icon={<CheckCircle2 size={14} />} onClick={() => finalizeMut.mutate()} loading={finalizeMut.isPending} disabled={finalizeMut.isPending}>
                Finalize
              </Button>
            )}
            {isOpen && can('accounting.invoices.collect') && (
              <Button variant="primary" size="sm" icon={<Coins size={14} />} onClick={() => setShowCollect(true)}>Record collection</Button>
            )}
            {invoice.amount_paid === '0.00' && invoice.status !== 'cancelled' && can('accounting.invoices.update') && (
              <Button variant="danger" size="sm" icon={<Ban size={14} />} onClick={() => { if (confirm('Cancel this invoice?')) cancelMut.mutate(); }}>Cancel</Button>
            )}
          </div>
        }
      />

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
              <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">VAT</dt><dd>{invoice.is_vatable ? 'Yes (12%)' : 'No'}</dd></div>
              {invoice.journal_entry && (
                <div className="col-span-2"><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Journal entry</dt>
                  <dd><a className="text-accent hover:underline font-mono" href={`/accounting/journal-entries/${invoice.journal_entry.id}`}>{invoice.journal_entry.entry_number}</a> · {invoice.journal_entry.status}</dd>
                </div>
              )}
            </dl>
          </Panel>

          <Panel title="Line items">
            <table className="w-full text-sm">
              <thead className="text-2xs uppercase tracking-wider text-muted">
                <tr className="border-b border-default bg-subtle">
                  <th className="h-8 px-2.5 text-left">#</th>
                  <th className="h-8 px-2.5 text-left">Description</th>
                  <th className="h-8 px-2.5 text-left">Account</th>
                  <th className="h-8 px-2.5 text-right">Qty</th>
                  <th className="h-8 px-2.5 text-right">Unit price</th>
                  <th className="h-8 px-2.5 text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                {invoice.items?.map((i, idx) => (
                  <tr key={i.id} className="h-8 border-b border-subtle">
                    <td className="px-2.5 text-muted font-mono tabular-nums">{String(idx + 1).padStart(2, '0')}</td>
                    <td className="px-2.5">{i.description}</td>
                    <td className="px-2.5 text-muted text-xs">{i.revenue_account ? <span><span className="font-mono">{i.revenue_account.code}</span> · {i.revenue_account.name}</span> : '—'}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{i.quantity}{i.unit ? ` ${i.unit}` : ''}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(i.unit_price)}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums font-medium">{formatPeso(i.total)}</td>
                  </tr>
                ))}
                <tr className="h-7"><td colSpan={5} className="px-2.5 text-right text-muted">Subtotal</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(invoice.subtotal)}</td></tr>
                {invoice.is_vatable && <tr className="h-7"><td colSpan={5} className="px-2.5 text-right text-muted">VAT (12%)</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(invoice.vat_amount)}</td></tr>}
                <tr className="h-8 border-t-2 border-primary font-medium"><td colSpan={5} className="px-2.5 text-right">Total</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(invoice.total_amount)}</td></tr>
              </tbody>
            </table>
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
                  <div className="text-muted">{c.payment_method}{c.reference_number ? ` · ${c.reference_number}` : ''}</div>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <Modal isOpen={showCollect} onClose={() => setShowCollect(false)} title={`Record collection · ${invoice.invoice_number ?? 'DRAFT'}`} size="sm">
        <form onSubmit={handleSubmit((d) => collectMut.mutate(d))} className="space-y-3">
          <Select label="Cash account" required {...register('cash_account_id')} error={errors.cash_account_id?.message}>
            <option value="">— Select —</option>
            {cashAccts.map((a: any) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
          </Select>
          <Input label="Collection date" type="date" required {...register('collection_date')} error={errors.collection_date?.message} />
          <Input label={`Amount (max ${formatPeso(invoice.balance)})`} type="number" step="0.01" min="0.01" max={invoice.balance}
            className="font-mono tabular-nums text-right" required prefix="₱" {...numberInputProps()}
            {...register('amount')} error={errors.amount?.message} />
          <Select label="Method" required {...register('payment_method')} error={errors.payment_method?.message}>
            <option value="cash">Cash</option>
            <option value="check">Check</option>
            <option value="bank_transfer">Bank transfer</option>
            <option value="online">Online</option>
          </Select>
          <Input label="Reference no." {...register('reference_number')} />
          <div className="flex justify-end gap-2 pt-2 border-t border-default">
            <Button type="button" variant="secondary" onClick={() => setShowCollect(false)}>Cancel</Button>
            <Button type="submit" variant="primary" loading={collectMut.isPending} disabled={collectMut.isPending}>Record</Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
