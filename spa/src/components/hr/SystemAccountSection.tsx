import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import {
  Button,
  Chip,
  ConfirmDialog,
  Panel,
  SkeletonBlock,
  EmptyState,
} from '@/components/ui';
import { usePermission } from '@/hooks/usePermission';
import { employeeAccountsApi } from '@/api/hr/employee-accounts';
import { CreateAccountModal } from './CreateAccountModal';
import type { EmployeeAccountStatus } from '@/types/hr';

interface Props {
  employeeId: string;
  suggestedEmail?: string;
}

/**
 * U1 — System Account section. Mounted on the Employee detail page.
 * Five-state aware (loading skeleton, error retry, empty no-account,
 * data active-account, refetching opacity).
 */
export function SystemAccountSection({ employeeId, suggestedEmail }: Props) {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [showCreate, setShowCreate] = useState(false);
  const [confirm, setConfirm] = useState<null | 'reset' | 'deactivate'>(null);

  const { data, isLoading, isError, isFetching } = useQuery<EmployeeAccountStatus>({
    queryKey: ['employee-account', employeeId],
    queryFn: () => employeeAccountsApi.status(employeeId),
    enabled: can('hr.employees.account_status'),
  });

  const reset = useMutation({
    mutationFn: () => employeeAccountsApi.resetPassword(employeeId),
    onSuccess: (r: { message: string; sent_to: string | null }) => {
      toast.success(r.message);
      setConfirm(null);
      queryClient.invalidateQueries({ queryKey: ['employee-account', employeeId] });
    },
    onError: () => toast.error('Failed to reset password.'),
  });

  const deactivate = useMutation({
    mutationFn: () => employeeAccountsApi.deactivate(employeeId),
    onSuccess: () => {
      toast.success('Account deactivated.');
      setConfirm(null);
      queryClient.invalidateQueries({ queryKey: ['employee-account', employeeId] });
      queryClient.invalidateQueries({ queryKey: ['employees'] });
    },
    onError: () => toast.error('Failed to deactivate account.'),
  });

  if (!can('hr.employees.account_status')) return null;

  return (
    <Panel
      title="System Account"
      meta={isFetching && !isLoading ? <span className="text-xs text-muted">Refreshing…</span> : null}
    >
      {/* LOADING */}
      {isLoading && (
        <div className="space-y-2">
          <SkeletonBlock className="h-3 w-32" />
          <SkeletonBlock className="h-3 w-48" />
          <SkeletonBlock className="h-3 w-40" />
        </div>
      )}

      {/* ERROR */}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load account status"
          description="An error occurred while loading the system-account info."
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() =>
                queryClient.invalidateQueries({ queryKey: ['employee-account', employeeId] })
              }
            >
              Retry
            </Button>
          }
        />
      )}

      {/* EMPTY — no account yet */}
      {data && !data.account_exists && (
        <div className="space-y-3">
          <p className="text-sm text-secondary">This employee does not have a system login.</p>
          {can('hr.employees.provision_account') && (
            <Button variant="primary" size="sm" onClick={() => setShowCreate(true)}>
              Create Account
            </Button>
          )}
        </div>
      )}

      {/* DATA */}
      {data && data.account_exists && (
        <div className="space-y-3 text-sm">
          <div className="flex flex-wrap items-center gap-3">
            <Chip variant={data.is_active ? (data.is_locked ? 'warning' : 'success') : 'neutral'}>
              {data.is_active ? (data.is_locked ? 'Locked' : 'Active') : 'Inactive'}
            </Chip>
            <span className="text-muted">
              Last login:{' '}
              <span className="font-mono tabular-nums text-primary">
                {data.last_login_at ? new Date(data.last_login_at).toLocaleString() : 'Never'}
              </span>
            </span>
          </div>
          <div>
            <span className="text-muted">Email: </span>
            <span className="font-mono tabular-nums">{data.email}</span>
          </div>
          <div>
            <span className="text-muted">Role: </span>
            <span>{data.role?.name ?? '—'}</span>
          </div>
          {data.must_change_password && (
            <div className="text-xs text-warning">
              Force-change password is enabled — user will reset on next login.
            </div>
          )}
          <div className="flex gap-2 pt-2">
            {can('hr.employees.reset_password') && (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => setConfirm('reset')}
                disabled={reset.isPending}
              >
                Reset Password
              </Button>
            )}
            {can('hr.employees.deactivate_account') && data.is_active && (
              <Button
                variant="danger"
                size="sm"
                onClick={() => setConfirm('deactivate')}
                disabled={deactivate.isPending}
              >
                Deactivate Account
              </Button>
            )}
          </div>
        </div>
      )}

      <CreateAccountModal
        isOpen={showCreate}
        onClose={() => setShowCreate(false)}
        employeeId={employeeId}
        suggestedEmail={suggestedEmail}
      />

      <ConfirmDialog
        isOpen={confirm === 'reset'}
        title="Reset password?"
        description="Generate a new temporary password and email it to the user. They will be required to change it on next login."
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
    </Panel>
  );
}
