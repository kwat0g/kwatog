import { useEffect } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { crmCustomersApi } from '@/api/crm/customers';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { CustomerForm, customerResolver, customerSchema, type CustomerFormValues } from './form';

import { FormActions } from '@/components/ui/FormActions';
type FormValues = CustomerFormValues;

export default function CrmCustomerEditPage() {
 const { id } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'customers', 'detail', id],
 queryFn: () => crmCustomersApi.show(id!),
 enabled: !!id,
 });

 const methods = useForm<z.input<typeof customerSchema>, unknown, FormValues>({
 resolver: customerResolver,
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

 const { handleSubmit, setError, reset, formState: { isSubmitting } } = methods;

 useEffect(() => {
 if (data) {
 reset({
 name: data.name,
 contact_person: data.contact_person ?? '',
 email: data.email ?? '',
 phone: data.phone ?? '',
 address: data.address ?? '',
 delivery_address: data.delivery_address ?? '',
 delivery_contact_person: data.delivery_contact_person ?? '',
 delivery_contact_phone: data.delivery_contact_phone ?? '',
 allowed_payment_methods: data.allowed_payment_methods ?? [],
 shipping_payer: data.shipping_payer ?? '',
 agreement_starts_on: data.agreement_starts_on ?? '',
 agreement_ends_on: data.agreement_ends_on ?? '',
 warranty_duration_days: data.warranty_duration_days ?? undefined,
 warranty_report_window_days: data.warranty_report_window_days ?? undefined,
 warranty_start_basis: data.warranty_start_basis ?? '',
 warranty_covered_issues: data.warranty_covered_issues ?? [],
 warranty_remedies: data.warranty_remedies ?? [],
 credit_limit: data.credit_limit ? Number(data.credit_limit) : undefined,
 payment_terms_days: data.payment_terms_days,
 is_active: data.is_active,
 });
 }
 }, [data, reset]);

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
 return crmCustomersApi.update(id!, payload);
 },
 onSuccess: (customer) => {
 qc.invalidateQueries({ queryKey: ['crm', 'customers'] });
 qc.invalidateQueries({ queryKey: ['accounting', 'customers'] });
 toast.success('Customer updated.');
 navigate(`/crm/customers/${customer.id}`);
 },
 onError: (e) => {
   applyServerValidationErrors(e, setError, 'Failed to save the customer.');
 },
 });
 const safety = useFormSafety({ form: methods, saved: mutation.isSuccess });

 const backTo = data ? `/crm/customers/${data.id}` : '/crm/customers';

 return (
 <div>
 <PageHeader
 title={data ? `Edit ${data.name}` : 'Edit customer'}
  subtitle="Update customer details and defaults used for future orders."
 backTo={backTo}
 backLabel={data?.name ?? 'Customers'}
 />
 <div className="mx-auto w-full max-w-screen-2xl px-4 sm:px-5">
      <FormDraftBanner safety={safety} inset={false} />
 {isLoading && <SkeletonDetail />}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load customer"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}
 {data && (
 <FormProvider {...methods}>
 <form onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}>
 <CustomerForm />
 <FormActions>
 <Button
 type="button"
 variant="secondary"
 onClick={() => navigate(backTo)}
 >
 Cancel
 </Button>
 <Button
 type="submit"
 variant="primary"
 loading={mutation.isPending}
 disabled={isSubmitting || mutation.isPending}
 >
 {mutation.isPending ? 'Saving…' : 'Save changes'}
 </Button>
 </FormActions>
 </form>
 </FormProvider>
 )}
 </div>
 </div>
 );
}
