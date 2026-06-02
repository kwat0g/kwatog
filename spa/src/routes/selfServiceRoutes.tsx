import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';
import { SelfServiceLayout } from '@/layouts/SelfServiceLayout';

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
    {/* Self-service portal — separate mobile-friendly layout (SelfServiceLayout) */}
    <Route
      element={
        <AuthGuard>
          <SelfServiceLayout />
        </AuthGuard>
      }
    >
      <Route path="/self-service" element={<SelfServiceHomePage />} />
      <Route path="/self-service/dtr" element={<SelfServiceDtrPage />} />
      {/* Legacy slugs (kept for backward compatibility); canonical paths below. */}
      <Route path="/self-service/leave" element={<SelfServiceLeavePage />} />
      <Route path="/self-service/me" element={<SelfServiceMePage />} />
      {/* U3 — canonical slugs aligned with bottom nav. */}
      <Route path="/self-service/leaves" element={<SelfServiceLeavesPage />} />
      <Route path="/self-service/profile" element={<SelfServiceProfilePage />} />
      <Route path="/self-service/loans" element={<SelfServiceLoansPage />} />
      {/* Task SS1 / SS3 — overtime requests + document downloads. */}
      <Route path="/self-service/overtime" element={<SelfServiceOvertimePage />} />
      <Route path="/self-service/documents" element={<SelfServiceDocumentsPage />} />
      <Route path="/self-service/notification-preferences" element={<SelfServiceNotifPrefsPage />} />
      <Route
        path="/self-service/payslips"
        element={<PermissionGuard permission="payroll.view"><SelfServicePayslipsPage /></PermissionGuard>}
      />
    </Route>
  </>
);
