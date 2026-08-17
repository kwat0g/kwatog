/** Sprint 8 — Task 69. Create maintenance schedule. */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { schedulesApi } from '@/api/maintenance/schedules';
import { machinesApi } from '@/api/mrp/machines';
import { moldsApi } from '@/api/mrp/molds';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
const schema = z.object({
  maintainable_type: z.string().min(1, 'Target type required'),
  maintainable_id: z.string().min(1, 'Target ID required'),
 description: z.string().min(1).max(200),
 interval_type: z.string().min(1, 'Interval type required'),
 interval_value: z.coerce.number().int().min(1),
 is_active: z.coerce.boolean().default(true),
});
type FormValues = z.infer<typeof schema>;

export default function CreateMaintenanceSchedulePage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { data: options } = useQuery({ queryKey: ['maintenance', 'schedule-options'], queryFn: () => schedulesApi.options() });
  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { maintainable_type: '', maintainable_id: '', interval_type: '', is_active: true },
 });
 const { register, handleSubmit, setError, watch, formState: { errors, isSubmitting } } = form;

 const maintainableType = watch('maintainable_type');
 const machines = useQuery({ queryKey: ['mrp', 'machines'], queryFn: () => machinesApi.list({ per_page: 200 }), enabled: maintainableType === 'machine' });
 const molds = useQuery({ queryKey: ['mrp', 'molds'], queryFn: () => moldsApi.list({ per_page: 200 }), enabled: maintainableType === 'mold' });

 const targetOptions = maintainableType === 'machine'
   ? (machines.data?.data ?? []).map((m) => ({ value: m.id, label: `${m.machine_code} - ${m.name}` }))
   : maintainableType === 'mold'
     ? (molds.data?.data ?? []).map((m) => ({ value: m.id, label: `${m.mold_code} - ${m.name}` }))
     : [];

 const mutation = useMutation({
 mutationFn: (data: FormValues) => schedulesApi.create(data as Parameters<typeof schedulesApi.create>[0]),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
 toast.success('Schedule created.');
 navigate('/maintenance/schedules');
 },
 onError: (err) => {
   applyServerValidationErrors(err, setError, 'Failed to save. Please try again.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader title="New maintenance schedule" backTo="/maintenance/schedules" backLabel="Schedules" />
      <FormDraftBanner safety={safety} />
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-2xl mx-auto px-5 py-4">
 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Target</legend>
 <div className="grid grid-cols-2 gap-3">
 <Select label="Type" {...register('maintainable_type')} error={errors.maintainable_type?.message} required>
 <option value="">— Select —</option>
 {(options?.maintainable_types ?? []).map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
 </Select>
 <Select label="Target" {...register('maintainable_id')} error={errors.maintainable_id?.message} disabled={!maintainableType} required>
   <option value="">— Select Target —</option>
   {targetOptions.map((opt) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
 </Select>
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
 </fieldset>

 <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
 <Button type="button" variant="secondary" onClick={() => navigate('/maintenance/schedules')}>Cancel</Button>
 <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
 {mutation.isPending ? 'Creating…' : 'Create schedule'}
 </Button>
 </div>
 </form>
 </div>
 );
}
