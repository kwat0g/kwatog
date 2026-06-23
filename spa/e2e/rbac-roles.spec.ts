/**
 * E2E RBAC permission guard tests.
 *
 * For every role, assert:
 *  - Pages they ARE authorized for render without "Forbidden."
 *  - Pages they are NOT authorized for show the PermissionGuard forbidden state
 *    ("You do not have permission to view this page.").
 *
 * All API calls are mocked. These test the frontend's PermissionGuard +
 * usePermission hook, NOT backend enforcement (that's the curl harness's job).
 */
import { test, expect } from '@playwright/test';
import { loginAs, ROLES, mockList } from './helpers-extended';
import { BasePage } from './pages/BasePage';

// ══════════════════════════════════════════════════════════════════════════════
// Page → required permission mapping (from routes/*.tsx)
// ══════════════════════════════════════════════════════════════════════════════

const GATED_PAGES: Record<string, { url: string; permission: string; }> = {
  'admin users':      { url: '/admin/users',      permission: 'admin.users.manage' },
  'admin roles':      { url: '/admin/roles',      permission: 'admin.roles.manage' },
  'admin audit logs': { url: '/admin/audit-logs', permission: 'admin.audit_logs.view' },
  'admin settings':   { url: '/admin/settings',   permission: 'admin.settings.manage' },
  'admin activity':   { url: '/admin/activity',   permission: 'admin.activity.view' },

  'hr employees':     { url: '/hr/employees',     permission: 'hr.employees.view' },
  'hr departments':   { url: '/hr/departments',   permission: 'hr.departments.view' },
  'hr positions':     { url: '/hr/positions',     permission: 'hr.positions.view' },
  'hr leaves':        { url: '/hr/leaves',        permission: 'leave.view' },
  'hr attendance':    { url: '/hr/attendance',    permission: 'attendance.view' },
  'hr loans':         { url: '/hr/loans',         permission: 'loans.view' },
  'hr separations':   { url: '/hr/separations',   permission: 'hr.separation.view' },

  'payroll periods':          { url: '/payroll/periods',           permission: 'payroll.view' },
  'payroll adjustments':      { url: '/payroll/adjustments',       permission: 'payroll.view' },
  'payroll pipeline':         { url: '/payroll/pipeline',          permission: 'payroll.view' },
  'payroll statutory':        { url: '/payroll/statutory',         permission: 'payroll.view' },

  'accounting coa':           { url: '/accounting/coa',            permission: 'accounting.coa.view' },
  'accounting journal':       { url: '/accounting/journal-entries', permission: 'accounting.journal.view' },
  'accounting invoices':      { url: '/accounting/invoices',       permission: 'accounting.invoices.view' },
  'accounting bills':         { url: '/accounting/bills',          permission: 'accounting.bills.view' },
  'accounting trial balance': { url: '/accounting/trial-balance',  permission: 'accounting.statements.view' },
  'accounting balance sheet': { url: '/accounting/balance-sheet',  permission: 'accounting.statements.view' },
  'accounting vendors':       { url: '/accounting/vendors',        permission: 'accounting.vendors.view' },

  'inventory items':         { url: '/inventory/items',          permission: 'inventory.view' },
  'inventory dashboard':     { url: '/inventory/dashboard',      permission: 'inventory.view' },
  'inventory grn':           { url: '/inventory/grn',            permission: 'inventory.view' },
  'inventory stock levels':  { url: '/inventory/stock-levels',   permission: 'inventory.view' },

  'purchasing requests':     { url: '/purchasing/purchase-requests', permission: 'purchasing.view' },
  'purchasing orders':       { url: '/purchasing/purchase-orders',  permission: 'purchasing.view' },

  'production work orders':  { url: '/production/work-orders',     permission: 'production.work_orders.view' },
  'production dashboard':    { url: '/production/dashboard',       permission: 'production.dashboard.view' },
  'production schedule':     { url: '/production/schedule',        permission: 'production.schedule.view' },
  'production oee':          { url: '/production/oee',             permission: 'production.dashboard.view' },

  'quality inspections':     { url: '/quality/inspections',        permission: 'quality.inspections.view' },
  'quality ncrs':            { url: '/quality/ncrs',               permission: 'quality.ncr.view' },
  'quality dashboard':       { url: '/quality/dashboard',          permission: 'quality.view' },

  'mrp boms':                { url: '/mrp/boms',                   permission: 'mrp.boms.view' },
  'mrp plans':               { url: '/mrp/plans',                  permission: 'mrp.plans.view' },
  'mrp machines':            { url: '/mrp/machines',               permission: 'mrp.machines.view' },

  'maintenance work orders': { url: '/maintenance/work-orders',    permission: 'maintenance.view' },
  'maintenance schedules':   { url: '/maintenance/schedules',      permission: 'maintenance.schedules.manage' },

  'assets':                  { url: '/assets',                     permission: 'assets.view' },
  'budgeting':               { url: '/budgeting',                  permission: 'budgeting.view' },

  'forecasting demand':      { url: '/forecasting/demand',         permission: 'forecasting.view' },
  'return management':       { url: '/return-management',          permission: 'return_management.view' },

  'supply chain shipments':  { url: '/supply-chain/shipments',     permission: 'supply_chain.view' },
  'supply chain deliveries': { url: '/supply-chain/deliveries',    permission: 'supply_chain.view' },
};

// ══════════════════════════════════════════════════════════════════════════════
// Tests: admin-only surface
// ══════════════════════════════════════════════════════════════════════════════

test.describe('Permission guards — admin surface (only system_admin may access)', () => {
  const adminPages = ['admin users', 'admin roles', 'admin audit logs', 'admin settings', 'admin activity'];

  for (const key of adminPages) {
    const { url } = GATED_PAGES[key];

    test(`admin can view ${key}`, async ({ page }) => {
      const bp = new BasePage(page);
      await loginAs(page, 'admin', url);
      await expect(bp.forbiddenText).not.toBeVisible();
    });
  }

  for (const key of adminPages) {
    const { url } = GATED_PAGES[key];

    test(`non-admin (hr) sees forbidden on ${key}`, async ({ page }) => {
      const bp = new BasePage(page);
      await loginAs(page, 'hr', url);
      await bp.expectForbidden();
    });

    test(`non-admin (employee) sees forbidden on ${key}`, async ({ page }) => {
      const bp = new BasePage(page);
      await loginAs(page, 'employee', url);
      await bp.expectForbidden();
    });
  }
});

// ══════════════════════════════════════════════════════════════════════════════
// Tests: role-specific boundary checks
// ══════════════════════════════════════════════════════════════════════════════

test.describe('Permission guards — HR boundaries', () => {
  test('hr can view employees, departments, leaves, attendance', async ({ page }) => {
    const bp = new BasePage(page);
    for (const u of ['/hr/employees', '/hr/departments', '/hr/leaves', '/hr/attendance']) {
      await loginAs(page, 'hr', u);
      await expect(bp.forbiddenText).not.toBeVisible();
    }
  });

  test('employee cannot view hr/employees (self-service only)', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'employee', '/hr/employees');
    await bp.expectForbidden();
  });

  test('qc cannot view hr/employees', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'qc', '/hr/employees');
    await bp.expectForbidden();
  });

  test('employee CAN view self-service pages (own leave, payslips, DTR)', async ({ page }) => {
    const bp = new BasePage(page);
    for (const u of ['/self-service/leave', '/self-service/leaves', '/self-service/payslips', '/self-service/dtr', '/self-service/loans']) {
      await loginAs(page, 'employee', u);
      await expect(bp.forbiddenText).not.toBeVisible();
    }
  });
});

test.describe('Permission guards — Payroll boundaries', () => {
  test('hr can view payroll periods (has payroll.view)', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'hr', '/payroll/periods');
    await expect(bp.forbiddenText).not.toBeVisible();
  });

  test('warehouse cannot view payroll periods (no payroll.view)', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'warehouse', '/payroll/periods');
    await bp.expectForbidden();
  });
});

test.describe('Permission guards — Quality vs Production boundary', () => {
  test('production_manager can VIEW quality inspections but cannot CREATE', async ({ page }) => {
    const bp = new BasePage(page);
    // View allowed
    await loginAs(page, 'production', '/quality/inspections');
    await expect(bp.forbiddenText).not.toBeVisible();
    // Create page is gated by quality.inspections.manage (which prod lacks)
    await loginAs(page, 'production', '/quality/inspections/new');
    await bp.expectForbidden();
  });

  test('qc_inspector cannot view production work orders', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'qc', '/production/work-orders');
    await bp.expectForbidden();
  });

  test('qc can view quality pages (inspections, ncrs, dashboard)', async ({ page }) => {
    const bp = new BasePage(page);
    for (const u of ['/quality/inspections', '/quality/ncrs', '/quality/dashboard']) {
      await loginAs(page, 'qc', u);
      await expect(bp.forbiddenText).not.toBeVisible();
    }
  });
});

test.describe('Permission guards — Accounting boundaries', () => {
  test('finance can view journal entries, coa, invoices, bills', async ({ page }) => {
    const bp = new BasePage(page);
    for (const u of ['/accounting/journal-entries', '/accounting/coa', '/accounting/invoices', '/accounting/bills']) {
      await loginAs(page, 'finance', u);
      await expect(bp.forbiddenText).not.toBeVisible();
    }
  });

  test('hr cannot view accounting pages', async ({ page }) => {
    const bp = new BasePage(page);
    for (const u of ['/accounting/journal-entries', '/accounting/coa']) {
      await loginAs(page, 'hr', u);
      await bp.expectForbidden();
    }
  });

  test('warehouse cannot view accounting pages', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'warehouse', '/accounting/journal-entries');
    await bp.expectForbidden();
  });
});

test.describe('Permission guards — Purchasing boundaries', () => {
  test('purchasing officer can view purchase requests and orders', async ({ page }) => {
    const bp = new BasePage(page);
    for (const u of ['/purchasing/purchase-requests', '/purchasing/purchase-orders']) {
      await loginAs(page, 'purchasing', u);
      await expect(bp.forbiddenText).not.toBeVisible();
    }
  });

  test('warehouse cannot view purchase orders (no purchasing.view)', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'warehouse', '/purchasing/purchase-orders');
    await bp.expectForbidden();
  });
});

test.describe('Permission guards — Dashboard segregation', () => {
  test('hr sees hr dashboard, not finance dashboard', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'hr', '/dashboard/hr');
    await expect(bp.forbiddenText).not.toBeVisible();
    await loginAs(page, 'hr', '/dashboard/accounting');
    await bp.expectForbidden();
  });

  test('employee sees default dashboard', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'employee', '/dashboard/default');
    await expect(bp.forbiddenText).not.toBeVisible();
  });
});

test.describe('Permission guards — department_head boundaries', () => {
  test('depthead can view HR leaves, attendance, self-service', async ({ page }) => {
    const bp = new BasePage(page);
    for (const u of ['/hr/leaves', '/hr/attendance', '/self-service/profile']) {
      await loginAs(page, 'depthead', u);
      await expect(bp.forbiddenText).not.toBeVisible();
    }
  });

  test('depthead cannot view admin pages', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'depthead', '/admin/users');
    await bp.expectForbidden();
  });

  test('depthead cannot view payroll periods (no payroll.view)', async ({ page }) => {
    const bp = new BasePage(page);
    await loginAs(page, 'depthead', '/payroll/periods');
    await bp.expectForbidden();
  });
});
