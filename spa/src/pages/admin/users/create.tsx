import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { Button, Input, Modal, ModalFooter, Panel, Select, Switch } from '@/components/ui';
import { PageHeader } from '@/components/layout/PageHeader';
import { adminUsersApi } from '@/api/admin/users';
import type { EmployeeCandidate } from '@/types/admin';

import { useFormSafety } from '@/hooks/useFormSafety';
import { useDebounce } from '@/hooks/useDebounce';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { applyServerValidationErrors } from '@/lib/formErrors';

// Mirrors CreateUserRequest: employee_id + role_id required, email nullable
// (filled from the employee record when present). The one state-dependent
// backend rule — "employee with no HR email needs a typed email" — is
// enforced at submit because it depends on the selected employee.
const schema = z.object({
 department_id: z.string().min(1, 'Select a department'),
 employee_id: z.string().min(1, 'Select an employee'),
 email: z.string().email('Invalid email').optional().or(z.literal('')),
 role_id: z.string().min(1, 'Role is required'),
 send_welcome: z.boolean().default(true),
});

type FormValues = z.infer<typeof schema>;

/**
 * U2 — Admin > Create User. Department-first flow: the admin picks a
 * department, then the employee dropdown lists that department's eligible
 * employees (no account yet, regular or probationary), searchable within it.
 * Identity is derived from the HR record — name always, email as a prefill.
 */
export default function AdminCreateUserPage() {
 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const [tempPasswordModal, setTempPasswordModal] = useState<string | null>(null);
 const [employeeSearch, setEmployeeSearch] = useState('');
 const [selectedEmployee, setSelectedEmployee] = useState<EmployeeCandidate | null>(null);
 const debouncedEmployeeSearch = useDebounce(employeeSearch, 300);

 const rolesQuery = useQuery({
 queryKey: ['admin-user-options'],
 queryFn: adminUsersApi.options,
 staleTime: 60_000,
 });

  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { department_id: '', employee_id: '', email: '', role_id: '', send_welcome: true },
 });
 const departments = rolesQuery.data?.departments ?? [];
 const departmentId = useWatch({ control: form.control, name: 'department_id' });

 const candidatesQuery = useQuery({
 queryKey: ['admin-employee-candidates', departmentId, debouncedEmployeeSearch],
 queryFn: () =>
 adminUsersApi.employeeCandidates({
 department_id: departmentId,
 search: debouncedEmployeeSearch || undefined,
 }),
 enabled: !!departmentId,
 placeholderData: (prev) => prev,
 });

 const candidates = candidatesQuery.data ?? [];
 const {
 register,
 handleSubmit,
 setError,
 setValue,
 formState: { errors, isSubmitting },
 } = form;

 const onDepartmentChange = (value: string) => {
 // Employees are department-scoped: switching departments invalidates any
 // selection and search carried over from the previous one.
 if (value !== departmentId) {
 setValue('employee_id', '');
 setSelectedEmployee(null);
 setEmployeeSearch('');
 }
 };

 const onEmployeeChange = (value: string) => {
 const candidate = candidates.find((c) => c.id === value) ?? null;
 setSelectedEmployee(candidate);
 // Prefill the login email from the HR record; cleared when the employee
 // has none (the admin must type one before submit).
 setValue('email', candidate?.email ?? '');
 };

 const mutation = useMutation({
 mutationFn: (v: FormValues) =>
 adminUsersApi.create({
 employee_id: v.employee_id,
 email: v.email?.trim() || undefined,
 role_id: v.role_id,
 send_welcome: v.send_welcome,
 }),
 onSuccess: (r) => {
 queryClient.invalidateQueries({ queryKey: ['admin-users'] });
 queryClient.invalidateQueries({ queryKey: ['admin-employee-candidates'] });
 toast.success(r.message ?? 'User created.');
 if (r.data.temp_password) {
 setTempPasswordModal(r.data.temp_password);
 } else {
 navigate(`/admin/users/${r.data.id}`);
 }
 },
 onError: (err) => {
   applyServerValidationErrors(err, setError, 'Failed to create user.');
 },
 });

 const onSubmit = (v: FormValues) => {
 if (selectedEmployee && !selectedEmployee.email && !v.email?.trim()) {
 setError('email', {
 type: 'manual',
 message: 'This employee has no email on record. Enter a login email.',
 });
 return;
 }
 mutation.mutate(v);
 };
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader title="Create User" backTo="/admin/users" backLabel="Users" />
      <FormDraftBanner safety={safety} />

 <form
 onSubmit={handleSubmit(onSubmit)}
 className="max-w-2xl mx-auto px-5 py-4"
 >
 <Panel title="Employee">
 <fieldset className="space-y-4">
 <Select
 label="Department"
 {...register('department_id', { onChange: (e) => onDepartmentChange(e.target.value) })}
 error={errors.department_id?.message}
 helper="Pick a department to load its employees."
 required
 >
 <option value="">Select a department</option>
 {departments.map((d) => (
 <option key={d.id} value={d.id}>
 {d.name}
 </option>
 ))}
 </Select>
 <Input
 label="Find employee"
 value={employeeSearch}
 onChange={(event) => setEmployeeSearch(event.target.value)}
 placeholder="Employee no, last name, or first name"
 helper="Only employees without a user account are listed."
 disabled={!departmentId}
 />
 <Select
 label="Employee"
 {...register('employee_id', { onChange: (e) => onEmployeeChange(e.target.value) })}
 error={errors.employee_id?.message ?? (candidatesQuery.isError ? 'Could not load employees. Retry before submitting.' : undefined)}
 helper={!departmentId ? 'Select a department first.' : candidatesQuery.isFetching ? 'Searching…' : undefined}
 disabled={!departmentId || candidatesQuery.isError || mutation.isPending}
 required
 >
 <option value="">{departmentId ? '— Select —' : 'Select a department first'}</option>
 {candidates.map((c) => (
 <option key={c.id} value={c.id}>
 {c.full_name} ({c.employee_no})
 </option>
 ))}
 </Select>
 {candidatesQuery.isError && (
 <Button type="button" variant="ghost" size="xs" onClick={() => candidatesQuery.refetch()}>
 Retry employee list
 </Button>
 )}
 {departmentId && candidates.length === 0 && !candidatesQuery.isFetching && !candidatesQuery.isError && (
 <p className="text-sm text-muted">
 No eligible employees found in this department. Employees who already have an account,
 or who are not regular/probationary, are not listed.
 </p>
 )}
 {selectedEmployee && (
 <div className="border border-default rounded-md p-3 text-sm space-y-1">
 <div className="flex items-center justify-between gap-2">
 <span className="font-medium">{selectedEmployee.full_name}</span>
 <span className="font-mono tabular-nums text-xs text-muted">{selectedEmployee.employee_no}</span>
 </div>
 <div className="text-muted text-xs">
 {[selectedEmployee.position, selectedEmployee.department, selectedEmployee.employment_type.replace('_', ' ')]
 .filter(Boolean)
 .join(' · ')}
 </div>
 </div>
 )}
 </fieldset>
 </Panel>

 <Panel title="Account">
 <fieldset className="space-y-4">
 <Input
 label="Email"
 type="email"
 {...register('email')}
 error={errors.email?.message}
 helper={
 selectedEmployee?.email
 ? 'Prefilled from the employee record — you can correct it before submitting.'
 : 'Required: this employee has no email on their HR record.'
 }
 required={!selectedEmployee?.email}
 />
 <Select
 label="Role"
 {...register('role_id')}
 error={errors.role_id?.message ?? (rolesQuery.isError ? 'Could not load roles. Retry before submitting.' : undefined)}
 helper={rolesQuery.isLoading ? 'Loading available roles…' : undefined}
 disabled={rolesQuery.isLoading || rolesQuery.isError || mutation.isPending}
 required
 >
 <option value="">Select a role</option>
 {(rolesQuery.data?.roles ?? []).map((r) => (
 <option key={r.id} value={r.id}>
 {r.name}
 </option>
 ))}
 </Select>
 {rolesQuery.isError && (
 <Button type="button" variant="ghost" size="xs" onClick={() => rolesQuery.refetch()}>
 Retry role list
 </Button>
 )}
 <div className="flex items-center justify-between text-sm">
 <span className="text-secondary">Send welcome email with temporary password</span>
 <Switch {...register('send_welcome')} />
 </div>
 </fieldset>
 </Panel>

 <ModalFooter>
 <Button
 type="button"
 variant="secondary"
 onClick={() => navigate('/admin/users')}
 >
 Cancel
 </Button>
 <Button
 type="submit"
 variant="primary"
 disabled={isSubmitting || mutation.isPending}
 loading={mutation.isPending}
 >
 {mutation.isPending ? 'Creating…' : 'Create User'}
 </Button>
 </ModalFooter>
 </form>

 <Modal
 isOpen={!!tempPasswordModal}
 onClose={() => {
 setTempPasswordModal(null);
 navigate('/admin/users');
 }}
 title="Account Created"
 size="sm"
 >
 <div className="space-y-3 px-1">
 <p className="text-sm">
 Account created successfully. Copy this temporary password — it is shown
 only once. The user must change it on first login.
 </p>
 <code className="block bg-elevated rounded-md p-3 font-mono tabular-nums text-md select-all">
 {tempPasswordModal}
 </code>
 <ModalFooter>
 <Button
 variant="primary"
 onClick={() => {
 setTempPasswordModal(null);
 navigate('/admin/users');
 }}
 >
 Done
 </Button>
 </ModalFooter>
 </div>
 </Modal>
 </div>
 );
}
