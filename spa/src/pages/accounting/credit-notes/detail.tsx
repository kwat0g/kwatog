import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { CheckCircle2, Link2 } from 'lucide-react';
import { creditNotesApi } from '@/api/accounting/credit-notes';
import { invoicesApi } from '@/api/accounting/invoices';
import { billsApi } from '@/api/accounting/bills';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import type { ChipVariant } from '@/components/ui/Chip';
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
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';
import type { CreditNoteStatus } from '@/types/accounting';

const CN_STATUS_CHIP: Record<CreditNoteStatus, ChipVariant> = {
  draft: 'neutral',
  finalized: 'info',
  applied: 'success',
  void: 'danger',
};

const applySchema = z.object({
  amount:      z.coerce.number().positive('> 0'),
  document_id: z.string().min(1, 'Select a document'),
});
type ApplyFormValues = z.infer<typeof applySchema>;

export default function CreditNoteDetailPage() {
  const { id = '' } = useParams();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [applyOpen, setApplyOpen] = useState(false);

  const { data: cn, isLoading, isError } = useQuery({
    queryKey: ['accounting', 'credit-notes', id],
    queryFn: () => creditNotesApi.show(id),
  });

  const isCustomer = cn?.type === 'customer';

  // Open documents to apply against (only load when the modal is relevant).
  const { data: openDocs } = useQuery({
    queryKey: ['accounting', 'credit-notes', id, 'open-docs', cn?.type, cn?.customer?.id, cn?.vendor?.id],
    queryFn: async () => {
      if (isCustomer) {
        const res = await invoicesApi.list({ customer_id: cn?.customer?.id, per_page: 100 });
        return res.data.filter((i) => ['finalized', 'partial'].includes(i.status)).map((i) => ({ id: i.id, label: `${i.invoice_number} · bal ${formatPeso(i.balance)}`, balance: i.balance }));
      }
      const res = await billsApi.list({ vendor_id: cn?.vendor?.id, per_page: 100 });
      return res.data.filter((b) => ['unpaid', 'partial'].includes(b.status)).map((b) => ({ id: b.id, label: `${b.bill_number} · bal ${formatPeso(b.balance)}`, balance: b.balance }));
    },
    enabled: applyOpen && !!cn,
  });

  const finalize = useMutation({
    mutationFn: () => creditNotesApi.finalize(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['accounting', 'credit-notes'] });
      toast.success('Credit note finalized and posted to the GL.');
    },
    onError: (e: AxiosError<ApiValidationError>) => toast.error(e.response?.data?.message ?? 'Failed to finalize.'),
  });

  const { register, handleSubmit, reset, formState: { errors } } = useForm<ApplyFormValues>({
    resolver: zodResolver(applySchema),
  });

  const apply = useMutation({
    mutationFn: (v: ApplyFormValues) => creditNotesApi.apply(id, {
      amount: String(v.amount),
      ...(isCustomer ? { invoice_id: v.document_id } : { bill_id: v.document_id }),
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['accounting', 'credit-notes'] });
      setApplyOpen(false);
      reset();
      toast.success('Credit applied.');
    },
    onError: (e: AxiosError<ApiValidationError>) => toast.error(e.response?.data?.message ?? 'Failed to apply credit.'),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !cn) return <div className="px-5 py-4 text-danger-fg">Credit note not found.</div>;

  const canManage = can('accounting.credit_notes.manage');

  return (
    <div>
      <PageHeader
        title={cn.credit_note_number ?? 'Draft credit note'}
        backTo="/accounting/credit-notes"
        backLabel="Credit Notes"
        subtitle={<Chip variant={CN_STATUS_CHIP[cn.status]}>{cn.status_label}</Chip>}
        actions={
          <div className="flex gap-1.5">
            {canManage && cn.status === 'draft' && (
              <Button variant="primary" size="sm" icon={<CheckCircle2 size={14} />} loading={finalize.isPending} onClick={() => finalize.mutate()}>
                Finalize + post
              </Button>
            )}
            {canManage && cn.status === 'finalized' && Number(cn.balance) > 0 && (
              <Button variant="primary" size="sm" icon={<Link2 size={14} />} onClick={() => setApplyOpen(true)}>
                Apply to {isCustomer ? 'invoice' : 'bill'}
              </Button>
            )}
          </div>
        }
      />

      <div className="max-w-4xl mx-auto px-5 py-4 space-y-4">
        <div className="grid grid-cols-4 gap-3">
          <StatCard label="Type" value={cn.type_label} />
          <StatCard label="Total" value={formatPeso(cn.total_amount)} />
          <StatCard label="Applied" value={formatPeso(cn.applied_amount)} />
          <StatCard label="Balance" value={formatPeso(cn.balance)} />
        </div>

        <Panel title="Details">
          <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-muted">Date</dt><dd>{formatDate(cn.date)}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">{isCustomer ? 'Customer' : 'Vendor'}</dt><dd>{isCustomer ? cn.customer?.name : cn.vendor?.name ?? '—'}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">Source {isCustomer ? 'invoice' : 'bill'}</dt><dd>{isCustomer ? cn.invoice?.invoice_number : cn.bill?.bill_number ?? '—'}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">VAT</dt><dd>{formatPeso(cn.vat_amount)}</dd></div>
            {cn.reason && <div className="col-span-2"><dt className="text-muted">Reason</dt><dd className="mt-0.5">{cn.reason}</dd></div>}
          </dl>
        </Panel>

        <Panel title="Lines">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-default text-2xs uppercase tracking-wider text-muted">
              <th className="text-left h-8 px-2.5">Description</th><th className="text-right px-2.5">Amount</th>
            </tr></thead>
            <tbody>
              {(cn.lines ?? []).map((l) => (
                <tr key={l.id} className="border-b border-subtle">
                  <td className="px-2.5 py-1.5">{l.description}</td>
                  <td className="px-2.5 py-1.5 text-right font-mono tabular-nums">{formatPeso(l.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>

        {(cn.applications?.length ?? 0) > 0 && (
          <Panel title="Applications">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-default text-2xs uppercase tracking-wider text-muted">
                <th className="text-left h-8 px-2.5">Applied to</th><th className="text-right px-2.5">Amount</th><th className="text-right px-2.5">Date</th>
              </tr></thead>
              <tbody>
                {cn.applications!.map((a) => (
                  <tr key={a.id} className="border-b border-subtle">
                    <td className="px-2.5 py-1.5 font-mono">{a.invoice_id ?? a.bill_id ?? '—'}</td>
                    <td className="px-2.5 py-1.5 text-right font-mono tabular-nums">{formatPeso(a.amount)}</td>
                    <td className="px-2.5 py-1.5 text-right">{a.created_at ? formatDate(a.created_at) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
        )}
      </div>

      <Modal isOpen={applyOpen} onClose={() => setApplyOpen(false)} title={`Apply credit to ${isCustomer ? 'invoice' : 'bill'}`}>
        <form onSubmit={handleSubmit((v) => apply.mutate(v), onFormInvalid<ApplyFormValues>())} className="space-y-3">
          <Select label={isCustomer ? 'Open invoice' : 'Open bill'} required {...register('document_id')} error={errors.document_id?.message}>
            <option value="">— Select —</option>
            {(openDocs ?? []).map((d) => <option key={d.id} value={d.id}>{d.label}</option>)}
          </Select>
          <Input label="Amount" type="number" step="0.01" min="0.01" required
            className="font-mono tabular-nums text-right" {...numberInputProps()}
            {...register('amount')} error={errors.amount?.message}
            helper={`Credit balance available: ${formatPeso(cn.balance)}`} />
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => setApplyOpen(false)}>Cancel</Button>
            <Button type="submit" variant="primary" loading={apply.isPending}>Apply</Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
