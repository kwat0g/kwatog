/** OGAMI-016 — calibration instrument create/edit form. */
import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { calibrationApi } from '@/api/quality/calibration';
import { Button } from '@/components/ui/Button';
import { FormActions } from '@/components/ui/FormActions';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { Select } from '@/components/ui/Select';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { useFormSafety } from '@/hooks/useFormSafety';

const today = () => new Date().toISOString().slice(0, 10);

// Mirrors StoreCalibrationRecordRequest + CalibrationService::assertDateOrder.
// ISO `YYYY-MM-DD` strings compare correctly lexicographically.
const schema = z.object({
 equipment_code: z.string().min(1, 'Equipment code is required').max(50),
 name: z.string().min(1, 'Name is required').max(150),
 location: z.string().max(100).optional().or(z.literal('')),
 last_calibration_date: z.string().optional().or(z.literal('')),
 next_calibration_date: z.string().optional().or(z.literal('')),
 frequency_days: z.coerce.number().int().min(1, 'Frequency must be at least 1 day').max(3650),
 status: z.enum(['active', 'retired']),
 responsible: z.string().max(100).optional().or(z.literal('')),
 remarks: z.string().max(2000).optional().or(z.literal('')),
})
 .refine((v) => !v.last_calibration_date || v.last_calibration_date <= today(), {
  path: ['last_calibration_date'],
  message: 'The last calibration date cannot be in the future.',
 })
 .refine(
  (v) => !v.last_calibration_date || !v.next_calibration_date || v.next_calibration_date >= v.last_calibration_date,
  {
   path: ['next_calibration_date'],
   message: 'The next calibration date cannot be earlier than the last calibration date.',
  },
 );

type FormValues = z.infer<typeof schema>;

const EMPTY_VALUES: FormValues = {
 equipment_code: '',
 name: '',
 location: '',
 last_calibration_date: '',
 next_calibration_date: '',
 frequency_days: 365,
 status: 'active',
 responsible: '',
 remarks: '',
};

export default function CalibrationFormPage() {
 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const { id } = useParams<{ id: string }>();
 const isEdit = Boolean(id);
 const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY_VALUES });
 const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = form;

 const detail = useQuery({
  queryKey: ['quality', 'calibration', 'detail', id],
  queryFn: () => calibrationApi.show(id as string),
  enabled: isEdit,
 });

 useEffect(() => {
  if (!detail.data) return;
  reset({
   equipment_code: detail.data.equipment_code,
   name: detail.data.name,
   location: detail.data.location ?? '',
   last_calibration_date: detail.data.last_calibration_date ?? '',
   next_calibration_date: detail.data.next_calibration_date ?? '',
   frequency_days: detail.data.frequency_days,
   status: detail.data.status === 'retired' ? 'retired' : 'active',
   responsible: detail.data.responsible ?? '',
   remarks: detail.data.remarks ?? '',
  });
 }, [detail.data, reset]);

 const mutation = useMutation({
  mutationFn: (values: FormValues) => {
   const payload = {
    ...values,
    location: values.location || undefined,
    last_calibration_date: values.last_calibration_date || undefined,
    next_calibration_date: values.next_calibration_date || undefined,
    responsible: values.responsible || undefined,
    remarks: values.remarks || undefined,
   };
   return isEdit ? calibrationApi.update(id as string, payload) : calibrationApi.create(payload);
  },
  onSuccess: (record) => {
   void queryClient.invalidateQueries({ queryKey: ['quality', 'calibration'] });
   toast.success(isEdit ? 'Calibration instrument updated' : 'Calibration instrument registered');
   navigate(`/quality/calibration/${record.id}/edit`);
  },
  onError: (error) => applyServerValidationErrors(error, setError, 'Could not save the calibration instrument.'),
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 if (isEdit && detail.isLoading) {
  return (
   <div>
    <PageHeader title="Edit calibration instrument" backTo="/quality/calibration" backLabel="Calibration register" />
    <div className="max-w-3xl mx-auto px-5 py-4">
     <SkeletonBlock className="h-72" />
    </div>
   </div>
  );
 }
 if (isEdit && detail.isError) {
  return (
   <div>
    <PageHeader title="Edit calibration instrument" backTo="/quality/calibration" backLabel="Calibration register" />
    <div className="max-w-3xl mx-auto px-5 py-4">
     <QueryErrorState subject="this calibration instrument" onRetry={() => void detail.refetch()} />
    </div>
   </div>
  );
 }

 return (
  <div>
   <PageHeader title={isEdit ? 'Edit calibration instrument' : 'New calibration instrument'} backTo="/quality/calibration" backLabel="Calibration register" />
   <FormDraftBanner safety={safety} />
   <form onSubmit={handleSubmit((values) => mutation.mutate(values), onFormInvalid<FormValues>())} className="max-w-3xl mx-auto px-5 py-4">
    <fieldset className="mb-6">
     <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Identification</legend>
     <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <Input label="Equipment code" {...register('equipment_code')} error={errors.equipment_code?.message} required />
      <Input label="Name" {...register('name')} error={errors.name?.message} required />
      <Input label="Location" {...register('location')} error={errors.location?.message} />
      <Input label="Responsible" {...register('responsible')} error={errors.responsible?.message} />
     </div>
    </fieldset>

    <fieldset className="mb-6">
     <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Calibration schedule</legend>
     <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <Input label="Last calibrated" type="date" max={today()} {...register('last_calibration_date')} error={errors.last_calibration_date?.message} />
      <Input label="Next due" type="date" {...register('next_calibration_date')} error={errors.next_calibration_date?.message} />
      <Input label="Frequency (days)" type="number" {...register('frequency_days')} error={errors.frequency_days?.message} required />
     </div>
     <div className="mt-3 max-w-xs">
      <Select label="Register status" {...register('status')} error={errors.status?.message}>
       <option value="active">Active</option>
       <option value="retired">Retired</option>
      </Select>
     </div>
    </fieldset>

    <fieldset className="mb-6">
     <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Notes</legend>
     <Textarea label="Remarks" {...register('remarks')} error={errors.remarks?.message} maxLength={2000} rows={4} />
    </fieldset>

    <FormActions>
     <Button type="button" variant="secondary" onClick={() => navigate('/quality/calibration')}>Cancel</Button>
     <Button type="submit" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending}>
      {mutation.isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Register instrument'}
     </Button>
    </FormActions>
   </form>
  </div>
 );
}
