import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { crmCustomersApi } from '@/api/crm/customers';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import type { Customer, CreateCustomerData } from '@/types/accounting';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  name:               z.string().min(1, 'Required').max(200),
  contact_person:     z.string().max(100).optional().or(z.literal('')),
  email:              z.string().email('Invalid email').optional().or(z.literal('')),
  phone:              z.string().max(20).optional().or(z.literal('')),
  address:            z.string().max(500).optional().or(z.literal('')),
  credit_limit:       z.coerce.number().min(0).max(99999999.99).optional().or(z.literal('').transform(() => undefined)),
  payment_terms_days: z.coerce.number().int().min(0).max(365).default(30),
  is_active:          z.boolean().default(true),
});

type FormValues = z.infer<typeof schema>;

interface Props {
  mode: 'create' | 'edit';
  initial?: Customer;
}

export function CustomerForm({ mode, initial }: Props) {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { register, handleSubmit, setError, setValue, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name:               initial?.name ?? '',
      contact_person:     initial?.contact_person ?? '',
      email:              initial?.email ?? '',
      phone:              initial?.phone ?? '',
      address:            initial?.address ?? '',
      credit_limit:       initial?.credit_limit ? Number(initial.credit_limit) : undefined,
      payment_terms_days: initial?.payment_terms_days ?? 30,
      is_active:          initial?.is_active ?? true,
    },
  });

  useEffect(() => {
    if (initial) {
      setValue('name',               initial.name);
      setValue('contact_person',     initial.contact_person ?? '');
      setValue('email',              initial.email ?? '');
      setValue('phone',              initial.phone ?? '');
      setValue('address',            initial.address ?? '');
      setValue('credit_limit',       initial.credit_limit ? Number(initial.credit_limit) : undefined);
      setValue('payment_terms_days', initial.payment_terms_days);
      setValue('is_active',          initial.is_active);
    }
  }, [initial, setValue]);

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreateCustomerData = {
        ...values,
        contact_person:     values.contact_person || undefined,
        email:              values.email || undefined,
        phone:              values.phone || undefined,
        address:            values.address || undefined,
        credit_limit:       values.credit_limit != null ? String(values.credit_limit) : null,
      };
      return mode === 'create'
        ? crmCustomersApi.create(payload)
        : crmCustomersApi.update(initial!.id, payload);
    },
    onSuccess: (customer) => {
      qc.invalidateQueries({ queryKey: ['crm', 'customers'] });
      qc.invalidateQueries({ queryKey: ['accounting', 'customers'] });
      toast.success(mode === 'create' ? 'Customer created.' : 'Customer updated.');
      navigate(`/crm/customers/${customer.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) =>
          setError(field as keyof FormValues, { type: 'server', message: (msgs as string[])[0] }),
        );
        toast.error(e.response?.data?.message ?? 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save customer.');
      }
    },
  });

  return (
    <form
      onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}
      className="max-w-3xl mx-auto px-5 py-6 space-y-4"
    >
      <Panel title="Identity">
        <div className="grid grid-cols-2 gap-3">
          <div className="col-span-2">
            <Input
              label="Customer name"
              required
              {...register('name')}
              error={errors.name?.message}
              placeholder="Ogami Corporation"
            />
          </div>
          <Input
            label="Contact person"
            {...register('contact_person')}
            error={errors.contact_person?.message}
            placeholder="Tanaka Hiroshi"
          />
          <Input
            label="Phone"
            {...register('phone')}
            error={errors.phone?.message}
            placeholder="+63 2 8888 0000"
          />
          <Input
            label="Email"
            type="email"
            {...register('email')}
            error={errors.email?.message}
            placeholder="purchasing@example.com"
          />
          <Input
            label="Payment terms (days)"
            type="number"
            min={0}
            max={365}
            className="font-mono tabular-nums text-right"
            {...numberInputProps({ decimal: false })}
            {...register('payment_terms_days')}
            error={errors.payment_terms_days?.message}
          />
          <div className="col-span-2">
            <Textarea
              label="Address"
              rows={2}
              {...register('address')}
              error={errors.address?.message}
              placeholder="Macapagal Blvd, Pasay City, Metro Manila"
            />
          </div>
          <Input
            label="Credit limit"
            type="number"
            step="0.01"
            min="0"
            prefix="₱"
            className="font-mono tabular-nums text-right"
            {...numberInputProps()}
            {...register('credit_limit')}
            error={errors.credit_limit?.message}
          />
        </div>
        <div className="mt-3">
          <Switch label="Active — visible when creating sales orders" {...register('is_active')} />
        </div>
      </Panel>

      <div className="flex justify-end gap-2 pt-2">
        <Button
          type="button"
          variant="secondary"
          onClick={() => navigate(initial ? `/crm/customers/${initial.id}` : '/crm/customers')}
        >
          Cancel
        </Button>
        <Button
          type="submit"
          variant="primary"
          loading={mutation.isPending}
          disabled={isSubmitting || mutation.isPending}
        >
          {mutation.isPending
            ? mode === 'create' ? 'Creating…' : 'Saving…'
            : mode === 'create' ? 'Create customer' : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}
