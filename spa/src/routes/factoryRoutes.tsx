import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Factory Floor PWA — mobile-first, no sidebar, operator-scoped
const FactoryFloorLayout = lazy(() => import('@/layouts/FactoryFloorLayout'));
const ActiveOrders       = lazy(() => import('@/pages/factory/ActiveOrders'));
const RecordOutput       = lazy(() => import('@/pages/factory/RecordOutput'));
const QcQuickCheck       = lazy(() => import('@/pages/factory/QcQuickCheck'));

export const factoryRoutes = (
  <Route
    element={
      <AuthGuard>
        <PermissionGuard permission="production.operator.access">
          <FactoryFloorLayout />
        </PermissionGuard>
      </AuthGuard>
    }
  >
    <Route path="/factory"             element={<ActiveOrders />} />
    <Route path="/factory/:woId/output" element={<RecordOutput />} />
    <Route path="/factory/qc"          element={<QcQuickCheck />} />
  </Route>
);
