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
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import {
  salaryAdjustmentsApi,
  type SalaryAdjustmentItem,
  type SalaryAdjustmentStatus,
} from '@/api/hr/salary-adjustments';

const STATUS_CHIP: Record<SalaryAdjustmentStatus, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
};

/**
 * REC-03 — salary-adjustment maker-checker queue. HR requests a change; a
 * different checker (production_manager) and approver (VP/admin) sign off before
 * the new pay is applied. Direct employee edits can no longer change pay.
 */
export default function SalaryAdjustmentsPage() {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<SalaryAdjustmentStatus>('pending');
  const [confirm, setConfirm] = useState<
    null | { kind: 'approve' | 'reject'; row: SalaryAdjustmentItem }
  >(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'salary-adjustments', status],
    queryFn: () => salaryAdjustmentsApi.list({ status }),
    placeholderData: (prev) => prev,
  });

  const act = useMutation({
    mutationFn: (args: { id: string; action: 'approve' | 'reject' }) =>
      salaryAdjustmentsApi.act(args.id, args.action, args.action === 'reject' ? 'Rejected via queue' : undefined),
    onSuccess: (_d, vars) => {
      toast.success(vars.action === 'approve' ? 'Adjustment approved.' : 'Adjustment rejected.');
      setConfirm(null);
      queryClient.invalidateQueries({ queryKey: ['hr', 'salary-adjustments'] });
    },
    onError: () => toast.error('Failed to update adjustment.'),
  });

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
        { value: 'rejected', label: 'Rejected' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Salary Adjustments"
        subtitle={data ? `${data.meta.total} requests · maker-checker gated` : undefined}
      />

      <FilterBar
        filters={filterConfig}
        values={{ status }}
        onFilter={(_k, v) => setStatus((v as SalaryAdjustmentStatus) || 'pending')}
      />

      {isLoading && !data && <SkeletonTable columns={5} rows={6} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load adjustments"
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
          title="No adjustments"
          description={`There are no ${status} salary adjustments.`}
        />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 py-4 space-y-3">
          {data.data.map((row) => (
            <article key={row.id} className="border border-default rounded-md bg-canvas overflow-hidden">
              <header className="flex items-center justify-between px-4 py-2 border-b border-default">
                <div className="flex items-center gap-3 text-sm">
                  {row.employee && (
                    <Link to={`/hr/employees/${row.employee.id}`} className="font-medium hover:underline">
                      {row.employee.full_name}
                    </Link>
                  )}
                  <span className="font-mono tabular-nums text-muted text-xs">{row.employee?.employee_no}</span>
                  <Chip variant={STATUS_CHIP[row.status]}>{row.status}</Chip>
                </div>
                <div className="text-xs text-muted">
                  <span className="font-mono tabular-nums">
                    {row.created_at ? formatDateTime(row.created_at) : ''}
                  </span>
                </div>
              </header>

              <div className="px-4 py-3 space-y-1.5 text-xs">
                <div className="flex">
                  <span className="text-muted w-40 shrink-0">Monthly salary:</span>
                  <span className="font-mono tabular-nums">
                    {formatPeso(row.from_basic_monthly_salary)} → {formatPeso(row.to_basic_monthly_salary)}
                  </span>
                </div>
                {(row.from_daily_rate || row.to_daily_rate) && (
                  <div className="flex">
                    <span className="text-muted w-40 shrink-0">Daily rate:</span>
                    <span className="font-mono tabular-nums">
                      {formatPeso(row.from_daily_rate)} → {formatPeso(row.to_daily_rate)}
                    </span>
                  </div>
                )}
                <div className="flex">
                  <span className="text-muted w-40 shrink-0">Effective:</span>
                  <span className="font-mono tabular-nums">{row.effective_date ?? '—'}</span>
                </div>
                <div className="flex">
                  <span className="text-muted w-40 shrink-0">Requested by:</span>
                  <span>{row.requested_by?.name ?? '—'}</span>
                </div>
                {row.reason && (
                  <div className="flex pt-1.5">
                    <span className="text-muted w-40 shrink-0">Reason:</span>
                    <span>{row.reason}</span>
                  </div>
                )}
              </div>

              {row.status === 'pending' && (
                <footer className="px-4 py-2 border-t border-default flex justify-end gap-2">
                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => setConfirm({ kind: 'reject', row })}
                    disabled={act.isPending}
                  >
                    Reject
                  </Button>
                  <Button
                    variant="primary"
                    size="sm"
                    onClick={() => setConfirm({ kind: 'approve', row })}
                    disabled={act.isPending}
                    loading={act.isPending && confirm?.row.id === row.id}
                  >
                    Approve
                  </Button>
                </footer>
              )}
            </article>
          ))}
        </div>
      )}

      <ConfirmDialog
        isOpen={confirm !== null}
        title={confirm?.kind === 'approve' ? 'Approve adjustment?' : 'Reject adjustment?'}
        description={
          confirm?.kind === 'reject'
            ? 'The requested salary will not be applied.'
            : 'Approving the final step applies the new salary and records an effective-dated history entry. You cannot approve a request you submitted.'
        }
        confirmLabel={act.isPending ? '...' : confirm?.kind === 'approve' ? 'Approve' : 'Reject'}
        variant={confirm?.kind === 'approve' ? 'primary' : 'danger'}
        onConfirm={() => {
          if (confirm) act.mutate({ id: confirm.row.id, action: confirm.kind });
        }}
        onClose={() => setConfirm(null)}
        pending={act.isPending}
      />
    </div>
  );
}
