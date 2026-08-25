import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useParams, useSearchParams, Link } from 'react-router-dom';
import { budgetingApi } from '@/api/accounting/budgeting';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { Select } from '@/components/ui/Select';
import { LuArrowLeft, LuBuilding2 } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { formatCompactCurrency } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as const;

export default function DepartmentBudgetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [searchParams, setSearchParams] = useSearchParams();
  const [selectedFiscalYearId, setSelectedFiscalYearId] = useState(searchParams.get('fiscal_year_id') ?? '');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const companyWide = id === 'company-wide';

  const fiscalYearsQuery = useQuery({ queryKey: ['budget-fiscal-years'], queryFn: () => budgetingApi.fiscalYears() });
  const fiscalYears = useMemo(() => fiscalYearsQuery.data ?? [], [fiscalYearsQuery.data]);
  useEffect(() => {
    if (!selectedFiscalYearId && fiscalYears.length > 0) {
      setSelectedFiscalYearId(fiscalYears.find((fy) => fy.status === 'active')?.id ?? fiscalYears[0].id);
    }
  }, [fiscalYears, selectedFiscalYearId]);
  useEffect(() => {
    if (selectedFiscalYearId) setSearchParams({ fiscal_year_id: selectedFiscalYearId });
  }, [selectedFiscalYearId, setSearchParams]);
  useEffect(() => setPage(1), [id, selectedFiscalYearId, perPage]);

  const budgetQuery = useQuery({
    queryKey: ['budgets', 'department', id, selectedFiscalYearId, page, perPage],
    queryFn: () => budgetingApi.list({
      fiscal_year_id: selectedFiscalYearId,
      ...(companyWide ? { company_wide: true } : { department_id: id }),
      page,
      per_page: perPage,
    }),
    enabled: !!id && !!selectedFiscalYearId,
  });
  const { data: budgetOptions } = useQuery({ queryKey: ['budgets', 'options'], queryFn: () => budgetingApi.options(), staleTime: 300_000 });

  if (fiscalYearsQuery.isLoading || budgetQuery.isLoading) return <SkeletonDetail />;
  if (budgetQuery.isError) {
    return <EmptyState icon="alert-circle" title="Failed to load budgets" action={<Button variant="secondary" onClick={() => void budgetQuery.refetch()}>Retry</Button>} />;
  }

  const budgets = budgetQuery.data?.data ?? [];
  const departmentName = companyWide ? 'Company-wide' : budgets.find((budget) => budget.department)?.department?.name ?? 'Department';
  const fiscalYear = fiscalYears.find((fy) => fy.id === selectedFiscalYearId);
  const totalAllocated = budgets.reduce((sum, budget) => sum + Number(budget.total_allocated), 0);
  const totalSpent = budgets.reduce((sum, budget) => sum + Number(budget.total_spent), 0);
  const totalCommitted = budgets.reduce((sum, budget) => sum + Number(budget.total_committed), 0);
  const totalAvailable = budgets.reduce((sum, budget) => sum + Number(budget.available), 0);
  const utilizationPct = totalAllocated > 0 ? ((totalSpent + totalCommitted) / totalAllocated) * 100 : 0;
  const getBarColor = (pct: number) => pct >= (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
    ? 'bg-danger-bg'
    : pct >= (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)
      ? 'bg-warning-bg'
      : 'bg-success-bg';
  const getTextColor = (pct: number) => pct >= (budgetOptions?.critical_ratio_pct ?? Number.POSITIVE_INFINITY)
    ? 'text-danger-fg'
    : pct >= (budgetOptions?.warning_ratio_pct ?? Number.POSITIVE_INFINITY)
      ? 'text-warning-fg'
      : 'text-success-fg';

  return (
    <div className="p-5 space-y-6">
      <PageHeader
        title={`${departmentName} Budget`}
        subtitle={`FY ${fiscalYear?.year ?? '—'} — Monthly Budget vs Actual Breakdown`}
        actions={
          <div className="flex items-center gap-2">
            <Select fieldSize="sm" aria-label="Fiscal year" value={selectedFiscalYearId} onChange={(event) => setSelectedFiscalYearId(event.target.value)}>
              {fiscalYears.map((fy) => <option key={fy.id} value={fy.id}>FY {fy.year}</option>)}
            </Select>
            <Link to="/budgeting" className="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary transition-colors"><LuArrowLeft size={14} /> Back to Overview</Link>
          </div>
        }
      />

      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard label="Total Allocated" value={formatCompactCurrency(totalAllocated, 1_000_000, 'M')} />
        <StatCard label="Total Spent" value={formatCompactCurrency(totalSpent, 1_000_000, 'M')} />
        <StatCard label="Committed" value={formatCompactCurrency(totalCommitted, 1_000_000, 'M')} />
        <StatCard label="Available" value={formatCompactCurrency(totalAvailable, 1_000_000, 'M')} />
      </div>

      <div className="space-y-1.5">
        <div className="flex justify-between text-sm"><span className="text-secondary">{utilizationPct.toFixed(1)}% consumed</span><span className="font-medium font-mono tabular-nums">{formatCompactCurrency(totalSpent + totalCommitted, 1_000_000, 'M')} / {formatCompactCurrency(totalAllocated, 1_000_000, 'M')}</span></div>
        <div className="h-3 bg-subtle rounded-full overflow-hidden"><div className={cn('h-full rounded-full transition-[width] duration-500', getBarColor(utilizationPct))} style={{ width: `${Math.min(utilizationPct, 100)}%` }} /></div>
      </div>

      {budgets.map((budget) => (
        <Panel key={budget.id} title={budget.name} meta={<div className="flex items-center gap-3"><Chip variant={chipVariantForStatus(budget.status)}>{budget.status_label ?? budget.status}</Chip><span className={cn('text-xs font-medium px-1.5 py-0.5 rounded', getTextColor(budget.utilization_pct))}>{budget.utilization_pct}% used</span></div>}>
          {budget.line_items && budget.line_items.length > 0 ? (
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead><tr className={theadTrCls}><Th className="sticky left-0 bg-canvas">Account</Th>{MONTHS.map((month) => <Th align="right" className="font-mono" key={month}>{month}</Th>)}<Th align="right">Annual</Th><Th align="right">Actual</Th><Th align="right">Variance</Th></tr></thead>
                <tbody>
                  {budget.line_items.map((line) => (
                    <tr key={line.id} className={trCls}>
                      <Td className="sticky left-0 bg-canvas"><span className="font-medium">{line.account?.code}</span><span className="ml-1.5 text-muted">{line.account?.name}</span></Td>
                      {MONTHS.map((month) => { const value = Number(line[month.toLowerCase() as keyof typeof line]); return <Td align="right" mono key={month}>{value > 0 ? formatCompactCurrency(value, 1_000, 'K') : '-'}</Td>; })}
                      <Td align="right" mono className="font-medium">{formatCompactCurrency(line.annual_total, 1_000, 'K')}</Td>
                      <Td align="right" mono>{formatCompactCurrency(line.actual_total, 1_000, 'K')}</Td>
                      <Td align="right" mono className={cn(Number(line.variance) < 0 ? 'text-danger-fg' : 'text-success-fg')}>{Number(line.variance) >= 0 ? '+' : '-'}{formatCompactCurrency(Math.abs(Number(line.variance)), 1_000, 'K')}</Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : <p className="text-sm text-muted py-4 text-center">No line items configured.</p>}
        </Panel>
      ))}

      {budgets.length === 0 && <div className="text-center py-12 text-muted"><LuBuilding2 size={48} className="mx-auto mb-3 opacity-40" /><p className="text-lg font-medium">No budgets found</p><p className="text-sm mt-1">This selection has no budgets for the selected fiscal year.</p></div>}
      {budgetQuery.data && <DataTablePagination meta={budgetQuery.data.meta} perPage={perPage} onPageChange={setPage} onPageSizeChange={setPerPage} />}
    </div>
  );
}
