import { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { ErrorBoundary } from '@/components/guards/ErrorBoundary';
import { AppLayout } from '@/layouts/AppLayout';
import { TopLoadingBar } from '@/components/ui/TopLoadingBar';

import { landingRoutes } from '@/routes/landingRoutes';
import { authRoutes } from '@/routes/authRoutes';
import { dashboardRoutes } from '@/routes/dashboardRoutes';
import { adminRoutes } from '@/routes/adminRoutes';
import { hrRoutes } from '@/routes/hrRoutes';
import { payrollRoutes } from '@/routes/payrollRoutes';
import { accountingRoutes } from '@/routes/accountingRoutes';
import { inventoryRoutes } from '@/routes/inventoryRoutes';
import { purchasingRoutes } from '@/routes/purchasingRoutes';
import { crmRoutes } from '@/routes/crmRoutes';
import { mrpRoutes } from '@/routes/mrpRoutes';
import { qualityRoutes } from '@/routes/qualityRoutes';
import { supplyChainRoutes } from '@/routes/supplyChainRoutes';
import { productionRoutes } from '@/routes/productionRoutes';
import { maintenanceRoutes } from '@/routes/maintenanceRoutes';
import { assetsRoutes } from '@/routes/assetsRoutes';
import { advancedRoutes } from '@/routes/advancedRoutes';
import { selfServiceRoutes } from '@/routes/selfServiceRoutes';
import { portalRoutes } from '@/routes/portalRoutes';
import { careersRoutes } from '@/routes/careersRoutes';
import { driverRoutes } from '@/routes/driverRoutes';
import { factoryRoutes } from '@/routes/factoryRoutes';
import { maintenanceMobileRoutes } from '@/routes/maintenanceMobileRoutes';

// Errors
const NotFoundPage = lazy(() => import('@/pages/error/NotFound'));

export default function App() {
  return (
    <Suspense fallback={<TopLoadingBar />}>
      <Routes>
        {/* Public landing page */}
        {landingRoutes}
        {careersRoutes}

        {/* Auth routes (no AuthGuard) */}
        {authRoutes}

        {/* Authenticated app shell */}
        <Route
          element={
            <AuthGuard>
              <ErrorBoundary>
                <AppLayout />
              </ErrorBoundary>
            </AuthGuard>
          }
        >
          {dashboardRoutes}
          {adminRoutes}
          {hrRoutes}
          {payrollRoutes}
          {accountingRoutes}
          {inventoryRoutes}
          {purchasingRoutes}
          {crmRoutes}
          {mrpRoutes}
          {qualityRoutes}
          {supplyChainRoutes}
          {productionRoutes}
          {maintenanceRoutes}
          {assetsRoutes}
          {advancedRoutes}
          {selfServiceRoutes}
        </Route>

        {/* Driver PWA — T2.5
            Uses AuthGuard with the main session (drivers log in via /login).
            DriverLayout renders a mobile-first shell with no sidebar. */}
        {driverRoutes}

        {/* Factory Floor PWA — Mobile-first for shop floor operators.
            Uses AuthGuard with the main session (operators log in via /login).
            FactoryFloorLayout renders a mobile-first shell with bottom nav. */}
        {factoryRoutes}

        {/* Maintenance Mobile PWA — Mobile-first for maintenance techs.
            Uses AuthGuard with the main session (techs log in via /login).
            MaintenanceMobileLayout renders a mobile-first shell with bottom nav. */}
        {maintenanceMobileRoutes}

        {/* B2B Portals — Supplier + Customer
            SECURITY: Portal routes are deliberately outside the main AuthGuard
            because they use a separate auth session (supplier/customer accounts).
            Each portal layout (SupplierPortalLayout / CustomerPortalLayout) performs
            its own bootstrap + redirect-to-login. Never render a portal page
            outside its layout wrapper — that would bypass auth entirely. */}
        {portalRoutes}

        {/* 404 */}
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}
