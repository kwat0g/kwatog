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
 permissions: new Set(['attendance.edit', 'leave.approve_hr', 'payroll.periods.view', 'payroll.statutory.export']),
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
 expect(paths).not.toContain('/accounting/portal-access');
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
 expect(item('/accounting/vendors').label).toBe('Suppliers & Vendors');
 expect(item('/accounting/ar-aging').label).toBe('Accounts Receivable Aging');
 expect(item('/accounting/ap-aging').label).toBe('Accounts Payable Aging');
 expect(item('/budgeting/budget-vs-actual').label).toBe('Budget vs. Actual');
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

 it('exposes the merged Warehouse Map from inventory.view (Stock Count is its toggle)', () => {
 // 2026-08-08: Stock Count merged into the Warehouse Map page. The sidebar
 // shows one entry for inventory.view users; the Stock Count tab inside the
 // page is gated on inventory.stock_count.view by the page itself, and the
 // /inventory/stock-count route keeps its stock_count gate for scanner links.
 const genericInventory = {
 permissions: new Set(['inventory.view']),
 features: allFeatures,
 roleSlug: 'production_manager',
 };

 expect(isNavItemVisible(item('/inventory/warehouse-map'), genericInventory)).toBe(true);
 expect(SECTIONS.flatMap((s) => s.items).some((entry) => entry.to === '/inventory/stock-count')).toBe(false);
 });

 it('exposes accounting periods to finance view holders while preserving the manage gate in the page', () => {
 const finance = {
 permissions: new Set(['accounting.periods.view']),
 features: allFeatures,
 roleSlug: 'finance_officer',
 };
 const employee = {
 permissions: new Set(['payroll.view']),
 features: allFeatures,
 roleSlug: 'employee',
 };

 expect(isNavItemVisible(item('/accounting/periods'), finance)).toBe(true);
 expect(isNavItemVisible(item('/accounting/periods'), employee)).toBe(false);
 });
});
