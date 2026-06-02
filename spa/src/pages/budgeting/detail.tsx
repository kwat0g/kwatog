import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { budgetingApi } from '@/api/accounting/budgeting';
import { usePermission } from '@/hooks/usePermission';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { FullPageLoader } from '@/components/ui/Spinner';
import toast from 'react-hot-toast';
import { cn } from '@/lib/cn';
import { ArrowLeft, DollarSign, TrendingUp, AlertTriangle, CheckCircle, Send, XCircle } from 'lucide-react';
import type { Budget } from '@/types/budgeting';

const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as const;

export default function BudgetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canManage = can('budgeting.manage');
  const canApprove = can('budgeting.approve');

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
    },
    onError: () => toast.error('Failed to submit budget.'),
  });

  const approveMutation = useMutation({
    mutationFn: () => budgetingApi.approve(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['budget', id] });
      toast.success('Budget approved and activated.');
    },
    onError: () => toast.error('Failed to approve budget.'),
  });

  const closeMutation = useMutation({
    mutationFn: () => budgetingApi.close(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['budget', id] });
      toast.success('Budget closed.');
    },
    onError: () => toast.error('Failed to close budget.'),
  });

  if (isLoading) return <FullPageLoader />;
  if (!budget) return <div className="p-6 text-red-500">Budget not found.</div>;

  const statusBadge = (status: string) => {
    const variants: Record<string, 'accent' | 'warning' | 'danger' | 'neutral'> = {
      active: 'accent', approved: 'accent', submitted: 'warning',
      draft: 'neutral', closed: 'neutral',
    };
    return <Badge variant={variants[status] || 'neutral'}>{status}</Badge>;
  };

  const canSubmit = budget.status === 'draft' && canManage;
  const canApproveAction = budget.status === 'submitted' && canApprove;
  const canClose = (budget.status === 'active' || budget.status === 'approved') && canManage;

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title={budget.name}
        subtitle={
          <div className="flex items-center gap-3 mt-1">
            {statusBadge(budget.status)}
            {budget.department && <span className="text-sm text-text-subtle">{budget.department.name}</span>}
            <Badge variant="neutral">{budget.budget_type}</Badge>
            <span className={cn(
              'text-xs font-medium px-1.5 py-0.5 rounded',
              budget.utilization_pct >= 95 ? 'text-red-600 bg-red-50' :
              budget.utilization_pct >= 80 ? 'text-yellow-600 bg-yellow-50' : 'text-green-600 bg-green-50'
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
              <Button size="sm" variant="primary" onClick={() => submitMutation.mutate()} loading={submitMutation.isPending}>
                <Send size={14} /> Submit for Approval
              </Button>
            )}
            {canApproveAction && (
              <Button size="sm" variant="primary" onClick={() => approveMutation.mutate()} loading={approveMutation.isPending}>
                <CheckCircle size={14} /> Approve
              </Button>
            )}
            {canClose && (
              <Button size="sm" variant="secondary" onClick={() => closeMutation.mutate()} loading={closeMutation.isPending}>
                <XCircle size={14} /> Close
              </Button>
            )}
          </div>
        }
      />

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="rounded-lg border border-default p-4">
          <div className="flex items-center gap-2 text-sm text-text-subtle mb-1">
            <DollarSign size={14} /> Allocated
          </div>
          <p className="text-2xl font-bold">₱{(budget.total_allocated / 1_000_000).toFixed(2)}M</p>
        </div>
        <div className="rounded-lg border border-default p-4">
          <div className="flex items-center gap-2 text-sm text-text-subtle mb-1">
            <TrendingUp size={14} /> Spent
          </div>
          <p className="text-2xl font-bold">₱{(budget.total_spent / 1_000_000).toFixed(2)}M</p>
        </div>
        <div className="rounded-lg border border-default p-4">
          <div className="flex items-center gap-2 text-sm text-text-subtle mb-1">
            <AlertTriangle size={14} /> Committed
          </div>
          <p className="text-2xl font-bold">₱{(budget.total_committed / 1_000_000).toFixed(2)}M</p>
        </div>
        <div className="rounded-lg border border-default p-4">
          <div className="flex items-center gap-2 text-sm text-text-subtle mb-1">
            <DollarSign size={14} /> Available
          </div>
          <p className={cn('text-2xl font-bold', budget.available < 0 ? 'text-red-600' : 'text-green-600')}>
            ₱{(budget.available / 1_000_000).toFixed(2)}M
          </p>
        </div>
      </div>

      {/* Utilization Bar */}
      <div className="space-y-1.5">
        <div className="flex justify-between text-sm">
          <span className="text-secondary">Utilization</span>
          <span className={cn('font-medium', budget.utilization_pct >= 95 ? 'text-red-600' : 'text-green-600')}>
            {budget.utilization_pct}%
          </span>
        </div>
        <div className="h-3 bg-muted rounded-full overflow-hidden">
          <div
            className={cn(
              'h-full rounded-full transition-all duration-500',
              budget.utilization_pct >= 95 ? 'bg-red-500' :
              budget.utilization_pct >= 80 ? 'bg-yellow-500' : 'bg-green-500'
            )}
            style={{ width: `${Math.min(budget.utilization_pct, 100)}%` }}
          />
        </div>
      </div>

      {/* Line Items */}
      <Panel title="Line Items" meta={<span className="text-xs text-text-subtle">Monthly allocations per account</span>}>
        {budget.line_items && budget.line_items.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-text-subtle">
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
                      <span className="ml-1.5 text-text-subtle text-xs">{li.account?.name}</span>
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
                    <td className={cn('py-2 text-right font-mono', li.variance < 0 ? 'text-red-600' : 'text-green-600')}>
                      {li.variance >= 0 ? '+' : ''}{li.variance >= 0 ? '₱' : '-₱'}{(Math.abs(li.variance) / 1000).toFixed(0)}K
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-text-subtle py-4 text-center">No line items configured.</p>
        )}
      </Panel>

      {/* Approval Info */}
      {(budget.submitted_by || budget.approved_by) && (
        <Panel title="Approval History">
          <div className="space-y-2 text-sm">
            {budget.submitted_by && (
              <div className="flex items-center justify-between py-1.5 border-b border-default/50">
                <span className="text-secondary">Submitted by</span>
                <span>{budget.submitted_by.name} {budget.submitted_at ? `on ${new Date(budget.submitted_at).toLocaleDateString()}` : ''}</span>
              </div>
            )}
            {budget.approved_by && (
              <div className="flex items-center justify-between py-1.5">
                <span className="text-secondary">Approved by</span>
                <span>{budget.approved_by.name} {budget.approved_at ? `on ${new Date(budget.approved_at).toLocaleDateString()}` : ''}</span>
              </div>
            )}
          </div>
        </Panel>
      )}
    </div>
  );
}
