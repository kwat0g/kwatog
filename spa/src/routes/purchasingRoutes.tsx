import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Purchasing (Sprint 5 — Tasks 42-44)
const PurchaseRequestsListPage  = lazy(() => import('@/pages/purchasing/purchase-requests'));
const CreatePurchaseRequestPage = lazy(() => import('@/pages/purchasing/purchase-requests/create'));
const PurchaseRequestDetailPage = lazy(() => import('@/pages/purchasing/purchase-requests/detail'));
const PurchaseOrdersListPage    = lazy(() => import('@/pages/purchasing/purchase-orders'));
const CreatePurchaseOrderPage   = lazy(() => import('@/pages/purchasing/purchase-orders/create'));
const PurchaseOrderDetailPage   = lazy(() => import('@/pages/purchasing/purchase-orders/detail'));
const ApprovedSuppliersPage     = lazy(() => import('@/pages/purchasing/approved-suppliers'));

// ADV6 — PR Templates
const PrTemplatesListPage = lazy(() => import('@/pages/purchasing/pr-templates'));
const PrTemplateFormPage  = lazy(() => import('@/pages/purchasing/pr-templates/create'));

// ADV5 — Procurement Chain overview
const ProcurementChainPage = lazy(() => import('@/pages/purchasing/chain'));

// Series F / Task F4 — Supplier performance dashboard
const SupplierPerformancePage = lazy(() => import('@/pages/purchasing/suppliers/performance'));

export const purchasingRoutes = (
  <>
    {/* Purchasing module (Sprint 5) */}
    <Route element={<ModuleGuard module="purchasing" />}>
      <Route path="/purchasing" element={<Navigate to="/purchasing/purchase-orders" replace />} />

      <Route path="/purchasing/purchase-requests"
        element={<PermissionGuard permission="purchasing.view"><PurchaseRequestsListPage /></PermissionGuard>} />
      <Route path="/purchasing/purchase-requests/create"
        element={<PermissionGuard permission="purchasing.pr.create"><CreatePurchaseRequestPage /></PermissionGuard>} />
      <Route path="/purchasing/purchase-requests/:id"
        element={<PermissionGuard permission="purchasing.view"><PurchaseRequestDetailPage /></PermissionGuard>} />

      <Route path="/purchasing/purchase-orders"
        element={<PermissionGuard permission="purchasing.view"><PurchaseOrdersListPage /></PermissionGuard>} />
      <Route path="/purchasing/purchase-orders/create"
        element={<PermissionGuard permission="purchasing.po.create"><CreatePurchaseOrderPage /></PermissionGuard>} />
      <Route path="/purchasing/purchase-orders/:id"
        element={<PermissionGuard permission="purchasing.view"><PurchaseOrderDetailPage /></PermissionGuard>} />

      <Route path="/purchasing/approved-suppliers"
        element={<PermissionGuard permission="purchasing.view"><ApprovedSuppliersPage /></PermissionGuard>} />

      {/* ADV6 — PR Templates */}
      <Route path="/purchasing/pr-templates"
        element={<PermissionGuard permission="purchasing.view"><PrTemplatesListPage /></PermissionGuard>} />
      <Route path="/purchasing/pr-templates/create"
        element={<PermissionGuard permission="purchasing.pr.create"><PrTemplateFormPage /></PermissionGuard>} />
      <Route path="/purchasing/pr-templates/:id/edit"
        element={<PermissionGuard permission="purchasing.pr.create"><PrTemplateFormPage /></PermissionGuard>} />

      {/* ADV5 — Procurement Chain overview */}
      <Route path="/purchasing/chain"
        element={<PermissionGuard permission="purchasing.view"><ProcurementChainPage /></PermissionGuard>} />

      {/* Series F / Task F4 — Supplier performance dashboard */}
      <Route path="/purchasing/suppliers/:id/performance"
        element={<PermissionGuard permission="purchasing.suppliers.performance.view"><SupplierPerformancePage /></PermissionGuard>} />
    </Route>
  </>
);
