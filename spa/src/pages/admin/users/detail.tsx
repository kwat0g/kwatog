import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Button,
  Chip,
  ConfirmDialog,
  EmptyState,
  Panel,
  Select,
  SkeletonDetail,
} from '@/components/ui';
import { PageHeader } from '@/components/layout/PageHeader';
import { adminUsersApi } from '@/api/admin/users';
import { client } from '@/api/client';
import type { AdminUserDetail } from '@/types/admin';
import { PermissionOverrides } from './_components/PermissionOverrides';

interface RoleOption { id: string; name: string }
interface RolesResponse { data: RoleOption[] }

/** U2 — Admin > User detail page. */
export default function AdminUserDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const [confirm, setConfirm] = useState<null | 'reset' | 'deactivate' | 'unlock'>(null);

  const userQuery = useQuery<AdminUserDetail>({
    queryKey: ['admin-user', id],
    queryFn: () => adminUsersApi.show(id),
    enabled: !!id,
  });

  const rolesQuery = useQuery<RolesResponse>({
    queryKey: ['admin-roles-list'],
    queryFn: () => client.get('/admin/roles').then((r) => r.data),
    staleTime: 60_000,
  });

  const reset = useMutation({
    mutationFn: () => adminUsersApi.resetPassword(id),
    onSuccess: (r) => {
      toast.success(r.message);
      setConfirm(null);
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
    mutationFn: (roleId: string) => adminUsersApi.changeRole(id, roleId),
    onSuccess: () => {
      toast.success('Role updated.');
      queryClient.invalidateQueries({ queryKey: ['admin-user', id] });
      queryClient.invalidateQueries({ queryKey: ['admin-users'] });
    },
    onError: () => toast.error('Failed to update role.'),
  });

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
                {user.last_activity ? new Date(user.last_activity).toLocaleString() : 'Never'}
              </span>
            </Field>
            <Field label="Password Changed">
              <span className="font-mono tabular-nums">
                {user.password_changed_at
                  ? new Date(user.password_changed_at).toLocaleDateString()
                  : '—'}
              </span>
            </Field>
            <Field label="Must Change Password">
              {user.must_change_password ? 'Yes' : 'No'}
            </Field>
            <Field label="Role">
              <Select
                value={user.role?.id ?? ''}
                onChange={(e) => changeRole.mutate(e.target.value)}
                disabled={changeRole.isPending}
                aria-label="Role"
              >
                <option value="">—</option>
                {(rolesQuery.data?.data ?? []).map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Created">
              <span className="font-mono tabular-nums">
                {user.created_at ? new Date(user.created_at).toLocaleDateString() : '—'}
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
                <span className="text-subtle">Employee No: </span>
                <span className="font-mono tabular-nums">
                  {user.employee.employee_no}
                </span>
              </div>
              {user.employee.department && (
                <div className="text-muted">
                  <span className="text-subtle">Department: </span>
                  {user.employee.department.name}
                </div>
              )}
            </div>
          ) : (
            <p className="text-sm text-subtle">No linked employee.</p>
          )}
        </Panel>

        {/* Login history */}
        <Panel title="Recent Logins" className="lg:col-span-3">
          {user.recent_logins.length === 0 ? (
            <p className="text-sm text-subtle">No login attempts recorded.</p>
          ) : (
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-default">
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                    When
                  </th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                    Status
                  </th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                    IP
                  </th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                    User Agent
                  </th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                    Reason
                  </th>
                </tr>
              </thead>
              <tbody>
                {user.recent_logins.map((evt) => (
                  <tr key={evt.id} className="h-8 border-b border-subtle">
                    <td className="px-2.5 font-mono tabular-nums text-secondary">
                      {evt.created_at ? new Date(evt.created_at).toLocaleString() : '—'}
                    </td>
                    <td className="px-2.5">
                      <Chip variant={evt.status === 'success' ? 'success' : 'danger'}>
                        {evt.status}
                      </Chip>
                    </td>
                    <td className="px-2.5 font-mono tabular-nums">{evt.ip_address ?? '—'}</td>
                    <td className="px-2.5 text-muted">
                      <span className="block max-w-[260px] truncate">
                        {evt.user_agent ?? '—'}
                      </span>
                    </td>
                    <td className="px-2.5 text-muted">{evt.reason ?? '—'}</td>
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
        description="Generate a new temporary password and email it to the user."
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
