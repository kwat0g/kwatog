import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Avatar } from '@/components/ui/Avatar';
import { Button, Chip, ConfirmDialog, EmptyState, FilterBar, Select } from '@/components/ui';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { PageHeader } from '@/components/layout/PageHeader';
import { Tag } from '@/components/ui/Tag';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import { useProfileUpdateRequestRealtime } from '@/hooks/useRealtimeRecord';
import { invalidateRealtimeChange } from '@/lib/realtime/invalidationMap';
import type { PaginatedResponse } from '@/types';
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
  useProfileUpdateRequestRealtime();
  const [searchParams] = useSearchParams();
  const awaitingMyAction = searchParams.get('signal') === 'awaiting_my_action';
  const canFinance = can('hr.profile_updates.finance_review');
  const canViewEmployees = can('hr.employees.view');
  const financeOnly = canFinance && !canViewEmployees;
  const [status, setStatus] = useState<ProfileUpdateRequestStatus | 'all'>('all');
  const [page, setPage] = useState(1);
  useEffect(() => {
    setStatus('all');
    setPage(1);
  }, [awaitingMyAction]);
  const [confirm, setConfirm] = useState<
    | null
    | { kind: 'approve' | 'reject'; stage: 'hr' | 'finance'; requestId: string }
  >(null);

  const { data, isLoading, isFetching, isError, refetch } = useQuery({
    queryKey: ['hr', 'profile-update-requests', awaitingMyAction ? undefined : status, page, awaitingMyAction],
    queryFn: () => profileUpdateRequestsApi.list(awaitingMyAction
      ? { signal: 'awaiting_my_action' }
      : { status, ...(page > 1 ? { page } : {}) }),
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
    onSuccess: (updated, vars) => {
      for (const [key, cached] of queryClient.getQueriesData<PaginatedResponse<ProfileUpdateReviewItem>>({
        queryKey: ['hr', 'profile-update-requests'],
      })) {
        if (!cached?.data.some((row) => row.id === updated.id)) continue;
        const awaitingMyActionList = key[4] === true;
        const filteredStatus = typeof key[2] === 'string' && key[2] !== 'all' ? key[2] : null;
        const actions = updated.available_actions;
        const stillActionable = !actions
          || actions.can_review_hr
          || actions.can_approve_finance
          || actions.can_reject_finance;
        const removeFromList = (awaitingMyActionList && !stillActionable)
          || (filteredStatus !== null && filteredStatus !== updated.status);
        const rows = removeFromList
          ? cached.data.filter((row) => row.id !== updated.id)
          : cached.data.map((row) => row.id === updated.id ? updated : row);
        const removed = rows.length !== cached.data.length;
        queryClient.setQueryData<PaginatedResponse<ProfileUpdateReviewItem>>(key, {
          ...cached,
          data: rows,
          meta: {
            ...cached.meta,
            total: Math.max(0, cached.meta.total - Number(removed)),
            from: rows.length > 0 ? cached.meta.from : null,
            to: rows.length > 0 ? (cached.meta.from ?? 1) + rows.length - 1 : null,
          },
        });
      }
      void invalidateRealtimeChange(queryClient, { entityType: 'profile_update_request', entityId: updated.id });
      toast.success(vars.action === 'approve' ? 'Request approved.' : 'Request rejected.');
      setConfirm(null);
    },
    onError: async (error, vars) => {
      if (error instanceof AxiosError && error.response?.status === 409) {
        await invalidateRealtimeChange(queryClient, { entityType: 'profile_update_request', entityId: vars.id });
        toast.error('This request changed while you were reviewing it. The queue has been refreshed.');
        setConfirm(null);
        return;
      }
      reportMutationError(error, 'Failed to update request.');
    },
  });

  const confirmedRow = confirm
    ? data?.data.find((row) => row.id === confirm.requestId)
    : undefined;
  const confirmationActionAvailable = Boolean(
    confirm && confirmedRow && !isFetching && (
      confirm.stage === 'hr'
        ? confirmedRow.available_actions?.can_review_hr === true
        : confirm.kind === 'approve'
          ? confirmedRow.available_actions?.can_approve_finance === true
          : confirmedRow.available_actions?.can_reject_finance === true
    ),
  );

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
    setStatus((value as ProfileUpdateRequestStatus | 'all') || 'all');
    setPage(1);
  };

  return (
    <div>
      <PageHeader
        title={awaitingMyAction ? 'Profile Changes Awaiting My Action' : financeOnly ? 'Bank Account Changes' : 'Profile Change Requests'}
        subtitle={data ? `${data.meta.total} requests` : undefined}
      />

      {!awaitingMyAction && <FilterBar
        searchable={false}
        resultCount={data?.meta.total}
        values={{ status }}
        onApplySavedView={(saved) => changeStatus(saved.status ?? 'all')}
        actions={(
          <Select
            aria-label="Status"
            value={status}
            searchable
            fieldSize="md"
            containerClassName="min-w-[190px]"
            onChange={(event) => changeStatus(event.target.value)}
          >
            <option value="all">All statuses</option>
            {selectableStatuses.map(({ value, label }) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </Select>
        )}
      />}

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
              const canApproveHr = row.available_actions?.can_review_hr === true;
              const canApproveFinance = row.available_actions?.can_approve_finance === true;
              const canRejectFinance = row.available_actions?.can_reject_finance === true;
              const canAct = canApproveHr || canApproveFinance || canRejectFinance;
              const stage = canApproveFinance || canRejectFinance ? 'finance' : 'hr';
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
                    {row.status === 'pending_finance' && !row.permissions?.review_finance && (
                      <p className="text-caption text-muted">Awaiting Finance Officer approval.</p>
                    )}
                    {canAct && (
                      <div className="flex flex-wrap justify-end gap-2 border-t border-subtle pt-3">
                        {(canApproveHr || canRejectFinance) && (
                          <Button
                            variant="danger"
                            size="sm"
                            onClick={() => setConfirm({ kind: 'reject', stage, requestId: row.id })}
                            disabled={review.isPending || isFetching}
                          >
                            Reject
                          </Button>
                        )}
                        {(canApproveHr || canApproveFinance) && (
                          <Button
                            variant="primary"
                            size="sm"
                            onClick={() => setConfirm({ kind: 'approve', stage, requestId: row.id })}
                            disabled={review.isPending || isFetching}
                            loading={review.isPending && confirm?.requestId === row.id}
                          >
                            {stage === 'finance' ? 'Finance Approve' : row.requires_finance ? 'HR Approve' : 'Approve'}
                          </Button>
                        )}
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
                description={status === 'all'
                  ? 'There are no profile update requests.'
                  : `There are no ${statusLabels.get(status)?.toLowerCase() ?? status} profile update requests.`}
              />
            )}
          />
        )}
      </div>

      <ConfirmDialog
        isOpen={confirm !== null}
        title={`${confirm?.kind === 'approve' ? 'Approve' : 'Reject'} ${confirmedRow?.employee?.full_name ?? 'profile change request'}?`}
        description={
          !confirmationActionAvailable && confirm
            ? 'This request changed or is being refreshed. Review the updated status before taking action.'
            : confirm?.kind === 'reject'
              ? 'The employee will see the rejection on their next visit.'
              : confirm?.stage === 'finance'
                ? 'Finance approval applies the new bank account to the employee record.'
                : confirmedRow?.requires_finance
                  ? 'HR approval records your sign-off; the change still needs Finance approval before it applies.'
                  : 'The whitelisted fields will be applied to the employee record.'
        }
        confirmLabel={review.isPending ? '...' : confirm?.kind === 'approve' ? 'Approve' : 'Reject'}
        variant={confirm?.kind === 'approve' ? 'primary' : 'danger'}
        onConfirm={() => {
          if (confirm && confirmedRow && confirmationActionAvailable) {
            review.mutate({ id: confirmedRow.id, action: confirm.kind, stage: confirm.stage });
          }
        }}
        onClose={() => setConfirm(null)}
        pending={review.isPending}
        disabled={!confirmationActionAvailable}
      />
    </div>
  );
}
