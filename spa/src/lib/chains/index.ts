/**
 * Sprint P1 — Centralized chain-step builders.
 *
 * Each builder takes a domain resource (LeaveRequest, EmployeeLoan, …) and
 * returns a `ChainStep[]` for the shared `<ChainHeader>` component. Putting
 * all chain logic here gives every detail page a consistent step set, label,
 * and active-step derivation rule.
 *
 * Sales Order and Work Order chains continue to come from the API
 * (`/sales-orders/{id}/chain`, `/work-orders/{id}/chain`) because their step
 * derivation depends on data that is expensive to ship to the SPA. Those
 * helpers are intentionally not duplicated here.
 */
export { buildLeaveChain } from './leave';
export { buildLoanChain } from './loan';
export { buildNcrChain } from './ncr';
export { buildGrnChain } from './grn';
export { buildDeliveryChain, buildDeliveryO2cChain } from './delivery';
export { buildPurchaseOrderChain } from './purchase-order';
