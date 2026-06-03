import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Supply Chain (Sprint 7 — Tasks 65, 66, 67)
const SupplyChainHubPage = lazy(() => import('@/pages/supply-chain/hub'));
const ShipmentsListPage  = lazy(() => import('@/pages/supply-chain/shipments'));
const DeliveriesListPage = lazy(() => import('@/pages/supply-chain/deliveries'));
const DeliveryDetailPage = lazy(() => import('@/pages/supply-chain/deliveries/detail'));
const FleetPage          = lazy(() => import('@/pages/supply-chain/fleet'));

export const supplyChainRoutes = (
  <>
    {/* Supply Chain module (Sprint 7 — Tasks 65, 66, 67) */}
    <Route element={<ModuleGuard module="supply_chain" />}>
      <Route path="/supply-chain" element={<Navigate to="/supply-chain/hub" replace />} />
      <Route path="/supply-chain/hub"
        element={<PermissionGuard permission="supply_chain.view"><SupplyChainHubPage /></PermissionGuard>} />
      <Route path="/supply-chain/shipments"
        element={<PermissionGuard permission="supply_chain.view"><ShipmentsListPage /></PermissionGuard>} />
      <Route path="/supply-chain/deliveries"
        element={<PermissionGuard permission="supply_chain.view"><DeliveriesListPage /></PermissionGuard>} />
      <Route path="/supply-chain/deliveries/:id"
        element={<PermissionGuard permission="supply_chain.view"><DeliveryDetailPage /></PermissionGuard>} />
      <Route path="/supply-chain/fleet"
        element={<PermissionGuard permission="supply_chain.view"><FleetPage /></PermissionGuard>} />
    </Route>
  </>
);
