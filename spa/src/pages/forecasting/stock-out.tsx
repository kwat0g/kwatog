/**
 * ADV11 — Stock-out Projection page.
 *
 * Lists every active item projected to fall below safety stock within the
 * chosen horizon, sorted by risk. Operators can jump to "Create PR" for the
 * worst items.
 */
import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Chip } from '@/components/ui/Chip';
import { forecastingApi } from '@/api/forecasting';
import type { StockOutRisk } from '@/types/forecasting';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const RISK_VARIANT: Record<StockOutRisk, 'danger' | 'warning' | 'info' | 'neutral'> = {
 critical: 'danger',
 high: 'warning',
 medium: 'warning',
 low: 'info',
 ok: 'neutral',
};

export default function StockOutProjectionPage() {
 const [horizon, setHorizon] = useState<number | undefined>(undefined);

 const q = useQuery({
 queryKey: ['forecasting/stock-out', horizon],
 queryFn: () => forecastingApi.stockOut({ horizon_days: horizon }),
 });
 const optionsQuery = useQuery({
 queryKey: ['forecasting', 'options'],
 queryFn: () => forecastingApi.options(),
 staleTime: 5 * 60 * 1000,
 });
 const sourceLabels = new Map((optionsQuery.data?.demand_sources ?? []).map((source) => [source.value, source.label]));
 const riskLabels = new Map((q.data?.meta.risk_options ?? []).map((risk) => [risk.value, risk.label]));

 useEffect(() => {
 if (horizon === undefined && q.data?.meta.default_horizon_days !== undefined) {
 setHorizon(q.data.meta.default_horizon_days);
 }
 }, [horizon, q.data?.meta.default_horizon_days]);

 if (q.isError) return <EmptyState icon="alert-circle" title="Failed to load projections" action={<Button variant="secondary" onClick={() => q.refetch()}>Retry</Button>} />;

 const rows = q.data?.data ?? [];
 const generatedAt = q.data?.meta.generated_at;

 // Counts per risk for the summary strip.
 const counts = rows.reduce<Record<string, number>>(
 (acc, r) => ({ ...acc, [r.risk]: (acc[r.risk] ?? 0) + 1 }),
 {},
 );
 const riskOptions = q.data?.meta.risk_options ?? [];

 return (
 <>
 <PageHeader
 title="Stock-Out Projection"
 subtitle={`Project days-until-stockout per item, using next-month forecast or the last ${q.data?.meta.demand_history_days ?? 'configured'} days of consumption.`}
 actions={
 <div className="flex items-center gap-2">
 <label className="text-2xs uppercase tracking-wide text-muted">Horizon</label>
 <input
 type="number"
 value={horizon ?? ''}
 min={q.data?.meta.minimum_horizon_days}
 max={q.data?.meta.maximum_horizon_days}
 onChange={(e) => setHorizon(parseInt(e.target.value, 10) || undefined)}
 className="w-32"
 aria-label="Projection horizon in days"
 />
 </div>
 }
 />

 <div className="p-5 space-y-4">
 {/* Risk summary strip */}
 <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
 {riskOptions.map((option) => {
 const risk = option.value as StockOutRisk;
 return (
 <Panel key={risk} noPadding>
 <div className="p-3">
 <div className="flex items-center justify-between">
 <span className="text-2xs uppercase tracking-wide text-muted">{riskLabels.get(risk) ?? risk}</span>
 <Chip variant={RISK_VARIANT[risk] ?? 'neutral'}>{counts[risk] ?? 0}</Chip>
 </div>
 <div className="text-xl font-medium text-primary mt-1 tabular-nums">
 {counts[risk] ?? 0}
 </div>
 </div>
 </Panel>
 );
 })}
 </div>

 <Panel
 title="Items at risk"
 meta={generatedAt ? <span className="text-2xs text-muted">Generated {formatDateTime(generatedAt)}</span> : null}
 noPadding
 >
 {q.isLoading ? (
 <div className="p-4"><SkeletonTable columns={9} rows={8} /></div>
 ) : rows.length === 0 ? (
 <EmptyState
 icon="package"
 title="All items healthy"
 description="No items are projected to stock out within the selected horizon."
 />
 ) : (
 <div className="overflow-x-auto">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Item</Th>
 <Th align="right">On hand</Th>
 <Th align="right">Safety</Th>
 <Th align="right">Daily demand</Th>
 <Th>Source</Th>
 <Th align="right">Days until stock-out</Th>
 <Th>Order by</Th>
 <Th align="right">Suggested qty</Th>
 <Th>Risk</Th>
 <Th align="right" />
 </tr>
 </thead>
 <tbody>
 {rows.map((r) => (
 <tr key={r.item_id} className={trCls}>
 <Td>
 <div className="font-medium text-primary">{r.code}</div>
 <div className="text-2xs text-muted truncate max-w-[260px]">{r.name}</div>
 </Td>
 <Td align="right" mono>
 {r.available.toFixed(2)} <span className="text-2xs text-muted">{r.unit_of_measure}</span>
 </Td>
 <Td align="right" mono className="text-muted">{r.safety_stock.toFixed(2)}</Td>
 <Td align="right" mono>{r.daily_demand.toFixed(2)}</Td>
 <Td className="text-2xs text-muted">{sourceLabels.get(r.demand_source) ?? r.demand_source}</Td>
 <Td align="right" mono>
 {r.days_until_stockout === null ? '—' : (
 <span className={r.days_until_stockout <= r.lead_time_days ? 'text-danger-fg font-medium' : ''}>
 {r.days_until_stockout}d
 </span>
 )}
 </Td>
 <Td className="text-2xs">
 {r.reorder_date ? formatDate(r.reorder_date) : '—'}
 </Td>
 <Td align="right" mono>
 {r.suggested_qty !== null ? r.suggested_qty.toFixed(2) : '—'}
 </Td>
 <Td>
 <Chip variant={RISK_VARIANT[r.risk]}>{riskLabels.get(r.risk) ?? r.risk}</Chip>
 </Td>
 <Td align="right" mono>
 <Link to="/purchasing/purchase-requests/create">
 <Button size="sm" variant="ghost">Create PR</Button>
 </Link>
 </Td>
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
