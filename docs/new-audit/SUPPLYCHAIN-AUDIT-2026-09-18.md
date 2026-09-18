# Supply Chain Audit

Date: 2026-09-18
Scope: `api/app/Modules/SupplyChain/`. `DeliveryService` was traced in the O2C doc; summarized here.
Claims are **[confirmed]** (file:line/grep) or **[assumption/unverified]**.

---

## 1. Walkthrough

- **ShipmentService**: `ordered→shipped→in_transit→customs→cleared→received` (+cancel); auto-stamps
  `atd`/`customs_clearance_date`/`ata`. `Received` does **not** create or link a GRN.
- **ShipmentLotService**: lot rows per delivery (unique `delivery_id`); feeds CoC/traceability.
- **ContainerService**: thin CRUD, no uniqueness per shipment.
- **ImpexDocumentService**: packing list / commercial invoice PDFs via `DocumentVaultService`.
- **LandedCostService**: allocates per PO line by value/quantity (not weight).
- **DriverDeliveryService**: driver-scoped `scheduled→loading→in_transit→delivered`.
- **DeliveryService**: output-bound passed-inspection gating, SO quantity reconciliation, PoD
  requirement, best-effort draft-invoice handoff with `DeliveryInvoiceHandoffStatus`.

## 2. Findings

1. **Landed cost never reaches inventory or GL.** `LandedCostService` persists allocations, but
   weighted-average receipt cost never consumes them and no JE is posted. **[confirmed]**
2. **`by_weight` is advertised then refused.** `ShipmentController::options` offers it;
   `assertMethodIsSupported` rejects it; `getItemWeights()`/the weight branch are unreachable.
   `manual` is accepted but silently equal-splits. **[confirmed]**
3. **`GET /shipments/{id}` never eager-loads landed costs**, so `landed_costs` is always null and
   absent from the SPA type; only the calculate response carries them.
4. **Shipment `Received` is not wired to receiving.** No GRN creation/verification, despite the
   migration doc assuming Inventory back-links by PO.
5. **Shipment can be deleted while in transit**; only `Received` is blocked.
6. **Document upload duplicates:** docblock claims per-type idempotency, but `uploadDocument`
   always inserts; `shipment_documents` has no unique index.
7. **`incoterm` two sources of truth:** persisted on the shipment but PDFs render `$po->incoterm`;
   `updateMeta` omits `incoterm` so it is write-once.
8. **Shipment lots are effectively never created via UI.** The API client is imported by no page;
   `coc_path` is filled by nothing. CoC attaches as a `DeliveryProof` instead. Lot-based
   traceability/CoC is therefore empty for real deliveries.
9. **Permission drift:** delivery reads allow `supply_chain.view` OR `supply_chain.deliveries.view`,
   but `receipt-photo`/proof routes demand only `supply_chain.view`, so `warehouse_staff` (narrow
   slug only) can read a delivery but 403s on its proof/photo — a broken link.
10. **`NotifyFinanceOnDeliveryConfirmed` is a silent no-op by default** — setting
    `accounting.delivery_confirmed.notification_roles` is never seeded.
11. **`retryInvoiceHandoff` has no HTTP route**, yet the operator message says "replay this handoff";
    only the queued listener can.
12. **`Vehicle.asset_id` is dead** (not fillable/exposed), so Asset↔fleet QR tracking is unbuilt.
13. **`DeliveryProof.proof_type` is a bare string** compared with the literal `'coc'` despite the
    `DeliveryProofType` enum; `ShipmentDocument`/`ShipmentLandedCost` lack `HasAuditLog`.
14. **No SPA consumers** for container CRUD, calculate-landed-cost, delivery reschedule,
    delivery delete/restore, shipment restore/updateMeta — backend endpoints with no UI.
15. **B2B `supplier_shipments` duplicates inbound tracking** under a different module with no
    cross-reference.

## 3. Assumptions

- A1. No test run; source/grep only. A2. Landed-cost GL intent is inferred from the absence of any
  post/inventory write; the spec may have scoped it out. A3. SPA usage checked by grep of the API
  client and pages, not a full build.
