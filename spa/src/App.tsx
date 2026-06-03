import { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { AppLayout } from '@/layouts/AppLayout';
import { FullPageLoader } from '@/components/ui/Spinner';

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

// Errors
const NotFoundPage = lazy(() => import('@/pages/error/NotFound'));

export default function App() {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <Routes>
        {/* Auth routes (no AuthGuard) */}
        {authRoutes}

        {/* Authenticated app shell */}
        <Route
          element={
            <AuthGuard>
              <AppLayout />
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

        {/* B2B Portals — Supplier + Customer */}
        {portalRoutes}

        {/* 404 */}
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}
