import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// MRP (Sprint 6 — Tasks 49, 50, 52, 53; machine/mold detail + BOM create added in audit §3.1, §3.4)
const BomsListPage     = lazy(() => import('@/pages/mrp/boms'));
const CreateBomPage    = lazy(() => import('@/pages/mrp/boms/create'));
const BomDetailPage    = lazy(() => import('@/pages/mrp/boms/detail'));
const MachinesListPage = lazy(() => import('@/pages/mrp/machines'));
const MachineDetailPage = lazy(() => import('@/pages/mrp/machines/detail'));
const MoldsListPage    = lazy(() => import('@/pages/mrp/molds'));
const MoldDetailPage   = lazy(() => import('@/pages/mrp/molds/detail'));
const MrpPlansListPage = lazy(() => import('@/pages/mrp/plans'));
const MrpPlanDetailPage = lazy(() => import('@/pages/mrp/plans/detail'));

export const mrpRoutes = (
  <>
    {/* MRP module (Sprint 6 — Tasks 49, 50, 52, 53) */}
    <Route element={<ModuleGuard module="mrp" />}>
      <Route path="/mrp" element={<Navigate to="/mrp/plans" replace />} />

      <Route path="/mrp/boms"
        element={<PermissionGuard permission="mrp.boms.view"><BomsListPage /></PermissionGuard>} />
      <Route path="/mrp/boms/create"
        element={<PermissionGuard permission="mrp.boms.manage"><CreateBomPage /></PermissionGuard>} />
      <Route path="/mrp/boms/:id"
        element={<PermissionGuard permission="mrp.boms.view"><BomDetailPage /></PermissionGuard>} />

      <Route path="/mrp/machines"
        element={<PermissionGuard permission="mrp.machines.view"><MachinesListPage /></PermissionGuard>} />
      <Route path="/mrp/machines/:id"
        element={<PermissionGuard permission="mrp.machines.view"><MachineDetailPage /></PermissionGuard>} />

      <Route path="/mrp/molds"
        element={<PermissionGuard permission="mrp.molds.view"><MoldsListPage /></PermissionGuard>} />
      <Route path="/mrp/molds/:id"
        element={<PermissionGuard permission="mrp.molds.view"><MoldDetailPage /></PermissionGuard>} />

      <Route path="/mrp/plans"
        element={<PermissionGuard permission="mrp.plans.view"><MrpPlansListPage /></PermissionGuard>} />
      <Route path="/mrp/plans/:id"
        element={<PermissionGuard permission="mrp.plans.view"><MrpPlanDetailPage /></PermissionGuard>} />
    </Route>
  </>
);
