import { cn } from '@/lib/cn';
/**
 * Sprint 6 — Task 58. Production dashboard.
 * Subscribes to production.dashboard for live invalidation; falls back to
 * 60s polling if Reverb is unavailable.
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Activity } from 'lucide-react';
import { useNavigate} from 'react-router-dom';
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
import { ShopFloorMap } from '@/components/production/ShopFloorMap';
import { useEcho } from '@/hooks/useEcho';
import { formatInt } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function ProductionDashboardPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['production', 'dashboard'],
    queryFn: () => productionDashboardApi.payload(),
    refetchInterval: 60_000,
    placeholderData: (prev) => prev });

  // Live invalidate on output recorded.
  useEcho('production.dashboard', '.output.recorded', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });
  // Live invalidate on machine status change.
  useEcho('production.dashboard', '.machine.status_changed', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });
  // Sprint 6 audit §1.7: also react to chain pulses, plan generation,
  // breakdown alerts, and mold shot-limit alerts so the dashboard stays
  // current without manual refresh.
  useEcho('production.dashboard', '.sales_order.confirmed', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });
  useEcho('production.dashboard', '.mrp.plan_generated', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });
  useEcho('production.dashboard', '.machine.breakdown_detected', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });
  useEcho('production.dashboard', '.mold.shot_limit_nearing', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });
  useEcho('production.dashboard', '.mold.shot_limit_reached', () => {
    qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
  });
  useEcho('production.dashboard', '.work_order.status_changed', () => {
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
            value={`${formatInt(k.today_output_good)} / ${formatInt(k.today_output_total)}`}
            helper={k.today_output_reject > 0 ? `${formatInt(k.today_output_reject)} rejects` : undefined}
          />
          <StatCard
            label="Active work orders"
            value={formatInt(k.active_work_orders)}
          />
          <StatCard
            label="Machine utilization"
            value={`${k.machines_running} / ${k.machines_total}`}
            helper={`${k.machines_idle} idle · ${k.machines_breakdown} breakdown`}
          />
          <StatCard
            label="Avg OEE today"
            value={k.avg_oee_today == null ? '—' : `${(k.avg_oee_today * 100).toFixed(1)}%`}
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
                    <BreakdownAlertCard key={`${a.type}-${i}`} type={a.type} typeLabel={a.type_label} severity={a.severity} message={a.message} link={a.link} />
                  ))}
                </div>}
          </Panel>
        </div>

        {/* Row 3: Interactive Shop Floor Map */}
        <ShopFloorMap machines={data.machine_utilization} />

        {/* Row 4: machine util + defect Pareto */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel title="Machine utilization (today)" noPadding>
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Machine</Th>
                  <Th>Status</Th>
                  <Th className="w-1/3">OEE</Th>
                </tr>
              </thead>
              <tbody>
                {data.machine_utilization.map((m) => (
                  <tr key={m.machine_id} className={cn(trCls, "cursor-pointer")} onClick={() => navigate(`/mrp/machines/${m.machine_id}`)}>
                    <Td>
                      {m.machine_code}
                      <div className="text-2xs text-muted">{m.name}</div>
                    </Td>
                    <Td>
                      <Chip variant={m.status === 'running' ? 'success' : m.status === 'breakdown' ? 'danger' : m.status === 'idle' ? 'neutral' : 'info'}>{m.status_label ?? m.status}</Chip>
                    </Td>
                    <Td><OeeGauge result={m} displayPolicy={data.display_policy} compact /></Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>

          <Panel title={`Defect Pareto (${data.defect_history_days}d)`} meta={`top ${data.defect_pareto.length}`}>
            {data.defect_pareto.length === 0 ? (
              <div className="text-sm text-muted">No defects recorded in the selected history window.</div>
            ) : (
              <div className="space-y-2">
                {data.defect_pareto.map((d) => (
                  <div key={d.defect_code}>
                    <div className="flex justify-between text-xs mb-1">
                      <span><span className="font-mono">{d.defect_code}</span> · <span className="text-muted">{d.defect_name}</span></span>
                      <span className="font-mono tabular-nums">{formatInt(d.count)} <span className="text-2xs text-muted">({d.percent.toFixed(1)}%)</span></span>
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
