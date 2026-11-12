import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { vendorsApi } from '@/api/accounting/vendors';
import { portalAccessApi } from '@/api/b2b/portal-access';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Checkbox } from '@/components/ui/Checkbox';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
const schema = z.object({
  name: z.string().min(1, 'Name is required').max(200),
  contact_person: z.string().max(100).optional().or(z.literal('')),
  email: z.string().email('Invalid email').optional().or(z.literal('')),
  phone: z.string().max(20).optional().or(z.literal('')),
  address: z.string().max(500).optional().or(z.literal('')),
  tin: z.string().max(20).optional().or(z.literal('')),
  withholding_tax_type: z
    .enum(['none', 'goods', 'services', 'rentals', 'professional'])
    .default('none'),
  payment_terms_days: z.coerce.number().int().min(0).max(365),
  is_active: z.boolean().default(true),
});
type FormValues = z.infer<typeof schema>;

export default function VendorFormPage({ mode }: { mode: 'create' | 'edit' }) {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { id = '' } = useParams<{ id: string }>();
  const [inviteToPortal, setInviteToPortal] = useState(false);
  const [authorizedSignatory, setAuthorizedSignatory] = useState(false);

  const { data: existing } = useQuery({
    queryKey: ['accounting', 'vendors', id],
    queryFn: () => vendorsApi.show(id),
    enabled: mode === 'edit' && !!id,
  });
  const { data: policies } = useQuery({
    queryKey: ['business-policies'],
    queryFn: businessPoliciesApi.get,
  });

  const form = useForm<z.input<typeof schema>, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: existing
      ? {
          name: existing.name,
          contact_person: existing.contact_person ?? '',
          email: existing.email ?? '',
          phone: existing.phone ?? '',
          address: existing.address ?? '',
          tin: existing.tin ?? '',
          withholding_tax_type: existing.withholding_tax_type ?? 'none',
          payment_terms_days: existing.payment_terms_days,
          is_active: existing.is_active,
        }
      : ({ is_active: true, withholding_tax_type: 'none' } as FormValues),
    values: existing
      ? {
          name: existing.name,
          contact_person: existing.contact_person ?? '',
          email: existing.email ?? '',
          phone: existing.phone ?? '',
          address: existing.address ?? '',
          tin: existing.tin ?? '',
          withholding_tax_type: existing.withholding_tax_type ?? 'none',
          payment_terms_days: existing.payment_terms_days,
          is_active: existing.is_active,
        }
      : undefined,
  });
  const {
    register,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = form;
  const vendorActive = form.watch('is_active');
  const contactPerson = form.watch('contact_person');
  const email = form.watch('email');
  const portalInviteReady = Boolean(vendorActive && contactPerson?.trim() && email?.trim());

  useEffect(() => {
    if (mode === 'create' && policies) {
      setValue('payment_terms_days', policies.vendor_payment_terms_days);
    }
  }, [mode, policies, setValue]);

  const mutation = useMutation({
    mutationFn: async ({ values, sendPortalInvitation, isAuthorizedSignatory }: {
      values: FormValues;
      sendPortalInvitation: boolean;
      isAuthorizedSignatory: boolean;
    }) => {
      const vendor = mode === 'create' ? await vendorsApi.create(values) : await vendorsApi.update(id, values);
      if (mode !== 'create' || !sendPortalInvitation) return { vendor, invitation: 'not_requested' as const };
      const name = vendor.contact_person?.trim();
      const vendorEmail = vendor.email?.trim();
      if (!vendor.is_active || !name || !vendorEmail) return { vendor, invitation: 'failed' as const };
      try {
        await portalAccessApi.inviteSupplier(vendor.id, {
          name,
          email: vendorEmail,
          is_authorized_signatory: isAuthorizedSignatory,
        });
        return { vendor, invitation: 'queued' as const };
      } catch {
        return { vendor, invitation: 'failed' as const };
      }
    },
    onSuccess: ({ vendor, invitation }) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'vendors'] });
      if (invitation === 'queued') void qc.invalidateQueries({ queryKey: ['b2b', 'portal-access'] });
      if (invitation === 'failed') {
        toast.error('Vendor created, but the invitation failed. Retry from Portal Access.');
      } else if (invitation === 'queued') {
        toast.success('Vendor created. Portal invitation queued.');
      } else {
        toast.success(mode === 'create' ? 'Vendor created.' : 'Vendor updated.');
      }
            safety.allowNavigationAfterSave();
      navigate(`/accounting/vendors/${vendor.id}`);
    },
    onError: (e) => {
      applyServerValidationErrors(e, setError, 'Failed to save the vendor.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });
  const submit = handleSubmit((values) => {
    const sendPortalInvitation = mode === 'create'
      && inviteToPortal
      && values.is_active
      && Boolean(values.contact_person?.trim())
      && Boolean(values.email?.trim());
    if (sendPortalInvitation) {
      if (!values.contact_person?.trim()) {
        setError('contact_person', { type: 'manual', message: 'Enter the contact person before inviting.' });
      }
      if (!values.email?.trim()) {
        setError('email', { type: 'manual', message: 'Enter an email address before inviting.' });
      }
      if (!values.is_active) {
        toast.error('Activate the vendor before sending a portal invitation.');
        return;
      }
      if (!values.contact_person?.trim() || !values.email?.trim()) {
        toast.error('Add a contact name and email before sending a portal invitation.');
        return;
      }
    }
    mutation.mutate({ values, sendPortalInvitation, isAuthorizedSignatory: authorizedSignatory });
  }, onFormInvalid<FormValues>());

  return (
    <div>
      <PageHeader
        title={mode === 'create' ? 'New vendor' : `Edit ${existing?.name ?? 'vendor'}`}
        backTo="/accounting/vendors"
        backLabel="Vendors"
      />
      <FormDraftBanner safety={safety} />
      <form
        onSubmit={submit}
        className="min-w-0 w-full px-5 py-4 space-y-4"
      >
        <Panel title="Identity">
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Vendor name"
              required
              {...register('name')}
              error={errors.name?.message}
            />
            <Input
              label="Contact person"
              {...register('contact_person')}
              error={errors.contact_person?.message}
            />
            <Input
              label="Email"
              type="email"
              {...register('email')}
              error={errors.email?.message}
            />
            <Input label="Phone" {...register('phone')} error={errors.phone?.message} />
            <Textarea
              label="Address"
              rows={2}
              className="col-span-2"
              {...register('address')}
              error={errors.address?.message}
            />
            <Input label="TIN" {...register('tin')} error={errors.tin?.message} />
            <Input
              label="Payment terms (days)"
              min={0}
              max={365}
              className="font-mono tabular-nums text-right"
              {...numberInputProps({ decimal: false })}
              {...register('payment_terms_days')}
              error={errors.payment_terms_days?.message}
            />
            <Select
              label="Expanded withholding tax (EWT)"
              helper="Withheld from payments to this vendor and reported on BIR 2307."
              {...register('withholding_tax_type')}
              error={errors.withholding_tax_type?.message}
            >
              <option value="none">None</option>
              <option value="goods">Goods (1%)</option>
              <option value="services">Services (2%)</option>
              <option value="rentals">Rentals (5%)</option>
              <option value="professional">Professional Services (10%)</option>
            </Select>
          </div>
          <div className="mt-3">
            <Switch label="Active" {...register('is_active')} />
          </div>
        </Panel>
        {mode === 'create' && (
          <Panel title="Portal access">
            <div className="space-y-3">
              <Switch
                label="Send portal invitation after vendor creation"
                description="Optional. The invitation goes to the contact email entered above."
                checked={inviteToPortal && portalInviteReady}
                disabled={!portalInviteReady}
                onChange={(event) => setInviteToPortal(event.target.checked)}
              />
              {!portalInviteReady && (
                <p className="text-xs text-muted">{!vendorActive
                  ? 'Activate the vendor to enable portal invitations.'
                  : 'Add a contact name and email to enable portal invitations.'}</p>
              )}
              {inviteToPortal && (
                <Checkbox
                  checked={authorizedSignatory}
                  onChange={(event) => setAuthorizedSignatory(event.target.checked)}
                  label={<span><span className="font-medium text-primary">Authorized signatory</span><span className="block text-xs text-muted">Allows this contact to sign supplier agreements.</span></span>}
                />
              )}
            </div>
          </Panel>
        )}
        <FormActions>
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/vendors')}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            loading={mutation.isPending}
            disabled={isSubmitting || mutation.isPending}
          >
            {mode === 'create' ? 'Create vendor' : 'Save changes'}
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
