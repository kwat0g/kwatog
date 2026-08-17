/**
 * Machine create/edit form. Mirrors StoreMachineRequest/UpdateMachineRequest.
 * Status is never submitted — the model defaults to idle and changes flow
 * through the transition-status endpoint.
 */
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { machinesApi } from '@/api/mrp/machines';
import type { Machine, CreateMachineData, UpdateMachineData } from '@/types/mrp';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
const schema = z.object({
  machine_code: z
    .string()
    .regex(/^[A-Z0-9-]{2,20}$/, 'Use 2–20 uppercase letters, digits, or hyphens.'),
  name: z.string().min(1, 'Name is required').max(100),
  tonnage: z
    .string()
    .regex(/^\d+$/, 'Whole number of tons, or leave blank')
    .optional()
    .or(z.literal('')),
  machine_type: z.string().max(50).optional().or(z.literal('')),
  operators_required: z
    .string()
    .regex(/^\d+(\.\d)?$/, 'Use a number with up to 1 decimal')
    .optional()
    .or(z.literal('')),
  available_hours_per_day: z
    .string()
    .regex(/^\d+(\.\d)?$/, 'Use 0–24 with up to 1 decimal')
    .refine((v) => v === '' || Number(v) <= 24, 'Max 24 hours')
    .optional()
    .or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

interface Props {
  initial?: Machine;
  mode: 'create' | 'edit';
}

export function MachineForm({ initial, mode }: Props) {
  const navigate = useNavigate();
  const qc = useQueryClient();

    const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      machine_code: initial?.machine_code ?? '',
      name: initial?.name ?? '',
      tonnage: initial?.tonnage != null ? String(initial.tonnage) : '',
      machine_type: initial?.machine_type ?? '',
      operators_required:
        initial?.operators_required != null ? String(initial.operators_required) : '',
      available_hours_per_day:
        initial?.available_hours_per_day != null ? String(initial.available_hours_per_day) : '',
    },
  });
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = form;

  // `reset` rather than field-by-field `setValue`: one render instead of six,
  // and it re-baselines the defaults so a hydrated record doesn't leave the
  // form looking dirty before the user has touched it.
  useEffect(() => {
    if (initial) {
      reset({
        machine_code: initial.machine_code,
        name: initial.name,
        tonnage: initial.tonnage != null ? String(initial.tonnage) : '',
        machine_type: initial.machine_type ?? '',
        operators_required:
          initial.operators_required != null ? String(initial.operators_required) : '',
        available_hours_per_day:
          initial.available_hours_per_day != null ? String(initial.available_hours_per_day) : '',
      });
    }
  }, [initial, reset]);

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreateMachineData | UpdateMachineData = {
        machine_code: values.machine_code,
        name: values.name,
        tonnage: values.tonnage ? Number(values.tonnage) : null,
        machine_type: values.machine_type?.trim() ? values.machine_type : null,
        operators_required: values.operators_required || null,
        available_hours_per_day: values.available_hours_per_day || null,
      };
      return mode === 'create'
        ? machinesApi.create(payload as CreateMachineData)
        : machinesApi.update(initial!.id, payload);
    },
    onSuccess: (machine) => {
      qc.invalidateQueries({ queryKey: ['mrp', 'machines'] });
      toast.success(mode === 'create' ? 'Machine created.' : 'Machine updated.');
      navigate(`/mrp/machines/${machine.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as keyof FormValues, { type: 'server', message: msgs[0] });
        });
        toast.error(e.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save machine.');
      }
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <form
      onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}
      className="max-w-3xl mx-auto px-5 py-4"
    >
      <FormDraftBanner safety={safety} inset={false} />
      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
          Identification
        </legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Machine Code"
            required
            {...register('machine_code')}
            error={errors.machine_code?.message}
            placeholder="e.g. INJ-01"
            className="font-mono"
          />
          <Input
            label="Machine Name"
            required
            {...register('name')}
            error={errors.name?.message}
            placeholder="e.g. Injection Press 1"
          />
        </div>
      </fieldset>

      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
          Specifications
        </legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Tonnage (T)"
            {...register('tonnage')}
            error={errors.tonnage?.message}
            placeholder="e.g. 250"
            className="font-mono"
          />
          <Input
            label="Machine Type"
            {...register('machine_type')}
            error={errors.machine_type?.message}
            placeholder="e.g. Horizontal"
          />
          <Input
            label="Operators Required"
            {...register('operators_required')}
            error={errors.operators_required?.message}
            placeholder="e.g. 1"
            className="font-mono"
          />
          <Input
            label="Available Hours / Day"
            {...register('available_hours_per_day')}
            error={errors.available_hours_per_day?.message}
            placeholder="e.g. 20"
            className="font-mono"
          />
        </div>
      </fieldset>

      <div className="flex items-center justify-end gap-2">
        <Button type="button" variant="secondary" onClick={() => navigate(-1)}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending}>
          {isSubmitting || mutation.isPending
            ? 'Saving…'
            : mode === 'create'
              ? 'Create machine'
              : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}
