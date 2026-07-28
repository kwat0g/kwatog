/**
 * COPQ (Cost of Poor Quality) Analytics Dashboard.
 *
 * Shows PAF failure-cost breakdown via stacked bar chart, total COPQ trend
 * line, product cost ranking table, and supplier quality table. Period
 * selector at top (6/12/24 months).
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts';
import { copqApi, type CopqByProduct, type CopqBySupplier } from '@/api/quality/copq';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso, formatInt } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

// ─── Helpers ─────────────────────────────────────
const PESO = (v: number) => formatPeso(v);
const PESO_SHORT = (v: number) => {
  if (v >= 1_000_000) return `₱${(v / 1_000_000).toFixed(1)}M`;
  if (v >= 1_000) return `₱${(v / 1_000).toFixed(1)}K`;
  return `₱${v.toFixed(0)}`;
};

const PERIOD_OPTIONS = [
  { value: 6, label: '6 months' },
  { value: 12, label: '12 months' },
  { value: 24, label: '24 months' },
] as const;

// ─── Chart colors (from design system) ──────────
const COLORS = {
  scrap: 'var(--danger)',      // red — scrapped material
  rework: 'var(--warning)',    // amber — rework effort
  returns: 'var(--purple)',     // purple — external returns
  trend: 'var(--accent)',      // indigo — total COPQ line
} as const;

// ─── Tooltip styling (matches project conventions) ──
const TOOLTIP_STYLE = {
  background: 'var(--bg-surface)',
  border: '1px solid var(--border-default)',
  borderRadius: 6,
  fontSize: 12,
};

export default function CopqAnalyticsPage() {
  const [months, setMonths] = useState(12);

  // ─── Queries ─────────────────────────────────────
  const trend = useQuery({
    queryKey: ['quality', 'copq', 'trend', months],
    queryFn: () => copqApi.trend(months),
    placeholderData: (prev) => prev,
  });

  const summary = useQuery({
    queryKey: ['quality', 'copq', 'summary'],
    queryFn: () => copqApi.summary(),
  });

  const byProduct = useQuery({
    queryKey: ['quality', 'copq', 'by-product'],
    queryFn: () => copqApi.byProduct({ limit: 20 }),
  });

  const bySupplier = useQuery({
    queryKey: ['quality', 'copq', 'by-supplier'],
    queryFn: () => copqApi.bySupplier({ limit: 20 }),
  });

  const isLoading = trend.isLoading || summary.isLoading;

  return (
    <div>
      <PageHeader
        title="Cost of Poor Quality"
        subtitle="PAF failure cost analysis"
        breadcrumbs={[{ label: 'Quality', href: '/quality' }, { label: 'COPQ' }]}
        actions={
          <div className="flex items-center gap-1">
            {PERIOD_OPTIONS.map((opt) => (
              <Button
                key={opt.value}
                variant={months === opt.value ? 'primary' : 'secondary'}
                size="sm"
                onClick={() => setMonths(opt.value)}
              >
                {opt.label}
              </Button>
            ))}
          </div>
        }
      />

      {/* ─── Summary Stats ─── */}
      <div className="px-5 grid grid-cols-4 gap-4 mb-4">
        <StatCard
          label="This Month"
          value={summary.isLoading ? '—' : PESO(summary.data?.current_month.total_cost ?? 0)}
          helper={summary.data?.current_month.period_label ?? 'loading...'}
        />
        <StatCard
          label="YTD Total"
          value={summary.isLoading ? '—' : PESO(summary.data?.ytd.total_cost ?? 0)}
          helper="year to date"
        />
        <StatCard
          label="Scrap (YTD)"
          value={summary.isLoading ? '—' : PESO(summary.data?.ytd.internal_scrap_cost ?? 0)}
          helper="internal scrap cost"
        />
        <StatCard
          label="Rework (YTD)"
          value={summary.isLoading ? '—' : PESO(summary.data?.ytd.internal_rework_cost ?? 0)}
          helper="internal rework cost"
        />
      </div>

      {/* ─── Charts row ─── */}
      <div className="px-5 grid grid-cols-2 gap-4 mb-4">
        {/* PAF stacked bar chart */}
        <Panel title="Failure Cost Breakdown" meta="stacked by category">
          {isLoading && <SkeletonBlock className="h-64" />}
          {trend.isError && (
            <EmptyState
              icon="alert-circle"
              title="Failed to load trend data"
              action={<Button variant="secondary" onClick={() => trend.refetch()}>Retry</Button>}
            />
          )}
          {trend.data && trend.data.length === 0 && (
            <EmptyState icon="bar-chart" title="No COPQ data" description="No cost data recorded yet." />
          )}
          {trend.data && trend.data.length > 0 && (
            <ResponsiveContainer width="100%" height={280}>
              <BarChart data={trend.data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border-default)" />
                <XAxis
                  dataKey="month"
                  tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
                  axisLine={false}
                  tickLine={false}
                />
                <YAxis
                  tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
                  axisLine={false}
                  tickLine={false}
                  tickFormatter={(v) => PESO_SHORT(v)}
                />
                <Tooltip
                  contentStyle={TOOLTIP_STYLE}
                  formatter={(v: number) => [PESO(v), '']}
                />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Bar
                  dataKey="internal_scrap_cost"
                  name="Scrap"
                  fill={COLORS.scrap}
                  stackId="failure"
                  radius={[0, 0, 0, 0]}
                />
                <Bar
                  dataKey="internal_rework_cost"
                  name="Rework"
                  fill={COLORS.rework}
                  stackId="failure"
                  radius={[0, 0, 0, 0]}
                />
                <Bar
                  dataKey="external_return_cost"
                  name="Returns"
                  fill={COLORS.returns}
                  stackId="failure"
                  radius={[2, 2, 0, 0]}
                />
              </BarChart>
            </ResponsiveContainer>
          )}
        </Panel>

        {/* COPQ trend line */}
        <Panel title="COPQ Trend" meta="total cost over time">
          {isLoading && <SkeletonBlock className="h-64" />}
          {trend.isError && (
            <EmptyState
              icon="alert-circle"
              title="Failed to load trend data"
              action={<Button variant="secondary" onClick={() => trend.refetch()}>Retry</Button>}
            />
          )}
          {trend.data && trend.data.length === 0 && (
            <EmptyState icon="trending-up" title="No trend data" description="No COPQ snapshots recorded yet." />
          )}
          {trend.data && trend.data.length > 0 && (
            <ResponsiveContainer width="100%" height={280}>
              <LineChart data={trend.data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border-default)" />
                <XAxis
                  dataKey="month"
                  tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
                  axisLine={false}
                  tickLine={false}
                />
                <YAxis
                  tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
                  axisLine={false}
                  tickLine={false}
                  tickFormatter={(v) => PESO_SHORT(v)}
                />
                <Tooltip
                  contentStyle={TOOLTIP_STYLE}
                  formatter={(v: number) => [PESO(v), '']}
                />
                <Line
                  type="monotone"
                  dataKey="total_cost"
                  name="Total COPQ"
                  stroke={COLORS.trend}
                  strokeWidth={2}
                  dot={{ r: 3, fill: COLORS.trend }}
                  activeDot={{ r: 5 }}
                />
              </LineChart>
            </ResponsiveContainer>
          )}
        </Panel>
      </div>

      {/* ─── Tables row ─── */}
      <div className="px-5 grid grid-cols-2 gap-4 mb-6">
        {/* Product cost ranking */}
        <Panel title="Cost by Product" meta="top 20" noPadding>
          {byProduct.isLoading && (
            <div className="p-4"><SkeletonBlock className="h-48" /></div>
          )}
          {byProduct.isError && (
            <EmptyState
              icon="alert-circle"
              title="Failed to load product data"
              action={<Button variant="secondary" onClick={() => byProduct.refetch()}>Retry</Button>}
            />
          )}
          {byProduct.data && byProduct.data.length === 0 && (
            <div className="px-4 py-4 text-sm text-muted text-center">No product cost data.</div>
          )}
          {byProduct.data && byProduct.data.length > 0 && (
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Product</Th>
                    <Th>Part No.</Th>
                    <Th align="right">NCRs</Th>
                    <Th align="right">Scrap</Th>
                    <Th align="right">Rework</Th>
                    <Th align="right">Total</Th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-default">
                  {byProduct.data.map((row: CopqByProduct) => (
                    <tr key={row.product_id} className={trCls}>
                      <Td className="truncate max-w-[200px]">{row.product_name}</Td>
                      <Td mono className="text-xs text-muted">{row.part_number}</Td>
                      <Td align="right" mono>{row.ncr_count}</Td>
                      <Td align="right" mono className="text-danger">{PESO(row.scrap_cost)}</Td>
                      <Td align="right" mono className="text-warning">{PESO(row.rework_cost)}</Td>
                      <Td align="right" mono className="font-medium">{PESO(row.total_cost)}</Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        {/* Supplier quality ranking */}
        <Panel title="Supplier NCR Ranking" meta="top 20" noPadding>
          {bySupplier.isLoading && (
            <div className="p-4"><SkeletonBlock className="h-48" /></div>
          )}
          {bySupplier.isError && (
            <EmptyState
              icon="alert-circle"
              title="Failed to load supplier data"
              action={<Button variant="secondary" onClick={() => bySupplier.refetch()}>Retry</Button>}
            />
          )}
          {bySupplier.data && bySupplier.data.length === 0 && (
            <div className="px-4 py-4 text-sm text-muted text-center">No supplier NCR data.</div>
          )}
          {bySupplier.data && bySupplier.data.length > 0 && (
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Vendor</Th>
                    <Th align="right">NCR Count</Th>
                    <Th align="right">Defective Qty</Th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-default">
                  {bySupplier.data.map((row: CopqBySupplier) => (
                    <tr key={row.vendor_id} className={trCls}>
                      <Td className="truncate max-w-[250px]">{row.vendor_name}</Td>
                      <Td align="right" mono>{row.ncr_count}</Td>
                      <Td align="right" mono>{formatInt(row.defective_qty)}</Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>
    </div>
  );
}
