import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, Cell } from 'recharts';
import { budgetingApi } from '@/api/accounting/budgeting';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { cn } from '@/lib/cn';
import { formatPeso, formatCompactCurrency } from '@/lib/formatNumber';
import type { BudgetVsActual, BudgetVsActualRow } from '@/types/budgeting';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { SegmentedControl } from '@/components/ui/SegmentedControl';

export default function BudgetVsActualPage() {
 const [groupBy, setGroupBy] = useState<string>('department');

 const { data, isLoading, error } = useQuery<BudgetVsActual>({
 queryKey: ['budget-vs-actual'],
 queryFn: () => budgetingApi.budgetVsActual(),
 });

 // Top 10 rows by absolute variance for chart (keep chart readable)
 const chartData = useMemo(() => {
 if (!data?.rows) return [];
 return [...data.rows]
 .sort((a, b) => Math.abs(b.variance) - Math.abs(a.variance))
 .slice(0, 10)
 .map((r) => ({
 name: r.account_code,
 budgeted: r.budgeted,
 actual: r.actual,
 variance: r.variance,
 }));
 }, [data]);

 if (isLoading) return <SkeletonDetail />;
 if (error) return <div className="p-5 text-danger-fg">Failed to load budget vs actual data.</div>;

 // Group rows
 const grouped: Record<string, { rows: BudgetVsActualRow[]; budgeted: number; actual: number }> = {};
 data?.rows.forEach((row) => {
 const key = groupBy === 'department' ? row.department : row.budget_type;
 if (!grouped[key]) grouped[key] = { rows: [], budgeted: 0, actual: 0 };
 grouped[key].rows.push(row);
 grouped[key].budgeted += row.budgeted;
 grouped[key].actual += row.actual;
 });

 const totalVariancePct = data && data.total_budgeted > 0
 ? (data.total_variance / data.total_budgeted * 100) : 0;

 const isFavorable = data && data.total_variance >= 0;

 return (
 <div className="p-5 space-y-6">
 <PageHeader
 title="Budget vs Actual"
 subtitle="P&L comparison — budgeted amounts vs actuals"
 breadcrumbs={[{ label: 'Budgeting', href: '/budgeting' }, { label: 'Budget vs Actual' }]}
 actions={
 <div className="flex items-center gap-2">
 <span className="text-xs text-muted">Group by</span>
 <SegmentedControl
 size="sm"
 label="Group by"
 value={groupBy}
 onChange={setGroupBy}
 options={[
 { value: 'department', label: 'Department' },
 { value: 'budget_type', label: 'Budget type' },
 ]}
 />
 </div>
 }
 />

 {data && (
 <>
 {/* Summary Cards */}
 <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
 <StatCard label="Total Budgeted" value={formatCompactCurrency(data.total_budgeted, 1_000_000, 'M')} />
 <StatCard label="Total Actual" value={formatCompactCurrency(data.total_actual, 1_000_000, 'M')} />
 <StatCard label="Total Variance" value={`${isFavorable ? '+' : ''}${totalVariancePct.toFixed(1)}%`} />
 </div>

 {/* Variance bar chart — top 10 by absolute variance */}
 {chartData.length > 0 && (
 <Panel title="Variance by account" meta="top 10 accounts">
 <div className="h-56">
 <ResponsiveContainer width="100%" height="100%">
 <BarChart data={chartData} margin={{ top: 8, right: 8, left: 0, bottom: 8 }}>
 <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" vertical={false} />
 <XAxis dataKey="name" tick={{ fontSize: 10, fill: 'var(--text-muted)' }} />
 <YAxis
 tick={{ fontSize: 10, fill: 'var(--text-muted)' }}
 tickFormatter={(v: number) => formatCompactCurrency(v, 1_000, 'K')}
 width={56}
 />
 <Tooltip
 contentStyle={{ background: 'var(--bg-elevated)', border: '1px solid var(--border-default)', borderRadius: 'var(--radius-md)', fontSize: 12 }}
 formatter={(v: number) => formatPeso(v)}
 />
 <Legend wrapperStyle={{ fontSize: 11 }} />
 <Bar dataKey="budgeted" name="Budgeted" fill="var(--info)" radius={[3, 3, 0, 0]} maxBarSize={32} />
 <Bar dataKey="actual" name="Actual" radius={[3, 3, 0, 0]} maxBarSize={32}>
 {chartData.map((entry, i) => (
 <Cell key={i} fill={entry.actual > entry.budgeted ? 'var(--danger)' : 'var(--success)'} />
 ))}
 </Bar>
 </BarChart>
 </ResponsiveContainer>
 </div>
 </Panel>
 )}

 {/* Grouped Summary */}
 <Panel title="Summary by Group">
 <div className="overflow-x-auto">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Group</Th>
 <Th align="right">Budgeted</Th>
 <Th align="right">Actual</Th>
 <Th align="right">Variance</Th>
 <Th align="right">%</Th>
 </tr>
 </thead>
 <tbody>
 {Object.entries(grouped).map(([key, group]) => {
 const variance = group.budgeted - group.actual;
 const pct = group.budgeted > 0 ? (variance / group.budgeted * 100) : 0;
 return (
 <tr key={key} className={trCls}>
 <Td className="font-medium">{key}</Td>
 <Td align="right" mono>{formatCompactCurrency(group.budgeted, 1_000_000, 'M')}</Td>
 <Td align="right" mono>{formatCompactCurrency(group.actual, 1_000_000, 'M')}</Td>
 <Td align="right" mono className={cn(variance < 0 ? 'text-danger-fg' : 'text-success-fg')}>
 {variance >= 0 ? '+' : '-'}{formatCompactCurrency(Math.abs(variance), 1_000_000, 'M')}
 </Td>
 <Td align="right" mono>
 <span className={cn(
 'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium',
 pct < 0 ? 'text-danger-fg bg-danger-bg' : 'text-success-fg bg-success-bg'
 )}>
 {pct >= 0 ? '+' : ''}{pct.toFixed(1)}%
 </span>
 </Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </div>
 </Panel>

 {/* Detail Rows */}
 <Panel title="Line Item Detail">
 <div className="overflow-x-auto max-h-[500px] overflow-y-auto">
 <table className={tableCls}>
 <thead className="sticky top-0 bg-canvas">
 <tr className={theadTrCls}>
 <Th>Account</Th>
 <Th>Department</Th>
 <Th align="right">Budgeted</Th>
 <Th align="right">Actual</Th>
 <Th align="right">Variance</Th>
 <Th align="right">%</Th>
 </tr>
 </thead>
 <tbody>
 {data.rows.map((row, i) => {
 const isOver = row.variance < 0;
 return (
 <tr key={i} className={trCls}>
 <Td>
 <span className="font-medium">{row.account_code}</span>
 <span className="ml-1 text-muted text-xs">{row.account_name}</span>
 </Td>
 <Td className="text-secondary">{row.department}</Td>
 <Td align="right" mono>{formatCompactCurrency(row.budgeted, 1_000, 'K')}</Td>
 <Td align="right" mono>{formatCompactCurrency(row.actual, 1_000, 'K')}</Td>
 <Td align="right" mono className={cn(isOver ? 'text-danger-fg' : 'text-success-fg')}>
 {row.variance >= 0 ? '+' : '-'}{formatCompactCurrency(Math.abs(row.variance), 1_000, 'K')}
 </Td>
 <Td align="right" mono>
 <span className={cn(
 'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium',
 row.variance_pct < 0 ? 'text-danger-fg bg-danger-bg' : 'text-success-fg bg-success-bg'
 )}>
 {row.variance_pct >= 0 ? '+' : ''}{row.variance_pct}%
 </span>
 </Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </div>
 </Panel>
 </>
 )}
 </div>
 );
}
