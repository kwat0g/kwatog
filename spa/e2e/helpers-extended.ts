/**
 * OGAMI ERP E2E — expanded helpers (POM-friendly).
 *
 * Adds full-role fixtures, error-state API mocking, and convenience functions
 * on top of the existing bare-metal auth mock in helpers.ts.
 *
 * The existing helpers.ts remains unchanged; this file imports from it.
 */
import type { Page } from '@playwright/test';
import { mockAuth, type MockUser } from './helpers';

// ── Re-export for consumers ─────────────────────────────────────────────────
export { mockAuth, type MockUser, USERS } from './helpers';

// ══════════════════════════════════════════════════════════════════════════════
// Full role permissions (matches RolePermissionSeeder + live verification)
// ══════════════════════════════════════════════════════════════════════════════

export const ROLES: Record<string, MockUser> = {
  admin: {
    id: 'adm0001', name: 'System Administrator', email: 'admin@ogami.test',
    roleSlug: 'system_admin', roleName: 'System Administrator',
    permissions: ['*'], employee: null,
  },
  hr: {
    id: 'hr00001', name: 'Maria Santos', email: 'hr@ogami.test',
    roleSlug: 'hr_officer', roleName: 'HR Officer',
    permissions: [
      'hr.departments.view', 'hr.departments.manage', 'hr.positions.view', 'hr.positions.manage',
      'hr.employees.view', 'hr.employees.create', 'hr.employees.edit', 'hr.employees.delete',
      'hr.employees.export', 'hr.employees.documents.view', 'hr.employees.view_sensitive',
      'hr.employees.separate', 'hr.employees.account_status', 'hr.employees.provision_account',
      'hr.employees.deactivate_account', 'hr.employees.reset_password',
      'hr.directory.view', 'hr.separation.view', 'hr.separation.initiate', 'hr.clearance.sign',
      'hr.trainings.view', 'hr.trainings.manage', 'hr.employees.trainings.view', 'hr.employees.trainings.manage',
      'attendance.view', 'attendance.import', 'attendance.edit', 'attendance.shifts.manage',
      'attendance.holidays.manage', 'attendance.ot.create', 'attendance.ot.approve',
      'leave.view', 'leave.create', 'leave.approve_dept', 'leave.approve_hr', 'leave.types.manage',
      'loans.view', 'loans.create', 'loans.approve', 'loans.write_off',
      'payroll.view', 'payroll.payslip.view_all', 'payroll.periods.create', 'payroll.periods.compute',
      'payroll.periods.approve', 'payroll.adjustments.create', 'payroll.thirteenth_month.run',
      'payroll.anomalies.review', 'payroll.gov_tables.manage',
      'dashboard.hr.view', 'search.global', 'notifications.view', 'notifications.preferences.manage',
      'calendar.view', 'approvals.board.view', 'alerts.view', 'dashboard.layout.reset',
    ],
    employee: { id: 'emp_hr', employee_no: 'OGM-2024-0001' },
  },
  finance: {
    id: 'fin0001', name: 'Ana Reyes', email: 'finance@ogami.test',
    roleSlug: 'finance_officer', roleName: 'Finance Officer',
    permissions: [
      'payroll.view', 'payroll.periods.create', 'payroll.periods.compute', 'payroll.periods.approve',
      'payroll.periods.finalize', 'payroll.payslip.view_all', 'payroll.adjustments.create',
      'payroll.thirteenth_month.run', 'payroll.periods.force_unlock', 'payroll.gov_tables.manage',
      'payroll.anomalies.review',
      'accounting.view', 'accounting.dashboard.view', 'accounting.coa.view', 'accounting.coa.manage',
      'accounting.coa.deactivate', 'accounting.journal.view', 'accounting.journal.create',
      'accounting.journal.post', 'accounting.journal.reverse',
      'accounting.periods.view', 'accounting.periods.manage',
      'accounting.vendors.view', 'accounting.vendors.manage',
      'accounting.bills.view', 'accounting.bills.create', 'accounting.bills.update', 'accounting.bills.pay',
      'accounting.customers.view', 'accounting.customers.manage',
      'accounting.invoices.view', 'accounting.invoices.create', 'accounting.invoices.update', 'accounting.invoices.collect',
      'accounting.statements.view', 'accounting.statements.export',
      'budgeting.view', 'budgeting.manage', 'budgeting.approve',
      'loans.view', 'loans.create', 'loans.approve', 'loans.write_off',
      'assets.view', 'assets.create', 'assets.update', 'assets.delete', 'assets.dispose',
      'assets.depreciation.view', 'assets.depreciation.run',
      'admin.gov_tables.manage', 'dashboard.accounting.view',
      'search.global', 'notifications.view', 'notifications.preferences.manage',
      'hr.profile_updates.finance_review', 'alerts.view', 'alerts.dismiss',
      'dashboard.view_bottlenecks', 'purchasing.suppliers.performance.view',
      'forecasting.view', 'return_management.view', 'quality.copq.view',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
    ],
    employee: { id: 'emp_fin', employee_no: 'OGM-2024-0002' },
  },
  production: {
    id: 'prd0001', name: 'Ricardo Tanaka', email: 'production@ogami.test',
    roleSlug: 'production_manager', roleName: 'Production Manager',
    permissions: [
      'production.view', 'production.wo.create', 'production.wo.confirm', 'production.wo.record',
      'production.work_orders.view', 'production.work_orders.lifecycle',
      'production.machines.manage', 'production.machines.transition',
      'production.molds.manage', 'production.schedule.view', 'production.schedule.confirm',
      'production.dashboard.view',
      'mrp.view', 'mrp.schedule', 'inventory.view',
      'quality.view', 'quality.inspections.view', 'quality.ncr.view', 'quality.copq.view',
      'dashboard.plant_manager.view', 'dashboard.ppc.view',
      'maintenance.view', 'assets.view',
      'attendance.view', 'attendance.ot.create', 'leave.view', 'leave.create',
      'loans.view', 'loans.create', 'payroll.view',
      'search.global', 'notifications.view', 'notifications.preferences.manage',
      'alerts.view', 'alerts.dismiss', 'dashboard.view_bottlenecks',
      'forecasting.view', 'return_management.view',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
      'quality.documents.view',
    ],
    employee: { id: 'emp_prd', employee_no: 'OGM-2024-0003' },
  },
  ppc: {
    id: 'ppc0001', name: 'Pedro Garcia', email: 'ppc@ogami.test',
    roleSlug: 'ppc_head', roleName: 'PPC Head',
    permissions: [
      'mrp.view', 'mrp.schedule', 'mrp.boms.view', 'mrp.boms.manage',
      'mrp.machines.view', 'mrp.molds.view', 'mrp.plans.view', 'mrp.plans.run',
      'mrp.runs.view', 'mrp.runs.trigger',
      'forecasting.view', 'forecasting.manage',
      'production.view', 'production.work_orders.view', 'production.wo.create', 'production.wo.confirm',
      'dashboard.ppc.view', 'maintenance.view', 'assets.view',
      'attendance.view', 'attendance.ot.create', 'leave.view', 'leave.create',
      'loans.view', 'loans.create', 'payroll.view',
      'search.global', 'notifications.view', 'notifications.preferences.manage',
      'alerts.view', 'alerts.dismiss', 'dashboard.view_bottlenecks',
      'return_management.view', 'return_management.manage',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
      'quality.documents.view',
    ],
    employee: { id: 'emp_ppc', employee_no: 'OGM-2024-0004' },
  },
  purchasing: {
    id: 'pur0001', name: 'Elena Cruz', email: 'purchasing@ogami.test',
    roleSlug: 'purchasing_officer', roleName: 'Purchasing Officer',
    permissions: [
      'purchasing.view', 'purchasing.pr.create', 'purchasing.pr.approve',
      'purchasing.po.create', 'purchasing.po.approve', 'purchasing.po.send',
      'purchasing.suppliers.performance.view', 'purchasing.suppliers.performance.recompute',
      'inventory.view', 'inventory.grn.create',
      'supply_chain.shipments.manage',
      'accounting.vendors.view', 'accounting.bills.view',
      'forecasting.view', 'return_management.view', 'return_management.manage',
      'dashboard.purchasing.view',
      'attendance.view', 'attendance.ot.create', 'leave.view', 'leave.create',
      'loans.view', 'loans.create', 'payroll.view',
      'search.global', 'notifications.view', 'notifications.preferences.manage',
      'quality.documents.view',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
    ],
    employee: { id: 'emp_pur', employee_no: 'OGM-2024-0005' },
  },
  warehouse: {
    id: 'wh00001', name: 'Carlos Mendoza', email: 'warehouse@ogami.test',
    roleSlug: 'warehouse_staff', roleName: 'Warehouse Staff',
    permissions: [
      'inventory.view', 'inventory.items.manage', 'inventory.warehouse.manage',
      'inventory.grn.create', 'inventory.issue.create', 'inventory.adjust',
      'inventory.stock_count.view', 'inventory.stock_count.manage', 'inventory.picking.view',
      'forecasting.view', 'return_management.view',
      'dashboard.warehouse.view',
      'attendance.view', 'attendance.ot.create', 'leave.view', 'leave.create',
      'loans.view', 'loans.create', 'payroll.view',
      'notifications.view', 'notifications.preferences.manage',
      'quality.documents.view',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
    ],
    employee: { id: 'emp_wh', employee_no: 'OGM-2024-0006' },
  },
  qc: {
    id: 'qc00001', name: 'Rosa Villareal', email: 'qc@ogami.test',
    roleSlug: 'qc_inspector', roleName: 'QC Inspector',
    permissions: [
      'quality.view', 'quality.inspections.create', 'quality.inspections.edit',
      'quality.inspections.view', 'quality.inspections.manage',
      'quality.specs.view', 'quality.specs.manage', 'quality.ncr.view', 'quality.ncr.manage',
      'quality.copq.view', 'quality.calibration.view', 'quality.calibration.manage',
      'quality.documents.view', 'quality.documents.manage',
      'dashboard.quality.view', 'return_management.view',
      'attendance.view', 'attendance.ot.create', 'leave.view', 'leave.create',
      'loans.view', 'loans.create', 'payroll.view',
      'notifications.view', 'notifications.preferences.manage',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
    ],
    employee: { id: 'emp_qc', employee_no: 'OGM-2024-0007' },
  },
  maintenance: {
    id: 'mnt0001', name: 'Juan Bautista', email: 'maintenance@ogami.test',
    roleSlug: 'maintenance_tech', roleName: 'Maintenance Technician',
    permissions: [
      'maintenance.view', 'maintenance.wo.create', 'maintenance.wo.complete',
      'assets.view',
      'attendance.view', 'attendance.ot.create', 'leave.view', 'leave.create',
      'loans.view', 'loans.create', 'payroll.view',
      'search.global', 'notifications.view', 'notifications.preferences.manage',
      'quality.documents.view',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
    ],
    employee: { id: 'emp_mnt', employee_no: 'OGM-2024-0008' },
  },
  depthead: {
    id: 'dpt0001', name: 'Roberto Santos', email: 'depthead@ogami.test',
    roleSlug: 'department_head', roleName: 'Department Head',
    permissions: [
      'hr.employees.view', 'hr.employees.trainings.view',
      'attendance.ot.approve', 'attendance.ot.create',
      'leave.approve_dept', 'leave.view', 'leave.create',
      'purchasing.view', 'purchasing.pr.approve',
      'hr.clearance.sign',
      'attendance.view', 'loans.view', 'loans.create', 'payroll.view',
      'search.global', 'notifications.view', 'notifications.preferences.manage',
      'quality.documents.view',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
    ],
    employee: { id: 'emp_dpt', employee_no: 'OGM-2024-0009' },
  },
  employee: {
    id: 'emp0001', name: 'Manuel Cruz', email: 'employee@ogami.test',
    roleSlug: 'employee', roleName: 'Employee',
    permissions: [
      'attendance.view', 'attendance.ot.create',
      'leave.view', 'leave.create',
      'loans.view', 'loans.create',
      'payroll.view',
      'quality.documents.view',
      'notifications.view', 'notifications.preferences.manage',
      'calendar.view', 'approvals.board.view', 'hr.directory.view', 'dashboard.layout.reset',
    ],
    employee: { id: 'emp_ee', employee_no: 'OGM-2024-0010' },
  },
};

// ══════════════════════════════════════════════════════════════════════════════
// Error-state API mock helpers
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Mock a 403 response on a specific API endpoint. Useful for testing the SPA's
 * error handling when the backend independently enforces a permission the
 * frontend guard missed.
 */
export async function mock403(page: Page, urlPattern: string): Promise<void> {
  await page.route(urlPattern, async (route) => {
    await route.fulfill({
      status: 403,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'You do not have permission to perform this action.' }),
    });
  });
}

/** Mock a 500 on an endpoint (database error, server crash). */
export async function mock500(page: Page, urlPattern: string): Promise<void> {
  await page.route(urlPattern, async (route) => {
    await route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Server error.' }),
    });
  });
}

/** Do nothing — request hangs forever (for loading-skeleton tests). */
export async function mockHang(page: Page, urlPattern: string): Promise<void> {
  await page.route(urlPattern, async () => {
    await new Promise<never>(() => {});
  });
}

// ══════════════════════════════════════════════════════════════════════════════
// Convenience: auth + navigate + wait for SPA boot
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Mock auth as the given role then goto a page. Returns once the SPA has
 * bootstrapped (networkidle).
 */
export async function loginAs(page: Page, role: keyof typeof ROLES, goto: string = '/dashboard/default'): Promise<void> {
  await mockAuth(page, ROLES[role]);
  await page.goto(goto);
  await page.waitForLoadState('networkidle');
}

/**
 * Mock auth + a paginated list endpoint. Returns the response factory so
 * callers can inspect what was sent.
 */
export function mockList<T>(page: Page, urlPattern: string, items: T[]): void {
  page.route(urlPattern, async (route) => {
    if (route.request().method() !== 'GET') { await route.continue(); return; }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: items,
        meta: {
          current_page: 1, last_page: 1, per_page: 25,
          total: items.length, from: items.length > 0 ? 1 : null, to: items.length > 0 ? items.length : null,
        },
        links: { first: null, last: null, prev: null, next: null },
      }),
    });
  });
}
