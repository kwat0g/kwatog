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
import { formatCompactCurrency, formatPeso } from '@/lib/formatNumber';
import { Plus } from 'lucide-react';
import type { BudgetOverview } from '@/types/budgeting';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { SegmentedControl } from '@/components/ui/SegmentedControl';

export default function BudgetOverviewPage() {
  const { can } = usePermission();
  const canManage = can('budgeting.manage');
  const navigate = useNavigate();
  const [selectedStatus, setSelectedStatus] = useState<string>('');

  const {
    data: overview,
    isLoading,
    error,
  } = useQuery<BudgetOverview>({
    queryKey: ['budget-overview'],
    queryFn: () => budgetingApi.overview(),
  });

  const { data: budgetList } = useQuery({
    queryKey: ['budgets', selectedStatus],
    queryFn: () => budgetingApi.list({ status: selectedStatus || undefined, per_page: 50 }),
  });

  const { data: budgetOptions } = useQuery({
    queryKey: ['budgets', 'options'],
    queryFn: () => budgetingApi.options(),
  });

  if (isLoading)
    return (
      <div className="p-5 space-y-6">
        <PageHeader title="Budget Overview" subtitle="Loading..." />
        <SkeletonTable columns={5} rows={6} />
      </div>
    );
  if (error)
    return (
      <div className="p-5 space-y-6">
        <PageHeader title="Budget Overview" />
        <EmptyState icon="alert-circle" title="Failed to load budget overview" />
      </div>
    );

  const getStatusColor = (pct: number) => {
    const warning = budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY;
    const critical = budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY;
    const exhausted = budgetOptions?.exhausted_ratio_pct ?? Number.POSITIVE_INFINITY;
    if (pct >= 100) return 'text-danger-fg bg-danger-bg';
    if (pct >= exhausted) return 'text-danger-fg bg-danger-bg';
    if (pct >= critical) return 'text-warning-fg bg-warning-bg';
    if (pct >= warning) return 'text-warning-fg bg-warning-bg';
    return 'text-success-fg bg-success-bg';
  };

  const getStatusDot = (pct: number) => {
    if (pct >= (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY))
      return 'bg-danger-bg';
    if (pct >= (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY))
      return 'bg-warning-bg';
    return 'bg-success-bg';
  };

  const getStatusLabel = (pct: number) => {
    if (pct >= 100) return 'Overdrawn';
    if (pct >= (budgetOptions?.exhausted_ratio_pct ?? Number.POSITIVE_INFINITY)) return 'Exhausted';
    if (pct >= (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)) return 'Critical';
    if (pct >= (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)) return 'Warning';
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
          <StatCard
            label="Total Allocated"
            value={formatCompactCurrency(overview.total_allocated, 1_000_000, 'M')}
          />
          <StatCard
            label="Total Spent"
            value={formatCompactCurrency(overview.total_spent, 1_000_000, 'M')}
          />
          <StatCard
            label="Committed (POs)"
            value={formatCompactCurrency(overview.total_committed, 1_000_000, 'M')}
          />
          <StatCard
            label="Available"
            value={formatCompactCurrency(overview.total_available, 1_000_000, 'M')}
          />
        </div>
      )}

      {/* Utilization Bar */}
      {overview && (
        <Panel title="Overall Budget Utilization">
          <div className="space-y-2">
            <div className="flex justify-between text-sm">
              <span className="text-secondary">{overview.utilization_pct}% consumed</span>
              <span
                className={cn(
                  'font-medium font-mono tabular-nums',
                  overview.utilization_pct >=
                    (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
                    ? 'text-danger-fg'
                    : 'text-success-fg',
                )}
              >
                {formatPeso(overview.total_spent + overview.total_committed)} /{' '}
                {formatPeso(overview.total_allocated)}
              </span>
            </div>
            <div className="h-3 bg-subtle rounded-full overflow-hidden">
              <div
                className={cn(
                  'h-full rounded-full transition-[width] duration-500',
                  overview.utilization_pct >=
                    (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
                    ? 'bg-danger-bg'
                    : overview.utilization_pct >=
                        (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)
                      ? 'bg-warning-bg'
                      : 'bg-success-bg',
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
          meta={
            <Chip
              variant={
                overview.utilization_pct >=
                (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)
                  ? 'warning'
                  : 'success'
              }
            >
              {overview.utilization_pct}% overall
            </Chip>
          }
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
                  <tr
                    key={i}
                    className={cn(trCls, 'cursor-pointer')}
                    onClick={() =>
                      navigate(`/budgeting/departments/${encodeURIComponent(dept.department)}`)
                    }
                  >
                    <Td className="font-medium">
                      <Link
                        to={`/budgeting/departments/${encodeURIComponent(dept.department)}`}
                        onClick={(e) => e.stopPropagation()}
                        className="hover:text-accent transition-colors"
                      >
                        {dept.department}
                      </Link>
                    </Td>
                    <Td align="right" mono>
                      {formatCompactCurrency(dept.allocated, 1_000_000, 'M')}
                    </Td>
                    <Td align="right" mono>
                      {formatCompactCurrency(dept.spent, 1_000_000, 'M')}
                    </Td>
                    <Td align="right" mono>
                      <span
                        className={cn(
                          'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium font-mono tabular-nums',
                          getStatusColor(dept.pct),
                        )}
                      >
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
            options={[{ value: '', label: 'All' }, ...(budgetOptions?.statuses ?? [])]}
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
                  <tr
                    key={budget.id}
                    className={cn(trCls, 'cursor-pointer')}
                    onClick={() => navigate(`/budgeting/${budget.id}`)}
                  >
                    <Td>
                      <Link
                        to={`/budgeting/${budget.id}`}
                        onClick={(e) => e.stopPropagation()}
                        className="font-medium hover:text-accent transition-colors"
                      >
                        {budget.name}
                      </Link>
                      {budget.department && (
                        <span className="ml-2 text-xs text-muted">{budget.department.name}</span>
                      )}
                    </Td>
                    <Td>
                      <Chip variant="neutral">{budget.budget_type}</Chip>
                    </Td>
                    <Td align="right" mono>
                      {formatCompactCurrency(budget.total_allocated, 1_000, 'K')}
                    </Td>
                    <Td align="right" mono>
                      {formatCompactCurrency(budget.total_spent, 1_000, 'K')}
                    </Td>
                    <Td align="right" mono>
                      {formatCompactCurrency(budget.available, 1_000, 'K')}
                    </Td>
                    <Td align="center">
                      <span
                        className={cn(
                          'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium font-mono tabular-nums',
                          getStatusColor(budget.utilization_pct),
                        )}
                      >
                        {budget.utilization_pct}%
                      </span>
                    </Td>
                    <Td align="center">
                      <Chip
                        variant={
                          budget.status === 'active'
                            ? 'success'
                            : budget.status === 'draft'
                              ? 'neutral'
                              : budget.status === 'closed'
                                ? 'neutral'
                                : 'warning'
                        }
                      >
                        {budget.status_label ?? budget.status}
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
