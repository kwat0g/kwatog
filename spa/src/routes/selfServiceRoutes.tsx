import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Self-service (Sprint 8 — Task 74; U3 adds /leaves, /profile, /loans canonical slugs)
const SelfServiceHomePage           = lazy(() => import('@/pages/self-service'));
const SelfServiceDtrPage            = lazy(() => import('@/pages/self-service/dtr'));
const SelfServiceLeavePage          = lazy(() => import('@/pages/self-service/leave'));
const SelfServiceLeavesPage         = lazy(() => import('@/pages/self-service/leaves'));
const SelfServiceMePage             = lazy(() => import('@/pages/self-service/me'));
const SelfServiceProfilePage        = lazy(() => import('@/pages/self-service/profile'));
const SelfServiceLoansPage          = lazy(() => import('@/pages/self-service/loans'));
const SelfServiceOvertimePage       = lazy(() => import('@/pages/self-service/overtime'));
const SelfServiceDocumentsPage      = lazy(() => import('@/pages/self-service/documents'));
const SelfServiceNotifPrefsPage     = lazy(() => import('@/pages/self-service/notification-preferences'));
const SelfServicePayslipsPage       = lazy(() => import('@/pages/self-service/payslips'));

export const selfServiceRoutes = (
  <>
    <Route path="/self-service" element={<SelfServiceHomePage />} />
    <Route path="/self-service/profile" element={<SelfServiceProfilePage />} />
    <Route path="/self-service/me" element={<SelfServiceMePage />} />
    <Route path="/self-service/documents" element={<SelfServiceDocumentsPage />} />
    <Route path="/self-service/notification-preferences" element={<SelfServiceNotifPrefsPage />} />

    {/* Attendance-gated */}
    <Route element={<ModuleGuard module="attendance" />}>
      <Route path="/self-service/dtr" element={<SelfServiceDtrPage />} />
      <Route path="/self-service/overtime" element={<SelfServiceOvertimePage />} />
    </Route>

    {/* Leave-gated */}
    <Route element={<ModuleGuard module="leave" />}>
      <Route path="/self-service/leave" element={<SelfServiceLeavePage />} />
      <Route path="/self-service/leaves" element={<SelfServiceLeavesPage />} />
    </Route>

    {/* Loans-gated */}
    <Route element={<ModuleGuard module="loans" />}>
      <Route path="/self-service/loans" element={<SelfServiceLoansPage />} />
    </Route>

    {/* Payroll-gated */}
    <Route element={<ModuleGuard module="payroll" />}>
      <Route
        path="/self-service/payslips"
        element={<PermissionGuard permission="payroll.view"><SelfServicePayslipsPage /></PermissionGuard>}
      />
    </Route>
  </>
);
