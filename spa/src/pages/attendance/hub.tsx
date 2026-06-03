/**
 * S1 — Attendance & Leave Hub
 *
 * Supporting feature hub. Each tab shows real inline data so HR and
 * managers get immediate value without extra navigation.
 */
import { useState } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { attendancesApi } from '@/api/attendance/attendances';
import { leaveRequestsApi } from '@/api/leave';
import { overtimeApi } from '@/api/attendance/overtime';
import { shiftsApi } from '@/api/attendance/shifts';
import { holidaysApi } from '@/api/attendance/holidays';
import { PageHeader } from '@/components/layout/PageHeader';
import { Input } from '@/components/ui/Input';
import { TabNavigation, type Tab } from '@/components/ui/TabNavigation';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { formatDate } from '@/lib/formatDate';

const TABS: Tab[] = [
  { key: 'attendance', label: 'Attendance', to: '/hr/attendance/hub?tab=attendance' },
  { key: 'overtime', label: 'Overtime', to: '/hr/attendance/hub?tab=overtime' },
  { key: 'shifts', label: 'Shifts', to: '/hr/attendance/hub?tab=shifts' },
  { key: 'holidays', label: 'Holidays', to: '/hr/attendance/hub?tab=holidays' },
  { key: 'leaves', label: 'Leave', to: '/hr/attendance/hub?tab=leaves' },
];

/** ── Quick-action buttons shown at the top of the hub ── */
function QuickActions() {
  const quickLinks = [
    { label: 'Daily Records',   to: '/hr/attendance',            icon: '📋' },
    { label: 'Overtime',        to: '/hr/attendance/overtime',   icon: '⏰' },
    { label: 'Shifts',          to: '/hr/attendance/shifts',     icon: '🔄' },
    { label: 'Holidays',        to: '/hr/attendance/holidays',   icon: '🎉' },
    { label: 'Leave Mgmt',      to: '/hr/leaves',                icon: '📅' },
    { label: 'Import DTR',      to: '/hr/attendance/import',     icon: '📤' },
  ];
  return (
    <div className="px-5 pt-4 pb-2">
      <div className="flex items-center gap-2 flex-wrap">
        {quickLinks.map((link) => (
          <Link
            key={link.to}
            to={link.to}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md border border-default bg-canvas text-secondary hover:bg-elevated hover:text-primary hover:border-accent transition-all duration-fast"
          >
            <span aria-hidden>{link.icon}</span>
            {link.label}
          </Link>
        ))}
      </div>
    </div>
  );
}

export default function AttendanceHubPage() {
  const [searchParams] = useSearchParams();
  const activeTab = searchParams.get('tab') ?? 'attendance';

  return (
    <div>
      <PageHeader
        title="Attendance & Leave"
        subtitle="Time Management"
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Attendance & Leave' },
        ]}
      />
      <QuickActions />
      <TabNavigation tabs={TABS} defaultKey="attendance" />
      <div className="px-5 py-4">
        {activeTab === 'attendance' && <AttendanceTab />}
        {activeTab === 'overtime' && <OvertimeTab />}
        {activeTab === 'shifts' && <ShiftsTab />}
        {activeTab === 'holidays' && <HolidaysTab />}
        {activeTab === 'leaves' && <LeaveTab />}
      </div>
    </div>
  );
}

/* ─── Attendance Tab ───────────────────────────────────── */

function AttendanceTab() {
  const today = new Date().toISOString().slice(0, 10);
  const [fromDate, setFromDate] = useState(today);
  const [toDate, setToDate] = useState(today);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance-hub', 'range', fromDate, toDate],
    queryFn: () => attendancesApi.list({ from: fromDate, to: toDate, per_page: 50 }),
    placeholderData: (prev) => prev,
    retry: false,
  });

  // Quick-select helpers
  const selectToday = () => {
    const d = new Date().toISOString().slice(0, 10);
    setFromDate(d);
    setToDate(d);
  };

  const selectThisWeek = () => {
    const now = new Date();
    const day = now.getDay(); // 0=Sun, 1=Mon …
    const monday = new Date(now);
    monday.setDate(now.getDate() - ((day + 6) % 7)); // go back to Monday
    const sunday = new Date(monday);
    sunday.setDate(monday.getDate() + 6);
    setFromDate(monday.toISOString().slice(0, 10));
    setToDate(sunday.toISOString().slice(0, 10));
  };

  const selectThisMonth = () => {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    setFromDate(first.toISOString().slice(0, 10));
    setToDate(last.toISOString().slice(0, 10));
  };

  const selectThisPayPeriod = () => {
    const now = new Date();
    const day = now.getDate();
    const year = now.getFullYear();
    const month = now.getMonth();
    if (day <= 15) {
      // 1st half: 1st — 15th
      const first = new Date(year, month, 1);
      const last = new Date(year, month, 15);
      setFromDate(first.toISOString().slice(0, 10));
      setToDate(last.toISOString().slice(0, 10));
    } else {
      // 2nd half: 16th — last day of month
      const first = new Date(year, month, 16);
      const last = new Date(year, month + 1, 0);
      setFromDate(first.toISOString().slice(0, 10));
      setToDate(last.toISOString().slice(0, 10));
    }
  };

  const rangeLabel =
    fromDate === toDate
      ? formatDate(fromDate)
      : `${formatDate(fromDate)} — ${formatDate(toDate)}`;

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const records = data?.data ?? [];
  const present = records.filter((r: any) => r.status === 'present' || r.time_in);
  const absent = records.filter((r: any) => r.status === 'absent');
  const late = records.filter((r: any) => r.status === 'late');
  const onLeave = records.filter((r: any) => r.status === 'on_leave');
  const totalEmployees = new Set(records.map((r: any) => r.employee?.id).filter(Boolean)).size;

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load attendance" description={`Unable to fetch data for ${rangeLabel}.`}
          action={<Link to="/hr/attendance" className="text-sm text-accent hover:underline">Go to attendance →</Link>} />
      ) : (
        <>
          {/* Date range picker */}
          <div className="flex items-end gap-3 flex-wrap">
            <div className="flex items-end gap-2">
              <Input
                label="From"
                type="date"
                value={fromDate}
                max={today}
                onChange={(e) => setFromDate(e.target.value)}
                className="font-mono w-36"
              />
              <span className="text-xs text-muted pb-2">→</span>
              <Input
                label="To"
                type="date"
                value={toDate}
                max={today}
                onChange={(e) => setToDate(e.target.value)}
                className="font-mono w-36"
              />
            </div>
            <div className="flex items-center gap-1 pb-[1px]">
              <button
                onClick={selectToday}
                className="px-2 py-1 text-xs rounded border border-default hover:bg-elevated transition-colors text-muted hover:text-primary"
              >
                Today
              </button>
              <button
                onClick={selectThisWeek}
                className="px-2 py-1 text-xs rounded border border-default hover:bg-elevated transition-colors text-muted hover:text-primary"
              >
                This Week
              </button>
              <button
                onClick={selectThisMonth}
                className="px-2 py-1 text-xs rounded border border-default hover:bg-elevated transition-colors text-muted hover:text-primary"
              >
                This Month
              </button>
              <button
                onClick={selectThisPayPeriod}
                className="px-2 py-1 text-xs rounded border border-default hover:bg-elevated transition-colors text-muted hover:text-primary"
              >
                This Pay Period
              </button>
            </div>
          </div>

          {/* Stat cards */}
          <div className="grid grid-cols-5 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Present</p>
              <p className="text-2xl font-semibold mt-1">{present.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Absent</p>
              <p className="text-2xl font-semibold mt-1">{absent.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Late</p>
              <p className="text-2xl font-semibold mt-1">{late.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">On Leave</p>
              <p className="text-2xl font-semibold mt-1">{onLeave.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Tracked</p>
              <p className="text-2xl font-semibold mt-1">{totalEmployees}</p>
            </div>
          </div>
          <div className="flex gap-3">
            <Link to="/hr/attendance" className="text-sm text-accent hover:underline">View full attendance →</Link>
            <Link to="/hr/attendance/import" className="text-sm text-accent hover:underline">Import DTR →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Overtime Tab ─────────────────────────────────────── */

function OvertimeTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance-hub', 'overtime-pending'],
    queryFn: () => overtimeApi.list({ status: 'pending', per_page: 10, sort: 'date', direction: 'asc' }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const pending = data?.data ?? [];

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load overtime requests"
          action={<Link to="/hr/attendance/overtime" className="text-sm text-accent hover:underline">Go to overtime →</Link>} />
      ) : pending.length === 0 ? (
        <EmptyState icon="clock" title="No pending overtime requests" description="All OT requests have been processed."
          action={<Link to="/hr/attendance/overtime" className="text-sm text-accent hover:underline">View all overtime →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Pending</p>
              <p className="text-2xl font-semibold mt-1">{pending.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Hours</p>
              <p className="text-2xl font-semibold mt-1">
                {pending.reduce((sum: number, r: any) => sum + Number(r.hours_requested ?? 0), 0)}
              </p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Employees</p>
              <p className="text-2xl font-semibold mt-1">
                {new Set(pending.map((r: any) => r.employee?.id).filter(Boolean)).size}
              </p>
            </div>
          </div>
          <Panel title="Pending Overtime Requests" actions={<Link to="/hr/attendance/overtime" className="text-sm text-accent hover:underline">View all →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Employee</th>
                    <th className="py-2 pr-3 font-medium">Date</th>
                    <th className="py-2 pr-3 font-medium">Hours</th>
                    <th className="py-2 pr-3 font-medium">Reason</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {pending.slice(0, 10).map((r: any) => (
                    <tr key={r.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3">
                        <Link to={`/hr/attendance/overtime`} className="text-accent hover:underline font-medium">
                          {r.employee?.full_name ?? '—'}
                        </Link>
                      </td>
                      <td className="py-2 pr-3 font-mono text-xs text-secondary">{formatDate(r.date)}</td>
                      <td className="py-2 pr-3 font-mono tabular-nums">{r.hours_requested}h</td>
                      <td className="py-2 pr-3 max-w-[200px]">
                        <span className="text-xs text-muted truncate block" title={r.reason}>
                          {r.reason}
                        </span>
                      </td>
                      <td className="py-2">
                        <Chip variant="warning">Pending</Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/hr/attendance/overtime" className="text-sm text-accent hover:underline">View all overtime →</Link>
            <Link to="/hr/attendance/overtime/create" className="text-sm text-accent hover:underline">New overtime request →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Shifts Tab ───────────────────────────────────────── */

function ShiftsTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance-hub', 'shifts'],
    queryFn: () => shiftsApi.list({ per_page: 50 }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const shifts = data?.data ?? [];
  const active = shifts.filter((s: any) => s.is_active);
  const night = active.filter((s: any) => s.is_night_shift);
  const extended = active.filter((s: any) => s.is_extended);

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load shifts"
          action={<Link to="/hr/attendance/shifts" className="text-sm text-accent hover:underline">Go to shifts →</Link>} />
      ) : shifts.length === 0 ? (
        <EmptyState icon="calendar" title="No shifts configured" description="Add a shift to get started."
          action={<Link to="/hr/attendance/shifts" className="text-sm text-accent hover:underline">Manage shifts →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-4 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Shifts</p>
              <p className="text-2xl font-semibold mt-1">{shifts.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Active</p>
              <p className="text-2xl font-semibold mt-1">{active.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Night Shifts</p>
              <p className="text-2xl font-semibold mt-1">{night.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Auto-OT</p>
              <p className="text-2xl font-semibold mt-1">{extended.length}</p>
            </div>
          </div>
          <Panel title="Shift Schedules" actions={<Link to="/hr/attendance/shifts" className="text-sm text-accent hover:underline">Manage →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 font-medium">Time</th>
                    <th className="py-2 pr-3 font-medium">Break</th>
                    <th className="py-2 pr-3 font-medium">Type</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {shifts.slice(0, 10).map((s: any) => (
                    <tr key={s.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3 font-medium">{s.name}</td>
                      <td className="py-2 pr-3 font-mono text-xs text-secondary">
                        {s.start_time} — {s.end_time}
                      </td>
                      <td className="py-2 pr-3 font-mono text-xs">{s.break_minutes} min</td>
                      <td className="py-2 pr-3">
                        <div className="flex gap-1 flex-wrap">
                          {s.is_night_shift && <Chip variant="info">Night</Chip>}
                          {s.is_extended && <Chip variant="warning">Auto-OT {s.auto_ot_hours}h</Chip>}
                          {!s.is_night_shift && !s.is_extended && (
                            <Chip variant="neutral">Standard</Chip>
                          )}
                        </div>
                      </td>
                      <td className="py-2">
                        <Chip variant={s.is_active ? 'success' : 'neutral'}>
                          {s.is_active ? 'Active' : 'Inactive'}
                        </Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/hr/attendance/shifts" className="text-sm text-accent hover:underline">Manage shifts →</Link>
            <Link to="/hr/attendance/shifts/assign" className="text-sm text-accent hover:underline">Bulk assign →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Holidays Tab ─────────────────────────────────────── */

function HolidaysTab() {
  const year = new Date().getFullYear();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance-hub', 'holidays', year],
    queryFn: () => holidaysApi.list({ year, per_page: 200 }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const holidays = data?.data ?? [];
  const today = new Date().toISOString().slice(0, 10);
  const upcoming = holidays
    .filter((h: any) => h.date >= today)
    .sort((a: any, b: any) => a.date.localeCompare(b.date))
    .slice(0, 10);

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load holidays"
          action={<Link to="/hr/attendance/holidays" className="text-sm text-accent hover:underline">Go to holidays →</Link>} />
      ) : holidays.length === 0 ? (
        <EmptyState icon="calendar" title={`No holidays configured for ${year}`} description="Add holidays to see them here."
          action={<Link to="/hr/attendance/holidays" className="text-sm text-accent hover:underline">Manage holidays →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Holidays</p>
              <p className="text-2xl font-semibold mt-1">{holidays.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Regular</p>
              <p className="text-2xl font-semibold mt-1">{holidays.filter((h: any) => h.type === 'regular').length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Upcoming</p>
              <p className="text-2xl font-semibold mt-1">{upcoming.length}</p>
            </div>
          </div>
          <Panel title="Upcoming Holidays" actions={<Link to="/hr/attendance/holidays" className="text-sm text-accent hover:underline">Manage →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Date</th>
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 font-medium">Type</th>
                    <th className="py-2 font-medium">Recurring</th>
                  </tr>
                </thead>
                <tbody>
                  {upcoming.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="py-6 text-center text-sm text-muted">No more holidays this year.</td>
                    </tr>
                  ) : (
                    upcoming.map((h: any) => (
                      <tr key={h.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                        <td className="py-2 pr-3 font-mono text-xs text-secondary">{formatDate(h.date)}</td>
                        <td className="py-2 pr-3 font-medium">{h.name}</td>
                        <td className="py-2 pr-3">
                          <Chip variant={h.type === 'regular' ? 'warning' : 'info'}>
                            {h.type === 'regular' ? 'Regular' : h.type === 'special_non_working' ? 'Special non-working' : h.type}
                          </Chip>
                        </td>
                        <td className="py-2">
                          {h.is_recurring ? <Chip variant="neutral">Annually</Chip> : <span className="text-xs text-text-subtle">One-off</span>}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/hr/attendance/holidays" className="text-sm text-accent hover:underline">View all holidays →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Leave Tab ────────────────────────────────────────── */

function LeaveTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance-hub', 'leaves-pending'],
    queryFn: () => leaveRequestsApi.list({ status: 'pending', per_page: 10 }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load leave requests"
          action={<Link to="/hr/leaves" className="text-sm text-accent hover:underline">Go to leave management →</Link>} />
      ) : !data?.data?.length ? (
        <EmptyState icon="calendar" title="No pending leave requests" description="All leave requests have been processed."
          action={<Link to="/hr/leaves" className="text-sm text-accent hover:underline">View all leaves →</Link>} />
      ) : (
        <>
          <Panel title="Pending Leave Requests" actions={<Link to="/hr/leaves" className="text-sm text-accent hover:underline">View all →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Employee</th>
                    <th className="py-2 pr-3 font-medium">Type</th>
                    <th className="py-2 pr-3 font-medium">Dates</th>
                    <th className="py-2 pr-3 font-medium">Days</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.slice(0, 10).map((l: any) => (
                    <tr key={l.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3">
                        <Link to={`/hr/leaves/${l.id}`} className="text-accent hover:underline font-medium">
                          {l.employee?.full_name ?? '—'}
                        </Link>
                      </td>
                      <td className="py-2 pr-3">
                        <Chip variant="info" >{l.leave_type?.name ?? l.type ?? 'Leave'}</Chip>
                      </td>
                      <td className="py-2 pr-3 font-mono text-xs text-secondary">
                        {l.start_date?.slice(0, 10)}{l.end_date ? ` — ${l.end_date.slice(0, 10)}` : ''}
                      </td>
                      <td className="py-2 pr-3 font-mono tabular-nums">{Number(l.days ?? 0)}</td>
                      <td className="py-2">
                        <Chip variant="warning" >Pending</Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/hr/leaves" className="text-sm text-accent hover:underline">View all leaves →</Link>
            <Link to="/hr/leaves/create" className="text-sm text-accent hover:underline">New leave request →</Link>
          </div>
        </>
      )}
    </div>
  );
}
