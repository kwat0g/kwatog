# Supplier Purchase Order Fulfillment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make supplier delivery plans, actual dispatch, shipping evidence, and warehouse receipt distinct and traceable from the acknowledged PO through AP payment.

**Architecture:** Extend the existing B2B supplier endpoints and PO-scoped internal activity rather than creating a new order workflow. Preserve the GRN as the authority for physical receipt and Quality as the authority for accepted stock. Add nullable links so historic supplier records remain readable.

**Tech Stack:** Laravel/PHP, PostgreSQL, React/TypeScript, Vitest, PHPUnit, Playwright.

**Spec:** [Supplier Purchase Order Fulfillment Design](../specs/2026-10-08-supplier-po-fulfillment-design.md)

## Global Constraints

- Plan only until the user asks to implement; do not mutate the live `PO-202610-0001` as part of automated testing.
- `expected_delivery_date` and `confirmed_delivery_date` may change only through existing PO controls, never from schedule, shipment, document, or GRN writes.
- Use `PurchaseOrderAccessPolicy` for internal PO row scope and existing vendor scoping for supplier routes.
- Add nullable schema fields; preserve legacy month-only schedules, unquantified shipments, PO-wide documents, and unlinked GRNs.
- Compare calendar dates in the application's `Asia/Manila` timezone.
- Do not treat a supplier-uploaded bill of lading as proof that OGAMI received goods.
- Run PHP tests in a dedicated `ogami_test_supplier_po` database. Create it once if absent and pass `-e DB_DATABASE=ogami_test_supplier_po` to `docker compose exec`; `api/tests/phpunit-bootstrap.php` permits this prefix. Never run destructive test setup against the live database.

---

## Contract examples

Supplier dated plan POST, using a loaded `PortalPoDetail`:

```ts
const plan = (po: PortalPoDetail) => ({
  purchase_order_id: po.id,
  month: '2026-10',
  planned_delivery_date: '2026-10-13',
  lines: [{ purchase_order_item_id: po.items[0].id, quantity: '20000.00' }],
});
```

Supplier quantified shipment POST:

```ts
const dispatch = (po: PortalPoDetail) => ({
  shipped_date: '2026-10-08',
  estimated_arrival: '2026-10-13',
  carrier: 'Example Carrier',
  tracking_number: 'TEST-PO-0001',
  items: [{ purchase_order_item_id: po.items[0].id, quantity_shipped: '20000.00' }],
});
```

The IDs are the existing opaque hashes returned by PO detail; API writes must not introduce raw numeric IDs. The shipment response adds `items`, `quantity_recorded`, and `late_eta`; the schedule response adds `planned_delivery_date` and `precision`. A warehouse GRN finalization may include `supplier_shipment_id` when the delivery was announced.

---

## Release 1 — status truth and action copy

### Task 1: Correct the supplier PO receipt summary

**Files:** Modify `spa/src/pages/portal/supplier/purchase-orders/detail.tsx`; test `spa/src/pages/portal/supplier/purchase-orders/detail.test.tsx`.

**Interface:** Uses existing `PortalPoGrn.status`; no API change. Render count of non-draft GRNs and count of draft expected GRNs separately.

- [ ] Add a UI test with one `draft` GRN: expect `Received GRNs` value `0`, `1 expected GRN`, and a Draft chip in the table. Add a second case with a `pending_qc` GRN: expect count `1`.
- [ ] Run `npm run test:run -- src/pages/portal/supplier/purchase-orders/detail.test.tsx` from `spa`; expect the new assertions to fail.
- [ ] Replace the current `po.goods_receipt_notes.length` KPI value with `po.goods_receipt_notes.filter((grn) => grn.status !== 'draft').length`; derive the draft count for helper text. Keep `goods_receipt_notes` table unchanged.
- [ ] Run the focused UI test and `npx eslint src/pages/portal/supplier/purchase-orders/detail.tsx src/pages/portal/supplier/purchase-orders/detail.test.tsx --max-warnings 0`; expect both to pass.

### Task 2: Explain the three supplier actions at point of use

**Files:** Modify `spa/src/pages/portal/supplier/purchase-orders/detail.tsx`; test `spa/src/pages/portal/supplier/purchase-orders/detail.test.tsx`.

**Interface:** No mutation contract change.

- [ ] Add UI assertions for button labels `Plan delivery`, `Add shipment`, and `Upload shipping document`, plus helper text: `Plan a date and quantity before dispatch`; `Record goods already dispatched`; `Packing list, bill of lading, or CoA. OGAMI confirms receipt in a GRN.`
- [ ] Run the focused Vitest file; expect copy assertions to fail.
- [ ] Apply copy and labels. Keep document types sourced from `SupplierShippingDocumentType`; do not add a `proof_of_delivery` option.
- [ ] Run the focused Vitest file and targeted ESLint; expect pass. Release 1 can ship independently.

## Release 2 — dated plan and Purchasing review

### Task 3: Store an exact planned day without breaking month-only history

**Files:** Create `api/database/migrations/2026_10_08_230100_add_planned_delivery_date_to_delivery_schedules.php`; modify `api/app/Modules/B2B/Models/DeliverySchedule.php`, `api/app/Modules/B2B/Requests/Supplier/StoreDeliveryScheduleRequest.php`, `api/app/Modules/B2B/Services/SupplierDeliveryScheduleService.php`, `api/app/Modules/B2B/Resources/DeliveryScheduleResource.php`; test `api/tests/Feature/B2B/SupplierDeliveryScheduleTest.php`.

**Interface:** Supplier POST accepts optional `planned_delivery_date: YYYY-MM-DD` during compatibility rollout. Supplier schedule resource returns `planned_delivery_date: string|null` and `precision: 'day'|'month'`. Customer schedule POST remains unchanged.

- [ ] Add feature tests: a dated supplier plan persists the day; a legacy month-only supplier plan still works and returns `precision=month`; customer schedules stay month-only; a date outside `month`, a past date, and a date after `confirmed_delivery_date` (or `expected_delivery_date` when confirmation is null) fail with 422. Assert PO dates unchanged in every case.
- [ ] Run `docker compose exec -T -e DB_DATABASE=ogami_test_supplier_po api php artisan test --filter='SupplierDeliveryScheduleTest' tests/Feature/B2B/SupplierDeliveryScheduleTest.php`; expect new cases to fail.
- [ ] Add a nullable `date('planned_delivery_date')` column and model cast. In the request, validate `date_format:Y-m-d` and `after_or_equal:today` when supplied. Under the PO row lock in `storeDeliverySchedule()`, reject `substr($date, 0, 7) !== $data['month']` and a day later than the PO's confirmed/required date. Return the day and precision from `DeliveryScheduleResource`.
- [ ] Run focused API tests; confirm `migrate` then `migrate:rollback --step=1` on the isolated database preserves existing rows. Confirm legacy rows serialize with `planned_delivery_date=null`.

### Task 4: Collect the planned day in the supplier UI

**Files:** Modify `spa/src/pages/portal/supplier/delivery-schedules.tsx`, `spa/src/types/b2b.ts`, `spa/src/api/b2b/supplier.ts`, `spa/src/pages/portal/supplier/purchase-orders/detail.tsx`; create `spa/src/pages/portal/supplier/delivery-schedules.test.tsx`.

**Interface:** New UI requires a day and sends both `month` and `planned_delivery_date`; old list rows show `Month-only plan`.

- [ ] Test a new schedule seeded from `PO-202610-0001`: day prefilled from confirmed date, month `2026-10`, quantity still entered per PO line; submitting sends `planned_delivery_date: '2026-10-13'`. Test a legacy row shows `Month-only plan` without inventing a day.
- [ ] Run the new Vitest file; expect fail.
- [ ] Add the date picker before line quantities; derive `month` from the selected date in the UI, while keeping the API's month field. Pass the PO hash in the route query when navigating from its detail page and preselect it. Explain that a schedule does not change the agreed PO date.
- [ ] Run focused Vitest, `npm run typecheck`, and targeted ESLint; expect pass.

### Task 5: Move supplier-plan review to the PO's operational owner

**Files:** Modify `api/database/seeders/RolePermissionSeeder.php`, `api/app/Modules/B2B/routes.php`, `api/app/Modules/B2B/Controllers/InternalDeliveryScheduleController.php`, `api/app/Modules/B2B/Services/DeliveryScheduleReviewService.php`; modify `spa/src/pages/purchasing/purchase-orders/components/SupplierActivityPanel.tsx`, `spa/src/types/supplierActivity.ts`; test `api/tests/Feature/B2B/DeliveryScheduleReviewTest.php` and a focused `SupplierActivityPanel` UI test.

**Interface:** Add `purchasing.delivery_schedules.review`. New PO-scoped endpoints under `b2b/purchase-orders/{purchaseOrder}/delivery-schedules/{deliverySchedule}/acknowledge|reject` reuse `DeliveryScheduleReviewService` and require both permission and `PurchaseOrderAccessPolicy::canView()`. Existing portal-access endpoints and customer reviews remain intact.

- [ ] Add tests: Purchasing Officer can acknowledge a supplier schedule for a visible PO; cannot review a customer schedule, another department's PO, or a schedule attached to another PO; Warehouse and Finance cannot review; system admin retains access. Test a rejected supplier schedule releases its schedulable quantity.
- [ ] Run `DeliveryScheduleReviewTest`; expect the new route/permission tests to fail.
- [ ] Add permission and role assignment. Implement PO-scoped route/controller checks before calling `acknowledge()` or `reject()`. Do not widen the current `b2b.portal_access.manage` permission.
- [ ] In the PO supplier-activity panel, show dated plan, status, and review controls only for users with the new permission; use the new endpoints. Keep old month-only rows labeled. Test loading, permission-hidden controls, acknowledge, and reject reason.
- [ ] Run focused PHP/Vitest tests, typecheck, and route audit. Release 2 ends here.

## Release 3 — actual dispatch and warehouse reconciliation

### Task 6: Record what each supplier shipment actually contains

**Files:** Create `api/database/migrations/2026_10_08_230200_create_supplier_shipment_items.php`, `api/app/Modules/B2B/Models/SupplierShipmentItem.php`, `api/app/Modules/B2B/Requests/Supplier/StoreSupplierShipmentRequest.php`; modify `api/app/Modules/B2B/Models/SupplierShipment.php`, `api/app/Modules/B2B/Controllers/SupplierShipmentController.php`, `api/app/Modules/B2B/Services/SupplierShipmentService.php`, `api/app/Modules/B2B/Resources/SupplierShipmentResource.php`, `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php`; test `api/tests/Feature/B2B/SupplierShipmentTest.php`.

**Interface:** New shipment POST requires `shipped_date`, `estimated_arrival`, and `items: [{purchase_order_item_id, quantity_shipped}]`. `quantity_shipped` uses `decimal(12,2)` PO purchase units. Existing PUT/header update and legacy `shipment-update` remain compatible; shipment line quantities are immutable after dispatch. Response includes `items` and `quantity_recorded: bool`.

- [ ] Add tests for zero-field POST rejection, missing/duplicate/foreign PO lines, nonpositive quantity, invalid dates, vendor isolation, two valid partial lots, and existing unquantified shipment serialization. Assert shipment POST leaves PO `quantity_received` and GRNs untouched.
- [ ] Run `SupplierShipmentTest`; expect new cases to fail.
- [ ] Add table with `supplier_shipment_id`, `purchase_order_item_id`, `quantity_shipped`, timestamps, and a unique `(supplier_shipment_id, purchase_order_item_id)` index. Add a dedicated create request. Under existing PO lock, verify every decoded line belongs to the PO, create header and lines in one transaction, and expose line hashes/quantities in both supplier and internal resources.
- [ ] Run focused tests and migration up/down on isolated database. Historical shipments must show `quantity_recorded=false`, never an inferred quantity.

### Task 7: Show dispatched quantities to Supplier and Purchasing

**Files:** Modify `spa/src/pages/portal/supplier/purchase-orders/detail.tsx`, `spa/src/types/b2b.ts`, `spa/src/pages/purchasing/purchase-orders/components/SupplierActivityPanel.tsx`, `spa/src/types/supplierActivity.ts`; test `spa/src/pages/portal/supplier/purchase-orders/detail.test.tsx` and a focused internal activity-panel test.

**Interface:** Supplier create form submits the shipment lines from Task 6; the edit form changes header/ETA only. Internal PO shows shipped quantity per line and labels old rows `Quantity not recorded`.

- [ ] Test a 20,000-bag PO: entering 12,000 in the first lot submits one item line; creating a second lot remains available; header correction does not change line quantities. Test old shipment display.
- [ ] Run focused Vitest; expect fail.
- [ ] Add PO-line quantity controls to Add shipment, with positive two-decimal validation and units beside values. Render dispatch lines in supplier and Purchasing shipment tables. Keep explicit messages that shipped quantity is not received quantity.
- [ ] Run focused Vitest, typecheck, and targeted ESLint; expect pass.

### Task 8: Link a received GRN to its supplier dispatch

**Files:** Create `api/database/migrations/2026_10_08_230300_link_grns_to_supplier_shipments.php`; modify `api/app/Modules/Inventory/Models/GoodsReceiptNote.php`, `api/app/Modules/Inventory/Requests/FinalizeGrnRequest.php`, `api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php`, `api/app/Modules/Inventory/Services/GrnService.php`, `api/app/Modules/Inventory/Resources/GoodsReceiptNoteResource.php`; modify `spa/src/pages/inventory/grn/detail.tsx`, `spa/src/pages/inventory/grn/components/GrnSupplierDocumentsPanel.tsx`; test `api/tests/Feature/Inventory/DraftGrnOnPoSentTest.php` and `api/tests/Feature/Inventory/GrnHttpContractTest.php`.

**Interface:** GRN gets nullable `supplier_shipment_id`. Finalize accepts optional supplier-shipment hash; old API callers without it remain valid. New Warehouse UI explicitly selects a same-PO shipment or `Unannounced delivery`.

- [ ] Add tests: finalize the auto-draft GRN with a same-PO shipment; reject a foreign-vendor/foreign-PO shipment; keep a legacy unlinked finalize valid; actual GRN received quantity remains authoritative; mismatch is visible and does not mutate shipment lines. Test supplier/Purchasing views reflect the resulting GRN status.
- [ ] Run focused GRN tests; expect fail.
- [ ] Add nullable FK and relationship. Resolve shipment under the GRN/PO transaction, verify PO ownership, and save the link when finalizing. Do not repurpose `goods_receipt_notes.shipment_id` (Supply Chain model). Expose link and quantities through the GRN resource; show shipment choice and discrepancy in the Warehouse UI.
- [ ] Run focused GRN, supplier-shipment, and three-way-match tests plus migration rollback on isolated database. Release 3 ends here.

### Task 9: Flag a late ETA without rewriting the contract

**Files:** Modify `api/app/Modules/B2B/Services/SupplierShipmentService.php`, `api/app/Modules/B2B/Resources/SupplierShipmentResource.php`, `api/app/Modules/B2B/Controllers/InternalSupplierActivityController.php`, `spa/src/pages/portal/supplier/purchase-orders/detail.tsx`, `spa/src/pages/purchasing/purchase-orders/components/SupplierActivityPanel.tsx`; test `api/tests/Feature/B2B/SupplierShipmentTest.php` and focused UI tests.

**Interface:** `late_eta: bool` compares ETA against `confirmed_delivery_date ?? expected_delivery_date`; it never writes either PO date. A late ETA notification uses a deduplication key containing shipment ID and ETA date.

- [ ] Test ETA October 14 against confirmed October 13: write succeeds, `late_eta=true`, PO confirmed date unchanged, one Purchasing notification. Test retry of same ETA does not duplicate alert; ETA correction to October 12 clears current warning while update history remains.
- [ ] Run focused API tests; expect fail.
- [ ] Compute the flag in resources and send the existing Purchasing notification audience after a successful shipment transaction. Render `At risk: ETA after confirmed date` on supplier and Purchasing views; do not block the supplier from reporting reality.
- [ ] Run focused API/Vitest tests, typecheck, and notification test. Release 3 risk signaling ends here.

## Release 4 — shipment context for supplier documents

### Task 10: Associate relevant files with a dispatch

**Files:** Create `api/database/migrations/2026_10_08_230400_link_portal_documents_to_supplier_shipments.php`; modify `api/app/Modules/B2B/Models/PortalShippingDocument.php`, `api/app/Modules/B2B/Requests/Supplier/UploadShippingDocumentsRequest.php`, `api/app/Modules/B2B/Services/SupplierShipmentService.php`, `api/app/Modules/B2B/Resources/PortalShippingDocumentResource.php`, `api/app/Modules/B2B/Controllers/InternalSupplierActivityController.php`; modify `spa/src/pages/portal/supplier/purchase-orders/detail.tsx`, `spa/src/pages/inventory/grn/components/GrnSupplierDocumentsPanel.tsx`; test `api/tests/Feature/B2B/SupplierShipmentTest.php` and `api/tests/Feature/B2B/SupplierPortalServiceTest.php`.

**Interface:** Upload accepts optional `supplier_shipment_id` hash. A document with no shipment remains PO-wide. Internal GRN document list includes files for its linked shipment plus PO-wide legacy files. No `proof_of_delivery` supplier type is added.

- [ ] Test matching-PO upload, foreign shipment rejection, PO-wide legacy visibility, GRN-specific filtering for two shipments, vendor-scoped download, and existing duplicate-upload idempotency.
- [ ] Run focused document tests; expect fail.
- [ ] Add nullable FK, same-PO ownership check, optional shipment selector, and resource field. Keep current private storage, MIME/size checks, and download authorization. Render `PO-wide` when no shipment is associated.
- [ ] Run focused document, cross-tenant, GRN document, and UI tests plus migration rollback. Release 4 ends here.

## End-to-end proof and handoff

### Task 11: Add a role-based runbook and one real state-transition test

**Files:** Create `docs/testing/supplier-po-e2e-runbook.md`; create `api/tests/Feature/E2E/SupplierPoFulfillmentTest.php`; extend `spa/e2e/chain-procure-to-pay.spec.ts` only if browser coverage adds a distinct UI assertion.

**Interface:** Runbook covers the spec's upstream PR creation, approvals, PO conversion, dispatch, and supplier acceptance checkpoints, then the seven fulfillment steps. It names `PR-202610-0001` and `PO-202610-0001` for the human walkthrough, without storing credentials or claiming that demo transactions ran.

- [ ] Write a failing API E2E test with separate supplier, Purchasing, Warehouse, Quality, and Finance principals. Cover `acknowledged PO -> dated schedule -> reviewed schedule -> quantified shipment -> draft GRN finalized -> QC-accepted GRN -> supplier invoice -> matched and paid bill`. Assert wrong-vendor and wrong-role mutations fail. Use factories and `ogami_test_supplier_po`; never use the live PO.
- [ ] Run `docker compose exec -T -e DB_DATABASE=ogami_test_supplier_po api php artisan test --filter='SupplierPoFulfillmentTest' tests/Feature/E2E/SupplierPoFulfillmentTest.php`; expect fail until releases 1–4 are present.
- [ ] Complete the runbook with exact menu paths, role account labels, test values, and expected status after each click from New PR through Paid. Record the already-completed live PR/PO checkpoints as observations, not actions to repeat on the existing PO. Include recovery instructions for rejection or partial receipt. Reuse existing AP and Quality workflows.
- [ ] Run focused API tests, `npm run typecheck`, targeted ESLint/Vitest, and the existing mocked E2E chain test. Count only the database-backed `SupplierPoFulfillmentTest` as a real state-transition proof. Review the diff for unrelated changes before any deployment decision.

## Release order and review gates

1. Release 1: truthful GRN state and clear labels. No migration.
2. Release 2: exact supplier plan and Purchasing review. Additive migration; customer schedule unchanged.
3. Release 3: quantified dispatch, GRN linkage, and late-ETA visibility. Additive migrations; historic rows marked legacy.
4. Release 4: shipment-specific documents. Additive migration; PO-wide legacy documents retained.
5. Run the role-based E2E proof and user walkthrough. Hold deployment until each release's focused tests and rollback check pass.
