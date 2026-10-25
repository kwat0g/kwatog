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
import { Input } from '@/components/ui/Input';
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

const RISK_COLOR: Record<StockOutRisk, string> = {
 critical: 'var(--danger)',
 high: 'var(--warning)',
 medium: 'var(--warning)',
 low: 'var(--info)',
 ok: 'var(--success)',
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

 // Count each risk once for the overview and the proportional distribution.
 const counts = rows.reduce<Record<StockOutRisk, number>>((acc, row) => {
 acc[row.risk] += 1;
 return acc;
 }, { critical: 0, high: 0, medium: 0, low: 0, ok: 0 });
 const riskOptions = q.data?.meta.risk_options ?? [];
 const urgentCount = counts.critical + counts.high;

 return (
 <>
 <PageHeader
 title="Stock-Out Projection"
 subtitle={`Project days-until-stockout per item, using next-month forecast or the last ${q.data?.meta.demand_history_days ?? 'configured'} days of consumption.`}
 actions={
 <div className="flex items-center gap-2">
 <label className="text-2xs uppercase tracking-wide text-muted">Horizon</label>
 <Input
 type="number"
 value={horizon ?? ''}
 min={q.data?.meta.minimum_horizon_days}
 max={q.data?.meta.maximum_horizon_days}
 onChange={(e) => setHorizon(parseInt(e.target.value, 10) || undefined)}
 className="w-28"
 aria-label="Projection horizon in days"
 />
 </div>
 }
 />

 <div className="p-5 space-y-4">
 <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.7fr)_minmax(260px,0.8fr)]">
 <Panel title="Risk profile" meta={`${rows.length} items evaluated`} noPadding>
 <div className="p-4">
 <div
 className="flex h-2 overflow-hidden rounded-full bg-subtle"
 role="img"
 aria-label={`${urgentCount} urgent items out of ${rows.length} evaluated`}
 >
 {riskOptions.map((option) => {
 const risk = option.value as StockOutRisk;
 const count = counts[risk];
 if (count === 0 || rows.length === 0) return null;
 return (
 <div
 key={risk}
 title={`${riskLabels.get(risk) ?? risk}: ${count}`}
 style={{ width: `${(count / rows.length) * 100}%`, backgroundColor: RISK_COLOR[risk] }}
 />
 );
 })}
 </div>
 <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
 {riskOptions.map((option) => {
 const risk = option.value as StockOutRisk;
 return (
 <div key={risk} className="min-w-0">
 <div className="flex items-center gap-1.5 text-xs text-muted">
 <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: RISK_COLOR[risk] }} />
 <span className="truncate">{riskLabels.get(risk) ?? risk}</span>
 </div>
 <div className="mt-1 font-mono text-lg tabular-nums text-primary">{counts[risk]}</div>
 </div>
 );
 })}
 </div>
 </div>
 </Panel>

 <Panel title="Projection context" noPadding>
 <dl className="grid grid-cols-2 gap-x-4 gap-y-3 p-4 text-xs">
 <div>
 <dt className="text-muted">Urgent items</dt>
 <dd className="mt-1 font-mono text-lg tabular-nums text-primary">{urgentCount}</dd>
 </div>
 <div>
 <dt className="text-muted">Horizon</dt>
 <dd className="mt-1 font-mono text-lg tabular-nums text-primary">{horizon ?? '—'} days</dd>
 </div>
 <div className="col-span-2">
 <dt className="text-muted">Demand basis</dt>
 <dd className="mt-1 text-primary">Next-month forecast or recent consumption</dd>
 </div>
 <div className="col-span-2">
 <dt className="text-muted">Last computed</dt>
 <dd className="mt-1 text-primary">{generatedAt ? formatDateTime(generatedAt) : 'Waiting for projection'}</dd>
 </div>
 </dl>
 </Panel>
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
 {Number(r.available).toFixed(2)} <span className="text-2xs text-muted">{r.unit_of_measure}</span>
 </Td>
 <Td align="right" mono className="text-muted">{Number(r.safety_stock).toFixed(2)}</Td>
 <Td align="right" mono>{Number(r.daily_demand).toFixed(2)}</Td>
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
 {r.suggested_qty !== null ? Number(r.suggested_qty).toFixed(2) : '—'}
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
