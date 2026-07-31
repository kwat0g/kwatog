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
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { SegmentedControl } from '@/components/ui/SegmentedControl';

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
    <div className="p-5 space-y-6">
      <PageHeader title="Budget Overview" subtitle="Loading..." />
      <SkeletonTable columns={5} rows={6} />
    </div>
  );
  if (error) return (
    <div className="p-5 space-y-6">
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
    <div className="p-5 space-y-6">
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
            <div className="h-3 bg-subtle rounded-full overflow-hidden">
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
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Department</Th>
                  <Th align="right">Allocated</Th>
                  <Th align="right">Spent</Th>
                  <Th align="right">%</Th>
                  <Th align="right">Status</Th>
                </tr>
              </thead>
              <tbody>
                {overview.by_department.map((dept, i) => (
                  <tr key={i} className={trCls}>
                    <Td className="font-medium">
                      <Link to={`/budgeting/departments/${encodeURIComponent(dept.department)}`} className="hover:text-accent transition-colors">
                        {dept.department}
                      </Link>
                    </Td>
                    <Td align="right" mono>₱ {(dept.allocated / 1_000_000).toFixed(1)}M</Td>
                    <Td align="right" mono>₱ {(dept.spent / 1_000_000).toFixed(1)}M</Td>
                    <Td align="right" mono>
                      <span className={cn('inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium font-mono tabular-nums', getStatusColor(dept.pct))}>
                        {dept.pct}%
                      </span>
                    </Td>
                    <Td align="right" mono>
                      <span className="inline-flex items-center gap-1.5 text-xs">
                        <span className={cn('h-1.5 w-1.5 rounded-full', getStatusDot(dept.pct))} />
                        {getStatusLabel(dept.pct)}
                      </span>
                    </Td>
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
          <SegmentedControl
            size="sm"
            label="Budget status"
            value={selectedStatus}
            onChange={setSelectedStatus}
            options={[
              { value: '', label: 'All' },
              { value: 'draft', label: 'Draft' },
              { value: 'active', label: 'Active' },
              { value: 'closed', label: 'Closed' },
            ]}
          />
        }
      >
        {budgetList && budgetList.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Name</Th>
                  <Th>Type</Th>
                  <Th align="right">Allocated</Th>
                  <Th align="right">Spent</Th>
                  <Th align="right">Available</Th>
                  <Th align="center">%</Th>
                  <Th align="center">Status</Th>
                </tr>
              </thead>
              <tbody>
                {budgetList.data.map((budget) => (
                  <tr key={budget.id} className={trCls}>
                    <Td>
                      <Link to={`/budgeting/${budget.id}`} className="font-medium hover:text-accent transition-colors">
                        {budget.name}
                      </Link>
                      {budget.department && (
                        <span className="ml-2 text-xs text-muted">{budget.department.name}</span>
                      )}
                    </Td>
                    <Td>
                      <Chip variant="neutral">{budget.budget_type}</Chip>
                    </Td>
                    <Td align="right" mono>₱ {(budget.total_allocated / 1_000).toFixed(0)}K</Td>
                    <Td align="right" mono>₱ {(budget.total_spent / 1_000).toFixed(0)}K</Td>
                    <Td align="right" mono>₱ {(budget.available / 1_000).toFixed(0)}K</Td>
                    <Td align="center">
                      <span className={cn(
                        'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium font-mono tabular-nums',
                        getStatusColor(budget.utilization_pct)
                      )}>
                        {budget.utilization_pct}%
                      </span>
                    </Td>
                    <Td align="center">
                      <Chip variant={
                        budget.status === 'active' ? 'success' :
                        budget.status === 'draft' ? 'neutral' :
                        budget.status === 'closed' ? 'neutral' : 'warning'
                      }>
                        {budget.status}
                      </Chip>
                    </Td>
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
