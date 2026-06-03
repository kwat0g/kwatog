import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// CRM (Sprint 6 — Tasks 47, 48; SO edit added in audit §3.1)
const CrmHubPage              = lazy(() => import('@/pages/crm/hub'));
const ProductsListPage        = lazy(() => import('@/pages/crm/products'));
const CreateProductPage       = lazy(() => import('@/pages/crm/products/create'));
const EditProductPage         = lazy(() => import('@/pages/crm/products/edit'));
const ProductDetailPage       = lazy(() => import('@/pages/crm/products/detail'));
const PriceAgreementsListPage = lazy(() => import('@/pages/crm/price-agreements'));
const SalesOrdersListPage     = lazy(() => import('@/pages/crm/sales-orders'));
const CreateSalesOrderPage    = lazy(() => import('@/pages/crm/sales-orders/create'));
const EditSalesOrderPage      = lazy(() => import('@/pages/crm/sales-orders/edit'));
const SalesOrderDetailPage    = lazy(() => import('@/pages/crm/sales-orders/detail'));

// Sprint 7 Task 68 — customer complaints + 8D
const ComplaintsListPage  = lazy(() => import('@/pages/crm/complaints'));
const ComplaintDetailPage = lazy(() => import('@/pages/crm/complaints/detail'));
const ComplaintCreatePage = lazy(() => import('@/pages/crm/complaints/create'));

export const crmRoutes = (
  <>
    {/* CRM module (Sprint 6 — Tasks 47, 48) */}
    <Route element={<ModuleGuard module="crm" />}>
      <Route path="/crm" element={<Navigate to="/crm/hub" replace />} />
      <Route path="/crm/hub"
        element={<PermissionGuard permission="crm.products.view"><CrmHubPage /></PermissionGuard>} />

      <Route path="/crm/products"
        element={<PermissionGuard permission="crm.products.view"><ProductsListPage /></PermissionGuard>} />
      <Route path="/crm/products/create"
        element={<PermissionGuard permission="crm.products.manage"><CreateProductPage /></PermissionGuard>} />
      <Route path="/crm/products/:id"
        element={<PermissionGuard permission="crm.products.view"><ProductDetailPage /></PermissionGuard>} />
      <Route path="/crm/products/:id/edit"
        element={<PermissionGuard permission="crm.products.manage"><EditProductPage /></PermissionGuard>} />

      <Route path="/crm/price-agreements"
        element={<PermissionGuard permission="crm.price_agreements.view"><PriceAgreementsListPage /></PermissionGuard>} />

      <Route path="/crm/sales-orders"
        element={<PermissionGuard permission="crm.sales_orders.view"><SalesOrdersListPage /></PermissionGuard>} />
      <Route path="/crm/sales-orders/create"
        element={<PermissionGuard permission="crm.sales_orders.create"><CreateSalesOrderPage /></PermissionGuard>} />
      <Route path="/crm/sales-orders/:id"
        element={<PermissionGuard permission="crm.sales_orders.view"><SalesOrderDetailPage /></PermissionGuard>} />
      <Route path="/crm/sales-orders/:id/edit"
        element={<PermissionGuard permission="crm.sales_orders.update"><EditSalesOrderPage /></PermissionGuard>} />

      {/* Sprint 7 Task 68 — customer complaints + 8D */}
      <Route path="/crm/complaints"
        element={<PermissionGuard permission="crm.complaints.manage"><ComplaintsListPage /></PermissionGuard>} />
      <Route path="/crm/complaints/new"
        element={<PermissionGuard permission="crm.complaints.manage"><ComplaintCreatePage /></PermissionGuard>} />
      <Route path="/crm/complaints/:id"
        element={<PermissionGuard permission="crm.complaints.manage"><ComplaintDetailPage /></PermissionGuard>} />
    </Route>
  </>
);
