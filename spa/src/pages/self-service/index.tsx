/**
 * Self-service home.
 *
 * Sprint 8 — Task 74. Web dashboard layout: KPI stat cards up top, then a
 * two-column grid with quick actions and leave balances — same design
 * language as the role dashboards.
 *
 * Data sources:
 * GET /api/v1/hr/self-service/home (greeting, shift, balances, payslip)
 * GET /api/v1/dashboards/employee (KPI cards, next holiday — 30s Redis)
 */
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import {
  LuCalendar,
  LuFileText,
  LuReceipt,
  LuClock,
  LuFolderOpen,
  LuWallet,
  LuArrowRight,
  LuCalendarDays,
} from '@/lib/icons';
import { dashboardsApi } from '@/api/dashboards';
import { selfServiceApi } from '@/api/self-service';
import { useAuthStore } from '@/stores/authStore';
import { useFeature } from '@/hooks/useFeature';
import { usePermission } from '@/hooks/usePermission';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate, formatDateFull } from '@/lib/formatDate';
import type { SelfServiceHome } from '@/types/self-service';

/* ───────────────────────── Typed interface ───────────────────────── */

interface EmployeeKpi {
  label: string;
  value: string;
  unit: string;
}

interface EmployeeDashboardData {
  kpis: EmployeeKpi[];
  panels: {
    latest_payslip: { gross_pay?: string; net_pay?: string } | null;
    next_holiday: { name?: string; date?: string } | null;
    notice?: string;
  };
}

/* ───────────────────────── Page component ───────────────────────── */

export default function SelfServiceHomePage() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const hasEmployeeLink = Boolean(user?.employee?.id);

  const homeQuery = useQuery({
    queryKey: ['self-service', 'home'],
    queryFn: () => selfServiceApi.home(),
    enabled: hasEmployeeLink,
    staleTime: 60_000,
  });

  const dashboardQuery = useQuery({
    queryKey: ['self-service', 'dashboard'],
    queryFn: () => dashboardsApi.employee(),
    placeholderData: (prev) => prev,
    refetchInterval: 60_000,
  });

  const home = homeQuery.data ?? null;
  const dashboard = (dashboardQuery.data ?? null) as EmployeeDashboardData | null;

  const isLoading =
    (homeQuery.isLoading && hasEmployeeLink) || (dashboardQuery.isLoading && !dashboard);
  const isError = dashboardQuery.isError && !dashboard;

  /* ─── LOADING ─── */
  if (isLoading) {
    return (
      <div>
        <PageHeader title="My Dashboard" subtitle="Loading your summary…" />
        <div className="px-5 py-4 space-y-4" aria-label="Loading dashboard">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            {[1, 2, 3].map((i) => (
              <SkeletonBlock key={i} className="h-[92px] rounded-md" />
            ))}
          </div>
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <SkeletonBlock className="h-72 rounded-md lg:col-span-2" />
            <SkeletonBlock className="h-72 rounded-md" />
          </div>
        </div>
      </div>
    );
  }

  /* ─── ERROR ─── */
  if (isError) {
    return (
      <div>
        <PageHeader title="My Dashboard" />
        <div className="px-5 py-4">
          <EmptyState
            icon="alert-circle"
            title="Couldn't load your dashboard"
            description="Something went wrong while loading your data. Please try again."
            action={
              <Button variant="secondary" onClick={() => dashboardQuery.refetch()}>
                Retry
              </Button>
            }
          />
        </div>
      </div>
    );
  }

  const kpis = dashboard?.kpis ?? [];
  const nextHoliday = dashboard?.panels?.next_holiday ?? null;
  const notice = dashboard?.panels?.notice;
  const latestPayslip = home?.latest_payslip ?? null;
  const leaveBalances = home?.leave_balances ?? [];
  const shift = home?.todays_shift ?? null;

  const title = home ? `${home.greeting}, ${home.employee.first_name}` : 'My Dashboard';
  const subtitleParts = [
    home ? formatDateFull(home.today) : null,
    shift
      ? `${shift.name} shift · ${String(shift.time_in).slice(0, 5)}–${String(shift.time_out).slice(0, 5)}`
      : null,
    home?.employee.department ?? null,
  ].filter(Boolean);

  return (
    <div>
      <PageHeader
        title={title}
        subtitle={
          subtitleParts.join(' · ') || 'Your payslips, attendance, leave, and requests in one place'
        }
        actions={
          <Button variant="secondary" size="sm" onClick={() => navigate('/self-service/profile')}>
            My profile
          </Button>
        }
      />

      <div className="px-5 py-4 space-y-4">
        {notice && (
          <div
            className="rounded-md border border-default bg-subtle px-3 py-2 text-sm text-muted"
            role="status"
          >
            {notice}
          </div>
        )}

        {/* KPI stat cards */}
        {kpis.length > 0 && (
          <section
            className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3"
            aria-label="Your summary"
          >
            {kpis.map((k) => (
              <StatCard key={k.label} label={k.label} value={k.value} helper={k.unit} />
            ))}
          </section>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
          {/* Quick actions — 2/3 */}
          <QuickActionsPanel
            payslipSubtitle={
              latestPayslip
                ? `Net ${formatPeso(latestPayslip.net_pay)} · ${formatDate(latestPayslip.period_start)} – ${formatDate(latestPayslip.period_end)}`
                : 'View payslip history'
            }
          />

          {/* Right rail — 1/3 */}
          <div className="space-y-4">
            <LatestPayslipPanel payslip={latestPayslip} />
            {leaveBalances.length > 0 && (
              <LeaveBalancesPanel balances={leaveBalances} policy={home?.leave_balance_policy} />
            )}
            {nextHoliday?.name && (
              <NextHolidayPanel name={nextHoliday.name} date={nextHoliday.date} />
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

/* ───────────────────────── Quick actions ───────────────────────── */

function QuickActionsPanel({ payslipSubtitle }: { payslipSubtitle: string }) {
  const { can } = usePermission();
  const attendance = useFeature('attendance');
  const leave = useFeature('leave');
  const loans = useFeature('loans');
  const payroll = useFeature('payroll');

  const actions = [
    payroll &&
      can('payroll.view') && {
        to: '/self-service/payslips',
        Icon: LuReceipt,
        title: 'Payslips',
        subtitle: payslipSubtitle,
      },
    leave && {
      to: '/self-service/leave',
      Icon: LuFileText,
      title: 'File a leave request',
      subtitle: 'Request leave or check approval status',
    },
    attendance && {
      to: '/self-service/overtime',
      Icon: LuClock,
      title: 'Apply for overtime',
      subtitle: 'Request OT and track approval',
    },
    attendance && {
      to: '/self-service/dtr',
      Icon: LuCalendar,
      title: 'My attendance',
      subtitle: 'Daily time record this month',
    },
    loans && {
      to: '/self-service/loans',
      Icon: LuWallet,
      title: 'Loans & cash advances',
      subtitle: 'Check balances or apply for a loan',
    },
    {
      to: '/self-service/documents',
      Icon: LuFolderOpen,
      title: 'My documents',
      subtitle: 'Employment certificate, contributions, BIR 2316',
    },
  ].filter((a): a is { to: string; Icon: typeof LuReceipt; title: string; subtitle: string } =>
    Boolean(a),
  );

  return (
    <Panel title="Quick actions" meta={`${actions.length} shortcuts`} className="lg:col-span-2">
      <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2" aria-label="Quick actions">
        {actions.map(({ to, Icon, title, subtitle }) => (
          <li key={to}>
            <Link
              to={to}
              aria-label={`${title}: ${subtitle}`}
              className="group flex items-start gap-3 rounded-md border border-default bg-canvas px-3 py-3 h-full hover:bg-subtle hover:border-strong transition-colors duration-fast"
            >
              <span
                className="w-8 h-8 rounded-md bg-subtle flex items-center justify-center text-muted shrink-0 group-hover:bg-elevated group-hover:text-primary transition-colors duration-fast"
                aria-hidden="true"
              >
                <Icon size={16} />
              </span>
              <span className="flex-1 min-w-0">
                <span className="block text-sm font-medium text-primary">{title}</span>
                <span className="block text-xs text-muted mt-0.5 line-clamp-2">{subtitle}</span>
              </span>
              <LuArrowRight
                size={14}
                className="text-text-subtle shrink-0 mt-1 opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-[opacity,transform] duration-fast"
                aria-hidden="true"
              />
            </Link>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

/* ───────────────────────── Right rail panels ───────────────────────── */

function LatestPayslipPanel({ payslip }: { payslip: SelfServiceHome['latest_payslip'] }) {
  const { can } = usePermission();
  const payroll = useFeature('payroll');
  if (!payroll || !can('payroll.view')) return null;

  return (
    <Panel
      title="Latest payslip"
      actions={
        <Link to="/self-service/payslips" className="text-xs text-link hover:underline">
          View all →
        </Link>
      }
    >
      {payslip ? (
        <div>
          <div className="text-2xs uppercase tracking-wider text-text-subtle font-medium mb-1.5">
            Net pay · {formatDate(payslip.period_start)} – {formatDate(payslip.period_end)}
          </div>
          <div className="text-2xl font-medium font-mono tabular-nums text-primary leading-tight">
            {formatPeso(payslip.net_pay)}
          </div>
          <div className="text-xs text-muted mt-1">
            Gross <span className="font-mono tabular-nums">{formatPeso(payslip.gross_pay)}</span>
          </div>
        </div>
      ) : (
        <EmptyState
          size="compact"
          icon="receipt"
          title="No payslip yet"
          description="Your payslip appears here after the next payroll run."
        />
      )}
    </Panel>
  );
}

function LeaveBalancesPanel({
  balances,
  policy,
}: {
  balances: SelfServiceHome['leave_balances'];
  policy?: SelfServiceHome['leave_balance_policy'];
}) {
  const warningRatio = policy?.warning_ratio;
  const criticalRatio = policy?.critical_ratio;
  return (
    <Panel
      title="Leave balances"
      meta={`${balances.length} types`}
      actions={
        <Link to="/self-service/leave" className="text-xs text-link hover:underline">
          File leave →
        </Link>
      }
      noPadding
    >
      <div className="divide-y divide-subtle" aria-label="Leave balances">
        {balances.map((b) => {
          const pct = b.total > 0 ? Math.min(100, (b.remaining / b.total) * 100) : 0;
          return (
            <div key={b.code} className="px-4 py-3">
              <div className="flex items-baseline justify-between mb-1.5">
                <span className="text-sm text-primary">{b.name}</span>
                <span className="text-xs font-mono tabular-nums text-muted">
                  {b.remaining} / {b.total} days
                </span>
              </div>
              <div
                className="h-1.5 rounded-full bg-subtle overflow-hidden"
                role="progressbar"
                aria-valuenow={b.remaining}
                aria-valuemin={0}
                aria-valuemax={b.total}
                aria-label={`${b.name}: ${b.remaining} of ${b.total} days remaining`}
              >
                <div
                  className={`h-full rounded-full transition-[width] duration-500 ${
                    criticalRatio !== undefined && warningRatio !== undefined
                      ? pct <= criticalRatio * 100
                        ? 'bg-danger-bg'
                        : pct <= warningRatio * 100
                          ? 'bg-warning-bg'
                          : 'bg-accent'
                      : 'bg-accent'
                  }`}
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>
    </Panel>
  );
}

function NextHolidayPanel({ name, date }: { name: string; date?: string }) {
  return (
    <Panel title="Next holiday">
      <div className="flex items-center gap-3">
        <span
          className="w-8 h-8 rounded-md bg-subtle flex items-center justify-center text-muted shrink-0"
          aria-hidden="true"
        >
          <LuCalendarDays size={16} />
        </span>
        <div className="min-w-0">
          <div className="text-sm font-medium text-primary truncate">{name}</div>
          {date && (
            <div className="text-xs text-muted font-mono tabular-nums">{formatDate(date)}</div>
          )}
        </div>
      </div>
    </Panel>
  );
}
