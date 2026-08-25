# Goods Receiving Fix Log

## 2026-08-24

No product fixes applied. Findings were released as `📋 Plan Ready` because the high-impact items require a separate session and cross-module decisions. See `audit-report.md` and `action-plan.md`.

## 2026-08-25

Resumed the existing plan after claiming `inventory/goods-receiving`. Changes stayed within the Inventory GRN module, its GRN SPA surfaces, and Inventory feature tests.

- **GRN-01 / GRN-06 — QC gate hardening:** `api/app/Modules/Inventory/Services/GrnService.php:727-815,935-1032,1089-1090` now validates incoming-inspection coverage and the `qc_inspection_id` anchor before any terminal single-screen pass/fail decision or stock acceptance. Fractional QC-eligible quantities are blocked from the Inventory fallback inspection path instead of being accepted or silently truncated; the dependent Quality listener's integer batch contract remains deferred. Added regression coverage at `api/tests/Feature/Inventory/GrnQcGateTest.php:222-245,322-340`.
- **GRN-02 — PO-line identity:** `api/app/Modules/Inventory/Services/GrnService.php:146-160` rejects a submitted item that differs from the locked PO line item and uses the PO line as the downstream source of truth. Regression coverage is at `api/tests/Feature/Inventory/GrnQcGateTest.php:247-272`.
- **GRN-05 — receiving destination:** `api/app/Modules/Inventory/Services/GrnService.php:1232-1268` now rejects removed, inactive, blocked, inactive-warehouse, quarantine, and scrap destinations at the service boundary; request rules at `api/app/Modules/Inventory/Requests/StoreGrnRequest.php:43-50` and the GRN pages at `spa/src/pages/inventory/grn/create.tsx:73-84` / `spa/src/pages/inventory/grn/detail.tsx:77-88` also narrow the selectable locations. Regression coverage is at `api/tests/Feature/Inventory/GrnQcGateTest.php:275-320`.
- **GRN-07 / GRN-08 — traceability contract:** `api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php:163-171`, `api/app/Modules/Inventory/Requests/StoreGrnRequest.php:53-60`, and `api/app/Modules/Inventory/Requests/FinalizeGrnRequest.php:23-30` whitelist traceability fields and prohibit caller-supplied `coa_verified`; `api/app/Modules/Inventory/Services/GrnService.php:140-145,378-383` enforces the same rule for direct service callers. The create and draft-finalization forms capture lot/UOM/expiry/moisture/COA references, while the detail page renders them at `spa/src/pages/inventory/grn/detail.tsx:389-414`. The actual COA upload/storage and Quality verification workflow remains deferred pending ownership/design input.
- **GRN-09 / GRN-10 / GRN-11 — recovery and observability:** `spa/src/routes/inventoryRoutes.tsx:90-99` keeps the legacy plural notification URL as a working alias; `api/app/Modules/Inventory/Models/GoodsReceiptNote.php:73-81` defines the eager-loaded QC/GL relationships; `api/app/Modules/Inventory/Resources/GoodsReceiptNoteResource.php:30-48` exposes inspection status and permission-gated journal status; and `spa/src/pages/inventory/grn/detail.tsx:319-320` links to both records where the viewer has permission.

Deferred items:

- **GRN-03:** unit-cost override authority and variance approval need an explicit Purchasing/AP/Finance business rule before changing financial valuation behavior.
- **GRN-04:** separating physical receipt, pending-QC, accepted, and rejected PO projections requires coordinated Purchasing, Quality, AP, MRP, and supplier-metrics changes outside this module's scope.
- **GRN-01 / GRN-08:** the dependent Quality listener still needs a decimal-safe, line-level incoming-inspection contract; document upload/storage ownership and Quality's COA verification transition also remain undecided. Only the Inventory-side blocking, capture, and caller protection were implemented.
- **Policy question:** draft finalization still permits intentionally omitted lines; the desired “not received yet” versus all-lines-required behavior needs product confirmation.

Verification: PHP syntax checks passed for the changed GRN PHP files and `npx eslint` passed for the changed GRN SPA files. The focused Laravel suite could not reach its configured PostgreSQL host `db` (`SQLSTATE[08006]`), so assertions remain to be rerun when `ogami_test` is available. The full SPA typecheck is blocked by unrelated pre-existing errors in `spa/src/pages/assets/detail.tsx` (`qrcode`) and `spa/src/pages/return-management/detail.tsx` (duplicate JSX attribute).
