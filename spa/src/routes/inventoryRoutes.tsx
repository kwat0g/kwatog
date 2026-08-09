import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Inventory (Sprint 5 — Tasks 39-41, 46)
const InventoryDashboardPage = lazy(() => import('@/pages/inventory/dashboard'));
const ItemsListPage = lazy(() => import('@/pages/inventory/items'));
const CreateItemPage = lazy(() => import('@/pages/inventory/items/create'));
const EditItemPage = lazy(() => import('@/pages/inventory/items/edit'));
const ItemDetailPage = lazy(() => import('@/pages/inventory/items/detail'));
const ItemQualityPlansPage = lazy(() => import('@/pages/inventory/items/quality-plans'));
// /inventory/categories removed 2026-08-08 (scope cut — folded into the Items
// page as a Categories modal; page file kept as ItemCategoriesManager)
const WarehousePage = lazy(() => import('@/pages/inventory/warehouse'));
const StockLevelsPage = lazy(() => import('@/pages/inventory/stock-levels'));
// /inventory/movements removed 2026-08-08 (scope cut — now a view toggle on Stock Levels)
const StockAdjustmentsPage = lazy(() => import('@/pages/inventory/stock-adjustments'));
const CreateStockAdjustmentPage = lazy(() => import('@/pages/inventory/stock-adjustments/create'));
const GrnListPage = lazy(() => import('@/pages/inventory/grn'));
const CreateGrnPage = lazy(() => import('@/pages/inventory/grn/create'));
const GrnDetailPage = lazy(() => import('@/pages/inventory/grn/detail'));
const MaterialIssuesListPage = lazy(() => import('@/pages/inventory/material-issues'));
const CreateMaterialIssuePage = lazy(() => import('@/pages/inventory/material-issues/create'));
const MaterialIssueDetailPage = lazy(() => import('@/pages/inventory/material-issues/detail'));
const MrbListPage = lazy(() => import('@/pages/inventory/mrb'));
const MrbDetailPage = lazy(() => import('@/pages/inventory/mrb/detail'));

// Series F / Task F3 — per-item stock card
const StockCardPage = lazy(() => import('@/pages/inventory/items/stock-card'));

// ADV8 — WMS (Warehouse Management System)
const WarehouseMapPage = lazy(() => import('@/pages/warehouse/map'));
// Stock Count merged into the Warehouse Map page (2026-08-08) — the count
// workflow now lives behind its Map | Stock Count toggle.
const PickingListPage = lazy(() => import('@/pages/warehouse/picking'));
const TransferOrdersPage = lazy(() => import('@/pages/warehouse/transfers'));
const WarehouseScannerPage = lazy(() => import('@/pages/warehouse/scanner'));

export const inventoryRoutes = (
 <>
 {/* Inventory module (Sprint 5) */}
 <Route element={<ModuleGuard module="inventory" />}>
 <Route path="/inventory" element={<Navigate to="/inventory/items" replace />} />
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
 <Route path="/inventory/items/:id/quality-plans"
 element={<PermissionGuard permission="inventory.view"><ItemQualityPlansPage /></PermissionGuard>} />

 {/* /inventory/categories removed 2026-08-08 (scope cut — Categories modal on the Items page) */}
 <Route path="/inventory/warehouse"
 element={<PermissionGuard permission="inventory.view"><WarehousePage /></PermissionGuard>} />

 <Route path="/inventory/stock-levels"
 element={<PermissionGuard permission="inventory.view"><StockLevelsPage /></PermissionGuard>} />
 {/* /inventory/movements removed 2026-08-08 (scope cut — now a view toggle on Stock Levels) */}
 <Route path="/inventory/stock-adjustments"
 element={<PermissionGuard permission="inventory.view"><StockAdjustmentsPage /></PermissionGuard>} />
 <Route path="/inventory/stock-adjustments/create"
 element={<PermissionGuard permission="inventory.adjust"><CreateStockAdjustmentPage /></PermissionGuard>} />
 {/* /inventory/stock-transfers/create removed 2026-08-08 (scope cut — page file kept) */}

 {/* ADV8 — WMS (Warehouse Management System) */}
 <Route path="/inventory/warehouse-map"
 element={<PermissionGuard permission="inventory.view"><WarehouseMapPage /></PermissionGuard>} />
 {/* /inventory/stock-count merged into Warehouse Map (2026-08-08). Path kept
 as a live alias — the barcode scanner links here — and renders the merged
 page in count view (see WarehouseMapPage). */}
 <Route path="/inventory/stock-count"
 element={<PermissionGuard permission="inventory.stock_count.view"><WarehouseMapPage /></PermissionGuard>} />
 <Route path="/inventory/picking"
 element={<PermissionGuard permission="inventory.view"><PickingListPage /></PermissionGuard>} />
 <Route path="/inventory/transfer-orders"
 element={<PermissionGuard permission="inventory.view"><TransferOrdersPage /></PermissionGuard>} />
 <Route path="/inventory/scanner"
 element={<PermissionGuard permission="inventory.view"><WarehouseScannerPage /></PermissionGuard>} />

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
 <Route path="/inventory/material-issues/:id"
 element={<PermissionGuard permission="inventory.view"><MaterialIssueDetailPage /></PermissionGuard>} />

 {/* REC-08 — Material Review Board / quarantine workflow */}
 <Route path="/inventory/mrb"
 element={<PermissionGuard permission="inventory.mrb.view"><MrbListPage /></PermissionGuard>} />
 <Route path="/inventory/mrb/:id"
 element={<PermissionGuard permission="inventory.mrb.view"><MrbDetailPage /></PermissionGuard>} />
 </Route>
 </>
);
