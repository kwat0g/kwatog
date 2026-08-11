import { Link } from 'react-router-dom';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { DashboardLayoutItem, DashboardWidgetSummary } from '@/api/dashboard-layout';

const WIDGET_LINKS: Record<string, string> = {
  'production.kpi': '/production/dashboard',
  'production.active_wo': '/production/work-orders',
  'production.wo_breakdown': '/production/work-orders',
  'production.gantt_mini': '/production/schedule',
  'machine.utilization': '/production/dashboard',
  'machine.status': '/mrp/machines',
  'oee.gauges': '/production/oee',
  'chain.stage_breakdown': '/chains',
  'qc.pareto': '/quality/dashboard',
  'qc.pending_inspections': '/quality/inspections',
  'qc.open_ncrs': '/quality/ncrs',
  'qc.pass_rate': '/quality/dashboard',
  'mrp.shortages': '/mrp/plans',
  'material.reservations': '/inventory/stock-levels',
  'finance.cash_position': '/accounting/balance-sheet',
  'finance.ar_aging': '/accounting/invoices',
  'finance.ap_aging': '/accounting/bills',
  'finance.revenue_mtd': '/accounting/income-statement',
  'finance.unpaid_invoices': '/accounting/invoices',
  'finance.upcoming_payables': '/accounting/bills',
  'hr.headcount': '/hr/employees',
  'hr.on_leave_today': '/hr/leaves',
  'hr.team_on_leave_today': '/hr/leaves',
  'hr.team_dtr_today': '/hr/attendance',
  'hr.probation_alerts': '/hr/employees',
  'payroll.upcoming': '/payroll/periods',
  'approvals.pending': '/approvals',
  'purchasing.open_prs': '/purchasing/purchase-requests',
  'purchasing.open_pos': '/purchasing/purchase-orders',
  'purchasing.supplier_perf': '/purchasing/approved-suppliers',
  'supply.overdue_deliveries': '/supply-chain/deliveries',
  'supply.delivery_schedule': '/supply-chain/deliveries',
  'inventory.low_stock': '/inventory/stock-levels',
  'inventory.pending_grns': '/inventory/grn',
  'inventory.pending_issues': '/inventory/material-issues',
  'self.payslip_summary': '/self-service/payslips',
  'self.leave_balance': '/self-service/leaves',
  'self.dtr_today': '/self-service/dtr',
  'self.pending_requests': '/self-service',
  // Forecast tiles summarise a projection; the bespoke role dashboards
  // carry the full historical + forecast chart, so "Open →" goes there.
  'forecast.headcount': '/dashboard/hr',
  'forecast.revenue': '/dashboard/finance',
  'forecast.defect_rate': '/dashboard/quality',
  'maintenance.open_wos': '/maintenance/work-orders',
  'maintenance.due_schedules': '/maintenance/schedules',
  'assets.under_maintenance': '/assets',
  'rma.open_returns': '/return-management',
  'rma.pending_approval': '/return-management',
  'crm.open_complaints': '/crm/complaints',
  'budget.utilization': '/budgeting/budget-vs-actual',
  'loans.outstanding': '/hr/loans',
  alerts: '/alerts',
};

function formatValue(summary: DashboardWidgetSummary): string {
  if (summary.value === null) return '—';
  if (summary.kind === 'currency') return formatPeso(summary.value);
  if (summary.kind === 'percent') return `${Number(summary.value).toFixed(1)}%`;
  if (summary.kind === 'hours') return `${Number(summary.value).toFixed(2)} h`;
  if (summary.kind === 'date') return formatDate(summary.value);
  if (summary.kind === 'decimal')
    return Number(summary.value).toLocaleString(undefined, { maximumFractionDigits: 2 });
  return Number(summary.value).toLocaleString();
}

export function LiveDashboardWidget({
  widget,
  summary,
  loading,
}: {
  widget: DashboardLayoutItem;
  summary?: DashboardWidgetSummary;
  loading: boolean;
}) {
  const href = WIDGET_LINKS[widget.key];

  return (
    <Panel
      title={widget.name}
      actions={
        href ? (
          <Link to={href} className="text-xs text-link hover:underline">
            Open →
          </Link>
        ) : undefined
      }
    >
      {loading ? (
        <div className="space-y-2">
          <SkeletonBlock className="h-8 w-28 rounded" />
          <SkeletonBlock className="h-4 w-44 rounded" />
        </div>
      ) : !summary || !summary.available ? (
        <EmptyState
          size="compact"
          icon="alert-circle"
          title="Live data unavailable"
          description={summary?.helper ?? 'This widget has no live response.'}
        />
      ) : (
        <div className="space-y-1.5">
          <div className="text-2xl font-mono tabular-nums font-medium text-primary">
            {formatValue(summary)}
          </div>
          {summary.helper && <p className="text-xs text-muted">{summary.helper}</p>}
          <p className="text-2xs text-subtle">
            Updated {new Date(summary.updated_at).toLocaleTimeString()}
          </p>
        </div>
      )}
    </Panel>
  );
}
