import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Avatar } from '@/components/ui/Avatar';
import { Button, Chip, ConfirmDialog, EmptyState, FilterBar, Select } from '@/components/ui';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { PageHeader } from '@/components/layout/PageHeader';
import { Tag } from '@/components/ui/Tag';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import {
  profileUpdateRequestsApi,
  type ProfileUpdateRequestStatus,
  type ProfileUpdateReviewItem,
} from '@/api/hr/profile-update-requests';

const FIELD_LABELS: Record<string, string> = {
  mobile_number: 'Mobile',
  email: 'Email',
  street_address: 'Street',
  barangay: 'Barangay',
  city: 'City',
  province: 'Province',
  zip_code: 'ZIP code',
  emergency_contact_name: 'Emergency contact',
  emergency_contact_relation: 'Emergency relation',
  emergency_contact_phone: 'Emergency phone',
  bank_name: 'Bank',
  bank_account_no: 'Bank account number',
};

const STATUS_CHIP: Record<ProfileUpdateRequestStatus, 'warning' | 'info' | 'success' | 'danger'> = {
  pending: 'warning',
  pending_finance: 'info',
  approved: 'success',
  rejected: 'danger',
};

/** U3 (HR side) — review queue for employee-initiated profile changes. */
export default function ProfileUpdateRequestsPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canFinance = can('hr.profile_updates.finance_review');
  const canViewEmployees = can('hr.employees.view');
  const canReviewHr = can('hr.employees.edit');
  const financeOnly = canFinance && !canViewEmployees;
  const defaultStatus: ProfileUpdateRequestStatus = financeOnly ? 'pending_finance' : 'pending';
  const [status, setStatus] = useState<ProfileUpdateRequestStatus>(defaultStatus);
  const [page, setPage] = useState(1);
  const [confirm, setConfirm] = useState<
    | null
    | { kind: 'approve' | 'reject'; stage: 'hr' | 'finance'; row: ProfileUpdateReviewItem }
  >(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'profile-update-requests', status, page],
    queryFn: () => profileUpdateRequestsApi.list({ status, ...(page > 1 ? { page } : {}) }),
    placeholderData: (prev) => prev,
  });
  const { data: options } = useQuery({
    queryKey: ['hr', 'profile-update-request-options'],
    queryFn: profileUpdateRequestsApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const statusLabels = new Map((options?.statuses ?? []).map((item) => [item.value, item.label]));
  const selectableStatuses = (options?.statuses ?? [])
    .filter((item) => !financeOnly || item.value !== 'pending')
    .map(({ value, label }) => ({ value, label }));

  const review = useMutation({
    mutationFn: (args: { id: string; action: 'approve' | 'reject'; stage: 'hr' | 'finance' }) =>
      args.stage === 'finance'
        ? profileUpdateRequestsApi.financeReview(args.id, args.action)
        : profileUpdateRequestsApi.review(args.id, args.action),
    onSuccess: (_data, vars) => {
      toast.success(vars.action === 'approve' ? 'Request approved.' : 'Request rejected.');
      setConfirm(null);
      queryClient.invalidateQueries({ queryKey: ['hr', 'profile-update-requests'] });
    },
    onError: (error) => reportMutationError(error, 'Failed to update request.'),
  });

  const columns: Column<ProfileUpdateReviewItem>[] = [
    {
      key: 'employee',
      header: 'Employee',
      primary: true,
      className: 'min-w-[210px]',
      cell: (row) => (
        <div className="flex min-w-0 items-center gap-2.5">
          <Avatar
            name={row.employee?.full_name ?? 'Employee unavailable'}
            identityKey={row.employee?.id ?? row.id}
            size="sm"
          />
          <div className="min-w-0">
            {row.employee && canViewEmployees ? (
              <Link to={`/hr/employees/${row.employee.id}`} className="block truncate text-link hover:underline">
                {row.employee.full_name}
              </Link>
            ) : (
              <span className="block truncate font-medium text-primary">
                {row.employee?.full_name ?? 'Employee unavailable'}
              </span>
            )}
            <span className="block truncate text-caption text-muted">
              {row.employee?.employee_no ?? 'No employee number'}
              {canViewEmployees && row.employee?.department ? ` · ${row.employee.department.name}` : ''}
            </span>
          </div>
        </div>
      ),
    },
    {
      key: 'category',
      header: 'Change type',
      className: 'w-32',
      cell: (row) => (
        <Tag categoryKey={row.requires_finance ? 'bank-account' : 'employee-profile'}>
          {row.requires_finance ? 'Banking' : 'Profile'}
        </Tag>
      ),
    },
    {
      key: 'fields',
      header: 'Fields',
      className: 'w-24',
      cell: (row) => <span className="tabular-nums text-secondary">{Object.keys(row.changes).length}</span>,
    },
    {
      key: 'requested',
      header: 'Requested',
      className: 'min-w-[180px]',
      cell: (row) => (
        <div className="min-w-0">
          <span className="block truncate">{row.requester?.name ?? 'Employee request'}</span>
          <span className="block text-caption text-muted">
            {row.created_at ? formatDateTime(row.created_at) : 'Date unavailable'}
          </span>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      className: 'w-36',
      cell: (row) => <Chip variant={STATUS_CHIP[row.status]}>{statusLabels.get(row.status) ?? row.status}</Chip>,
    },
  ];

  const changeStatus = (value: unknown) => {
    setStatus((value as ProfileUpdateRequestStatus) || defaultStatus);
    setPage(1);
  };

  return (
    <div>
      <PageHeader
        title={financeOnly ? 'Bank Account Changes' : 'Profile Change Requests'}
        subtitle={data ? `${data.meta.total} requests` : undefined}
      />

      <FilterBar
        searchable={false}
        resultCount={data?.meta.total}
        actions={(
          <Select
            aria-label="Status"
            value={status}
            searchable
            fieldSize="md"
            containerClassName="min-w-[190px]"
            onChange={(event) => changeStatus(event.target.value)}
          >
            {selectableStatuses.map(({ value, label }) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </Select>
        )}
      />

      <div className="p-5 pt-4">
        {isError && !data ? (
          <EmptyState
            icon="alert-circle"
            title="Failed to load requests"
            description="Please try again."
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        ) : (
          <DataTable
            columns={columns}
            data={data?.data ?? []}
            meta={data?.meta}
            onPageChange={setPage}
            loading={isLoading && !data}
            density="compact"
            expandOnRowClick
            renderExpanded={(row) => {
              const stage = row.status === 'pending_finance' ? 'finance' : 'hr';
              const canAct = row.status === 'pending'
                ? canReviewHr
                : row.status === 'pending_finance' && canFinance;
              return (
                <div className="grid gap-4 md:grid-cols-[minmax(0,1.2fr)_minmax(220px,0.8fr)]">
                  <section aria-label="Requested changes">
                    <h2 className="mb-2 text-label font-medium text-primary">Requested changes</h2>
                    <dl className="divide-y divide-subtle border-t border-subtle">
                      {Object.entries(row.changes).map(([key, value]) => (
                        <div key={key} className="grid grid-cols-[minmax(8rem,0.8fr)_minmax(0,1.2fr)] gap-3 py-2">
                          <dt className="text-caption text-muted">{FIELD_LABELS[key] ?? key}</dt>
                          <dd className="break-words font-mono tabular-nums text-secondary">
                            {value ?? <em className="font-sans text-subtle">empty</em>}
                          </dd>
                        </div>
                      ))}
                    </dl>
                  </section>

                  <section className="space-y-3" aria-label="Review context">
                    {row.note && <p><span className="mr-2 text-caption text-muted">Employee note</span>{row.note}</p>}
                    {row.review_remarks && <p><span className="mr-2 text-caption text-muted">HR remarks</span>{row.review_remarks}</p>}
                    {row.finance_remarks && <p><span className="mr-2 text-caption text-muted">Finance remarks</span>{row.finance_remarks}</p>}
                    {row.status === 'pending_finance' && row.reviewer && (
                      <p><span className="mr-2 text-caption text-muted">HR approved by</span>{row.reviewer.name}</p>
                    )}
                    {row.status === 'pending_finance' && !canFinance && (
                      <p className="text-caption text-muted">Awaiting Finance Officer approval.</p>
                    )}
                    {canAct && (
                      <div className="flex flex-wrap justify-end gap-2 border-t border-subtle pt-3">
                        <Button
                          variant="danger"
                          size="sm"
                          onClick={() => setConfirm({ kind: 'reject', stage, row })}
                          disabled={review.isPending}
                        >
                          Reject
                        </Button>
                        <Button
                          variant="primary"
                          size="sm"
                          onClick={() => setConfirm({ kind: 'approve', stage, row })}
                          disabled={review.isPending}
                          loading={review.isPending && confirm?.row.id === row.id}
                        >
                          {stage === 'finance' ? 'Finance Approve' : row.requires_finance ? 'HR Approve' : 'Approve'}
                        </Button>
                      </div>
                    )}
                  </section>
                </div>
              );
            }}
            emptyState={(
              <EmptyState
                size="compact"
                icon="inbox"
                title="No requests"
                description={`There are no ${statusLabels.get(status)?.toLowerCase() ?? status} profile update requests.`}
              />
            )}
          />
        )}
      </div>

      <ConfirmDialog
        isOpen={confirm !== null}
        title={`${confirm?.kind === 'approve' ? 'Approve' : 'Reject'} ${confirm?.row.employee?.full_name ?? 'profile change request'}?`}
        description={
          confirm?.kind === 'reject'
            ? 'The employee will see the rejection on their next visit.'
            : confirm?.stage === 'finance'
              ? 'Finance approval applies the new bank account to the employee record.'
              : confirm?.row.requires_finance
                ? 'HR approval records your sign-off; the change still needs Finance approval before it applies.'
                : 'The whitelisted fields will be applied to the employee record.'
        }
        confirmLabel={review.isPending ? '...' : confirm?.kind === 'approve' ? 'Approve' : 'Reject'}
        variant={confirm?.kind === 'approve' ? 'primary' : 'danger'}
        onConfirm={() => {
          if (confirm) review.mutate({ id: confirm.row.id, action: confirm.kind, stage: confirm.stage });
        }}
        onClose={() => setConfirm(null)}
        pending={review.isPending}
      />
    </div>
  );
}
