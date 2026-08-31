# Goods Receiving Module Audit

Module: `inventory/goods-receiving` (`M041`)  
Session: 2026-08-24  
Recommendation: `Plan Ready`; release without code changes in this session.

## Scope

Audited the GRN API, service lifecycle, incoming-QC handoff, stock and GL boundaries, migrations/models/resources, and the SPA list/create/detail flow. Purchasing, Quality, warehouse, accounting, and AP files were read as dependencies only; no files outside this module were changed.

The registry's dependency ordering currently leaves the unlocked Tier 3 operational modules in a cycle of `Not Started` dependencies. `goods-receiving` was the first unlocked candidate claimed after the eligible Tier 2 candidate was locked by another session. This is an audit-process question, not a product finding.

## Discovery

Implemented surfaces include:

- GRN create, draft finalization, retryable incoming-QC handoff, accept/partial-accept/reject, stock movement, GL posting, outbox events, and role-protected routes (`api/app/Modules/Inventory/routes.php:132-144`).
- Synchronous plus durable incoming-QC triggering (`api/app/Modules/Inventory/Services/GrnService.php:225-255`) and a SPA list/create/detail flow (`spa/src/routes/inventoryRoutes.tsx:90-95`).
- Lot, expiry, moisture, COA, journal-entry, and handoff columns exist in the data model/migrations, but several are not part of the normal API/UI contract.

The focused backend baseline contains coverage for QC gating, authorization, draft finalization, rejection, GL posting, and lot traceability. It could not execute assertions in this session: all 39 selected tests failed during setup because PostgreSQL host `db` could not resolve (`SQLSTATE[08006]`, `ogami_test`). No test result is treated as a product failure.

## Findings

### GRN-01 — Broken: single-screen terminal QC can accept without an inspection

`receiveWithQc` validates a terminal verdict and permission, but passes raw items to the service (`api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php:153-182,190-202`). The service can have no existing inspection (`api/app/Modules/Inventory/Services/GrnService.php:761-780`), then calls `acceptInternal` for `passed`/`passed_with_remarks` (`:865-878`); that method explicitly bypasses the public QC gate and moves stock (`:912-951`). The incoming-QC listener also truncates fractional quantities to an integer and skips quantities below one (`api/app/Modules/Quality/Listeners/TriggerIncomingQC.php:75-84`).

Impact: a permitted single-screen request for a QC-eligible fractional receipt, or a receipt whose inspection staging failed, can mark the GRN accepted and post stock/GL without a persisted incoming inspection. This is a state-machine and inventory-integrity issue. `Scope: large`; `Session: separate-recommended`.

### GRN-02 — Broken: the received item is not required to match the PO line item

The request only checks that both IDs exist (`api/app/Modules/Inventory/Requests/StoreGrnRequest.php:35-45`; the single-screen validator has the same shape at `api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php:157-163`). The service verifies that the PO line belongs to the PO, but never compares `$itemId` with `$poi->item_id`, then persists the caller's item ID while incrementing that PO line (`api/app/Modules/Inventory/Services/GrnService.php:135-146,199-223`).

Impact: PO, QC, stock, GL, and later bill/traceability records can describe different items. `Scope: medium`; `Session: separate-recommended`.

### GRN-03 — Incomplete: receipt unit cost is caller-controlled without a variance policy

`unit_cost` is accepted from the request (`api/app/Modules/Inventory/Requests/StoreGrnRequest.php:40-45`), overrides the PO price in the service (`api/app/Modules/Inventory/Services/GrnService.php:194-206`), is editable in the create UI (`spa/src/pages/inventory/grn/create.tsx:235-245`), and is the value used for accepted inventory GL valuation (`api/app/Modules/Inventory/Services/GrnGlPostingService.php:89-104`). The code does not show a role, approval, or configured variance boundary.

Question for the owning process: is a receiving-time price override intentional? If yes, define who may override it and how the variance reaches Purchasing/AP. If no, use the authoritative PO cost. `Scope: medium`; `Session: separate-recommended` because this is financial behavior.

### GRN-04 — Broken: PO status advances before QC acceptance

Both normal create and draft finalization call `refreshPoStatus` before QC (`api/app/Modules/Inventory/Services/GrnService.php:225,401-410`). The status helper uses physical `quantity_received` to move a PO to `partially_received`, while only `quantity_accepted` qualifies for `received` (`:1124-1141`). The documented flow confirms the PO update while the GRN remains pending QC (`docs/PROCESS-FLOWS.md:651-655`), and this re-confirms prior finding F-014 (`docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md:321-336`).

Impact: Purchasing, supplier metrics, MRP, and AP consumers can treat physically received but QC-rejected goods as received. Separate physical, pending-QC, accepted, and rejected quantities/statuses. `Scope: large`; `Session: separate-recommended`.

### GRN-05 — Broken: receipt destination can be inactive, blocked, or soft-deleted

Normal receiving validates only `exists:warehouse_locations,id` (`api/app/Modules/Inventory/Requests/StoreGrnRequest.php:40-45`); draft finalization only requires string IDs (`api/app/Modules/Inventory/Requests/FinalizeGrnRequest.php:16-24`). The service decodes the location but does not check its lifecycle (`api/app/Modules/Inventory/Services/GrnService.php:143-146,366-368`). Locations are soft-deletable and expose an `active` scope (`api/app/Modules/Inventory/Models/WarehouseLocation.php:17-48`), with `is_active` and `is_blocked` schema state (`api/database/migrations/0055_create_warehouse_locations_table.php:13-20`; `api/database/migrations/0159_enhance_warehouse_locations.php:13-20`). Stock movement validation checks that a receipt has a destination, not that the destination is usable (`api/app/Modules/Inventory/Services/StockMovementService.php:238-270`).

Impact: direct API/service callers can create stock in a bin that warehouse operations have retired or blocked. `Scope: medium`; `Session: separate-recommended` because the invariant crosses the stock boundary.

### GRN-06 — Broken: a malformed stored QC anchor can fail open in the public accept gate

When no incoming inspection rows are found, `assertQcGate` falls back to `qc_inspection_id` but stores the nullable query result in the status collection (`api/app/Modules/Inventory/Services/GrnService.php:683-707`). The fallback query constrains `stage = incoming`, while the database only makes `qc_inspection_id` a foreign key to `inspections` (`api/database/migrations/0443_add_movement_gl_and_qc_fk_and_sequence_keys.php:37-41`). A non-incoming or missing-stage anchor therefore yields `null`, which is indistinguishable from “no blocking status” to the `first` check.

Add an explicit missing/invalid-anchor rejection and a regression test. `Scope: small`; `Session: separate-recommended` because it must be fixed with the broader QC invariant in GRN-01.

### GRN-07 — Broken: single-screen payload can write unverified lot/COA metadata

The controller runs validation but ignores the returned validated array and passes `$request->input('items')` to the service (`api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php:153-171,190-198`). The service consumes optional lot, expiry, moisture, COA path, and `coa_verified` keys (`api/app/Modules/Inventory/Services/GrnService.php:199-218`), while the route is available to the inventory GRN permission (`api/app/Modules/Inventory/routes.php:138,143-144`). Thus a pending single-screen request can carry fields not declared by the validator, including `coa_verified=true`, without a Quality verification step.

Whitelist the payload and make COA verification an explicit Quality-owned transition. `Scope: medium`; `Session: separate-recommended`.

### GRN-08 — Missing: lot/expiry/resin-QC traceability is not end-to-end in the normal contract

The model and migrations support supplier/material lots, expiry, moisture, COA path, and verification (`api/app/Modules/Inventory/Models/GrnItem.php:20-39`; `api/database/migrations/0217_add_resin_qc_to_grn_items.php:20-32`). The normal store request omits those fields (`api/app/Modules/Inventory/Requests/StoreGrnRequest.php:33-46`), the SPA types omit them (`spa/src/api/inventory/grn.ts:5-24`; `spa/src/types/inventory.ts:234-246`), the create UI only captures quantity/cost/location (`spa/src/pages/inventory/grn/create.tsx:201-260`), and the resource omits them (`api/app/Modules/Inventory/Resources/GrnItemResource.php:14-33`).

Impact: warehouse users cannot capture or review the provenance needed by the traceability/QC process even though the persistence layer supports it. `Scope: large`; `Session: separate-recommended` because it crosses Inventory, Quality, and document storage.

### GRN-09 — Broken: incoming-QC notification link points to a nonexistent SPA route

The Quality listener emits `/inventory/grns/{hash_id}` (`api/app/Modules/Quality/Listeners/TriggerIncomingQC.php:165-172`), while the SPA defines `/inventory/grn` and `/inventory/grn/:id` (`spa/src/routes/inventoryRoutes.tsx:90-95`). The notification's primary recovery link therefore lands on a missing route. `Scope: small`; `Session: separate-recommended` because the broken line is in a dependency module.

### GRN-10 — Missing: accepted GRN accounting link is not exposed to operators

GL posting creates/posts a journal and stores its ID on the GRN (`api/app/Modules/Inventory/Services/GrnGlPostingService.php:208-232`; `api/database/migrations/0173_add_journal_entry_id_to_goods_receipt_notes.php:13-22`). The GRN resource does not return `journal_entry_id` (`api/app/Modules/Inventory/Resources/GoodsReceiptNoteResource.php:15-65`), and the SPA type has no field for it (`spa/src/types/inventory.ts:195-223`).

Impact: Finance and warehouse users cannot reconcile a receipt directly to its GL entry from the GRN screen/API. `Scope: medium`; `Session: separate-recommended` because this is financial observability.

### GRN-11 — Incomplete: the GRN detail exposes handoff state but not the actual inspection

The documented operator flow requires navigating to `/quality/inspections` to find the linked incoming inspection (`docs/PROCESS-FLOWS.md:672-685`). The GRN resource exposes only handoff status/message/time (`api/app/Modules/Inventory/Resources/GoodsReceiptNoteResource.php:23-29`), and the detail page offers retry but no inspection identifier or link (`spa/src/pages/inventory/grn/detail.tsx:172-190,225-237`).

Impact: operators can see that handoff is generated but cannot follow the GRN-to-inspection record from the GRN. `Scope: medium`; `Session: separate-recommended` because it requires a cross-module contract.

## Policy question / polish

Draft finalization intentionally permits a partial submitted line set: the UI sends only positive quantity/bin rows (`spa/src/pages/inventory/grn/detail.tsx:87-98`) and enables finalization when any line is ready (`:168-171`), while the service finalizes the submitted rows (`api/app/Modules/Inventory/Services/GrnService.php:354-410`). Existing tests cover one-line finalization, so this is not classified as a defect. Clarify in the UI that omitted draft lines mean “not received yet,” or add a line-level state if the business policy requires it. `Scope: small`; `Session: same-session-ok`.

## Evidence limitation

Focused command: `cd api && php artisan test tests/Feature/Inventory/GrnQcGateTest.php tests/Feature/Inventory/GrnPartialAcceptHttpTest.php tests/Feature/Inventory/GrnIncomingQcHandoffTest.php tests/Feature/Inventory/DraftGrnOnPoSentTest.php tests/Feature/Inventory/ReceiveGoodsAuthorizationTest.php tests/Feature/Inventory/GrnRejectionTest.php tests/Feature/Inventory/GrnGlPostingTest.php tests/Feature/Inventory/LotTraceabilityTest.php`.

Result: 39 tests failed before assertions because `db` could not be resolved. Re-run after the test database/container is available; add targeted regression coverage for GRN-01, GRN-02, GRN-05, GRN-06, and GRN-07.

---

## Re-audit 2026-09-01 (session 3, in progress)

Status: **in progress**. Claim result: `RECLAIMED` (stale lock, 150h old, from the
2026-08-25 session).

Prior-work assessment: the 2026-08-25 session **committed fixes** and its
`fix-log.md` **self-flags as unverified** — "The focused Laravel suite could not
reach its configured PostgreSQL host `db` (`SQLSTATE[08006]`), so assertions
remain to be rerun." Same category as `supplier-performance`. This session's job
is therefore first to *execute* what was written, then extend into the invariants
the prior two sessions never probed (weighted-average cost, over-receipt
aggregation, AP handoff, immutability, concurrency, archived-row leakage,
money-validation family).

Findings below are appended as they are measured.

