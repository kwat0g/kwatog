import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';

import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { DashboardShell, KpiGrid, PanelRow } from '@/components/dashboard/DashboardShell';
import { ForecastPanel } from '@/components/dashboard/ForecastPanel';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { DonutBreakdown } from '@/components/charts';
import { client } from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { ForecastPanelData } from '@/types/forecasting-dashboard';
import { formatPeso } from '@/lib/formatNumber';

/**
 * Task D4 — HR Officer dashboard.
 *
 * Opinionated 5-row layout replacing the generic `<RoleDashboard>` wrapper:
 *   Row 1 — 4 KPI stat cards
 *   Row 2 — Attendance summary + pending my action (2-col)
 *   Row 3 — Department headcount + probation alerts (2-col)
 *   Row 4 — Leave calendar this week + calendar events & birthdays (2-col)
 *   Row 5 — Recent hires + pending leaves (2-col)
 */

interface HrDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
    by_department: Array<{ label: string; count: number }>;
    recent_hires: Array<{ id: string; employee_no: string; name: string; date_hired: string }>;
    pending_leaves: Array<{ id: string; leave_request_no: string | null; status: string; days: string }>;
    attendance_summary: { present: number; late: number; absent: number; on_leave: number };
    probation_alerts: Array<{
      id: string; employee_no: string; name: string; date_hired: string;
      probation_end: string; department: string;
    }>;
    leave_calendar_week: Array<{
      id: string; employee_no: string; name: string;
      start_date: string; end_date: string; days: string;
    }>;
    hr_calendar_events: {
      holidays: Array<{ name: string; date: string; type: string }>;
      birthdays: Array<{ id: string; name: string; date: string }>;
      birthdays_count: number;
    };
    pending_my_action: { leave_requests: number; profile_updates: number; clearances: number; total: number };
    headcount_forecast: ForecastPanelData;
    // REC-05 — present only for HR users with payroll.view.
    payroll_summary?: {
      latest_period: { id: string; label: string; status: string; payroll_date: string | null } | null;
      employees_on_run: number;
      net_pay_total: string;
      pending_salary_adjustments: number;
    };
  };
}

export default function HrDashboard() {
  const q = useQuery({
    queryKey: ['dashboard', 'hr'],
    queryFn: (): Promise<HrDashboardData> =>
      client.get<ApiSuccess<HrDashboardData>>('/dashboards/hr').then((r) => r.data.data),
    refetchInterval: 60_000,
  });

  return (
    <DashboardShell<HrDashboardData>
      title="HR Officer Dashboard"
      subtitle="Workforce, attendance, leave, and compliance overview."
      query={q}
      refreshingQueryKey={['dashboard', 'hr']}
    >
      {({ kpis, panels }) => {
        const departmentDonutData = panels.by_department
          .map((d) => ({ name: d.label, value: d.count, color: 'var(--accent)' }))
          .slice(0, 6);

        const attendanceDonutData = [
          { name: 'Present', value: panels.attendance_summary.present, color: 'var(--success)' },
          { name: 'Late', value: panels.attendance_summary.late, color: 'var(--warning)' },
          { name: 'Absent', value: panels.attendance_summary.absent, color: 'var(--danger)' },
          { name: 'On Leave', value: panels.attendance_summary.on_leave, color: 'var(--info)' },
        ].filter((i) => i.value > 0);

        return (
          <>
            {/* Row 1 — KPIs */}
            <KpiGrid count={kpis.length}>
              {kpis.map((kpi) => (
                <StatCard key={kpi.label} label={kpi.label} value={kpi.value} helper={kpi.unit} />
              ))}
            </KpiGrid>

            {/* KPI Scorecard strip */}
            <KpiStrip codes={['attendance_rate']} />

            {/* Row 2 — Attendance summary + pending my action */}
            <PanelRow>
              <AttendanceSummaryPanel data={panels.attendance_summary} />
              <PendingMyActionPanel data={panels.pending_my_action} />
            </PanelRow>

            {/* Row 3 — Department headcount + probation alerts */}
            <PanelRow>
              <DepartmentHeadcountPanel departments={panels.by_department} />
              <ProbationAlertsPanel alerts={panels.probation_alerts} />
            </PanelRow>

            {/* Row 4 — Leave calendar this week + calendar events */}
            <PanelRow>
              <LeaveCalendarPanel leaves={panels.leave_calendar_week} />
              <CalendarEventsPanel events={panels.hr_calendar_events} />
            </PanelRow>

            {/* Row 5 — Recent hires + pending leaves */}
            <PanelRow>
              <RecentHiresPanel hires={panels.recent_hires} />
              <PendingLeavesPanel leaves={panels.pending_leaves} />
            </PanelRow>

            {/* Row 5.5 — Chart visualizations */}
            <PanelRow>
              <Panel title="Department Headcount Breakdown">
                {departmentDonutData.length === 0 ? (
                  <EmptyState size="compact" icon="users" title="No departments" description="No department data available." />
                ) : (
                  <DonutBreakdown
                    data={departmentDonutData}
                    centerLabel="Departments"
                    centerValue={String(departmentDonutData.length)}
                  />
                )}
              </Panel>
              <Panel title="Attendance Today Breakdown">
                {attendanceDonutData.length === 0 ? (
                  <EmptyState size="compact" icon="calendar" title="No attendance yet" description="No attendance records for today." />
                ) : (
                  <DonutBreakdown
                    data={attendanceDonutData}
                    centerLabel="Total"
                    centerValue={String(attendanceDonutData.reduce((sum, i) => sum + i.value, 0))}
                  />
                )}
              </Panel>
            </PanelRow>

            {/* Row 6 — Headcount forecast */}
            <ForecastPanel
              data={panels.headcount_forecast}
              isLoading={false}
              isError={false}
              title="Headcount Forecast"
              formatValue={(v) => String(v)}
              unitLabel="employees"
            />

            {/* Row 7 — Payroll snapshot (visible only when payroll.view is granted) */}
            {panels.payroll_summary && <PayrollSummaryPanel data={panels.payroll_summary} />}
          </>
        );
      }}
    </DashboardShell>
  );
}

/* ── Sub-panels ─────────────────────────────────────────────────────────── */

function AttendanceSummaryPanel({
  data,
}: {
  data: HrDashboardData['panels']['attendance_summary'];
}) {
  const items: Array<{ label: string; value: number; color: string }> = [
    { label: 'Present',  value: data.present,  color: 'text-success' },
    { label: 'Late',     value: data.late,     color: 'text-warning' },
    { label: 'Absent',   value: data.absent,   color: 'text-danger' },
    { label: 'On Leave', value: data.on_leave, color: 'text-info' },
  ];
  const total = data.present + data.late + data.absent + data.on_leave;

  return (
    <Panel title="Attendance Today" actions={<Link className="text-xs text-link hover:underline" to="/hr/attendance">Open →</Link>}>
      {total === 0 ? (
        <EmptyState size="compact" icon="calendar" title="No attendance yet" description="No attendance records for today." />
      ) : (
        <div className="space-y-2">
          {items.map((i) => (
            <div key={i.label} className="flex items-center justify-between text-sm">
              <span className="flex items-center gap-2">
                <span className={`w-2 h-2 rounded-full ${i.color}`} aria-hidden="true" />
                {i.label}
              </span>
              <span className={`font-mono tabular-nums ${i.color}`}>{i.value}</span>
            </div>
          ))}
          <div className="flex items-center justify-between text-sm pt-1 border-t border-border">
            <span className="font-medium">Total active</span>
            <span className="font-mono tabular-nums font-medium">{total}</span>
          </div>
        </div>
      )}
    </Panel>
  );
}

function PendingMyActionPanel({
  data,
}: {
  data: HrDashboardData['panels']['pending_my_action'];
}) {
  const items: Array<{ label: string; value: number; href: string }> = [
    { label: 'Leave requests', value: data.leave_requests, href: '/hr/leaves' },
    { label: 'Profile updates', value: data.profile_updates, href: '/hr/profile-update-requests' },
    { label: 'Clearances',     value: data.clearances,     href: '/hr/separations' },
  ];

  return (
    <Panel title="My Action Items" actions={<Link className="text-xs text-link hover:underline" to="/approvals">Approvals board →</Link>}>
      {data.total === 0 ? (
        <EmptyState size="compact" icon="check-circle" title="Nothing to action" description="No pending items require your attention." />
      ) : (
        <>
          <div className="text-2xl font-medium font-mono tabular-nums mb-3">{data.total}</div>
          <div className="space-y-1.5">
            {items.map((i) => (
              <div key={i.label} className="flex items-center justify-between text-sm">
                <Link to={i.href} className="hover:underline">{i.label}</Link>
                <span className="font-mono tabular-nums">{i.value}</span>
              </div>
            ))}
          </div>
        </>
      )}
    </Panel>
  );
}

function DepartmentHeadcountPanel({
  departments,
}: {
  departments: HrDashboardData['panels']['by_department'];
}) {
  if (departments.length === 0) {
    return (
      <Panel title="Headcount by Department">
        <EmptyState size="compact" icon="users" title="No departments" description="No department data available." />
      </Panel>
    );
  }
  const maxCount = Math.max(...departments.map((d) => d.count), 1);
  return (
    <Panel title="Headcount by Department" actions={<Link className="text-xs text-link hover:underline" to="/hr/employees">Employees →</Link>}>
      <div className="space-y-1.5">
        {departments.map((d) => (
          <div key={d.label} className="flex items-center gap-2 text-sm">
            <span className="w-28 truncate">{d.label}</span>
            <div className="flex-1 h-2.5 bg-elevated rounded-full overflow-hidden">
              <div
                className="h-full bg-accent rounded-full transition-all duration-500"
                style={{ width: `${(d.count / maxCount) * 100}%` }}
                role="progressbar"
                aria-valuenow={d.count}
                aria-valuemin={0}
                aria-valuemax={maxCount}
                aria-label={`${d.label}: ${d.count}`}
              />
            </div>
            <span className="w-8 text-right font-mono tabular-nums">{d.count}</span>
          </div>
        ))}
      </div>
    </Panel>
  );
}

function ProbationAlertsPanel({
  alerts,
}: {
  alerts: HrDashboardData['panels']['probation_alerts'];
}) {
  return (
    <Panel
      title="Probation Alerts (next 30 days)"
      actions={<Link className="text-xs text-link hover:underline" to="/hr/employees">Employees →</Link>}
    >
      {alerts.length === 0 ? (
        <EmptyState size="compact" icon="check-circle" title="No probation reviews due" description="No probationary employees end probation in the next 30 days." />
      ) : (
        <div className="space-y-1.5 text-sm">
          {alerts.map((a) => (
            <Link
              key={a.id}
              to={`/hr/employees/${a.id}`}
              className="flex items-center justify-between p-1.5 rounded hover:bg-elevated transition-colors"
            >
              <div className="truncate min-w-0">
                <span className="font-medium">{a.name}</span>
                <span className="text-muted ml-1">({a.department})</span>
              </div>
              <span className="shrink-0 text-xs text-muted font-mono">{a.probation_end}</span>
            </Link>
          ))}
        </div>
      )}
    </Panel>
  );
}

function LeaveCalendarPanel({
  leaves,
}: {
  leaves: HrDashboardData['panels']['leave_calendar_week'];
}) {
  return (
    <Panel
      title="Leaves This Week"
      actions={<Link className="text-xs text-link hover:underline" to="/hr/leaves">All leaves →</Link>}
    >
      {leaves.length === 0 ? (
        <EmptyState size="compact" icon="calendar" title="Everyone in" description="No approved leaves this week." />
      ) : (
        <div className="space-y-1.5 text-sm">
          {leaves.map((l) => (
            <Link
              key={l.id}
              to={`/hr/leaves/${l.id}`}
              className="flex items-center justify-between p-1.5 rounded hover:bg-elevated transition-colors"
            >
              <div className="truncate min-w-0">
                <span className="font-medium">{l.name}</span>
                <span className="text-muted text-xs ml-1">({l.employee_no})</span>
              </div>
              <div className="shrink-0 flex items-center gap-2 text-xs text-muted">
                <span>{l.start_date}</span>
                <span>–</span>
                <span>{l.end_date}</span>
                <Chip variant="info">{l.days}d</Chip>
              </div>
            </Link>
          ))}
        </div>
      )}
    </Panel>
  );
}

function CalendarEventsPanel({
  events,
}: {
  events: HrDashboardData['panels']['hr_calendar_events'];
}) {
  return (
    <Panel title="Calendar Events This Month" actions={<Link className="text-xs text-link hover:underline" to="/calendar">Calendar →</Link>}>
      <div className="space-y-3">
        {/* Holidays */}
        {events.holidays.length === 0 ? (
          <div>
            <p className="text-xs font-medium text-muted uppercase tracking-wider mb-1">Holidays</p>
            <p className="text-sm text-muted">No holidays this month.</p>
          </div>
        ) : (
          <div>
            <p className="text-xs font-medium text-muted uppercase tracking-wider mb-1">Holidays</p>
            <div className="space-y-1">
              {events.holidays.map((h) => (
                <div key={h.name + h.date} className="flex items-center justify-between text-sm">
                  <span>{h.name}</span>
                  <span className="text-xs text-muted font-mono">
                    {h.date}
                    <Chip variant="neutral" className="ml-1">{h.type}</Chip>
                  </span>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Birthdays */}
        <div>
          <p className="text-xs font-medium text-muted uppercase tracking-wider mb-1">
            Birthdays ({events.birthdays_count})
          </p>
          {events.birthdays.length === 0 ? (
            <p className="text-sm text-muted">No birthdays this month.</p>
          ) : (
            <div className="space-y-1">
              {events.birthdays.map((b) => (
                <Link
                  key={b.id}
                  to={`/hr/employees/${b.id}`}
                  className="flex items-center justify-between text-sm hover:bg-elevated rounded p-0.5 transition-colors"
                >
                  <span>{b.name}</span>
                  <span className="text-xs text-muted font-mono">{b.date}</span>
                </Link>
              ))}
            </div>
          )}
        </div>
      </div>
    </Panel>
  );
}

function RecentHiresPanel({
  hires,
}: {
  hires: HrDashboardData['panels']['recent_hires'];
}) {
  return (
    <Panel title="Recent Hires" actions={<Link className="text-xs text-link hover:underline" to="/hr/employees">All employees →</Link>}>
      {hires.length === 0 ? (
        <EmptyState size="compact" icon="user-x" title="No recent hires" description="Nobody has joined in the last 30 days." />
      ) : (
        <div className="space-y-1.5 text-sm">
          {hires.map((h) => (
            <Link
              key={h.id}
              to={`/hr/employees/${h.id}`}
              className="flex items-center justify-between p-1.5 rounded hover:bg-elevated transition-colors"
            >
              <div className="truncate min-w-0">
                <span className="font-medium">{h.name}</span>
                <span className="text-muted text-xs ml-1">({h.employee_no})</span>
              </div>
              <span className="shrink-0 text-xs text-muted">{h.date_hired}</span>
            </Link>
          ))}
        </div>
      )}
    </Panel>
  );
}

function PendingLeavesPanel({
  leaves,
}: {
  leaves: HrDashboardData['panels']['pending_leaves'];
}) {
  const statusVariant = (status: string): 'warning' | 'danger' | 'neutral' | 'success' | 'info' => {
    if (status === 'pending_hr') return 'danger';
    if (status === 'pending_dept') return 'warning';
    if (status === 'pending') return 'warning';
    return 'neutral';
  };
  return (
    <Panel title="Pending Leave Requests" actions={<Link className="text-xs text-link hover:underline" to="/hr/leaves">Leaves →</Link>}>
      {leaves.length === 0 ? (
        <EmptyState size="compact" icon="check-circle" title="No pending requests" description="Every leave request has been actioned." />
      ) : (
        <div className="space-y-1.5 text-sm">
          {leaves.map((l) => (
            <Link
              key={l.id}
              to={`/hr/leaves/${l.id}`}
              className="flex items-center justify-between p-1.5 rounded hover:bg-elevated transition-colors"
            >
              <span className="font-mono text-xs text-muted">
                {l.leave_request_no ?? `#${l.id}`}
              </span>
              <div className="flex items-center gap-2">
                <Chip variant={statusVariant(l.status)}>{l.status}</Chip>
                <span className="font-mono tabular-nums text-xs">{l.days}d</span>
              </div>
            </Link>
          ))}
        </div>
      )}
    </Panel>
  );
}

const PAYROLL_STATUS_CHIP: Record<string, 'info' | 'warning' | 'success' | 'danger'> = {
  draft: 'info',
  processing: 'warning',
  approved: 'info',
  finalized: 'success',
  disbursed: 'success',
  voided: 'danger',
};

function PayrollSummaryPanel({
  data,
}: {
  data: NonNullable<HrDashboardData['panels']['payroll_summary']>;
}) {
  return (
    <Panel
      title="Payroll Snapshot"
      actions={
        <Link className="text-xs text-link hover:underline" to="/payroll/periods">
          Payroll →
        </Link>
      }
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
        <div className="border border-default rounded-md px-3 py-2">
          <div className="text-xs text-muted">Latest Period</div>
          <div className="text-sm font-medium mt-0.5">
            {data.latest_period ? data.latest_period.label : '—'}
          </div>
          {data.latest_period && (
            <div className="mt-1">
              <Chip variant={PAYROLL_STATUS_CHIP[data.latest_period.status] ?? 'info'}>
                {data.latest_period.status}
              </Chip>
            </div>
          )}
        </div>
        <div className="border border-default rounded-md px-3 py-2">
          <div className="text-xs text-muted">Employees on Run</div>
          <div className="text-lg font-mono tabular-nums mt-0.5">{data.employees_on_run}</div>
        </div>
        <div className="border border-default rounded-md px-3 py-2">
          <div className="text-xs text-muted">Net Pay (period)</div>
          <div className="text-lg font-mono tabular-nums mt-0.5">{formatPeso(data.net_pay_total)}</div>
        </div>
        <div className="border border-default rounded-md px-3 py-2">
          <div className="text-xs text-muted">Pending Salary Adjustments</div>
          <div className="text-lg font-mono tabular-nums mt-0.5">
            {data.pending_salary_adjustments > 0 ? (
              <Link to="/hr/salary-adjustments" className="text-link hover:underline">
                {data.pending_salary_adjustments}
              </Link>
            ) : (
              0
            )}
          </div>
        </div>
      </div>
    </Panel>
  );
}
