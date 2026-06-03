import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { client } from '@/api/client';
import { ChainBottleneckWidget } from '@/components/dashboard/ChainBottleneckWidget';
import { StockOutPanel } from '@/components/dashboard/StockOutPanel';
import { DemandForecastPanel } from '@/components/dashboard/DemandForecastPanel';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { alertRefLink } from '@/lib/dashboardLinks';
import type { ApiSuccess } from '@/types';

/**
 * Task D2 — Plant Manager dashboard.
 *
 * Opinionated 4-row layout (replaces the generic `<RoleDashboard>` wrapper):
 *   Row 1 — 4 KPI stat cards
 *   Row 2 — Chain stage breakdown
 *   Row 3 — Machine utilization + defect pareto
 *   Row 4 — Alerts panel + financial snapshot
 *   Bottom — Chain bottleneck widget (if permitted)
 */

interface PlantManagerData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
    chain_stages: Array<{ key: string; label: string; color: string; count: number; percent: number }>;
    alerts: Array<{ kind: string; severity: string; label: string; ref: string | null; ref_id: string | null }>;
    machine_util: Array<{ id: string; code: string; name: string; status: string; has_active_wo: boolean }>;
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
    <div>
      <PageHeader
        title="Plant Manager Dashboard"
        subtitle="Production, quality, and financial overview."
        actions={
          <div className="inline-flex rounded-md border border-default overflow-hidden text-sm" role="group" aria-label="Time range">
            {(['today', 'week', 'month', 'quarter'] as const).map((r) => (
              <button
                key={r}
                type="button"
                onClick={() => setRange(r)}
                className={`px-3 py-1.5 capitalize transition-colors duration-fast ${
                  range === r ? 'bg-accent text-accent-fg' : 'bg-canvas text-secondary hover:bg-elevated'
                }`}
                aria-pressed={range === r}
              >
                {r}
              </button>
            ))}
          </div>
        }
      />
      <div className="px-5 py-4 space-y-4">
        {q.isLoading && !q.data && <SkeletonDetail />}

        {q.isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load plant dashboard"
            description="We couldn't reach the plant manager dashboard."
            action={
              <Button variant="secondary" onClick={() => q.refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {q.data && (
          <>
            {/* Row 1 — KPIs */}
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
              {q.data.kpis.map((kpi) => (
                <StatCard
                  key={kpi.label}
                  label={kpi.label}
                  value={`${kpi.value}${kpi.unit === 'pct' ? '%' : ''}`}
                  helper={kpi.unit === 'PHP' ? 'PHP' : kpi.unit === 'pct' ? 'yield' : kpi.unit}
                />
              ))}
            </div>

            {/* Row 2 — Chain stage breakdown */}
            <Panel title="Order-to-Cash Chain" actions={<Link className="text-xs text-link hover:underline" to="/approvals">View board →</Link>}>
              <StageBar stages={q.data.panels.chain_stages} />
            </Panel>

            {/* Row 3 — Machine utilization + defect pareto */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <MachineUtilPanel machines={q.data.panels.machine_util} />
              <DefectParetoPanel defects={q.data.panels.defect_pareto} />
            </div>

            {/* Row 4 — Alerts + financial snapshot */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <AlertsPanel alerts={q.data.panels.alerts} />
              <FinancialSnapshotPanel snapshot={q.data.panels.financial_snapshot} />
            </div>

            {/* Row 5: Forecasting */}
            <div className="grid grid-cols-2 gap-3">
              <StockOutPanel title="Stock-out Risk Forecast" horizonDays={30} hideWhenEmpty />
              <DemandForecastPanel hideWhenEmpty />
            </div>

            {/* Bottleneck widget */}
            {can('dashboard.view_bottlenecks') && (
              <ChainBottleneckWidget hideWhenEmpty />
            )}
          </>
        )}
      </div>
    </div>
  );
}

/* ── Sub-panels ─────────────────────────────────────────────────────────── */

function StageBar({ stages }: { stages: PlantManagerData['panels']['chain_stages'] }) {
  if (stages.length === 0) {
    return <p className="text-sm text-muted">No active orders in the pipeline.</p>;
  }
  const colorMap: Record<string, string> = {
    success: 'bg-success',
    info: 'bg-info',
    warning: 'bg-warning',
    danger: 'bg-danger',
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
        <p className="text-sm text-muted">No machines configured.</p>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
          {machines.map((m) => (
            <Link
              key={m.id}
              to={`/mrp/machines/${m.id}`}
              className="p-2 rounded-md border border-border bg-surface hover:bg-elevated transition-colors"
            >
              <div className="text-xs font-medium truncate">{m.code}</div>
              <div className="flex items-center gap-1 mt-1">
                <Chip variant={statusVariant(m.status)}>{m.status}</Chip>
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
        <p className="text-sm text-muted">No defects recorded.</p>
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
                className="h-full bg-danger rounded-full transition-all duration-500"
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
    danger: 'bg-danger',
    warning: 'bg-warning',
    success: 'bg-success',
    neutral: 'bg-muted',
  };
  return (
    <Panel
      title="Alerts & Attention"
      meta={alerts.length ? String(alerts.length) : undefined}
      actions={<Link className="text-xs text-link hover:underline" to="/alerts">All alerts →</Link>}
    >
      {alerts.length === 0 ? (
        <p className="text-sm text-muted">No active alerts.</p>
      ) : (
        <ul className="divide-y divide-subtle">
          {alerts.map((a, i) => (
            <li key={`${a.kind}-${i}`} className="py-2">
              <Link
                to={alertRefLink(a.ref, a.ref_id, a.kind)}
                className="flex items-center gap-2 text-sm rounded-sm -mx-1 px-1 hover:bg-subtle transition-colors duration-fast"
              >
                <span className={`inline-block h-1.5 w-1.5 rounded-full shrink-0 ${sevDot[a.severity] ?? 'bg-muted'}`} aria-hidden />
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
    { label: 'Cash on hand',  value: snapshot.cash_balance,  href: '/accounting/coa' },
    { label: 'AR Outstanding', value: snapshot.ar_outstanding, href: '/accounting/invoices' },
    { label: 'AP Outstanding', value: snapshot.ap_outstanding, href: '/accounting/bills' },
    { label: 'Revenue MTD',   value: snapshot.revenue_mtd,   href: '/accounting/income-statement' },
  ];
  return (
    <Panel title="Financial Snapshot" actions={<Link className="text-xs text-link hover:underline" to="/accounting">Accounting →</Link>}>
      <table className="w-full text-sm">
        <tbody>
          {rows.map((r) => (
            <tr key={r.label} className="border-b border-border last:border-0">
              <td className="py-1.5 text-muted">{r.label}</td>
              <td className="py-1.5 text-right">
                <Link to={r.href} className="font-mono tabular-nums hover:underline">
                  {formatPeso(r.value)}
                </Link>
              </td>
            </tr>
          ))}
          <tr>
            <td className="pt-2 text-muted">Draft JEs</td>
            <td className="pt-2 text-right font-mono tabular-nums">
              {snapshot.je_draft_count}
            </td>
          </tr>
        </tbody>
      </table>
    </Panel>
  );
}


