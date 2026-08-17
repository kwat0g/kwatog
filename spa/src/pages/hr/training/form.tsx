import { Switch } from '@/components/ui/Switch';
import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { trainingsApi } from '@/api/hr/trainings';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import type { ApiValidationError } from '@/types';
import type { CreateTrainingData } from '@/types/hr';
import { onFormInvalid } from '@/lib/formErrors';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
const schema = z.object({
 name: z.string().min(1, 'Name is required').max(200),
 description: z.string().max(1000).optional().or(z.literal('')),
 duration_hours: z.coerce.number().min(0).optional().or(z.literal('')),
 validity_months: z.coerce.number().min(0).optional().or(z.literal('')),
 is_certification: z.boolean().optional(),
 department_id: z.string().optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

/**
 * The optional numeric fields accept '' so the inputs can be cleared, but the
 * API takes `number | undefined`. Drop the blanks rather than posting ''.
 */
const toPayload = (d: FormValues): CreateTrainingData => ({
 name: d.name,
 description: d.description === '' ? undefined : d.description,
 duration_hours: d.duration_hours === '' ? undefined : d.duration_hours,
 validity_months: d.validity_months === '' ? undefined : d.validity_months,
 is_certification: d.is_certification,
 department_id: d.department_id === '' ? undefined : d.department_id,
});

export default function TrainingFormPage() {
 const { id } = useParams<{ id: string }>();
 const isEdit = !!id;
 const navigate = useNavigate();
 const qc = useQueryClient();

 const { data: depts = [] } = useQuery({
 queryKey: ['hr', 'departments', 'tree'],
 queryFn: () => departmentsApi.tree(),
 });

 const { data: training, isLoading: loadingTraining } = useQuery({
 queryKey: ['hr', 'training', id],
 queryFn: () => trainingsApi.show(id!),
 enabled: isEdit,
 });

  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { is_certification: false },
 });
 const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = form;

 useEffect(() => {
 if (training) {
 reset({
 name: training.name,
 description: training.description ?? '',
 duration_hours: training.duration_hours ?? '',
 validity_months: training.validity_months ?? '',
 is_certification: training.is_certification,
 department_id: training.department?.id ?? '',
 });
 }
 }, [training, reset]);

 const mutation = useMutation({
 mutationFn: (d: FormValues) =>
 isEdit ? trainingsApi.update(id!, toPayload(d)) : trainingsApi.create(toPayload(d)),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['hr', 'trainings'] });
 toast.success(isEdit ? 'Training updated.' : 'Training created.');
 navigate('/hr/trainings');
 },
 onError: (e: AxiosError<ApiValidationError>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
 toast.error(`${field}: ${msgs[0]}`);
 });
 } else toast.error('Failed to save training.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 if (isEdit && loadingTraining) return <SkeletonForm />;
 if (isEdit && !loadingTraining && !training) return <EmptyState icon="alert-circle" title="Training not found" />;

 return (
 <div>
 <PageHeader title={isEdit ? 'Edit training' : 'Add training'} backTo="/hr/trainings" backLabel="Trainings" />
      <FormDraftBanner safety={safety} />
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid())} className="max-w-2xl mx-auto px-5 py-4">
 <Panel title="Training details">
 <div className="space-y-3">
 <Input label="Name" required {...register('name')} error={errors.name?.message} />
 <Textarea label="Description" {...register('description')} error={errors.description?.message} rows={3} />
 <div className="grid grid-cols-2 gap-3">
 <Input label="Duration (hours)" type="number" min="0" {...register('duration_hours')} error={errors.duration_hours?.message} />
 <Input label="Validity (months)" type="number" min="0" {...register('validity_months')} error={errors.validity_months?.message} />
 </div>
 <Select label="Department" {...register('department_id')} error={errors.department_id?.message}>
 <option value="">— All departments —</option>
 {depts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
 </Select>
 <Switch label="Is certification" {...register('is_certification')} />
 </div>
 </Panel>
 <FormActions>
 <Button type="button" variant="secondary" onClick={() => navigate('/hr/trainings')}>Cancel</Button>
 <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
 {isEdit ? 'Save changes' : 'Create training'}
 </Button>
 </FormActions>
 </form>
 </div>
 );
}