import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Payroll (Sprint 3 — Tasks 23-30)
const PayrollPeriodsPage = lazy(() => import('@/pages/payroll/periods'));
const CreatePayrollPeriodPage = lazy(() => import('@/pages/payroll/periods/create'));
const PayrollPeriodDetailPage = lazy(() => import('@/pages/payroll/periods/detail'));
const PayrollEmployeeDetailPage = lazy(() => import('@/pages/payroll/periods/employee-detail'));
const PayrollAdjustmentsPage = lazy(() => import('@/pages/payroll/adjustments'));
const CreatePayrollAdjustmentPage = lazy(() => import('@/pages/payroll/adjustments/create'));
// /payroll/pipeline removed 2026-08-08 (scope cut — page file kept)
// /payroll/de-minimis removed 2026-08-08 (scope cut — now a modal on /payroll/periods)
const StatutoryExportsPage = lazy(() => import('@/pages/payroll/statutory'));

export const payrollRoutes = (
 <>
 {/* Payroll module */}
 <Route element={<ModuleGuard module="payroll" />}>
 <Route
 path="/payroll/periods"
 element={<PermissionGuard permission="payroll.periods.view"><PayrollPeriodsPage /></PermissionGuard>}
 />
 <Route
 path="/payroll/periods/create"
 element={<PermissionGuard permission="payroll.periods.create"><CreatePayrollPeriodPage /></PermissionGuard>}
 />
 <Route
 path="/payroll/periods/:id"
 element={<PermissionGuard permission="payroll.periods.view"><PayrollPeriodDetailPage /></PermissionGuard>}
 />
 <Route
 path="/payroll/periods/:id/employee/:eid"
 element={<PermissionGuard permission="payroll.periods.view"><PayrollEmployeeDetailPage /></PermissionGuard>}
 />
 <Route
 path="/payroll/adjustments"
 element={<PermissionGuard permission="payroll.adjustments.create"><PayrollAdjustmentsPage /></PermissionGuard>}
 />
 <Route
 path="/payroll/adjustments/create"
 element={<PermissionGuard permission="payroll.adjustments.create"><CreatePayrollAdjustmentPage /></PermissionGuard>}
 />
<Route
        path="/payroll/statutory"
        element={<PermissionGuard permission="payroll.statutory.export"><StatutoryExportsPage /></PermissionGuard>}
      />
 </Route>
 </>
);
