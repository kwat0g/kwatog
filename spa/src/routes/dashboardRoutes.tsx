import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

const DashboardPage = lazy(() => import('@/pages/dashboard'));
// Task D1 — direct escape hatch to the generic widget-layout home, so users
// whose role redirects them away can still reach it explicitly.
const DashboardDefaultPage = lazy(() => import('@/pages/dashboard/default'));

// Sprint 8 dashboards (Tasks 72 + 73)
const PlantManagerDashboardPage = lazy(() => import('@/pages/dashboard/plant-manager'));
const HrDashboardPage           = lazy(() => import('@/pages/dashboard/hr'));
const PpcDashboardPage          = lazy(() => import('@/pages/dashboard/ppc'));
// Task D5 — dedicated Finance Officer dashboard.
const FinanceDashboardPage      = lazy(() => import('@/pages/dashboard/finance'));

// D3, D6, D7, D8 — Role-specific dashboards
const PurchasingDashboardPage  = lazy(() => import('@/pages/dashboard/purchasing'));
const WarehouseDashboardPage   = lazy(() => import('@/pages/dashboard/warehouse'));
const QcDashboardPage          = lazy(() => import('@/pages/dashboard/quality'));
const AdminDashboardPage       = lazy(() => import('@/pages/dashboard/admin'));

// Cross-module pages (no specific module guard)
const CalendarPage       = lazy(() => import('@/pages/calendar'));
const ApprovalsBoardPage = lazy(() => import('@/pages/approvals'));
const ChainTrackerPage   = lazy(() => import('@/pages/chains'));
const NotificationsListPage = lazy(() => import('@/pages/notifications'));

const AdminUsersRolesHubPage = lazy(() => import('@/pages/admin/users-roles'));

const AdminActivityFeedPage = lazy(() => import('@/pages/admin/activity'));



export const dashboardRoutes = (
  <>
    {/* Task D1 — `/dashboard` is the role router; `/dashboard/default`
        is the explicit escape hatch to the generic widget-layout page. */}
    <Route path="/dashboard" element={<DashboardPage />} />
    <Route path="/dashboard/default" element={<DashboardDefaultPage />} />

    {/* Sprint 8 dashboards */}
    <Route path="/dashboard/plant-manager"
      element={<PermissionGuard permission="dashboard.plant_manager.view"><PlantManagerDashboardPage /></PermissionGuard>} />
    <Route path="/dashboard/hr"
      element={<PermissionGuard permission="dashboard.hr.view"><HrDashboardPage /></PermissionGuard>} />
    <Route path="/dashboard/ppc"
      element={<PermissionGuard permission="dashboard.ppc.view"><PpcDashboardPage /></PermissionGuard>} />
    {/* Task D1 — `/dashboard/finance` is the canonical Finance Officer dashboard.
        `/dashboard/accounting` is kept as a permanent redirect. */}
    <Route path="/dashboard/finance"
      element={<PermissionGuard permission="dashboard.accounting.view"><FinanceDashboardPage /></PermissionGuard>} />
    <Route path="/dashboard/accounting"
      element={<Navigate to="/dashboard/finance" replace />} />
    {/* D6, D7, D8 — New role-specific dashboards */}
    <Route path="/dashboard/purchasing"
      element={<PermissionGuard permission="dashboard.purchasing.view"><PurchasingDashboardPage /></PermissionGuard>} />
    <Route path="/dashboard/warehouse"
      element={<PermissionGuard permission="dashboard.warehouse.view"><WarehouseDashboardPage /></PermissionGuard>} />
    <Route path="/dashboard/quality"
      element={<PermissionGuard permission="dashboard.quality.view"><QcDashboardPage /></PermissionGuard>} />
    <Route path="/dashboard/admin"
      element={<PermissionGuard permission="dashboard.admin.view"><AdminDashboardPage /></PermissionGuard>} />

    {/* Series F / Task F1 — Cross-module calendar */}
    <Route
      path="/calendar"
      element={<PermissionGuard permission="calendar.view"><CalendarPage /></PermissionGuard>}
    />

    {/* Series F / Task F2 — Approvals Kanban board */}
    <Route
      path="/approvals"
      element={<PermissionGuard permission="approvals.board.view"><ApprovalsBoardPage /></PermissionGuard>}
    />

    {/* Chain Tracker — cross-module order-to-cash journey view */}
    <Route
      path="/chains"
      element={<PermissionGuard permission="crm.sales_orders.view"><ChainTrackerPage /></PermissionGuard>}
    />

    {/* Series F / Task F7 — System activity feed */}
    <Route
      path="/admin/activity"
      element={<PermissionGuard permission="admin.activity.view"><AdminActivityFeedPage /></PermissionGuard>}
    />

    <Route path="/admin/users-roles"
      element={<PermissionGuard permission="admin.users.manage"><AdminUsersRolesHubPage /></PermissionGuard>} />

    {/* Notifications page (Sprint 8 — Task 77) */}
    <Route path="/notifications" element={<NotificationsListPage />} />
  </>
);
