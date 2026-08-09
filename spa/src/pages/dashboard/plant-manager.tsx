import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Th, Td, tableCls, trCls } from '@/components/ui/table-cells';
import { client } from '@/api/client';
import { DashboardShell, KpiGrid, PanelRow } from '@/components/dashboard/DashboardShell';
import { ChainBottleneckWidget } from '@/components/dashboard/ChainBottleneckWidget';
import { StockOutPanel } from '@/components/dashboard/StockOutPanel';
import { DemandForecastPanel } from '@/components/dashboard/DemandForecastPanel';
import { ForecastAccuracyPanel } from '@/components/dashboard/ForecastAccuracyPanel';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { DonutBreakdown, BarComparison } from '@/components/charts';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { alertRefLink } from '@/lib/dashboardLinks';
import type { ApiSuccess } from '@/types';

/**
 * Task D2 — Plant Manager dashboard.
 *
 * Opinionated 4-row layout (replaces the generic `<RoleDashboard>` wrapper):
 * Row 1 — 4 KPI stat cards
 * Row 2 — Chain stage breakdown
 * Row 3 — Machine utilization + defect pareto
 * Row 4 — Alerts panel + financial snapshot
 * Bottom — Chain bottleneck widget (if permitted)
 */

interface PlantManagerData {
 kpis: Array<{ label: string; value: string | null; unit: string }>;
 panels: {
 chain_stages: Array<{ key: string; label: string; color: string; count: number; percent: number }>;
 alerts: Array<{ kind: string; severity: string; label: string; ref: string | null; ref_id: string | null }>;
 machine_util: Array<{ id: string; code: string; name: string; status: string; status_label?: string; has_active_wo: boolean }>;
 defect_pareto: Array<{ code: string; name: string; count: number }>;
 financial_snapshot: {
 cash_balance: string;
 ar_outstanding: string;
 ap_outstanding: string;
 revenue_mtd: string;
 je_draft_count: number;
 };
 };
}

export default function PlantManagerDashboard() {
 const { can } = usePermission();

 const [range, setRange] = useState<'today' | 'week' | 'month' | 'quarter'>('week');

 const q = useQuery({
 queryKey: ['dashboard', 'plant-manager', range],
 queryFn: (): Promise<PlantManagerData> =>
 client
 .get<ApiSuccess<PlantManagerData>>('/dashboards/plant-manager', { params: { range } })
 .then((r) => r.data.data),
 refetchInterval: 60_000,
 placeholderData: (prev) => prev,
 });

 return (
 <DashboardShell<PlantManagerData>
 title="Plant Manager Dashboard"
 subtitle="Production, quality, and financial overview."
 query={q}
 refreshingQueryKey={['dashboard', 'plant-manager', range]}
 actions={
 <SegmentedControl
 label="Time range"
 value={range}
 onChange={setRange}
 size="sm"
 options={[
 { value: 'today', label: 'Today' },
 { value: 'week', label: 'Week' },
 { value: 'month', label: 'Month' },
 { value: 'quarter', label: 'Quarter' },
 ]}
 />
 }
 >
 {(data) => {
 const machineStatusCounts: Record<string, number> = {};
 data.panels.machine_util.forEach((m) => {
 machineStatusCounts[m.status] = (machineStatusCounts[m.status] || 0) + 1;
 });
 const colorMap: Record<string, string> = {
 running: 'var(--success)',
 idle: 'var(--warning)',
 setup: 'var(--info)',
 breakdown: 'var(--danger)',
 down: 'var(--danger)',
 stopped: 'var(--text-muted)',
 };
 const machineStatusData = Object.entries(machineStatusCounts).map(([name, value]) => ({
 name,
 value,
 color: colorMap[name] ?? 'var(--text-muted)',
 }));

 return (
 <>
 {/* Row 1 — KPIs */}
 <KpiGrid count={data.kpis.length}>
 {data.kpis.map((kpi) => (
 <StatCard
 key={kpi.label}
 label={kpi.label}
 value={kpi.value == null ? '—' : `${kpi.value}${kpi.unit === 'pct' ? '%' : ''}`}
 helper={/^[A-Z]{3}$/.test(kpi.unit) ? kpi.unit : kpi.unit === 'pct' ? 'yield' : kpi.unit}
 />
 ))}
 </KpiGrid>

 {/* KPI Scorecard strip — the OEE card drills into the full OEE report; the rest go to the scorecard. */}
 <KpiStrip
 codes={['oee', 'dppm', 'first_pass_yield', 'on_time_delivery']}
 linkByCode={{ oee: '/production/oee' }}
 />

 {/* Row 2 — Chain stage breakdown */}
 <Panel title="Order-to-Cash Chain" actions={<Link className="text-xs text-link hover:underline" to="/approvals">View board →</Link>}>
 <StageBar stages={data.panels.chain_stages} />
 </Panel>

 {/* Row 3 — Machine utilization + defect pareto */}
 <PanelRow>
 <MachineUtilPanel machines={data.panels.machine_util} />
 <DefectParetoPanel defects={data.panels.defect_pareto} />
 </PanelRow>

 {/* Row 4 — Alerts + financial snapshot */}
 <PanelRow>
 <AlertsPanel alerts={data.panels.alerts} />
 <FinancialSnapshotPanel snapshot={data.panels.financial_snapshot} />
 </PanelRow>

 {/* Row 4.5 — Chart visualizations */}
 <PanelRow>
 <Panel title="Machine Status Breakdown">
 {machineStatusData.length === 0 ? (
 <EmptyState size="compact" icon="cpu" title="No machines" description="No machine data available." />
 ) : (
 <DonutBreakdown
 data={machineStatusData}
 centerLabel="Machines"
 centerValue={String(data.panels.machine_util.length)}
 />
 )}
 </Panel>
 <Panel title="Top Defects">
 {data.panels.defect_pareto.length === 0 ? (
 <EmptyState size="compact" icon="check-circle" title="No defects" description="No defects recorded this period." />
 ) : (
 <BarComparison
 data={data.panels.defect_pareto.slice(0, 8).map((d) => ({ label: d.code, count: d.count }))}
 bars={[{ dataKey: 'count', color: 'var(--danger)', label: 'Defects' }]}
 xKey="label"
 height={180}
 />
 )}
 </Panel>
 </PanelRow>

 {/* Row 5: Forecasting */}
 {can('forecasting.view') && (
 <PanelRow cols={3}>
 <StockOutPanel title="Stock-out Risk Forecast" />
 <DemandForecastPanel />
 <ForecastAccuracyPanel />
 </PanelRow>
 )}

 {/* Bottleneck widget */}
 {can('dashboard.view_bottlenecks') && <ChainBottleneckWidget hideWhenEmpty />}
 </>
 );
 }}
 </DashboardShell>
 );
}

/* ── Sub-panels ─────────────────────────────────────────────────────────── */

function StageBar({ stages }: { stages: PlantManagerData['panels']['chain_stages'] }) {
 if (stages.length === 0) {
 return (
 <EmptyState size="compact" icon="inbox" title="Order pipeline empty" description="No active orders in the pipeline." />
 );
 }
 const colorMap: Record<string, string> = {
 success: 'bg-success-bg',
 info: 'bg-info-bg',
 warning: 'bg-warning-bg',
 danger: 'bg-danger-bg',
 };
 return (
 <div className="space-y-2">
 {stages.map((s) => (
 <div key={s.key} className="flex items-center gap-3">
 <span className="w-32 shrink-0 text-sm">{s.label}</span>
 <div className="flex-1 h-3 bg-elevated rounded-full overflow-hidden">
 <div
 className={`h-full rounded-full transition-all duration-500 ${colorMap[s.color] ?? 'bg-accent'}`}
 style={{ width: `${s.percent}%` }}
 role="progressbar"
 aria-valuenow={s.count}
 aria-valuemin={0}
 aria-valuemax={Math.max(1, ...stages.map((x) => x.count))}
 aria-label={`${s.label}: ${s.count}`}
 />
 </div>
 <span className="w-8 text-right text-sm font-mono tabular-nums">{s.count}</span>
 </div>
 ))}
 </div>
 );
}

function MachineUtilPanel({ machines }: { machines: PlantManagerData['panels']['machine_util'] }) {
 const statusVariant = (status: string): 'success' | 'warning' | 'danger' | 'neutral' | 'info' => {
 switch (status) {
 case 'running': return 'success';
 case 'idle': case 'setup': return 'warning';
 case 'breakdown': case 'down': case 'stopped': return 'danger';
 default: return 'neutral';
 }
 };
 return (
 <Panel title="Machine Utilization" actions={<Link className="text-xs text-link hover:underline" to="/mrp/machines">Open machines →</Link>}>
 {machines.length === 0 ? (
 <EmptyState size="compact" icon="cpu" title="No machines" description="No machines are configured yet." />
 ) : (
 <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
 {machines.map((m) => (
 <Link
 key={m.id}
 to={`/mrp/machines/${m.id}`}
 className="p-2 rounded-md border border-default bg-surface hover:bg-elevated transition-colors"
 >
 <div className="text-xs font-medium truncate">{m.code}</div>
 <div className="flex items-center gap-1 mt-1">
 <Chip variant={statusVariant(m.status)}>{m.status_label ?? m.status}</Chip>
 {m.has_active_wo && <span className="text-2xs text-muted">running</span>}
 </div>
 </Link>
 ))}
 </div>
 )}
 </Panel>
 );
}

function DefectParetoPanel({ defects }: { defects: PlantManagerData['panels']['defect_pareto'] }) {
 if (defects.length === 0) {
 return (
 <Panel title="Defect Pareto (top 8)">
 <EmptyState size="compact" icon="check-circle" title="No defects" description="No defects recorded this period." />
 </Panel>
 );
 }
 const maxCount = Math.max(...defects.map((d) => d.count), 1);
 return (
 <Panel title="Defect Pareto (top 8)" actions={<Link className="text-xs text-link hover:underline" to="/quality/ncrs">Open NCRs →</Link>}>
 <div className="space-y-1.5">
 {defects.map((d) => (
 <div key={d.code} className="flex items-center gap-2 text-sm">
 <span className="w-20 truncate text-muted" title={d.name}>{d.code}</span>
 <div className="flex-1 h-2.5 bg-elevated rounded-full overflow-hidden">
 <div
 className="h-full bg-danger-bg rounded-full transition-all duration-500"
 style={{ width: `${(d.count / maxCount) * 100}%` }}
 role="progressbar"
 aria-valuenow={d.count}
 aria-valuemin={0}
 aria-valuemax={maxCount}
 aria-label={`${d.name}: ${d.count}`}
 />
 </div>
 <span className="w-8 text-right font-mono tabular-nums">{d.count}</span>
 </div>
 ))}
 </div>
 </Panel>
 );
}

function AlertsPanel({ alerts }: { alerts: PlantManagerData['panels']['alerts'] }) {
 const sevDot: Record<string, string> = {
 danger: 'bg-danger-bg',
 warning: 'bg-warning-bg',
 success: 'bg-success-bg',
 neutral: 'bg-strong',
 };
 return (
 <Panel
 title="Alerts & Attention"
 meta={alerts.length ? String(alerts.length) : undefined}
 actions={<Link className="text-xs text-link hover:underline" to="/alerts">All alerts →</Link>}
 >
 {alerts.length === 0 ? (
 <EmptyState size="compact" icon="bell-off" title="All clear" description="No active alerts." />
 ) : (
 <ul className="divide-y divide-subtle">
 {alerts.map((a, i) => (
 <li key={`${a.kind}-${i}`} className="py-2">
 <Link
 to={alertRefLink(a.ref, a.ref_id, a.kind)}
 className="flex items-center gap-2 text-sm rounded-sm -mx-1 px-1 hover:bg-subtle transition-colors duration-fast"
 >
 <span className={`inline-block h-1.5 w-1.5 rounded-full shrink-0 ${sevDot[a.severity] ?? 'bg-strong'}`} aria-hidden />
 <span className="truncate">{a.label}</span>
 </Link>
 </li>
 ))}
 </ul>
 )}
 </Panel>
 );
}

function FinancialSnapshotPanel({
 snapshot,
}: {
 snapshot: PlantManagerData['panels']['financial_snapshot'];
}) {
 const rows: Array<{ label: string; value: string; href: string }> = [
 { label: 'Cash on hand', value: snapshot.cash_balance, href: '/accounting/coa' },
 { label: 'AR Outstanding', value: snapshot.ar_outstanding, href: '/accounting/invoices' },
 { label: 'AP Outstanding', value: snapshot.ap_outstanding, href: '/accounting/bills' },
 { label: 'Revenue MTD', value: snapshot.revenue_mtd, href: '/accounting/income-statement' },
 ];
 return (
 <Panel title="Financial Snapshot" actions={<Link className="text-xs text-link hover:underline" to="/accounting">Accounting →</Link>}>
 <table className={tableCls}>
 <caption className="sr-only">Financial snapshot</caption>
 <thead className="sr-only">
 <tr>
 <Th>Metric</Th>
 <Th align="right">Amount</Th>
 </tr>
 </thead>
 <tbody>
 {rows.map((r) => (
 <tr key={r.label} className={trCls}>
 <Td className="text-muted">{r.label}</Td>
 <Td align="right" mono>
 <Link to={r.href} className="hover:underline">
 {formatPeso(r.value)}
 </Link>
 </Td>
 </tr>
 ))}
 <tr className={trCls}>
 <Td className="text-muted">Draft JEs</Td>
 <Td align="right" mono>{snapshot.je_draft_count}</Td>
 </tr>
 </tbody>
 </table>
 </Panel>
 );
}
