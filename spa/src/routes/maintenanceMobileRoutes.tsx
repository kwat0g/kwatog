import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Maintenance Mobile PWA — mobile-first, no sidebar, tech-scoped
const MaintenanceMobileLayout     = lazy(() => import('@/layouts/MaintenanceMobileLayout'));
const MobileMaintenanceList       = lazy(() => import('@/pages/maintenance/mobile'));
const MobileWorkOrderDetail       = lazy(() => import('@/pages/maintenance/mobile/work-order'));
const MobileConditionReading      = lazy(() => import('@/pages/maintenance/mobile/condition-reading'));

export const maintenanceMobileRoutes = (
  <Route
    element={
      <AuthGuard>
        <ModuleGuard module="maintenance">
          <PermissionGuard permission="maintenance.view">
            <MaintenanceMobileLayout />
          </PermissionGuard>
        </ModuleGuard>
      </AuthGuard>
    }
  >
    <Route path="/maintenance/mobile"                      element={<MobileMaintenanceList />} />
    <Route path="/maintenance/mobile/condition-reading"     element={<MobileConditionReading />} />
    <Route path="/maintenance/mobile/:mwoId"               element={<MobileWorkOrderDetail />} />
  </Route>
);
