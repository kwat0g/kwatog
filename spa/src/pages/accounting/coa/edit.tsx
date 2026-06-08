import { useNavigate, useParams } from 'react-router-dom';
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
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  name:        z.string().min(1, 'Name required').max(100),
  description: z.string().max(500).optional().or(z.literal('')),
  is_active:   z.coerce.boolean(),
});
type FormValues = z.infer<typeof schema>;

export default function EditAccountPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: account, isLoading } = useQuery({
    queryKey: ['accounting', 'accounts', id],
    queryFn: () => accountsApi.show(id),
    enabled: !!id,
  });

  const { register, handleSubmit, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    values: account ? {
      name: account.name,
      description: account.description ?? '',
      is_active: account.is_active,
    } : undefined,
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => accountsApi.update(id, {
      name: data.name,
      description: data.description || undefined,
      is_active: data.is_active,
    }),
    onSuccess: (account) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'accounts'] });
      toast.success(`Account ${account.code} updated.`);
      navigate('/accounting/coa');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to update account.');
      }
    },
  });

  if (isLoading || !account) return <SkeletonDetail />;

  return (
    <div>
      <PageHeader title={`Edit ${account.code}`} backTo="/accounting/coa" backLabel="Chart of Accounts"
        breadcrumbs={[{ label: 'COA', href: '/accounting/coa' }, { label: account.code }]} />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-2xl mx-auto px-5 py-6 space-y-4">

        <div className="grid grid-cols-2 gap-3">
          <div>
            <p className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Code</p>
            <p className="font-mono text-sm">{account.code}</p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Type</p>
            <p className="text-sm capitalize">{account.type}</p>
          </div>
        </div>

        <Input label="Account name" {...register('name')} error={errors.name?.message} required />

        <Textarea label="Description (optional)" {...register('description')} rows={2}
          error={errors.description?.message} />

        <Select label="Status" {...register('is_active')} error={errors.is_active?.message}>
          <option value="true">Active</option>
          <option value="false">Inactive</option>
        </Select>

        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/coa')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>Save changes</Button>
        </div>
      </form>
    </div>
  );
}
