import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Button, Chip, ConfirmDialog, EmptyState, Input, Modal, ModalFooter, Panel, Select, SkeletonDetail, Td, Textarea, Th } from '@/components/ui';
import { PageHeader } from '@/components/layout/PageHeader';
import { adminUsersApi } from '@/api/admin/users';
import type { AdminUserDetail } from '@/types/admin';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { PermissionOverrides } from './_components/PermissionOverrides';
import { tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

type ProfileDraft = { name: string; email: string };

/** U2 — Admin > User detail page. */
export default function AdminUserDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const queryClient = useQueryClient();
 const [confirm, setConfirm] = useState<null | 'reset' | 'deactivate' | 'unlock'>(null);
 const [roleDialogOpen, setRoleDialogOpen] = useState(false);
 const [pendingRoleId, setPendingRoleId] = useState('');
 const [roleReason, setRoleReason] = useState('');
 const [profileDialogOpen, setProfileDialogOpen] = useState(false);
 const [profileDraft, setProfileDraft] = useState<ProfileDraft>({ name: '', email: '' });
 const [profileErrors, setProfileErrors] = useState<Record<string, string>>({});
 const [tempPasswordModal, setTempPasswordModal] = useState<string | null>(null);

 const userQuery = useQuery<AdminUserDetail>({
 queryKey: ['admin-user', id],
 queryFn: () => adminUsersApi.show(id),
 enabled: !!id,
 });

 const rolesQuery = useQuery({
 queryKey: ['admin-user-options'],
 queryFn: adminUsersApi.options,
 staleTime: 60_000,
 });

 const reset = useMutation({
 mutationFn: () => adminUsersApi.resetPassword(id),
 onSuccess: (r) => {
 toast.success(r.message);
 setConfirm(null);
 setTempPasswordModal(r.temp_password);
 queryClient.invalidateQueries({ queryKey: ['admin-user', id] });
 },
 onError: () => toast.error('Failed to reset password.'),
 });

 const deactivate = useMutation({
 mutationFn: () => adminUsersApi.deactivate(id),
 onSuccess: () => {
 toast.success('Account deactivated.');
 setConfirm(null);
 queryClient.invalidateQueries({ queryKey: ['admin-user', id] });
 queryClient.invalidateQueries({ queryKey: ['admin-users'] });
 },
 onError: () => toast.error('Failed to deactivate.'),
 });

 const activate = useMutation({
 mutationFn: () => adminUsersApi.activate(id),
 onSuccess: () => {
 toast.success('Account reactivated.');
 queryClient.invalidateQueries({ queryKey: ['admin-user', id] });
 queryClient.invalidateQueries({ queryKey: ['admin-users'] });
 },
 onError: () => toast.error('Failed to activate.'),
 });

 const unlock = useMutation({
 mutationFn: () => adminUsersApi.unlock(id),
 onSuccess: () => {
 toast.success('Account unlocked.');
 setConfirm(null);
 queryClient.invalidateQueries({ queryKey: ['admin-user', id] });
 },
 onError: () => toast.error('Failed to unlock.'),
 });

 const changeRole = useMutation({
 mutationFn: ({ roleId, reason }: { roleId: string; reason: string }) => adminUsersApi.changeRole(id, roleId, userQuery.data?.role?.id ?? '', reason),
 onSuccess: () => {
 toast.success('Role updated.');
 setRoleDialogOpen(false);
 setPendingRoleId('');
 setRoleReason('');
 queryClient.invalidateQueries({ queryKey: ['admin-user', id] });
 queryClient.invalidateQueries({ queryKey: ['admin-users'] });
 },
 onError: (error: AxiosError<{ message?: string }>) => toast.error(error.response?.data?.message ?? 'Failed to update role.'),
 });

 const updateProfile = useMutation({
 mutationFn: () => adminUsersApi.updateProfile(id, profileDraft),
 onSuccess: () => {
 toast.success('User profile updated.');
 setProfileDialogOpen(false);
 setProfileErrors({});
 queryClient.invalidateQueries({ queryKey: ['admin-user', id] });
 queryClient.invalidateQueries({ queryKey: ['admin-users'] });
 },
 onError: (error: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 const next: Record<string, string> = {};
 for (const [field, messages] of Object.entries(error.response?.data?.errors ?? {})) {
 next[field] = messages[0] ?? '';
 }
 setProfileErrors(next);
 toast.error(error.response?.data?.message ?? 'Failed to update user profile.');
 },
 });

 const openRoleDialog = (roleId: string) => {
 if (!roleId || roleId === userQuery.data?.role?.id) return;
 setPendingRoleId(roleId);
 setRoleReason('');
 setRoleDialogOpen(true);
 };

 if (userQuery.isLoading) return <SkeletonDetail />;
 if (userQuery.isError) {
 return (
 <EmptyState
 icon="alert-circle"
 title="Failed to load user"
 description="An error occurred while loading the user details."
 action={
 <Button variant="secondary" onClick={() => userQuery.refetch()}>
 Retry
 </Button>
 }
 />
 );
 }
 const user = userQuery.data;
 if (!user) return null;

 return (
 <div>
 <PageHeader
 title={user.name}
 subtitle={
 <span className="font-mono tabular-nums text-muted">{user.email}</span>
 }
 backTo="/admin/users"
 backLabel="Users"
 actions={
 <div className="flex gap-1.5">
 {!user.employee && (
 <Button
 variant="secondary"
 size="sm"
 onClick={() => {
 setProfileDraft({ name: user.name, email: user.email });
 setProfileErrors({});
 setProfileDialogOpen(true);
 }}
 >
 Edit profile
 </Button>
 )}
 {user.is_locked && (
 <Button variant="secondary" size="sm" onClick={() => setConfirm('unlock')}>
 Unlock
 </Button>
 )}
 {user.is_active ? (
 <Button
 variant="danger"
 size="sm"
 onClick={() => setConfirm('deactivate')}
 disabled={deactivate.isPending}
 >
 Deactivate
 </Button>
 ) : (
 <Button
 variant="primary"
 size="sm"
 onClick={() => activate.mutate()}
 disabled={activate.isPending}
 loading={activate.isPending}
 >
 Reactivate
 </Button>
 )}
 <Button
 variant="secondary"
 size="sm"
 onClick={() => setConfirm('reset')}
 disabled={reset.isPending}
 >
 Reset Password
 </Button>
 </div>
 }
 />

 <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 px-5 py-4">
 {/* Account */}
 <Panel title="Account" className="lg:col-span-2">
 <div className="grid grid-cols-2 gap-3 text-sm">
 <Field label="Status">
 <Chip
 variant={
 user.is_locked ? 'warning' : user.is_active ? 'success' : 'neutral'
 }
 >
 {user.is_locked ? 'Locked' : user.is_active ? 'Active' : 'Inactive'}
 </Chip>
 </Field>
 <Field label="Last Login">
 <span className="font-mono tabular-nums">
 {user.last_activity ? formatDateTime(user.last_activity) : 'Never'}
 </span>
 </Field>
 <Field label="Password Changed">
 <span className="font-mono tabular-nums">
 {user.password_changed_at
 ? formatDate(user.password_changed_at)
 : '—'}
 </span>
 </Field>
 <Field label="Must Change Password">
 {user.must_change_password ? 'Yes' : 'No'}
 </Field>
 <Field label="Role">
 <Select
 value={user.role?.id ?? ''}
 onChange={(e) => openRoleDialog(e.target.value)}
 disabled={changeRole.isPending || rolesQuery.isLoading || rolesQuery.isError}
 error={rolesQuery.isError ? 'Could not load roles. Retry below.' : undefined}
 aria-label="Role"
 >
 <option value="">—</option>
 {(rolesQuery.data?.roles ?? []).map((r) => (
 <option key={r.id} value={r.id}>
 {r.name}
 </option>
 ))}
 </Select>
 {rolesQuery.isError && (
 <Button variant="ghost" size="xs" onClick={() => rolesQuery.refetch()}>
 Retry role list
 </Button>
 )}
 </Field>
 <Field label="Created">
 <span className="font-mono tabular-nums">
 {user.created_at ? formatDate(user.created_at) : '—'}
 </span>
 </Field>
 </div>
 </Panel>

 {/* Linked Employee */}
 <Panel title="Linked Employee">
 {user.employee ? (
 <div className="text-sm space-y-2">
 <div>
 <Link
 to={`/hr/employees/${user.employee.id}`}
 className="font-medium text-accent hover:underline"
 >
 {user.employee.full_name}
 </Link>
 </div>
 <div className="text-muted">
 <span className="text-text-subtle">Employee No: </span>
 <span className="font-mono tabular-nums">
 {user.employee.employee_no}
 </span>
 </div>
 {user.employee.department && (
 <div className="text-muted">
 <span className="text-text-subtle">Department: </span>
 {user.employee.department.name}
 </div>
 )}
 </div>
 ) : (
 <p className="text-sm text-text-subtle">No linked employee.</p>
 )}
 </Panel>

 {/* Login history */}
 <Panel title="Recent Logins" className="lg:col-span-3">
 {user.recent_logins.length === 0 ? (
 <p className="text-sm text-text-subtle">No login attempts recorded.</p>
 ) : (
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>
 When
 </Th>
 <Th>
 Status
 </Th>
 <Th>
 IP
 </Th>
 <Th>User Agent</Th>
 <Th>
 Reason
 </Th>
 </tr>
 </thead>
 <tbody>
 {user.recent_logins.map((evt) => (
 <tr key={evt.id} className={trCls}>
 <Td mono className="text-secondary">
 {evt.created_at ? formatDateTime(evt.created_at) : '—'}
 </Td>
 <Td>
 <Chip variant={evt.status === 'success' ? 'success' : 'danger'}>
 {evt.status_label ?? evt.status}
 </Chip>
 </Td>
 <Td mono>{evt.ip_address ?? '—'}</Td>
 <Td className="text-muted">
 <span className="block max-w-[260px] truncate">
 {evt.user_agent ?? '—'}
 </span>
 </Td>
 <Td className="text-muted">{evt.reason ?? '—'}</Td>
 </tr>
 ))}
 </tbody>
 </table>
 )}
 </Panel>

 {/* Permission overrides — Series R / Task R2 */}
 <Panel title="Permission overrides" className="lg:col-span-3">
 <PermissionOverrides
 userId={id}
 isSystemAdminUser={user.role?.slug === 'system_admin'}
 />
 </Panel>
 </div>

 <ConfirmDialog
 isOpen={confirm === 'reset'}
 title="Reset password?"
 description="Generate a new temporary password. It will be shown once here so it can be shared through an approved channel if email delivery fails."
 confirmLabel={reset.isPending ? 'Resetting…' : 'Reset Password'}
 variant="primary"
 onConfirm={() => reset.mutate()}
 onClose={() => setConfirm(null)}
 pending={reset.isPending}
 />
 <ConfirmDialog
 isOpen={confirm === 'deactivate'}
 title="Deactivate account?"
 description="This will log out all active sessions and prevent the user from logging in."
 confirmLabel={deactivate.isPending ? 'Deactivating…' : 'Deactivate'}
 variant="danger"
 onConfirm={() => deactivate.mutate()}
 onClose={() => setConfirm(null)}
 pending={deactivate.isPending}
 />
 <ConfirmDialog
 isOpen={confirm === 'unlock'}
 title="Unlock account?"
 description="Reset the failed-login counter and clear any active lockout."
 confirmLabel={unlock.isPending ? 'Unlocking…' : 'Unlock'}
 variant="primary"
 onConfirm={() => unlock.mutate()}
 onClose={() => setConfirm(null)}
 pending={unlock.isPending}
 />

 <Modal
 isOpen={roleDialogOpen}
 onClose={() => {
 if (changeRole.isPending) return;
 setRoleDialogOpen(false);
 setPendingRoleId('');
 setRoleReason('');
 }}
 title="Confirm role change"
 size="sm"
 closeOnOverlayClick={!changeRole.isPending}
 >
 <div className="space-y-4">
 <p className="text-sm text-secondary">
 Change <span className="font-medium text-primary">{user.name}</span> to{' '}
 <span className="font-medium text-primary">
 {rolesQuery.data?.roles.find((role) => role.id === pendingRoleId)?.name ?? 'the selected role'}
 </span>?
 </p>
 <Textarea
 label="Reason"
 value={roleReason}
 onChange={(event) => setRoleReason(event.target.value)}
 placeholder="Explain why this role change is needed…"
 helper="At least 5 characters; recorded in the audit log."
 rows={3}
 required
 disabled={changeRole.isPending}
 />
 <ModalFooter>
 <Button
 variant="secondary"
 onClick={() => {
 setRoleDialogOpen(false);
 setPendingRoleId('');
 setRoleReason('');
 }}
 disabled={changeRole.isPending}
 >
 Cancel
 </Button>
 <Button
 variant="primary"
 onClick={() => {
 if (pendingRoleId && roleReason.trim().length >= 5) {
 changeRole.mutate({ roleId: pendingRoleId, reason: roleReason.trim() });
 }
 }}
 disabled={changeRole.isPending || roleReason.trim().length < 5 || !pendingRoleId}
 loading={changeRole.isPending}
 >
 Confirm change
 </Button>
 </ModalFooter>
 </div>
 </Modal>

 <Modal
 isOpen={profileDialogOpen}
 onClose={() => {
 if (!updateProfile.isPending) setProfileDialogOpen(false);
 }}
 title="Edit user profile"
 size="sm"
 closeOnOverlayClick={!updateProfile.isPending}
 >
 <div className="space-y-4">
 <p className="text-sm text-secondary">
 Standalone account details can be corrected here. Linked employee accounts must be updated from the employee profile.
 </p>
 <Input
 label="Full name"
 value={profileDraft.name}
 onChange={(event) => setProfileDraft((draft) => ({ ...draft, name: event.target.value }))}
 error={profileErrors.name}
 required
 disabled={updateProfile.isPending}
 />
 <Input
 label="Email"
 type="email"
 value={profileDraft.email}
 onChange={(event) => setProfileDraft((draft) => ({ ...draft, email: event.target.value }))}
 error={profileErrors.email}
 required
 disabled={updateProfile.isPending}
 />
 <ModalFooter>
 <Button variant="secondary" onClick={() => setProfileDialogOpen(false)} disabled={updateProfile.isPending}>
 Cancel
 </Button>
 <Button
 variant="primary"
 onClick={() => updateProfile.mutate()}
 disabled={updateProfile.isPending || profileDraft.name.trim().length === 0 || profileDraft.email.trim().length === 0}
 loading={updateProfile.isPending}
 >
 Save profile
 </Button>
 </ModalFooter>
 </div>
 </Modal>

 <Modal
 isOpen={!!tempPasswordModal}
 onClose={() => setTempPasswordModal(null)}
 title="Temporary password"
 size="sm"
 >
 <div className="space-y-3">
 <p className="text-sm text-secondary">
 Copy this one-time temporary password and share it through an approved channel. The user must change it on first login.
 </p>
 <code className="block rounded-md bg-elevated p-3 font-mono tabular-nums select-all">
 {tempPasswordModal}
 </code>
 <ModalFooter>
 <Button variant="primary" onClick={() => setTempPasswordModal(null)}>
 Done
 </Button>
 </ModalFooter>
 </div>
 </Modal>
 </div>
 );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
 return (
 <div className="flex flex-col gap-1">
 <span className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</span>
 <div>{children}</div>
 </div>
 );
}
