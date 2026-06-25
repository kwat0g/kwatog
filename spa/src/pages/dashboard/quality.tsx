/**
 * QC Inspector Dashboard — Task D8.
 *
 * Data source: GET /api/v1/dashboards/quality (via dashboardsApi.quality)
 * Backend:     RoleDashboardService::quality()
 * Cache:       30s Redis per user
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { dashboardsApi } from '@/api/dashboards';
import { kpiLink } from '@/lib/dashboardLinks';
import { formatPeso } from '@/lib/formatNumber';
import { PageHeader } from '@/components/layout/PageHeader';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock, SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { ForecastPanel } from '@/components/dashboard/ForecastPanel';
import { DonutBreakdown, BarComparison } from '@/components/charts';
import { CopqWidget } from '@/pages/dashboard/widgets/CopqWidget';

/* ───────────────────────── Typed interface ───────────────────────── */

interface InspectionItem {
  id: string;
  inspection_number: string;
  stage: string;
  product: string;
  batch_no: string | null;
  qty: string;
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
  customer: string;
  defect_code: string;
  status: string;
}

interface ChainCoverage {
  inspected: number;
  total: number;
  pct: number;
}

interface CopqData {
  internal_failure: {
    scrap_units: number;
    rework_units: number;
    scrap_cost: number;
    rework_cost: number;
  };
  external_failure: {
    returns: number;
    complaints: number;
    return_cost: number;
  };
  total: number;
  period_label: string;
}

interface QualityDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
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
    copq?: CopqData;
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
    <Panel title="Inspection Queue" meta={items.length.toString()}>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-subtle">
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Inspection</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Stage</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Product</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Qty</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Waiting</th>
          </tr>
        </thead>
        <tbody>
          {items.map((ins) => {
            const stageVariant = ins.stage === 'outgoing' ? 'danger' : ins.stage === 'in_process' ? 'warning' : 'info';
            return (
              <tr key={ins.id} className="border-b border-subtle h-7 hover:bg-subtle/30 transition-colors">
                <td className="py-1">
                  <Link
                    to={`/quality/inspections/${ins.id}`}
                    className="text-link hover:underline font-mono text-xs"
                    aria-label={`View inspection ${ins.inspection_number}`}
                  >
                    {ins.inspection_number}
                  </Link>
                </td>
                <td className="py-1">
                  <Chip variant={stageVariant}>{ins.stage}</Chip>
                </td>
                <td className="py-1 text-muted text-xs truncate max-w-[120px]">{ins.product}</td>
                <td className="py-1 text-right font-mono tabular-nums text-xs">{ins.qty}</td>
                <td className="py-1 text-right font-mono tabular-nums text-xs text-muted">{ins.waiting_since}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </Panel>
  );
}

function DefectParetoPanel({ items }: { items: DefectItem[] }) {
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
              <div
                role="progressbar"
                aria-valuenow={pct}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`${d.code}: ${d.count} occurrences`}
                className="h-2 bg-subtle rounded-full overflow-hidden"
              >
                <div
                  className={defectBarClass(pct)}
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

function defectBarClass(pct: number): string {
  if (pct >= 75) return 'h-full bg-danger rounded-full';
  if (pct >= 50) return 'h-full bg-warning rounded-full';
  return 'h-full bg-info rounded-full';
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
    <Panel title="Open NCRs" meta={items.length.toString()}>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-subtle">
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">NCR</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Severity</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Customer</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Status</th>
          </tr>
        </thead>
        <tbody>
          {items.map((ncr) => {
            const severityVariant = ncr.severity === 'critical' ? 'danger' : ncr.severity === 'major' ? 'warning' : 'info';
            return (
              <tr key={ncr.id} className="border-b border-subtle h-7 hover:bg-subtle/30 transition-colors">
                <td className="py-1">
                  <Link
                    to={`/quality/ncrs/${ncr.id}`}
                    className="text-link hover:underline font-mono text-xs"
                    aria-label={`View NCR ${ncr.ncr_number}`}
                  >
                    {ncr.ncr_number}
                  </Link>
                </td>
                <td className="py-1">
                  <Chip variant={severityVariant}>{ncr.severity}</Chip>
                </td>
                <td className="py-1 text-muted text-xs truncate max-w-[120px]">{ncr.customer}</td>
                <td className="py-1">
                  <Chip variant={ncr.status === 'open' ? 'danger' : 'warning'}>{ncr.status}</Chip>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </Panel>
  );
}

function QcChainCoveragePanel({ coverage }: { coverage: QualityDashboardData['panels']['qc_chain_coverage'] | undefined }) {
  const safe = coverage ?? { incoming: { inspected: 0, total: 0, pct: 0 }, in_process: { inspected: 0, total: 0, pct: 0 }, outgoing: { inspected: 0, total: 0, pct: 0 } };
  const stages = [
    { key: 'incoming', label: 'Incoming (GRN)', ...safe.incoming },
    { key: 'in_process', label: 'In-Process (WIP)', ...safe.in_process },
    { key: 'outgoing', label: 'Outgoing (FGI)', ...safe.outgoing },
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
    <Panel title="QC Chain Coverage (This Week)">
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
            <div
              role="progressbar"
              aria-valuenow={s.pct}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label={`${s.label}: ${s.inspected} of ${s.total} inspected`}
              className="h-2.5 bg-subtle rounded-full overflow-hidden"
            >
              <div
                className={coverageBarClass(s.pct)}
                style={{ width: `${s.pct}%` }}
              />
            </div>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function coverageBarClass(pct: number): string {
  if (pct >= 90) return 'h-full bg-success rounded-full';
  if (pct >= 75) return 'h-full bg-info rounded-full';
  if (pct >= 50) return 'h-full bg-warning rounded-full';
  return 'h-full bg-danger rounded-full';
}

/* ───────────────────────── Page component ───────────────────────── */

export default function QcDashboard() {
  const q = useQuery({
    queryKey: ['dashboard', 'quality'],
    queryFn: () => dashboardsApi.quality(),
    refetchInterval: 60_000,
  });

  // Compute chart data
  const inspectionStageData = (q.data as unknown as QualityDashboardData)?.panels?.inspection_queue ? (() => {
    const stageCounts: Record<string, number> = {};
    (q.data as unknown as QualityDashboardData).panels.inspection_queue.forEach(ins => {
      stageCounts[ins.stage] = (stageCounts[ins.stage] || 0) + 1;
    });
    const colorMap: Record<string, string> = {
      incoming: 'var(--color-info)',
      in_process: 'var(--color-warning)',
      outgoing: 'var(--color-danger)',
    };
    return Object.entries(stageCounts).map(([name, value]) => ({
      name,
      value,
      color: colorMap[name] ?? 'var(--color-muted)',
    }));
  })() : [];

  /* ─── LOADING ─── */
  if (q.isLoading && !q.data) {
    return (
      <div>
        <PageHeader title="QC Dashboard" subtitle="Quality control overview" />
        <div className="px-5 py-6 space-y-4">
          <div className="grid grid-cols-4 gap-2">
            {[1, 2, 3, 4].map((i) => <SkeletonBlock key={i} className="h-16 rounded-md" />)}
          </div>
          <SkeletonDetail />
        </div>
      </div>
    );
  }

  /* ─── ERROR ─── */
  if (q.isError || !q.data) {
    return (
      <div>
        <PageHeader title="QC Dashboard" subtitle="Quality control overview" />
        <div className="px-5 py-6">
          <EmptyState
            icon="alert-circle"
            title="Failed to load dashboard"
            action={<Button variant="secondary" onClick={() => q.refetch()}>Retry</Button>}
          />
        </div>
      </div>
    );
  }

  const { kpis, panels } = q.data as unknown as QualityDashboardData;

  return (
    <div>
      <PageHeader title="QC Dashboard" subtitle="Live · refreshes every 60s" />

      <div className="px-5 py-4 space-y-4">
        {/* ── Row 1: KPIs ── */}
        <section className="grid grid-cols-4 gap-2">
          {kpis.map((k) => (
            <StatCard
              key={k.label}
              label={k.label}
              value={k.unit === 'PHP' ? `₱ ${k.value}` : k.value}
              helper={k.unit !== 'PHP' && k.unit !== 'count' ? k.unit : undefined}
              linkTo={kpiLink(k.label)}
            />
          ))}
        </section>

        {/* ── Row 2: Inspection Queue + Defect Pareto ── */}
        <div className="grid grid-cols-2 gap-4">
          <InspectionQueuePanel items={panels?.inspection_queue ?? []} />
          <DefectParetoPanel items={panels?.defect_pareto ?? []} />
        </div>

        {/* ── Row 3: Open NCRs + QC Chain Coverage ── */}
        <div className="grid grid-cols-2 gap-4">
          <NcrStatusPanel items={panels?.ncr_status ?? []} />
          <QcChainCoveragePanel coverage={panels?.qc_chain_coverage} />
        </div>

        {/* ── Row 3.5: Chart visualizations ── */}
        <div className="grid grid-cols-2 gap-4">
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
                data={(panels?.defect_pareto ?? []).slice(0, 6).map(d => ({ label: d.code, count: d.count }))}
                bars={[{ dataKey: 'count', color: 'var(--color-danger)', label: 'Occurrences' }]}
                xKey="label"
                height={180}
              />
            )}
          </Panel>
        </div>

        {/* ── Row 4: Defect Rate Forecast ── */}
        <ForecastPanel
          data={panels?.defect_rate_forecast}
          isLoading={false}
          isError={false}
          title="Defect Rate Forecast (6 months)"
          formatValue={(v) => `${v.toFixed(1)}%`}
          unitLabel="%"
        />

        {/* ── Row 5: COPQ Widget (dedicated component with trend chart) ── */}
        <CopqWidget />
      </div>
    </div>
  );
}
