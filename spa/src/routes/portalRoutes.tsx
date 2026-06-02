import { lazy } from 'react';
import { Route } from 'react-router-dom';

// ADV10 — B2B Portals (Supplier + Customer)
const SupplierPortalLayout            = lazy(() => import('@/layouts/SupplierPortalLayout'));
const SupplierPortalLoginPage         = lazy(() => import('@/pages/portal/supplier/login'));
const SupplierPortalDashboardPage     = lazy(() => import('@/pages/portal/supplier/dashboard'));
const SupplierPurchaseOrdersPage      = lazy(() => import('@/pages/portal/supplier/purchase-orders'));
const SupplierPurchaseOrderDetailPage = lazy(() => import('@/pages/portal/supplier/purchase-orders/detail'));
const SupplierInvoicesPage            = lazy(() => import('@/pages/portal/supplier/invoices'));
const SupplierInvoiceDetailPage       = lazy(() => import('@/pages/portal/supplier/invoices/detail'));
const SupplierDeliveriesPage          = lazy(() => import('@/pages/portal/supplier/deliveries'));
const SupplierStatementOfAccountPage  = lazy(() => import('@/pages/portal/supplier/statement-of-account'));
const SupplierDeliverySchedulesPage   = lazy(() => import('@/pages/portal/supplier/delivery-schedules'));
const CustomerPortalLayout            = lazy(() => import('@/layouts/CustomerPortalLayout'));
const CustomerPortalLoginPage         = lazy(() => import('@/pages/portal/customer/login'));
const CustomerPortalDashboardPage     = lazy(() => import('@/pages/portal/customer/dashboard'));
const CustomerOrdersPage              = lazy(() => import('@/pages/portal/customer/orders'));
const CustomerOrderDetailPage         = lazy(() => import('@/pages/portal/customer/orders/detail'));
const CustomerInvoicesPage            = lazy(() => import('@/pages/portal/customer/invoices'));
const CustomerInvoiceDetailPage       = lazy(() => import('@/pages/portal/customer/invoices/detail'));
const CustomerDeliveriesPage          = lazy(() => import('@/pages/portal/customer/deliveries'));
const CustomerDeliveryDetailPage      = lazy(() => import('@/pages/portal/customer/deliveries/detail'));
const CustomerComplaintsPage          = lazy(() => import('@/pages/portal/customer/complaints'));
const CustomerStatementOfAccountPage  = lazy(() => import('@/pages/portal/customer/statement-of-account'));
const CustomerDeliverySchedulesPage   = lazy(() => import('@/pages/portal/customer/delivery-schedules'));

export const portalRoutes = (
  <>
    {/* ADV10 — B2B Supplier Portal */}
    <Route path="/portal/supplier/login" element={<SupplierPortalLoginPage />} />
    <Route element={<SupplierPortalLayout />}>
      <Route path="/portal/supplier" element={<SupplierPortalDashboardPage />} />
      <Route path="/portal/supplier/purchase-orders" element={<SupplierPurchaseOrdersPage />} />
      <Route path="/portal/supplier/purchase-orders/:id" element={<SupplierPurchaseOrderDetailPage />} />
      <Route path="/portal/supplier/invoices" element={<SupplierInvoicesPage />} />
      <Route path="/portal/supplier/invoices/:id" element={<SupplierInvoiceDetailPage />} />
      <Route path="/portal/supplier/deliveries" element={<SupplierDeliveriesPage />} />
      <Route path="/portal/supplier/statement-of-account" element={<SupplierStatementOfAccountPage />} />
      <Route path="/portal/supplier/delivery-schedules" element={<SupplierDeliverySchedulesPage />} />
    </Route>

    {/* ADV10 — B2B Customer Portal */}
    <Route path="/portal/customer/login" element={<CustomerPortalLoginPage />} />
    <Route element={<CustomerPortalLayout />}>
      <Route path="/portal/customer" element={<CustomerPortalDashboardPage />} />
      <Route path="/portal/customer/orders" element={<CustomerOrdersPage />} />
      <Route path="/portal/customer/orders/:id" element={<CustomerOrderDetailPage />} />
      <Route path="/portal/customer/invoices" element={<CustomerInvoicesPage />} />
      <Route path="/portal/customer/invoices/:id" element={<CustomerInvoiceDetailPage />} />
      <Route path="/portal/customer/deliveries" element={<CustomerDeliveriesPage />} />
      <Route path="/portal/customer/deliveries/:id" element={<CustomerDeliveryDetailPage />} />
      <Route path="/portal/customer/complaints" element={<CustomerComplaintsPage />} />
      <Route path="/portal/customer/statement-of-account" element={<CustomerStatementOfAccountPage />} />
      <Route path="/portal/customer/delivery-schedules" element={<CustomerDeliverySchedulesPage />} />
    </Route>
  </>
);
