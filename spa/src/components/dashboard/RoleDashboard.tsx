/** Sprint 8 — Tasks 72 + 73. Generic role dashboard renderer. */
import { useQuery } from '@tanstack/react-query';
import { dashboardsApi, type DashboardEnvelope } from '@/api/dashboards';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/layout/PageHeader';

type Role = 'plantManager' | 'hr' | 'ppc' | 'accounting';

const TITLES: Record<Role, string> = {
  plantManager: 'Plant Manager Dashboard',
  hr: 'HR Dashboard',
  ppc: 'PPC Dashboard',
  accounting: 'Accounting Dashboard',
};

export function RoleDashboard({ role }: { role: Role }) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['dashboard', role],
    queryFn: () => dashboardsApi[role](),
    refetchInterval: 60_000,
  });

  if (isLoading) {
    return (
      <div className="px-5 py-6 space-y-4">
        <div className="grid grid-cols-4 gap-2">
          {[1, 2, 3, 4].map((i) => <SkeletonBlock key={i} className="h-16 rounded-md" />)}
        </div>
        <SkeletonBlock className="h-64 rounded-md" />
      </div>
    );
  }
  if (isError || !data) {
    return (
      <div className="px-5 py-6">
        <EmptyState icon="alert-circle" title="Failed to load dashboard"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      </div>
    );
  }

  return (
    <div>
      <PageHeader title={TITLES[role]} subtitle="Live · refreshes every 60s" />
      <div className="px-5 py-4 space-y-4">
        <section className="grid grid-cols-4 gap-2">
          {data.kpis.map((k) => (
            <StatCard
              key={k.label}
              label={k.label}
              value={k.unit === 'PHP' ? `₱ ${k.value}` : k.value}
              helper={k.unit !== 'PHP' && k.unit !== 'count' ? k.unit : undefined}
            />
          ))}
        </section>
        <RolePanels envelope={data} />
      </div>
    </div>
  );
}

function RolePanels({ envelope }: { envelope: DashboardEnvelope }) {
  const p = envelope.panels;

  return (
    <div className="grid grid-cols-2 gap-4">
      {Array.isArray(p.chain_stages) && (
        <Panel title="Active orders by chain stage">
          <ul className="space-y-2">
            {p.chain_stages.map((s: any) => (
              <li key={s.label}>
                <div className="flex items-center justify-between text-sm mb-1">
                  <span>{s.label}</span>
                  <span className="font-mono tabular-nums">{s.count}</span>
                </div>
                <div className="h-1 bg-subtle rounded-full overflow-hidden">
                  <div className={stageFillClass(s.color)}
                    style={{ width: `${s.percent}%` }} />
                </div>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      {Array.isArray(p.alerts) && (
        <Panel title="Alerts" meta={p.alerts.reduce((a: number, x: any) => a + (x.count ?? 0), 0).toString()}>
          <ul className="divide-y divide-subtle">
            {p.alerts.map((a: any) => (
              <li key={a.kind} className="flex items-center justify-between py-2 text-sm">
                <span className="flex items-center gap-2">
                  <span className={alertDotClass(a.severity)} />
                  {a.label}
                </span>
                <span className="font-mono tabular-nums">{a.count}</span>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      {Array.isArray(p.machine_util) && (
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
              {p.machine_util.map((m: any) => (
                <tr key={m.id} className="border-b border-subtle h-7">
                  <td className="font-mono">{m.code}</td>
                  <td className="text-muted">{m.name}</td>
                  <td><Chip variant={m.status === 'running' ? 'info' : m.status === 'breakdown' ? 'danger' : m.status === 'maintenance' ? 'warning' : 'neutral'}>{m.status}</Chip></td>
                  <td className="text-right font-mono">{m.has_active_wo ? '✓' : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {Array.isArray(p.defect_pareto) && p.defect_pareto.length > 0 && (
        <Panel title="Defect Pareto · top 8">
          <ul className="space-y-1.5">
            {p.defect_pareto.map((d: any) => {
              const max = Math.max(1, ...p.defect_pareto.map((x: any) => x.count));
              return (
                <li key={d.code}>
                  <div className="flex justify-between text-sm">
                    <span><span className="font-mono">{d.code}</span> {d.name}</span>
                    <span className="font-mono tabular-nums">{d.count}</span>
                  </div>
                  <div className="h-1 bg-subtle rounded-full overflow-hidden">
                    <div className="h-full bg-accent" style={{ width: `${(d.count / max) * 100}%` }} />
                  </div>
                </li>
              );
            })}
          </ul>
        </Panel>
      )}

      {Array.isArray(p.by_department) && (
        <Panel title="Headcount by department">
          <ul className="divide-y divide-subtle">
            {p.by_department.map((row: any) => (
              <li key={row.label} className="flex items-center justify-between py-1.5 text-sm">
                <span>{row.label}</span>
                <span className="font-mono tabular-nums">{row.count}</span>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      {Array.isArray(p.recent_jes) && (
        <Panel title="Recent journal entries">
          <ul className="divide-y divide-subtle">
            {p.recent_jes.map((je: any) => (
              <li key={je.id} className="flex items-center justify-between py-1.5 text-sm">
                <span><span className="font-mono">{je.entry_number}</span> · <span className="text-muted">{je.date}</span></span>
                <span className="flex items-center gap-2">
                  <Chip variant={je.status === 'posted' ? 'success' : 'neutral'}>{je.status}</Chip>
                  <span className="font-mono tabular-nums">₱{je.total_debit}</span>
                </span>
              </li>
            ))}
          </ul>
        </Panel>
      )}
    </div>
  );
}

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
