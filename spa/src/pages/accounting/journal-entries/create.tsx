import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Plus, X } from 'lucide-react';
import { accountsApi } from '@/api/accounting/accounts';
import { journalEntriesApi } from '@/api/accounting/journal-entries';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import type { ApiValidationError } from '@/types';

const lineSchema = z.object({
  account_id: z.string().min(1, 'Account is required'),
  debit:  z.coerce.number({ invalid_type_error: 'Number' }).min(0, 'Min 0').default(0),
  credit: z.coerce.number({ invalid_type_error: 'Number' }).min(0, 'Min 0').default(0),
  description: z.string().max(200).optional().or(z.literal('')),
});

const schema = z.object({
  date:        z.string().min(1, 'Date is required'),
  description: z.string().min(1, 'Description is required').max(500),
  lines:       z.array(lineSchema).min(2, 'At least two lines'),
});

type FormValues = z.infer<typeof schema>;

export default function CreateJournalEntryPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: accountsResp } = useQuery({
    queryKey: ['accounting', 'accounts', 'flat-active'],
    queryFn: () => accountsApi.list({ per_page: 200, is_active: true }),
  });
  const accounts = accountsResp?.data ?? [];

  const { register, control, handleSubmit, watch, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      date: new Date().toISOString().slice(0, 10),
      description: '',
      lines: [
        { account_id: '', debit: 0, credit: 0, description: '' },
        { account_id: '', debit: 0, credit: 0, description: '' },
      ],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'lines' });
  const lines = watch('lines');

  const totals = useMemo(() => {
    let d = 0, c = 0;
    for (const l of lines) {
      d += Number(l.debit) || 0;
      c += Number(l.credit) || 0;
    }
    const diff = d - c;
    return { d: d.toFixed(2), c: c.toFixed(2), diff: diff.toFixed(2), balanced: Math.abs(diff) < 0.005 };
  }, [lines]);

  const mutation = useMutation({
    mutationFn: (d: FormValues) => journalEntriesApi.create({
      date: d.date,
      description: d.description,
      lines: d.lines.map((l) => ({
        account_id: l.account_id,
        debit:  String(l.debit  ?? 0),
        credit: String(l.credit ?? 0),
        description: l.description || undefined,
      })),
    }),
    onSuccess: (je) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'journal-entries'] });
      toast.success(`Draft ${je.entry_number} created.`);
      navigate(`/accounting/journal-entries/${je.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
          setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
        );
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to create entry.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="New journal entry" backTo="/accounting/journal-entries" backLabel="Journal Entries" />

      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-5xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Header">
          <div className="grid grid-cols-3 gap-3">
            <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
            <Textarea label="Description" required rows={2} className="col-span-2" {...register('description')} error={errors.description?.message} maxLength={500} />
          </div>
        </Panel>

        <Panel title="Lines">
          <div className="border border-default rounded-md overflow-hidden">
            <div className="grid grid-cols-12 h-8 px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
              <div className="col-span-4">Account</div>
              <div className="col-span-3">Description</div>
              <div className="col-span-2 text-right">Debit</div>
              <div className="col-span-2 text-right">Credit</div>
              <div className="col-span-1" />
            </div>
            {fields.map((field, idx) => (
              <div key={field.id} className="grid grid-cols-12 gap-2 px-2.5 py-1.5 border-b border-subtle items-start">
                <div className="col-span-4">
                  <Select required {...register(`lines.${idx}.account_id` as const)} error={errors.lines?.[idx]?.account_id?.message}>
                    <option value="">— Select account —</option>
                    {accounts.filter((a) => a.is_active).map((a) => (
                      <option key={a.id} value={a.id}>{a.code} — {a.name}</option>
                    ))}
                  </Select>
                </div>
                <div className="col-span-3">
                  <Input placeholder="Memo" {...register(`lines.${idx}.description` as const)} />
                </div>
                <div className="col-span-2">
                  <Input type="number" step="0.01" min="0" placeholder="0.00"
                    className="font-mono tabular-nums text-right"
                    {...numberInputProps()}
                    {...register(`lines.${idx}.debit` as const)} error={errors.lines?.[idx]?.debit?.message} />
                </div>
                <div className="col-span-2">
                  <Input type="number" step="0.01" min="0" placeholder="0.00"
                    className="font-mono tabular-nums text-right"
                    {...numberInputProps()}
                    {...register(`lines.${idx}.credit` as const)} error={errors.lines?.[idx]?.credit?.message} />
                </div>
                <div className="col-span-1 flex justify-end pt-1">
                  {fields.length > 2 && (
                    <button type="button" className="text-muted hover:text-danger-fg" onClick={() => remove(idx)}>
                      <X size={14} />
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>

          <div className="flex items-center justify-between mt-3">
            <Button type="button" variant="secondary" size="sm" icon={<Plus size={14} />} onClick={() => append({ account_id: '', debit: 0, credit: 0, description: '' })}>
              Add line
            </Button>
            <div className="flex items-center gap-4 text-sm font-mono tabular-nums">
              <span>Debit: <span className="font-medium">{formatPeso(totals.d)}</span></span>
              <span>Credit: <span className="font-medium">{formatPeso(totals.c)}</span></span>
              <span className={totals.balanced ? 'text-success-fg font-medium' : 'text-danger-fg font-medium'}>
                Δ {formatPeso(totals.diff)}
              </span>
            </div>
          </div>
        </Panel>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/journal-entries')}>Cancel</Button>
          <Button type="submit" variant="primary"
            disabled={!totals.balanced || isSubmitting || mutation.isPending}
            loading={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Save draft'}
          </Button>
        </div>
      </form>
    </div>
  );
}
