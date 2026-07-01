/**
 * Self-service home (mobile-first).
 *
 * Sprint 8 — Task 74. Upgraded to match D2/D4/D5 quality standards: typed
 * interface, shared API client, auto-refresh, and aria attributes.
 *
 * Data source: GET /api/v1/dashboards/employee (via dashboardsApi.employee)
 * Backend:     RoleDashboardService::employee()
 * Cache:       30s Redis per user
 * Layout:      Full-width SPA layout with PageHeader
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Calendar, FileText, Receipt, ChevronRight, Clock, FolderOpen } from 'lucide-react';
import { dashboardsApi } from '@/api/dashboards';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';

/* ───────────────────────── Typed interface ───────────────────────── */

interface EmployeeKpi {
  label: string;
  value: string;
  unit: string;
}

interface LatestPayslip {
  gross_pay?: string;
  net_pay?: string;
}

interface NextHoliday {
  name?: string;
  date?: string;
}

interface LeaveBalance {
  code: string;
  name: string;
  total: number;
  used: number;
  remaining: number;
}

interface EmployeeDashboardData {
  kpis: EmployeeKpi[];
  leave_balances: LeaveBalance[];
  panels: {
    latest_payslip: LatestPayslip | null;
    next_holiday: NextHoliday | null;
    notice?: string;
  };
}

/* ───────────────────────── Page component ───────────────────────── */

export default function SelfServiceHome() {
  return (
    <div>
      <PageHeader title="Dashboard" backTo="/self-service" backLabel="Self-service" />
      <SelfServiceContent />
    </div>
  );
}

function SelfServiceContent() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'dashboard'],
    queryFn: () => dashboardsApi.employee(),
    placeholderData: (prev) => prev,
    refetchInterval: 60_000,
  });

  /* ─── LOADING ─── */
  if (isLoading && !data) {
    return (
      <div className="px-5 py-4 space-y-3" aria-label="Loading dashboard">
        {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-20 rounded-md" />)}
      </div>
    );
  }

  /* ─── ERROR ─── */
  if (isError || !data) {
    return (
      <div className="px-5 py-6">
        <EmptyState
          icon="alert-circle"
          title="Couldn't load your dashboard"
          description="Pull-to-refresh or tap retry."
          action={
            <button
              onClick={() => refetch()}
              className="h-8 px-3 rounded-md border border-default text-sm hover:bg-elevated"
            >
              Retry
            </button>
          }
        />
      </div>
    );
  }

  const raw = data as unknown as EmployeeDashboardData;
  const kpis = raw.kpis ?? [];
  const latestPayslip = raw.panels?.latest_payslip ?? null;
  const nextHoliday = raw.panels?.next_holiday ?? null;
  const notice = raw.panels?.notice;

  /* ─── DATA ─── */
  return (
    <div className="px-5 py-4 space-y-4">
      {notice && (
        <div
          className="rounded-md border border-default bg-subtle px-3 py-2 text-sm text-muted"
          role="status"
        >
          {notice}
        </div>
      )}

      {/* KPI tiles */}
      <section className="grid grid-cols-3 gap-2" aria-label="Your summary">
        {kpis.map((k) => (
          <div
            key={k.label}
            className="rounded-md border border-default bg-surface p-2.5"
            aria-label={`${k.label}: ${k.value} ${k.unit}`}
          >
            <div className="text-[10px] uppercase tracking-wider text-muted font-medium">
              {k.label}
            </div>
            <div className="text-base font-medium font-mono tabular-nums mt-1 text-primary">
              {k.value}
            </div>
            <div className="text-[10px] text-muted">{k.unit}</div>
          </div>
        ))}
      </section>

      {/* Quick actions */}
      <section className="space-y-2" aria-label="Quick actions">
        <QuickAction
          to="/self-service/payslips"
          Icon={Receipt}
          title="Latest payslip"
          subtitle={
            latestPayslip
              ? `Net ₱${latestPayslip.net_pay ?? '0.00'} · Gross ₱${latestPayslip.gross_pay ?? '0.00'}`
              : 'Tap to view payslip history'
          }
        />
        <QuickAction
          to="/self-service/leave"
          Icon={FileText}
          title="File a leave request"
          subtitle="Request leave or check approval status"
        />
        <QuickAction
          to="/self-service/overtime"
          Icon={Clock}
          title="Apply for overtime"
          subtitle="Request OT and track approval"
        />
        <QuickAction
          to="/self-service/dtr"
          Icon={Calendar}
          title="My attendance"
          subtitle={
            nextHoliday
              ? `Next holiday: ${nextHoliday.name} (${nextHoliday.date})`
              : 'Daily time record this month'
          }
        />
        <QuickAction
          to="/self-service/documents"
          Icon={FolderOpen}
          title="My documents"
          subtitle="Employment certificate, contributions, BIR 2316"
        />
      </section>

      {/* Leave balances */}
      {raw.leave_balances && raw.leave_balances.length > 0 && (
        <LeaveBalances balances={raw.leave_balances} />
      )}
    </div>
  );
}

/* ───────────────────────── Leave balances component ───────────────────────── */

function LeaveBalances({ balances }: { balances: LeaveBalance[] }) {
  if (!balances.length) return null;
  return (
    <section aria-label="Leave balances">
      <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
        Leave balances
      </div>
      <div className="rounded-md border border-default bg-canvas divide-y divide-subtle">
        {balances.map((b) => {
          const pct = b.total > 0 ? Math.min(100, (b.remaining / b.total) * 100) : 0;
          return (
            <div key={b.code} className="px-3 py-2.5">
              <div className="flex items-baseline justify-between mb-1.5">
                <span className="text-sm">{b.name}</span>
                <span className="text-xs font-mono tabular-nums text-muted">
                  {b.remaining} / {b.total} days
                </span>
              </div>
              <div className="h-1.5 rounded-full bg-subtle overflow-hidden" aria-hidden="true">
                <div
                  className={`h-full rounded-full transition-[width] duration-500 ${
                    pct <= 20 ? 'bg-danger' : pct <= 50 ? 'bg-warning' : 'bg-accent'
                  }`}
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}

/* ───────────────────────── Quick action link component ───────────────────────── */

function QuickAction({
  to, Icon, title, subtitle,
}: { to: string; Icon: typeof Receipt; title: string; subtitle: string }) {
  return (
    <Link
      to={to}
      className="flex items-center gap-3 rounded-md border border-default bg-canvas px-3 py-3 hover:bg-elevated transition-colors duration-fast"
      aria-label={`${title}: ${subtitle}`}
    >
      <span className="w-9 h-9 rounded-md bg-subtle flex items-center justify-center text-muted" aria-hidden="true">
        <Icon size={18} />
      </span>
      <span className="flex-1 min-w-0">
        <span className="block text-sm font-medium text-primary truncate">{title}</span>
        <span className="block text-xs text-muted truncate">{subtitle}</span>
      </span>
      <ChevronRight size={16} className="text-subtle shrink-0" aria-hidden="true" />
    </Link>
  );
}
