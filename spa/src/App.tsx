import { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';
import { AppLayout } from '@/layouts/AppLayout';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FullPageLoader } from '@/components/ui/Spinner';

// Auth flow
const LoginPage = lazy(() => import('@/pages/auth/login'));
const ChangePasswordPage = lazy(() => import('@/pages/auth/change-password'));

// App
const DashboardPage = lazy(() => import('@/pages/dashboard'));

// Admin (real implementations land in Tasks 10 / 11 / 12)
const RolesIndexPage = lazy(() => import('@/pages/admin/roles'));
const RolePermissionsPage = lazy(() => import('@/pages/admin/roles/permissions'));
const SettingsPage = lazy(() => import('@/pages/admin/settings'));
const AuditLogsPage = lazy(() => import('@/pages/admin/audit-logs'));

// Errors
const NotFoundPage = lazy(() => import('@/pages/error/NotFound'));

export default function App() {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <Routes>
        {/* ─── Auth (no AuthGuard) ───────────────────────────── */}
        <Route element={<AuthLayout />}>
          <Route path="/login" element={<LoginPage />} />
        </Route>

        {/* Change password requires session but no permissions. */}
        <Route
          path="/change-password"
          element={
            <AuthGuard>
              <AuthLayout />
            </AuthGuard>
          }
        >
          <Route index element={<ChangePasswordPage />} />
        </Route>

        {/* ─── Authenticated app shell ──────────────────────── */}
        <Route
          element={
            <AuthGuard>
              <AppLayout />
            </AuthGuard>
          }
        >
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<DashboardPage />} />

          {/* Admin */}
          <Route
            path="/admin/roles"
            element={
              <PermissionGuard permission="admin.roles.manage">
                <RolesIndexPage />
              </PermissionGuard>
            }
          />
          <Route
            path="/admin/roles/:id/permissions"
            element={
              <PermissionGuard permission="admin.roles.manage">
                <RolePermissionsPage />
              </PermissionGuard>
            }
          />
          <Route
            path="/admin/settings"
            element={
              <PermissionGuard permission="admin.settings.manage">
                <SettingsPage />
              </PermissionGuard>
            }
          />
          <Route
            path="/admin/audit-logs"
            element={
              <PermissionGuard permission="admin.audit_logs.view">
                <AuditLogsPage />
              </PermissionGuard>
            }
          />
        </Route>

        {/* 404 */}
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}
