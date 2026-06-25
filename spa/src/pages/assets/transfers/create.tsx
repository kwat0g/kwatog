/** Create asset transfer form. */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { assetTransfersApi } from '@/api/assets';
import { departmentsApi } from '@/api/hr/departments';
import { assetsApi } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';
import type { CreateTransferData } from '@/types/assets';

const schema = z.object({
  asset_id: z.string().min(1, 'Asset is required'),
  from_department_id: z.string().min(1, 'From department is required'),
  to_department_id: z.string().min(1, 'To department is required'),
  reason: z.string().max(2000).optional().or(z.literal('')),
  transfer_date: z.string().min(1, 'Transfer date is required'),
}).refine((d) => d.from_department_id !== d.to_department_id, {
  message: 'Destination must differ from source department',
  path: ['to_department_id'],
});
type FormValues = z.infer<typeof schema>;

export default function CreateAssetTransferPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { transfer_date: new Date().toISOString().split('T')[0] },
  });

  const { data: deptData, isLoading: deptLoading } = useQuery({
    queryKey: ['hr', 'departments', 'list'],
    queryFn: () => departmentsApi.list({ per_page: 200 }),
    staleTime: 300_000,
  });

  const { data: assetsData, isLoading: assetsLoading } = useQuery({
    queryKey: ['assets', { per_page: 500 }],
    queryFn: () => assetsApi.list({ per_page: 500, status: 'active' }),
    staleTime: 300_000,
  });

  const mutation = useMutation({
    mutationFn: (data: CreateTransferData) => assetTransfersApi.create(data),
    onSuccess: (transfer) => {
      qc.invalidateQueries({ queryKey: ['asset-transfers'] });
      toast.success(`Transfer ${transfer.transfer_number} created.`);
      navigate('/assets/transfers');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to create transfer.');
      }
    },
  });

  const onSubmit = (data: FormValues) => {
    mutation.mutate({
      asset_id: data.asset_id,
      from_department_id: data.from_department_id,
      to_department_id: data.to_department_id,
      reason: data.reason || undefined,
      transfer_date: data.transfer_date,
    });
  };

  return (
    <div>
      <PageHeader title="New asset transfer" backTo="/assets/transfers" backLabel="Transfers" />
      <form onSubmit={handleSubmit(onSubmit, onFormInvalid<FormValues>())} className="max-w-3xl mx-auto px-5 py-6">
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Transfer details</legend>
          <Select label="Asset" {...register('asset_id')} error={errors.asset_id?.message} required disabled={assetsLoading}>
            <option value="">{assetsLoading ? 'Loading...' : '-- Select asset --'}</option>
            {assetsData?.data?.map((a) => (
              <option key={a.id} value={a.id}>{a.asset_code} - {a.name}</option>
            ))}
          </Select>
          <div className="grid grid-cols-2 gap-3 mt-3">
            <Select label="From department" {...register('from_department_id')} error={errors.from_department_id?.message} required disabled={deptLoading}>
              <option value="">{deptLoading ? 'Loading...' : '-- Select --'}</option>
              {deptData?.data?.map((d) => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </Select>
            <Select label="To department" {...register('to_department_id')} error={errors.to_department_id?.message} required disabled={deptLoading}>
              <option value="">{deptLoading ? 'Loading...' : '-- Select --'}</option>
              {deptData?.data?.map((d) => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </Select>
          </div>
          <div className="mt-3">
            <Input label="Transfer date" type="date" {...register('transfer_date')} error={errors.transfer_date?.message} required />
          </div>
          <div className="mt-3">
            <Textarea label="Reason" {...register('reason')} rows={3} error={errors.reason?.message} placeholder="Why is this asset being transferred?" />
          </div>
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/assets/transfers')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating...' : 'Create transfer'}
          </Button>
        </div>
      </form>
    </div>
  );
}
