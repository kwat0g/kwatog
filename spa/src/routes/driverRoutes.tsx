import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// T2.5 — Driver PWA (mobile-first, no sidebar, self-scoped to driver_id)
const DriverLayout         = lazy(() => import('@/layouts/DriverLayout'));
const DriverDeliveryList   = lazy(() => import('@/pages/driver/DriverDeliveryList'));
const DriverDeliveryDetail = lazy(() => import('@/pages/driver/DriverDeliveryDetail'));
const DriverPhotoCapture   = lazy(() => import('@/pages/driver/DriverPhotoCapture'));

export const driverRoutes = (
  <Route
    element={
      <AuthGuard>
        <PermissionGuard permission="supply_chain.driver.access">
          <DriverLayout />
        </PermissionGuard>
      </AuthGuard>
    }
  >
    <Route path="/driver"            element={<DriverDeliveryList />} />
    <Route path="/driver/:id"        element={<DriverDeliveryDetail />} />
    <Route path="/driver/:id/photo"  element={<DriverPhotoCapture />} />
  </Route>
);
