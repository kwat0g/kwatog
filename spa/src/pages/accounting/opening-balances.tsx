import { useMemo, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Plus, Trash2, Scale, CheckCircle2, AlertCircle } from 'lucide-react';
import { accountsApi } from '@/api/accounting/accounts';
import { openingBalancesApi, type TbMatchResult } from '@/api/accounting/opening-balances';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const lineSchema = z.object({
 account_id: z.string().min(1, 'Account is required'),
 debit: z.coerce.number({ invalid_type_error: 'Number' }).min(0, 'Min 0'),
 credit: z.coerce.number({ invalid_type_error: 'Number' }).min(0, 'Min 0'),
}).refine((line) => line.debit > 0 || line.credit > 0, { message: 'Enter a debit or credit amount.', path: ['debit'] });

const schema = z.object({
 date: z.string().min(1, 'Date is required'),
 lines: z.array(lineSchema).min(2, 'At least two lines'),
});
type FormValues = z.infer<typeof schema>;

export default function OpeningBalancesPage() {
 const [match, setMatch] = useState<TbMatchResult | null>(null);

 const { data: accountsResp } = useQuery({
 queryKey: ['accounting', 'accounts', 'flat-active'],
 queryFn: () => accountsApi.list({ per_page: 500, is_active: true }),
 });
 const accounts = accountsResp?.data ?? [];

 const { register, control, handleSubmit, watch, formState: { errors } } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 date: new Date().toISOString().slice(0, 10),
 lines: [
 { account_id: '', debit: undefined as unknown as number, credit: undefined as unknown as number },
 { account_id: '', debit: undefined as unknown as number, credit: undefined as unknown as number },
 ],
 },
 });
 const { fields, append, remove } = useFieldArray({ control, name: 'lines' });
 const lines = watch('lines');
 const date = watch('date');

 const totals = useMemo(() => {
 let d = 0, c = 0;
 for (const l of lines) { d += Number(l.debit) || 0; c += Number(l.credit) || 0; }
 const diff = d - c;
 return { d: d.toFixed(2), c: c.toFixed(2), diff: diff.toFixed(2), balanced: Math.abs(diff) < 0.005 };
 }, [lines]);

 const payloadLines = () =>
 lines
 .filter((l) => l.account_id)
 .map((l) => ({ account_id: l.account_id, debit: String(l.debit), credit: String(l.credit) }));

 const postMut = useMutation({
 mutationFn: () => openingBalancesApi.postGl(date, payloadLines()),
 onSuccess: (r) => { toast.success(r.message ?? 'Opening balances posted.'); setMatch(null); },
 onError: (e: AxiosError<{ message?: string }>) =>
 toast.error(e.response?.data?.message ?? 'Failed to post opening balances.'),
 });

 const matchMut = useMutation({
 mutationFn: () => openingBalancesApi.tbMatch(payloadLines(), date),
 onSuccess: (r) => setMatch(r),
 onError: (e: AxiosError<{ message?: string }>) =>
 toast.error(e.response?.data?.message ?? 'Reconciliation failed.'),
 });

 return (
 <div>
 <PageHeader
 title="Opening Balances"
 subtitle="Load the legacy trial balance at go-live. An unbalanced TB is rejected — nothing is posted."
 backTo="/accounting/journal-entries"
 backLabel="Journal Entries"
 breadcrumbs={[{ label: 'Accounting' }, { label: 'Opening Balances' }]}
 />

 <form
 onSubmit={handleSubmit(() => postMut.mutate(), onFormInvalid<FormValues>())}
 className="max-w-5xl mx-auto px-5 py-4 space-y-4"
 >
 <Panel title="Header">
 <div className="grid grid-cols-3 gap-3">
 <Input label="As-of date" type="date" required {...register('date')} error={errors.date?.message} />
 </div>
 </Panel>

 <Panel title="Legacy trial balance">
 <div className="border border-default rounded-md overflow-hidden">
 <div className="grid grid-cols-12 h-8 px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
 <div className="col-span-7">Account</div>
 <div className="col-span-2 text-right">Debit</div>
 <div className="col-span-2 text-right">Credit</div>
 <div className="col-span-1" />
 </div>
 {fields.map((field, idx) => (
 <div key={field.id} className="grid grid-cols-12 gap-2 px-2.5 py-1.5 border-b border-subtle items-start">
 <div className="col-span-7">
 <Select required {...register(`lines.${idx}.account_id` as const)} error={errors.lines?.[idx]?.account_id?.message}>
 <option value="">— Select account —</option>
 {accounts.filter((a) => a.is_active).map((a) => (
 <option key={a.id} value={a.id}>{a.code} — {a.name}</option>
 ))}
 </Select>
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
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<Trash2 size={14} />}
 aria-label="Remove line"
 onClick={() => remove(idx)}
 className="text-muted hover:text-danger"
 />
 )}
 </div>
 </div>
 ))}
 </div>

 <div className="flex items-center justify-between mt-3">
 <Button type="button" variant="secondary" size="sm" icon={<Plus size={14} />} onClick={() => append({ account_id: '', debit: undefined as unknown as number, credit: undefined as unknown as number })}>
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

 {!totals.balanced && (
 <div className="mt-3 flex items-center gap-2 px-3 py-2 rounded-md bg-warning-bg text-warning-fg text-xs">
 <AlertCircle size={14} />
 Debits and credits must match before posting. The server will also reject an unbalanced TB.
 </div>
 )}
 </Panel>

 <div className="flex justify-end gap-2 pt-2">
 <Button type="button" variant="secondary" icon={<Scale size={14} />}
 onClick={() => matchMut.mutate()}
 disabled={matchMut.isPending} loading={matchMut.isPending}>
 Reconcile vs system
 </Button>
 <Button type="submit" variant="primary"
 disabled={!totals.balanced || postMut.isPending} loading={postMut.isPending}>
 {postMut.isPending ? 'Posting…' : 'Post opening balances'}
 </Button>
 </div>
 </form>

 {match && (
 <div className="max-w-5xl mx-auto px-5 pb-8">
 <Panel title="Reconciliation vs system trial balance">
 <div className={`flex items-center gap-2 px-3 py-2 mb-3 rounded-md text-xs ${match.balanced ? 'bg-success-bg text-success-fg' : 'bg-warning-bg text-warning-fg'}`}>
 {match.balanced ? <CheckCircle2 size={14} /> : <AlertCircle size={14} />}
 {match.balanced
 ? 'Balanced — the system trial balance equals the legacy TB.'
 : 'Variances found — the system does not yet match the legacy TB.'}
 </div>
 <div className="border border-default rounded-md overflow-hidden">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Account</Th>
 <Th align="right">Legacy Dr</Th>
 <Th align="right">Legacy Cr</Th>
 <Th align="right">System Dr</Th>
 <Th align="right">System Cr</Th>
 <Th align="right">Variance</Th>
 </tr>
 </thead>
 <tbody>
 {match.rows.map((r, i) => {
 const v = Number(r.variance);
 return (
 <tr key={i} className={trCls}>
 <Td><span className="font-mono text-xs text-muted">{r.account_code}</span> {r.account_name}</Td>
 <Td align="right" mono>{formatPeso(r.legacy_debit)}</Td>
 <Td align="right" mono>{formatPeso(r.legacy_credit)}</Td>
 <Td align="right" mono>{formatPeso(r.system_debit)}</Td>
 <Td align="right" mono>{formatPeso(r.system_credit)}</Td>
 <Td align="right" mono className={Math.abs(v) < 0.005 ? 'text-muted' : 'text-danger-fg font-medium'}>
 {formatPeso(r.variance)}
 </Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </div>
 </Panel>
 </div>
 )}
 </div>
 );
}
