import { useEffect } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { crmCustomersApi } from '@/api/crm/customers';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import type { ApiValidationError } from '@/types';
import { CustomerForm, customerSchema, type CustomerFormValues } from './form';

type FormValues = CustomerFormValues;

export default function CrmCustomerCreatePage() {
 const navigate = useNavigate();
 const qc = useQueryClient();

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

 const { handleSubmit, setError, setValue, formState: { isSubmitting } } = methods;
 const { data: policies } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });

 useEffect(() => {
 if (policies) setValue('payment_terms_days', policies.customer_payment_terms_days);
 }, [policies, setValue]);

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
 const safety = useFormSafety({ form: methods, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader
 title="New customer"
 backTo="/crm/customers"
 backLabel="Customers"
 />
      <FormDraftBanner safety={safety} />
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
