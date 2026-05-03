import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Calendar, FileText, Receipt, ChevronRight } from 'lucide-react';
import { client } from '@/api/client';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';

/**
 * Sprint 8 — Task 74. Self-service home (mobile-first).
 *
 * Hits GET /dashboards/employee which is server-scoped to auth.user.employee_id.
 * Three KPI tiles, one panel per quick action. Designed to fit a 390px viewport
 * without horizontal scroll.
 */
export default function SelfServiceHome() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'dashboard'],
    queryFn: () => client.get<{ data: any }>('/dashboards/employee').then(r => r.data.data),
  });

  if (isLoading) {
    return (
      <div className="px-4 py-4 space-y-3">
        {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-20 rounded-md" />)}
      </div>
    );
  }
  if (isError || !data) {
    return (
      <div className="px-4 py-6">
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

  const kpis: Array<{ label: string; value: string; unit: string }> = data.kpis ?? [];
  const latestPayslip: { gross_pay?: string; net_pay?: string } | null = data.panels?.latest_payslip ?? null;
  const nextHoliday: { name?: string; date?: string } | null = data.panels?.next_holiday ?? null;
  const notice: string | undefined = data.panels?.notice;

  return (
    <div className="px-4 py-4 space-y-4">
      {notice && (
        <div className="rounded-md border border-default bg-subtle px-3 py-2 text-sm text-muted">
          {notice}
        </div>
      )}

      {/* KPI tiles */}
      <section className="grid grid-cols-3 gap-2">
        {kpis.map((k) => (
          <div key={k.label} className="rounded-md border border-default bg-surface p-2.5">
            <div className="text-[10px] uppercase tracking-wider text-muted font-medium">{k.label}</div>
            <div className="text-base font-medium font-mono tabular-nums mt-1 text-primary">{k.value}</div>
            <div className="text-[10px] text-muted">{k.unit}</div>
          </div>
        ))}
      </section>

      {/* Quick actions */}
      <section className="space-y-2">
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
          to="/self-service/dtr"
          Icon={Calendar}
          title="My attendance"
          subtitle={nextHoliday ? `Next holiday: ${nextHoliday.name} (${nextHoliday.date})` : 'Daily time record this month'}
        />
      </section>
    </div>
  );
}

function QuickAction({
  to, Icon, title, subtitle,
}: { to: string; Icon: typeof Receipt; title: string; subtitle: string }) {
  return (
    <Link
      to={to}
      className="flex items-center gap-3 rounded-md border border-default bg-canvas px-3 py-3 hover:bg-elevated"
    >
      <span className="w-9 h-9 rounded-md bg-subtle flex items-center justify-center text-muted">
        <Icon size={18} />
      </span>
      <span className="flex-1 min-w-0">
        <span className="block text-sm font-medium text-primary truncate">{title}</span>
        <span className="block text-xs text-muted truncate">{subtitle}</span>
      </span>
      <ChevronRight size={16} className="text-subtle" />
    </Link>
  );
}
