/**
 * Opportunity create/edit form. Mirrors StoreOpportunityRequest /
 * UpdateOpportunityRequest. customer_id / assigned_to are hash IDs resolved
 * server-side. Stage defaults server-side (prospecting) and moves only via
 * advance/win/lose.
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
import { opportunitiesApi } from '@/api/crm/opportunities';
import { crmCustomersApi } from '@/api/crm/customers';
import { adminUsersApi } from '@/api/admin/users';
import type { Opportunity, CreateOpportunityData, UpdateOpportunityData } from '@/types/crm';

const money = z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use a non-negative decimal with up to 2 places');

const schema = z.object({
 customer_id: z.string().min(1, 'Customer is required'),
 title: z.string().min(1, 'Title is required').max(255),
 estimated_value: money.optional().or(z.literal('')),
 expected_close_date: z.string().optional().or(z.literal('')),
 probability: z.string().regex(/^\d+$/, 'Use 0–100').refine((v) => v === '' || (Number(v) >= 0 && Number(v) <= 100), 'Use 0–100').optional().or(z.literal('')),
 assigned_to: z.string().optional().or(z.literal('')),
 notes: z.string().optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

interface Props {
 initial?: Opportunity;
 mode: 'create' | 'edit';
}

export function OpportunityForm({ initial, mode }: Props) {
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
 customer_id: initial?.customer?.id ?? '',
 title: initial?.title ?? '',
 estimated_value: initial?.estimated_value ?? '',
 expected_close_date: initial?.expected_close_date ?? '',
 probability: initial ? String(initial.probability) : '',
 assigned_to: initial?.assignee?.id ?? '',
 notes: initial?.notes ?? '',
 },
 });

 useEffect(() => {
 if (initial) {
 setValue('customer_id', initial.customer?.id ?? '');
 setValue('title', initial.title);
 setValue('estimated_value', initial.estimated_value);
 setValue('expected_close_date', initial.expected_close_date ?? '');
 setValue('probability', String(initial.probability));
 setValue('assigned_to', initial.assignee?.id ?? '');
 setValue('notes', initial.notes ?? '');
 }
 }, [initial, setValue]);

 const mutation = useMutation({
 mutationFn: (values: FormValues) => {
 const payload: CreateOpportunityData | UpdateOpportunityData = {
 customer_id: values.customer_id,
 title: values.title,
 estimated_value: values.estimated_value || null,
 expected_close_date: values.expected_close_date || null,
 probability: values.probability ? Number(values.probability) : null,
 assigned_to: values.assigned_to || null,
 notes: values.notes?.trim() ? values.notes : null,
 };
 return mode === 'create'
 ? opportunitiesApi.create(payload as CreateOpportunityData)
 : opportunitiesApi.update(initial!.id, payload);
 },
 onSuccess: (opportunity) => {
 qc.invalidateQueries({ queryKey: ['crm', 'opportunities'] });
 toast.success(mode === 'create' ? 'Opportunity created.' : 'Opportunity updated.');
 navigate(`/crm/opportunities/${opportunity.id}`);
 },
 onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
 setError(field as keyof FormValues, { type: 'server', message: msgs[0] });
 });
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else {
 toast.error(e.response?.data?.message ?? 'Failed to save opportunity.');
 }
 },
 });

 return (
 <form
 onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}
 className="max-w-3xl mx-auto px-5 py-4"
 >
 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Opportunity</legend>
 <div className="grid grid-cols-2 gap-3">
 <Input
 label="Title"
 required
 {...register('title')}
 error={errors.title?.message}
 placeholder="e.g. Wiper Bushing — Annual Supply"
 />
 <Select
 label="Customer"
 required
 {...register('customer_id')}
 error={errors.customer_id?.message}
 disabled={customers.isLoading}
 >
 <option value="">Select a customer…</option>
 {customers.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
 </Select>
 <Input
 label="Estimated Value (₱)"
 {...register('estimated_value')}
 error={errors.estimated_value?.message}
 placeholder="e.g. 2500000"
 className="font-mono"
 />
 <Input
 label="Probability (%)"
 {...register('probability')}
 error={errors.probability?.message}
 placeholder="e.g. 30"
 className="font-mono"
 />
 <Input
 label="Expected Close Date"
 type="date"
 {...register('expected_close_date')}
 error={errors.expected_close_date?.message}
 className="font-mono"
 />
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
 placeholder="Deal context…"
 rows={4}
 />
 </fieldset>

 <div className="flex items-center justify-end gap-2">
 <Button type="button" variant="secondary" onClick={() => navigate(-1)}>Cancel</Button>
 <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending}>
 {isSubmitting || mutation.isPending ? 'Saving…' : mode === 'create' ? 'Create opportunity' : 'Save changes'}
 </Button>
 </div>
 </form>
 );
}
