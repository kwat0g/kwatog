import { FormProvider, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { crmCustomersApi } from '@/api/crm/customers';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';
import { CustomerForm } from './form';

const schema = z.object({
  name:               z.string().min(1, 'Required').max(200),
  code:               z.string().min(1, 'Required').max(50),
  contact_person:     z.string().max(100).optional().or(z.literal('')),
  email:              z.string().email('Invalid email').optional().or(z.literal('')),
  phone:              z.string().max(20).optional().or(z.literal('')),
  address:            z.string().max(500).optional().or(z.literal('')),
  credit_limit:       z.coerce.number().min(0).max(99999999.99).optional().or(z.literal('').transform(() => undefined)),
  payment_terms_days: z.coerce.number().int().min(0).max(365).default(30),
  is_active:          z.boolean().default(true),
});

type FormValues = z.infer<typeof schema>;

export default function CrmCustomerCreatePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const methods = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: '',
      code: '',
      contact_person: '',
      email: '',
      phone: '',
      address: '',
      payment_terms_days: 30,
      is_active: true,
    },
  });

  const { handleSubmit, setError, formState: { isSubmitting } } = methods;

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload = {
        ...values,
        code:           values.code || undefined,
        contact_person: values.contact_person || undefined,
        email:          values.email || undefined,
        phone:          values.phone || undefined,
        address:        values.address || undefined,
        credit_limit:   values.credit_limit != null ? String(values.credit_limit) : null,
      };
      return crmCustomersApi.create(payload);
    },
    onSuccess: (customer) => {
      qc.invalidateQueries({ queryKey: ['crm', 'customers'] });
      qc.invalidateQueries({ queryKey: ['accounting', 'customers'] });
      toast.success('Customer created.');
      navigate(`/crm/customers/${customer.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) =>
          setError(field as keyof FormValues, { type: 'server', message: (msgs as string[])[0] }),
        );
        toast.error(e.response?.data?.message ?? 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to create customer.');
      }
    },
  });

  return (
    <div>
      <PageHeader
        title="New customer"
        backTo="/crm/customers"
        backLabel="Customers"
        breadcrumbs={[
          { label: 'CRM' },
          { label: 'Customers', href: '/crm/customers' },
          { label: 'New customer' },
        ]}
      />
      <FormProvider {...methods}>
        <form onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}>
          <CustomerForm />
          <div className="max-w-3xl mx-auto px-5 pb-6 flex justify-end gap-2">
            <Button
              type="button"
              variant="secondary"
              onClick={() => navigate('/crm/customers')}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              loading={mutation.isPending}
              disabled={isSubmitting || mutation.isPending}
            >
              {mutation.isPending ? 'Creating…' : 'Create customer'}
            </Button>
          </div>
        </form>
      </FormProvider>
    </div>
  );
}
