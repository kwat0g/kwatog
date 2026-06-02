/**
 * PPC (Production Planning & Control) Dashboard.
 *
 * Upgraded from the generic <RoleDashboard role="ppc" /> wrapper to an
 * explicit page with typed data, inline sub-panel components, and full
 * loading/error/empty state coverage — matching the quality bar set by
 * D2 (Plant Manager), D4 (HR), and D5 (Finance) dashboards.
 *
 * Data source: GET /api/v1/dashboards/ppc (via dashboardsApi.ppc)
 * Backend:     RoleDashboardService::ppc()
 * Cache:       30s Redis per user
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { dashboardsApi } from '@/api/dashboards';
import { chainApi } from '@/api/chain';
import type { ChainBottleneckGroup, ChainBottleneckRow, ChainBottlenecks } from '@/types/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock, SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { usePermission } from '@/hooks/usePermission';
import { chainStageLink, alertRefLink, kpiLink } from '@/lib/dashboardLinks';

/* ───────────────────────── Typed interface ───────────────────────── */

interface PpcKpi {
  label: string;
  value: string;
  unit: string;
}

interface ChainStage {
  key: string;
  label: string;
  color: string;
  count: number;
  percent: number;
}

interface AlertItem {
  kind: string;
  severity: string;
  label: string;
  ref: string | null;
  ref_id: string | null;
}

interface MachineRow {
  id: string;
  code: string;
  name: string;
  status: string;
  has_active_wo: boolean;
}

interface GanttRow {
  machine: string;
  date: string;
  label: string;
  status: string;
}

interface ProductionGanttRow { machine: string; day: string; status: string; wo_number: string | null; }

interface MrpShortage {
  item_code: string;
  item_name: string;
  shortage: string;
  urgency: string;
  pr_status: string | null;
}

interface WoStatusItem {
  status: string;
  count: number;
}

interface PpcDashboardData {
  kpis: PpcKpi[];
  panels: {
    chain_stages: ChainStage[];
    alerts: AlertItem[];
    machine_util: MachineRow[];
    // D3 — New panels
    mrp_last_run: string;
    unplanned_wos: number;
    production_gantt: ProductionGanttRow[];
    mrp_shortages: MrpShortage[];
    machine_availability: GanttRow[];
    wo_status_breakdown: WoStatusItem[];
  };
}

/* ───────────────────────── Inline sub-panel components ───────────────────────── */

function KpiRow({ kpis }: { kpis: PpcKpi[] }) {
  return (
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
  );
}

function ChainStagePanel({ stages }: { stages: ChainStage[] }) {
  if (stages.length === 0) return null;

  return (
    <Panel title="Active orders by chain stage">
      <ul className="space-y-2">
        {stages.map((s) => {
          const href = chainStageLink(s.key);
          const inner = (
            <>
              <div className="flex items-center justify-between text-sm mb-1">
                <span>{s.label}</span>
                <span className="font-mono tabular-nums">{s.count}</span>
              </div>
              <div
                role="progressbar"
                aria-valuenow={s.percent}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`${s.label}: ${s.count} orders`}
                className="h-1 bg-subtle rounded-full overflow-hidden"
              >
                <div
                  className={stageFillClass(s.color)}
                  style={{ width: `${s.percent}%` }}
                />
              </div>
            </>
          );
          return (
            <li key={s.key}>
              {href ? (
                <Link
                  to={href}
                  className="block rounded-sm px-1 -mx-1 hover:bg-subtle transition-colors duration-fast"
                  aria-label={`View ${s.label} orders`}
                >
                  {inner}
                </Link>
              ) : (
                inner
              )}
            </li>
          );
        })}
      </ul>
    </Panel>
  );
}

function AlertsPanel({ alerts }: { alerts: AlertItem[] }) {
  if (alerts.length === 0) return null;
  return (
    <Panel title="Alerts" meta={alerts.length.toString()}>
      <ul className="divide-y divide-subtle">
        {alerts.map((a, i) => (
          <li key={`${a.kind}-${i}`} className="py-2">
            <Link
              to={alertRefLink(a.ref, a.ref_id, a.kind)}
              className="flex items-center gap-2 w-full text-sm rounded-sm px-1 -mx-1 hover:bg-subtle transition-colors duration-fast"
              aria-label={`View ${a.label}`}
            >
              <span className={alertDotClass(a.severity)} aria-hidden="true" />
              <span className="truncate">{a.label}</span>
            </Link>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function MachineUtilPanel({ machines }: { machines: MachineRow[] }) {
  if (machines.length === 0) {
    return (
      <Panel title="Machine utilisation">
        <EmptyState icon="cpu" title="No machines" description="No machines configured yet." />
      </Panel>
    );
  }

  return (
    <Panel title="Machine utilisation">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-subtle">
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Code</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Name</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Status</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Active WO</th>
          </tr>
        </thead>
        <tbody>
          {machines.map((m) => (
            <tr key={m.id} className="border-b border-subtle h-7">
              <td className="font-mono">{m.code}</td>
              <td className="text-muted">{m.name}</td>
              <td>
                <Chip variant={machineStatusVariant(m.status)}>{m.status}</Chip>
              </td>
              <td className="text-right font-mono" aria-label={m.has_active_wo ? 'Has active work order' : 'No active work order'}>
                {m.has_active_wo ? '✓' : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

/* ───────────────────────── Helpers ───────────────────────── */

function stageFillClass(color?: string): string {
  if (color === 'danger') return 'h-full bg-danger';
  if (color === 'warning') return 'h-full bg-warning';
  if (color === 'info') return 'h-full bg-info';
  return 'h-full bg-success';
}

function alertDotClass(severity?: string): string {
  const base = 'inline-block w-1.5 h-1.5 rounded-full';
  if (severity === 'danger') return `${base} bg-danger`;
  if (severity === 'warning') return `${base} bg-warning`;
  if (severity === 'info') return `${base} bg-info`;
  return `${base} bg-success`;
}

function machineStatusVariant(status: string): 'info' | 'danger' | 'warning' | 'neutral' {
  if (status === 'running') return 'info';
  if (status === 'breakdown') return 'danger';
  if (status === 'maintenance') return 'warning';
  return 'neutral';
}

/* ───────────────────────── D3 — New sub-panel components ───────────────────────── */

function MrpMetaPanel({ lastRun, unplanned }: { lastRun: string; unplanned: number }) {
  return (
    <Panel title="MRP Overview">
      <dl className="space-y-3 text-sm">
        <div className="flex justify-between">
          <dt className="text-muted">Last MRP Run</dt>
          <dd className="font-mono tabular-nums">{lastRun || '—'}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-muted">Unplanned WOs</dt>
          <dd className="font-mono tabular-nums">{unplanned}</dd>
        </div>
      </dl>
    </Panel>
  );
}

function ProductionGanttPanel({ rows }: { rows: ProductionGanttRow[] }) {
  if (rows.length === 0) {
    return (
      <Panel title="Production Gantt (7-day)">
        <EmptyState icon="inbox" title="No scheduled work" description="No production scheduled in the next 7 days." />
      </Panel>
    );
  }

  const machines = [...new Set(rows.map((r) => r.machine))];
  const days = [...new Set(rows.map((r) => r.day))].sort();

  return (
    <Panel title="Production Gantt (7-day)">
      <div className="overflow-x-auto">
        <table className="w-full text-xs border-collapse">
          <thead>
            <tr>
              <th className="text-left pr-2 py-1 text-2xs uppercase tracking-wider text-muted font-medium">Machine</th>
              {days.map((d) => (
                <th key={d} className="text-center px-1 py-1 text-2xs uppercase tracking-wider text-muted font-medium">
                  {new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short' })}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {machines.map((m) => (
              <tr key={m} className="border-t border-subtle">
                <td className="pr-2 py-1.5 font-mono text-xs">{m}</td>
                {days.map((d) => {
                  const cell = rows.find((r) => r.machine === m && r.day === d);
                  const cls = cell?.status === 'running' ? 'bg-info/30'
                    : cell?.status === 'planned' ? 'bg-warning/20'
                    : 'bg-subtle/30';
                  return (
                    <td
                      key={`${m}-${d}`}
                      className={`text-center px-1 py-1.5 rounded-sm ${cls}`}
                      title={cell?.wo_number ?? undefined}
                      aria-label={`${m} on ${d}: ${cell?.status ?? 'available'}${cell?.wo_number ? ` (${cell.wo_number})` : ''}`}
                    >
                      {cell?.status === 'running' ? '▶' : cell?.status === 'planned' ? '○' : '·'}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}

function MrpShortagesPanel({ shortages }: { shortages: MrpShortage[] }) {
  if (shortages.length === 0) {
    return (
      <Panel title="MRP Shortages">
        <EmptyState icon="check-circle" title="No shortages" description="All auto-generated PRs have been processed." />
      </Panel>
    );
  }

  return (
    <Panel title="MRP Shortages" meta={shortages.length.toString()}>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-subtle">
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Item</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Qty</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Urgency</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">PR</th>
          </tr>
        </thead>
        <tbody>
          {shortages.map((s) => (
            <tr key={s.item_code} className="border-b border-subtle h-7">
              <td className="py-1">
                <span className="font-mono text-xs">{s.item_code}</span>
                <span className="text-muted ml-1">{s.item_name}</span>
              </td>
              <td className="text-right font-mono tabular-nums py-1">{s.shortage}</td>
              <td className="py-1">
                <Chip variant={s.urgency === 'urgent' ? 'danger' : s.urgency === 'high' ? 'warning' : 'neutral'}>
                  {s.urgency}
                </Chip>
              </td>
              <td className="py-1">
                <Chip variant={s.pr_status === 'pending' ? 'warning' : 'neutral'}>
                  {s.pr_status ?? '—'}
                </Chip>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function MachineAvailabilityGrid({ rows }: { rows: GanttRow[] }) {
  if (rows.length === 0) {
    return (
      <Panel title="Machine Availability (7-day)">
        <EmptyState icon="cpu" title="No machines" description="No machines configured." />
      </Panel>
    );
  }

  const machines = [...new Set(rows.map((r) => r.machine))];
  const days = [...new Map(rows.map((r) => [r.date, r.label])).entries()]
    .sort(([a], [b]) => a.localeCompare(b)); // [date, label][]

  return (
    <Panel title="Machine Availability (7-day)">
      <div className="overflow-x-auto">
        <table className="w-full text-xs border-collapse">
          <thead>
            <tr>
              <th className="text-left pr-2 py-1 text-2xs uppercase tracking-wider text-muted font-medium">Machine</th>
              {days.map(([date, label]) => (
                <th key={date} className="text-center px-1 py-1 text-2xs uppercase tracking-wider text-muted font-medium">{label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {machines.map((m) => (
              <tr key={m} className="border-t border-subtle">
                <td className="pr-2 py-1.5 font-mono text-xs">{m}</td>
                {days.map(([date]) => {
                  const cell = rows.find((r) => r.machine === m && r.date === date);
                  const cls = cell?.status === 'available' ? 'bg-success/20'
                    : cell?.status === 'busy' ? 'bg-info/30'
                    : 'bg-danger/20';
                  return (
                    <td
                      key={`${m}-${date}`}
                      className={`text-center px-1 py-1.5 rounded-sm ${cls}`}
                      aria-label={`${m} on ${date}: ${cell?.status ?? 'unknown'}`}
                    >
                      {cell?.status === 'available' ? '✓' : cell?.status === 'busy' ? '●' : '✗'}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}

function WoStatusBreakdownPanel({ items }: { items: WoStatusItem[] }) {
  if (items.length === 0) {
    return (
      <Panel title="WO Status Breakdown">
        <EmptyState icon="inbox" title="No work orders" description="No work orders found." />
      </Panel>
    );
  }

  const maxCount = Math.max(1, ...items.map((i) => i.count));

  return (
    <Panel title="WO Status Breakdown">
      <ul className="space-y-2">
        {items.map((i) => {
          const pct = Math.round((i.count / maxCount) * 100);
          return (
            <li key={i.status}>
              <div className="flex items-center justify-between text-sm mb-1">
                <span className="capitalize">{i.status.replace(/_/g, ' ')}</span>
                <span className="font-mono tabular-nums">{i.count}</span>
              </div>
              <div
                role="progressbar"
                aria-valuenow={pct}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`${i.status}: ${i.count} work orders`}
                className="h-2 bg-subtle rounded-full overflow-hidden"
              >
                <div
                  className={woBarClass(i.status)}
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

function woBarClass(status: string): string {
  if (status === 'in_progress') return 'h-full bg-info rounded-full';
  if (status === 'completed') return 'h-full bg-success rounded-full';
  if (status === 'paused') return 'h-full bg-warning rounded-full';
  if (status === 'planned' || status === 'confirmed') return 'h-full bg-accent rounded-full';
  return 'h-full bg-subtle rounded-full';
}

/* ───────────────────────── Page component ───────────────────────── */

export default function PpcDashboard() {
  const { can } = usePermission();

  const q = useQuery({
    queryKey: ['dashboard', 'ppc'],
    queryFn: () => dashboardsApi.ppc(),
    refetchInterval: 60_000,
  });

  const bottlenecks = useQuery({
    queryKey: ['chain-bottlenecks', 'ppc_head'],
    queryFn: (): Promise<ChainBottlenecks> => chainApi.bottlenecks('ppc_head'),
    enabled: can('dashboard.view_bottlenecks'),
    refetchInterval: 60_000,
    staleTime: 60_000,
  });

  /* ─── LOADING ─── */
  if (q.isLoading && !q.data) {
    return (
      <div>
        <PageHeader title="PPC Dashboard" subtitle="Production Planning & Control" />
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
        <PageHeader title="PPC Dashboard" subtitle="Production Planning & Control" />
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

  const { kpis, panels } = q.data as unknown as PpcDashboardData;

  return (
    <div>
      <PageHeader title="PPC Dashboard" subtitle="Live · refreshes every 60s" />

      <div className="px-5 py-4 space-y-4">
        {/* ── Row 1: KPIs ── */}
        <KpiRow kpis={kpis} />

        {/* ── Row 2: Chain stages + Alerts ── */}
        <div className="grid grid-cols-2 gap-4">
          {Array.isArray(panels?.chain_stages) && panels.chain_stages.length > 0 ? (
            <ChainStagePanel stages={panels.chain_stages} />
          ) : (
            <Panel title="Active orders by chain stage">
              <EmptyState icon="inbox" title="Order pipeline empty" description="No active orders in any stage." />
            </Panel>
          )}

          {Array.isArray(panels?.alerts) && panels.alerts.length > 0 ? (
            <AlertsPanel alerts={panels.alerts} />
          ) : (
            <Panel title="Alerts">
              <EmptyState icon="bell-off" title="All clear" description="No active alerts." />
            </Panel>
          )}
        </div>

        {/* ── Row 3: Machine utilisation (full width) ── */}
        <MachineUtilPanel machines={panels?.machine_util ?? []} />

        {/* ── Row 4: D3 — MRP meta + Production Gantt (2-col grid) ── */}
        <div className="grid grid-cols-2 gap-4">
          <MrpMetaPanel
            lastRun={panels?.mrp_last_run ?? '—'}
            unplanned={panels?.unplanned_wos ?? 0}
          />
          <ProductionGanttPanel rows={panels?.production_gantt ?? []} />
        </div>

        {/* ── Row 5: D3 — MRP Shortages + Machine Availability (2-col grid) ── */}
        <div className="grid grid-cols-2 gap-4">
          <MrpShortagesPanel shortages={panels?.mrp_shortages ?? []} />
          <MachineAvailabilityGrid rows={panels?.machine_availability ?? []} />
        </div>

        {/* ── Row 6: D3 — WO Status Breakdown (full width) ── */}
        <WoStatusBreakdownPanel items={panels?.wo_status_breakdown ?? []} />

        {/* ── Row 7: Chain bottleneck widget (gated by permission, Series C — Task C5) ── */}
        {can('dashboard.view_bottlenecks') && renderBottleneckWidget(bottlenecks)}
      </div>
    </div>
  );
}

/* ───────────────────────── Bottleneck widget (co-located) ───────────────────────── */

function renderBottleneckWidget(
  bq: ReturnType<typeof useQuery<ChainBottlenecks>>,
) {
  const groups = (bq.data?.groups ?? []).filter((g): g is ChainBottleneckGroup => g.count > 0);

  /* loading */
  if (bq.isLoading && !bq.data) {
    return (
      <Panel title="Chain bottlenecks" meta="Refreshes every 60s">
        <div className="space-y-2">
          {[0, 1, 2].map((i) => <SkeletonBlock key={i} className="h-8 w-full" />)}
        </div>
      </Panel>
    );
  }

  /* error */
  if (bq.isError) {
    return (
      <Panel title="Chain bottlenecks">
        <EmptyState
          icon="alert-circle"
          title="Failed to load bottlenecks"
          action={<Button variant="secondary" onClick={() => bq.refetch()}>Retry</Button>}
        />
      </Panel>
    );
  }

  /* empty — nothing stuck */
  if (groups.length === 0) return null;

  /* data */
  const total = bq.data?.total ?? 0;
  return (
    <Panel title="Chain bottlenecks" meta={`${total} stuck`} bodyClassName="p-0">
      <ul>
        {groups.map((g) => (
          <li
            key={g.key}
            className="flex items-center justify-between px-4 py-2.5 border-b border-subtle last:border-b-0"
          >
            <div className="min-w-0 flex-1">
              <div className="text-sm text-primary truncate">{g.label}</div>
              <div className="text-xs text-muted truncate">
                {g.rows.slice(0, 3).map((r: ChainBottleneckRow) => r.doc_number).join(', ')}
                {g.rows.length > 3 ? ` +${g.rows.length - 3} more` : ''}
              </div>
            </div>
            <div className="flex items-center gap-2 ml-3">
              <Chip variant={g.count >= 5 ? 'danger' : 'warning'}>
                <span className="font-mono tabular-nums">{g.count}</span>
              </Chip>
              <Link
                to={bottleneckLink(g.rows[0])}
                className="text-xs text-accent hover:underline"
              >
                View
              </Link>
            </div>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function bottleneckLink(row: ChainBottleneckRow | undefined): string {
  if (!row) return '#';
  switch (row.entity_type) {
    case 'sales_order':      return `/crm/sales-orders/${row.entity_id}`;
    case 'work_order':       return `/production/work-orders/${row.entity_id}`;
    case 'inspection':       return `/quality/inspections/${row.entity_id}`;
    case 'delivery':         return `/supply-chain/deliveries/${row.entity_id}`;
    case 'invoice':          return `/accounting/invoices/${row.entity_id}`;
    case 'purchase_request': return `/purchasing/purchase-requests/${row.entity_id}`;
    case 'bill':             return `/accounting/bills/${row.entity_id}`;
    default:                 return '#';
  }
}
