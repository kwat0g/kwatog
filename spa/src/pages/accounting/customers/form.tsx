import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { FormProvider, useForm } from 'react-hook-form';
import type { Resolver } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { customersApi } from '@/api/accounting/customers';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
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
import { CustomerDeliveryLocationPicker } from '@/components/accounting/CustomerDeliveryLocationPicker';
import {
 customerAddressField,
 customerContactNameField,
 customerCreditLimitField,
 customerEmailField,
 customerLegalNameField,
 customerPaymentTermsField,
 customerPhoneField,
 customerRoleTitleField,
 customerTaxIdField,
 customerWarrantyDaysField,
} from '@/lib/validation/customerFields';
const createSchema = (requireDeliveryPin: boolean) => z.object({
 name: customerLegalNameField,
 contact_person: customerContactNameField,
 contact_person_role: customerRoleTitleField,
 email: customerEmailField,
 phone: customerPhoneField,
 address: customerAddressField,
 delivery_address: customerAddressField,
 delivery_contact_person: customerContactNameField,
 delivery_contact_phone: customerPhoneField,
 delivery_latitude: z.preprocess((value) => value === '' || value == null ? undefined : value, z.coerce.number().min(-90).max(90).optional()),
 delivery_longitude: z.preprocess((value) => value === '' || value == null ? undefined : value, z.coerce.number().min(-180).max(180).optional()),
 tin: customerTaxIdField,
 credit_limit: customerCreditLimitField,
 payment_terms_days: customerPaymentTermsField,
 restrict_payment_methods: z.boolean().default(false),
 allowed_payment_methods: z.array(z.enum(['cash', 'check', 'bank_transfer'])).default([]),
 warranty_duration_days: customerWarrantyDaysField,
 warranty_report_window_days: customerWarrantyDaysField,
 is_active: z.boolean().default(true),
}).superRefine((values, context) => {
 if ((values.delivery_latitude == null) !== (values.delivery_longitude == null)) {
  context.addIssue({ code: 'custom', path: ['delivery_latitude'], message: 'Choose a complete delivery location pin.' });
 }
 if (requireDeliveryPin && (values.delivery_latitude == null || values.delivery_longitude == null)) {
  context.addIssue({ code: 'custom', path: ['delivery_latitude'], message: 'Select the delivery location on the map.' });
 }
 if (values.restrict_payment_methods && values.allowed_payment_methods.length === 0) {
  context.addIssue({ code: 'custom', path: ['allowed_payment_methods'], message: 'Choose at least one allowed method.' });
 }
});
type FormValues = z.output<ReturnType<typeof createSchema>>;

export default function CustomerFormPage({ mode }: { mode: 'create' | 'edit' }) {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { id = '' } = useParams<{ id: string }>();

 const { data: existing } = useQuery({
 queryKey: ['accounting', 'customers', id],
 queryFn: () => customersApi.show(id),
 enabled: mode === 'edit' && !!id,
 });
 const { data: policies } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });
 const formSchema = createSchema(mode === 'create');
 const resolver = zodResolver(formSchema) as unknown as Resolver<z.input<ReturnType<typeof createSchema>>, unknown, FormValues>;

  const form = useForm<z.input<ReturnType<typeof createSchema>>, unknown, FormValues>({
 resolver,
 defaultValues: { is_active: true, restrict_payment_methods: false, allowed_payment_methods: [] },
 values: existing ? {
 name: existing.name, contact_person: existing.contact_person ?? '', contact_person_role: existing.contact_person_role ?? '', email: existing.email ?? '',
 phone: existing.phone ?? '', address: existing.address ?? '', tin: existing.tin ?? '',
 delivery_address: existing.delivery_address ?? '', delivery_contact_person: existing.delivery_contact_person ?? '',
 delivery_contact_phone: existing.delivery_contact_phone ?? '',
 delivery_latitude: existing.delivery_latitude == null ? '' : String(existing.delivery_latitude),
 delivery_longitude: existing.delivery_longitude == null ? '' : String(existing.delivery_longitude),
 credit_limit: existing.credit_limit ? Number(existing.credit_limit) : undefined,
 payment_terms_days: existing.payment_terms_days,
 restrict_payment_methods: existing.allowed_payment_methods !== null,
 allowed_payment_methods: existing.allowed_payment_methods ?? [],
 warranty_duration_days: existing.warranty_duration_days ?? undefined,
 warranty_report_window_days: existing.warranty_report_window_days ?? undefined,
 is_active: existing.is_active,
 } as z.input<ReturnType<typeof createSchema>> : undefined,
 });
 const { register, handleSubmit, setError, setValue, formState: { errors, isSubmitting } } = form;

 useEffect(() => {
 if (mode === 'create' && policies) {
 setValue('payment_terms_days', policies.customer_payment_terms_days);
 }
 }, [mode, policies, setValue]);

 const mutation = useMutation({
 mutationFn: (d: FormValues) => {
 const { restrict_payment_methods, ...customerData } = d;
 const payload = {
  ...customerData,
  allowed_payment_methods: restrict_payment_methods ? customerData.allowed_payment_methods : null,
  credit_limit: d.credit_limit ? String(d.credit_limit) : null,
 };
 return mode === 'create' ? customersApi.create(payload) : customersApi.update(id, payload);
 },
 onSuccess: (c) => {
 qc.invalidateQueries({ queryKey: ['accounting', 'customers'] });
 toast.success(mode === 'create' ? 'Customer created.' : 'Customer updated.');
  safety.allowNavigationAfterSave();
  navigate(mode === 'create' ? `/crm/customers/${c.id}` : `/accounting/customers/${c.id}`);
 },
 onError: (e) => {
   applyServerValidationErrors(e, setError, 'Failed to save the customer.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader title={mode === 'create' ? 'New customer' : `Edit ${existing?.name ?? 'customer'}`} subtitle={mode === 'create' ? 'Set the receiving location and commercial defaults. Product access and warranty choices are configured after saving.' : undefined} backTo="/accounting/customers" backLabel="Customers"
 />
      <FormDraftBanner safety={safety} />
 <FormProvider {...form}>
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="min-w-0 w-full px-5 py-4 space-y-4">
 <p role="note" className="rounded-md border border-default bg-surface px-4 py-3 text-sm leading-relaxed text-secondary">After saving, configure the products this customer can order and add warranty choices for each product from the CRM customer record.</p>
 <Panel title="Identity">
 <div className="grid grid-cols-2 gap-3">
 <Input label="Customer name" required {...register('name')} error={errors.name?.message} />
 <Input label="Contact person" {...register('contact_person')} error={errors.contact_person?.message} />
 <Input label="Contact role / title" {...register('contact_person_role')} error={errors.contact_person_role?.message} placeholder="Sales officer, manager, CEO" />
 <Input label="Email" type="email" {...register('email')} error={errors.email?.message} />
 <Input label="Phone" {...register('phone')} error={errors.phone?.message} />
 <Textarea label="Address" rows={2} className="col-span-2" {...register('address')} error={errors.address?.message} />
 <Input label="TIN" {...register('tin')} error={errors.tin?.message} />
 <Input label="Default payment terms (days)" min={0} max={365} className="font-mono tabular-nums text-right" helper="Starting point for new orders; confirm or change it on each order." {...numberInputProps({ decimal: false })} {...register('payment_terms_days')} error={errors.payment_terms_days?.message} />
 <Input label="Credit limit (optional)" min="0" prefix="₱" className="font-mono tabular-nums text-right" helper="Reference only; it does not block quantities or orders." {...numberInputProps()} step="0.01" {...register('credit_limit')} error={errors.credit_limit?.message} />
 <Input label="Fallback warranty duration (days)" type="number" min={0} max={3650} helper="Optional. Product-specific choices take precedence." {...register('warranty_duration_days')} error={errors.warranty_duration_days?.message} />
 <Input label="Fallback report window (days)" type="number" min={0} max={3650} helper="Optional; measured from the agreed start event." {...register('warranty_report_window_days')} error={errors.warranty_report_window_days?.message} />
 </div>
 <div className="mt-4 space-y-3 border-t border-default pt-4">
  <Textarea label="Delivery address" helper="Enter the receiving gate or unloading point. A map pin is required when creating a customer." rows={3} {...register('delivery_address')} error={errors.delivery_address?.message} />
  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
   <Input label="Receiving contact" {...register('delivery_contact_person')} error={errors.delivery_contact_person?.message} />
   <Input label="Receiving phone" {...register('delivery_contact_phone')} error={errors.delivery_contact_phone?.message} />
  </div>
  <CustomerDeliveryLocationPicker />
 </div>
 <fieldset className="mt-4 border-t border-default pt-3">
  <legend className="text-label text-muted font-medium">Allowed payment methods</legend>
  <Switch label="Restrict payment methods" {...register('restrict_payment_methods')} />
  {form.watch('restrict_payment_methods') && <div className="mt-2 grid grid-cols-1 gap-x-2 gap-y-1 sm:grid-cols-3">
   {([['cash', 'Cash'], ['check', 'Check'], ['bank_transfer', 'Bank transfer']] as const).map(([value, label]) => <Checkbox key={value} className="w-full justify-start rounded-md px-2 text-left hover:bg-subtle" label={label} value={value} {...register('allowed_payment_methods')} />)}
  </div>}
  <p className="mt-1 text-xs text-muted">{form.watch('restrict_payment_methods') ? 'Orders may use only the selected methods.' : 'All supported methods are allowed.'} Cash and check payments are recorded by Finance.</p>
  {errors.allowed_payment_methods?.message && <p role="alert" className="mt-1 text-sm text-danger-fg">{errors.allowed_payment_methods.message}</p>}
 </fieldset>
 <div className="mt-3"><Switch label="Active" {...register('is_active')} /></div>
 </Panel>
 <FormActions>
 <Button type="button" variant="secondary" onClick={() => navigate('/accounting/customers')}>Cancel</Button>
 <Button type="submit" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending}>
 {mode === 'create' ? 'Create customer' : 'Save changes'}
 </Button>
 </FormActions>
 </form>
 </FormProvider>
 </div>
 );
}
