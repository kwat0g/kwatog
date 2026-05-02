/**
 * Sprint 6 — Task 58. Production dashboard.
 * Subscribes to production.dashboard for live invalidation; falls back to
 * 60s polling if Reverb is unavailable.
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Activity } from 'lucide-react';
import { Link } from 'react-router-dom';
import { productionDashboardApi } from '@/api/production/dashboard';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { OeeGauge } from '@/components/production/OeeGauge';
import { BreakdownAlertCard } from '@/components/production/BreakdownAlertCard';
import { useEcho } from '@/hooks/useEcho';

export default function ProductionDashboardPage() {
  const qc = useQueryClient();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['production', 'dashboard'],
    queryFn: () => productionDashboardApi.payload(),
    refetchInterval: 60_000,
    placeholderData: (prev) => prev,
  });

  // Live invalidate on output recorded.
  useEcho('production.dashboard', '.output.recorded', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });
  // Live invalidate on machine status change.
  useEcho('production.dashboard', '.machine.status_changed', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });

  if (isLoading && !data) {
    return (
      <div>
        <PageHeader title="Production" />
        <div className="px-5 py-4 space-y-4">
          <SkeletonTable columns={4} rows={1} />
          <SkeletonTable columns={2} rows={6} />
        </div>
      </div>
    );
  }
  if (isError || !data) {
    return (
      <div>
        <PageHeader title="Production" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load production dashboard"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  const k = data.kpis;
  return (
    <div>
      <PageHeader
        title="Production"
        subtitle={`Updated ${data.generated_at?.slice(11, 16)} UTC · cached 30s`}
      />
      <div className="px-5 py-4 space-y-4">
        {/* KPI row */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <StatCard
            label="Today output"
            value={`${k.today_output_good.toLocaleString()} / ${k.today_output_total.toLocaleString()}`}
            helper={k.today_output_reject > 0 ? `${k.today_output_reject.toLocaleString()} rejects` : undefined}
          />
          <StatCard
            label="Active work orders"
            value={k.active_work_orders.toLocaleString()}
          />
          <StatCard
            label="Machine utilization"
            value={`${k.machines_running} / ${k.machines_total}`}
            helper={`${k.machines_idle} idle · ${k.machines_breakdown} breakdown`}
          />
          <StatCard
            label="Avg OEE today"
            value={`${(k.avg_oee_today * 100).toFixed(1)}%`}
          />
        </div>

        {/* Row 2: chain breakdown + alerts */}
        <div className="grid gap-4 lg:grid-cols-3">
          <Panel title="Active orders by chain stage" className="lg:col-span-2">
            {data.chain_stage_breakdown.length === 0 ? (
              <div className="text-sm text-muted">No active sales orders.</div>
            ) : (
              <div className="space-y-3">
                {data.chain_stage_breakdown.map((s) => (
                  <div key={s.label}>
                    <div className="flex justify-between text-xs mb-1">
                      <span className="text-primary">{s.label}</span>
                      <span className="font-mono tabular-nums text-muted">{s.count} <span className="text-2xs">({s.percent.toFixed(1)}%)</span></span>
                    </div>
                    <div className="h-1 bg-elevated rounded-full overflow-hidden">
                      <div
                        className={`h-1 rounded-full ${
                          s.color === 'success' ? 'bg-success' :
                          s.color === 'warning' ? 'bg-warning' :
                          s.color === 'danger'  ? 'bg-danger' :
                          s.color === 'info'    ? 'bg-accent' : 'bg-elevated'
                        }`}
                        style={{ width: `${Math.min(100, s.percent)}%` }}
                        aria-hidden
                      />
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Panel>

          <Panel title="Alerts" meta={`${data.alerts.length} active`}>
            {data.alerts.length === 0
              ? <div className="text-sm text-muted">All clear.</div>
              : <div className="space-y-1">
                  {data.alerts.map((a, i) => (
                    <BreakdownAlertCard key={`${a.type}-${i}`} type={a.type} severity={a.severity} message={a.message} link={a.link} />
                  ))}
                </div>}
          </Panel>
        </div>

        {/* Row 3: machine util + defect Pareto */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel title="Machine utilization (today)" noPadding>
            <table className="w-full text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Machine</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Status</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2 w-1/3">OEE</th>
                </tr>
              </thead>
              <tbody>
                {data.machine_utilization.map((m) => (
                  <tr key={m.machine_id} className="border-t border-subtle">
                    <td className="px-2.5 py-2">
                      <Link to={`/mrp/machines/${m.machine_id}`} className="font-mono text-accent hover:underline">{m.machine_code}</Link>
                      <div className="text-2xs text-muted">{m.name}</div>
                    </td>
                    <td className="px-2.5 py-2">
                      <Chip variant={m.status === 'running' ? 'success' : m.status === 'breakdown' ? 'danger' : m.status === 'idle' ? 'neutral' : 'info'}>{m.status}</Chip>
                    </td>
                    <td className="px-2.5 py-2"><OeeGauge result={m} compact /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>

          <Panel title="Defect Pareto (7d)" meta={`top ${data.defect_pareto.length}`}>
            {data.defect_pareto.length === 0 ? (
              <div className="text-sm text-muted">No defects recorded in the last 7 days.</div>
            ) : (
              <div className="space-y-2">
                {data.defect_pareto.map((d) => (
                  <div key={d.defect_code}>
                    <div className="flex justify-between text-xs mb-1">
                      <span><span className="font-mono">{d.defect_code}</span> · <span className="text-muted">{d.defect_name}</span></span>
                      <span className="font-mono tabular-nums">{d.count.toLocaleString()} <span className="text-2xs text-muted">({d.percent.toFixed(1)}%)</span></span>
                    </div>
                    <div className="h-1.5 bg-elevated rounded-full overflow-hidden">
                      <div className="h-1.5 bg-accent rounded-full" style={{ width: `${Math.min(100, d.percent)}%` }} aria-hidden />
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Panel>
        </div>

        <div className="text-2xs text-muted flex items-center gap-1">
          <Activity size={10} />
          Live updates via WebSocket on production.dashboard channel.
        </div>
      </div>
    </div>
  );
}
