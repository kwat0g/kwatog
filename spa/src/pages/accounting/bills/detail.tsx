import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { Printer, Receipt, Ban } from 'lucide-react';
import { billsApi } from '@/api/accounting/bills';
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

const paymentSchema = z.object({
  cash_account_id:  z.string().min(1, 'Required'),
  payment_date:     z.string().min(1, 'Required'),
  amount:           z.coerce.number().positive('> 0'),
  payment_method:   z.enum(['cash', 'check', 'bank_transfer', 'online']),
  reference_number: z.string().max(50).optional().or(z.literal('')),
});
type PaymentFormValues = z.infer<typeof paymentSchema>;

export default function BillDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [showPay, setShowPay] = useState(false);

  const { data: bill, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'bills', id],
    queryFn: () => billsApi.show(id),
    enabled: !!id,
  });

  const { data: cashAccounts } = useQuery({
    queryKey: ['accounting', 'accounts', 'cash'],
    queryFn: () => accountsApi.list({ per_page: 50, type: 'asset' }),
    enabled: showPay,
  });

  const { register, handleSubmit, formState: { errors }, reset } = useForm<PaymentFormValues>({
    resolver: zodResolver(paymentSchema),
    defaultValues: { payment_date: new Date().toISOString().slice(0, 10), payment_method: 'bank_transfer' },
  });

  const cancelMut = useMutation({
    mutationFn: () => billsApi.cancel(id),
    onSuccess: () => {
      toast.success('Bill cancelled.');
      qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
    },
    onError: (e: any) => toast.error(e.response?.data?.message ?? 'Failed to cancel.'),
  });
  const payMut = useMutation({
    mutationFn: (d: PaymentFormValues) => billsApi.recordPayment(id, {
      cash_account_id: d.cash_account_id,
      payment_date:    d.payment_date,
      amount:          String(d.amount),
      payment_method:  d.payment_method,
      reference_number: d.reference_number || undefined,
    }),
    onSuccess: () => {
      toast.success('Payment recorded.');
      qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
      setShowPay(false);
      reset({ payment_date: new Date().toISOString().slice(0, 10), payment_method: 'bank_transfer', cash_account_id: '', amount: 0, reference_number: '' });
    },
    onError: (e: any) => toast.error(e.response?.data?.message ?? 'Failed to record payment.'),
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
            <Chip variant={chipVariantForStatus(bill.status)}>{bill.status}</Chip>
            {bill.is_overdue && <Chip variant="danger">overdue</Chip>}
          </div>
        }
        backTo="/accounting/bills"
        backLabel="Bills"
        actions={
          <div className="flex gap-1.5">
            <a href={billsApi.pdfUrl(bill.id)} target="_blank" rel="noreferrer">
              <Button variant="secondary" size="sm" icon={<Printer size={14} />}>Print</Button>
            </a>
            {isOpen && can('accounting.bills.pay') && (
              <Button variant="primary" size="sm" icon={<Receipt size={14} />} onClick={() => setShowPay(true)}>Record payment</Button>
            )}
            {bill.amount_paid === '0.00' && bill.status !== 'cancelled' && can('accounting.bills.update') && (
              <Button variant="danger" size="sm" icon={<Ban size={14} />} onClick={() => { if (confirm('Cancel this bill? This will reverse the JE.')) cancelMut.mutate(); }}>
                Cancel
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 grid grid-cols-4 gap-4">
        <StatCard label="Total" value={formatPeso(bill.total_amount)} />
        <StatCard label="Paid"  value={formatPeso(bill.amount_paid)} />
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
              <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">VAT</dt><dd>{bill.is_vatable ? 'Yes (12%)' : 'No'}</dd></div>
              {bill.journal_entry && (
                <div className="col-span-2"><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Journal entry</dt>
                  <dd><a className="text-accent hover:underline font-mono" href={`/accounting/journal-entries/${bill.journal_entry.id}`}>{bill.journal_entry.entry_number}</a> · {bill.journal_entry.status}</dd>
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
                {bill.items?.map((i, idx) => (
                  <tr key={i.id} className="h-8 border-b border-subtle">
                    <td className="px-2.5 text-muted font-mono tabular-nums">{String(idx + 1).padStart(2, '0')}</td>
                    <td className="px-2.5">{i.description}</td>
                    <td className="px-2.5 text-muted text-xs">
                      {i.expense_account ? <span><span className="font-mono">{i.expense_account.code}</span> · {i.expense_account.name}</span> : '—'}
                    </td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{i.quantity}{i.unit ? ` ${i.unit}` : ''}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(i.unit_price)}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums font-medium">{formatPeso(i.total)}</td>
                  </tr>
                ))}
                <tr className="h-7"><td colSpan={5} className="px-2.5 text-right text-muted">Subtotal</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(bill.subtotal)}</td></tr>
                {bill.is_vatable && <tr className="h-7"><td colSpan={5} className="px-2.5 text-right text-muted">VAT (12%)</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(bill.vat_amount)}</td></tr>}
                <tr className="h-8 border-t-2 border-primary font-medium"><td colSpan={5} className="px-2.5 text-right">Total</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(bill.total_amount)}</td></tr>
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
                  <div className="text-muted">{p.payment_method}{p.reference_number ? ` · ${p.reference_number}` : ''}</div>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <Modal isOpen={showPay} onClose={() => setShowPay(false)} title={`Record payment for ${bill.bill_number}`} size="sm">
        <form onSubmit={handleSubmit((d) => payMut.mutate(d))} className="space-y-3">
          <Select label="Cash account" required {...register('cash_account_id')} error={errors.cash_account_id?.message}>
            <option value="">— Select —</option>
            {cashAccts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
          </Select>
          <Input label="Payment date" type="date" required {...register('payment_date')} error={errors.payment_date?.message} />
          <Input label={`Amount (max ${formatPeso(bill.balance)})`} type="number" step="0.01" min="0.01" max={bill.balance}
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
            <Button type="button" variant="secondary" onClick={() => setShowPay(false)}>Cancel</Button>
            <Button type="submit" variant="primary" loading={payMut.isPending} disabled={payMut.isPending}>Record</Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
