/**
 * WS-A.1 — Admin: Self-Service Portal Invites.
 *
 * Lists pending / used / expired invites and lets HR (or system_admin)
 * issue a new invite for any employee that does not yet have a portal
 * user account.
 */
import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Mail, X, UserPlus } from 'lucide-react';
import { AxiosError } from 'axios';
import {
  userInvitesApi,
  type CreateInvitePayload,
  type InviteListParams,
  type UserInvite,
} from '@/api/admin/user-invites';
import { employeesApi } from '@/api/hr/employees';
import { rolesApi } from '@/api/admin/roles';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import type { ApiValidationError } from '@/types';

const STATUS_OPTIONS: { value: NonNullable<InviteListParams['status']>; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'used',    label: 'Accepted' },
  { value: 'expired', label: 'Expired' },
  { value: 'revoked', label: 'Revoked' },
];

export default function PortalInvitesPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('auth.users.invite');

  const [filters, setFilters] = useState<InviteListParams>({
    page: 1,
    per_page: 25,
    status: 'pending',
  });
  const [inviteOpen, setInviteOpen] = useState(false);
  const [revoking, setRevoking] = useState<UserInvite | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['admin', 'invites', filters],
    queryFn: () => userInvitesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const revoke = useMutation({
    mutationFn: (id: string) => userInvitesApi.revoke(id),
    onSuccess: () => {
      toast.success('Invite revoked');
      qc.invalidateQueries({ queryKey: ['admin', 'invites'] });
      setRevoking(null);
    },
    onError: () => toast.error('Failed to revoke'),
  });

  const handleRevoke = () => {
    if (revoking) revoke.mutate(revoking.id);
  };

  return (
    <div>
      <PageHeader
        title="Self-Service Invites"
        subtitle={
          data ? (
            <>
              {data.meta.total} {filters.status} invite{data.meta.total === 1 ? '' : 's'}
            </>
          ) : undefined
        }
        actions={
          canManage ? (
            <Button variant="primary" size="sm" icon={<UserPlus size={12} />} onClick={() => setInviteOpen(true)}>
              New invite
            </Button>
          ) : null
        }
      />

      {/* Status filter */}
      <div className="px-5 pt-4 flex gap-2">
        {STATUS_OPTIONS.map((s) => (
          <button
            key={s.value}
            type="button"
            onClick={() => setFilters((f) => ({ ...f, status: s.value, page: 1 }))}
            className={
              'px-3 h-7 text-xs rounded-md border transition-colors ' +
              (filters.status === s.value
                ? 'bg-elevated border-default text-primary font-medium'
                : 'border-default text-muted hover:text-primary')
            }
          >
            {s.label}
          </button>
        ))}
      </div>

      <div className="px-5 py-4">
        {isLoading && !data ? (
          <SkeletonTable columns={4} rows={6} />
        ) : isError ? (
          <EmptyState
            icon="alert-circle"
            title="Failed to load invites"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        ) : !data || data.data.length === 0 ? (
          <EmptyState
            icon="inbox"
            title={`No ${filters.status} invites`}
            description={
              filters.status === 'pending'
                ? 'Issue an invite from the employee detail page or via the "New invite" button.'
                : undefined
            }
          />
        ) : (
          <table className="w-full text-sm">
            <thead className="text-left text-muted text-xs">
              <tr>
                <th className="py-2">Employee</th>
                <th>Email</th>
                <th>Role</th>
                <th>Expires</th>
                <th>Issued by</th>
                <th className="text-right pr-2">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-default">
              {data.data.map((row) => (
                <tr key={row.id}>
                  <td className="py-2">
                    {row.employee ? (
                      <Link to={`/hr/employees/${row.employee.id}`} className="hover:text-primary">
                        <div>{row.employee.full_name}</div>
                        <div className="font-mono text-xs text-muted">{row.employee.employee_no}</div>
                      </Link>
                    ) : '—'}
                  </td>
                  <td>{row.email}</td>
                  <td>{row.role?.name ?? <span className="text-muted">(position default)</span>}</td>
                  <td>{row.expires_at ? formatDateTime(row.expires_at) : '—'}</td>
                  <td>{row.inviter?.name ?? '—'}</td>
                  <td className="text-right pr-2">
                    {row.used_at ? (
                      <Chip variant="success">Accepted</Chip>
                    ) : row.is_expired ? (
                      <Chip variant="warning">Expired</Chip>
                    ) : (
                      <span className="inline-flex items-center gap-2">
                        <Chip variant="info">Pending</Chip>
                        {canManage && (
                          <Button
                            variant="ghost"
                            size="sm"
                            icon={<X size={12} />}
                            onClick={() => setRevoking(row)}
                            aria-label={`Revoke invite for ${row.email}`}
                          >
                            Revoke
                          </Button>
                        )}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <NewInviteModal isOpen={inviteOpen} onClose={() => setInviteOpen(false)} />

      <ConfirmDialog
        isOpen={revoking !== null}
        onClose={() => setRevoking(null)}
        title="Revoke invite?"
        description={
          revoking
            ? `The invite for ${revoking.email} will become unusable. This action cannot be undone.`
            : ''
        }
        confirmLabel="Revoke"
        variant="danger"
        onConfirm={handleRevoke}
        pending={revoke.isPending}
      />
    </div>
  );
}

interface NewInviteFormState {
  employee_id: string;
  email: string;
  role_id: string;
}

function NewInviteModal({ isOpen, onClose }: { isOpen: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState<NewInviteFormState>({ employee_id: '', email: '', role_id: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { data: employees } = useQuery({
    queryKey: ['admin', 'invites', 'employees-without-user'],
    queryFn: () => employeesApi.list({ per_page: 200 }),
    enabled: isOpen,
  });

  const { data: roles } = useQuery({
    queryKey: ['admin', 'invites', 'roles'],
    queryFn: () => rolesApi.list({ per_page: 100, sort: 'name' }),
    enabled: isOpen,
  });

  // Filter out employees that already have a portal user account.
  const eligibleEmployees = (employees?.data ?? []).filter((e) => !e.user);

  const createMutation = useMutation({
    mutationFn: (data: CreateInvitePayload) => userInvitesApi.create(data),
    onSuccess: (invite) => {
      toast.success(`Invite sent to ${invite.email}`);
      qc.invalidateQueries({ queryKey: ['admin', 'invites'] });
      onClose();
      setForm({ employee_id: '', email: '', role_id: '' });
      setErrors({});
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      const validationErrors = err.response?.data?.errors;
      if (validationErrors) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(validationErrors)) flat[k] = v[0] ?? '';
        setErrors(flat);
      } else {
        toast.error(err.response?.data?.message ?? 'Failed to send invite');
      }
    },
  });

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setErrors({});
    const payload: CreateInvitePayload = {
      employee_id: form.employee_id,
      email: form.email.trim(),
    };
    if (form.role_id) payload.role_id = form.role_id;
    createMutation.mutate(payload);
  };

  const employeeChoice = eligibleEmployees.find((e) => e.id === form.employee_id);
  const defaultEmail = employeeChoice?.contact?.email ?? null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={<span className="inline-flex items-center gap-2"><Mail size={14} /> Invite to self-service portal</span>}>
      <form onSubmit={onSubmit} className="flex flex-col gap-3">
        <Select
          label="Employee"
          value={form.employee_id}
          onChange={(e) => {
            const next = e.target.value;
            const emp = eligibleEmployees.find((x) => x.id === next);
            setForm((f) => ({
              ...f,
              employee_id: next,
              email: f.email || (emp?.contact?.email ?? ''),
            }));
          }}
          required
          error={errors.employee_id}
          helper={
            eligibleEmployees.length === 0
              ? 'All employees already have a portal account.'
              : undefined
          }
        >
          <option value="">Pick an employee…</option>
          {eligibleEmployees.map((emp) => (
            <option key={emp.id} value={emp.id}>
              {emp.full_name} — {emp.employee_no}
            </option>
          ))}
        </Select>

        <Input
          type="email"
          label="Portal email"
          value={form.email}
          onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
          required
          error={errors.email}
          helper={defaultEmail ? `Default from employee record: ${defaultEmail}` : undefined}
        />

        <Select
          label="Role"
          value={form.role_id}
          onChange={(e) => setForm((f) => ({ ...f, role_id: e.target.value }))}
          error={errors.role_id}
          helper="Leave blank to use the position's default role."
        >
          <option value="">(use position default)</option>
          {(roles?.data ?? []).map((r) => (
            <option key={r.id} value={r.id}>{r.name}</option>
          ))}
        </Select>

        <div className="flex justify-end gap-2 mt-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={createMutation.isPending}>
            Send invite
          </Button>
        </div>
      </form>
    </Modal>
  );
}
