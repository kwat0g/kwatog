import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { budgetingApi } from '@/api/accounting/budgeting';
import { usePermission } from '@/hooks/usePermission';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { Select } from '@/components/ui/Select';
import { cn } from '@/lib/cn';
import { formatCompactCurrency, formatPeso } from '@/lib/formatNumber';
import { LuPlus } from '@/lib/icons';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { QueryErrorState } from '@/components/ui/QueryErrorState';

export default function BudgetOverviewPage() {
  const { can } = usePermission();
  const canManage = can('budgeting.manage');
  const navigate = useNavigate();
  const [selectedStatus, setSelectedStatus] = useState('');
  const [selectedFiscalYearId, setSelectedFiscalYearId] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const fiscalYearsQuery = useQuery({
    queryKey: ['budget-fiscal-years'],
    queryFn: () => budgetingApi.fiscalYears(),
  });
  const fiscalYears = useMemo(() => fiscalYearsQuery.data ?? [], [fiscalYearsQuery.data]);
  useEffect(() => {
    if (!selectedFiscalYearId && fiscalYears.length > 0) {
      setSelectedFiscalYearId(
        fiscalYears.find((fy) => fy.status === 'active')?.id ?? fiscalYears[0].id,
      );
    }
  }, [fiscalYears, selectedFiscalYearId]);

  const overviewQuery = useQuery({
    queryKey: ['budget-overview', selectedFiscalYearId],
    queryFn: () => budgetingApi.overview(selectedFiscalYearId),
    enabled: !!selectedFiscalYearId,
  });
  const { data: overview, isLoading: overviewLoading, error } = overviewQuery;

  const budgetListQuery = useQuery({
    queryKey: ['budgets', selectedFiscalYearId, selectedStatus, page, perPage],
    queryFn: () => budgetingApi.list({
      fiscal_year_id: selectedFiscalYearId,
      status: selectedStatus || undefined,
      page,
      per_page: perPage,
    }),
    enabled: !!selectedFiscalYearId,
  });

  const { data: budgetOptions } = useQuery({
    queryKey: ['budgets', 'options'],
    queryFn: () => budgetingApi.options(),
  });

  useEffect(() => setPage(1), [selectedFiscalYearId, selectedStatus, perPage]);

  if (fiscalYearsQuery.isLoading || overviewLoading) {
    return (
      <div className="p-5 space-y-6">
        <PageHeader title="Budget Overview" subtitle="Loading..." />
        <SkeletonTable columns={5} rows={6} />
      </div>
    );
  }
  if (error) {
    return (
      <div className="p-5 space-y-6">
        <PageHeader title="Budget Overview" />
        <QueryErrorState subject="the budget overview" onRetry={() => void overviewQuery.refetch()} />
      </div>
    );
  }

  const fiscalYear = fiscalYears.find((fy) => fy.id === selectedFiscalYearId);
  const getStatusColor = (pct: number) => {
    const warning = budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY;
    const critical = budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY;
    const exhausted = budgetOptions?.exhausted_ratio_pct ?? Number.POSITIVE_INFINITY;
    if (pct >= 100 || pct >= exhausted) return 'text-danger-fg bg-danger-bg';
    if (pct >= critical || pct >= warning) return 'text-warning-fg bg-warning-bg';
    return 'text-success-fg bg-success-bg';
  };
  const getStatusDot = (pct: number) => pct >= (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
    ? 'bg-danger-bg'
    : pct >= (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)
      ? 'bg-warning-bg'
      : 'bg-success-bg';
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
        subtitle={`FY ${fiscalYear?.year ?? '—'} — Department Budget Summary`}
        actions={
          <div className="flex items-center gap-2">
            <Select
              fieldSize="sm"
              aria-label="Fiscal year"
              value={selectedFiscalYearId}
              onChange={(event) => setSelectedFiscalYearId(event.target.value)}
            >
              {fiscalYears.map((fy) => <option key={fy.id} value={fy.id}>FY {fy.year}</option>)}
            </Select>
            {canManage && (
              <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/budgeting/create')}>
                Create Budget
              </Button>
            )}
          </div>
        }
      />

      {overview && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <StatCard label="Total Allocated" value={formatCompactCurrency(overview.total_allocated, 1_000_000, 'M')} />
          <StatCard label="Total Spent" value={formatCompactCurrency(overview.total_spent, 1_000_000, 'M')} />
          <StatCard label="Committed (POs)" value={formatCompactCurrency(overview.total_committed, 1_000_000, 'M')} />
          <StatCard label="Available" value={formatCompactCurrency(overview.total_available, 1_000_000, 'M')} />
        </div>
      )}

      {overview && (
        <Panel title="Overall Budget Utilization">
          <div className="space-y-2">
            <div className="flex justify-between text-sm">
              <span className="text-secondary">{overview.utilization_pct}% consumed</span>
              <span className="font-medium font-mono tabular-nums">
                {formatPeso(Number(overview.total_spent) + Number(overview.total_committed))} / {formatPeso(overview.total_allocated)}
              </span>
            </div>
            <div className="h-3 bg-subtle rounded-full overflow-hidden">
              <div
                className={cn(
                  'h-full rounded-full transition-[width] duration-500',
                  overview.utilization_pct >= (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
                    ? 'bg-danger-bg'
                    : overview.utilization_pct >= (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)
                      ? 'bg-warning-bg'
                      : 'bg-success-bg',
                )}
                style={{ width: `${Math.min(overview.utilization_pct, 100)}%` }}
              />
            </div>
          </div>
        </Panel>
      )}

      {overview && (
        <Panel title="By Department" meta={<Chip variant={overview.utilization_pct >= (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY) ? 'warning' : 'success'}>{overview.utilization_pct}% overall</Chip>}>
          <div className="overflow-x-auto">
            <table className={tableCls}>
              <thead><tr className={theadTrCls}><Th>Department</Th><Th align="right">Allocated</Th><Th align="right">Spent</Th><Th align="right">%</Th><Th align="right">Status</Th></tr></thead>
              <tbody>
                {overview.by_department.map((dept) => {
                  const target = dept.department_id ?? 'company-wide';
                  const href = `/budgeting/departments/${encodeURIComponent(target)}?fiscal_year_id=${encodeURIComponent(selectedFiscalYearId)}`;
                  return (
                    <tr key={target} className={cn(trCls, 'cursor-pointer')} onClick={() => navigate(href)}>
                      <Td className="font-medium"><Link to={href} onClick={(event) => event.stopPropagation()} className="hover:text-accent transition-colors">{dept.department}</Link></Td>
                      <Td align="right" mono>{formatCompactCurrency(dept.allocated, 1_000_000, 'M')}</Td>
                      <Td align="right" mono>{formatCompactCurrency(dept.spent, 1_000_000, 'M')}</Td>
                      <Td align="right" mono><span className={cn('inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium font-mono tabular-nums', getStatusColor(dept.pct))}>{dept.pct}%</span></Td>
                      <Td align="right" mono><span className="inline-flex items-center gap-1.5 text-xs"><span className={cn('h-1.5 w-1.5 rounded-full', getStatusDot(dept.pct))} />{getStatusLabel(dept.pct)}</span></Td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Panel>
      )}

      <Panel title="Budgets" meta={<SegmentedControl size="sm" label="Budget status" value={selectedStatus} onChange={setSelectedStatus} options={[{ value: '', label: 'All' }, ...(budgetOptions?.statuses ?? [])]} />}>
        {budgetListQuery.data && budgetListQuery.data.data.length > 0 ? (
          <>
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead><tr className={theadTrCls}><Th>Name</Th><Th>Type</Th><Th align="right">Allocated</Th><Th align="right">Spent</Th><Th align="right">Available</Th><Th align="center">%</Th><Th align="center">Status</Th></tr></thead>
                <tbody>
                  {budgetListQuery.data.data.map((budget) => (
                    <tr key={budget.id} className={cn(trCls, 'cursor-pointer')} onClick={() => navigate(`/budgeting/${budget.id}`)}>
                      <Td><Link to={`/budgeting/${budget.id}`} onClick={(event) => event.stopPropagation()} className="font-medium hover:text-accent transition-colors">{budget.name}</Link>{budget.department && <span className="ml-2 text-xs text-muted">{budget.department.name}</span>}</Td>
                      <Td><Chip variant="neutral">{budget.budget_type}</Chip></Td>
                      <Td align="right" mono>{formatCompactCurrency(budget.total_allocated, 1_000, 'K')}</Td>
                      <Td align="right" mono>{formatCompactCurrency(budget.total_spent, 1_000, 'K')}</Td>
                      <Td align="right" mono>{formatCompactCurrency(budget.available, 1_000, 'K')}</Td>
                      <Td align="center"><span className={cn('inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium font-mono tabular-nums', getStatusColor(budget.utilization_pct))}>{budget.utilization_pct}%</span></Td>
                      <Td align="center"><Chip variant={budget.status === 'active' ? 'success' : budget.status === 'draft' ? 'neutral' : budget.status === 'closed' ? 'neutral' : 'warning'}>{budget.status_label ?? budget.status}</Chip></Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <DataTablePagination
              meta={budgetListQuery.data.meta}
              perPage={perPage}
              onPageChange={setPage}
              onPageSizeChange={setPerPage}
            />
          </>
        ) : <p className="text-sm text-muted py-4 text-center">No budgets found.</p>}
      </Panel>
    </div>
  );
}
