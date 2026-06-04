import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { SparkLine } from '@/components/charts/SparkLine';
import {
  dashboardsApi,
  type AdminDashboardData,
  type AdminSession,
  type AdminLockedAccount,
  type AdminFailedLogin,
  type AdminFailedJob,
  type AdminAlert,
  type AdminAuditEvent,
} from '@/api/dashboards';

type KpiUnit = 'sessions' | 'accounts' | 'attempts' | 'jobs';

const kpiAccent: Record<KpiUnit, string> = {
  sessions: 'text-accent',
  accounts: 'text-danger',
  attempts: 'text-warning',
  jobs:     'text-danger',
};

/**
 * System Administrator dashboard — system health and security monitoring.
 *
 * Row 1 — 4 KPI stat cards: active sessions, locked accounts, failed logins 24h, failed jobs
 * Row 2 — Active sessions table  +  Account security summary
 * Row 3 — Auth events: 24h breakdown + hourly sparkline + recent failures
 * Row 4 — Queue health  +  Open system alerts
 * Row 5 — Recent audit trail (3-column grid)
 */
export default function AdminDashboard() {
  const q = useQuery({
    queryKey: ['dashboard', 'admin'],
    queryFn: () => dashboardsApi.admin(),
    refetchInterval: 30_000,
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="System Administrator"
        subtitle="Platform health, security events, and account monitoring."
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
            {/* Row 1 — 4 system KPI stat cards */}
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

            {/* Row 2 — Active sessions + Account security */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <ActiveSessionsPanel data={q.data.panels.active_sessions} />
              <AccountSecurityPanel data={q.data.panels.account_security} />
            </div>

            {/* Row 3 — Auth events */}
            <AuthEventsPanel data={q.data.panels.auth_events} />

            {/* Row 4 — Queue health + Open alerts */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <QueueHealthPanel data={q.data.panels.queue_health} />
              <OpenAlertsPanel data={q.data.panels.open_alerts} />
            </div>

            {/* Row 5 — Audit trail */}
            <AuditTrailPanel events={q.data.panels.recent_audit} />
          </>
        )}
      </div>
    </div>
  );
}

/* ── Active Sessions ─────────────────────────────────────────────────────── */

function ActiveSessionsPanel({
  data,
}: {
  data: AdminDashboardData['panels']['active_sessions'];
}) {
  return (
    <Panel
      title="Active Sessions"
      meta={`${data.total} session${data.total !== 1 ? 's' : ''} · ${data.unique_users} user${data.unique_users !== 1 ? 's' : ''}`}
      actions={<Link className="text-xs text-link hover:underline" to="/admin/users">Manage users →</Link>}
    >
      {data.sessions.length === 0 ? (
        <p className="text-sm text-muted">No active sessions in the last 30 minutes.</p>
      ) : (
        <div className="space-y-0">
          {data.sessions.map((s: AdminSession, i: number) => (
            <div
              key={i}
              className="flex items-center gap-2 py-1.5 border-b border-subtle last:border-0 text-sm"
            >
              <div className="min-w-0 flex-1">
                <span className="font-medium truncate block">{s.user}</span>
                <span className="text-2xs text-muted">{s.role}</span>
              </div>
              <div className="text-right shrink-0">
                <span className="text-xs font-mono tabular-nums text-secondary block">{s.ip}</span>
                <span className="text-2xs text-muted">{s.device}</span>
              </div>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}

/* ── Account Security ────────────────────────────────────────────────────── */

function AccountSecurityPanel({
  data,
}: {
  data: AdminDashboardData['panels']['account_security'];
}) {
  const stats = [
    { label: 'Total accounts',        value: data.total,                color: '' },
    { label: 'Active',                value: data.active,               color: 'text-success' },
    { label: 'Inactive / disabled',   value: data.inactive,             color: data.inactive > 0 ? 'text-warning' : '' },
    { label: 'Currently locked',      value: data.locked,               color: data.locked > 0 ? 'text-danger' : '' },
    { label: 'At risk (≥3 failures)', value: data.at_risk,              color: data.at_risk > 0 ? 'text-warning' : '' },
    { label: 'Must change password',  value: data.must_change_password, color: data.must_change_password > 0 ? 'text-warning' : '' },
  ];

  return (
    <Panel
      title="Account Security"
      actions={<Link className="text-xs text-link hover:underline" to="/admin/users">All accounts →</Link>}
    >
      <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 mb-3">
        {stats.map((s) => (
          <div key={s.label} className="flex justify-between items-baseline">
            <span className="text-2xs text-muted truncate mr-2">{s.label}</span>
            <span className={`text-sm font-mono tabular-nums font-medium shrink-0 ${s.color}`}>
              {s.value}
            </span>
          </div>
        ))}
      </div>

      {data.locked_accounts.length > 0 && (
        <>
          <div className="text-2xs uppercase tracking-wider text-muted mb-1 pt-2 border-t border-subtle">
            Locked now
          </div>
          <div className="space-y-0">
            {data.locked_accounts.map((acc: AdminLockedAccount, i: number) => (
              <div
                key={i}
                className="flex items-center justify-between py-1.5 border-b border-subtle last:border-0 text-sm"
              >
                <div className="min-w-0 flex-1">
                  <span className="font-medium truncate block">{acc.name}</span>
                  <span className="text-2xs text-muted truncate block">{acc.email}</span>
                </div>
                <span className="text-xs font-mono tabular-nums text-danger shrink-0 ml-2">
                  {acc.attempts} attempts
                </span>
              </div>
            ))}
          </div>
        </>
      )}
    </Panel>
  );
}

/* ── Auth Events ─────────────────────────────────────────────────────────── */

const AUTH_STATUS_LABELS: Record<string, string> = {
  success:                 'Success',
  failed_credentials:      'Wrong credentials',
  failed_locked:           'Account locked',
  failed_inactive:         'Account inactive',
  failed_password_expired: 'Password expired',
};

const AUTH_STATUS_VARIANT: Record<string, 'success' | 'danger' | 'warning' | 'neutral'> = {
  success:                 'success',
  failed_credentials:      'danger',
  failed_locked:           'danger',
  failed_inactive:         'warning',
  failed_password_expired: 'warning',
};

function AuthEventsPanel({
  data,
}: {
  data: AdminDashboardData['panels']['auth_events'];
}) {
  const breakdown = data.breakdown_24h;
  const totalAttempts = Object.values(breakdown).reduce((s, n) => s + n, 0);

  return (
    <Panel
      title="Authentication Events · Last 24h"
      meta={`${totalAttempts} total attempts`}
      actions={<Link className="text-xs text-link hover:underline" to="/admin/audit-logs">View audit log →</Link>}
    >
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Left: status breakdown + sparkline */}
        <div>
          <div className="text-2xs uppercase tracking-wider text-muted mb-2">By status</div>
          <div className="space-y-1.5">
            {Object.entries(breakdown).map(([status, count]) => (
              <div key={status} className="flex items-center justify-between text-sm">
                <span className="text-muted truncate mr-2">
                  {AUTH_STATUS_LABELS[status] ?? status}
                </span>
                <span className={`font-mono tabular-nums font-medium shrink-0 ${
                  status === 'success' ? 'text-success' : 'text-danger'
                }`}>
                  {count}
                </span>
              </div>
            ))}
            {Object.keys(breakdown).length === 0 && (
              <p className="text-sm text-muted">No login attempts in the last 24h.</p>
            )}
          </div>

          {data.success_trend_24h.length > 0 && (
            <div className="mt-4">
              <div className="text-2xs uppercase tracking-wider text-muted mb-1">Successful / hour</div>
              <SparkLine
                data={data.success_trend_24h}
                color="var(--color-success)"
                height={32}
                width={160}
              />
            </div>
          )}
        </div>

        {/* Right: recent failures */}
        <div className="lg:col-span-2">
          <div className="text-2xs uppercase tracking-wider text-muted mb-2">Recent failures</div>
          {data.recent_failures.length === 0 ? (
            <p className="text-sm text-muted">No failed logins in the last 24h.</p>
          ) : (
            <div className="space-y-0">
              {data.recent_failures.map((f: AdminFailedLogin, i: number) => (
                <div
                  key={i}
                  className="flex items-center gap-2 py-1.5 border-b border-subtle last:border-0 text-sm"
                >
                  <Chip variant={AUTH_STATUS_VARIANT[f.status] ?? 'neutral'} className="shrink-0">
                    {AUTH_STATUS_LABELS[f.status] ?? f.status}
                  </Chip>
                  <span className="truncate flex-1 text-xs">{f.email}</span>
                  <span className="text-2xs font-mono tabular-nums text-muted shrink-0">{f.ip}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </Panel>
  );
}

/* ── Queue Health ────────────────────────────────────────────────────────── */

function QueueHealthPanel({
  data,
}: {
  data: AdminDashboardData['panels']['queue_health'];
}) {
  return (
    <Panel
      title="Queue Health"
      meta={
        data.healthy ? (
          <span className="text-success text-xs font-medium">● Healthy</span>
        ) : (
          <span className="text-danger text-xs font-medium">● Issues detected</span>
        )
      }
    >
      <div className="grid grid-cols-2 gap-3 mb-3">
        <div className="p-3 rounded-md bg-elevated">
          <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">Pending</div>
          <div className="text-2xl font-mono tabular-nums font-medium">{data.pending_jobs}</div>
        </div>
        <div className="p-3 rounded-md bg-elevated">
          <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">Failed</div>
          <div className={`text-2xl font-mono tabular-nums font-medium ${data.failed_jobs > 0 ? 'text-danger' : ''}`}>
            {data.failed_jobs}
          </div>
        </div>
      </div>

      {data.recent_failed.length > 0 && (
        <>
          <div className="text-2xs uppercase tracking-wider text-muted mb-1">Recent failures</div>
          <div className="space-y-0">
            {data.recent_failed.map((job: AdminFailedJob, i: number) => (
              <div key={i} className="py-1.5 border-b border-subtle last:border-0">
                <div className="flex items-center justify-between text-sm">
                  <span className="font-mono text-xs text-secondary truncate">{job.queue}</span>
                  <span className="text-2xs text-muted shrink-0 ml-2 font-mono tabular-nums">{job.failed_at}</span>
                </div>
                <p className="text-2xs text-muted truncate mt-0.5">{job.error}</p>
              </div>
            ))}
          </div>
        </>
      )}

      {data.failed_jobs === 0 && data.pending_jobs === 0 && (
        <p className="text-sm text-muted">Queue is clear.</p>
      )}
    </Panel>
  );
}

/* ── Open Alerts ─────────────────────────────────────────────────────────── */

const ALERT_VARIANT: Record<string, 'danger' | 'warning' | 'info' | 'neutral'> = {
  critical: 'danger',
  warning:  'warning',
  info:     'info',
};

function OpenAlertsPanel({
  data,
}: {
  data: AdminDashboardData['panels']['open_alerts'];
}) {
  return (
    <Panel
      title="Open System Alerts"
      meta={data.total > 0 ? `${data.total} open` : undefined}
      actions={<Link className="text-xs text-link hover:underline" to="/alerts">All alerts →</Link>}
    >
      {data.total > 0 && (
        <div className="flex gap-3 mb-3">
          {data.critical > 0 && (
            <span className="inline-flex items-center gap-1 text-xs font-medium text-danger">
              <span className="h-1.5 w-1.5 rounded-full bg-danger inline-block" />
              {data.critical} critical
            </span>
          )}
          {data.warning > 0 && (
            <span className="inline-flex items-center gap-1 text-xs font-medium text-warning">
              <span className="h-1.5 w-1.5 rounded-full bg-warning inline-block" />
              {data.warning} warning
            </span>
          )}
        </div>
      )}

      {data.items.length === 0 ? (
        <p className="text-sm text-muted">No open alerts.</p>
      ) : (
        <div className="space-y-0">
          {data.items.map((alert: AdminAlert) => (
            <div
              key={alert.id}
              className="flex items-start gap-2 py-2 border-b border-subtle last:border-0"
            >
              <Chip variant={ALERT_VARIANT[alert.severity] ?? 'neutral'} className="shrink-0 mt-0.5">
                {alert.severity}
              </Chip>
              <div className="min-w-0 flex-1">
                <div className="text-sm font-medium truncate">{alert.title}</div>
                <div className="text-2xs text-muted truncate">{alert.message}</div>
              </div>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}

/* ── Audit Trail ─────────────────────────────────────────────────────────── */

const ACTION_COLOR: Record<string, string> = {
  created: 'text-success',
  updated: 'text-info',
  deleted: 'text-danger',
};

function AuditTrailPanel({ events }: { events: AdminAuditEvent[] }) {
  return (
    <Panel
      title="Recent Audit Trail"
      actions={<Link className="text-xs text-link hover:underline" to="/admin/audit-logs">Full log →</Link>}
    >
      {events.length === 0 ? (
        <p className="text-sm text-muted">No recent audit events.</p>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6">
          {events.map((e: AdminAuditEvent, i: number) => (
            <div
              key={i}
              className="flex items-start gap-2 py-1.5 border-b border-subtle last:border-0 text-sm"
            >
              <span className={`font-mono text-2xs uppercase shrink-0 mt-0.5 w-12 ${ACTION_COLOR[e.action] ?? 'text-muted'}`}>
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
