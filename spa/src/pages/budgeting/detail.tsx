import { useState } from 'react';
import { isAxiosError } from 'axios';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { budgetingApi } from '@/api/accounting/budgeting';
import { useRealtimeRecord } from '@/hooks/useRealtimeRecord';
import { invalidateRealtimeList } from '@/lib/realtime/invalidationMap';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatDate } from '@/lib/formatDate';
import { formatCompactCurrency } from '@/lib/formatNumber';
import toast from 'react-hot-toast';
import { cn } from '@/lib/cn';
import { reportMutationError } from '@/lib/formErrors';
import { budgetStatusVariant } from '@/lib/statusVariants';
import { LuArrowLeft, LuSend, LuCircleX, LuCircleCheck, LuPencil, LuUndo2, LuTrash2 } from '@/lib/icons';
import type { Budget } from '@/types/budgeting';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { refetchUnlessClientHttpError, refetchOnMountUnlessClientError } from '@/lib/httpErrorPolicy';
const MONTHS = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
] as const;

export default function BudgetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [confirmSubmit, setConfirmSubmit] = useState(false);
  const [confirmApprove, setConfirmApprove] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);
  const [confirmReject, setConfirmReject] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [canContinueViewing, setCanContinueViewing] = useState(true);

  const budgetQuery = useQuery<Budget>({
    queryKey: ['budget', id],
    queryFn: () => budgetingApi.show(id!),
    enabled: !!id,
    staleTime: 0,
    refetchOnMount: refetchOnMountUnlessClientError,
    refetchOnWindowFocus: refetchUnlessClientHttpError,
  });
  const { data: budget, isLoading } = budgetQuery;
  useRealtimeRecord('budget', canContinueViewing ? id : undefined);
  const { data: budgetOptions } = useQuery({
    queryKey: ['budgets', 'options'],
    queryFn: () => budgetingApi.options(),
    staleTime: 300_000,
  });

  const seedBudgetResponse = (saved: Budget): void => {
    queryClient.setQueryData<Budget>(['budget', id], (current) => ({ ...current, ...saved }));
    setCanContinueViewing(saved.permissions?.view ?? true);
    void invalidateRealtimeList(queryClient, 'budget');
  };
  const reportActionError = (error: unknown, fallback: string): void => {
    if (isAxiosError(error) && error.response?.status === 409) {
      void budgetQuery.refetch();
      toast.error('This budget changed while you were acting. The latest state is loading; review it before trying again.');
      return;
    }
    reportMutationError(error, fallback);
  };

  const submitMutation = useMutation({
    mutationFn: () => budgetingApi.submit(id!),
    onSuccess: (saved) => {
      seedBudgetResponse(saved);
      toast.success('Budget submitted for approval.');
      setConfirmSubmit(false);
    },
    onError: (error) => reportActionError(error, 'Could not submit the budget for approval.'),
  });

  const approveMutation = useMutation({
    mutationFn: () => budgetingApi.approve(id!),
    onSuccess: (saved) => {
      seedBudgetResponse(saved);
      toast.success('Budget approved and activated.');
      setConfirmApprove(false);
    },
    onError: (error) => reportActionError(error, 'Could not approve the budget.'),
  });

  const closeMutation = useMutation({
    mutationFn: () => budgetingApi.close(id!),
    onSuccess: (saved) => {
      seedBudgetResponse(saved);
      toast.success('Budget closed.');
      setConfirmClose(false);
    },
    onError: (error) => reportActionError(error, 'Could not close the budget.'),
  });

  const rejectMutation = useMutation({
    mutationFn: (reason: string) => budgetingApi.reject(id!, reason),
    onSuccess: (saved) => {
      seedBudgetResponse(saved);
      toast.success('Budget returned to draft.');
      setConfirmReject(false);
    },
    onError: (error) => reportActionError(error, 'Could not return the budget to draft.'),
  });

  const deleteMutation = useMutation({
    mutationFn: () => budgetingApi.destroy(id!),
    onSuccess: () => {
      void invalidateRealtimeList(queryClient, 'budget');
      toast.success('Draft budget deleted.');
      navigate('/budgeting');
    },
    onError: (error) => reportMutationError(error, 'Could not delete the draft budget.'),
  });

  if (isLoading) return <SkeletonDetail />;
  // A failed fetch used to render "Budget not found", telling the user the
  // record was gone when the request had simply errored.
  if (budgetQuery.isError) {
    if (isAxiosError(budgetQuery.error) && [403, 404].includes(budgetQuery.error.response?.status ?? 0)) {
      return <EmptyState icon="lock" title="Budget is no longer available" description="It may have been deleted or your access may have changed." action={<Button variant="secondary" onClick={() => navigate('/budgeting')}>Return to budgets</Button>} />;
    }
    return <QueryErrorState subject="this budget" onRetry={() => void budgetQuery.refetch()} />;
  }
  if (!budget) return <EmptyState icon="alert-circle" title="Budget not found" />;

  const availableActions = new Set(budget.available_actions ?? []);
  const actionsAreCurrent = !budgetQuery.isFetching;

  return (
    <div className="p-5 space-y-6">
      <PageHeader
        title={budget.name}
        subtitle={
          <div className="flex items-center gap-3 mt-1">
            <Chip variant={budgetStatusVariant[budget.status]}>
              {budget.status_label ?? budget.status}
            </Chip>
            {budget.department && (
              <span className="text-sm text-muted">{budget.department.name}</span>
            )}
            <Chip variant="neutral">{budget.budget_type}</Chip>
            <span
              className={cn(
                'text-xs font-medium px-1.5 py-0.5 rounded',
                budget.utilization_pct >=
                  (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
                  ? 'text-danger-fg bg-danger-bg'
                  : budget.utilization_pct >=
                      (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)
                    ? 'text-warning-fg bg-warning-bg'
                    : 'text-success-fg bg-success-bg',
              )}
            >
              {budget.utilization_pct}% used
            </span>
          </div>
        }
        actions={
          <div className="flex items-center gap-2">
            <Link
              to="/budgeting"
              className="inline-flex items-center gap-1 text-sm text-secondary hover:text-primary transition-colors"
            >
              <LuArrowLeft size={14} /> Back
            </Link>
            {actionsAreCurrent && availableActions.has('edit') && (
              <Button size="sm" variant="secondary" onClick={() => navigate(`/budgeting/${budget.id}/edit`)}>
                <LuPencil size={14} /> Edit
              </Button>
            )}
            {actionsAreCurrent && availableActions.has('delete') && (
              <Button
                size="sm"
                variant="secondary"
                onClick={() => setConfirmDelete(true)}
                loading={deleteMutation.isPending}
                disabled={!actionsAreCurrent}
              >
                <LuTrash2 size={14} /> Delete
              </Button>
            )}
            {actionsAreCurrent && availableActions.has('submit') && (
              <Button
                size="sm"
                variant="primary"
                onClick={() => setConfirmSubmit(true)}
                loading={submitMutation.isPending}
                disabled={!actionsAreCurrent}
              >
                <LuSend size={14} /> Submit for Approval
              </Button>
            )}
            {actionsAreCurrent && availableActions.has('approve') && (
              <Button
                size="xs"
                variant="primary"
                onClick={() => setConfirmApprove(true)}
                loading={approveMutation.isPending}
                disabled={!actionsAreCurrent}
              >
                <LuCircleCheck size={14} /> Approve
              </Button>
            )}
            {actionsAreCurrent && availableActions.has('reject') && (
              <Button
                size="sm"
                variant="secondary"
                onClick={() => setConfirmReject(true)}
                disabled={!actionsAreCurrent}
              >
                <LuUndo2 size={14} /> Return to draft
              </Button>
            )}
            {actionsAreCurrent && availableActions.has('close') && (
              <Button
                size="sm"
                variant="secondary"
                onClick={() => setConfirmClose(true)}
                loading={closeMutation.isPending}
                disabled={!actionsAreCurrent}
              >
                <LuCircleX size={14} /> Close
              </Button>
            )}
          </div>
        }
      />

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard
          label="Allocated"
          value={formatCompactCurrency(budget.total_allocated, 1_000_000, 'M')}
        />
        <StatCard label="Spent" value={formatCompactCurrency(budget.total_spent, 1_000_000, 'M')} />
        <StatCard
          label="Committed"
          value={formatCompactCurrency(budget.total_committed, 1_000_000, 'M')}
        />
        <StatCard
          label="Available"
          value={formatCompactCurrency(budget.available, 1_000_000, 'M')}
          className={Number(budget.available) < 0 ? 'text-danger-fg' : 'text-success-fg'}
        />
      </div>

      {/* Utilization Bar */}
      <div className="space-y-1.5">
        <div className="flex justify-between text-sm">
          <span className="text-secondary">Utilization</span>
          <span
            className={cn(
              'font-medium',
              budget.utilization_pct >=
                (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
                ? 'text-danger-fg'
                : 'text-success-fg',
            )}
          >
            {budget.utilization_pct}%
          </span>
        </div>
        <div className="h-3 bg-subtle rounded-full overflow-hidden">
          <div
            className={cn(
              'h-full rounded-full transition-[width] duration-500',
              budget.utilization_pct >=
                (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
                ? 'bg-danger-bg'
                : budget.utilization_pct >=
                    (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)
                  ? 'bg-warning-bg'
                  : 'bg-success-bg',
            )}
            style={{ width: `${Math.min(budget.utilization_pct, 100)}%` }}
          />
        </div>
      </div>

      {/* Line Items */}
      <Panel
        title="Line Items"
        meta={<span className="text-xs text-muted">Monthly allocations per account</span>}
      >
        {budget.line_items && budget.line_items.length > 0 ? (
          <div className="overflow-x-auto">
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Account</Th>
                  {MONTHS.map((m) => (
                    <Th align="right" className="font-mono" key={m}>
                      {m}
                    </Th>
                  ))}
                  <Th align="right">Annual</Th>
                  <Th align="right">Actual</Th>
                  <Th align="right">Variance</Th>
                </tr>
              </thead>
              <tbody>
                {budget.line_items.map((li) => (
                  <tr key={li.id} className={trCls}>
                    <Td>
                      <span className="font-medium">{li.account?.code}</span>
                      <span className="ml-1.5 text-muted text-xs">{li.account?.name}</span>
                    </Td>
                    {MONTHS.map((m) => {
                      const val = Number(li[m.toLowerCase() as keyof typeof li]);
                      return (
                        <Td align="right" mono className="text-xs" key={m}>
                          {val > 0 ? formatCompactCurrency(val, 1_000, 'K') : '-'}
                        </Td>
                      );
                    })}
                    <Td align="right" mono className="font-medium">
                      {formatCompactCurrency(li.annual_total, 1_000, 'K')}
                    </Td>
                    <Td align="right" mono>
                      {formatCompactCurrency(li.actual_total, 1_000, 'K')}
                    </Td>
                    <Td
                      align="right"
                      mono
                      className={cn(Number(li.variance) < 0 ? 'text-danger-fg' : 'text-success-fg')}
                    >
                      {Number(li.variance) >= 0 ? '+' : '-'}
                      {formatCompactCurrency(Math.abs(Number(li.variance)), 1_000, 'K')}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-muted py-4 text-center">No line items configured.</p>
        )}
      </Panel>

      {/* Approval Info */}
      {(budget.submitted_by || budget.approved_by || budget.rejected_by) && (
        <Panel title="Approval History">
          <div className="space-y-2 text-sm">
            {budget.submitted_by && (
              <div className="flex items-center justify-between py-1.5 border-b border-default/50">
                <span className="text-secondary">Submitted by</span>
                <span>
                  {budget.submitted_by.name}{' '}
                  {budget.submitted_at ? `on ${formatDate(budget.submitted_at)}` : ''}
                </span>
              </div>
            )}
            {budget.approved_by && (
              <div className="flex items-center justify-between py-1.5 border-b border-default/50">
                <span className="text-secondary">Approved by</span>
                <span>
                  {budget.approved_by.name}{' '}
                  {budget.approved_at ? `on ${formatDate(budget.approved_at)}` : ''}
                </span>
              </div>
            )}
            {budget.rejected_by && (
              <div className="flex items-center justify-between py-1.5">
                <span className="text-secondary">Returned to draft by</span>
                <span className="text-right">
                  {budget.rejected_by.name}{' '}
                  {budget.rejected_at ? `on ${formatDate(budget.rejected_at)}` : ''}
                  {budget.rejection_reason && (
                    <span className="block text-xs text-muted">{budget.rejection_reason}</span>
                  )}
                </span>
              </div>
            )}
          </div>
        </Panel>
      )}

      <ConfirmDialog
        isOpen={confirmSubmit}
        onClose={() => setConfirmSubmit(false)}
        onConfirm={() => submitMutation.mutate()}
        title="Submit budget for approval?"
        variant="warning"
        confirmLabel="Submit"
        pending={submitMutation.isPending}
      />
      <ConfirmDialog
        isOpen={confirmApprove}
        onClose={() => setConfirmApprove(false)}
        onConfirm={() => approveMutation.mutate()}
        title="Approve budget?"
        variant="warning"
        confirmLabel="Approve"
        pending={approveMutation.isPending}
      />
      <ConfirmDialog
        isOpen={confirmClose}
        onClose={() => setConfirmClose(false)}
        onConfirm={() => closeMutation.mutate()}
        title="Close budget period?"
        description="Closed budgets are read-only. Adjust with a supplemental budget for the same department."
        variant="warning"
        confirmLabel="Close"
        pending={closeMutation.isPending}
      />
      <ReasonDialog
        isOpen={confirmReject}
        onClose={() => setConfirmReject(false)}
        onConfirm={async (reason) => { await rejectMutation.mutateAsync(reason); }}
        title="Return budget to draft?"
        description="The maker can rework and resubmit. The reason is recorded in the approval history."
        reasonLabel="Return reason"
        reasonPlaceholder="What must change before this can be approved?"
        confirmLabel="Return to draft"
        variant="warning"
        pending={rejectMutation.isPending}
      />
      <ConfirmDialog
        isOpen={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => deleteMutation.mutate()}
        title="Delete this draft budget?"
        description="The draft and its line items are permanently removed. This cannot be undone."
        variant="danger"
        confirmLabel="Delete draft"
        pending={deleteMutation.isPending}
      />
    </div>
  );
}
