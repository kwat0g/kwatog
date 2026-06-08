import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Assets (Sprint 8 — Task 70)
const AssetsListPage       = lazy(() => import('@/pages/assets'));
const CreateAssetPage      = lazy(() => import('@/pages/assets/create'));
const AssetDetailPage      = lazy(() => import('@/pages/assets/detail'));
const EditAssetPage        = lazy(() => import('@/pages/assets/edit'));
const DepreciationRunsPage = lazy(() => import('@/pages/admin/depreciation'));

export const assetsRoutes = (
  <>
    {/* Assets module (Sprint 8 — Task 70) */}
    <Route element={<ModuleGuard module="assets" />}>
      <Route path="/assets"
        element={<PermissionGuard permission="assets.view"><AssetsListPage /></PermissionGuard>} />
      <Route path="/assets/create"
        element={<PermissionGuard permission="assets.create"><CreateAssetPage /></PermissionGuard>} />
      <Route path="/assets/:id"
        element={<PermissionGuard permission="assets.view"><AssetDetailPage /></PermissionGuard>} />
      <Route path="/assets/:id/edit"
        element={<PermissionGuard permission="assets.create"><EditAssetPage /></PermissionGuard>} />
      <Route path="/admin/depreciation"
        element={<PermissionGuard permission="assets.depreciation.view"><DepreciationRunsPage /></PermissionGuard>} />
    </Route>
  </>
);
