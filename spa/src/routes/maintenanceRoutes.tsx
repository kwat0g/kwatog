import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Maintenance (Sprint 8 — Task 69)
const MaintenanceHomePage           = lazy(() => import('@/pages/maintenance'));
const MaintenanceWorkOrdersListPage = lazy(() => import('@/pages/maintenance/work-orders'));
const CreateMaintenanceWorkOrderPage = lazy(() => import('@/pages/maintenance/work-orders/create'));
const MaintenanceWorkOrderDetailPage = lazy(() => import('@/pages/maintenance/work-orders/detail'));
const MaintenanceSchedulesListPage  = lazy(() => import('@/pages/maintenance/schedules'));
const CreateMaintenanceSchedulePage = lazy(() => import('@/pages/maintenance/schedules/create'));
const MaintenanceScheduleDetailPage = lazy(() => import('@/pages/maintenance/schedules/detail'));
const EditMaintenanceSchedulePage   = lazy(() => import('@/pages/maintenance/schedules/edit'));
// ADV8 — Predictive maintenance & downtime analytics
const MachineHealthPage     = lazy(() => import('@/pages/maintenance/machine-health'));
const DowntimeAnalyticsPage = lazy(() => import('@/pages/maintenance/downtime'));

export const maintenanceRoutes = (
  <>
    {/* Maintenance module (Sprint 8 — Task 69 + ADV8) */}
    <Route element={<ModuleGuard module="maintenance" />}>
      <Route path="/maintenance" element={<MaintenanceHomePage />} />
      {/* ADV8 — Predictive maintenance & downtime analytics */}
      <Route path="/maintenance/machine-health"
        element={<PermissionGuard permission="maintenance.view"><MachineHealthPage /></PermissionGuard>} />
      <Route path="/maintenance/downtime"
        element={<PermissionGuard permission="maintenance.view"><DowntimeAnalyticsPage /></PermissionGuard>} />
      <Route path="/maintenance/work-orders"
        element={<PermissionGuard permission="maintenance.view"><MaintenanceWorkOrdersListPage /></PermissionGuard>} />
      <Route path="/maintenance/work-orders/create"
        element={<PermissionGuard permission="maintenance.wo.create"><CreateMaintenanceWorkOrderPage /></PermissionGuard>} />
      <Route path="/maintenance/work-orders/:id"
        element={<PermissionGuard permission="maintenance.view"><MaintenanceWorkOrderDetailPage /></PermissionGuard>} />
      <Route path="/maintenance/schedules"
        element={<PermissionGuard permission="maintenance.view"><MaintenanceSchedulesListPage /></PermissionGuard>} />
      <Route path="/maintenance/schedules/create"
        element={<PermissionGuard permission="maintenance.schedules.manage"><CreateMaintenanceSchedulePage /></PermissionGuard>} />
      <Route path="/maintenance/schedules/:id"
        element={<PermissionGuard permission="maintenance.view"><MaintenanceScheduleDetailPage /></PermissionGuard>} />
      <Route path="/maintenance/schedules/:id/edit"
        element={<PermissionGuard permission="maintenance.schedules.manage"><EditMaintenanceSchedulePage /></PermissionGuard>} />
    </Route>
  </>
);
