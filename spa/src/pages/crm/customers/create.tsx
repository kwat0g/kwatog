import { useEffect } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { crmCustomersApi } from '@/api/crm/customers';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { CustomerForm, customerCreateResolver, customerSchema, type CustomerFormValues } from './form';

import { FormActions } from '@/components/ui/FormActions';
type FormValues = CustomerFormValues;

export default function CrmCustomerCreatePage() {
 const navigate = useNavigate();
 const qc = useQueryClient();

 const methods = useForm<z.input<typeof customerSchema>, unknown, FormValues>({
 resolver: customerCreateResolver,
 defaultValues: {
 name: '',
 contact_person: '',
 email: '',
 phone: '',
 address: '',
 delivery_address: '',
 delivery_contact_person: '',
 delivery_contact_phone: '',
 allowed_payment_methods: [], shipping_payer: '', agreement_starts_on: '', agreement_ends_on: '',
 warranty_duration_days: undefined, warranty_report_window_days: undefined, warranty_start_basis: '', warranty_covered_issues: [], warranty_remedies: [],
 is_active: true,
 },
 });

 const { handleSubmit, setError, setValue, formState: { isSubmitting } } = methods;
 const { data: policies } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });

 useEffect(() => {
 if (policies) setValue('payment_terms_days', policies.customer_payment_terms_days);
 }, [policies, setValue]);

 const mutation = useMutation({
 mutationFn: (values: FormValues) => {
 const payload = {
 ...values,
 contact_person: values.contact_person || undefined,
 email: values.email || undefined,
 phone: values.phone || undefined,
 address: values.address || undefined,
 delivery_address: values.delivery_address || undefined,
 delivery_contact_person: values.delivery_contact_person || undefined,
 delivery_contact_phone: values.delivery_contact_phone || undefined,
 allowed_payment_methods: values.allowed_payment_methods?.length ? values.allowed_payment_methods : null,
 shipping_payer: values.shipping_payer || null,
 agreement_starts_on: values.agreement_starts_on || null,
 agreement_ends_on: values.agreement_ends_on || null,
 warranty_duration_days: values.warranty_duration_days ?? null,
 warranty_report_window_days: values.warranty_report_window_days ?? null,
 warranty_start_basis: values.warranty_start_basis || null,
 warranty_covered_issues: values.warranty_covered_issues?.length ? values.warranty_covered_issues : null,
 warranty_remedies: values.warranty_remedies?.length ? values.warranty_remedies : null,
 credit_limit: values.credit_limit != null ? String(values.credit_limit) : null,
 };
 return crmCustomersApi.create(payload);
 },
 onSuccess: (customer) => {
 qc.invalidateQueries({ queryKey: ['crm', 'customers'] });
 qc.invalidateQueries({ queryKey: ['accounting', 'customers'] });
 toast.success('Customer created.');
 navigate(`/crm/customers/${customer.id}`);
 },
 onError: (e) => {
   applyServerValidationErrors(e, setError, 'Failed to create the customer.');
 },
 });
 const safety = useFormSafety({ form: methods, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader
 title="New customer"
  subtitle="Customer details and defaults used for future orders."
 backTo="/crm/customers"
 backLabel="Customers"
 />
 <div className="mx-auto w-full max-w-screen-2xl px-4 sm:px-5">
      <FormDraftBanner safety={safety} inset={false} />
 <FormProvider {...methods}>
 <form onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}>
 <CustomerForm requireWarrantyDefaults />
 <FormActions>
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
 </FormActions>
 </form>
 </FormProvider>
 </div>
 </div>
 );
}
