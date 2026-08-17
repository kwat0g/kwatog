import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { accountsApi } from '@/api/accounting/accounts';
import { accountingOptionsApi } from '@/api/accounting/options';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import type { AccountType } from '@/types/accounting';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
const schema = z.object({
 code: z.string().min(1, 'Code required').max(20),
 name: z.string().min(1, 'Name required').max(100),
 type: z.string().min(1, 'Type required'),
 normal_balance: z.string().optional(),
 parent_id: z.string().optional().or(z.literal('')),
 description: z.string().max(500).optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function CreateAccountPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();

 const { data: accounts } = useQuery({
 queryKey: ['accounting', 'accounts', 'list'],
 queryFn: () => accountsApi.list({ per_page: 200 }),
 staleTime: 60_000,
 });
 const { data: options } = useQuery({
 queryKey: ['accounting', 'options'],
 queryFn: () => accountingOptionsApi.list(),
 staleTime: 300_000,
 });

  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { type: '', normal_balance: '' },
 });
 const { register, handleSubmit, setValue, setError, formState: { errors } } = form;

 const mutation = useMutation({
 mutationFn: (data: FormValues) => accountsApi.create({
 code: data.code,
 name: data.name,
 type: data.type as AccountType,
 normal_balance: (data.normal_balance || options?.account_types.find((t) => t.value === data.type)?.default_normal_balance) as 'debit' | 'credit' | undefined,
 parent_id: data.parent_id || null,
 description: data.description || undefined,
 }),
 onSuccess: (account) => {
 qc.invalidateQueries({ queryKey: ['accounting', 'accounts'] });
 toast.success(`Account ${account.code} created.`);
 navigate('/accounting/coa');
 },
 onError: (err) => {
   applyServerValidationErrors(err, setError, 'Failed to create account.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader title="New account" backTo="/accounting/coa" backLabel="Chart of Accounts"
 />
      <FormDraftBanner safety={safety} />
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
 className="max-w-2xl mx-auto px-5 py-4 space-y-4">

 <div className="grid grid-cols-2 gap-3">
 <Input label="Account code" {...register('code')} error={errors.code?.message} required
 placeholder="Account code" className="font-mono" />
 <Select label="Type" {...register('type', {
 onChange: (e) => setValue('normal_balance', options?.account_types.find((t) => t.value === e.target.value)?.default_normal_balance || ''),
 })} error={errors.type?.message} required>
 <option value="">Select type</option>
 {(options?.account_types ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 </div>

 <Input label="Account name" {...register('name')} error={errors.name?.message} required
 placeholder="Account name" />

 <div className="grid grid-cols-2 gap-3">
 <Select label="Normal balance" {...register('normal_balance')} error={errors.normal_balance?.message}>
 <option value="">Select balance</option>
 {(options?.normal_balances ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 <Select label="Parent account (optional)" {...register('parent_id')} error={errors.parent_id?.message}>
 <option value="">— None (top-level) —</option>
 {accounts?.data
 .filter((a) => !a.is_leaf)
 .map((a) => (
 <option key={a.id} value={a.id}>{a.code} — {a.name}</option>
 ))}
 </Select>
 </div>

 <Textarea label="Description (optional)" {...register('description')} rows={2}
 error={errors.description?.message} />

 <FormActions>
 <Button type="button" variant="secondary" onClick={() => navigate('/accounting/coa')}>Cancel</Button>
 <Button type="submit" variant="primary" loading={mutation.isPending}>Create account</Button>
 </FormActions>
 </form>
 </div>
 );
}
