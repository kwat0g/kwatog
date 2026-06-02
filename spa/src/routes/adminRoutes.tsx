import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Admin (Sprint 1; Series R/R1 adds /admin/roles/create)
const RolesIndexPage = lazy(() => import('@/pages/admin/roles'));
const CreateRolePage = lazy(() => import('@/pages/admin/roles/create'));
const CompareRolesPage = lazy(() => import('@/pages/admin/roles/compare'));
const RolePermissionsPage = lazy(() => import('@/pages/admin/roles/permissions'));
const SettingsPage = lazy(() => import('@/pages/admin/settings'));
const AuditLogsPage = lazy(() => import('@/pages/admin/audit-logs'));

// Admin > Scheduled exports (Series E — Task E2)
const ScheduledExportsPage = lazy(() => import('@/pages/admin/scheduled-exports'));

// Admin > Users (Task U2)
const AdminUsersIndexPage = lazy(() => import('@/pages/admin/users'));
const AdminUserDetailPage = lazy(() => import('@/pages/admin/users/detail'));
const AdminCreateUserPage = lazy(() => import('@/pages/admin/users/create'));

// Alerts (Task A2)
const AlertsListPage = lazy(() => import('@/pages/alerts'));

// Gov tables
const AdminGovTablesPage = lazy(() => import('@/pages/admin/gov-tables'));

// Audit log diff (Sprint 8 — Task 79; list page already exists)
const AuditLogDetailPage = lazy(() => import('@/pages/admin/audit-logs/detail'));

export const adminRoutes = (
  <>
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
      path="/admin/roles/create"
      element={
        <PermissionGuard permission="admin.roles.manage">
          <CreateRolePage />
        </PermissionGuard>
      }
    />
    {/* ADV4 — must come before /admin/roles/:id/permissions to avoid being captured. */}
    <Route
      path="/admin/roles/compare"
      element={
        <PermissionGuard permission="admin.roles.manage">
          <CompareRolesPage />
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

    {/* Series E (E2) — scheduled CSV/Excel exports */}
    <Route
      path="/admin/scheduled-exports"
      element={
        <PermissionGuard permission="admin.scheduled_exports.view">
          <ScheduledExportsPage />
        </PermissionGuard>
      }
    />

    {/* U2 — Admin user management */}
    <Route
      path="/admin/users"
      element={
        <PermissionGuard permission="admin.users.manage">
          <AdminUsersIndexPage />
        </PermissionGuard>
      }
    />
    <Route
      path="/admin/users/create"
      element={
        <PermissionGuard permission="admin.users.manage">
          <AdminCreateUserPage />
        </PermissionGuard>
      }
    />
    <Route
      path="/admin/users/:id"
      element={
        <PermissionGuard permission="admin.users.manage">
          <AdminUserDetailPage />
        </PermissionGuard>
      }
    />
    <Route
      path="/alerts"
      element={
        <PermissionGuard permission="alerts.view">
          <AlertsListPage />
        </PermissionGuard>
      }
    />
    <Route
      path="/admin/gov-tables"
      element={
        <PermissionGuard permission="admin.gov_tables.manage">
          <AdminGovTablesPage />
        </PermissionGuard>
      }
    />

    {/* Audit log diff detail (Sprint 8 — Task 79) */}
    <Route path="/admin/audit-logs/:id"
      element={<PermissionGuard permission="admin.audit_logs.view"><AuditLogDetailPage /></PermissionGuard>} />
  </>
);
