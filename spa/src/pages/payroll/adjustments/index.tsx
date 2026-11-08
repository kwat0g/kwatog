import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuPlus, LuCheck, LuX } from '@/lib/icons';
import toast from 'react-hot-toast';
import { adjustmentsApi, type AdjustmentListParams } from '@/api/payroll/adjustments';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import { payrollAdjustmentStatusVariant } from '@/lib/statusVariants';
import type { PayrollAdjustment } from '@/types/payroll';
import type { PaginatedResponse } from '@/types';

import { useUrlFilters } from '@/hooks/useUrlFilters';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
import { sameQueryExceptPage } from '@/lib/queryKeys/pageScope';

export default function PayrollAdjustmentsPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  useRealtimeList('payroll_adjustment', 'visible');
  const [filters, setFilters] = useUrlFilters<AdjustmentListParams>({
    page: 1,
    per_page: 25,
    sort: 'created_at',
    direction: 'desc',
  });
  const [rejectRemarks, setRejectRemarks] = useState('');

  const reconcileAdjustment = (updated: PayrollAdjustment) => {
    qc.setQueryData<PaginatedResponse<PayrollAdjustment>>(['payroll-adjustments', filters], (current) =>
      current ? { ...current, data: current.data.map((row) => row.id === updated.id ? updated : row) } : current,
    );
    void qc.invalidateQueries({ queryKey: ['payroll-adjustments'] });
  };

  const queryKey = ['payroll-adjustments', filters] as const;
  const { data, isLoading, isFetching, isError, refetch } = useQuery({
    queryKey,
    queryFn: () => adjustmentsApi.list(filters),
    placeholderData: (previous, previousQuery) =>
      sameQueryExceptPage(previousQuery?.queryKey, queryKey, 1) ? previous : undefined,
  });
  const adjustments = data?.data ?? [];
  const rejectActionTarget = useQueryRecordTarget(adjustments, { isFetching, isError });
  const approveActionTarget = useQueryRecordTarget(adjustments, { isFetching, isError });
  const rejectTarget = rejectActionTarget.target;
  const approveTarget = approveActionTarget.target;
  const { data: adjustmentOptions } = useQuery({
    queryKey: ['payroll-adjustments', 'options'],
    queryFn: adjustmentsApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const labels = new Map(
    [...(adjustmentOptions?.statuses ?? []), ...(adjustmentOptions?.types ?? [])].map((option) => [
      option.value,
      option.label,
    ]),
  );

  const approveMutation = useMutation({
    mutationFn: (id: string) => adjustmentsApi.approve(id),
    onSuccess: (updated) => {
      toast.success('Adjustment approved.');
      reconcileAdjustment(updated);
      approveActionTarget.close();
    },
    onError: (error) => {
      void qc.invalidateQueries({ queryKey: ['payroll-adjustments'] });
      reportMutationError(error, 'Could not approve the payroll adjustment.');
    },
  });
  const rejectMutation = useMutation({
    mutationFn: ({ id, remarks }: { id: string; remarks: string }) =>
      adjustmentsApi.reject(id, remarks),
    onSuccess: (updated) => {
      toast.success('Adjustment rejected.');
      reconcileAdjustment(updated);
      rejectActionTarget.close();
      setRejectRemarks('');
    },
    onError: (error) => {
      void qc.invalidateQueries({ queryKey: ['payroll-adjustments'] });
      reportMutationError(error, 'Could not reject the payroll adjustment.');
    },
  });

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(adjustmentOptions?.statuses ?? [])],
    },
    {
      key: 'type',
      label: 'Type',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(adjustmentOptions?.types ?? [])],
    },
  ];

  const columns: Column<PayrollAdjustment>[] = [
    {
      key: 'employee',
      header: 'Employee',
      cell: (r) =>
        r.employee ? (
          <StackedCell
            primary={r.employee.full_name}
            secondary={<span className="font-mono">{r.employee.employee_no}</span>}
          />
        ) : (
          '—'
        ),
    },
    {
      key: 'type',
      header: 'Type',
      cell: (r) => (
        <Chip variant={r.type === 'underpayment' ? 'info' : 'warning'}>
          {labels.get(r.type) ?? r.type_label}
        </Chip>
      ),
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      cell: (r) => <NumCell className="font-medium">{formatPeso(r.amount)}</NumCell>,
    },
    { key: 'period', header: 'Period', cell: (r) => r.period?.label ?? '—' },
    {
      key: 'reason',
      header: 'Reason',
      cell: (r) => (
        <span className="text-xs text-muted">
          {r.reason.slice(0, 80)}
          {r.reason.length > 80 ? '…' : ''}
        </span>
      ),
    },
    {
      key: 'created_at',
      header: 'Submitted',
      cell: (r) => <NumCell>{formatDate(r.created_at)}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={payrollAdjustmentStatusVariant[r.status]}>{labels.get(r.status) ?? r.status_label}</Chip>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      cell: (r) =>
        (r.available_actions.approve || r.available_actions.reject) ? (
          <div className="flex items-center gap-1">
            {r.available_actions.approve && (
              <Button
                size="xs"
                variant="ghost"
                icon={<LuCheck size={12} />}
                onClick={() => approveActionTarget.open(r)}
                disabled={approveMutation.isPending || rejectMutation.isPending}
              >
                Approve
              </Button>
            )}
            {r.available_actions.reject && (
              <Button
                size="xs"
                variant="ghost"
                icon={<LuX size={12} />}
                onClick={() => rejectActionTarget.open(r)}
                disabled={approveMutation.isPending || rejectMutation.isPending}
              >
                Reject
              </Button>
            )}
          </div>
        ) : null,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Payroll Adjustments"
        subtitle={data ? `${data.meta.total} adjustments` : undefined}
        actions={
          can('payroll.adjustments.create') ? (
            <Button
              variant="primary"
              size="xs"
              icon={<LuPlus size={14} />}
              onClick={() => navigate('/payroll/adjustments/create')}
            >
              Raise adjustment
            </Button>
          ) : null
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search adjustments…"
      />

      {isLoading && !data && <SkeletonTable columns={8} rows={6} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load adjustments"
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
          description={
            can('payroll.adjustments.create')
              ? 'Adjustments are raised against finalized payroll periods.'
              : 'Nothing here yet.'
          }
          action={
            can('payroll.adjustments.create') ? (
              <Button variant="primary" onClick={() => navigate('/payroll/adjustments/create')}>
                Raise adjustment
              </Button>
            ) : undefined
          }
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
          />
        </div>
      )}

      {/* Reject modal */}
      <Modal
        isOpen={rejectActionTarget.targetId !== null}
        onClose={rejectActionTarget.close}
        size="sm"
        title="Reject adjustment"
      >
        <div className="py-3">
          {rejectActionTarget.isChecking && <p role="status" className="text-xs text-muted mb-2">Checking the latest adjustment status. Your reason is kept.</p>}
          {rejectActionTarget.isUnverified && <p role="alert" className="text-xs text-danger-fg mb-2">The latest status could not be checked. Your reason is kept; retry the refresh before rejecting.</p>}
          {!rejectActionTarget.isChecking && !rejectActionTarget.isUnverified && rejectActionTarget.isUnavailable && (
            <p role="alert" className="text-xs text-warning-fg mb-2">This adjustment is no longer available for rejection. Your reason is kept.</p>
          )}
          {rejectActionTarget.isFresh && rejectTarget && !rejectTarget.available_actions.reject && (
            <p role="alert" className="text-xs text-warning-fg mb-2">This adjustment is no longer available for rejection. Your reason is kept.</p>
          )}
          {rejectActionTarget.isFresh && rejectTarget?.available_actions.reject && <p className="text-xs text-muted mb-2">
            Reject {rejectTarget?.employee?.full_name}'s {rejectTarget?.type_label.toLowerCase()} of{' '}
            <span className="font-mono">{formatPeso(rejectTarget?.amount)}</span>?
          </p>}
          <Textarea
            label="Reason for rejection"
            value={rejectRemarks}
            onChange={(e) => setRejectRemarks(e.target.value)}
            placeholder="Provide a reason…"
            rows={3}
          />
        </div>
        <ModalFooter>
          <Button
            variant="secondary"
            onClick={rejectActionTarget.close}
            disabled={rejectMutation.isPending}
          >
            Cancel
          </Button>
          <Button
            variant="danger"
            onClick={() =>
              rejectTarget && rejectMutation.mutate({ id: rejectTarget.id, remarks: rejectRemarks })
            }
            disabled={
              !rejectRemarks.trim() ||
              rejectMutation.isPending ||
              !rejectActionTarget.isFresh ||
              !rejectTarget?.available_actions.reject
            }
            loading={rejectMutation.isPending}
          >
            Confirm reject
          </Button>
        </ModalFooter>
      </Modal>

      <ConfirmDialog
        isOpen={approveActionTarget.targetId !== null}
        onClose={approveActionTarget.close}
        onConfirm={() => {
          if (approveActionTarget.isFresh && approveTarget?.available_actions.approve) approveMutation.mutate(approveTarget.id);
        }}
        title="Approve payroll adjustment?"
        description={
          approveActionTarget.isChecking ? 'Checking the latest adjustment status.'
          : approveActionTarget.isUnverified ? 'The latest status could not be checked. Retry the refresh before approving.'
          : approveActionTarget.isUnavailable ? 'This adjustment is no longer available for approval.'
          : approveActionTarget.isFresh && approveTarget && !approveTarget.available_actions.approve ? 'This adjustment is no longer available for approval.'
          : approveTarget ? (
            <>
              <span className="font-mono tabular-nums text-primary">
                {formatPeso(approveTarget.amount)}
              </span>{' '}
              {labels.get(approveTarget.type)?.toLowerCase() ??
                approveTarget.type_label.toLowerCase()}{' '}
              for{' '}
              <span className="text-primary">
                {approveTarget.employee?.full_name ?? 'this employee'}
              </span>
              {approveTarget.period?.label ? <> against {approveTarget.period.label}</> : null}.
              Once approved it is applied to the employee&rsquo;s next payroll run.
            </>
          ) : undefined
        }
        variant="warning"
        confirmLabel="Approve"
        pending={approveMutation.isPending}
        disabled={!approveActionTarget.isFresh || !approveTarget?.available_actions.approve}
      />
    </div>
  );
}
