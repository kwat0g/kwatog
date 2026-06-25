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

  if (summaryQ.isError) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load accuracy data"
        action={<Button variant="secondary" onClick={() => { summaryQ.refetch(); productsQ.refetch(); }}>Retry</Button>}
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
                ? (mapeValue <= 15 ? 'Excellent accuracy' : mapeValue <= 30 ? 'Acceptable' : 'Needs improvement')
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
        <Panel title={`Monthly Forecast vs Actual — ${year}`} noPadding>
          {summaryQ.isLoading ? (
            <div className="p-4"><SkeletonBlock className="h-[300px]" /></div>
          ) : chartData.length === 0 ? (
            <EmptyState
              icon="bar-chart-2"
              title="No reconciled data"
              description={`No forecast periods with actuals for ${year}.`}
            />
          ) : (
            <div className="p-4">
              <ResponsiveContainer width="100%" height={300}>
                <ComposedChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-border-default" />
                  <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11, className: 'fill-text-subtle' }}
                    axisLine={{ className: 'stroke-border-default' }}
                  />
                  <YAxis
                    tick={{ fontSize: 11, className: 'fill-text-subtle' }}
                    axisLine={{ className: 'stroke-border-default' }}
                  />
                  <RTooltip
                    contentStyle={{
                      backgroundColor: 'var(--color-surface)',
                      border: '1px solid var(--color-border-default)',
                      borderRadius: 6,
                      fontSize: 12,
                    }}
                  />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Bar
                    dataKey="variance"
                    name="Variance"
                    fill="var(--color-accent-amber)"
                    opacity={0.35}
                    barSize={20}
                  />
                  <Line
                    type="monotone"
                    dataKey="forecast"
                    name="Forecast"
                    stroke="var(--color-accent-indigo)"
                    strokeWidth={2}
                    dot={{ r: 3 }}
                  />
                  <Line
                    type="monotone"
                    dataKey="actual"
                    name="Actual"
                    stroke="var(--color-accent-emerald)"
                    strokeWidth={2}
                    dot={{ r: 3 }}
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
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-2xs uppercase tracking-wide text-muted bg-elevated/50 border-b border-default">
                    <th
                      className="px-4 py-2 cursor-pointer select-none"
                      onClick={() => toggleSort('part_number')}
                    >
                      Part #{sortIndicator('part_number')}
                    </th>
                    <th
                      className="px-4 py-2 cursor-pointer select-none"
                      onClick={() => toggleSort('name')}
                    >
                      Product{sortIndicator('name')}
                    </th>
                    <th
                      className="px-4 py-2 text-right cursor-pointer select-none"
                      onClick={() => toggleSort('mape')}
                    >
                      MAPE %{sortIndicator('mape')}
                    </th>
                    <th
                      className="px-4 py-2 text-right cursor-pointer select-none"
                      onClick={() => toggleSort('bias')}
                    >
                      Bias %{sortIndicator('bias')}
                    </th>
                    <th
                      className="px-4 py-2 text-right cursor-pointer select-none"
                      onClick={() => toggleSort('periods_evaluated')}
                    >
                      Periods{sortIndicator('periods_evaluated')}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {sortedProducts.map((p: ProductAccuracy) => (
                    <tr key={p.product_id} className="border-b border-default/50 hover:bg-elevated/30">
                      <td className="px-4 py-2 font-mono text-xs tabular-nums">{p.part_number}</td>
                      <td className="px-4 py-2 text-primary">{p.name}</td>
                      <td className="px-4 py-2 text-right font-mono tabular-nums">
                        <span className={p.mape <= 15 ? 'text-success' : p.mape <= 30 ? 'text-warning' : 'text-danger'}>
                          {p.mape.toFixed(1)}%
                        </span>
                      </td>
                      <td className="px-4 py-2 text-right font-mono tabular-nums">
                        {p.bias > 0 ? '+' : ''}{p.bias.toFixed(1)}%
                      </td>
                      <td className="px-4 py-2 text-right font-mono tabular-nums">{p.periods_evaluated}</td>
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
