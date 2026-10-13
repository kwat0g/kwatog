/**
 * Purchasing Officer Dashboard — Task D6.
 *
 * Data source: GET /api/v1/dashboards/purchasing (via dashboardsApi.purchasing)
 * Backend: RoleDashboardService::purchasing()
 * Cache: 30s Redis per user
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { dashboardsApi, DASHBOARD_REFRESH_MS } from '@/api/dashboards';
import { kpiLink } from '@/lib/dashboardLinks';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Th, Td, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { DashboardShell, KpiGrid, PanelRow } from '@/components/dashboard/DashboardShell';
import { StockOutPanel } from '@/components/dashboard/StockOutPanel';
import { DemandForecastPanel } from '@/components/dashboard/DemandForecastPanel';
import { usePermission } from '@/hooks/usePermission';
import { DonutBreakdown, BarComparison } from '@/components/charts';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { PURCHASING_KPIS } from '@/lib/dashboardKpis';
import { formatPeso } from '@/lib/formatNumber';

/* ───────────────────────── Typed interface ───────────────────────── */

interface PrActionItem {
 id: string;
 pr_number: string;
 department: string;
 items_count: number;
 estimated_total: string;
 urgency: string;
 urgency_label?: string;
 days_waiting: number;
}

interface PoPipelineItem {
 status: string;
 status_label?: string;
 count: number;
}

interface SupplierScoreItem {
 name: string;
 overall_score: string;
 tier?: string | null;
}

interface UpcomingDelivery {
 id: string;
 po_number: string;
 vendor: string;
 items_count: number;
 expected_date: string | null;
 status: string;
 status_label?: string;
}

interface PurchasingDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
  pr_action_queue: PrActionItem[];
  po_pipeline: PoPipelineItem[];
  supplier_performance: SupplierScoreItem[];
  upcoming_deliveries: UpcomingDelivery[];
  delivery_horizon_days?: number;
  /**
   * Supplier-risk composition (stock-out + demand MAPE). OPTIONAL: the server
   * omits it without `forecasting.view` (PanelGate) — render what arrives.
   */
  supplier_risk?: SupplierRiskData;
  };
}

/** Mirrors `ForecastingDashboardService::supplierRisk()` — read-only, no override keys. */
interface SupplierRiskData {
  at_risk_count: number;
  critical_count: number;
  high_count: number;
  horizon_days: number;
  mape: number | null;
  bias: number | null;
  periods_evaluated: number;
  top_items: Array<{
  item_id: string;
  code: string;
  name: string;
  days_until_stockout: number | null;
  risk: string;
  }>;
}

/* ───────────────────────── Sub-panel components ───────────────────────── */

function PrActionQueuePanel({ items }: { items: PrActionItem[] }) {
 if (items.length === 0) {
 return (
 <Panel title="PR Action Queue">
 <EmptyState icon="check-circle" title="All caught up" description="No pending purchase requests requiring action." />
 </Panel>
 );
 }

 return (
 <Panel title="PR Action Queue" meta={items.length.toString()} noPadding scrollBody bodyClassName="px-1.5 pb-2">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>PR #</Th>
 <Th>Dept</Th>
 <Th align="right">Items</Th>
 <Th align="right">Est. Total</Th>
 <Th>Urgency</Th>
 <Th align="right">Waiting</Th>
 </tr>
 </thead>
 <tbody>
 {items.map((pr) => (
 <tr key={pr.id} className={trCls}>
 <Td>
 <Link
 to={`/purchasing/purchase-requests/${pr.id}`}
 className="text-link hover:underline font-mono text-xs"
 aria-label={`View purchase request ${pr.pr_number}`}
 >
 {pr.pr_number}
 </Link>
 </Td>
 <Td className="text-muted text-xs">{pr.department}</Td>
 <Td align="right" mono>{pr.items_count}</Td>
 <Td align="right" mono>{formatPeso(pr.estimated_total)}</Td>
 <Td>
 <Chip variant={pr.urgency === 'urgent' ? 'danger' : pr.urgency === 'high' ? 'warning' : 'neutral'}>
 {pr.urgency_label ?? pr.urgency}
 </Chip>
 </Td>
 <Td align="right" mono className="text-muted">{pr.days_waiting}d</Td>
 </tr>
 ))}
 </tbody>
 </table>
 </Panel>
 );
}

function PoPipelinePanel({ items }: { items: PoPipelineItem[] }) {
 if (items.length === 0) {
 return (
 <Panel title="PO Pipeline">
 <EmptyState icon="inbox" title="No purchase orders" description="No POs in the pipeline." />
 </Panel>
 );
 }

 const maxCount = Math.max(1, ...items.map((i) => i.count));

 return (
 <Panel title="PO Pipeline">
 <ul className="space-y-2">
 {items.map((i) => {
 const pct = Math.round((i.count / maxCount) * 100);
 return (
 <li key={i.status}>
 <div className="flex items-center justify-between text-sm mb-1">
 <span>{i.status_label ?? i.status.replace(/_/g, ' ')}</span>
 <span className="font-mono tabular-nums">{i.count}</span>
 </div>
 <div
 role="progressbar"
 aria-valuenow={pct}
 aria-valuemin={0}
 aria-valuemax={100}
 aria-label={`${i.status}: ${i.count} POs`}
 className="h-2 bg-subtle rounded-full overflow-hidden"
 >
 <div
 className={poBarClass(i.status)}
 style={{ width: `${pct}%` }}
 />
 </div>
 </li>
 );
 })}
 </ul>
 </Panel>
 );
}

function poBarClass(status: string): string {
 if (status === 'received' || status === 'closed') return 'h-full bg-success-bg rounded-full';
 if (status === 'sent') return 'h-full bg-info-bg rounded-full';
 if (status === 'approved') return 'h-full bg-warning-bg rounded-full';
 return 'h-full bg-strong rounded-full';
}

function SupplierPerformancePanel({ items }: { items: SupplierScoreItem[] }) {
 if (items.length === 0) {
 return (
 <Panel title="Supplier Performance">
 <EmptyState icon="inbox" title="No supplier scores yet" description="No supplier performance scores are available yet." />
 </Panel>
 );
 }

 return (
 <Panel title="Top Suppliers">
 <ul className="divide-y divide-subtle">
 {items.map((s) => (
 <li key={s.name} className="flex items-center justify-between py-2 text-sm">
 <span className="truncate">{s.name}</span>
 <Chip variant={s.tier === 'A' ? 'success' : s.tier === 'B' ? 'info' : s.tier === 'C' ? 'warning' : 'danger'}>
 <span className="font-mono tabular-nums">{s.overall_score}</span>
 </Chip>
 </li>
 ))}
 </ul>
 </Panel>
 );
}

function UpcomingDeliveriesPanel({ items, horizonDays }: { items: UpcomingDelivery[]; horizonDays: number }) {
 if (items.length === 0) {
 return (
 <Panel title={`Upcoming Deliveries (${horizonDays} days)`}>
 <EmptyState icon="truck" title="None scheduled" description={`No deliveries expected in the next ${horizonDays} days.`} />
 </Panel>
 );
 }

 return (
 <Panel title={`Upcoming Deliveries (${horizonDays} days)`} meta={items.length.toString()} noPadding scrollBody bodyClassName="px-1.5 pb-2">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>PO #</Th>
 <Th>Vendor</Th>
 <Th align="right">Expected</Th>
 <Th>Status</Th>
 </tr>
 </thead>
 <tbody>
 {items.map((d) => (
 <tr key={d.id} className={trCls}>
 <Td>
 <Link
 to={`/purchasing/purchase-orders/${d.id}`}
 className="text-link hover:underline font-mono text-xs"
 aria-label={`View purchase order ${d.po_number}`}
 >
 {d.po_number}
 </Link>
 </Td>
 <Td className="text-muted text-xs truncate">{d.vendor}</Td>
 <Td align="right" mono>{d.expected_date ?? '—'}</Td>
 <Td>
 <Chip variant={d.status === 'sent' ? 'info' : d.status === 'approved' ? 'warning' : 'neutral'}>
 {d.status_label ?? d.status.replace(/_/g, ' ')}
 </Chip>
 </Td>
 </tr>
 ))}
 </tbody>
 </table>
 </Panel>
 );
}

/**
 * Supplier Risk — read-only composition of the stock-out projection and the
 * demand-forecast accuracy (MAPE/bias). Data rides the page payload; the worst
 * five items link out, detail lives in the StockOutPanel below. No override
 * inputs: override + MRP toggle stay on the /forecasting pages.
 */
function SupplierRiskPanel({ data }: { data: SupplierRiskData }) {
  const outlookLink = (
  <Link className="text-xs text-link hover:underline" to="/forecasting/stock-out">
  Stock-out outlook →
  </Link>
  );

  if (data.at_risk_count === 0) {
  return (
  <Panel
  title="Supplier Risk"
  meta={`${data.horizon_days}d horizon`}
  actions={outlookLink}
  >
  <EmptyState
  icon="check-circle"
  title="No items at risk"
  description={`No items projected to run out within ${data.horizon_days} days.`}
  />
  </Panel>
  );
  }

  return (
  <Panel
  title="Supplier Risk"
  meta={`${data.at_risk_count} at risk · ${data.horizon_days}d horizon`}
  actions={
  <span className="flex items-center gap-2">
  {outlookLink}
  <Link className="text-xs text-link hover:underline" to="/forecasting/accuracy">
  Accuracy →
  </Link>
  </span>
  }
  >
  <div className="flex flex-wrap items-center gap-2 text-sm">
  <Chip variant="danger">
  <span className="font-mono tabular-nums">{data.critical_count}</span>
  {' '}critical
  </Chip>
  <Chip variant="warning">
  <span className="font-mono tabular-nums">{data.high_count}</span>
  {' '}high
  </Chip>
  <span className="ml-auto font-mono tabular-nums text-xs text-muted">
  {data.mape != null && data.periods_evaluated > 0
  ? `Demand MAPE ${data.mape.toFixed(1)}% · bias ${data.bias != null && data.bias > 0 ? '+' : ''}${data.bias?.toFixed(1) ?? '—'}% · ${data.periods_evaluated} periods`
  : 'No reconciled accuracy yet'}
  </span>
  </div>
  <ul className="mt-2 divide-y divide-subtle">
  {data.top_items.map((item) => (
  <li key={item.item_id} className="flex items-center justify-between gap-2 py-1.5 text-sm">
  <Link
  to={`/inventory/items/${item.item_id}`}
  className="min-w-0 truncate font-mono text-xs text-link hover:underline"
  aria-label={`View item ${item.code} - ${item.name}`}
  >
  {item.code}
  <span className="ml-1 text-muted">{item.name}</span>
  </Link>
  <span className="flex shrink-0 items-center gap-2">
  <span className="font-mono tabular-nums text-xs text-muted">
  {item.days_until_stockout != null ? `${item.days_until_stockout}d left` : 'no demand signal'}
  </span>
  <Chip variant={SUPPLIER_RISK_VARIANT[item.risk] ?? 'neutral'}>{item.risk}</Chip>
  </span>
  </li>
  ))}
  </ul>
  </Panel>
  );
}

const SUPPLIER_RISK_VARIANT: Record<string, ChipVariant> = {
  critical: 'danger',
  high: 'warning',
  medium: 'info',
  low: 'neutral',
  ok: 'success',
};

/* ───────────────────────── Page component ───────────────────────── */

export default function PurchasingDashboard() {
  const { can } = usePermission();
  const q = useQuery({
 queryKey: ['dashboard', 'purchasing'],
 queryFn: () => dashboardsApi.purchasing<PurchasingDashboardData>(),
 refetchInterval: DASHBOARD_REFRESH_MS,
 });

 return (
 <DashboardShell<PurchasingDashboardData>
 title="Purchasing Dashboard"
 subtitle="Live · refreshes every 60s"
 query={q}
 refreshingQueryKey={['dashboard', 'purchasing']}
 header={<KpiStrip codes={PURCHASING_KPIS} compact />}
 >
 {({ kpis, panels }) => {
 const poStatusChartData =
 panels?.po_pipeline?.map((i) => ({
 name: i.status_label ?? i.status.replace(/_/g, ' '),
 value: i.count,
 color:
 i.status === 'received' || i.status === 'closed'
 ? 'var(--success)'
 : i.status === 'sent'
 ? 'var(--info)'
 : 'var(--warning)',
 })) ?? [];

 const urgencyCounts: Record<string, number> = { urgent: 0, high: 0, normal: 0 };
 (panels?.pr_action_queue ?? []).forEach((pr) => {
 urgencyCounts[pr.urgency] = (urgencyCounts[pr.urgency] || 0) + 1;
 });
 const prStatusChartData = Object.entries(urgencyCounts)
 .filter(([, v]) => v > 0)
 .map(([label, count]) => ({ label, count }));

 return (
 <>
 {/* ── Row 1: KPIs ── */}
 <KpiGrid count={kpis.length}>
 {kpis.map((k) => (
 <StatCard
 key={k.label}
 label={k.label}
 value={/^[A-Z]{3}$/.test(k.unit) ? `${k.unit} ${k.value}` : k.value}
 helper={!/^[A-Z]{3}$/.test(k.unit) && k.unit !== 'count' ? k.unit : undefined}
 linkTo={kpiLink(k.label)}
 />
 ))}
 </KpiGrid>

 {/* ── Row 2: PR Action Queue + PO Pipeline ── */}
 <PanelRow>
 <PrActionQueuePanel items={panels?.pr_action_queue ?? []} />
 <PoPipelinePanel items={panels?.po_pipeline ?? []} />
 </PanelRow>

 {/* ── Row 3: Supplier Performance + Upcoming Deliveries ── */}
 <PanelRow>
 <SupplierPerformancePanel items={panels?.supplier_performance ?? []} />
 <UpcomingDeliveriesPanel items={panels?.upcoming_deliveries ?? []} horizonDays={panels?.delivery_horizon_days ?? 0} />
 </PanelRow>

 {/* ── Row 4: Charts ── */}
 <PanelRow>
 <Panel title="PO Status Distribution">
 {poStatusChartData.length === 0 ? (
 <EmptyState icon="inbox" title="No POs" description="No purchase order data available." />
 ) : (
 <DonutBreakdown
 data={poStatusChartData}
 centerLabel="Total POs"
 centerValue={String(poStatusChartData.reduce((sum, i) => sum + i.value, 0))}
 />
 )}
 </Panel>
 <Panel title="PR Pipeline by Urgency">
 {prStatusChartData.length === 0 ? (
 <EmptyState icon="inbox" title="No PRs" description="No pending purchase requests." />
 ) : (
 <BarComparison
 data={prStatusChartData}
 bars={[{ dataKey: 'count', color: 'var(--warning)', label: 'PRs' }]}
 xKey="label"
 height={180}
 />
 )}
 </Panel>
 </PanelRow>

  {/* ── Row 4.5: Supplier risk (read-only composition from the payload — rides
  the page poll, no separate fetch; absent when PanelGate strips it) ── */}
  {panels?.supplier_risk && <SupplierRiskPanel data={panels.supplier_risk} />}

  {/* ── Row 5: Forecasting (consulted, not monitored — manual refresh only;
  hidden without the forecasting read so a denied viewer gets no 403 tiles) ── */}
  {can('forecasting.view') && (
  <PanelRow>
  <StockOutPanel hideWhenEmpty />
  <DemandForecastPanel hideWhenEmpty />
  </PanelRow>
  )}
 </>
 );
 }}
 </DashboardShell>
 );
}
