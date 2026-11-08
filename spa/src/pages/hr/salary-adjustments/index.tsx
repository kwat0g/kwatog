import { reportMutationError } from '@/lib/formErrors';
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import type { PaginatedResponse } from '@/types';
import {
 Button,
 Chip,
 ConfirmDialog,
 EmptyState,
 FilterBar,
 SkeletonTable,
 type FilterConfig,
} from '@/components/ui';
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
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
 *
 * Scope cut 2026-08-08: folded from a standalone page into a tab on the
 * Employees page. No PageHeader here — the Employees page header owns it.
 */
export function SalaryAdjustmentsTab() {
 const queryClient = useQueryClient();
 const [status, setStatus] = useState<SalaryAdjustmentStatus | ''>('');
 const [confirmKind, setConfirmKind] = useState<'approve' | 'reject' | null>(null);

 const { data, isLoading, isFetching, isError, refetch } = useQuery({
 queryKey: ['hr', 'salary-adjustments', status],
 queryFn: () => salaryAdjustmentsApi.list({ ...(status ? { status } : {}) }),
 });
 const confirmActionTarget = useQueryRecordTarget(data?.data, { isFetching, isError });
 const confirmTarget = confirmActionTarget.target;
 const canConfirm = Boolean(confirmKind && confirmActionTarget.isFresh && confirmTarget?.available_actions.includes(confirmKind));
 const { data: adjustmentOptions } = useQuery({
 queryKey: ['hr', 'salary-adjustments', 'options'],
 queryFn: salaryAdjustmentsApi.options,
 staleTime: 5 * 60 * 1000,
 });

 useEffect(() => {
 if (!confirmKind || isFetching || isError) return;
 if (!confirmTarget?.available_actions.includes(confirmKind)) {
  confirmActionTarget.close();
  setConfirmKind(null);
 }
 }, [confirmKind, confirmTarget, confirmActionTarget, isFetching, isError]);

 const act = useMutation({
 mutationFn: (args: { id: string; action: 'approve' | 'reject' }) =>
 salaryAdjustmentsApi.act(args.id, args.action, args.action === 'reject' ? 'Rejected via queue' : undefined),
 onSuccess: (updated, vars) => {
 queryClient.setQueryData<PaginatedResponse<SalaryAdjustmentItem>>(
 ['hr', 'salary-adjustments', status],
 (current) => {
 if (!current) return current;
 const existed = current.data.some((row) => row.id === updated.id);
    const nextRows = !status || updated.status === status
 ? current.data.map((row) => row.id === updated.id ? updated : row)
 : current.data.filter((row) => row.id !== updated.id);
 return {
 ...current,
 data: nextRows,
 meta: {
 ...current.meta,
 total: Math.max(0, current.meta.total - (existed && updated.status !== status ? 1 : 0)),
 },
 };
 },
 );
 toast.success(vars.action === 'approve' ? 'Adjustment approved.' : 'Adjustment rejected.');
 confirmActionTarget.close();
 setConfirmKind(null);
 queryClient.invalidateQueries({ queryKey: ['hr', 'salary-adjustments'] });
 },
 onError: (error) => {
  void queryClient.invalidateQueries({ queryKey: ['hr', 'salary-adjustments'] });
  reportMutationError(error, 'Failed to update adjustment.');
 },
 });

 const filterConfig: FilterConfig[] = [
 {
 key: 'status',
 label: 'Status',
 type: 'select',
 options: [
    { value: '', label: 'All statuses' },
 ...(adjustmentOptions?.statuses ?? []),
 ],
 },
 ];

 return (
 <div>
 <FilterBar
 filters={filterConfig}
 values={{ status }}
 onFilter={(_k, v) => setStatus((v as SalaryAdjustmentStatus) || '')}
 onApplySavedView={(saved) => setStatus((saved.status as SalaryAdjustmentStatus | undefined) ?? '')}
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
 <Chip variant={STATUS_CHIP[row.status]}>{row.status_label ?? row.status}</Chip>
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
 {(row.from_semi_monthly_rate || row.to_semi_monthly_rate) && (
 <div className="flex">
 <span className="text-muted w-40 shrink-0">Semi-monthly rate:</span>
 <span className="font-mono tabular-nums">
 {formatPeso(row.from_semi_monthly_rate)} → {formatPeso(row.to_semi_monthly_rate)}
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

 {row.status === 'pending' && (row.available_actions.length > 0 || row.permissions.act) && (
 <footer className="px-4 py-2 border-t border-default flex justify-end gap-2">
 {row.available_actions.includes('reject') && <Button
 variant="danger"
 size="sm"
 onClick={() => { setConfirmKind('reject'); confirmActionTarget.open(row); }}
 disabled={act.isPending}
 >
 Reject
 </Button>}
 {row.available_actions.includes('approve') && <Button
 variant="primary"
 size="sm"
 onClick={() => { setConfirmKind('approve'); confirmActionTarget.open(row); }}
 disabled={act.isPending}
 loading={act.isPending && confirmActionTarget.targetId === row.id}
 >
 Approve
 </Button>}
 {row.available_actions.length === 0 && row.permissions.act && (
 <span role="status" className="text-xs text-muted">This approval step is assigned to another reviewer.</span>
 )}
 </footer>
 )}
 </article>
 ))}
 </div>
 )}

 <ConfirmDialog
 isOpen={confirmKind !== null && confirmActionTarget.targetId !== null}
 title={confirmKind === 'approve' ? 'Approve adjustment?' : 'Reject adjustment?'}
 description={
 isFetching
 ? 'Checking the latest adjustment status and available actions…'
 : isError
 ? 'The latest adjustment status could not be checked. Refresh the list before continuing.'
 : !canConfirm
 ? 'This action is no longer available for this adjustment.'
 : confirmKind === 'reject'
 ? 'The requested salary will not be applied.'
 : 'Approving the final step applies the new salary and records an effective-dated history entry. You cannot approve a request you submitted.'
 }
 confirmLabel={act.isPending ? '...' : confirmKind === 'approve' ? 'Approve' : 'Reject'}
 variant={confirmKind === 'approve' ? 'primary' : 'danger'}
 onConfirm={() => {
 if (
 confirmKind && confirmTarget && canConfirm && !isFetching && !isError
 ) act.mutate({ id: confirmTarget.id, action: confirmKind });
 }}
 onClose={() => { confirmActionTarget.close(); setConfirmKind(null); }}
 pending={act.isPending}
 disabled={isFetching || isError || !canConfirm}
 />
 </div>
 );
}
