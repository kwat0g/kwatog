/** Sprint 8 — Task 70. Create asset form. */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { assetsApi } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  name: z.string().min(1, 'Name is required').max(200),
  description: z.string().max(5000).optional().or(z.literal('')),
  category: z.enum(['machine', 'mold', 'vehicle', 'equipment', 'furniture', 'other']),
  acquisition_date: z.string().min(1),
  acquisition_cost: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Enter amount like 100000.00'),
  useful_life_years: z.coerce.number().int().min(1).max(100),
  salvage_value: z.string().regex(/^\d+(\.\d{1,2})?$/).optional().or(z.literal('')),
  location: z.string().max(100).optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function CreateAssetPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { category: 'equipment', useful_life_years: 5, salvage_value: '0' },
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => assetsApi.create({
      ...data,
      description: data.description || undefined,
      salvage_value: data.salvage_value || '0',
      location: data.location || undefined,
    }),
    onSuccess: (asset) => {
      qc.invalidateQueries({ queryKey: ['assets'] });
      toast.success(`Asset ${asset.asset_code} created.`);
      navigate(`/assets/${asset.id}`);
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error('Please fix the errors below.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="New asset" backTo="/assets" backLabel="Assets" />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="max-w-3xl mx-auto px-5 py-6">
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Identification</legend>
          <Input label="Name" {...register('name')} error={errors.name?.message} required />
          <div className="grid grid-cols-2 gap-3 mt-3">
            <Select label="Category" {...register('category')} error={errors.category?.message} required>
              <option value="machine">Machine</option>
              <option value="mold">Mold</option>
              <option value="vehicle">Vehicle</option>
              <option value="equipment">Equipment</option>
              <option value="furniture">Furniture</option>
              <option value="other">Other</option>
            </Select>
            <Input label="Location" {...register('location')} error={errors.location?.message} placeholder="e.g. Production floor" />
          </div>
          <div className="mt-3">
            <Textarea label="Description" {...register('description')} rows={3} error={errors.description?.message} />
          </div>
        </fieldset>

        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Acquisition</legend>
          <div className="grid grid-cols-3 gap-3">
            <Input label="Acquisition date" type="date" {...register('acquisition_date')} error={errors.acquisition_date?.message} required />
            <Input label="Acquisition cost (₱)" {...register('acquisition_cost')} error={errors.acquisition_cost?.message}
              className="font-mono" placeholder="0.00" required />
            <Input label="Useful life (years)" type="number" {...register('useful_life_years')} error={errors.useful_life_years?.message} required />
          </div>
          <div className="mt-3 max-w-xs">
            <Input label="Salvage value (₱)" {...register('salvage_value')} error={errors.salvage_value?.message}
              className="font-mono" placeholder="0.00" />
          </div>
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/assets')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating…' : 'Create asset'}
          </Button>
        </div>
      </form>
    </div>
  );
}
