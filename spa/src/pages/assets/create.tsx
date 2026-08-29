/** Fixed asset registration form. */
import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { assetsApi } from '@/api/assets';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
import type { AssetCategory, DepreciationMethod } from '@/types/assets';
import { SALVAGE_OVER_COST_MESSAGE, salvageExceedsCost } from './salvageBound';

const money = z.string().regex(/^\d+(\.\d{1,2})?$/, 'Enter an amount with up to 2 decimals.');

const schema = z.object({
  name: z.string().min(1, 'Name is required').max(200),
  description: z.string().max(5000).optional().or(z.literal('')),
  category: z.string().min(1, 'Category is required'),
  department_id: z.string().optional().or(z.literal('')),
  acquisition_date: z.string().min(1, 'Acquisition date required'),
  acquisition_cost: money,
  useful_life_years: z.coerce.number().int().min(1).max(100),
  depreciation_method: z.string().min(1, 'Depreciation method is required'),
  salvage_value: money.optional().or(z.literal('')),
  location: z.string().max(100).optional().or(z.literal('')),
  insurance_policy_no: z.string().max(100).optional().or(z.literal('')),
  insurance_provider: z.string().max(150).optional().or(z.literal('')),
  insurance_expiry: z.string().optional().or(z.literal('')),
  insured_value: money.optional().or(z.literal('')),
}).superRefine((values, ctx) => {
  if (salvageExceedsCost(values.salvage_value, values.acquisition_cost)) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['salvage_value'],
      message: SALVAGE_OVER_COST_MESSAGE,
    });
  }
});
type FormValues = z.infer<typeof schema>;

export default function CreateAssetPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      category: '',
      department_id: '',
      depreciation_method: 'straight_line',
      salvage_value: '',
      insurance_policy_no: '',
      insurance_provider: '',
      insurance_expiry: '',
      insured_value: '',
    },
  });
  const { register, handleSubmit, setError, watch, setValue, formState: { errors, isSubmitting } } = form;

  const { data: deptData, isLoading: deptLoading } = useQuery({
    queryKey: ['hr', 'departments', 'list'],
    queryFn: () => departmentsApi.list({ per_page: 200 }),
    staleTime: 300_000,
  });
  const { data: assetOptions } = useQuery({
    queryKey: ['assets', 'options'],
    queryFn: assetsApi.options,
  });

  useEffect(() => {
    if (!watch('category') && assetOptions?.categories?.length) {
      setValue('category', assetOptions.categories[0].value);
    }
  }, [assetOptions, setValue, watch]);

  const mutation = useMutation({
    mutationFn: (data: FormValues) => assetsApi.create({
      name: data.name,
      description: data.description || undefined,
      category: data.category as AssetCategory,
      department_id: data.department_id || null,
      acquisition_date: data.acquisition_date,
      acquisition_cost: data.acquisition_cost,
      useful_life_years: data.useful_life_years,
      depreciation_method: data.depreciation_method as DepreciationMethod,
      salvage_value: data.salvage_value || undefined,
      location: data.location || undefined,
      insurance_policy_no: data.insurance_policy_no || undefined,
      insurance_provider: data.insurance_provider || undefined,
      insurance_expiry: data.insurance_expiry || undefined,
      insured_value: data.insured_value || undefined,
    }),
    onSuccess: (asset) => {
      qc.invalidateQueries({ queryKey: ['assets'] });
      toast.success(`Asset ${asset.asset_code} created.`);
      navigate(`/assets/${asset.id}`);
    },
    onError: (error) => {
      applyServerValidationErrors(error, setError, 'Failed to create the asset.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <div>
      <PageHeader title="New asset" backTo="/assets" backLabel="Assets" />
      <FormDraftBanner safety={safety} />
      <form onSubmit={handleSubmit((data) => mutation.mutate(data), onFormInvalid<FormValues>())} className="max-w-3xl mx-auto px-5 py-4">
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Identification</legend>
          <Input label="Name" {...register('name')} error={errors.name?.message} required />
          <div className="grid grid-cols-2 gap-3 mt-3">
            <Select label="Category" {...register('category')} error={errors.category?.message} required>
              {(assetOptions?.categories ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </Select>
            <Select label="Department" {...register('department_id')} error={errors.department_id?.message} disabled={deptLoading}>
              <option value="">{deptLoading ? 'Loading…' : '— None —'}</option>
              {deptData?.data?.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
            </Select>
          </div>
          <div className="mt-3">
            <Input label="Location" {...register('location')} error={errors.location?.message} placeholder="Asset location" />
          </div>
          <div className="mt-3">
            <Textarea label="Description" {...register('description')} rows={3} error={errors.description?.message} />
          </div>
        </fieldset>

        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Acquisition & depreciation</legend>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <Input label="Acquisition date" type="date" {...register('acquisition_date')} error={errors.acquisition_date?.message} required />
            <Input label="Acquisition cost" {...register('acquisition_cost')} error={errors.acquisition_cost?.message} className="font-mono" placeholder="0.00" required />
            <Input label="Useful life (years)" type="number" {...register('useful_life_years')} error={errors.useful_life_years?.message} required />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <Select label="Depreciation method" {...register('depreciation_method')} error={errors.depreciation_method?.message} required>
              <option value="straight_line">Straight Line</option>
              <option value="declining_balance">Declining Balance (200%)</option>
            </Select>
            <Input label="Salvage value" {...register('salvage_value')} error={errors.salvage_value?.message} className="font-mono" placeholder="0.00" />
          </div>
        </fieldset>

        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Insurance (optional)</legend>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input label="Policy number" {...register('insurance_policy_no')} error={errors.insurance_policy_no?.message} />
            <Input label="Provider" {...register('insurance_provider')} error={errors.insurance_provider?.message} />
            <Input label="Expiry" type="date" {...register('insurance_expiry')} error={errors.insurance_expiry?.message} />
            <Input label="Insured value" {...register('insured_value')} error={errors.insured_value?.message} className="font-mono" placeholder="0.00" />
          </div>
        </fieldset>

        <FormActions>
          <Button type="button" variant="secondary" onClick={() => navigate('/assets')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating…' : 'Create asset'}
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
