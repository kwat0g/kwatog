import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, Cell } from 'recharts';
import { LuRefreshCw } from '@/lib/icons';
import { budgetingApi } from '@/api/accounting/budgeting';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Select } from '@/components/ui/Select';
import { Chip } from '@/components/ui/Chip';
import { cn } from '@/lib/cn';
import { formatPeso, formatCompactCurrency } from '@/lib/formatNumber';
import type { BudgetVsActual, BudgetVsActualRow } from '@/types/budgeting';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';

export default function BudgetVsActualPage() {
  const [groupBy, setGroupBy] = useState('department');
  const [selectedFiscalYearId, setSelectedFiscalYearId] = useState('');
  const { can } = usePermission();
  const queryClient = useQueryClient();

  const fiscalYearsQuery = useQuery({ queryKey: ['budget-fiscal-years'], queryFn: () => budgetingApi.fiscalYears() });
  const fiscalYears = useMemo(() => fiscalYearsQuery.data ?? [], [fiscalYearsQuery.data]);
  useEffect(() => {
    if (!selectedFiscalYearId && fiscalYears.length > 0) setSelectedFiscalYearId(fiscalYears.find((fy) => fy.status === 'active')?.id ?? fiscalYears[0].id);
  }, [fiscalYears, selectedFiscalYearId]);
  const fiscalYear = fiscalYears.find((fy) => fy.id === selectedFiscalYearId);

  const reportQuery = useQuery<BudgetVsActual>({
    queryKey: ['budget-vs-actual', selectedFiscalYearId],
    queryFn: () => budgetingApi.budgetVsActual(selectedFiscalYearId),
    enabled: !!selectedFiscalYearId,
  });
  const syncStatusQuery = useQuery({
    queryKey: ['budget-sync-status', selectedFiscalYearId],
    queryFn: () => budgetingApi.syncStatus(selectedFiscalYearId),
    enabled: !!selectedFiscalYearId,
    refetchInterval: 5000,
  });
  const syncActuals = useMutation({
    mutationFn: () => budgetingApi.syncActuals(selectedFiscalYearId),
    onSuccess: () => {
      toast.success('Budget actuals rebuild queued.');
      void syncStatusQuery.refetch();
      queryClient.invalidateQueries({ queryKey: ['budget-vs-actual', selectedFiscalYearId] });
    },
    onError: () => toast.error('Failed to queue the budget actuals rebuild.'),
  });

  const data = reportQuery.data;
  const syncRun = syncStatusQuery.data;
  const chartData = useMemo(() => data?.rows
    ? [...data.rows].sort((a, b) => Math.abs(Number(b.variance)) - Math.abs(Number(a.variance))).slice(0, 10).map((row) => ({ name: row.account_code, budgeted: Number(row.budgeted), actual: Number(row.actual), variance: Number(row.variance) }))
    : [], [data]);

  if (fiscalYearsQuery.isLoading || reportQuery.isLoading) return <SkeletonDetail />;
  if (reportQuery.error) return <div className="p-5 text-danger-fg">Failed to load budget vs actual data.</div>;

  const grouped: Record<string, { rows: BudgetVsActualRow[]; budgeted: number; actual: number }> = {};
  data?.rows.forEach((row) => {
    const key = groupBy === 'department' ? row.department : row.budget_type;
    if (!grouped[key]) grouped[key] = { rows: [], budgeted: 0, actual: 0 };
    grouped[key].rows.push(row);
    grouped[key].budgeted += Number(row.budgeted);
    grouped[key].actual += Number(row.actual);
  });
  const totalBudgeted = Number(data?.total_budgeted ?? 0);
  const totalVariance = Number(data?.total_variance ?? 0);
  const totalVariancePct = totalBudgeted > 0 ? (totalVariance / totalBudgeted) * 100 : 0;
  const isFavorable = totalVariance >= 0;
  const isSyncing = syncRun?.status === 'queued' || syncRun?.status === 'running';

  return (
    <div className="p-5 space-y-6">
      <PageHeader
        title="Budget vs Actual"
        subtitle={`FY ${fiscalYear?.year ?? '—'} — P&L comparison`}
        actions={
          <div className="flex items-center gap-2">
            <Select fieldSize="sm" aria-label="Fiscal year" value={selectedFiscalYearId} onChange={(event) => setSelectedFiscalYearId(event.target.value)}>
              {fiscalYears.map((fy) => <option key={fy.id} value={fy.id}>FY {fy.year}</option>)}
            </Select>
            {can('budgeting.manage') && <Button variant="secondary" size="xs" icon={<LuRefreshCw size={13} />} onClick={() => syncActuals.mutate()} disabled={syncActuals.isPending || isSyncing} loading={syncActuals.isPending}>{isSyncing ? 'Rebuilding…' : 'Rebuild actuals'}</Button>}
            <span className="text-xs text-muted">Group by</span>
            <SegmentedControl size="sm" label="Group by" value={groupBy} onChange={setGroupBy} options={[{ value: 'department', label: 'Department' }, { value: 'budget_type', label: 'Budget type' }]} />
          </div>
        }
      />

      {syncRun && (
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted border border-default rounded-md px-3 py-2">
          <Chip variant={syncRun.status === 'completed' ? 'success' : syncRun.status === 'failed' ? 'danger' : 'warning'}>{syncRun.status}</Chip>
          <span>{syncRun.processed_lines} / {syncRun.total_lines} lines</span>
          {syncRun.completed_at && <span>Last completed {new Date(syncRun.completed_at).toLocaleString()}</span>}
          {syncRun.last_error && <span className="text-danger-fg">{syncRun.last_error}</span>}
        </div>
      )}

      {data && <>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <StatCard label="Total Budgeted" value={formatCompactCurrency(data.total_budgeted, 1_000_000, 'M')} />
          <StatCard label="Total Actual" value={formatCompactCurrency(data.total_actual, 1_000_000, 'M')} />
          <StatCard label="Total Variance" value={`${isFavorable ? '+' : ''}${totalVariancePct.toFixed(1)}%`} />
        </div>

        {chartData.length > 0 && <Panel title="Variance by account" meta="top 10 accounts"><div className="h-56"><ResponsiveContainer width="100%" height="100%"><BarChart data={chartData} margin={{ top: 8, right: 8, left: 0, bottom: 8 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" vertical={false} /><XAxis dataKey="name" tick={{ fontSize: 10, fill: 'var(--text-muted)' }} /><YAxis tick={{ fontSize: 10, fill: 'var(--text-muted)' }} tickFormatter={(value: number) => formatCompactCurrency(value, 1_000, 'K')} width={56} /><Tooltip contentStyle={{ background: 'var(--bg-elevated)', border: '1px solid var(--border-default)', borderRadius: 'var(--radius-md)', fontSize: 12 }} formatter={(value: number) => formatPeso(value)} /><Legend wrapperStyle={{ fontSize: 11 }} /><Bar dataKey="budgeted" name="Budgeted" fill="var(--info)" radius={[3, 3, 0, 0]} maxBarSize={32} /><Bar dataKey="actual" name="Actual" radius={[3, 3, 0, 0]} maxBarSize={32}>{chartData.map((entry, index) => <Cell key={index} fill={entry.actual > entry.budgeted ? 'var(--danger)' : 'var(--success)'} />)}</Bar></BarChart></ResponsiveContainer></div></Panel>}

        <Panel title="Summary by Group"><div className="overflow-x-auto"><table className={tableCls}><thead><tr className={theadTrCls}><Th>Group</Th><Th align="right">Budgeted</Th><Th align="right">Actual</Th><Th align="right">Variance</Th><Th align="right">%</Th></tr></thead><tbody>{Object.entries(grouped).map(([key, group]) => { const variance = group.budgeted - group.actual; const pct = group.budgeted > 0 ? (variance / group.budgeted) * 100 : 0; return <tr key={key} className={trCls}><Td className="font-medium">{key}</Td><Td align="right" mono>{formatCompactCurrency(group.budgeted, 1_000_000, 'M')}</Td><Td align="right" mono>{formatCompactCurrency(group.actual, 1_000_000, 'M')}</Td><Td align="right" mono className={cn(variance < 0 ? 'text-danger-fg' : 'text-success-fg')}>{variance >= 0 ? '+' : '-'}{formatCompactCurrency(Math.abs(variance), 1_000_000, 'M')}</Td><Td align="right" mono><span className={cn('inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium', pct < 0 ? 'text-danger-fg bg-danger-bg' : 'text-success-fg bg-success-bg')}>{pct >= 0 ? '+' : ''}{pct.toFixed(1)}%</span></Td></tr>; })}</tbody></table></div></Panel>

        <Panel title="Line Item Detail"><div className="overflow-x-auto max-h-[500px] overflow-y-auto"><table className={tableCls}><thead className="sticky top-0 bg-canvas"><tr className={theadTrCls}><Th>Account</Th><Th>Department</Th><Th align="right">Budgeted</Th><Th align="right">Actual</Th><Th align="right">Variance</Th><Th align="right">%</Th></tr></thead><tbody>{data.rows.map((row, index) => { const variance = Number(row.variance); return <tr key={`${row.budget_id}-${row.account_code}-${index}`} className={trCls}><Td><span className="font-medium">{row.account_code}</span><span className="ml-1 text-muted text-xs">{row.account_name}</span></Td><Td className="text-secondary">{row.department}</Td><Td align="right" mono>{formatCompactCurrency(row.budgeted, 1_000, 'K')}</Td><Td align="right" mono>{formatCompactCurrency(row.actual, 1_000, 'K')}</Td><Td align="right" mono className={cn(variance < 0 ? 'text-danger-fg' : 'text-success-fg')}>{variance >= 0 ? '+' : '-'}{formatCompactCurrency(Math.abs(variance), 1_000, 'K')}</Td><Td align="right" mono><span className={cn('inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium', row.variance_pct < 0 ? 'text-danger-fg bg-danger-bg' : 'text-success-fg bg-success-bg')}>{row.variance_pct >= 0 ? '+' : ''}{row.variance_pct}%</span></Td></tr>; })}</tbody></table></div></Panel>
      </>}
    </div>
  );
}
