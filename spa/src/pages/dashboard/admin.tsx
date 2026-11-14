import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuRefreshCw } from '@/lib/icons';
import { usePermission } from '@/hooks/usePermission';
import { kpiApi } from '@/api/kpi';
import { refetchIntervalUnlessClientHttpError } from '@/lib/httpErrorPolicy';
import { settingsApi, type SystemInfo } from '@/api/admin/settings';
import { AdminSystemHealthPanel } from '@/components/admin/AdminSystemHealthPanel';
import { BarComparison } from '@/components/charts/BarComparison';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { StatusDot } from '@/components/ui/StatusDot';
import { DashboardAttentionList, DashboardShell, DashSection, DashTail, KpiGrid, PanelRow } from '@/components/dashboard/DashboardShell';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { ADMIN_KPIS } from '@/lib/dashboardKpis';
import { formatDateTime, formatRelative } from '@/lib/formatDate';
import {
 dashboardsApi,
 DASHBOARD_REFRESH_FAST_MS,
 type AdminDashboardData,
 type AdminSession,
 type AdminLockedAccount,
 type AdminFailedLogin,
 type AdminFailedJob,
 type AdminAlert,
 type AdminAuditEvent,
} from '@/api/dashboards';

const PANEL_ACTION_CLASS = 'inline-flex min-h-8 items-center rounded-sm text-caption font-medium text-link underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1';

/**
 * System Administrator dashboard — system health and security monitoring.
 *
 * The page leads with live system signals and platform health, then groups
 * access risk, authentication activity, and the audit trail by admin task.
 */
export default function AdminDashboard() {
 const { can } = usePermission();
 const canViewSettings = can('admin.settings.manage');
 const q = useQuery({
 queryKey: ['dashboard', 'admin'],
  queryFn: () => dashboardsApi.admin(),
  refetchInterval: refetchIntervalUnlessClientHttpError(DASHBOARD_REFRESH_FAST_MS),
  });
 const systemInfoQuery = useQuery({
  queryKey: ['admin', 'system-info'],
  queryFn: settingsApi.systemInfo,
  enabled: canViewSettings,
  staleTime: 0,
 });
 const systemHealthQuery = useQuery({
  queryKey: ['admin', 'system-health'],
  queryFn: settingsApi.systemHealth,
  enabled: canViewSettings,
  refetchInterval: refetchIntervalUnlessClientHttpError(canViewSettings ? DASHBOARD_REFRESH_FAST_MS : false),
  refetchIntervalInBackground: false,
 });

  return (
  <DashboardShell<AdminDashboardData>
  title="System Administrator"
  subtitle="Security, accounts, and platform health."
  query={q}
  kpiCount={5}
  attentionPlacement="after-kpis"
  refreshingQueryKey={['dashboard', 'admin']}
  // Shared KPI strip slot. ADMIN_KPIS is intentionally empty — this surface
  // uses operational signals instead of business KPI snapshots — so this renders nothing,
  // but every dashboard mounts the strip from the same place.
  header={<KpiStrip codes={ADMIN_KPIS} compact />}
  >
   {(snapshot) => {
    const { panels } = snapshot;
    const signInStats = panels.auth_events ? summarizeAuthentication(panels.auth_events.breakdown_24h) : null;
    const snapshotStats = [
     panels.open_alerts && {
      label: 'Critical alerts',
      value: panels.open_alerts.critical,
      helper: `${panels.open_alerts.warning} warning${panels.open_alerts.warning === 1 ? '' : 's'} · ${panels.open_alerts.total} open`,
      linkTo: '/alerts',
      tone: panels.open_alerts.critical > 0 ? 'text-danger-fg' : panels.open_alerts.warning > 0 ? 'text-warning-fg' : 'text-success-fg',
     },
     panels.active_sessions && {
      label: 'Active sessions',
      value: panels.active_sessions.total,
      helper: `${panels.active_sessions.unique_users} users · past ${panels.active_sessions.active_window_minutes ?? 5} min`,
      linkTo: '/admin/users',
      tone: 'text-accent',
     },
     panels.account_security && {
      label: 'Locked accounts',
      value: panels.account_security.locked,
      helper: `${panels.account_security.at_risk} at risk · ${panels.account_security.inactive} inactive`,
      linkTo: '/admin/users',
      tone: panels.account_security.locked > 0 ? 'text-danger-fg' : 'text-success-fg',
     },
     panels.auth_events && {
      label: 'Failed sign-ins',
      value: signInStats?.failed ?? 0,
      helper: `${panels.auth_events.window_hours ?? 24}h · ${signInStats?.successRate ?? '—'}% success`,
      linkTo: '/admin/audit-logs',
      tone: (signInStats?.failed ?? 0) > 0 ? 'text-warning-fg' : 'text-success-fg',
     },
     panels.queue_health && {
      label: 'Failed jobs',
      value: panels.queue_health.failed_jobs,
      helper: `${panels.queue_health.pending_jobs} pending`,
      linkTo: '/admin/settings',
      tone: panels.queue_health.failed_jobs > 0 ? 'text-danger-fg' : 'text-success-fg',
     },
    ].filter((stat): stat is SnapshotStat => Boolean(stat));

    return (
     <>
      {snapshotStats.length > 0 && (
      <DashSection title="System overview" tone="lede">
        <KpiGrid count={snapshotStats.length}>
         {snapshotStats.map((stat) => (
          <StatCard
           key={stat.label}
           label={stat.label}
           value={<span className={stat.tone}>{stat.value.toLocaleString()}</span>}
           helper={stat.helper}
           linkTo={stat.linkTo}
          />
         ))}
        </KpiGrid>
       </DashSection>
      )}

      <DashboardAttentionList />

      {(panels.queue_health || panels.open_alerts) && (
       <DashSection title="System issues">
        {panels.open_alerts && panels.queue_health ? (
         <PanelRow>
          <OpenAlertsPanel data={panels.open_alerts} />
          <QueueHealthPanel data={panels.queue_health} />
         </PanelRow>
        ) : panels.open_alerts ? (
         <OpenAlertsPanel data={panels.open_alerts} />
        ) : panels.queue_health ? (
         <QueueHealthPanel data={panels.queue_health} />
        ) : null}
       </DashSection>
      )}

      {canViewSettings && (
       <AdminSystemHealthPanel
        health={systemHealthQuery.data}
        isLoading={systemHealthQuery.isLoading}
        isError={systemHealthQuery.isError}
        onRetry={() => void systemHealthQuery.refetch()}
       />
      )}

      {panels.auth_events && <AuthEventsPanel data={panels.auth_events} />}

      {(panels.active_sessions || panels.account_security) && (
      <DashSection title="Accounts and access">
        {panels.active_sessions && panels.account_security ? (
         <PanelRow>
          <ActiveSessionsPanel data={panels.active_sessions} />
          <AccountSecurityPanel data={panels.account_security} />
         </PanelRow>
        ) : panels.active_sessions ? (
         <ActiveSessionsPanel data={panels.active_sessions} />
        ) : panels.account_security ? (
         <AccountSecurityPanel data={panels.account_security} />
        ) : null}
       </DashSection>
      )}

      {panels.recent_audit && <AuditTrailPanel events={panels.recent_audit} />}

      <DashTail title="Platform details" summary="Runtime and KPIs">
       {canViewSettings ? (
        <PanelRow>
         <RuntimePanel
          data={systemInfoQuery.data}
          isLoading={systemInfoQuery.isLoading}
          isError={systemInfoQuery.isError}
          onRetry={() => void systemInfoQuery.refetch()}
         />
         <ComputeKpisPanel />
        </PanelRow>
       ) : (
        <ComputeKpisPanel />
       )}
      </DashTail>
     </>
    );
   }}
 </DashboardShell>
 );
}

interface SnapshotStat {
 label: string;
 value: number;
 helper: string;
 linkTo: string;
 tone: string;
}

function summarizeAuthentication(breakdown: Record<string, number>) {
 const successful = breakdown.success ?? 0;
 const failed = Object.entries(breakdown)
  .filter(([status]) => status !== 'success')
  .reduce((total, [, count]) => total + count, 0);
 const attempts = successful + failed;
 return {
  successful,
  failed,
  attempts,
  successRate: attempts > 0 ? Math.round((successful / attempts) * 100) : 100,
 };
}

function RuntimePanel({
 data,
 isLoading,
 isError,
 onRetry,
}: {
 data: SystemInfo | undefined;
 isLoading: boolean;
 isError: boolean;
 onRetry: () => void;
}) {
 return (
  <Panel
   title="Application runtime"
   meta={data ? formatDateTime(data.server_time) : undefined}
   actions={<Link className={PANEL_ACTION_CLASS} to="/admin/settings">Settings</Link>}
  >
   {isLoading && <p role="status" className="text-sm text-muted">Loading runtime details…</p>}
   {isError && (
    <div role="alert" className="flex flex-wrap items-center justify-between gap-3">
     <p className="text-sm text-muted">Runtime details could not be loaded.</p>
     <Button size="sm" variant="secondary" onClick={onRetry}>Retry</Button>
    </div>
   )}
   {data && (
    <>
     <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2">
      <StatusDot
       label={`${data.app_env[0]?.toUpperCase() ?? ''}${data.app_env.slice(1)} environment`}
       variant={data.app_env === 'production' ? 'success' : 'warning'}
      />
      <Chip variant={data.app_debug ? 'danger' : 'success'}>
       {data.app_debug ? 'Debug enabled' : 'Debug disabled'}
      </Chip>
     </div>
     <dl className="grid grid-cols-1 gap-x-5 gap-y-3 sm:grid-cols-2">
      <RuntimeValue label="Database">
       {data.database.driver} · {data.database.version}
      </RuntimeValue>
      <RuntimeValue label="Queue and cache">
       {data.queue_driver} queue · {data.cache_driver} cache
      </RuntimeValue>
      <RuntimeValue label="Application">
       PHP {data.php_version} · Laravel {data.laravel_version}
      </RuntimeValue>
      <RuntimeValue label="Session and timezone">
       {data.session_driver} · {data.timezone}
      </RuntimeValue>
     </dl>
    </>
   )}
  </Panel>
 );
}

function RuntimeValue({ label, children }: { label: string; children: ReactNode }) {
 return (
  <div className="min-w-0">
    <dt className="text-caption font-medium text-muted">{label}</dt>
    <dd className="mt-0.5 break-words text-body-small text-primary">{children}</dd>
  </div>
 );
}

/* ── Compute KPIs ──────────────────────────────────────────────────────── */

const MONTHS = [
 'January', 'February', 'March', 'April', 'May', 'June',
 'July', 'August', 'September', 'October', 'November', 'December',
];

function ComputeKpisPanel() {
 const { can } = usePermission();
 const queryClient = useQueryClient();

 const now = new Date();
 const [year, setYear] = useState(now.getFullYear());
 const [month, setMonth] = useState(now.getMonth() + 1);

 const computeMut = useMutation({
 mutationFn: () => kpiApi.compute(year, month),
 onSuccess: (res) => {
 toast.success(res.data.message);
 queryClient.invalidateQueries({ queryKey: ['kpi'] });
 },
 onError: () => {
 toast.error('Failed to compute KPIs');
 },
 });

 if (!can('dashboard.admin.view')) return null;

 const yearOptions = Array.from({ length: 4 }, (_, i) => now.getFullYear() - 3 + i);

 return (
 <Panel
 title="Compute KPIs"
 meta={`${MONTHS[month - 1]} ${year}`}
 >
 <div className="flex flex-wrap items-center gap-2">
 <Select
 value={month}
 onChange={(e) => setMonth(Number(e.target.value))}
 aria-label="Month"
 >
 {MONTHS.map((m, i) => (
 <option key={i} value={i + 1}>{m}</option>
 ))}
 </Select>
 <Select
 value={year}
 onChange={(e) => setYear(Number(e.target.value))}
 aria-label="Year"
 >
 {yearOptions.map((y) => (
 <option key={y} value={y}>{y}</option>
 ))}
 </Select>
 <Button
 variant="secondary"
 onClick={() => computeMut.mutate()}
 loading={computeMut.isPending}
 icon={<LuRefreshCw size={14} />}
 >
 {computeMut.isPending ? 'Computing…' : 'Compute KPIs'}
 </Button>
 </div>
 <p className="text-caption text-muted mt-2">
 Recomputes every KPI snapshot for the selected month. Strips refresh on next load.
 </p>
 </Panel>
 );
}

/* ── Active Sessions ─────────────────────────────────────────────────────── */

function ActiveSessionsPanel({
 data,
}: {
 data: NonNullable<AdminDashboardData['panels']['active_sessions']>;
}) {
 return (
 <Panel
 title="Active sessions"
 meta={`${data.total} session${data.total !== 1 ? 's' : ''} · ${data.unique_users} user${data.unique_users !== 1 ? 's' : ''}`}
 actions={<Link className={PANEL_ACTION_CLASS} to="/admin/users">Manage users</Link>}
 >
 {data.sessions.length === 0 ? (
 <EmptyState size="compact" icon="monitor" title="Nobody signed in" description={`No active sessions in the last ${data.active_window_minutes ?? 'configured'} minutes.`} />
 ) : (
 <>
 <ul className="divide-y divide-subtle">
 {data.sessions.slice(0, 8).map((s: AdminSession) => (
  <li
   key={`${s.user}:${s.ip}:${s.last_activity}`}
   className="flex min-w-0 items-center gap-3 py-2 text-sm"
  >
   <span aria-hidden="true" className="h-2 w-2 shrink-0 rounded-full bg-success" />
   <div className="min-w-0 flex-1">
    <span className="block truncate font-medium text-primary">{s.user}</span>
    <span className="block truncate text-xs text-muted">{s.role} · {s.device}</span>
   </div>
   <div className="shrink-0 text-right">
    <span className="block text-xs tabular-nums text-secondary">{s.ip}</span>
    <time className="block text-xs text-muted" dateTime={s.last_activity} title={formatDateTime(s.last_activity)}>
     Active {formatRelative(s.last_activity)}
    </time>
   </div>
  </li>
 ))}
 </ul>
 {data.sessions.length > 8 && (
  <p className="mt-2 text-xs text-muted">Showing 8 of {data.total} active sessions.</p>
 )}
 </>
 )}
 </Panel>
 );
}

/* ── Account Security ────────────────────────────────────────────────────── */

function AccountSecurityPanel({
 data,
}: {
 data: NonNullable<AdminDashboardData['panels']['account_security']>;
}) {
 const stats = [
 { label: 'Total accounts', value: data.total, color: '' },
 { label: 'Active', value: data.active, color: 'text-success-fg' },
 { label: 'Inactive / disabled', value: data.inactive, color: data.inactive > 0 ? 'text-warning-fg' : '' },
 { label: 'Currently locked', value: data.locked, color: data.locked > 0 ? 'text-danger-fg' : '' },
 { label: 'At risk (3 or more failures)', value: data.at_risk, color: data.at_risk > 0 ? 'text-warning-fg' : '' },
 { label: 'Must change password', value: data.must_change_password, color: data.must_change_password > 0 ? 'text-warning-fg' : '' },
 ];

 return (
 <Panel
 title="Account security"
 actions={<Link className={PANEL_ACTION_CLASS} to="/admin/users">All accounts</Link>}
 >
 <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 mb-3">
 {stats.map((s) => (
 <div key={s.label} className="flex justify-between items-baseline">
 <span className="text-2xs text-muted truncate mr-2">{s.label}</span>
 <span className={`text-sm tabular-nums font-medium shrink-0 ${s.color}`}>
 {s.value}
 </span>
 </div>
 ))}
 </div>

 {data.locked_accounts.length > 0 && (
 <>
 <div className="mb-1 border-t border-subtle pt-2 text-xs font-medium text-muted">
 Currently locked
 </div>
 <ul className="divide-y divide-subtle">
 {data.locked_accounts.map((acc: AdminLockedAccount) => (
 <li
 key={acc.email}
 className="flex min-w-0 items-center justify-between gap-3 py-2 text-sm"
 >
 <div className="min-w-0 flex-1">
 <span className="block truncate font-medium text-primary">{acc.name}</span>
 <span className="block truncate text-xs text-muted">{acc.email} · {acc.role}</span>
 </div>
 <div className="shrink-0 text-right">
  <span className="block text-xs tabular-nums text-danger-fg">
   {acc.attempts} failed attempts
  </span>
  <time className="block text-xs text-muted" dateTime={acc.locked_until} title={formatDateTime(acc.locked_until)}>
   Unlocks {formatRelative(acc.locked_until)}
  </time>
 </div>
 </li>
 ))}
 </ul>
 </>
 )}
 </Panel>
 );
}

/* ── Auth Events ─────────────────────────────────────────────────────────── */

const AUTH_STATUS_VARIANT: Record<string, 'success' | 'danger' | 'warning' | 'neutral'> = {
 success: 'success',
 failed_credentials: 'danger',
 failed_locked: 'danger',
 failed_inactive: 'warning',
 failed_password_expired: 'warning',
};

function AuthEventsPanel({
 data,
}: {
 data: NonNullable<AdminDashboardData['panels']['auth_events']>;
}) {
 const breakdown = data.breakdown_24h;
 const windowHours = data.window_hours ?? 24;
 const statusLabels = new Map(data.status_options.map((option) => [option.value, option.label]));
 const totals = summarizeAuthentication(breakdown);
 const trendBuckets = authTrendBuckets(data.success_trend_24h, windowHours);
 const statusRows = Object.entries(breakdown).sort((a, b) => b[1] - a[1]);

 return (
 <Panel
 title="Authentication activity"
 meta={`${totals.attempts.toLocaleString()} attempts · ${totals.successRate}% successful in ${windowHours}h`}
 actions={<Link className={PANEL_ACTION_CLASS} to="/admin/audit-logs">View audit log</Link>}
 >
 <div className="grid min-w-0 gap-5 xl:grid-cols-12">
  <section aria-labelledby="auth-outcome-title" className="min-w-0 xl:col-span-3">
    <h3 id="auth-outcome-title" className="mb-3 text-sm font-semibold text-primary">Sign-in outcomes</h3>
   {statusRows.length === 0 ? (
    <p className="text-sm text-muted">No login attempts in the last {windowHours}h.</p>
   ) : (
    <div className="space-y-3">
     {statusRows.map(([status, count]) => (
      <div key={status}>
        <div className="mb-1 flex items-center justify-between gap-2 text-caption">
        <span className="truncate text-secondary">{statusLabels.get(status) ?? status}</span>
        <span className="shrink-0 tabular-nums text-primary">{count.toLocaleString()}</span>
       </div>
       <div className="h-1.5 overflow-hidden rounded-full bg-subtle">
        <div
         className={`h-full rounded-full ${AUTH_STATUS_FILL[status] ?? 'bg-danger'}`}
         style={{ width: `${totals.attempts > 0 ? (count / totals.attempts) * 100 : 0}%` }}
        />
       </div>
      </div>
     ))}
    </div>
   )}
  </section>

  <section aria-labelledby="auth-trend-title" className="min-w-0 xl:col-span-5">
   <h3 id="auth-trend-title" className="mb-2 text-sm font-semibold text-primary">Successful sign-ins over time</h3>
   {data.success_trend_24h.some((count) => count > 0) ? (
    <BarComparison
     label={`Successful sign-ins across the last ${windowHours} hours, grouped into time windows`}
     data={trendBuckets}
     bars={[{ dataKey: 'successful', label: 'Successful sign-ins' }]}
     xKey="period"
     height={190}
     formatValue={(value) => value.toLocaleString()}
    />
   ) : (
    <EmptyState size="compact" icon="bar-chart" title="No successful sign-ins yet" description={`No successful sign-ins in the last ${windowHours} hours.`} />
   )}
  </section>

  <section aria-labelledby="recent-failures-title" className="min-w-0 xl:col-span-4">
   <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
    <h3 id="recent-failures-title" className="text-sm font-semibold text-primary">Recent failures</h3>
    {data.recent_failures.length > 6 && (
     <span className="text-xs text-muted">Latest 6 of {data.recent_failures.length}</span>
    )}
   </div>
   {data.recent_failures.length === 0 ? (
    <EmptyState size="compact" icon="shield" title="No failed logins" description={`No failed sign-ins in the last ${windowHours}h.`} />
   ) : (
    <ul className="divide-y divide-subtle">
     {data.recent_failures.slice(0, 6).map((failure: AdminFailedLogin) => (
      <li key={`${failure.email}:${failure.created_at}:${failure.ip}`} className="py-2">
       <div className="flex min-w-0 items-center gap-2">
        <Chip variant={AUTH_STATUS_VARIANT[failure.status] ?? 'neutral'} className="shrink-0">
         {statusLabels.get(failure.status) ?? failure.status}
        </Chip>
        <span className="min-w-0 flex-1 truncate text-xs font-medium text-primary">{failure.email}</span>
       </div>
       <div className="mt-1 flex flex-wrap justify-between gap-x-2 gap-y-0.5 text-xs text-muted">
        <span>{failure.reason !== '—' ? failure.reason : failure.ip}</span>
        <time dateTime={failure.created_at} title={formatDateTime(failure.created_at)}>
         {formatRelative(failure.created_at)}
        </time>
       </div>
      </li>
     ))}
    </ul>
   )}
  </section>
 </div>
 </Panel>
 );
}

const AUTH_STATUS_FILL: Record<string, string> = {
 success: 'bg-success',
 failed_credentials: 'bg-danger',
 failed_locked: 'bg-danger',
 failed_inactive: 'bg-warning',
 failed_password_expired: 'bg-warning',
};

function authTrendBuckets(values: number[], windowHours: number) {
 if (values.length === 0) return [];
 const bucketCount = Math.min(8, values.length);
 const bucketSize = Math.ceil(values.length / bucketCount);
 return Array.from({ length: bucketCount }, (_, index) => {
  const start = index * bucketSize;
  const slice = values.slice(start, start + bucketSize);
  const olderHours = Math.ceil((values.length - start) * (windowHours / values.length));
  const newerHours = Math.ceil((values.length - start - slice.length) * (windowHours / values.length));
  return {
   period: newerHours === 0 ? `${olderHours}h–now` : `${olderHours}–${newerHours}h`,
   successful: slice.reduce((total, value) => total + value, 0),
  };
 });
}

/* ── Queue Health ────────────────────────────────────────────────────────── */

function QueueHealthPanel({
 data,
}: {
 data: NonNullable<AdminDashboardData['panels']['queue_health']>;
}) {
 return (
 <Panel
 title="Queue health"
 meta={
 data.healthy ? (
 <StatusDot label="Healthy" variant="success" />
 ) : (
 <StatusDot label="Issues detected" variant="danger" />
 )
 }
 >
 <div className="mb-3 grid grid-cols-2 gap-4 border-b border-default pb-3">
 <div className="min-w-0">
  <div className="text-xs text-muted">Pending jobs</div>
  <div className="mt-0.5 text-xl font-medium tabular-nums text-primary">{data.pending_jobs}</div>
 </div>
 <div className="min-w-0">
  <div className="text-xs text-muted">Failed jobs</div>
  <div className={`mt-0.5 text-xl font-medium tabular-nums ${data.failed_jobs > 0 ? 'text-danger-fg' : 'text-primary'}`}>
   {data.failed_jobs}
  </div>
 </div>
 </div>

 {data.recent_failed.length > 0 && (
 <>
 <div className="mb-1 flex items-baseline justify-between gap-2">
  <span className="text-xs font-medium text-muted">Recent failures</span>
  {data.failed_jobs > 5 && <span className="text-xs text-muted">Latest 5 of {data.failed_jobs}</span>}
 </div>
 <div className="space-y-0">
 {data.recent_failed.slice(0, 5).map((job: AdminFailedJob) => (
 <div key={job.uuid} className="border-b border-subtle py-2 last:border-0">
 <div className="flex min-w-0 items-center justify-between gap-2 text-sm">
 <span className="truncate font-mono text-xs text-secondary">{job.queue}</span>
 <time className="shrink-0 text-xs text-muted" dateTime={job.failed_at} title={formatDateTime(job.failed_at)}>
  {formatRelative(job.failed_at)}
 </time>
 </div>
 <p className="mt-1 truncate text-xs text-muted" title={job.error}>{job.error}</p>
 </div>
 ))}
 </div>
 </>
 )}

 {data.failed_jobs === 0 && data.pending_jobs === 0 && (
 <EmptyState size="compact" icon="check-circle" title="Queue is clear" description="No pending or failed jobs." />
 )}
 </Panel>
 );
}

/* ── Open Alerts ─────────────────────────────────────────────────────────── */

const ALERT_VARIANT: Record<string, 'danger' | 'warning' | 'info' | 'neutral'> = {
 critical: 'danger',
 warning: 'warning',
 info: 'info',
};

function OpenAlertsPanel({
 data,
}: {
 data: NonNullable<AdminDashboardData['panels']['open_alerts']>;
}) {
 return (
 <Panel
 title="Open system alerts"
 meta={data.total > 0 ? `${data.total} open` : undefined}
 actions={<Link className={PANEL_ACTION_CLASS} to="/alerts">All alerts</Link>}
 >
 {data.total > 0 && (
 <div className="flex gap-3 mb-3">
 {data.critical > 0 && (
 <span className="inline-flex items-center gap-1 text-xs font-medium text-danger-fg">
 <span className="h-1.5 w-1.5 rounded-full bg-danger-bg inline-block" />
 {data.critical} critical
 </span>
 )}
 {data.warning > 0 && (
 <span className="inline-flex items-center gap-1 text-xs font-medium text-warning-fg">
 <span className="h-1.5 w-1.5 rounded-full bg-warning-bg inline-block" />
 {data.warning} warning
 </span>
 )}
 </div>
 )}

 {data.items.length === 0 ? (
 <EmptyState size="compact" icon="bell-off" title="All clear" description="No open system alerts." />
 ) : (
 <>
 <ul className="divide-y divide-subtle">
 {data.items.slice(0, 6).map((alert: AdminAlert) => (
 <li
 key={alert.id}
 className="flex min-w-0 items-start gap-2 py-2"
 >
 <Chip variant={ALERT_VARIANT[alert.severity] ?? 'neutral'} className="shrink-0 mt-0.5">
 {alert.severity_label ?? alert.severity}
 </Chip>
 <div className="min-w-0 flex-1">
 <div className="truncate text-sm font-medium text-primary" title={alert.title}>{alert.title}</div>
 <div className="truncate text-xs text-muted" title={alert.message}>{alert.message}</div>
 </div>
 <time className="shrink-0 text-xs text-muted" dateTime={alert.created_at} title={formatDateTime(alert.created_at)}>
  {formatRelative(alert.created_at)}
 </time>
 </li>
 ))}
 </ul>
 {data.total > 6 && (
  <p className="mt-2 text-xs text-muted">Showing 6 of {data.total} open alerts.</p>
 )}
 </>
 )}
 </Panel>
 );
}

/* ── Audit Trail ─────────────────────────────────────────────────────────── */

const ACTION_COLOR: Record<string, string> = {
 created: 'text-success-fg',
 updated: 'text-info-fg',
 deleted: 'text-danger-fg',
};

function AuditTrailPanel({ events }: { events: AdminAuditEvent[] }) {
 return (
 <Panel
 title="Recent audit trail"
 meta={`${events.length} recent event${events.length === 1 ? '' : 's'}`}
 actions={<Link className={PANEL_ACTION_CLASS} to="/admin/audit-logs">Full log</Link>}
 >
 {events.length === 0 ? (
 <EmptyState size="compact" icon="file-text" title="No audit events" description="Recent record changes will appear here." />
 ) : (
 <>
 <ul className="divide-y divide-subtle">
 {events.slice(0, 8).map((e: AdminAuditEvent) => (
 <li
 key={`${e.created_at}:${e.action}:${e.entity}:${e.user}`}
 className="flex min-w-0 items-start gap-3 py-2.5 text-sm"
 >
 <span className={`mt-0.5 w-16 shrink-0 text-xs font-medium uppercase ${ACTION_COLOR[e.action] ?? 'text-muted'}`}>
 {e.action}
 </span>
 <div className="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
  <div className="min-w-0 flex-1">
   <span className="block truncate font-medium text-primary">{e.entity}</span>
   <span className="block truncate text-xs text-muted">{e.user} · {e.ip}</span>
  </div>
  <time className="shrink-0 text-xs text-muted" dateTime={e.created_at} title={formatDateTime(e.created_at)}>
   {formatRelative(e.created_at)}
  </time>
 </div>
 </li>
 ))}
 </ul>
 {events.length > 8 && (
  <p className="mt-2 text-xs text-muted">Showing 8 of {events.length} recent events.</p>
 )}
 </>
 )}
 </Panel>
 );
}
