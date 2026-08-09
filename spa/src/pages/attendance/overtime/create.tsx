import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { overtimeApi } from '@/api/attendance/overtime';
import { employeesApi } from '@/api/hr/employees';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import type { ApiValidationError } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';

function todayIso(): string {
 const d = new Date();
 const off = d.getTimezoneOffset() * 60_000;
 return new Date(d.getTime() - off).toISOString().slice(0, 10);
}

function addDaysIso(dateStr: string, days: number): string {
 const d = new Date(dateStr + 'T00:00:00');
 d.setDate(d.getDate() + days);
 const off = d.getTimezoneOffset() * 60_000;
 return new Date(d.getTime() - off).toISOString().slice(0, 10);
}

type FormValues = {
 employee_id: string;
 date: string;
 hours_requested: number;
 reason: string;
};

export default function OvertimeCreatePage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const user = useAuthStore((s) => s.user);

 const { data: options, isLoading: optionsLoading, isError: optionsError } = useQuery({
 queryKey: ['attendance', 'overtime', 'options'],
 queryFn: overtimeApi.options,
 staleTime: 5 * 60_000,
 });

 const { data: employeesResp } = useQuery({
 queryKey: ['hr', 'employees', 'all'],
 queryFn: () => employeesApi.list({ per_page: 100, status: 'active' }),
 });
 const employees = employeesResp?.data ?? [];

 // Policy validation is derived from the server response. Until the settings
 // arrive, only field-shape validation runs; the form remains disabled so a
 // missing response can never silently turn into a local default policy.
 const schema = useMemo(() => {
 const base = z.object({
 employee_id: z.string().min(1, 'Employee is required'),
 date: z.string().min(1, 'Date is required'),
 hours_requested: z.coerce.number({ invalid_type_error: 'Enter a number' }),
 reason: z.string().trim().min(5, 'Provide at least 5 characters').max(2000),
 });
 if (!options) return base;

 // Mirrors StoreOvertimeRequestRequest: after_or_equal(today - past) and
 // before_or_equal(today + future), using only values returned by the API.
 const dateMin = addDaysIso(todayIso(), -options.request_past_days);
 const dateMax = addDaysIso(todayIso(), options.request_future_days);
 return base.extend({
 date: base.shape.date
 .refine((v) => v >= dateMin, 'Date is outside the configured request window.')
 .refine((v) => v <= dateMax, 'Date is outside the configured request window.'),
 hours_requested: base.shape.hours_requested
 .min(options.request_min_hours, `Minimum ${options.request_min_hours} hours`)
 .max(options.maximum_hours, `Maximum ${options.maximum_hours} hours per day`),
 });
 }, [options]);

 const {
 register, handleSubmit, setError,
 formState: { errors, isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 employee_id: user?.employee?.id ?? '',
 date: todayIso(),
 },
 });

 const mutation = useMutation({
 mutationFn: (d: FormValues) => overtimeApi.create({
 employee_id: d.employee_id,
 date: d.date,
 hours_requested: d.hours_requested,
 reason: d.reason,
 }),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] });
 toast.success('Overtime request submitted.');
 navigate('/hr/attendance/overtime');
 },
 onError: (e: AxiosError<ApiValidationError>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
 setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
 );
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else toast.error('Failed to submit OT request.');
 },
 });

 const dateMin = options ? addDaysIso(todayIso(), -options.request_past_days) : undefined;
 const dateMax = options ? addDaysIso(todayIso(), options.request_future_days) : undefined;

 return (
 <div>
 <PageHeader title="New overtime request" backTo="/hr/attendance/overtime" backLabel="Overtime" />
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-2xl mx-auto px-5 py-4">
 {optionsLoading ? (
 <Panel title="Request details"><SkeletonBlock className="h-24" /></Panel>
 ) : (
 <Panel
 title="Request details"
 meta={options ? `Max ${options.maximum_hours} hours/day` : undefined}
 >
 <div className="grid grid-cols-2 gap-3">
 <Select label="Employee" required {...register('employee_id')} error={errors.employee_id?.message}>
 <option value="">— Select —</option>
 {employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_no})</option>)}
 </Select>
 <Input
 label="Date"
 type="date"
 required
 {...register('date')}
 error={errors.date?.message}
 min={dateMin}
 max={dateMax}
 />
 <Input
 label="Hours requested"
 type="number"
 step="any"
 required
 {...register('hours_requested')}
 error={errors.hours_requested?.message}
 min={options?.request_min_hours}
 max={options?.maximum_hours}
 className="font-mono"
 />
 </div>
 {options && (
 <p className="text-xs text-muted mt-2">
 Requests must fall within the configured window (past {options.request_past_days}d · future {options.request_future_days}d). Maximum {options.maximum_hours} hours per day.
 </p>
 )}
 <div className="mt-3">
 <Textarea label="Reason" required {...register('reason')} error={errors.reason?.message} rows={3} />
 </div>
 </Panel>
 )}
 <div className="flex justify-end gap-2 pt-4">
 <Button type="button" variant="secondary" onClick={() => navigate('/hr/attendance/overtime')}>Cancel</Button>
 <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending || optionsLoading || optionsError || !options} loading={mutation.isPending}>
 {mutation.isPending ? 'Submitting…' : 'Submit request'}
 </Button>
 </div>
 </form>
 </div>
 );
}
