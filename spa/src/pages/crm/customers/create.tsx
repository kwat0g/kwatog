import { useEffect } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
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
import { CustomerForm, customerSchema, type CustomerFormValues } from './form';

import { FormActions } from '@/components/ui/FormActions';
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
 onError: (e) => {
   applyServerValidationErrors(e, setError, 'Failed to create the customer.');
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
 );
}
