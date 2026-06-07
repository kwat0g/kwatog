import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Supply Chain (Sprint 7 — Tasks 65, 66, 67)
const ShipmentsListPage   = lazy(() => import('@/pages/supply-chain/shipments'));
const ShipmentCreatePage  = lazy(() => import('@/pages/supply-chain/shipments/create'));
const ShipmentDetailPage  = lazy(() => import('@/pages/supply-chain/shipments/detail'));
const DeliveriesListPage  = lazy(() => import('@/pages/supply-chain/deliveries'));
const DeliveryCreatePage  = lazy(() => import('@/pages/supply-chain/deliveries/create'));
const DeliveryDetailPage  = lazy(() => import('@/pages/supply-chain/deliveries/detail'));
const FleetPage           = lazy(() => import('@/pages/supply-chain/fleet'));

export const supplyChainRoutes = (
  <>
    {/* Supply Chain module (Sprint 7 — Tasks 65, 66, 67) */}
    <Route element={<ModuleGuard module="supply_chain" />}>
      <Route path="/supply-chain" element={<Navigate to="/supply-chain/deliveries" replace />} />
      <Route path="/supply-chain/shipments"
        element={<PermissionGuard permission="supply_chain.view"><ShipmentsListPage /></PermissionGuard>} />
      <Route path="/supply-chain/shipments/create"
        element={<PermissionGuard permission="supply_chain.shipments.manage"><ShipmentCreatePage /></PermissionGuard>} />
      <Route path="/supply-chain/shipments/:id"
        element={<PermissionGuard permission="supply_chain.view"><ShipmentDetailPage /></PermissionGuard>} />
      <Route path="/supply-chain/deliveries"
        element={<PermissionGuard permission="supply_chain.view"><DeliveriesListPage /></PermissionGuard>} />
      <Route path="/supply-chain/deliveries/create"
        element={<PermissionGuard permission="supply_chain.deliveries.create"><DeliveryCreatePage /></PermissionGuard>} />
      <Route path="/supply-chain/deliveries/:id"
        element={<PermissionGuard permission="supply_chain.view"><DeliveryDetailPage /></PermissionGuard>} />
      <Route path="/supply-chain/fleet"
        element={<PermissionGuard permission="supply_chain.view"><FleetPage /></PermissionGuard>} />
    </Route>
  </>
);
