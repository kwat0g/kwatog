import { lazy } from 'react';
import { Outlet, Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// T2.5 — Driver delivery surface (self-scoped to driver_id).
//
// These routes render inside the standard app shell (AppLayout → sidebar +
// topbar + breadcrumbs) like every other module. They used to hang off a
// standalone shell with no chrome at all, which meant the one role whose job
// is a phone screen lost the run sheet's navigation: no way to reach the list
// from a detail page except a hand-rolled back link. The pages themselves stay
// phone-first (cards under `md`, 44px controls), the frame around them is the
// one the rest of the product uses.
const DriverDeliveryList = lazy(() => import('@/pages/driver/DriverDeliveryList'));
const DriverDeliveryDetail = lazy(() => import('@/pages/driver/DriverDeliveryDetail'));
const DriverPhotoCapture = lazy(() => import('@/pages/driver/DriverPhotoCapture'));

export const driverRoutes = (
 <Route
 element={
 <AuthGuard>
 <ModuleGuard module="supply_chain">
 <PermissionGuard permission="supply_chain.driver.access">
 <Outlet />
 </PermissionGuard>
 </ModuleGuard>
 </AuthGuard>
 }
 >
 <Route path="/driver" element={<DriverDeliveryList />} />
 <Route path="/driver/:id" element={<DriverDeliveryDetail />} />
 <Route path="/driver/:id/photo" element={<DriverPhotoCapture />} />
 </Route>
);
