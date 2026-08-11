import { Link } from 'react-router-dom';
import { SparkLine } from '@/components/charts/SparkLine';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { WidgetBreakdown } from './WidgetBreakdown';
import { WidgetTable } from './WidgetTable';
import type {
 DashboardLayoutItem,
 DashboardWidgetSummary,
 WidgetBreakdownData,
 WidgetData,
 WidgetGaugeData,
 WidgetTableData,
 WidgetTrendData,
} from '@/api/dashboard-layout';

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

function formatRichValue(value: number, kind: DashboardWidgetSummary['kind']): string {
 if (kind === 'currency') return formatPeso(value);
 if (kind === 'percent') return `${value.toFixed(1)}%`;
 if (kind === 'hours') return `${value.toFixed(2)} h`;
 if (kind === 'decimal') return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
 return value.toLocaleString();
}

function RichGauge({ value, target, min, max, kind }: WidgetGaugeData) {
 const range = max > min ? max - min : 1;
 const progress = ((value - min) / range) * 100;

 return (
 <div className="space-y-2">
 <div className="flex items-baseline justify-between gap-2">
 <span className="text-2xl font-mono tabular-nums font-medium text-primary">
 {formatRichValue(value, kind)}
 </span>
 {target !== null && (
 <span className="text-xs text-muted">Target {formatRichValue(target, kind)}</span>
 )}
 </div>
 <ProgressBar
 value={progress}
 height="2"
 ariaLabel={`${formatRichValue(value, kind)} of target range`}
 />
 </div>
 );
}

function RichTrend({ points, delta, kind }: WidgetTrendData) {
 if (points.length === 0) return <p className="text-xs text-muted">No trend data.</p>;

 const latest = points[points.length - 1]?.value ?? 0;
 return (
 <div className="space-y-2">
 <div className="flex items-baseline justify-between gap-2">
 <span className="text-2xl font-mono tabular-nums font-medium text-primary">
 {formatRichValue(latest, kind)}
 </span>
 {delta !== null && (
 <span className={delta >= 0 ? 'text-xs text-success-fg' : 'text-xs text-danger-fg'}>
 {delta >= 0 ? '+' : ''}{delta.toFixed(1)}%
 </span>
 )}
 </div>
 <SparkLine data={points.map((point) => point.value)} height={32} width={160} />
 <p className="text-2xs text-subtle">Latest of {points.length} periods</p>
 </div>
 );
}

function renderRichWidget(widget: DashboardLayoutItem): React.ReactNode | null {
 const data = widget.data as WidgetData | null;
 if (!data) return null;

 switch (widget.render_kind) {
 case 'breakdown':
 return <WidgetBreakdown {...(data as WidgetBreakdownData)} />;
 case 'trend':
 return <RichTrend {...(data as WidgetTrendData)} />;
 case 'table':
 return <WidgetTable {...(data as WidgetTableData)} />;
 case 'gauge':
 return <RichGauge {...(data as WidgetGaugeData)} />;
 default:
 return null;
 }
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
  const richContent = !loading ? renderRichWidget(widget) : null;

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
      ) : richContent ? (
        richContent
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
