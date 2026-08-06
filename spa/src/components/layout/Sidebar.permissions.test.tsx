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

 it('does not expose stock count from the generic inventory permission', () => {
 const genericInventory = {
 permissions: new Set(['inventory.view']),
 features: allFeatures,
 roleSlug: 'production_manager',
 };
 const warehouse = {
 permissions: new Set(['inventory.view', 'inventory.stock_count.view']),
 features: allFeatures,
 roleSlug: 'warehouse_staff',
 };

 expect(isNavItemVisible(item('/inventory/stock-count'), genericInventory)).toBe(false);
 expect(isNavItemVisible(item('/inventory/stock-count'), warehouse)).toBe(true);
 });
});
