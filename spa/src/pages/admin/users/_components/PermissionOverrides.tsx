import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Radio } from '@/components/ui/Radio';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Tooltip } from '@/components/ui/Tooltip';
import { CanDo } from '@/components/guards/CanDo';
import { permissionsApi } from '@/api/admin/permissions';
import { userOverridesApi } from '@/api/admin/user-overrides';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError } from '@/types';
import type {
  CreateUserPermissionOverrideData,
  PermissionOverrideType,
  UserPermissionOverride,
} from '@/types/admin';

interface PermissionOverridesSectionProps {
  userId: string;
  /** When true (current user's role is system_admin), show banner — overrides do not apply. */
  isSystemAdminUser?: boolean;
}

/**
 * Series R — Task R2.
 *
 * Mounted on the User detail page. Lists active overrides for the user and
 * provides Add/Remove actions gated by `admin.users.manage_permissions`.
 *
 * Server is the source of truth: the list endpoint already excludes expired
 * overrides; create returns the merged record.
 */
export function PermissionOverrides({
  userId,
  isSystemAdminUser,
}: PermissionOverridesSectionProps) {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canManage = can('admin.users.manage_permissions');

  const [showAdd, setShowAdd] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState<UserPermissionOverride | null>(null);

  const list = useQuery({
    queryKey: ['admin', 'users', userId, 'overrides'],
    queryFn: () => userOverridesApi.list(userId),
    enabled: !!userId && canManage,
  });

  const remove = useMutation({
    mutationFn: (override: UserPermissionOverride) =>
      userOverridesApi.delete(userId, override.id),
    onSuccess: () => {
      toast.success('Override removed.');
      setConfirmRemove(null);
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId, 'overrides'] });
      queryClient.invalidateQueries({ queryKey: ['admin-user', userId] });
    },
    onError: () => toast.error('Failed to remove override.'),
  });

  return (
    <div>
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-medium">Permission overrides</h3>
        <CanDo permission="admin.users.manage_permissions">
          <Button
            variant="secondary"
            size="sm"
            icon={<Plus size={12} />}
            onClick={() => setShowAdd(true)}
            disabled={isSystemAdminUser}
            aria-label="Add override"
          >
            Add override
          </Button>
        </CanDo>
      </div>

      {!canManage && (
        <p className="text-sm text-subtle">
          You don't have permission to view or manage user overrides.
        </p>
      )}

      {canManage && isSystemAdminUser && (
        <Chip variant="warning" className="mb-3">
          System Administrator bypasses overrides — entries here have no effect.
        </Chip>
      )}

      {canManage && list.isLoading && (
        <div className="flex items-center gap-2 py-6 text-muted">
          <Spinner /> <span className="text-sm">Loading overrides…</span>
        </div>
      )}

      {canManage && list.isError && (
        <EmptyState
          icon="alert-circle"
          title="Could not load overrides"
          description="Try refreshing the page."
          action={
            <Button variant="secondary" onClick={() => list.refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {canManage && list.data && list.data.length === 0 && (
        <p className="text-sm text-subtle">
          No active overrides. The user inherits exactly their role's permissions.
        </p>
      )}

      {canManage && list.data && list.data.length > 0 && (
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b border-default">
              <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                Permission
              </th>
              <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                Type
              </th>
              <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                Granted by
              </th>
              <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                Reason
              </th>
              <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                Expires
              </th>
              <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium" />
            </tr>
          </thead>
          <tbody>
            {list.data.map((o) => (
              <tr key={o.id} className="h-8 border-b border-subtle hover:bg-subtle">
                <td className="px-2.5">
                  <div className="font-medium">{o.permission.name}</div>
                  <div className="text-xs font-mono text-muted">{o.permission.slug}</div>
                </td>
                <td className="px-2.5">
                  <Chip variant={o.type === 'grant' ? 'success' : 'danger'}>
                    {o.type === 'grant' ? 'Granted' : 'Revoked'}
                  </Chip>
                </td>
                <td className="px-2.5">
                  {o.granted_by ? (
                    <span>
                      {o.granted_by.name}{' '}
                      <span className="text-muted font-mono">({o.granted_by.email})</span>
                    </span>
                  ) : (
                    <span className="text-muted">—</span>
                  )}
                </td>
                <td className="px-2.5 text-muted">
                  <Tooltip content={o.reason}>
                    <span className="block max-w-[260px] truncate">{o.reason}</span>
                  </Tooltip>
                </td>
                <td className="px-2.5 font-mono tabular-nums text-secondary">
                  {o.expires_at ? new Date(o.expires_at).toLocaleString() : 'No expiry'}
                </td>
                <td className="px-2.5 text-right" onClick={(e) => e.stopPropagation()}>
                  <CanDo permission="admin.users.manage_permissions">
                    <Button
                      variant="ghost"
                      size="sm"
                      icon={<Trash2 size={12} />}
                      onClick={() => setConfirmRemove(o)}
                      aria-label={`Remove ${o.permission.slug} override`}
                    >
                      Remove
                    </Button>
                  </CanDo>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <AddOverrideModal
        userId={userId}
        isOpen={showAdd}
        onClose={() => setShowAdd(false)}
      />

      <ConfirmDialog
        isOpen={!!confirmRemove}
        title="Remove override?"
        description={
          confirmRemove
            ? `The user will no longer have the ${confirmRemove.type === 'grant' ? 'extra' : 'revoked'} permission "${confirmRemove.permission.name}". They'll fall back to their role's defaults.`
            : ''
        }
        confirmLabel={remove.isPending ? 'Removing…' : 'Remove'}
        variant="danger"
        onConfirm={() => {
          if (confirmRemove) remove.mutate(confirmRemove);
        }}
        onClose={() => setConfirmRemove(null)}
        pending={remove.isPending}
      />
    </div>
  );
}

interface AddOverrideModalProps {
  userId: string;
  isOpen: boolean;
  onClose: () => void;
}

function AddOverrideModal({ userId, isOpen, onClose }: AddOverrideModalProps) {
  const queryClient = useQueryClient();
  const matrix = useQuery({
    queryKey: ['admin', 'permissions', 'matrix'],
    queryFn: permissionsApi.matrix,
    enabled: isOpen,
    staleTime: 60_000,
  });

  const [permissionSlug, setPermissionSlug] = useState('');
  const [type, setType] = useState<PermissionOverrideType>('grant');
  const [reason, setReason] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const flatPermissions = useMemo(() => {
    const rows = Object.values(matrix.data ?? {}).flat();
    return rows.sort((a, b) => a.slug.localeCompare(b.slug));
  }, [matrix.data]);

  const reset = () => {
    setPermissionSlug('');
    setType('grant');
    setReason('');
    setExpiresAt('');
    setErrors({});
  };

  const close = () => {
    reset();
    onClose();
  };

  const create = useMutation({
    mutationFn: (data: CreateUserPermissionOverrideData) =>
      userOverridesApi.create(userId, data),
    onSuccess: () => {
      toast.success('Override applied.');
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId, 'overrides'] });
      queryClient.invalidateQueries({ queryKey: ['admin-user', userId] });
      close();
    },
    onError: (error: AxiosError<ApiValidationError>) => {
      if (error.response?.status === 422 && error.response.data.errors) {
        const next: Record<string, string> = {};
        for (const [field, messages] of Object.entries(error.response.data.errors)) {
          next[field] = messages[0];
        }
        setErrors(next);
        toast.error('Please fix the errors below.');
        return;
      }
      toast.error('Failed to create override.');
    },
  });

  const submit = () => {
    if (!permissionSlug) {
      setErrors((p) => ({ ...p, permission_slug: 'Pick a permission.' }));
      return;
    }
    if (reason.trim().length < 5) {
      setErrors((p) => ({ ...p, reason: 'Reason must be at least 5 characters.' }));
      return;
    }
    create.mutate({
      permission_slug: permissionSlug,
      type,
      reason: reason.trim(),
      expires_at: expiresAt ? new Date(expiresAt).toISOString() : null,
    });
  };

  return (
    <Modal isOpen={isOpen} onClose={close} title="Add permission override" size="md">
      <div className="space-y-3 py-2">
        <Select
          label="Permission"
          value={permissionSlug}
          onChange={(e) => {
            setPermissionSlug(e.target.value);
            setErrors((p) => ({ ...p, permission_slug: '' }));
          }}
          error={errors.permission_slug}
          required
        >
          <option value="">Select a permission…</option>
          {flatPermissions.map((p) => (
            <option key={p.slug} value={p.slug}>
              [{p.module}] {p.slug} — {p.name}
            </option>
          ))}
        </Select>

        <div>
          <label className="text-xs text-muted font-medium block mb-1">Type</label>
          <div className="flex flex-col gap-2">
            <label className="flex items-start gap-2 cursor-pointer">
              <Radio
                name="override-type"
                value="grant"
                checked={type === 'grant'}
                onChange={() => setType('grant')}
                aria-label="Grant"
              />
              <span className="leading-tight">
                <span className="text-sm font-medium block">Grant</span>
                <span className="text-xs text-muted">
                  Add a permission this user's role does not include.
                </span>
              </span>
            </label>
            <label className="flex items-start gap-2 cursor-pointer">
              <Radio
                name="override-type"
                value="revoke"
                checked={type === 'revoke'}
                onChange={() => setType('revoke')}
                aria-label="Revoke"
              />
              <span className="leading-tight">
                <span className="text-sm font-medium block">Revoke</span>
                <span className="text-xs text-muted">
                  Remove a permission this user's role would otherwise grant.
                </span>
              </span>
            </label>
          </div>
        </div>

        <Textarea
          label="Reason"
          value={reason}
          onChange={(e) => {
            setReason(e.target.value);
            setErrors((p) => ({ ...p, reason: '' }));
          }}
          error={errors.reason}
          placeholder="Why is this override necessary? Logged in audit_logs."
          rows={3}
          required
        />
        <p className="text-xs text-muted -mt-1">{reason.length}/500</p>

        <Input
          label="Expires (optional)"
          type="datetime-local"
          value={expiresAt}
          onChange={(e) => setExpiresAt(e.target.value)}
          helper="Leave blank for a permanent override."
          error={errors.expires_at}
        />
      </div>

      <div className="flex justify-end gap-2 pt-3 border-t border-default">
        <Button variant="secondary" onClick={close} disabled={create.isPending}>
          Cancel
        </Button>
        <Button
          variant="primary"
          onClick={submit}
          loading={create.isPending}
          disabled={create.isPending}
        >
          {create.isPending ? 'Applying…' : 'Apply override'}
        </Button>
      </div>
    </Modal>
  );
}
