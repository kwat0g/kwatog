import { useMemo } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { journalEntriesApi } from '@/api/accounting/journal-entries';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { FormActions } from '@/components/ui/FormActions';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { formatPeso } from '@/lib/formatNumber';
import { numberInputProps } from '@/lib/numberInput';
import { useFormSafety } from '@/hooks/useFormSafety';
import { fromCents, toCents } from './money';

const amountSchema = z.string()
 .regex(/^\d+(\.\d{1,2})?$/, 'Use an amount with up to two decimals.')
 .or(z.literal(''));

const lineSchema = z.object({
 account_id: z.string().min(1, 'Account is required'),
 debit: amountSchema,
 credit: amountSchema,
 description: z.string().max(200).optional().or(z.literal('')),
}).refine((line) => (toCents(line.debit) > 0n) !== (toCents(line.credit) > 0n), {
 message: 'Enter exactly one debit or credit amount',
 path: ['debit'],
});

const schema = z.object({
 date: z.string().min(1, 'Date is required'),
 description: z.string().min(1, 'Description is required').max(500),
 lines: z.array(lineSchema).min(2, 'At least two lines'),
});

type FormValues = z.infer<typeof schema>;

export default function EditJournalEntryPage() {
 const { id = '' } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();

 const { data, isLoading, isError, refetch } = useQuery({
  queryKey: ['accounting', 'journal-entries', id],
  queryFn: () => journalEntriesApi.show(id),
  enabled: !!id,
 });
 const { data: accountsResp } = useQuery({
  queryKey: ['accounting', 'accounts', 'flat-active'],
  queryFn: () => accountsApi.list({ per_page: 200, is_active: true }),
 });
 const accounts = accountsResp?.data ?? [];

 const form = useForm<FormValues>({
  resolver: zodResolver(schema),
  values: data ? {
   date: data.date.slice(0, 10),
   description: data.description,
   lines: (data.lines ?? []).map((line) => ({
    account_id: line.account?.id ?? '',
    debit: line.debit === '0.00' ? '' : line.debit,
    credit: line.credit === '0.00' ? '' : line.credit,
    description: line.description ?? '',
   })),
  } : undefined,
 });
 const { register, control, handleSubmit, setError, watch, formState: { errors } } = form;
 const { fields, append, remove } = useFieldArray({ control, name: 'lines' });
 const lines = watch('lines') ?? [];

 const totals = useMemo(() => {
  let debit = 0n;
  let credit = 0n;
  for (const line of lines) {
   debit += toCents(line.debit);
   credit += toCents(line.credit);
  }
  return {
   debit: fromCents(debit),
   credit: fromCents(credit),
   diff: fromCents(debit - credit),
   balanced: debit === credit,
  };
 }, [lines]);

 const mutation = useMutation({
  mutationFn: (values: FormValues) => journalEntriesApi.update(id, {
   date: values.date,
   description: values.description,
   lines: values.lines.map((line) => ({
    account_id: line.account_id,
    debit: line.debit || '0',
    credit: line.credit || '0',
    description: line.description || undefined,
   })),
  }),
  onSuccess: (entry) => {
   qc.invalidateQueries({ queryKey: ['accounting', 'journal-entries'] });
   toast.success(`Draft ${entry.entry_number} updated.`);
   navigate(`/accounting/journal-entries/${entry.id}`);
  },
  onError: (error) => applyServerValidationErrors(error, setError, 'Failed to update the journal entry.'),
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 if (isLoading) return <SkeletonDetail />;
 if (isError || !data) {
  return <EmptyState icon="alert-circle" title="Failed to load entry" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
 }
 if (data.status !== 'draft' || data.deleted_at) {
  return <EmptyState title="Only active drafts can be edited" action={<Button variant="secondary" onClick={() => navigate(`/accounting/journal-entries/${data.id}`)}>Back to entry</Button>} />;
 }

 return (
  <div>
   <PageHeader title={`Edit ${data.entry_number}`} backTo={`/accounting/journal-entries/${data.id}`} backLabel="Journal Entry" />
   <FormDraftBanner safety={safety} />
   <form onSubmit={handleSubmit((values) => mutation.mutate(values), onFormInvalid<FormValues>())} className="max-w-5xl mx-auto px-5 py-4 space-y-4">
    <Panel title="Header">
     <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
      <Textarea label="Description" required rows={2} className="col-span-2" {...register('description')} error={errors.description?.message} maxLength={500} />
     </div>
    </Panel>

    <Panel title="Lines">
     <div className="border border-default rounded-md overflow-hidden">
      <div className="hidden md:grid md:grid-cols-12 h-row px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
       <div className="col-span-4">Account</div>
       <div className="col-span-3">Description</div>
       <div className="col-span-2 text-right">Debit</div>
       <div className="col-span-2 text-right">Credit</div>
       <div className="col-span-1" />
      </div>
      {fields.map((field, index) => (
       <div key={field.id} className="grid grid-cols-1 md:grid-cols-12 gap-2 px-2.5 py-1.5 border-b border-subtle items-start">
        <div className="col-span-4">
         <Select required {...register(`lines.${index}.account_id` as const)} error={errors.lines?.[index]?.account_id?.message}>
          <option value="">— Select account —</option>
          {accounts.filter((account) => account.is_active).map((account) => (
           <option key={account.id} value={account.id}>{account.code} — {account.name}</option>
          ))}
         </Select>
        </div>
        <div className="col-span-3"><Input placeholder="Memo" {...register(`lines.${index}.description` as const)} /></div>
        <div className="col-span-2">
         <Input step="0.01" min="0" placeholder="0.00" className="font-mono tabular-nums text-right" {...numberInputProps()} {...register(`lines.${index}.debit` as const)} error={errors.lines?.[index]?.debit?.message} />
        </div>
        <div className="col-span-2">
         <Input step="0.01" min="0" placeholder="0.00" className="font-mono tabular-nums text-right" {...numberInputProps()} {...register(`lines.${index}.credit` as const)} error={errors.lines?.[index]?.credit?.message} />
        </div>
        <div className="col-span-1 flex justify-end pt-1">
         {fields.length > 2 && <Button type="button" variant="ghost" size="sm" onClick={() => remove(index)} className="text-muted hover:text-danger-fg">Remove</Button>}
        </div>
       </div>
      ))}
     </div>
     <div className="flex items-center justify-between mt-3">
      <Button type="button" variant="secondary" size="sm" onClick={() => append({ account_id: '', debit: '', credit: '', description: '' })}>Add line</Button>
      <div className="flex items-center gap-4 text-sm font-mono tabular-nums">
       <span>Debit: <span className="font-medium">{formatPeso(totals.debit)}</span></span>
       <span>Credit: <span className="font-medium">{formatPeso(totals.credit)}</span></span>
       <span className={totals.balanced ? 'text-success-fg font-medium' : 'text-danger-fg font-medium'}>Δ {formatPeso(totals.diff)}</span>
      </div>
     </div>
    </Panel>

    <FormActions>
     <Button type="button" variant="secondary" onClick={() => navigate(`/accounting/journal-entries/${data.id}`)}>Cancel</Button>
     <Button type="submit" variant="primary" disabled={!totals.balanced || mutation.isPending} loading={mutation.isPending}>{mutation.isPending ? 'Saving…' : 'Save draft'}</Button>
    </FormActions>
   </form>
  </div>
 );
}
