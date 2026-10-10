/** Sprint 8 — Task 69. Create maintenance schedule. */
import { useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { schedulesApi } from '@/api/maintenance/schedules';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
const schema = z.object({
  maintainable_type: z.string().min(1, 'Target type required'),
  maintainable_id: z.string().min(1, 'Target ID required'),
 description: z.string().min(1).max(200),
 interval_type: z.string().min(1, 'Interval type required'),
 interval_value: z.coerce.number().int().min(1),
 estimated_duration_minutes: z.coerce.number().int().min(15).max(480),
 is_active: z.coerce.boolean().default(true),
});
type FormValues = z.infer<typeof schema>;

export default function CreateMaintenanceSchedulePage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { data: options } = useQuery({ queryKey: ['maintenance', 'schedule-options'], queryFn: () => schedulesApi.options() });
  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { maintainable_type: '', maintainable_id: '', interval_type: '', estimated_duration_minutes: 60, is_active: true },
 });
 const { register, handleSubmit, setError, watch, formState: { errors, isSubmitting } } = form;

 const maintainableType = watch('maintainable_type');
 const [targetSearch, setTargetSearch] = useState('');
 const targets = useQuery({
  queryKey: ['maintenance', 'schedule-targets', maintainableType, targetSearch],
  queryFn: () => schedulesApi.targets(maintainableType, targetSearch),
  enabled: !!maintainableType,
 });
 const targetOptions = (targets.data ?? []).map((target) => ({ value: target.id, label: `${target.code} - ${target.name}` }));

 const mutation = useMutation({
 mutationFn: (data: FormValues) => schedulesApi.create(data as Parameters<typeof schedulesApi.create>[0]),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
 toast.success('Schedule created.');
 navigate('/maintenance/schedules');
 },
 onError: (err) => {
   applyServerValidationErrors(err, setError, 'Failed to create the maintenance schedule.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader title="New maintenance schedule" backTo="/maintenance/schedules" backLabel="Schedules" />
      <FormDraftBanner safety={safety} />
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="min-w-0 w-full px-5 py-4">
 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Target</legend>
 <div className="grid grid-cols-2 gap-3">
 <Select label="Type" {...register('maintainable_type', { onChange: () => { form.setValue('maintainable_id', ''); setTargetSearch(''); } })} error={errors.maintainable_type?.message} required>
 <option value="">— Select —</option>
 {(options?.maintainable_types ?? []).map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
 </Select>
 <Select searchable onSearch={setTargetSearch} label="Target" {...register('maintainable_id')} error={errors.maintainable_id?.message} disabled={!maintainableType} required>
   <option value="">— Select Target —</option>
   {targetOptions.map((opt) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
 </Select>
 {targets.isError && <p role="alert" className="text-sm text-danger-fg">Targets could not load. <button type="button" className="underline" onClick={() => void targets.refetch()}>Retry</button></p>}
 </div>
 </fieldset>

 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Schedule</legend>
 <Input label="Description" {...register('description')} error={errors.description?.message} required />
 <div className="grid grid-cols-2 gap-3 mt-3">
 <Select label="Interval type" {...register('interval_type')} error={errors.interval_type?.message} required>
 <option value="">— Select —</option>
 {(options?.interval_types ?? []).map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
 </Select>
 <Input label="Interval value" type="number" {...register('interval_value')} error={errors.interval_value?.message} required />
 </div>
 <div className="mt-3">
 <Input label="Estimated duration (minutes)" type="number" min={15} max={480} {...register('estimated_duration_minutes')} error={errors.estimated_duration_minutes?.message} required />
 </div>
 </fieldset>

 <FormActions>
 <Button type="button" variant="secondary" onClick={() => navigate('/maintenance/schedules')}>Cancel</Button>
 <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
 {mutation.isPending ? 'Creating…' : 'Create schedule'}
 </Button>
 </FormActions>
 </form>
 </div>
 );
}
