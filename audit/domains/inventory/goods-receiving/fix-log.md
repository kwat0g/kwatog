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

## 2026-09-01 (re-audit, session 3)

Claimed `RECLAIMED` (stale lock, 150h). Environment verified before probing: `db`
and `redis` Up and healthy; `select 1;` answered. The `api` container is **not**
running on this host, so everything ran via `docker compose run --rm api`.

### Prior-work assessment

The 2026-08-25 session's `fix-log` entry above self-flags as never runtime-verified
(`SQLSTATE[08006]`, host `db` unreachable). Assessed by probe, not by reading it:
**all of its work is present at HEAD and all of it passes on first real
execution** — the item-identity guard, `resolveReceivingLocation()`, the
`coa_verified` prohibition and `assertIncomingInspectionCoverage()`. Same outcome
as `supplier-performance`. **Zero of the 11 findings from 2026-08-24 reproduce.**

One exception, which became this session's P0: the same session also edited
`StoreGrnRequest` and that edit broke `POST /api/v1/inventory/grn` for every
request. Not its fault that it shipped unmeasured — but nothing in the repository
posts to that route, so 52 green tests could never have caught it either.

### Baseline

`52 passed (181 assertions), 31.78s` on the focused GRN set in `ogami_test_grn`.

### Fixed

- **GRN-R1 — P0: `POST /api/v1/inventory/grn` 500'd on every request.**
  `api/app/Modules/Inventory/Requests/StoreGrnRequest.php:56-69`. The location
  predicate used `Rule::exists(...)->where('is_blocked', false)`;
  `DatabaseRule::formatWheres()` string-serialises the value, `(string) false`
  is `''`, and PostgreSQL answers `22P02 invalid input syntax for type boolean: ""`.
  Now a query closure, which `ValidationRuleParser::prepareRule()` keeps
  unserialised (it preserves an `Exists` object only when `queryCallbacks()` is
  non-empty). **Before:** HTTP 500 on a valid payload. **After:** HTTP 201,
  `data.status = pending_qc`, one `grn_items` row at `5.000`.

- **GRN-R2 — the incoming-QC gate failed OPEN for a soft-deleted item.**
  `api/app/Modules/Inventory/Services/GrnService.php:822-857`. `qcEligibleLineIds()`
  read `$line->item`, which `SoftDeletes` makes null, silently emptying the
  eligible set and short-circuiting both `assertIncomingInspectionCoverage()` and
  `assertQcGate()`. Item now resolves `withTrashed()`; a line whose item cannot be
  resolved at all counts as **eligible**, so an anomaly requires QC rather than
  waiving it. **Before:** archived item + zero inspection rows + nulled
  `qc_inspection_id` → `ACCEPTED`, `stock_levels.quantity = 10.000`,
  1 `stock_movements` row. **After:** `BusinessRuleException` "no incoming
  inspection records", GRN stays `pending_qc`, no `stock_levels` row, 0 movements.
  The live-item control was and remains REFUSED, which is what pins the cause on
  the soft delete rather than on the `modules.accounting` setting.

- **GRN-R3 — money/quantity validation family on three surfaces.**
  `FinalizeGrnRequest.php:16-31`, `AcceptGrnRequest.php:16-29` (which had **no**
  per-value rule at all), `GoodsReceiptNoteController.php:153-176`. All used bare
  `numeric`; `is_numeric('1e3')` is true but `bccomp('1e3','0',3)` raises a
  `ValueError` that no controller arm catches. **Before:** HTTP 500 on
  `1e3`/`1e17`/`1e20` on all three; `finalize` stored `1.9999` as **2.000**;
  `accept` stored `1.9999` as **2.000**; `receive-goods` stored a `unit_cost` of
  `10.00005` as **10.0001** and accepted `'1e3'` as a **₱1000** price — both of
  which `StockMovementService` blends into `weighted_avg_cost` and
  `GrnGlPostingService` debits to inventory. **After:** 422 on every one, and
  nothing persisted (asserted `GrnItem::count() === 0` / `quantity_accepted`
  unchanged at `0.000`). Now uses the `decimal:0,3` / `decimal:0,4` shape
  `StoreGrnRequest` already had, plus column-matching `max` so an
  in-range-looking decimal cannot overflow into a `22003`.

- **GRN-R4 — 500 on a soft-deleted PO or item.** `StoreGrnRequest.php:38-52`
  added `whereNull('deleted_at')` to the `purchase_orders` and `items`
  predicates, matching the `warehouse_locations` one beside them.
  `purchase_order_items` has no `SoftDeletes`, so its plain `exists` is correct
  and was left alone. **Before:** HTTP 500 each. **After:** 422 with the field
  keyed.

- **Bonus (same class as R2):** `GrnGlPostingService.php:96-101` resolved the
  line's item with `Item::query()->…->firstOrFail()`, so accepting a receipt
  whose item was archived *after* a genuine QC pass died on an unhandled
  `ModelNotFoundException` — a 500 with no message a receiving clerk can act on.
  Now `withTrashed()`. The routed account is derived from `item_type`, which
  archiving does not change, so the accounts and the amount are identical to what
  they would have been beforehand. **No valuation figure changed.**

### Verification

- `tests/Feature/Inventory` (whole directory, explicit path):
  **169 passed / 557 assertions / 0 failed**, up from the 52-test focused
  baseline. No pre-existing test turned red — so this is neither of the two
  precedents (no revert needed, no red fixture to keep).
- New coverage: `api/tests/Feature/Inventory/GrnHttpContractTest.php`, 31 tests.
  **Confirmed red against unmodified source: 29 of 31 fail** (HEAD versions
  swapped in via `git show HEAD:<path>`, then restored and proven with
  `sha256sum -c` → 6/6 OK). The 2 that pass either way are labelled:
  `incoming qc gate holds for a live item with no inspections` (the deliberate
  control) and `accept … "overflows the column"` (a service check catches it
  before the column does).
- `php -l` clean on all 7 files. `phpstan` **[OK] No errors** on the 6 source files.
- `pint --test`: **NEW (mine only) = []** on all five pre-existing files, proven
  by extracting `git show HEAD:api/<path>` and diffing rule lists
  programmatically — `binary_operator_spaces`, `braces_position`,
  `ordered_imports` etc. are all inherited. `AcceptGrnRequest.php` was clean at
  HEAD and my aligned `=>` broke it, so that one I fixed. The new test file is
  entirely mine and was formatted with Pint in write mode (that file only).
- Scratch probes deleted (`GrnReauditProbeTest`, `E4ProbeTest`, `E5ProbeTest`);
  `ogami_test_grn` dropped.

### Deferred (reported, not fixed — with reasons)

- **GRN-R5** — an accepted GRN is fully mutable and hard-deletable, with **zero**
  triggers on any of the four tables. Measured: quantity 10 → 999 and cost
  10 → 1.0000 after stock and GL moved; status rewound `accepted` → `pending_qc`;
  `delete()` cascaded `grn_items` away and left an orphan `stock_movements` row.
  `bills.goods_receipt_note_id` is `ON DELETE SET NULL`, so deleting a GRN strips
  an approved bill of its provenance instead of refusing. **No HTTP surface
  reaches any of it** (no update or destroy route), so it is latent. Needs a
  migration plus an observer and decides what "final" means for a financial
  record — and the FK change reaches into Accounting.
- **GRN-R6** — the partial-accept remainder has no reachable destination: on a
  40-received / 25-accepted GRN, `reject` is refused, reducing accepted is
  refused, and `material_review_records` stays at 0. MRB quarantine cannot help
  because it works by transferring an existing `stock_levels` row, and these
  goods never entered stock. Crosses Purchasing, MRB (a separate unaudited
  module) and ReturnManagement.
- **GRN-R7** — `purchase_order_items.quantity_received` is `numeric(12,2)`
  against `grn_items.quantity_received`'s `numeric(15,3)`, and
  `quantity_accepted` on the *same table* is `numeric(12,3)`. Measured: 4 receipts
  of `10.005` gave `sum(grn_items) = 40.020` but a PO-line total of **40.04**; and
  ordered `10.00` + a receipt of `9.994` (stored `9.99`) let a further `0.010`
  through, for **10.004 received against a 10.00 order at zero tolerance**. The
  column and its `decimal:2` cast belong to **Purchasing**. A containment inside
  Inventory exists (derive the remainder from `SUM(grn_items.quantity_received)`)
  but it changes the basis of the over-receipt decision and interacts with
  `reversePoReceipt()`.
- **GRN-R8** — an archived vendor renders an accepted receipt with
  `data.0.vendor = null` (no 500s, no aggregate leakage). Presentation policy.
- **GRN-R9** — `DocumentSequenceService::generate()`'s lock-then-bare-`insert`
  race applies to `grn`. **Shared service in `Common` — not this module's.**
  Format and uniqueness themselves measured correct.
- **GRN-R10** — `POST /inventory/receive-goods` is dead twice over: no SPA caller
  for `grnApi.receiveGoods`, and no seeded non-admin role holds both
  `inventory.grn.create` and `quality.inspections.manage` (measured 403). Needs a
  product decision, not a fix.
- **Carried forward unchanged:** GRN-03 (unit-cost override authority — R3 stops
  the value being *malformed*, it does not decide who may override a PO price),
  GRN-04 (physical vs QC-accepted PO projections), GRN-08's COA upload/storage
  half, and the draft-finalization policy question.

### Could not verify

- **The AP bill quantity at runtime.** `AutoCreateBillOnGrnAccepted` is a queued
  listener dispatched through the outbox and does not run inline; measured 0
  `bills` rows after both a partial and a full acceptance. Verified by reading
  instead — `BillService::createDraftForGrn()` bills `quantity_accepted` and
  skips `<= 0` lines, and `assertBillProvenance()` refuses a stock bill whose GRN
  is not `Accepted`. AP is explicitly not mine to fix.
- **Two concurrent receipts on one PO line at runtime.** `RefreshDatabase` hides
  uncommitted fixtures from a second connection, so the probe would have reported
  "no lock" as an artifact. Abandoned deliberately. Verified statically: `create()`
  takes `PurchaseOrder … lockForUpdate()` then a per-line
  `PurchaseOrderItem … lockForUpdate()` before reading the running total.
- **A restore route.** None exists and none should — `GoodsReceiptNote` has no
  `SoftDeletes`.
- **File attachments.** `coa_document_path` is a bare `string(500)`; there is no
  upload endpoint, no MIME validation and no serving controller anywhere in the
  module. That is GRN-08's still-deferred half, not a new finding.
