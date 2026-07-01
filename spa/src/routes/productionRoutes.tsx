import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Production (Sprint 6 — Tasks 51, 53–58; WO create added in audit §3.1)
const WorkOrdersListPage     = lazy(() => import('@/pages/production/work-orders'));
const CreateWorkOrderPage    = lazy(() => import('@/pages/production/work-orders/create'));
const WorkOrderDetailPage    = lazy(() => import('@/pages/production/work-orders/detail'));
const RecordOutputPage       = lazy(() => import('@/pages/production/work-orders/record-output'));
const ProductionSchedulePage = lazy(() => import('@/pages/production/schedule'));
const ProductionDashboardPage = lazy(() => import('@/pages/production/dashboard'));
const OeeReportPage          = lazy(() => import('@/pages/production/oee'));

// Task 12 — Production Routings
const RoutingsListPage  = lazy(() => import('@/pages/production/routings'));
const RoutingEditorPage = lazy(() => import('@/pages/production/routings/editor'));

export const productionRoutes = (
  <>
    {/* Production module (Sprint 6 — Tasks 51, 54, 55, 58; WO create added in audit §3.1) */}
    <Route element={<ModuleGuard module="production" />}>
      {/* Sprint 6 audit §3.5: redirect /production to work orders so users
          without the dashboard permission don't hit a 403 immediately.
          The dashboard remains the natural home for those who have it. */}
      <Route path="/production" element={<Navigate to="/production/work-orders" replace />} />

      <Route path="/production/dashboard"
        element={<PermissionGuard permission="production.dashboard.view"><ProductionDashboardPage /></PermissionGuard>} />
      {/* Sprint P10 — full OEE report. */}
      <Route path="/production/oee"
        element={<PermissionGuard permission="production.dashboard.view"><OeeReportPage /></PermissionGuard>} />

      <Route path="/production/schedule"
        element={<PermissionGuard permission="production.schedule.view"><ProductionSchedulePage /></PermissionGuard>} />

      <Route path="/production/work-orders"
        element={<PermissionGuard permission="production.work_orders.view"><WorkOrdersListPage /></PermissionGuard>} />
      <Route path="/production/work-orders/create"
        element={<PermissionGuard permission="production.wo.create"><CreateWorkOrderPage /></PermissionGuard>} />
      <Route path="/production/work-orders/:id"
        element={<PermissionGuard permission="production.work_orders.view"><WorkOrderDetailPage /></PermissionGuard>} />
      <Route path="/production/work-orders/:id/record-output"
        element={<PermissionGuard permission="production.wo.record"><RecordOutputPage /></PermissionGuard>} />

      {/* Task 12 — Production Routings */}
      <Route path="/production/routings"
        element={<PermissionGuard permission="production.routings.view"><RoutingsListPage /></PermissionGuard>} />
      <Route path="/production/routings/create"
        element={<PermissionGuard permission="production.routings.manage"><RoutingEditorPage /></PermissionGuard>} />
      <Route path="/production/routings/:id"
        element={<PermissionGuard permission="production.routings.manage"><RoutingEditorPage /></PermissionGuard>} />
    </Route>
  </>
);
