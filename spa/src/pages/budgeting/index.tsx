import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { budgetingApi } from '@/api/accounting/budgeting';
import { usePermission } from '@/hooks/usePermission';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import { formatPeso } from '@/lib/formatNumber';
import { Plus } from 'lucide-react';
import type { BudgetOverview } from '@/types/budgeting';

export default function BudgetOverviewPage() {
  const { can } = usePermission();
  const canManage = can('budgeting.manage');
  const navigate = useNavigate();
  const [selectedStatus, setSelectedStatus] = useState<string>('');

  const { data: overview, isLoading, error } = useQuery<BudgetOverview>({
    queryKey: ['budget-overview'],
    queryFn: () => budgetingApi.overview(),
  });

  const { data: budgetList } = useQuery({
    queryKey: ['budgets', selectedStatus],
    queryFn: () => budgetingApi.list({ status: selectedStatus || undefined, per_page: 50 }),
  });

  if (isLoading) return (
    <div className="p-6 space-y-6">
      <PageHeader title="Budget Overview" subtitle="Loading..." />
      <SkeletonTable columns={5} rows={6} />
    </div>
  );
  if (error) return (
    <div className="p-6 space-y-6">
      <PageHeader title="Budget Overview" />
      <EmptyState icon="alert-circle" title="Failed to load budget overview" />
    </div>
  );

  const getStatusColor = (pct: number) => {
    if (pct >= 120) return 'text-danger-fg bg-danger-bg';
    if (pct >= 100) return 'text-warning-fg bg-warning-bg';
    if (pct >= 95) return 'text-warning-fg bg-warning-bg';
    if (pct >= 80) return 'text-warning-fg bg-warning-bg';
    return 'text-success-fg bg-success-bg';
  };

  const getStatusDot = (pct: number) => {
    if (pct >= 95) return 'bg-danger';
    if (pct >= 80) return 'bg-warning';
    return 'bg-success';
  };

  const getStatusLabel = (pct: number) => {
    if (pct >= 120) return 'Overdrawn';
    if (pct >= 100) return 'Exhausted';
    if (pct >= 95) return 'Critical';
    if (pct >= 80) return 'Warning';
    return 'On track';
  };

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title="Budget Overview"
        subtitle={`FY ${new Date().getFullYear()} — Department Budget Summary`}
        actions={
          canManage && (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/budgeting/create')}
            >
              Create Budget
            </Button>
          )
        }
      />

      {/* Summary Cards */}
      {overview && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <StatCard label="Total Allocated" value={`₱ ${(overview.total_allocated / 1_000_000).toFixed(2)}M`} />
          <StatCard label="Total Spent" value={`₱ ${(overview.total_spent / 1_000_000).toFixed(2)}M`} />
          <StatCard label="Committed (POs)" value={`₱ ${(overview.total_committed / 1_000_000).toFixed(2)}M`} />
          <StatCard label="Available" value={`₱ ${(overview.total_available / 1_000_000).toFixed(2)}M`} />
        </div>
      )}

      {/* Utilization Bar */}
      {overview && (
        <Panel title="Overall Budget Utilization">
          <div className="space-y-2">
            <div className="flex justify-between text-sm">
              <span className="text-secondary">{overview.utilization_pct}% consumed</span>
              <span className={cn('font-medium font-mono tabular-nums', overview.utilization_pct >= 95 ? 'text-danger' : 'text-success')}>
                {formatPeso(overview.total_spent + overview.total_committed)} / {formatPeso(overview.total_allocated)}
              </span>
            </div>
            <div className="h-3 bg-muted rounded-full overflow-hidden">
              <div
                className={cn(
                  'h-full rounded-full transition-all duration-500',
                  overview.utilization_pct >= 95 ? 'bg-danger' :
                  overview.utilization_pct >= 80 ? 'bg-warning' : 'bg-success'
                )}
                style={{ width: `${Math.min(overview.utilization_pct, 100)}%` }}
              />
            </div>
          </div>
        </Panel>
      )}

      {/* By Department */}
      {overview && (
        <Panel
          title="By Department"
          meta={<Chip variant={overview.utilization_pct >= 80 ? 'warning' : 'success'}>{overview.utilization_pct}% overall</Chip>}
        >
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-muted">
                  <th className="py-2 pr-4">Department</th>
                  <th className="py-2 pr-4 text-right">Allocated</th>
                  <th className="py-2 pr-4 text-right">Spent</th>
                  <th className="py-2 pr-4 text-right">%</th>
                  <th className="py-2 text-right">Status</th>
                </tr>
              </thead>
              <tbody>
                {overview.by_department.map((dept, i) => (
                  <tr key={i} className="border-b border-default/50 hover:bg-elevated/50 transition-colors">
                    <td className="py-2.5 pr-4 font-medium">
                      <Link to={`/budgeting/departments/${encodeURIComponent(dept.department)}`} className="hover:text-accent transition-colors">
                        {dept.department}
                      </Link>
                    </td>
                    <td className="py-2.5 pr-4 text-right font-mono tabular-nums">₱ {(dept.allocated / 1_000_000).toFixed(1)}M</td>
                    <td className="py-2.5 pr-4 text-right font-mono tabular-nums">₱ {(dept.spent / 1_000_000).toFixed(1)}M</td>
                    <td className="py-2.5 pr-4 text-right">
                      <span className={cn('inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium font-mono tabular-nums', getStatusColor(dept.pct))}>
                        {dept.pct}%
                      </span>
                    </td>
                    <td className="py-2.5 text-right">
                      <span className="inline-flex items-center gap-1.5 text-xs">
                        <span className={cn('h-1.5 w-1.5 rounded-full', getStatusDot(dept.pct))} />
                        {getStatusLabel(dept.pct)}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      )}

      {/* Budget List */}
      <Panel
        title="Budgets"
        meta={
          <div className="flex gap-2">
            {['', 'draft', 'active', 'closed'].map((s) => (
              <button
                key={s}
                onClick={() => setSelectedStatus(s)}
                className={cn(
                  'px-2 py-0.5 text-xs rounded transition-colors',
                  selectedStatus === s ? 'bg-accent text-white' : 'bg-muted text-secondary hover:bg-muted/80'
                )}
              >
                {s || 'All'}
              </button>
            ))}
          </div>
        }
      >
        {budgetList && budgetList.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-muted">
                  <th className="py-2 pr-4">Name</th>
                  <th className="py-2 pr-4">Type</th>
                  <th className="py-2 pr-4 text-right">Allocated</th>
                  <th className="py-2 pr-4 text-right">Spent</th>
                  <th className="py-2 pr-4 text-right">Available</th>
                  <th className="py-2 pr-4 text-center">%</th>
                  <th className="py-2 text-center">Status</th>
                </tr>
              </thead>
              <tbody>
                {budgetList.data.map((budget) => (
                  <tr key={budget.id} className="border-b border-default/50 hover:bg-elevated/50 transition-colors">
                    <td className="py-2.5 pr-4">
                      <Link to={`/budgeting/${budget.id}`} className="font-medium hover:text-accent transition-colors">
                        {budget.name}
                      </Link>
                      {budget.department && (
                        <span className="ml-2 text-xs text-muted">{budget.department.name}</span>
                      )}
                    </td>
                    <td className="py-2.5 pr-4">
                      <Chip variant="neutral">{budget.budget_type}</Chip>
                    </td>
                    <td className="py-2.5 pr-4 text-right font-mono tabular-nums">₱ {(budget.total_allocated / 1_000).toFixed(0)}K</td>
                    <td className="py-2.5 pr-4 text-right font-mono tabular-nums">₱ {(budget.total_spent / 1_000).toFixed(0)}K</td>
                    <td className="py-2.5 pr-4 text-right font-mono tabular-nums">₱ {(budget.available / 1_000).toFixed(0)}K</td>
                    <td className="py-2.5 pr-4 text-center">
                      <span className={cn(
                        'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium font-mono tabular-nums',
                        getStatusColor(budget.utilization_pct)
                      )}>
                        {budget.utilization_pct}%
                      </span>
                    </td>
                    <td className="py-2.5 text-center">
                      <Chip variant={
                        budget.status === 'active' ? 'success' :
                        budget.status === 'draft' ? 'neutral' :
                        budget.status === 'closed' ? 'neutral' : 'warning'
                      }>
                        {budget.status}
                      </Chip>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-muted py-4 text-center">No budgets found.</p>
        )}
      </Panel>
    </div>
  );
}
