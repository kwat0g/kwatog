/**
 * Reusable Product create/edit form. Used by create.tsx and edit.tsx wrappers.
 * Server-side validation errors land on the matching field; sane Zod schema
 * mirrors the StoreProductRequest rules.
 */
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { productsApi } from '@/api/crm/products';
import type { Product, CreateProductData, UpdateProductData } from '@/types/crm';

const schema = z.object({
  part_number:     z.string().regex(/^[A-Z0-9-]{2,30}$/, 'Use 2–30 uppercase letters, digits, or hyphens.'),
  name:            z.string().min(1, 'Name is required').max(200),
  description:     z.string().max(1000).optional().or(z.literal('')),
  unit_of_measure: z.string().min(1, 'UOM is required').max(20),
  standard_cost:   z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use a non-negative decimal with up to 2 places'),
  is_active:       z.boolean().optional(),
});

type FormValues = z.infer<typeof schema>;

interface Props {
  initial?: Product;
  mode: 'create' | 'edit';
}

export function ProductForm({ initial, mode }: Props) {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const {
    register, handleSubmit, setError, watch, setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      part_number:     initial?.part_number ?? '',
      name:            initial?.name ?? '',
      description:     initial?.description ?? '',
      unit_of_measure: initial?.unit_of_measure ?? 'pcs',
      standard_cost:   initial?.standard_cost ?? '0.00',
      is_active:       initial?.is_active ?? true,
    },
  });

  // Re-sync defaults if initial changes (e.g. edit page after fetch).
  useEffect(() => {
    if (initial) {
      setValue('part_number',     initial.part_number);
      setValue('name',            initial.name);
      setValue('description',     initial.description ?? '');
      setValue('unit_of_measure', initial.unit_of_measure);
      setValue('standard_cost',   initial.standard_cost);
      setValue('is_active',       initial.is_active);
    }
  }, [initial, setValue]);

  const isActive = watch('is_active');
  const { ref: isActiveRef, ...isActiveRegister } = register('is_active');
  void isActiveRef;

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreateProductData | UpdateProductData = {
        ...values,
        description: values.description?.trim() ? values.description : null,
      };
      return mode === 'create'
        ? productsApi.create(payload as CreateProductData)
        : productsApi.update(initial!.id, payload);
    },
    onSuccess: (product) => {
      qc.invalidateQueries({ queryKey: ['crm', 'products'] });
      toast.success(mode === 'create' ? 'Product created.' : 'Product updated.');
      navigate(`/crm/products/${product.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as keyof FormValues, { type: 'server', message: msgs[0] });
        });
        toast.error('Please fix the errors below.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save product.');
      }
    },
  });

  return (
    <form
      onSubmit={handleSubmit((v) => mutation.mutate(v))}
      className="max-w-3xl mx-auto px-5 py-6"
    >
      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Identification</legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Part Number"
            required
            {...register('part_number')}
            error={errors.part_number?.message}
            placeholder="e.g. WB-001"
            className="font-mono"
          />
          <Input
            label="Unit of Measure"
            required
            {...register('unit_of_measure')}
            error={errors.unit_of_measure?.message}
            placeholder="pcs"
          />
          <div className="col-span-2">
            <Input
              label="Name"
              required
              {...register('name')}
              error={errors.name?.message}
              placeholder="Wiper Bushing (Standard)"
            />
          </div>
          <div className="col-span-2">
            <Textarea
              label="Description"
              rows={3}
              {...register('description')}
              error={errors.description?.message}
              placeholder="Optional notes for production / sales reference."
            />
          </div>
        </div>
      </fieldset>

      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Costing</legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Standard Cost"
            required
            prefix="₱"
            {...register('standard_cost')}
            error={errors.standard_cost?.message}
            placeholder="0.00"
            className="font-mono"
          />
        </div>
        <p className="mt-2 text-xs text-muted">
          Internal accounting figure. Customer pricing is set per Price Agreement (Sales → Customers → Price Agreements).
        </p>
      </fieldset>

      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Status</legend>
        <Switch
          {...isActiveRegister}
          checked={!!isActive}
          label="Active — visible to CRM officers when creating sales orders"
        />
      </fieldset>

      <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
        <Button type="button" variant="secondary" onClick={() => navigate('/crm/products')}>
          Cancel
        </Button>
        <Button
          type="submit"
          variant="primary"
          disabled={isSubmitting || mutation.isPending}
          loading={mutation.isPending}
        >
          {mutation.isPending
            ? mode === 'create' ? 'Creating…' : 'Saving…'
            : mode === 'create' ? 'Create product' : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}
