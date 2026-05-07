/* eslint-disable react-refresh/only-export-components */
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { useAuthStore } from '@/stores/authStore';

/**
 * Series R — Task R4.
 *
 * Maps dashboard widget keys → React components. Keys not registered here
 * render as a friendly placeholder so a misspelled or removed widget
 * doesn't crash the whole dashboard.
 *
 * Each widget is a small, self-contained card. Heavy widgets (Gantt, OEE)
 * link out to their dedicated pages until a proper widget body is wired in
 * by their owning sprint.
 *
 * The eslint-disable above is intentional: this file IS the registry; it
 * intentionally exports both the lookup function AND the in-file component
 * leaves so the dashboard can render them via a single map. Splitting each
 * widget into its own file would be churn without benefit at this stage.
 */

type WidgetComponent = () => ReactNode;

function StubLink({
  title,
  to,
  description,
  helper,
}: {
  title: string;
  to?: string;
  description?: string;
  helper?: string;
}) {
  return (
    <Panel title={title}>
      <div className="space-y-2">
        {description && <p className="text-sm text-secondary">{description}</p>}
        {to && (
          <Link to={to} className="text-sm text-accent hover:underline">
            Open full view →
          </Link>
        )}
        {helper && <p className="text-xs text-muted">{helper}</p>}
      </div>
    </Panel>
  );
}

function AccountSummaryWidget() {
  const user = useAuthStore((s) => s.user);
  return (
    <Panel title="Account">
      <div className="grid grid-cols-2 gap-3">
        <StatCard label="Active modules" value={user?.features?.length ?? 0} />
        <StatCard label="Permissions" value={user?.permissions?.length ?? 0} />
        <StatCard label="Role" value={user?.role?.name ?? '—'} />
        <StatCard label="Status" value={user?.is_active ? 'Active' : 'Inactive'} />
      </div>
    </Panel>
  );
}

const REGISTRY: Record<string, WidgetComponent> = {
  // Production / Plant
  'production.kpi': () => <StubLink title="Production KPIs" to="/production/dashboard" description="Live throughput, OEE, and downtime stats." />,
  'production.active_wo': () => <StubLink title="Active work orders" to="/production/work-orders" description="Currently running and queued WOs." />,
  'production.wo_breakdown': () => <StubLink title="WO status breakdown" to="/production/work-orders" />,
  'production.gantt_mini': () => <StubLink title="Production schedule" to="/production/schedule" description="Gantt overview across machines." />,
  'machine.utilization': () => <StubLink title="Machine utilization" to="/production/dashboard" />,
  'machine.status': () => <StubLink title="Machine status" to="/mrp/machines" />,
  'oee.gauges': () => <StubLink title="OEE gauges" to="/production/oee" />,
  'chain.stage_breakdown': () => <StubLink title="Chain stage breakdown" to="/dashboard" description="Active records grouped by chain stage." />,

  // Quality
  'qc.pareto': () => <StubLink title="QC defect Pareto" to="/quality/dashboard" />,
  'qc.pending_inspections': () => <StubLink title="Pending inspections" to="/quality/inspections" description="Inspections waiting on a result." />,
  'qc.open_ncrs': () => <StubLink title="Open NCRs" to="/quality/ncrs" />,
  'qc.pass_rate': () => <StubLink title="Pass rate by product" to="/quality/dashboard" />,

  // MRP / PPC
  'mrp.shortages': () => <StubLink title="MRP shortages" to="/mrp/plans" description="Unfulfilled demand from latest plan." />,
  'material.reservations': () => <StubLink title="Material reservations" to="/inventory/movements" />,

  // Finance
  'finance.cash_position': () => <StubLink title="Cash position" to="/accounting/balance-sheet" />,
  'finance.ar_aging': () => <StubLink title="AR aging" to="/accounting/invoices" />,
  'finance.ap_aging': () => <StubLink title="AP aging" to="/accounting/bills" />,
  'finance.revenue_mtd': () => <StubLink title="Revenue MTD" to="/accounting/income-statement" />,
  'finance.unpaid_invoices': () => <StubLink title="Unpaid invoices" to="/accounting/invoices" />,
  'finance.upcoming_payables': () => <StubLink title="Upcoming payables" to="/accounting/bills" />,

  // HR / Payroll
  'hr.headcount': () => <StubLink title="Headcount by department" to="/hr/employees" />,
  'hr.on_leave_today': () => <StubLink title="On leave today" to="/hr/leaves" />,
  'hr.team_on_leave_today': () => <StubLink title="Team on leave today" to="/hr/leaves" />,
  'hr.team_dtr_today': () => <StubLink title="Team DTR today" to="/hr/attendance" />,
  'hr.probation_alerts': () => <StubLink title="Probation alerts" to="/hr/employees" />,
  'payroll.upcoming': () => <StubLink title="Upcoming payroll" to="/payroll/periods" />,
  'approvals.pending': () => <StubLink title="Pending approvals" to="/notifications" description="Items awaiting your decision." />,

  // Purchasing / Supply Chain
  'purchasing.open_prs': () => <StubLink title="Open purchase requests" to="/purchasing/purchase-requests" />,
  'purchasing.open_pos': () => <StubLink title="Open purchase orders" to="/purchasing/purchase-orders" />,
  'purchasing.supplier_perf': () => <StubLink title="Supplier performance" to="/purchasing/approved-suppliers" helper="Detailed supplier KPIs ship in Series F (F4)." />,
  'supply.overdue_deliveries': () => <StubLink title="Overdue deliveries" to="/supply-chain/deliveries" />,
  'supply.delivery_schedule': () => <StubLink title="Delivery schedule" to="/supply-chain/deliveries" />,

  // Inventory / Warehouse
  'inventory.low_stock': () => <StubLink title="Low stock alerts" to="/inventory/stock-levels" />,
  'inventory.pending_grns': () => <StubLink title="Pending GRNs" to="/inventory/grn" />,
  'inventory.pending_issues': () => <StubLink title="Pending material issues" to="/inventory/material-issues" />,

  // Self-service
  'self.payslip_summary': () => <StubLink title="Latest payslip" to="/self-service/payslips" />,
  'self.leave_balance': () => <StubLink title="My leave balance" to="/self-service/leaves" />,
  'self.dtr_today': () => <StubLink title="My shift today" to="/self-service/dtr" />,
  'self.pending_requests': () => <StubLink title="My pending requests" to="/self-service/leaves" />,

  // Platform
  'alerts': () => <StubLink title="Alerts" to="/alerts" description="Open issues that need attention." />,

  // Account summary card (always available even when no role default exists).
  'account.summary': AccountSummaryWidget,
};

export function getWidgetComponent(key: string): WidgetComponent | null {
  return REGISTRY[key] ?? null;
}
