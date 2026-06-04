import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { SparkLine, BarComparison } from '@/components/charts';
import { dashboardsApi, type AdminDashboardData } from '@/api/dashboards';

type KpiUnit = 'users' | 'items' | 'alerts' | 'attempts';

const kpiAccent: Record<KpiUnit, string> = {
  users:    'text-accent',
  items:    'text-warning',
  alerts:   'text-danger',
  attempts: 'text-danger',
};

export default function AdminDashboard() {
  const q = useQuery({
    queryKey: ['dashboard', 'admin'],
    queryFn: () => dashboardsApi.admin(),
    refetchInterval: 60_000,
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="System Administrator"
        subtitle="Cross-module health, user activity, and pending work."
      />
      <div className="px-5 py-4 space-y-4">
        {q.isLoading && !q.data && <SkeletonDetail />}

        {q.isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load admin dashboard"
            description="Could not reach the admin dashboard endpoint."
            action={<Button variant="secondary" onClick={() => q.refetch()}>Retry</Button>}
          />
        )}

        {q.data && (
          <>
            {/* Row 1 — 4 KPI stat cards */}
            <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">
              {q.data.kpis.map((kpi) => (
                <StatCard
                  key={kpi.label}
                  label={kpi.label}
                  value={
                    <span className={kpiAccent[kpi.unit as KpiUnit] ?? 'text-primary'}>
                      {kpi.value}
                    </span>
                  }
                  helper={kpi.unit}
                />
              ))}
            </div>

            {/* Row 2 — Three-chain stage bar */}
            <Panel
              title="Three-Chain Overview"
              actions={<Link className="text-xs text-link hover:underline" to="/approvals">Approvals board →</Link>}
            >
              <ChainStageBar stages={q.data.panels.chain_stages} />
            </Panel>

            {/* Row 3 — Module activity + User activity */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <ModuleActivityPanel modules={q.data.panels.module_activity} />
              <UserActivityPanel activity={q.data.panels.user_activity} />
            </div>

            {/* Row 4 — Pending approvals + Recent audit */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <PendingApprovalsPanel approvals={q.data.panels.pending_approvals} />
              <RecentAuditPanel events={q.data.panels.recent_audit} />
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/* ── Sub-panels ─────────────────────────────────────────────────────────── */

function ChainStageBar({ stages }: { stages: AdminDashboardData['panels']['chain_stages'] }) {
  if (stages.length === 0) {
    return <p className="text-sm text-muted">No active records in the pipeline.</p>;
  }
  const colorMap: Record<string, string> = {
    success: 'bg-success',
    info:    'bg-info',
    warning: 'bg-warning',
    danger:  'bg-danger',
  };
  return (
    <div className="space-y-2">
      {stages.map((s) => (
        <div key={s.key} className="flex items-center gap-3">
          <span className="w-40 shrink-0 text-sm text-secondary">{s.label}</span>
          <div className="flex-1 h-2.5 bg-elevated rounded-full overflow-hidden">
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

function ModuleActivityPanel({ modules }: { modules: AdminDashboardData['panels']['module_activity'] }) {
  return (
    <Panel title="Module Activity" actions={<Link className="text-xs text-link hover:underline" to="/admin/audit-logs">Audit logs →</Link>}>
      <div className="grid grid-cols-2 gap-2">
        {modules.map((mod) => (
          <Link
            key={mod.key}
            to={mod.href}
            className="p-3 rounded-md border border-default bg-surface hover:bg-elevated transition-colors duration-fast"
          >
            <div className="text-xs font-semibold uppercase tracking-wider text-secondary mb-2">
              {mod.label}
            </div>
            <div className="space-y-1">
              {mod.stats.map((stat) => (
                <div key={stat.label} className="flex justify-between items-center">
                  <span className="text-2xs text-muted truncate mr-2">{stat.label}</span>
                  <span className="text-sm font-mono tabular-nums font-medium shrink-0">{stat.value}</span>
                </div>
              ))}
            </div>
          </Link>
        ))}
      </div>
    </Panel>
  );
}

function UserActivityPanel({ activity }: { activity: AdminDashboardData['panels']['user_activity'] }) {
  const statusVariant = (status: string): 'success' | 'danger' | 'neutral' => {
    if (status === 'success') return 'success';
    if (status.startsWith('failed')) return 'danger';
    return 'neutral';
  };

  return (
    <Panel
      title="User Activity"
      meta={`${activity.active_today} logins today`}
      actions={<Link className="text-xs text-link hover:underline" to="/admin/users">All users →</Link>}
    >
      {/* Sparkline header */}
      <div className="flex items-center justify-between mb-3 pb-3 border-b border-subtle">
        <div>
          <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">7-Day Login Trend</div>
          <div className="text-xl font-mono tabular-nums font-medium">{activity.total_users} users</div>
        </div>
        <SparkLine
          data={activity.login_trend_7d}
          color="var(--color-success)"
          height={36}
          width={120}
        />
      </div>

      {/* Recent logins */}
      {activity.recent_logins.length === 0 ? (
        <p className="text-sm text-muted">No recent logins.</p>
      ) : (
        <div className="space-y-0">
          {activity.recent_logins.map((login, i) => (
            <div key={i} className="flex items-center justify-between py-1.5 border-b border-subtle last:border-0">
              <div className="flex items-center gap-2 min-w-0">
                <Chip variant={statusVariant(login.status)} className="shrink-0">
                  {login.status === 'success' ? 'ok' : 'fail'}
                </Chip>
                <span className="text-sm truncate">{login.name}</span>
              </div>
              <span className="text-2xs text-muted font-mono shrink-0 ml-2">{login.ip}</span>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}

function PendingApprovalsPanel({ approvals }: { approvals: AdminDashboardData['panels']['pending_approvals'] }) {
  const total = approvals.reduce((sum, a) => sum + a.count, 0);
  return (
    <Panel
      title="Pending Approvals"
      meta={total > 0 ? String(total) : undefined}
      actions={<Link className="text-xs text-link hover:underline" to="/approvals">Open board →</Link>}
    >
      {approvals.length === 0 ? (
        <p className="text-sm text-muted">No pending approvals.</p>
      ) : (
        <>
          <BarComparison
            data={approvals.map((a) => ({ label: a.label, count: a.count }))}
            bars={[{ dataKey: 'count', color: 'var(--color-warning)', label: 'Pending' }]}
            xKey="label"
            height={160}
          />
          <ul className="mt-2 space-y-1">
            {approvals.map((a) => (
              <li key={a.type} className="flex items-center justify-between text-sm">
                <Link to={a.href} className="text-link hover:underline truncate mr-2">{a.label}</Link>
                <span className="font-mono tabular-nums text-warning font-medium shrink-0">{a.count}</span>
              </li>
            ))}
          </ul>
        </>
      )}
    </Panel>
  );
}

function RecentAuditPanel({ events }: { events: AdminDashboardData['panels']['recent_audit'] }) {
  const actionColor = (action: string): string => {
    if (action === 'deleted') return 'text-danger';
    if (action === 'created') return 'text-success';
    if (action === 'updated') return 'text-info';
    return 'text-muted';
  };

  return (
    <Panel
      title="Recent Audit Events"
      actions={<Link className="text-xs text-link hover:underline" to="/admin/audit-logs">Full log →</Link>}
    >
      {events.length === 0 ? (
        <p className="text-sm text-muted">No recent audit events.</p>
      ) : (
        <div className="space-y-0">
          {events.map((e, i) => (
            <div key={i} className="flex items-start gap-2 py-1.5 border-b border-subtle last:border-0 text-sm">
              <span className={`font-mono text-2xs shrink-0 mt-0.5 uppercase ${actionColor(e.action)}`}>
                {e.action}
              </span>
              <div className="min-w-0 flex-1">
                <span className="font-medium truncate block">{e.entity}</span>
                <span className="text-2xs text-muted">{e.user} · {e.ip}</span>
              </div>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}
