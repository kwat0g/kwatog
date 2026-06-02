import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Inventory (Sprint 5 — Tasks 39-41, 46)
const InventoryDashboardPage    = lazy(() => import('@/pages/inventory/dashboard'));
const ItemsListPage             = lazy(() => import('@/pages/inventory/items'));
const CreateItemPage            = lazy(() => import('@/pages/inventory/items/create'));
const EditItemPage              = lazy(() => import('@/pages/inventory/items/edit'));
const ItemDetailPage            = lazy(() => import('@/pages/inventory/items/detail'));
const ItemCategoriesPage        = lazy(() => import('@/pages/inventory/categories'));
const WarehousePage             = lazy(() => import('@/pages/inventory/warehouse'));
const StockLevelsPage           = lazy(() => import('@/pages/inventory/stock-levels'));
const StockMovementsPage        = lazy(() => import('@/pages/inventory/movements'));
const CreateStockAdjustmentPage = lazy(() => import('@/pages/inventory/stock-adjustments/create'));
const CreateStockTransferPage   = lazy(() => import('@/pages/inventory/stock-transfers/create'));
const GrnListPage               = lazy(() => import('@/pages/inventory/grn'));
const CreateGrnPage             = lazy(() => import('@/pages/inventory/grn/create'));
const GrnDetailPage             = lazy(() => import('@/pages/inventory/grn/detail'));
const MaterialIssuesListPage    = lazy(() => import('@/pages/inventory/material-issues'));
const CreateMaterialIssuePage   = lazy(() => import('@/pages/inventory/material-issues/create'));

// Series F / Task F3 — per-item stock card
const StockCardPage = lazy(() => import('@/pages/inventory/items/stock-card'));

// ADV8 — WMS (Warehouse Management System)
const WarehouseMapPage   = lazy(() => import('@/pages/warehouse/map'));
const StockCountPage     = lazy(() => import('@/pages/warehouse/stock-count'));
const PickingListPage    = lazy(() => import('@/pages/warehouse/picking'));
const TransferOrdersPage = lazy(() => import('@/pages/warehouse/transfers'));

export const inventoryRoutes = (
  <>
    {/* Inventory module (Sprint 5) */}
    <Route element={<ModuleGuard module="inventory" />}>
      <Route path="/inventory" element={<Navigate to="/inventory/dashboard" replace />} />
      <Route path="/inventory/dashboard"
        element={<PermissionGuard permission="inventory.view"><InventoryDashboardPage /></PermissionGuard>} />

      <Route path="/inventory/items"
        element={<PermissionGuard permission="inventory.view"><ItemsListPage /></PermissionGuard>} />
      <Route path="/inventory/items/create"
        element={<PermissionGuard permission="inventory.items.manage"><CreateItemPage /></PermissionGuard>} />
      <Route path="/inventory/items/:id"
        element={<PermissionGuard permission="inventory.view"><ItemDetailPage /></PermissionGuard>} />
      <Route path="/inventory/items/:id/edit"
        element={<PermissionGuard permission="inventory.items.manage"><EditItemPage /></PermissionGuard>} />
      {/* Series F / Task F3 — per-item stock card */}
      <Route path="/inventory/items/:id/stock-card"
        element={<PermissionGuard permission="inventory.view"><StockCardPage /></PermissionGuard>} />

      <Route path="/inventory/categories"
        element={<PermissionGuard permission="inventory.view"><ItemCategoriesPage /></PermissionGuard>} />
      <Route path="/inventory/warehouse"
        element={<PermissionGuard permission="inventory.view"><WarehousePage /></PermissionGuard>} />

      <Route path="/inventory/stock-levels"
        element={<PermissionGuard permission="inventory.view"><StockLevelsPage /></PermissionGuard>} />
      <Route path="/inventory/movements"
        element={<PermissionGuard permission="inventory.view"><StockMovementsPage /></PermissionGuard>} />
      <Route path="/inventory/stock-adjustments/create"
        element={<PermissionGuard permission="inventory.adjust"><CreateStockAdjustmentPage /></PermissionGuard>} />
      <Route path="/inventory/stock-transfers/create"
        element={<PermissionGuard permission="inventory.adjust"><CreateStockTransferPage /></PermissionGuard>} />

      {/* ADV8 — WMS (Warehouse Management System) */}
      <Route path="/inventory/warehouse-map"
        element={<PermissionGuard permission="inventory.view"><WarehouseMapPage /></PermissionGuard>} />
      <Route path="/inventory/stock-count"
        element={<PermissionGuard permission="inventory.view"><StockCountPage /></PermissionGuard>} />
      <Route path="/inventory/picking"
        element={<PermissionGuard permission="inventory.view"><PickingListPage /></PermissionGuard>} />
      <Route path="/inventory/transfer-orders"
        element={<PermissionGuard permission="inventory.view"><TransferOrdersPage /></PermissionGuard>} />

      <Route path="/inventory/grn"
        element={<PermissionGuard permission="inventory.view"><GrnListPage /></PermissionGuard>} />
      <Route path="/inventory/grn/create"
        element={<PermissionGuard permission="inventory.grn.create"><CreateGrnPage /></PermissionGuard>} />
      <Route path="/inventory/grn/:id"
        element={<PermissionGuard permission="inventory.view"><GrnDetailPage /></PermissionGuard>} />

      <Route path="/inventory/material-issues"
        element={<PermissionGuard permission="inventory.view"><MaterialIssuesListPage /></PermissionGuard>} />
      <Route path="/inventory/material-issues/create"
        element={<PermissionGuard permission="inventory.issue.create"><CreateMaterialIssuePage /></PermissionGuard>} />
    </Route>
  </>
);
