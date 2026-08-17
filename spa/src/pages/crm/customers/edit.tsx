import { useEffect } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
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
import { CustomerForm, customerSchema, type CustomerFormValues } from './form';

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

 const methods = useForm<FormValues>({
 resolver: zodResolver(customerSchema),
 defaultValues: {
 name: '',
 code: '',
 contact_person: '',
 email: '',
 phone: '',
 address: '',
 is_active: true,
 },
 });

 const { handleSubmit, setError, reset, formState: { isSubmitting } } = methods;

 useEffect(() => {
 if (data) {
 reset({
 name: data.name,
 code: data.code ?? '',
 contact_person: data.contact_person ?? '',
 email: data.email ?? '',
 phone: data.phone ?? '',
 address: data.address ?? '',
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
 code: values.code || undefined,
 contact_person: values.contact_person || undefined,
 email: values.email || undefined,
 phone: values.phone || undefined,
 address: values.address || undefined,
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
   applyServerValidationErrors(e, setError, 'Failed to save. Please try again.');
 },
 });
 const safety = useFormSafety({ form: methods, saved: mutation.isSuccess });

 const backTo = data ? `/crm/customers/${data.id}` : '/crm/customers';

 return (
 <div>
 <PageHeader
 title={data ? `Edit ${data.name}` : 'Edit customer'}
 backTo={backTo}
 backLabel={data?.name ?? 'Customers'}
 />
      <FormDraftBanner safety={safety} />
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
 <div className="max-w-3xl mx-auto px-5 pb-6 flex justify-end gap-2">
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
 </div>
 </form>
 </FormProvider>
 )}
 </div>
 );
}
