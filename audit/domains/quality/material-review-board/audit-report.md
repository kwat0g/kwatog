# M054 — material-review-board audit report

Audit date: 2026-08-25  
Status: 🔁 Needs Re-audit  
Claim: quality / material-review-board  
Scope: MRB hold/quarantine creation, stock movement isolation, quality/NCR linkage, disposition release, supplier return handoff, permissions, lifecycle state, and the related Inventory SPA/API surfaces.

## Production readiness

Production audit: 58/100, risky, because held inventory can bypass the MRB release path and the `rework`/`use_as_is` release path can return material to good stock without a second Quality or concession-evidence gate.

Blockers:

- Enforce same-warehouse, active, zone-correct location validation for every hold/release movement.
- Make quarantine stock exclusive to the MRB release workflow rather than generic transfers or adjustments.
- Define and enforce the Quality gate for rework/use-as-is, including concession evidence where required.
- Complete supplier-return provenance and purchasing/accounting handoff before marking an MRB returned.

## Decision

This resumed plan received contained fixes for F001, F005, F006, F007, and F008. F002, F003, F004, and F009 remain open because they require cross-module policy or human decisions that cannot be safely inferred inside this module. The module is released as 🔁 Needs Re-audit; see the execution record in `action-plan.md` and `fix-log.md`.

The finding evidence below is the baseline audit evidence. It is intentionally not presented as a fresh full audit after the targeted fixes; the next session should re-check the changed findings and the deferred decisions.

## Findings

### M054-F001 — Explicit location IDs can violate quarantine, warehouse, and active-location invariants

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The request validates only that source/quarantine/target location IDs exist, with no active, zone, warehouse, or source-stock relationship checks at `api/app/Modules/Inventory/Requests/StoreMrbRequest.php:40-46` and `api/app/Modules/Inventory/Requests/ReleaseMrbRequest.php:32-39`.
- `hold()` uses the safe same-warehouse active-zone resolver only when the quarantine location is omitted. An explicit ID is accepted as-is at `api/app/Modules/Inventory/Services/QuarantineService.php:96-104`; the resolver's stricter rules are at `:291-309`.
- Rework/use-as-is release rejects only quarantine and scrap target zones; it does not require an active target or the same warehouse as the held quarantine location at `QuarantineService.php:203-218`.
- The warehouse tree returns all locations at `api/app/Modules/Inventory/Services/WarehouseService.php:22-29`. The hold form filters explicit quarantine choices by zone type but not `is_active` at `spa/src/pages/inventory/mrb/index.tsx:239-253`, and the release form filters only quarantine—not inactive locations or warehouse identity—at `spa/src/pages/inventory/mrb/detail.tsx:226-239`.

Impact: a caller can hold stock into an ordinary, inactive, or cross-warehouse location, and can release held stock into an inactive or different warehouse. The MRB row then claims quarantine/release semantics that the physical stock ledger does not honor.

### M054-F002 — Held stock can bypass MRB release through generic transfers and adjustments

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The stock movement guard blocks quarantine/scrap sources only for `MaterialIssue` and `Delivery`; `Transfer` and adjustment paths are deliberately allowed through `api/app/Modules/Inventory/Services/StockMovementService.php:238-270,272-293`.
- Transfer orders execute a generic transfer without checking for a held MRB at `api/app/Modules/Inventory/Services/TransferOrderService.php:57-77`; their create/execute routes are available under `inventory.adjust` at `api/app/Modules/Inventory/routes.php:122-127`.
- Stock adjustments also pass `AdjustmentOut`/`AdjustmentIn` movements without an MRB ownership check at `api/app/Modules/Inventory/Services/StockAdjustmentService.php:231-243`.
- The warehouse role has `inventory.adjust` as well as `inventory.mrb.manage` at `api/database/seeders/RolePermissionSeeder.php:610-621`.

Impact: held stock can be transferred back to good inventory, adjusted out, or otherwise changed without a release movement. The MRB remains `held` with a stale quantity and later release can fail, double-account, or act on a different physical balance. Issue/picking guards are not sufficient ownership controls for the full inventory mutation surface.

### M054-F003 — Rework and use-as-is release has no reinspection or concession-evidence gate

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The Quality disposition contract describes `rework` as repair to specification and `use_as_is` as a concession requiring customer sign-off at `api/app/Modules/Quality/Enums/NcrDisposition.php:8-13`.
- M054 handles both dispositions identically: it only requires a target location, rejects quarantine/scrap zones, transfers the held quantity to good stock, and marks the MRB released at `api/app/Modules/Inventory/Services/QuarantineService.php:197-221`.
- The release request accepts only disposition, target location, and optional free-text notes at `api/app/Modules/Inventory/Requests/ReleaseMrbRequest.php:32-39`; there is no reinspection ID/result, concession approval, sign-off actor, or supporting evidence field.
- The NCR service's separate rework/replacement behavior is reached from NCR closure at `api/app/Modules/Quality/Services/NcrService.php:247-331`, but M054 release does not require or invoke that Quality lifecycle.

Impact: nonconforming material can re-enter issuable good stock on a warehouse/QC release command without proof that rework passed or that a use-as-is concession was authorized. The status `released` overstates the evidence captured by the module.

### M054-F004 — Return-to-supplier marks inventory returned without supplier or procurement provenance

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- The MRB schema records item, locations, optional NCR/inspection, quantity, movements, users, and notes, but no vendor, PO, GRN, bill, lot, or supplier-return document fields at `api/database/migrations/0262_create_material_review_records_table.php:20-56`.
- The `return_to_supplier` branch posts only a generic `ReturnToVendor` stock movement and changes the MRB status to `returned` at `api/app/Modules/Inventory/Services/QuarantineService.php:240-267`.
- The movement's accounting mapping is a generic inventory/GRNI posting at `api/app/Modules/Inventory/Services/MovementGlPostingService.php:245-272`; M054 does not create or link a supplier dispatch, GRN reversal, bill credit, or purchasing notification.
- The documented failed-incoming-QC flow says supplier return requires a new PO/restart at `docs/PROCESS-FLOWS.md:1409-1417`, while the M054 release request has no supplier/source fields.

Impact: the inventory ledger can say material was returned while Purchasing and Accounts Payable cannot identify the supplier receipt, quantity, or financial document being reversed. If the intended policy is that M054 is only a physical write-off handoff, that ownership boundary must be explicit; as shipped, the terminal `returned` state implies more completion than the module proves.

### M054-F005 — NCR and inspection links are existence-only, incomplete in the UI, and not item/status-bound

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- `StoreMrbRequest` checks only `exists` for `ncr_id` and `inspection_id` at `api/app/Modules/Inventory/Requests/StoreMrbRequest.php:40-46`.
- `hold()` copies those IDs without checking item/product identity, inspection entity/stage/status, NCR status, or affected quantity at `api/app/Modules/Inventory/Services/QuarantineService.php:124-135`.
- The linked models carry the fields needed for those checks—NCR product/inspection/status at `api/app/Modules/Quality/Models/NonConformanceReport.php:36-72` and inspection item/entity/status at `api/app/Modules/Quality/Models/Inspection.php:34-55,62-80`—but M054 does not use them.
- The SPA loads the first 100 NCRs without an item/status filter and sends no inspection ID at `spa/src/pages/inventory/mrb/index.tsx:255-269`; the resource exposes only an inspection hash, not its stage/status, at `api/app/Modules/Inventory/Resources/MaterialReviewRecordResource.php:34-41`.

Impact: an MRB can be attached to an unrelated, passed, cancelled, or closed quality record, and the operator cannot see the inspection result from the MRB page. The central quality-to-quarantine trace is therefore not authoritative or usable for release decisions.

### M054-F006 — Hold commands have no durable idempotency contract

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- The store endpoint accepts a normal request and immediately calls `hold()` with no idempotency key or command identity at `api/app/Modules/Inventory/Controllers/MrbController.php:49-59`.
- Each call generates a new MRB number, inserts a new record, and posts a new physical transfer at `api/app/Modules/Inventory/Services/QuarantineService.php:120-153`; the migration guarantees only uniqueness of the generated number at `0262_create_material_review_records_table.php:20-23`.
- The focused race coverage protects duplicate release, not a timed-out/replayed hold (`api/tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php:102-137`).

Impact: a client timeout after commit followed by a retry can quarantine the same intended event twice whenever source quantity remains. The second MRB is indistinguishable from a legitimate separate hold and can create excess quarantine, downstream release, and GL effects.

### M054-F007 — The MRB search field is a no-op

Classification: Broken  
Priority: P2  
Session: same-session-ok

Evidence:

- The SPA renders a search control and stores `search` in the URL filters at `spa/src/pages/inventory/mrb/index.tsx:144-150`.
- The controller forwards only `status`, `item_id`, and `per_page` at `api/app/Modules/Inventory/Controllers/MrbController.php:37-41`, so `search` is discarded before the service sees it.
- `QuarantineService::list()` implements only status and item filters at `api/app/Modules/Inventory/Services/QuarantineService.php:45-65`.

Impact: operators searching by MRB ticket number receive the unfiltered result set. In a growing quarantine queue this obscures the record needed for a release or incident response while the UI falsely indicates that the search was applied.

### M054-F008 — Client and API quantity precision disagree, and lookup lists are bounded without recovery

Classification: Polish  
Priority: P2  
Session: same-session-ok

Evidence:

- The API accepts at most three decimal places at `api/app/Modules/Inventory/Requests/StoreMrbRequest.php:40-43`, while the hold form accepts four at `spa/src/pages/inventory/mrb/index.tsx:38-47`.
- The hold modal fetches only 500 active items and 100 NCRs with no search, pagination control, or query-error state at `spa/src/pages/inventory/mrb/index.tsx:228-258`.
- The material quantity is a three-decimal database value at `api/database/migrations/0262_create_material_review_records_table.php:29-30`, so a client-side value accepted as `1.2345` will fail only after submission rather than being rejected consistently in the form.

Impact: operators can select an incomplete lookup list or enter a value the form says is valid but the API rejects. The failure path is especially poor for a hold operation because the operator may already be responding to a physical quality exception.

### M054-F009 — High-impact release dispositions have no dual-control policy boundary

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- Both hold and release use the same `inventory.mrb.manage` permission at `api/app/Modules/Inventory/routes.php:156-157` and `api/app/Modules/Inventory/Requests/StoreMrbRequest.php:21-24,ReleaseMrbRequest.php:20-23`.
- Seeded warehouse staff and QC inspectors both receive that permission at `api/database/seeders/RolePermissionSeeder.php:619-645`; there is no separate disposition-approval permission or approval record in the M054 schema.
- A single authorized operator can therefore create the hold and directly scrap, return to supplier, rework, or use-as-is release through `QuarantineService::release()` at `:165-221`.

Impact: the system does not prove whether a high-value write-off, supplier return, or use-as-is concession received an independent Quality/management decision. This may be intentional for the plant, but the shipped permission model and audit record do not state the policy. Confirm the segregation-of-duties threshold before treating M054 as production-ready.

## Verified strengths

- Inventory and MRB routes are behind Sanctum, the inventory feature flag, and server-side view/manage permissions at `api/app/Modules/Inventory/routes.php:24,152-157`.
- Hold and release writes use database transactions, decimal-string quantity arithmetic, authoritative WAC movements, and allow-listed `material_review_record` source references.
- Release re-reads and locks the MRB row before checking `held`, preventing duplicate release movement; the focused stale-release race tests passed.
- Source availability/reservations are checked before a hold, and quarantine/scrap stock is excluded from material issue and picking paths.
- The MRB status domain is database-guarded (`held`, `released`, `scrapped`, `returned`) at `api/database/migrations/2026_08_13_221000_add_enum_lifecycle_status_guards.php:53,72-99`.
- Hash IDs are decoded at the request boundary and raw IDs are not exposed by the resource; role responsibility and dashboard badge tests passed.

## Verification

Passed:

- `docker compose run --rm api php artisan test tests/Feature/Inventory/QuarantineMrbTest.php tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php tests/Feature/Auth/RoleResponsibilityAlignmentTest.php tests/Feature/Dashboard/BadgeControllerTest.php tests/Feature/Dashboard/BadgeRealtimeTest.php` — 40 tests, 332 assertions.
- `docker compose run --rm spa npm run typecheck` — passed.

Evidence limits:

- No authenticated browser journey was run for raising a hold with invalid/inactive/cross-warehouse locations, linking an inspection/NCR, releasing use-as-is/rework, returning to supplier, or using the search field.
- No focused test covers duplicate/replayed hold requests, generic transfer/adjustment from held stock, invalid quality-link identity/status/quantity, release target activity/warehouse, supplier accounting/procurement reconciliation, or dual-control expectations.
- No deployed warehouse configuration, supplier workflow, or live GL/purchasing handoff was available for a production drill.

## Resumed-plan verification

- `docker compose run --rm api php artisan test tests/Feature/Inventory/QuarantineMrbTest.php tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php` — 16 tests, 49 assertions passed, including the new location, idempotency, and quality-link cases.
- Targeted ESLint for both MRB SPA pages — passed. The latest repository-wide `npm run typecheck` is blocked by pre-existing errors in `src/pages/assets/detail.tsx` (`qrcode`) and `src/pages/return-management/detail.tsx`; no M054 file appears in that error set. The repository-wide lint command also reports three unrelated pre-existing hook dependency errors outside M054.
