import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';
import type { AccountType } from '@/types/accounting';

const schema = z.object({
  code:           z.string().min(1, 'Code required').max(20),
  name:           z.string().min(1, 'Name required').max(100),
  type:           z.enum(['asset', 'liability', 'equity', 'revenue', 'expense']),
  normal_balance: z.enum(['debit', 'credit']).optional(),
  parent_id:      z.string().optional().or(z.literal('')),
  description:    z.string().max(500).optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

const TYPE_DEFAULTS: Record<AccountType, 'debit' | 'credit'> = {
  asset: 'debit', expense: 'debit',
  liability: 'credit', equity: 'credit', revenue: 'credit',
};

export default function CreateAccountPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: accounts } = useQuery({
    queryKey: ['accounting', 'accounts', 'list'],
    queryFn: () => accountsApi.list({ per_page: 200 }),
    staleTime: 60_000,
  });

  const { register, handleSubmit, watch, setValue, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { type: 'asset', normal_balance: 'debit' },
  });

  const watchType = watch('type') as AccountType | undefined;

  const mutation = useMutation({
    mutationFn: (data: FormValues) => accountsApi.create({
      code: data.code,
      name: data.name,
      type: data.type,
      normal_balance: data.normal_balance || TYPE_DEFAULTS[data.type as AccountType],
      parent_id: data.parent_id || null,
      description: data.description || undefined,
    }),
    onSuccess: (account) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'accounts'] });
      toast.success(`Account ${account.code} created.`);
      navigate('/accounting/coa');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to create account.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="New account" backTo="/accounting/coa" backLabel="Chart of Accounts"
        breadcrumbs={[{ label: 'Finance', href: '/accounting/coa' }, { label: 'COA', href: '/accounting/coa' }, { label: 'New' }]} />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-2xl mx-auto px-5 py-6 space-y-4">

        <div className="grid grid-cols-2 gap-3">
          <Input label="Account code" {...register('code')} error={errors.code?.message} required
            placeholder="e.g. 1010" className="font-mono" />
          <Select label="Type" {...register('type', {
            onChange: (e) => setValue('normal_balance', TYPE_DEFAULTS[e.target.value as AccountType]),
          })} error={errors.type?.message} required>
            <option value="asset">Asset</option>
            <option value="liability">Liability</option>
            <option value="equity">Equity</option>
            <option value="revenue">Revenue</option>
            <option value="expense">Expense</option>
          </Select>
        </div>

        <Input label="Account name" {...register('name')} error={errors.name?.message} required
          placeholder="e.g. Cash on Hand" />

        <div className="grid grid-cols-2 gap-3">
          <Select label="Normal balance" {...register('normal_balance')} error={errors.normal_balance?.message}>
            <option value="debit">Debit</option>
            <option value="credit">Credit</option>
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

        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/coa')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>Create account</Button>
        </div>
      </form>
    </div>
  );
}
