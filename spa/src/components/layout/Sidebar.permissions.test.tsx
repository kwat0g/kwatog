import { describe, expect, it, vi } from 'vitest';

vi.mock('@/hooks/useBadges', () => ({
 useBadges: () => ({ getBadge: () => undefined }),
}));

import { SECTIONS, isNavItemVisible } from './Sidebar';

function item(path: string) {
 const found = SECTIONS.flatMap((section) => section.items).find((entry) => entry.to === path);
 if (!found) throw new Error(`Missing sidebar item ${path}`);
 return found;
}

const allFeatures = new Set([
 'attendance', 'leave', 'loans', 'payroll', 'quality', 'hr', 'production',
 'mrp', 'inventory', 'purchasing', 'supply_chain', 'accounting', 'budgeting',
]);

describe('role-aligned sidebar permissions', () => {
 it('does not turn PPC self-service permissions into back-office HR navigation', () => {
 const ppcPermissions = new Set(['attendance.view', 'leave.view', 'leave.create', 'payroll.view']);
 const context = { permissions: ppcPermissions, features: allFeatures, roleSlug: 'ppc_head' };

 expect(isNavItemVisible(item('/hr/attendance'), context)).toBe(false);
 expect(isNavItemVisible(item('/hr/leaves'), context)).toBe(false);
 expect(isNavItemVisible(item('/payroll/periods'), context)).toBe(false);
 expect(isNavItemVisible(item('/payroll/statutory'), context)).toBe(false);
 });

 it('shows operational HR links only to users with the matching responsibility', () => {
 const hr = {
 permissions: new Set(['attendance.edit', 'leave.approve_hr', 'payroll.periods.compute', 'payroll.statutory.export']),
 features: allFeatures,
 roleSlug: 'hr_officer',
 };

 expect(isNavItemVisible(item('/hr/attendance'), hr)).toBe(true);
 expect(isNavItemVisible(item('/hr/leaves'), hr)).toBe(true);
 expect(isNavItemVisible(item('/payroll/periods'), hr)).toBe(true);
 expect(isNavItemVisible(item('/payroll/statutory'), hr)).toBe(true);
 });

 it('keeps secondary pages out of the shared navigation sitemap', () => {
 const paths = new Set(SECTIONS.flatMap((section) => section.items).map((entry) => entry.to));

 expect(paths).not.toContain('/calendar');
 expect(paths).not.toContain('/hr/training/matrix');
 expect(paths).not.toContain('/hr/trainings');
 expect(paths).not.toContain('/hr/skills');
 expect(paths).not.toContain('/quality/capability');
 expect(paths).not.toContain('/maintenance/downtime');
 expect(paths).not.toContain('/crm/inquiries');
  expect(paths).not.toContain('/quality/calibration');
  expect(paths).not.toContain('/hr/recruitment');
  expect(paths).not.toContain('/admin/sod');
  expect(paths).not.toContain('/inventory/scanner');
  });

 it('uses labels that describe each primary destination', () => {
 expect(item('/chains').label).toBe('Business Chain Tracker');
 expect(item('/approvals').label).toBe('Approval Queue');
 expect(item('/crm/complaints').label).toBe('Customer Complaints');
 expect(item('/production/work-orders').label).toBe('Production Work Orders');
 expect(item('/production/schedule').label).toBe('Production Schedule (Gantt)');
 expect(item('/production/routings').label).toBe('Production Routings');
 expect(item('/mrp/machines').label).toBe('Production Machines');
 expect(item('/mrp/molds').label).toBe('Production Molds');
 expect(item('/inventory/items').label).toBe('Inventory Items');
 expect(item('/inventory/grn').label).toBe('Goods Receipts (GRN)');
 expect(item('/inventory/material-issues').label).toBe('Material Issues');
 expect(item('/inventory/mrb').label).toBe('Quarantine Holds (MRB)');
 expect(item('/inventory/stock-levels').label).toBe('Inventory Stock Levels');
 expect(item('/inventory/transfer-orders').label).toBe('Inventory Transfers');
 expect(item('/inventory/picking').label).toBe('Order Picking');
 expect(item('/supply-chain/deliveries').label).toBe('Outbound Deliveries');
 expect(item('/supply-chain/shipments').label).toBe('Inbound Shipments');
 expect(item('/supply-chain/fleet').label).toBe('Delivery Fleet');
 expect(item('/quality/inspection-specs').label).toBe('Inspection Specifications');
 expect(item('/quality/inspections').label).toBe('Quality Inspections');
 expect(item('/quality/ncrs').label).toBe('Nonconformance Reports (NCRs)');
 expect(item('/quality/traceability').label).toBe('Lot Traceability');
 expect(item('/accounting/invoices').label).toBe('Accounts Receivable Invoices');
 expect(item('/accounting/bills').label).toBe('Accounts Payable Bills');
 expect(item('/dashboard/finance').label).toBe('Finance Dashboard');
 expect(item('/hr/attendance').label).toBe('Attendance & DTR');
 expect(item('/hr/leaves').label).toBe('Leave Management');
 expect(item('/payroll/periods').label).toBe('Payroll Processing');
 expect(item('/payroll/adjustments').label).toBe('Payroll Adjustments');
 expect(item('/payroll/statutory').label).toBe('Payroll Statutory Exports');
 expect(item('/maintenance/work-orders').label).toBe('Maintenance Work Orders');
 expect(item('/maintenance/schedules').label).toBe('Maintenance Schedules');
 expect(item('/admin/users').label).toBe('User Accounts');
 expect(item('/admin/roles').label).toBe('Roles & Permissions');
 expect(item('/admin/settings').label).toBe('Settings');
 expect(item('/admin/sessions').label).toBe('Active Sessions');
 expect(item('/admin/gov-tables').label).toBe('Government Contribution Tables');
 });

 it('orders sections along the primary business flow', () => {
 expect(SECTIONS.map((section) => section.label)).toEqual([
 'Overview',
 'Sales & CRM',
 'Production Planning (MRP)',
 'Procurement',
 'Production',
 'Quality',
 'Warehouse',
 'Supply Chain',
 'Human Resources',
 'Finance',
 'Maintenance',
 'Assets',
 'Administration',
 ]);
 });

 it('shows department approval pages without granting HR or payroll administration', () => {
 const departmentHead = {
 permissions: new Set(['attendance.ot.approve', 'leave.approve_dept', 'payroll.view']),
 features: allFeatures,
 roleSlug: 'department_head',
 };

 expect(isNavItemVisible(item('/hr/attendance'), departmentHead)).toBe(true);
 expect(isNavItemVisible(item('/hr/leaves'), departmentHead)).toBe(true);
 expect(isNavItemVisible(item('/payroll/periods'), departmentHead)).toBe(false);
 expect(isNavItemVisible(item('/payroll/statutory'), departmentHead)).toBe(false);
 });

  it('gates portal access administration on the b2b portal_access permission', () => {
  const finance = {
  permissions: new Set(['b2b.portal_access.view']),
  features: allFeatures,
  roleSlug: 'finance_officer',
  };
  const purchasing = {
  permissions: new Set(['purchasing.view', 'accounting.vendors.manage']),
  features: allFeatures,
  roleSlug: 'purchasing_officer',
  };

  expect(isNavItemVisible(item('/accounting/portal-access'), finance)).toBe(true);
  expect(isNavItemVisible(item('/accounting/portal-access'), purchasing)).toBe(false);
  });

   it('exposes the merged Warehouse Map only to warehouse structure managers', () => {
 // 2026-08-08: Stock Count merged into the Warehouse Map page. The sidebar
  // Warehouse Map is structure-sensitive WMS navigation; generic inventory
  // readers keep item/stock-level access but do not receive the map entry.
  const genericInventory = {
  permissions: new Set(['inventory.view']),
  features: allFeatures,
  roleSlug: 'production_manager',
  };
  const warehouse = {
  permissions: new Set(['inventory.warehouse.manage']),
  features: allFeatures,
  roleSlug: 'warehouse_staff',
  };

  expect(isNavItemVisible(item('/inventory/warehouse-map'), genericInventory)).toBe(false);
  expect(isNavItemVisible(item('/inventory/warehouse-map'), warehouse)).toBe(true);
  expect(SECTIONS.flatMap((s) => s.items).some((entry) => entry.to === '/inventory/stock-count')).toBe(false);
  });

  it('keeps Warehouse execution navigation aligned across every seeded role', () => {
  const warehousePaths = [
  '/inventory/material-issues',
  '/inventory/stock-adjustments',
  '/inventory/warehouse-map',
  '/inventory/transfer-orders',
  '/inventory/picking',
  ];
  const rolePermissions: Record<string, string[]> = {
  system_admin: [],
  vice_president: [],
  hr_officer: [],
  finance_officer: ['inventory.adjust.approve'],
  production_manager: ['inventory.view'],
  ppc_head: [],
  purchasing_officer: ['inventory.view', 'inventory.grn.create'],
  warehouse_staff: [
  'inventory.view', 'inventory.issue.create', 'inventory.adjust',
  'inventory.warehouse.manage', 'inventory.picking.view',
  ],
  qc_inspector: ['inventory.view'],
  maintenance_tech: [],
  impex_officer: [],
  department_head: [],
  employee: [],
  driver: [],
  sales_officer: [],
  customer_service_officer: [],
  };
  const expected: Record<string, string[]> = {
  system_admin: warehousePaths,
  finance_officer: ['/inventory/stock-adjustments'],
  warehouse_staff: warehousePaths,
  };

  for (const [roleSlug, permissions] of Object.entries(rolePermissions)) {
  const context = { permissions: new Set(permissions), features: allFeatures, roleSlug };
  const visible = warehousePaths.filter((path) => isNavItemVisible(item(path), context));
  expect(visible, `${roleSlug} Warehouse navigation`).toEqual(expected[roleSlug] ?? []);
  }
  });

  it('keeps Procurement navigation aligned with maker, checker, and review roles', () => {
  const procurementPaths = [
  '/purchasing/chain',
  '/purchasing/purchase-orders',
  '/purchasing/purchase-requests',
  '/purchasing/rfqs',
  '/purchasing/approved-suppliers',
  '/purchasing/supplier-listings',
  ];
  const rolePermissions: Record<string, string[]> = {
  system_admin: [],
  vice_president: ['purchasing.pr.approve', 'purchasing.po.approve', 'purchasing.rfq.view'],
  hr_officer: [],
  finance_officer: ['purchasing.pr.approve', 'purchasing.po.approve', 'purchasing.rfq.view'],
  production_manager: ['purchasing.view'],
  ppc_head: [],
  purchasing_officer: [
  'purchasing.pr.create', 'purchasing.po.create', 'purchasing.rfq.view',
  'purchasing.supplier_listings.review',
  ],
  warehouse_staff: [],
  qc_inspector: ['purchasing.rfq.view'],
  maintenance_tech: [],
  impex_officer: ['purchasing.view'],
  department_head: ['purchasing.pr.approve'],
  employee: [],
  driver: [],
  sales_officer: [],
  customer_service_officer: [],
  };
  const expected: Record<string, string[]> = {
  system_admin: procurementPaths,
  vice_president: procurementPaths.slice(0, 4),
  finance_officer: procurementPaths.slice(0, 4),
  purchasing_officer: procurementPaths,
  qc_inspector: ['/purchasing/rfqs'],
  department_head: ['/purchasing/chain', '/purchasing/purchase-requests'],
  };

  for (const [roleSlug, permissions] of Object.entries(rolePermissions)) {
  const context = { permissions: new Set(permissions), features: allFeatures, roleSlug };
  const visible = procurementPaths.filter((path) => isNavItemVisible(item(path), context));
  expect(visible, `${roleSlug} Procurement navigation`).toEqual(expected[roleSlug] ?? []);
  }
  });

  it('keeps the Finance sidebar focused on the dashboard, invoices, bills, vendors, and primary statements', () => {
 const finance = {
 permissions: new Set(['dashboard.accounting.view']),
 features: allFeatures,
 roleSlug: 'finance_officer',
 };
 const employee = {
 permissions: new Set(['payroll.view']),
 features: allFeatures,
 roleSlug: 'employee',
 };

 expect(isNavItemVisible(item('/dashboard/finance'), finance)).toBe(true);
 expect(isNavItemVisible(item('/dashboard/finance'), employee)).toBe(false);

  const financePaths = SECTIONS.find((section) => section.label === 'Finance')?.items.map((entry) => entry.to);
   expect(financePaths).toEqual([
   '/dashboard/finance',
   '/accounting/invoices',
  '/accounting/bills',
  '/accounting/vendors',
  '/accounting/income-statement',
   '/accounting/balance-sheet',
   ]);
   });

   it('shows payroll processing to the Finance checker without exposing HR statutory exports', () => {
   const finance = {
   permissions: new Set(['payroll.periods.view', 'payroll.periods.approve', 'payroll.periods.finalize']),
   features: allFeatures,
   roleSlug: 'finance_officer',
   };

   expect(isNavItemVisible(item('/payroll/periods'), finance)).toBe(true);
   expect(isNavItemVisible(item('/payroll/statutory'), finance)).toBe(false);
   });
});
