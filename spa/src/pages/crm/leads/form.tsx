/**
 * Lead create/edit form. Mirrors StoreLeadRequest/UpdateLeadRequest.
 * customer_id / assigned_to are hash IDs resolved server-side via
 * ResolvesHashIds.
 */
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { leadsApi } from '@/api/crm/leads';
import { crmCustomersApi } from '@/api/crm/customers';
import { adminUsersApi } from '@/api/admin/users';
import type { Lead, CreateLeadData, UpdateLeadData, LeadSource } from '@/types/crm';

const SOURCES: Array<{ value: LeadSource; label: string }> = [
 { value: 'referral', label: 'Referral' },
 { value: 'website', label: 'Website' },
 { value: 'trade_show', label: 'Trade Show' },
 { value: 'cold_call', label: 'Cold Call' },
 { value: 'existing_customer', label: 'Existing Customer' },
 { value: 'other', label: 'Other' },
];

const money = z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use a non-negative decimal with up to 2 places');

const schema = z.object({
 company_name: z.string().min(1, 'Company name is required').max(255),
 contact_person: z.string().min(1, 'Contact person is required').max(255),
 email: z.string().email('Enter a valid email').optional().or(z.literal('')),
 phone: z.string().max(50).optional().or(z.literal('')),
 source: z.string().min(1, 'Source is required'),
 estimated_value: money.optional().or(z.literal('')),
 notes: z.string().optional().or(z.literal('')),
 assigned_to: z.string().optional().or(z.literal('')),
 customer_id: z.string().optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

interface Props {
 initial?: Lead;
 mode: 'create' | 'edit';
}

export function LeadForm({ initial, mode }: Props) {
 const navigate = useNavigate();
 const qc = useQueryClient();

 const customers = useQuery({
 queryKey: ['crm', 'customers', 'lookup'],
 queryFn: () => crmCustomersApi.list({ per_page: 200 }),
 });
 const users = useQuery({
 queryKey: ['admin', 'users', 'lookup'],
 queryFn: () => adminUsersApi.list({ per_page: 100, status: 'active' }),
 });

 const {
 register, handleSubmit, setError, setValue,
 formState: { errors, isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 company_name: initial?.company_name ?? '',
 contact_person: initial?.contact_person ?? '',
 email: initial?.email ?? '',
 phone: initial?.phone ?? '',
 source: initial?.source ?? '',
 estimated_value: initial?.estimated_value ?? '',
 notes: initial?.notes ?? '',
 assigned_to: initial?.assignee?.id ?? '',
 customer_id: initial?.customer?.id ?? '',
 },
 });

 useEffect(() => {
 if (initial) {
 setValue('company_name', initial.company_name);
 setValue('contact_person', initial.contact_person);
 setValue('email', initial.email ?? '');
 setValue('phone', initial.phone ?? '');
 setValue('source', initial.source);
 setValue('estimated_value', initial.estimated_value ?? '');
 setValue('notes', initial.notes ?? '');
 setValue('assigned_to', initial.assignee?.id ?? '');
 setValue('customer_id', initial.customer?.id ?? '');
 }
 }, [initial, setValue]);

 const mutation = useMutation({
 mutationFn: (values: FormValues) => {
 const payload: CreateLeadData | UpdateLeadData = {
 company_name: values.company_name,
 contact_person: values.contact_person,
 email: values.email?.trim() ? values.email : null,
 phone: values.phone?.trim() ? values.phone : null,
 source: values.source as LeadSource,
 estimated_value: values.estimated_value || null,
 notes: values.notes?.trim() ? values.notes : null,
 assigned_to: values.assigned_to || null,
 customer_id: values.customer_id || null,
 };
 return mode === 'create'
 ? leadsApi.create(payload as CreateLeadData)
 : leadsApi.update(initial!.id, payload);
 },
 onSuccess: (lead) => {
 qc.invalidateQueries({ queryKey: ['crm', 'leads'] });
 toast.success(mode === 'create' ? 'Lead created.' : 'Lead updated.');
 navigate(`/crm/leads/${lead.id}`);
 },
 onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
 setError(field as keyof FormValues, { type: 'server', message: msgs[0] });
 });
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else {
 toast.error(e.response?.data?.message ?? 'Failed to save lead.');
 }
 },
 });

 return (
 <form
 onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}
 className="max-w-3xl mx-auto px-5 py-4"
 >
 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Company</legend>
 <div className="grid grid-cols-2 gap-3">
 <Input
 label="Company Name"
 required
 {...register('company_name')}
 error={errors.company_name?.message}
 placeholder="e.g. Toyota Motor PH"
 />
 <Select
 label="Source"
 required
 {...register('source')}
 error={errors.source?.message}
 >
 <option value="">Select a source…</option>
 {SOURCES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
 </Select>
 <Input
 label="Contact Person"
 required
 {...register('contact_person')}
 error={errors.contact_person?.message}
 placeholder="e.g. Juan Dela Cruz"
 />
 <Input
 label="Email"
 {...register('email')}
 error={errors.email?.message}
 placeholder="juan@company.ph"
 />
 <Input
 label="Phone"
 {...register('phone')}
 error={errors.phone?.message}
 placeholder="+63…"
 className="font-mono"
 />
 <Input
 label="Estimated Value (₱)"
 {...register('estimated_value')}
 error={errors.estimated_value?.message}
 placeholder="e.g. 2500000"
 className="font-mono"
 />
 </div>
 </fieldset>

 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Assignment</legend>
 <div className="grid grid-cols-2 gap-3">
 <Select
 label="Existing Customer (required before converting)"
 {...register('customer_id')}
 error={errors.customer_id?.message}
 disabled={customers.isLoading}
 >
 <option value="">None</option>
 {customers.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
 </Select>
 <Select
 label="Assigned To"
 {...register('assigned_to')}
 error={errors.assigned_to?.message}
 disabled={users.isLoading}
 >
 <option value="">Unassigned</option>
 {users.data?.data.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
 </Select>
 </div>
 </fieldset>

 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Notes</legend>
 <Textarea
 label="Notes"
 {...register('notes')}
 error={errors.notes?.message}
 placeholder="Context about the lead…"
 rows={4}
 />
 </fieldset>

 <div className="flex items-center justify-end gap-2">
 <Button type="button" variant="secondary" onClick={() => navigate(-1)}>Cancel</Button>
 <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending}>
 {isSubmitting || mutation.isPending ? 'Saving…' : mode === 'create' ? 'Create lead' : 'Save changes'}
 </Button>
 </div>
 </form>
 );
}
