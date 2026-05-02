import { useNavigate, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { vendorsApi } from '@/api/accounting/vendors';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  name:               z.string().min(1, 'Name is required').max(200),
  contact_person:     z.string().max(100).optional().or(z.literal('')),
  email:              z.string().email('Invalid email').optional().or(z.literal('')),
  phone:              z.string().max(20).optional().or(z.literal('')),
  address:            z.string().max(500).optional().or(z.literal('')),
  tin:                z.string().max(20).optional().or(z.literal('')),
  payment_terms_days: z.coerce.number().int().min(0).max(365).default(30),
  is_active:          z.boolean().default(true),
});
type FormValues = z.infer<typeof schema>;

export default function VendorFormPage({ mode }: { mode: 'create' | 'edit' }) {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { id = '' } = useParams<{ id: string }>();

  const { data: existing } = useQuery({
    queryKey: ['accounting', 'vendors', id],
    queryFn: () => vendorsApi.show(id),
    enabled: mode === 'edit' && !!id,
  });

  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: existing ? {
      name: existing.name, contact_person: existing.contact_person ?? '', email: existing.email ?? '',
      phone: existing.phone ?? '', address: existing.address ?? '', tin: existing.tin ?? '',
      payment_terms_days: existing.payment_terms_days, is_active: existing.is_active,
    } : { payment_terms_days: 30, is_active: true } as FormValues,
    values: existing ? {
      name: existing.name, contact_person: existing.contact_person ?? '', email: existing.email ?? '',
      phone: existing.phone ?? '', address: existing.address ?? '', tin: existing.tin ?? '',
      payment_terms_days: existing.payment_terms_days, is_active: existing.is_active,
    } : undefined,
  });

  const mutation = useMutation({
    mutationFn: (d: FormValues) => mode === 'create' ? vendorsApi.create(d) : vendorsApi.update(id, d),
    onSuccess: (v) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'vendors'] });
      toast.success(mode === 'create' ? 'Vendor created.' : 'Vendor updated.');
      navigate(`/accounting/vendors/${v.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) => setError(f as keyof FormValues, { type: 'server', message: (msgs as string[])[0] }));
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save vendor.');
      }
    },
  });

  return (
    <div>
      <PageHeader title={mode === 'create' ? 'New vendor' : `Edit ${existing?.name ?? 'vendor'}`} backTo="/accounting/vendors" backLabel="Vendors" />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-3xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Identity">
          <div className="grid grid-cols-2 gap-3">
            <Input label="Vendor name" required {...register('name')} error={errors.name?.message} />
            <Input label="Contact person" {...register('contact_person')} error={errors.contact_person?.message} />
            <Input label="Email" type="email" {...register('email')} error={errors.email?.message} />
            <Input label="Phone" {...register('phone')} error={errors.phone?.message} />
            <Textarea label="Address" rows={2} className="col-span-2" {...register('address')} error={errors.address?.message} />
            <Input label="TIN" {...register('tin')} error={errors.tin?.message} />
            <Input label="Payment terms (days)" type="number" min={0} max={365} className="font-mono tabular-nums text-right"
              {...register('payment_terms_days')} error={errors.payment_terms_days?.message} />
          </div>
          <div className="mt-3">
            <Switch label="Active" {...register('is_active')} />
          </div>
        </Panel>
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/vendors')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending}>
            {mode === 'create' ? 'Create vendor' : 'Save changes'}
          </Button>
        </div>
      </form>
    </div>
  );
}
