/**
 * ADV11 — Forecast Accuracy Dashboard (`/forecasting/accuracy`).
 *
 * Shows overall MAPE, bias, and periods evaluated as StatCards,
 * a dual-line chart (forecast vs actual) with variance bars,
 * and a per-product accuracy table.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
 CartesianGrid,
 Line,
 Bar,
 ComposedChart,
 ResponsiveContainer,
 Tooltip as RTooltip,
 XAxis,
 YAxis,
 Legend,
 ReferenceLine,
} from 'recharts';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonBlock, SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { forecastingApi } from '@/api/forecasting';
import type { ProductAccuracy } from '@/types/forecasting';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { chartColor } from '@/lib/chartPalette';

const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

type SortField = 'part_number' | 'name' | 'mape' | 'bias' | 'periods_evaluated';
type SortDir = 'asc' | 'desc';

export default function ForecastAccuracyPage() {
 const currentYear = new Date().getFullYear();
 const [year, setYear] = useState<number>(currentYear);
 const [sortField, setSortField] = useState<SortField>('mape');
 const [sortDir, setSortDir] = useState<SortDir>('desc');

 const summaryQ = useQuery({
 queryKey: ['forecasting/accuracy/summary', year],
 queryFn: () => forecastingApi.accuracySummary(year),
 });

 const productsQ = useQuery({
 queryKey: ['forecasting/accuracy/products', year],
 queryFn: () => forecastingApi.accuracyByProduct(year),
 });
 const optionsQ = useQuery({
 queryKey: ['forecasting', 'options'],
 queryFn: () => forecastingApi.options(),
 staleTime: 300_000,
 });
 const excellentMape = optionsQ.data?.accuracy_policy.excellent_mape;
 const acceptableMape = optionsQ.data?.accuracy_policy.acceptable_mape;

 const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - i);

 // Chart data from the summary monthly breakdown
 const chartData = (summaryQ.data?.monthly ?? [])
 .sort((a, b) => a.month - b.month)
 .map((m) => ({
 label: `${MONTH_NAMES[m.month - 1]}`,
 forecast: m.forecast,
 actual: m.actual,
 variance: m.variance,
 ape: m.ape,
 }));

 // Sorted product table data
 const sortedProducts = [...(productsQ.data ?? [])].sort((a, b) => {
 const aVal = a[sortField];
 const bVal = b[sortField];
 if (typeof aVal === 'string' && typeof bVal === 'string') {
 return sortDir === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
 }
 const numA = Number(aVal);
 const numB = Number(bVal);
 return sortDir === 'asc' ? numA - numB : numB - numA;
 });

 function toggleSort(field: SortField) {
 if (sortField === field) {
 setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
 } else {
 setSortField(field);
 setSortDir('desc');
 }
 }

 function sortIndicator(field: SortField) {
 if (sortField !== field) return '';
 return sortDir === 'asc' ? ' ↑' : ' ↓';
 }

 const mapeValue = summaryQ.data?.mape;
 const biasValue = summaryQ.data?.bias;
 const periodsValue = summaryQ.data?.periods_evaluated ?? 0;

 if (summaryQ.isError || productsQ.isError) {
 return (
 <EmptyState
 icon="alert-circle"
 title="Failed to load forecast accuracy"
 description="The accuracy summary or product breakdown could not be refreshed."
 action={<Button variant="secondary" onClick={() => { void summaryQ.refetch(); void productsQ.refetch(); }}>Retry</Button>}
 />
 );
 }

 return (
 <>
 <PageHeader
 title="Forecast Accuracy"
 subtitle="MAPE, bias, and per-product accuracy tracking for reconciled forecast periods."
 actions={
 <div className="flex items-center gap-2">
 <label className="text-2xs uppercase tracking-wide text-muted">Year</label>
 <Select
 value={year}
 onChange={(e) => setYear(parseInt(e.target.value) || currentYear)}
 className="w-28"
 >
 {yearOptions.map((y) => (
 <option key={y} value={y}>{y}</option>
 ))}
 </Select>
 </div>
 }
 />

 <div className="p-5 space-y-4">
 {/* KPI cards */}
 {summaryQ.isLoading ? (
 <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
 {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-[88px]" />)}
 </div>
 ) : (
 <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
 <StatCard
 label="MAPE"
 value={mapeValue !== null && mapeValue !== undefined ? `${mapeValue.toFixed(1)}%` : '--'}
 helper={mapeValue !== null && mapeValue !== undefined
 ? (excellentMape != null && mapeValue <= excellentMape ? 'Excellent accuracy' : acceptableMape != null && mapeValue <= acceptableMape ? 'Acceptable' : 'Needs improvement')
 : 'No data'}
 />
 <StatCard
 label="Forecast Bias"
 value={biasValue !== null && biasValue !== undefined
 ? `${biasValue > 0 ? '+' : ''}${biasValue.toFixed(1)}%`
 : '--'}
 helper={biasValue !== null && biasValue !== undefined
 ? (biasValue > 0 ? 'Under-forecasting' : biasValue < 0 ? 'Over-forecasting' : 'Balanced')
 : 'No data'}
 />
 <StatCard
 label="Periods Evaluated"
 value={String(periodsValue)}
 helper={`${year} reconciled periods`}
 />
 </div>
 )}

 {/* Forecast vs Actual chart */}
 <Panel
 title={`Monthly forecast vs. actual · ${year}`}
 meta={chartData.length > 0 ? `${chartData.length} periods` : undefined}
 noPadding
 >
 {summaryQ.isLoading ? (
 <div className="p-4"><SkeletonBlock className="h-[300px]" /></div>
 ) : chartData.length === 0 ? (
 <EmptyState
 icon="bar-chart"
 title="No reconciled data"
 description={`No forecast periods with actuals for ${year}.`}
 />
 ) : (
 <div className="p-4">
 <ResponsiveContainer width="100%" height={340}>
 <ComposedChart data={chartData} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
 <CartesianGrid vertical={false} stroke="var(--border-subtle)" />
 <XAxis
 dataKey="label"
 tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
 axisLine={{ stroke: 'var(--border-default)' }}
 tickLine={false}
 minTickGap={12}
 />
 <YAxis
 yAxisId="demand"
 tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
 axisLine={false}
 tickLine={false}
 width={42}
 />
 <YAxis
 yAxisId="variance"
 orientation="right"
 tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
 axisLine={false}
 tickLine={false}
 width={42}
 />
 <RTooltip
 contentStyle={{
 backgroundColor: 'var(--bg-elevated)',
 border: '1px solid var(--border-default)',
 borderRadius: 6,
 color: 'var(--text-primary)',
 fontSize: 12,
 }}
 formatter={(value, name) => [value == null ? '—' : Number(value).toLocaleString(), name]}
 />
 <Legend wrapperStyle={{ fontSize: 12, color: 'var(--text-muted)' }} />
 <ReferenceLine y={0} yAxisId="variance" stroke="var(--border-strong)" />
 <Bar
 yAxisId="variance"
 dataKey="variance"
 name="Variance"
 fill={chartColor(2)}
 fillOpacity={0.45}
 barSize={20}
 />
 <Line
 yAxisId="demand"
 type="monotone"
 dataKey="forecast"
 name="Forecast"
 stroke={chartColor(0)}
 strokeWidth={2}
 dot={{ r: 3, fill: chartColor(0) }}
 />
 <Line
 yAxisId="demand"
 type="monotone"
 dataKey="actual"
 name="Actual"
 stroke={chartColor(3)}
 strokeWidth={2}
 dot={{ r: 3, fill: chartColor(3) }}
 />
 </ComposedChart>
 </ResponsiveContainer>
 </div>
 )}
 </Panel>

 {/* Per-product accuracy table */}
 <Panel title="Accuracy by Product" noPadding>
 {productsQ.isLoading ? (
 <div className="p-4"><SkeletonTable columns={5} rows={6} /></div>
 ) : sortedProducts.length === 0 ? (
 <EmptyState
 icon="package"
 title="No product accuracy data"
 description={`No active products have reconciled forecasts for ${year}.`}
 />
 ) : (
 <div className="overflow-x-auto">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th aria-sort={sortField === 'part_number' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}>
 <button type="button" className="rounded-sm text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset" onClick={() => toggleSort('part_number')}>
 Part #{sortIndicator('part_number')}
 </button>
 </Th>
 <Th aria-sort={sortField === 'name' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}>
 <button type="button" className="rounded-sm text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset" onClick={() => toggleSort('name')}>
 Product{sortIndicator('name')}
 </button>
 </Th>
 <Th align="right" aria-sort={sortField === 'mape' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}>
 <button type="button" className="rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset" onClick={() => toggleSort('mape')}>
 MAPE %{sortIndicator('mape')}
 </button>
 </Th>
 <Th align="right" aria-sort={sortField === 'bias' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}>
 <button type="button" className="rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset" onClick={() => toggleSort('bias')}>
 Bias %{sortIndicator('bias')}
 </button>
 </Th>
 <Th align="right" aria-sort={sortField === 'periods_evaluated' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}>
 <button type="button" className="rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset" onClick={() => toggleSort('periods_evaluated')}>
 Periods{sortIndicator('periods_evaluated')}
 </button>
 </Th>
 </tr>
 </thead>
 <tbody>
 {sortedProducts.map((p: ProductAccuracy) => (
 <tr key={p.product_id} className={trCls}>
 <Td mono className="text-xs">{p.part_number}</Td>
 <Td className="text-primary">{p.name}</Td>
 <Td align="right" mono>
 <span className={excellentMape != null && p.mape <= excellentMape ? 'text-success-fg' : acceptableMape != null && p.mape <= acceptableMape ? 'text-warning-fg' : 'text-danger-fg'}>
 {p.mape.toFixed(1)}%
 </span>
 </Td>
 <Td align="right" mono>
 {p.bias > 0 ? '+' : ''}{p.bias.toFixed(1)}%
 </Td>
 <Td align="right" mono>{p.periods_evaluated}</Td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>
 )}
 </Panel>
 </div>
 </>
 );
}
