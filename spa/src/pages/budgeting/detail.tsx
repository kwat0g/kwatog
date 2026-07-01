import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { budgetingApi } from '@/api/accounting/budgeting';
import { usePermission } from '@/hooks/usePermission';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatDate } from '@/lib/formatDate';
import toast from 'react-hot-toast';
import { cn } from '@/lib/cn';
import { ArrowLeft, Send, XCircle, CheckCircle } from 'lucide-react';
import type { Budget } from '@/types/budgeting';

const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as const;

const STATUS_VARIANT: Record<string, ChipVariant> = {
  active: 'success', approved: 'success', submitted: 'warning',
  draft: 'neutral', closed: 'neutral',
};

export default function BudgetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canManage = can('budgeting.manage');
  const canApprove = can('budgeting.approve');
  const [confirmSubmit, setConfirmSubmit] = useState(false);
  const [confirmApprove, setConfirmApprove] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);

  const { data: budget, isLoading } = useQuery<Budget>({
    queryKey: ['budget', id],
    queryFn: () => budgetingApi.show(id!),
    enabled: !!id,
  });

  const submitMutation = useMutation({
    mutationFn: () => budgetingApi.submit(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['budget', id] });
      toast.success('Budget submitted for approval.');
      setConfirmSubmit(false);
    },
    onError: () => toast.error('Failed to submit budget.'),
  });

  const approveMutation = useMutation({
    mutationFn: () => budgetingApi.approve(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['budget', id] });
      toast.success('Budget approved and activated.');
      setConfirmApprove(false);
    },
    onError: () => toast.error('Failed to approve budget.'),
  });

  const closeMutation = useMutation({
    mutationFn: () => budgetingApi.close(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['budget', id] });
      toast.success('Budget closed.');
      setConfirmClose(false);
    },
    onError: () => toast.error('Failed to close budget.'),
  });

  if (isLoading) return <SkeletonDetail />;
  if (!budget) return <EmptyState icon="alert-circle" title="Budget not found" />;

  const canSubmit = budget.status === 'draft' && canManage;
  const canApproveAction = budget.status === 'submitted' && canApprove;
  const canClose = (budget.status === 'active' || budget.status === 'approved') && canManage;

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title={budget.name}
        subtitle={
          <div className="flex items-center gap-3 mt-1">
            <Chip variant={STATUS_VARIANT[budget.status] ?? 'neutral'}>{budget.status}</Chip>
            {budget.department && <span className="text-sm text-muted">{budget.department.name}</span>}
            <Chip variant="neutral">{budget.budget_type}</Chip>
            <span className={cn(
              'text-xs font-medium px-1.5 py-0.5 rounded',
              budget.utilization_pct >= 95 ? 'text-danger-fg bg-danger-bg' :
              budget.utilization_pct >= 80 ? 'text-warning-fg bg-warning-bg' : 'text-success-fg bg-success-bg'
            )}>
              {budget.utilization_pct}% used
            </span>
          </div>
        }
        breadcrumbs={[{ label: 'Budgeting', href: '/budgeting' }, { label: budget.name }]}
        actions={
          <div className="flex items-center gap-2">
            <Link to="/budgeting" className="inline-flex items-center gap-1 text-sm text-secondary hover:text-primary transition-colors">
              <ArrowLeft size={14} /> Back
            </Link>
            {canSubmit && (
              <Button size="sm" variant="primary" onClick={() => setConfirmSubmit(true)} loading={submitMutation.isPending}>
                <Send size={14} /> Submit for Approval
              </Button>
            )}
            {canApproveAction && (
              <Button size="sm" variant="primary" onClick={() => setConfirmApprove(true)} loading={approveMutation.isPending}>
                <CheckCircle size={14} /> Approve
              </Button>
            )}
            {canClose && (
              <Button size="sm" variant="secondary" onClick={() => setConfirmClose(true)} loading={closeMutation.isPending}>
                <XCircle size={14} /> Close
              </Button>
            )}
          </div>
        }
      />

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard label="Allocated" value={`₱${(budget.total_allocated / 1_000_000).toFixed(2)}M`} />
        <StatCard label="Spent" value={`₱${(budget.total_spent / 1_000_000).toFixed(2)}M`} />
        <StatCard label="Committed" value={`₱${(budget.total_committed / 1_000_000).toFixed(2)}M`} />
        <StatCard
          label="Available"
          value={`₱${(budget.available / 1_000_000).toFixed(2)}M`}
          className={budget.available < 0 ? 'text-danger' : 'text-success'}
        />
      </div>

      {/* Utilization Bar */}
      <div className="space-y-1.5">
        <div className="flex justify-between text-sm">
          <span className="text-secondary">Utilization</span>
          <span className={cn('font-medium', budget.utilization_pct >= 95 ? 'text-danger' : 'text-success')}>
            {budget.utilization_pct}%
          </span>
        </div>
        <div className="h-3 bg-muted rounded-full overflow-hidden">
          <div
            className={cn(
              'h-full rounded-full transition-all duration-500',
              budget.utilization_pct >= 95 ? 'bg-danger' :
              budget.utilization_pct >= 80 ? 'bg-warning' : 'bg-success'
            )}
            style={{ width: `${Math.min(budget.utilization_pct, 100)}%` }}
          />
        </div>
      </div>

      {/* Line Items */}
      <Panel title="Line Items" meta={<span className="text-xs text-muted">Monthly allocations per account</span>}>
        {budget.line_items && budget.line_items.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-muted">
                  <th className="py-2 pr-3">Account</th>
                  {MONTHS.map((m) => (
                    <th key={m} className="py-2 pr-3 text-right font-mono">{m}</th>
                  ))}
                  <th className="py-2 pr-3 text-right">Annual</th>
                  <th className="py-2 pr-3 text-right">Actual</th>
                  <th className="py-2 text-right">Variance</th>
                </tr>
              </thead>
              <tbody>
                {budget.line_items.map((li) => (
                  <tr key={li.id} className="border-b border-default/50 hover:bg-elevated/50 transition-colors">
                    <td className="py-2 pr-3">
                      <span className="font-medium">{li.account?.code}</span>
                      <span className="ml-1.5 text-muted text-xs">{li.account?.name}</span>
                    </td>
                    {MONTHS.map((m) => {
                      const val = li[m.toLowerCase() as keyof typeof li] as number;
                      return (
                        <td key={m} className="py-2 pr-3 text-right font-mono text-xs">
                          {val > 0 ? `₱${(val / 1000).toFixed(0)}K` : '-'}
                        </td>
                      );
                    })}
                    <td className="py-2 pr-3 text-right font-mono font-medium">₱{(li.annual_total / 1000).toFixed(0)}K</td>
                    <td className="py-2 pr-3 text-right font-mono">₱{(li.actual_total / 1000).toFixed(0)}K</td>
                    <td className={cn('py-2 text-right font-mono', li.variance < 0 ? 'text-danger' : 'text-success')}>
                      {li.variance >= 0 ? '+' : ''}{li.variance >= 0 ? '₱' : '-₱'}{(Math.abs(li.variance) / 1000).toFixed(0)}K
                    </td>
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
      {(budget.submitted_by || budget.approved_by) && (
        <Panel title="Approval History">
          <div className="space-y-2 text-sm">
            {budget.submitted_by && (
              <div className="flex items-center justify-between py-1.5 border-b border-default/50">
                <span className="text-secondary">Submitted by</span>
                <span>{budget.submitted_by.name} {budget.submitted_at ? `on ${formatDate(budget.submitted_at)}` : ''}</span>
              </div>
            )}
            {budget.approved_by && (
              <div className="flex items-center justify-between py-1.5">
                <span className="text-secondary">Approved by</span>
                <span>{budget.approved_by.name} {budget.approved_at ? `on ${formatDate(budget.approved_at)}` : ''}</span>
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
        description="No further changes or transfers will be allowed."
        variant="warning"
        confirmLabel="Close"
        pending={closeMutation.isPending}
      />
    </div>
  );
}
