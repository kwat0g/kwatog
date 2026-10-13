/**
 * QC Inspector Dashboard — Task D8.
 *
 * Data source: GET /api/v1/dashboards/quality (via dashboardsApi.quality)
 * Backend: RoleDashboardService::quality()
 * Cache: 30s Redis per user
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { dashboardsApi, DASHBOARD_REFRESH_MS } from '@/api/dashboards';
import { kpiLink } from '@/lib/dashboardLinks';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Th, Td, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { DashboardShell, KpiGrid, PanelRow } from '@/components/dashboard/DashboardShell';
import { PeriodNote } from '@/components/dashboard/period';
import { ForecastPanel } from '@/components/dashboard/ForecastPanel';
import { DonutBreakdown, BarComparison } from '@/components/charts';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { QC_KPIS } from '@/lib/dashboardKpis';

/* ───────────────────────── Typed interface ───────────────────────── */

interface InspectionItem {
 id: string;
 inspection_number: string;
 stage: string;
 stage_label?: string;
 product: string;
 batch_no: string | null;
 qty: string | null;
 waiting_since: string;
}

interface DefectItem {
 code: string;
 name: string;
 count: number;
}

interface NcrItem {
 id: string;
 ncr_number: string;
 severity: string;
 severity_label?: string;
 customer: string;
 defect_code: string;
 status: string;
 status_label?: string;
}

interface ChainCoverage {
 label?: string;
 inspected: number;
 total: number;
 pct: number;
}

interface QualityDashboardData {
 display_policy?: {
 defect_danger_pct: number;
 defect_warning_pct: number;
 coverage_success_pct: number;
 coverage_info_pct: number;
 coverage_warning_pct: number;
 };
 kpis: Array<{ label: string; value: string | null; unit: string }>;
 panels: {
 inspection_queue: InspectionItem[];
 defect_pareto: DefectItem[];
 ncr_status: NcrItem[];
 qc_chain_coverage: {
 incoming: ChainCoverage;
 in_process: ChainCoverage;
 outgoing: ChainCoverage;
 };
 defect_rate_forecast: import('@/types/forecasting-dashboard').ForecastPanelData;
 };
}

/* ───────────────────────── Sub-panel components ───────────────────────── */

function InspectionQueuePanel({ items }: { items: InspectionItem[] }) {
 if (items.length === 0) {
 return (
 <Panel title="Inspection Queue">
 <EmptyState icon="check-circle" title="Queue empty" description="No inspections pending. All stages current." />
 </Panel>
 );
 }

 return (
 <Panel title="Inspection Queue" meta={items.length.toString()} noPadding scrollBody bodyClassName="px-1.5 pb-2">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Inspection</Th>
 <Th>Stage</Th>
 <Th>Product</Th>
 <Th align="right">Qty</Th>
 <Th align="right">Waiting</Th>
 </tr>
 </thead>
 <tbody>
 {items.map((ins) => {
 const stageVariant = ins.stage === 'outgoing' ? 'danger' : ins.stage === 'in_process' ? 'warning' : 'info';
 return (
 <tr key={ins.id} className={trCls}>
 <Td>
 <Link
 to={`/quality/inspections/${ins.id}`}
 className="text-link hover:underline font-mono text-xs"
 aria-label={`View inspection ${ins.inspection_number}`}
 >
 {ins.inspection_number}
 </Link>
 </Td>
 <Td>
 <Chip variant={stageVariant}>{ins.stage_label ?? ins.stage}</Chip>
 </Td>
 <Td className="text-muted text-xs truncate max-w-[120px]">{ins.product}</Td>
 <Td align="right" mono>{ins.qty ?? '—'}</Td>
 <Td align="right" mono className="text-muted">{ins.waiting_since}</Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </Panel>
 );
}

function DefectParetoPanel({ items, policy }: { items: DefectItem[]; policy?: QualityDashboardData['display_policy'] }) {
 if (items.length === 0) {
 return (
 <Panel title="Defect Pareto">
 <EmptyState icon="check-circle" title="No defects" description="No defects recorded in the current period." />
 </Panel>
 );
 }

 const maxCount = Math.max(1, ...items.map((i) => i.count));

 return (
 <Panel title="Defect Pareto (Top Defects)">
 <ul className="space-y-2">
 {items.map((d) => {
 const pct = Math.round((d.count / maxCount) * 100);
 return (
 <li key={d.code}>
 <div className="flex items-center justify-between text-sm mb-1">
 <span className="truncate">
 <span className="font-mono text-xs">{d.code}</span>
 <span className="text-muted ml-1">{d.name}</span>
 </span>
 <span className="font-mono tabular-nums ml-2">{d.count}</span>
 </div>
 <ProgressBar
   value={pct}
   variant={defectVariant(pct, policy)}
   height="2"
   ariaLabel={`${d.code}: ${d.count} occurrences`}
 />
 </li>
 );
 })}
 </ul>
 </Panel>
 );
}

function defectVariant(pct: number, policy?: QualityDashboardData['display_policy']): 'danger' | 'warning' | 'info' {
  if (policy && pct >= policy.defect_danger_pct) return 'danger';
  if (policy && pct >= policy.defect_warning_pct) return 'warning';
  return 'info';
}

function NcrStatusPanel({ items }: { items: NcrItem[] }) {
 if (items.length === 0) {
 return (
 <Panel title="Open NCRs">
 <EmptyState icon="check-circle" title="No open NCRs" description="All non-conformance reports are closed." />
 </Panel>
 );
 }

 return (
 <Panel title="Open NCRs" meta={items.length.toString()} noPadding scrollBody bodyClassName="px-1.5 pb-2">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>NCR</Th>
 <Th>Severity</Th>
 <Th>Customer</Th>
 <Th>Status</Th>
 </tr>
 </thead>
 <tbody>
 {items.map((ncr) => {
 const severityVariant = ncr.severity === 'critical' ? 'danger' : ncr.severity === 'major' ? 'warning' : 'info';
 return (
 <tr key={ncr.id} className={trCls}>
 <Td>
 <Link
 to={`/quality/ncrs/${ncr.id}`}
 className="text-link hover:underline font-mono text-xs"
 aria-label={`View NCR ${ncr.ncr_number}`}
 >
 {ncr.ncr_number}
 </Link>
 </Td>
 <Td>
 <Chip variant={severityVariant}>{ncr.severity_label ?? ncr.severity}</Chip>
 </Td>
 <Td className="text-muted text-xs truncate max-w-[120px]">{ncr.customer}</Td>
 <Td>
 <Chip variant={ncr.status === 'open' ? 'danger' : 'warning'}>{ncr.status_label ?? ncr.status}</Chip>
 </Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </Panel>
 );
}

function QcChainCoveragePanel({ coverage, policy }: { coverage: QualityDashboardData['panels']['qc_chain_coverage'] | undefined; policy?: QualityDashboardData['display_policy'] }) {
 const safe = coverage ?? { incoming: { inspected: 0, total: 0, pct: 0 }, in_process: { inspected: 0, total: 0, pct: 0 }, outgoing: { inspected: 0, total: 0, pct: 0 } };
 const stages = [
 { key: 'incoming', ...safe.incoming, label: safe.incoming.label ?? 'Incoming' },
 { key: 'in_process', ...safe.in_process, label: safe.in_process.label ?? 'In-process' },
 { key: 'outgoing', ...safe.outgoing, label: safe.outgoing.label ?? 'Outgoing' },
 ];

 const allZero = stages.every((s) => s.total === 0);
 if (allZero) {
 return (
 <Panel title="QC Chain Coverage">
 <EmptyState icon="inbox" title="No activity" description="No QC activity this week." />
 </Panel>
 );
 }

 return (
 <Panel
 title="QC Chain Coverage"
 meta={<PeriodNote fallback="current calendar week" />}
 >
 <ul className="space-y-3">
 {stages.map((s) => (
 <li key={s.key}>
 <div className="flex items-center justify-between text-sm mb-1">
 <span>{s.label}</span>
 <span className="text-muted text-xs">
 {s.inspected}/{s.total}
 <span className="font-mono tabular-nums ml-1">({s.pct}%)</span>
 </span>
 </div>
 <ProgressBar
   value={s.pct}
   variant={coverageVariant(s.pct, policy)}
   height="2"
   ariaLabel={`${s.label}: ${s.inspected} of ${s.total} inspected`}
 />
 </li>
 ))}
 </ul>
 </Panel>
 );
}

function coverageVariant(
  pct: number,
  policy?: QualityDashboardData['display_policy'],
): 'success' | 'info' | 'warning' | 'danger' {
  if (policy && pct >= policy.coverage_success_pct) return 'success';
  if (policy && pct >= policy.coverage_info_pct) return 'info';
  if (policy && pct >= policy.coverage_warning_pct) return 'warning';
  return 'danger';
}

/* ───────────────────────── Page component ───────────────────────── */

export default function QcDashboard() {
 const q = useQuery({
 queryKey: ['dashboard', 'quality'],
 queryFn: () => dashboardsApi.quality<QualityDashboardData>(),
 refetchInterval: DASHBOARD_REFRESH_MS,
 });

 return (
 <DashboardShell<QualityDashboardData>
 title="QC Dashboard"
 subtitle="Live · refreshes every 60s"
 query={q}
 refreshingQueryKey={['dashboard', 'quality']}
 header={<KpiStrip codes={QC_KPIS} compact />}
 >
 {({ kpis, panels, display_policy }) => {
 const stageCounts: Record<string, number> = {};
 (panels?.inspection_queue ?? []).forEach((ins) => {
 stageCounts[ins.stage] = (stageCounts[ins.stage] || 0) + 1;
 });
 const colorMap: Record<string, string> = {
 incoming: 'var(--info)',
 in_process: 'var(--warning)',
 outgoing: 'var(--danger)',
 };
 const inspectionStageData = Object.entries(stageCounts).map(([name, value]) => ({
 name,
 value,
 color: colorMap[name] ?? 'var(--text-muted)',
 }));

 return (
 <>
 {/* ── Row 1: KPIs ── */}
 <KpiGrid count={kpis.length}>
 {kpis.map((k) => (
 <StatCard
 key={k.label}
 label={k.label}
 value={k.value == null ? '—' : /^[A-Z]{3}$/.test(k.unit) ? `${k.unit} ${k.value}` : k.value}
 helper={!/^[A-Z]{3}$/.test(k.unit) && k.unit !== 'count' ? k.unit : undefined}
 linkTo={kpiLink(k.label)}
 />
 ))}
 </KpiGrid>

 {/* ── Row 2: Inspection Queue + Defect Pareto ── */}
 <PanelRow>
 <InspectionQueuePanel items={panels?.inspection_queue ?? []} />
 <DefectParetoPanel items={panels?.defect_pareto ?? []} policy={display_policy} />
 </PanelRow>

 {/* ── Row 3: Open NCRs + QC Chain Coverage ── */}
 <PanelRow>
 <NcrStatusPanel items={panels?.ncr_status ?? []} />
 <QcChainCoveragePanel coverage={panels?.qc_chain_coverage} policy={display_policy} />
 </PanelRow>

 {/* ── Row 4: Charts ── */}
 <PanelRow>
 <Panel title="Inspection Queue by Stage">
 {inspectionStageData.length === 0 ? (
 <EmptyState icon="check-circle" title="Queue empty" description="No pending inspections." />
 ) : (
 <DonutBreakdown
 data={inspectionStageData}
 centerLabel="Total"
 centerValue={String(inspectionStageData.reduce((sum, i) => sum + i.value, 0))}
 />
 )}
 </Panel>
 <Panel title="Top Defects by Count">
 {(panels?.defect_pareto ?? []).length === 0 ? (
 <EmptyState icon="check-circle" title="No defects" description="No defects recorded." />
 ) : (
 <BarComparison
 data={(panels?.defect_pareto ?? []).slice(0, 6).map((d) => ({ label: d.code, count: d.count }))}
 bars={[{ dataKey: 'count', color: 'var(--danger)', label: 'Occurrences' }]}
 xKey="label"
 height={180}
 />
 )}
 </Panel>
 </PanelRow>

  {/* ── Row 5: Defect Rate Forecast ── */}
  <ForecastPanel
  data={panels?.defect_rate_forecast}
  isLoading={false}
  isError={false}
  title={`Defect Rate Forecast (${panels?.defect_rate_forecast?.forecast.length ?? 0} months)`}
  formatValue={(v) => `${v.toFixed(1)}%`}
  unitLabel="%"
  detailsHref="/quality/ncrs"
  />
 </>
 );
 }}
 </DashboardShell>
 );
}
