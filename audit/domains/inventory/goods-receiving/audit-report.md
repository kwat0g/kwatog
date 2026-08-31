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

Claim result: `RECLAIMED` (stale lock, 150h old, from the 2026-08-25 session).

Prior-work assessment: the 2026-08-25 session **committed fixes** and its
`fix-log.md` **self-flags as unverified** — "The focused Laravel suite could not
reach its configured PostgreSQL host `db` (`SQLSTATE[08006]`), so assertions
remain to be rerun." Same category as `supplier-performance`. This session's job
is therefore first to *execute* what was written, then extend into the invariants
the prior two sessions never probed (weighted-average cost, over-receipt
aggregation, AP handoff, immutability, concurrency, archived-row leakage,
money-validation family).

### Baseline (measured, not assumed)

```
docker compose run --rm -e DB_DATABASE=ogami_test_grn api php -d memory_limit=512M \
  artisan test tests/Feature/Inventory/{GrnQcGate,GrnPartialAcceptHttp,GrnIncomingQcHandoff,\
  DraftGrnOnPoSent,ReceiveGoodsAuthorization,GrnRejection,GrnGlPosting,LotTraceability,\
  WeightedAvgCost,CycleCountWac}Test.php --no-coverage
→ Tests: 52 passed (181 assertions). Duration: 31.78s.
```

**Every one of the 2026-08-25 session's committed fixes passes on first real
execution.** All 11 findings from 2026-08-24 (GRN-01…GRN-11) are closed or
deferred as its log says, and none of the closed ones reproduce. Verified by
probe, not by reading the log: the item-identity guard
(`GrnService.php:157-161`), `resolveReceivingLocation()` (`:1232-1270`), the
`coa_verified` prohibition (`:140-144`, `:378-382`) and
`assertIncomingInspectionCoverage()` (`:773-815`) are all present at HEAD and all
hold under test.

The prior session's *unverified* status was therefore honest and its work was
sound — with one exception, which is this re-audit's P0: it also edited
`StoreGrnRequest` and that edit **breaks the module's primary create endpoint on
every request**. Nothing measured it because no test in the repository hits
`POST /api/v1/inventory/grn`.

Findings below are all newly measured against real PostgreSQL rows in
`ogami_test_grn`.

## Findings — Broken

### GRN-R1 — **P0**: `POST /api/v1/inventory/grn` returns 500 for every request

`api/app/Modules/Inventory/Requests/StoreGrnRequest.php:47-53`:

```php
'items.*.location_id' => [
    'required', 'integer',
    Rule::exists('warehouse_locations', 'id')
        ->whereNull('deleted_at')
        ->where('is_active', true)
        ->where('is_blocked', false),      // ← this
],
```

Laravel serialises an `Exists` rule object to a string
(`Illuminate\Validation\Rules\DatabaseRule::formatWheres()` runs
`str_replace('"', '""', $where['value'])`). PHP casts `false` to `''`, so the
rule becomes `is_blocked,""` and the presence verifier issues
`where "is_blocked" = ''` against a PostgreSQL `boolean`:

```
SQLSTATE[22P02]: Invalid text representation: ERROR: invalid input syntax for type boolean: ""
CONTEXT: unnamed portal parameter $3 = ''
SQL: select count(*) as aggregate from "warehouse_locations"
     where "id" = 1 and "deleted_at" is null and "is_active" = 1 and "is_blocked" =
```

Measured on a fully valid happy-path payload: **HTTP 500**. `->where('is_active', true)`
survives only because `(string) true === '1'`, which PostgreSQL accepts for a boolean.

Two aggravating details:

1. The failing `select` aborts the surrounding PostgreSQL transaction, so every
   later statement on that connection dies with `25P02 current transaction is
   aborted`. In a request that is invisible; in a test it cascades.
2. Introduced by the 2026-08-25 GRN-05 fix, swept into `167de85e`
   ("NOT REVIEWED. This commit exists to make the branch buildable"). **No test
   in the repository posts to `/api/v1/inventory/grn`**, which is why a
   completely dead create endpoint passed 52 green tests for six days.

`Scope: small` · `Session: same-session-ok` — a one-value validation predicate.

### GRN-R2 — the incoming-QC coverage gate fails **OPEN** for a soft-deleted item

`GrnService::qcEligibleLineIds()` (`api/app/Modules/Inventory/Services/GrnService.php:823-840`)
derives QC eligibility from the line's item:

```php
foreach ($grn->items as $line) {
    if (! $line->item) { continue; }          // ← archived item ⇒ line not eligible
    if ($line->item->item_type === ItemType::RawMaterial) { $eligible[] = ...; }
```

`Item` uses `SoftDeletes` and `GrnItem::item()` does not `withTrashed()`, so
archiving an item in inventory-master silently empties the eligible set —
and `assertIncomingInspectionCoverage()` returns early on `$eligibleLineIds === []`
(`:790-792`), and `assertQcGate()` returns early on `$inspections->isEmpty()`
(`:732-734`).

Measured, with a control:

| scenario (`modules.accounting=false`, zero inspection rows, `qc_inspection_id` nulled) | result |
|---|---|
| item **live** | REFUSED — "has no incoming inspection records; incoming QC must be completed before acceptance" · stock none |
| item **soft-deleted** | **ACCEPTED** · `grn=accepted` · `stock_levels.quantity=10.000` · `stock_movements=1` |

This is precisely the F-06 invariant the whole coverage mechanism exists to
enforce, defeated by archiving one row in another module.

With `modules.accounting=true` the same receipt *is* refused — but by an
unhandled `ModelNotFoundException` out of
`GrnGlPostingService.php:96` (`Item::query()->whereKey($row->item_id)->firstOrFail()`),
i.e. a 500 rather than the gate's 422. So the gate is not what is holding; the
GL posting happening to re-read the same archived row is.

`Scope: small` · `Session: same-session-ok` — a missing guard that refuses input
already intended to be refused. It changes no valuation figure, no bill-matching
quantity, and nobody's authority to accept goods.

### GRN-R3 — money/quantity validation family: three endpoints 500, three silently round

`StoreGrnRequest` gets this right (`decimal:0,3` for quantity, `decimal:0,4` for
cost). The other three receiving surfaces use bare `numeric`, which `is_numeric()`
accepts for scientific notation while `bccomp()` throws `ValueError` on it
(confirmed on PHP 8.3.30: `bccomp('1e3','0',3)` → *"Argument #1 ($num1) is not
well-formed"*).

| surface | field | rule (file:line) | `1e3` | `1e17` | `1e20` | silent rounding |
|---|---|---|---|---|---|---|
| `PATCH /grn/{id}/finalize` | `quantity_received` | `FinalizeGrnRequest.php:23` `numeric` | **500** | **500** | **500** | `1.9999` → stored **2.000**; `10.00005` → `10.000` |
| `PATCH /grn/{id}/accept` | `item_accepted_map.*` | `AcceptGrnRequest.php:19` — `['nullable','array']`, **no per-value rule at all** | **500** | **500** | **500** | `1.9999` → stored **2.000** |
| `POST /receive-goods` | `unit_cost` | `GoodsReceiptNoteController.php:162` `numeric` | 201, stored **1000.0000** | **500** (PG `22003`) | **500** | `10.00005` → stored **10.0001** |
| `POST /receive-goods` | `quantity_received` | `GoodsReceiptNoteController.php:161` `numeric` | 500 | 500 | 500 | as above |

The 500s come from `bccomp` in `GrnService.php:399` (finalize) and `:628`
(partial accept), and from a PostgreSQL `numeric(15,4)` overflow for `unit_cost`.
None is caught: the controller arms catch
`BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException`,
and `ValueError` is none of them.

The rounding half matters more than the 500s: `unit_cost` is the value
`StockMovementService::move()` blends into `weighted_avg_cost` and
`GrnGlPostingService` debits to inventory, so `10.00005 → 10.0001` is a
fabricated cost, and `1e3` accepted as a price is a caller-controlled valuation
with no variance policy (still-open GRN-03).

`Scope: small` · `Session: same-session-ok` — align the three on the rule
`StoreGrnRequest` already uses. This *narrows* accepted input only.

### GRN-R4 — `POST /grn` 500s on a soft-deleted PO and on a soft-deleted item

`StoreGrnRequest.php:38` and `:42` use bare `exists:purchase_orders,id` /
`exists:items,id` with no `whereNull('deleted_at')`, unlike the `location_id`
rule beside them. Both models use `SoftDeletes`. Measured: **HTTP 500** for each
(archived PO, archived item). Currently masked by GRN-R1; both survive its fix,
because validation passes and the service then dereferences a trashed row.

`Scope: small` · `Session: same-session-ok`.

## Findings — Missing

### GRN-R5 — an accepted GRN is fully mutable and hard-deletable; **zero** triggers

Once stock and GL have moved a GRN is a financial record. Measured on an accepted
GRN whose stock movement and journal entry both exist:

| attempt | result |
|---|---|
| `GrnItem::fill(['quantity_received'=>'999.000','unit_cost'=>'1.0000'])->save()` | **ALLOWED** — reads back `999.000` / `1.0000` |
| rewind `status` accepted → `pending_qc` and save | **ALLOWED** |
| `$grn->delete()` (hard — no `SoftDeletes` on the model) | **ALLOWED** — `grn_items` cascaded to 0 rows, **1 orphan `stock_movements` row** left pointing at a nonexistent GRN |

```
information_schema.triggers where event_object_table in
  ('goods_receipt_notes','grn_items','stock_levels','stock_movements')  →  0 rows
```

And the FK inventory:

```
bills.goods_receipt_note_id  →  ON DELETE SET NULL
grn_items.goods_receipt_note_id → ON DELETE CASCADE
```

so deleting a GRN silently strips a bill — including an approved one — of its
receipt provenance rather than refusing. `assertPersistedBillProvenance()` then
throws for any *future* posting attempt, but an already-posted bill just loses
its link.

Mitigating fact, stated plainly: **no HTTP surface reaches any of this.** There is
no update route and no destroy route for a GRN, and every illegal service-level
transition is refused (see the invariant table). This is a latent integrity gap
reachable from a service, a console command or tinker — the same shape
`journal-ledger` closed with an observer **plus** a PostgreSQL `P0001` trigger.

`Scope: medium` · `Session: separate-recommended` — needs a migration plus an
observer, and it decides what "a receipt is final" means for a financial record.

### GRN-R6 — the partial-accept remainder has no reachable destination

Measured on a GRN with 40.000 received and 25.000 accepted (`partial_accepted`):

| attempt to dispose of the 15.000 remainder | result |
|---|---|
| `reject()` | REFUSED — *"Only pending_qc GRNs can be rejected."* |
| `partialAccept()` reducing the line to 0 | REFUSED — *"Accepted quantity … cannot be reduced after stock was posted."* |
| `material_review_records` rows created anywhere in the flow | **0** |

The 15 units are physically in the building, absent from `stock_levels`, still
counted in `purchase_order_items.quantity_received` (so the PO can never reach
`received`), and have no return-to-supplier, MRB or scrap transition. MRB
quarantine cannot help: `QuarantineService` holds stock by **transferring a
`stock_levels` row into a quarantine-zone location**, and these goods never
entered `stock_levels`. `StockMovementType::ReturnToVendor` is produced only by
`ReturnManagement/Services/ReturnRequestService.php:1693` and
`QuarantineService.php:377`, both of which likewise start from stock on hand.

`Scope: large` · `Session: separate-recommended` — crosses Inventory, Purchasing,
material-review-board and ReturnManagement. **Reported, not fixed** (MRB is a
separate unaudited module).

## Findings — Incomplete

### GRN-R7 — the PO-line running total is `numeric(12,2)` while GRN lines are `numeric(15,3)`, and the over-receipt guard is breachable

```
purchase_order_items.quantity          numeric(12,2)
purchase_order_items.quantity_received numeric(12,2)   ← cast decimal:2
purchase_order_items.quantity_accepted numeric(12,3)   ← cast decimal:3  (same table!)
grn_items.quantity_received            numeric(15,3)
```

`GrnService.php:197` computes the remainder from the 2-decimal running total:
`$remaining = bcsub((string) $poi->quantity, (string) $poi->quantity_received, 3)`.

Measured:

| probe | result |
|---|---|
| 4 receipts of `10.005` on one line | `sum(grn_items.quantity_received)` = **40.020**, `purchase_order_items.quantity_received` = **40.04** — each receipt rounded up independently |
| ordered `10.00`, receive `9.994` (PO line stores `9.99`), then receive `0.010` | second receipt **ALLOWED**; total physically received **10.004** against a 10.00 order, with `inventory.over_receipt_tolerance_pct` at its default **0** |

So the stated invariant ("only the remainder may be received") is measurably
breachable, by up to ~0.005 per receipt, purely from the scale mismatch.

Root cause is the Purchasing column and its `decimal:2` cast
(`api/app/Modules/Purchasing/Models/PurchaseOrderItem.php:27`). **Reported, not
fixed — Purchasing owns it.** A containment inside Inventory is possible
(derive the remainder from `SUM(grn_items.quantity_received)` instead of the
running total) but that changes the basis of the over-receipt decision and
interacts with `reversePoReceipt()`, so it is `separate-recommended`.

### GRN-R8 — an archived vendor renders an accepted receipt with a null supplier identity

Archiving the PO, the vendor and the item behind an accepted GRN:

| surface | status | payload |
|---|---|---|
| `GET /inventory/grn` | 200 (total unchanged, 1) | `data.0.vendor` = **null** |
| `GET /inventory/grn/{id}` | 200 | — |
| `GET /inventory/dashboard` | 200 | — |

No 500s and no row leakage into or out of aggregates (a GRN is not
soft-deletable, so it correctly stays visible). But an accepted receipt whose
supplier reads `null` is the same null-identity shape `supplier-performance`
found. Same for the item on the line.

`Scope: small` · `Session: same-session-ok` if the desired behaviour is
`withTrashed()` on the display relations; **question** below, because it is a
presentation decision.

### GRN-R9 — `DocumentSequenceService::generate()` insert race applies to `grn`

`api/app/Common/Services/DocumentSequenceService.php:64-88` does
`SELECT … FOR UPDATE` → on miss, a bare `insert()` → re-`SELECT … FOR UPDATE`.
Concurrent first-callers in a month both miss and both insert; the loser gets
`23505`. This is the shared-service defect already measured elsewhere (4 of 8
callers). **Shared-service scope — reported, not fixed** (`Common` is not mine).

Format and uniqueness themselves are correct and were measured:
`GRN-202609-0001 … GRN-202609-0005`, 5/5 distinct, and
`goods_receipt_notes_grn_number_unique` exists as a unique btree index.

## Findings — Polish / dead surfaces

### GRN-R10 — `POST /api/v1/inventory/receive-goods` is dead twice over

1. **No client.** `grnApi.receiveGoods` is defined at
   `spa/src/api/inventory/grn.ts:51` and called from **nowhere** in `spa/src`
   (grep across `.ts`/`.tsx`).
2. **No role can use it.** A terminal `qc.result` requires the route's
   `permission:inventory.grn.create` **and** the controller's
   `quality.inspections.manage` check (`GoodsReceiptNoteController.php:185-191`).
   Measured: a `grn.create`-only role posting `qc.result=passed` → **403**. Per
   `RolePermissionSeeder`, `warehouse_staff` and `purchasing_officer` hold
   `inventory.grn.create` without any quality permission; `qc_inspector` holds
   `quality.*` without `inventory.grn.create`. Only `system_admin` (whose
   `hasPermission()` short-circuits to `true`) holds both.

This reads as the intended outcome of `7dd5e50d fix: separate receiving from
terminal QC` — the segregation is *correct*, it just leaves the endpoint with no
user. Non-terminal use (`qc.result=pending`) does work for a receiving role.
See the question below.

## Invariants that HOLD (measured, no finding)

Recorded so a later session does not re-derive them: over-receipt refused;
receipt refused against `draft`/`cancelled`/`closed`/`received`/`pending_approval`
POs; aggregate over-receipt across two partial receipts refused; zero, negative
and sub-tolerance quantities refused; WAC exact to 4 dp against an independent
BCMath figure (`10.6172`), BCMath throughout, inside `DB::transaction()`, with
the division guarded by `bccomp($newQty,'0',3) > 0`; zero-cost receipt →
`0.0000`, no divide-by-zero and no NaN; a rejected receipt moves neither cost nor
quantity; `pending_qc` goods never enter `stock_levels` at all, so issuing from
pending-QC stock is structurally impossible; a rejected GRN is refused on all
four re-entry paths; `partial_accepted` moves only the accepted quantity; every
illegal status transition refused; all nine GRN endpoints (including `index` and
`options`) return 403 for a role with no inventory permission; no raw integer ids
anywhere in the GRN `show` payload.

## Questions for a human

1. **GRN-R10** — retire `/receive-goods` and its SPA client function, or grant one
   operator role both `inventory.grn.create` and `quality.inspections.manage`?
   The second re-opens the self-certification that `7dd5e50d` closed.
2. **GRN-R8** — should the GRN list/detail resolve an archived vendor/item
   `withTrashed()` (showing "Acme (archived)") or is `null` intended?
3. **GRN-R7** — widening `purchase_order_items.quantity_received` to
   `numeric(15,3)` is a Purchasing migration. Who owns it?
4. **GRN-03 (still open from 2026-08-24)** — `unit_cost` remains
   caller-controlled with no variance policy. GRN-R3 stops it being *malformed*;
   it does not decide who may override a PO price.
5. **Accounting decision #12** — `created_by` is nulled on source-linked journal
   entries, so the GRN's GL entry (`GrnGlPostingService` → `postSystem()`) has an
   inert maker-checker. Consequence for goods receiving: the JE that values a
   receipt records no maker. Not this module's decision.

## Could NOT verify

- **The AP bill quantity, at runtime.** `AutoCreateBillOnGrnAccepted` is a queued
  listener dispatched through the outbox, which does not run inline in the test
  harness — measured `bills` rows after both a partial and a full acceptance:
  **0**. Verified by reading instead: `BillService::createDraftForGrn()`
  (`api/app/Modules/Accounting/Services/BillService.php:330-333`) bills
  `$line->quantity_accepted` and skips lines at `<= 0`, and
  `assertBillProvenance()` (`:876-879`) refuses any stock bill whose GRN is not
  `Accepted`. So the contract reads correct; I did not execute it. AP is
  explicitly not mine to fix.
- **Two concurrent receipts on one PO line, at runtime.** `RefreshDatabase` hides
  uncommitted fixtures from a second connection, so a two-connection probe would
  have reported "no lock" as an artifact. Abandoned deliberately rather than
  reported. Verified statically instead: `create()` takes
  `PurchaseOrder … lockForUpdate()` (`GrnService.php:118`) and a per-line
  `PurchaseOrderItem … lockForUpdate()` (`:148`) before reading the running
  total, which is the correct serialization point.
- **A restore route.** There is none for a GRN and there should not be — the
  model has no `SoftDeletes`. Nothing to probe.
- **File attachments.** `coa_document_path` is a `string(500)` reference only;
  there is no upload endpoint, no MIME validation and no serving controller
  anywhere in the module. That is the still-deferred half of GRN-08, not a new
  finding.

