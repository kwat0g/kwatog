import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Button,
  Chip,
  ConfirmDialog,
  EmptyState,
  FilterBar,
  SkeletonTable,
  type FilterConfig,
} from '@/components/ui';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
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
  zip_code: 'Zip',
  emergency_contact_name: 'Emergency contact',
  emergency_contact_relation: 'Emergency relation',
  emergency_contact_phone: 'Emergency phone',
  bank_name: 'Bank',
  bank_account_no: 'Bank account no.',
};

const STATUS_CHIP: Record<ProfileUpdateRequestStatus, 'warning' | 'info' | 'success' | 'danger'> = {
  pending: 'warning',
  pending_finance: 'info',
  approved: 'success',
  rejected: 'danger',
};

const STATUS_LABEL: Record<ProfileUpdateRequestStatus, string> = {
  pending: 'pending HR',
  pending_finance: 'awaiting Finance',
  approved: 'approved',
  rejected: 'rejected',
};

/**
 * U3 (HR side) — review queue for employee-initiated profile changes.
 * Shows pending requests by default; HR can approve or reject inline.
 */
export default function ProfileUpdateRequestsPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canFinance = can('hr.profile_updates.finance_review');
  const [status, setStatus] = useState<ProfileUpdateRequestStatus>('pending');
  const [confirm, setConfirm] = useState<
    | null
    | { kind: 'approve' | 'reject'; stage: 'hr' | 'finance'; row: ProfileUpdateReviewItem }
  >(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'profile-update-requests', status],
    queryFn: () => profileUpdateRequestsApi.list({ status }),
    placeholderData: (prev) => prev,
  });

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
    onError: () => toast.error('Failed to update request.'),
  });

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: 'pending', label: 'Pending HR' },
        { value: 'pending_finance', label: 'Awaiting Finance' },
        { value: 'approved', label: 'Approved' },
        { value: 'rejected', label: 'Rejected' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Profile Change Requests"
        subtitle={data ? `${data.meta.total} requests` : undefined}
      />

      <FilterBar
        filters={filterConfig}
        values={{ status }}
        onFilter={(_k, v) => setStatus((v as ProfileUpdateRequestStatus) || 'pending')}
      />

      {isLoading && !data && <SkeletonTable columns={5} rows={6} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load requests"
          description="Please try again."
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No requests"
          description={`There are no ${status} profile update requests.`}
        />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 py-4 space-y-3">
          {data.data.map((row) => (
            <article
              key={row.id}
              className="border border-default rounded-md bg-canvas overflow-hidden"
            >
              <header className="flex items-center justify-between px-4 py-2 border-b border-default">
                <div className="flex items-center gap-3 text-sm">
                  {row.employee && (
                    <Link
                      to={`/hr/employees/${row.employee.id}`}
                      className="font-medium hover:underline"
                    >
                      {row.employee.full_name}
                    </Link>
                  )}
                  <span className="font-mono tabular-nums text-muted text-xs">
                    {row.employee?.employee_no}
                  </span>
                  <Chip variant={STATUS_CHIP[row.status]}>{STATUS_LABEL[row.status]}</Chip>
                  {row.requires_finance && (
                    <Chip variant="purple">Bank · dual approval</Chip>
                  )}
                </div>
                <div className="text-xs text-muted">
                  <span className="font-mono tabular-nums">
                    {row.created_at ? new Date(row.created_at).toLocaleString() : ''}
                  </span>
                </div>
              </header>

              <div className="px-4 py-3 space-y-1.5 text-xs">
                {Object.entries(row.changes).map(([key, value]) => (
                  <div key={key} className="flex">
                    <span className="text-muted w-40 shrink-0">
                      {FIELD_LABELS[key] ?? key}:
                    </span>
                    <span className="font-mono tabular-nums">{value ?? <em className="text-subtle">empty</em>}</span>
                  </div>
                ))}
                {row.note && (
                  <div className="flex pt-1.5">
                    <span className="text-muted w-40 shrink-0">Note:</span>
                    <span>{row.note}</span>
                  </div>
                )}
                {row.review_remarks && (
                  <div className="flex pt-1.5">
                    <span className="text-muted w-40 shrink-0">HR remarks:</span>
                    <span>{row.review_remarks}</span>
                  </div>
                )}
                {row.finance_remarks && (
                  <div className="flex pt-1.5">
                    <span className="text-muted w-40 shrink-0">Finance remarks:</span>
                    <span>{row.finance_remarks}</span>
                  </div>
                )}
                {row.status === 'pending_finance' && (
                  <div className="flex pt-1.5">
                    <span className="text-muted w-40 shrink-0">HR approved by:</span>
                    <span>{row.reviewer?.name ?? '—'}</span>
                  </div>
                )}
              </div>

              {/* HR leg */}
              {row.status === 'pending' && (
                <footer className="px-4 py-2 border-t border-default flex justify-end gap-2">
                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => setConfirm({ kind: 'reject', stage: 'hr', row })}
                    disabled={review.isPending}
                  >
                    Reject
                  </Button>
                  <Button
                    variant="primary"
                    size="sm"
                    onClick={() => setConfirm({ kind: 'approve', stage: 'hr', row })}
                    disabled={review.isPending}
                    loading={review.isPending && confirm?.row.id === row.id}
                  >
                    {row.requires_finance ? 'HR Approve' : 'Approve'}
                  </Button>
                </footer>
              )}

              {/* Finance leg — only the Finance Officer (or admin) can act */}
              {row.status === 'pending_finance' && (
                <footer className="px-4 py-2 border-t border-default flex items-center justify-end gap-2">
                  {!canFinance && (
                    <span className="text-xs text-muted mr-auto">Awaiting Finance Officer approval.</span>
                  )}
                  {canFinance && (
                    <>
                      <Button
                        variant="danger"
                        size="sm"
                        onClick={() => setConfirm({ kind: 'reject', stage: 'finance', row })}
                        disabled={review.isPending}
                      >
                        Reject
                      </Button>
                      <Button
                        variant="primary"
                        size="sm"
                        onClick={() => setConfirm({ kind: 'approve', stage: 'finance', row })}
                        disabled={review.isPending}
                        loading={review.isPending && confirm?.row.id === row.id}
                      >
                        Finance Approve
                      </Button>
                    </>
                  )}
                </footer>
              )}
            </article>
          ))}
        </div>
      )}

      <ConfirmDialog
        isOpen={confirm !== null}
        title={confirm?.kind === 'approve' ? 'Approve request?' : 'Reject request?'}
        description={
          confirm?.kind === 'reject'
            ? 'The employee will see the rejection on their next visit.'
            : confirm?.stage === 'finance'
            ? 'Finance approval applies the new bank account to the employee record.'
            : confirm?.row.requires_finance
            ? 'HR approval records your sign-off; the change still needs Finance approval before it applies.'
            : 'The whitelisted fields will be applied to the employee record.'
        }
        confirmLabel={
          review.isPending
            ? '...'
            : confirm?.kind === 'approve'
            ? 'Approve'
            : 'Reject'
        }
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
