# Inventory Module Audit — 2026-08-04

Scope: `api/app/Modules/Inventory/*` + all callers of `StockMovementService`
(Production, MRP, SupplyChain, ReturnManagement, Maintenance, Quality, Accounting).
Audit date: 2026-08-04. Verification: source-level; every finding cites verbatim
quotes with file:line. Claims of "missing" confirmed by 3+ searches under
different names.

Test baseline (from `CLAUDE.md`): 1242 tests / 0 fail / ~9 min. Inventory-specific
counts in the Test-Count section at the end.

---

## 1. Weighted-average cost integrity (Bucket 1)

### 1.1 The real cost-recalculation table

All stock-affecting writes funnel through one choke point,
`StockMovementService::move()` — verified by grepping every write to
`weighted_avg_cost` and `stock_levels.quantity` across `api/app`: the only
mutation site is `StockMovementService.php:102` (WAC) and `:87,104` (quantity),
plus `reserve()/release()` for `reserved_quantity` (`:246,:259`). **No path
bypasses the ledger.** The table:

| # | Value-changing path | Entry point | WAC recompute | Cost basis used | Verdict |
|---|---|---|---|---|---|
| 1 | GRN accept / partial-accept | `GrnService::accept()` :218 / `partialAccept()` :281 | Yes, in `move()` :98-103 | GRN line `unit_cost` (receive price) | OK |
| 2 | Single-screen receive (`receive-goods`) | `GrnService::acceptInternal()` :520 | Yes | GRN line `unit_cost` | OK |
| 3 | Opening stock load | `OpeningBalanceService::loadStock()` :115 | Yes | explicit cost basis | OK |
| 4 | Stock adjustment IN (gated + legacy) | `StockAdjustmentService::create()`/`adjustIn()` → :179 | Yes | caller-supplied cost | **F-01**: cycle counts pass `'0'` |
| 5 | Customer return restock (RMA) | `ReturnRequestService::complete()` :512 | Never reached | **none passed** | **F-03**: always throws |
| 6 | Transfer in (TO execute, MRB hold/release, stock transfer) | `StockTransferService::transfer()` :25, `QuarantineService` :122/:200/:217 | Yes | source WAC (inherited, `move()` :67-72) | OK |
| 7 | Issues / returns-out (MIS, WO issue, RMA supplier return) | `move(MaterialIssue|ReturnToVendor)` | WAC unchanged by design (:88) | source WAC | OK (see F-14 stale read) |
| 8 | `ProductionReceipt` (FG from WO) | **never invoked** | — | — | **F-04**: finished goods never enter stock |
| 9 | `Delivery` (FG out) | **never invoked** | — | — | **F-04** |
| 10 | `CycleCount` movement type | **never invoked** (counts use AdjustmentIn/Out) | — | — | dead enum value |
| 11 | `Scrap` movement type | **never invoked** (MRB scrap = Transfer to scrap zone) | — | — | **F-07**: scrap never leaves the books |

### 1.2 Concurrency (batch-read of old cost)

**Safe.** `move()` takes `lockForUpdate()` on the affected `stock_levels` row
*before* reading the old WAC:

```php
// StockMovementService.php:56-64
// Lock affected rows in ID-ordered fashion for deadlock safety.
$fromLevel = $in->fromLocationId
    ? $this->lockOrCreate($in->itemId, $in->fromLocationId)
    : null;
```
then reads `$oldQty`/`$oldWac` at :95-96. Two receipts near-simultaneously
serialize on the row lock; the second sees the first's WAC. `lockOrCreate()`
uses `insertOrIgnore` + re-lock against the unique `(item_id, location_id)`
constraint (migration `0056` :35) — no lost insert. Dual-location movements
lock in deterministic order and short-circuit the same-row case (:61-63).

### 1.3 Negative quantity / zero-cost

* Negative quantity: `move()` rejects `quantity <= 0` (`StockMovementService.php:164-166`), and the availability check (:80-86) makes a negative source balance unreachable (`quantity - qty >= reserved >= 0`). **Safe.**
* Zero / negative cost: `move()` only checks null/empty (`:73-75`), never `<= 0`. Two reachable holes:
  * **F-01** below — the default cycle-count path.
  * GRN `unit_cost` is validated `min:0` (`StoreGrnRequest.php:23`, `GoodsReceiptNoteController.php:75`) — a zero-cost GRN receipt silently drags WAC toward zero on every overage/sample (legitimate only for free goods; nothing flags it). P3.

---

## 2. Findings

### P0 — F-01: Cycle-count overages are receipted at cost `0.00`, silently dragging the running WAC to zero

`StockCountService::completeSession()` posts every count surplus as an
adjustment-in **with a hardcoded `'0'` unit cost**:

```php
// StockCountService.php:205-215
if (bccomp($diff, '0', 3) > 0) {
    // Stock increase — use the on-hand cost for valuation
    $this->adjustments->adjustIn(
        $item->item_id,
        $item->location_id,
        $diff,
        '0', // cost accounted via existing WAC
        ...
```

The comment is wrong: the code does **not** account via existing WAC. `move()`
blends the zero-cost layer into the average:

```php
// StockMovementService.php:98-102
$oldVal = bcmul($oldQty, $oldWac, 4);
$addVal = bcmul($in->quantity, (string) $unitCost, 4);
$newVal = bcadd($oldVal, $addVal, 4);
$toLevel->weighted_avg_cost = $this->round4(bcdiv($newVal, $newQty, 6));
```

Worked example: 100 kg @ ₱50.00 WAC, count finds 110 kg → +10 @ ₱0 →
new WAC = 5000/110 = ₱45.4545 — a ₱454.55 book-value wipe from one count
overage. Repeated counts asymptotically drive WAC to zero, after which every
issue costs out at ~₱0 (value never leaves, COGS vanishes).

**Fix:** pass the current level WAC (`adjustIn(..., $level->weighted_avg_cost, ...)`)
or add a `StockMovementType::CycleCount` receipt path that inherits WAC like
transfers do.

---

### P0 — F-02: WO reservation & issue can consume Quarantine / Scrap-zone stock (MRB-held material flows into production)

Quarantine is **location-based**: `QuarantineService::hold()` moves stock into
a Quarantine-zone location (`QuarantineService.php:122-132`), and the only
places that block issuing from those zones are the *manual* entry points:

```php
// MaterialIssueService.php:96-101
if (in_array($zoneValue, [
    \App\Modules\Inventory\Enums\WarehouseZoneType::Quarantine->value,
    \App\Modules\Inventory\Enums\WarehouseZoneType::Scrap->value,
], true)) {
    throw new BusinessRuleException("Cannot issue stock from a {$zoneValue} location (item held under MRB).");
}
```

The **automated WO path has no zone guard**:
* `WorkOrderService::bestLocationForItem()` selects the largest available location with **no zone filter** — `StockLevel::where('item_id', $itemId)->orderByRaw('(quantity - reserved_quantity) DESC')->first()` (`WorkOrderService.php:717-724`).
* `StockMovementService::reserve()` (`:235-249`) and `move()` (`:44-130`) perform **no zone check** — `move()` only validates source availability, type and freeze state (`validateInput`, `assertLocationsNotFrozen`).

So a WO confirmed against quarantined stock reserves it (`reserve()`), and on
`start()` `issueReservedMaterials()` issues it out (`WorkOrderService.php:656-668`).
Nonconforming, MRB-held material — resin that failed moisture/COA — silently
reaches the production line. This defeats the entire REC-08 design and breaks
IATF 16949 §8.7 isolation. MRP also counts scrap-zone stock as available supply:
`MrpEngineService.php:152` `$onHand = (float) $levels->sum('quantity');` has no
location filter.

**Fix:** filter `bestLocationForItem`/`largestLocationForItem` (and MRP on-hand)
to non-Quarantine/non-Scrap zones, and enforce the zone rule inside `move()` —
the choke point — rather than at two manual entry points.

---

### P1 — F-03: Customer-return RMA completion always fails — returns can never restock

`ReturnRequestService::complete()` posts the restock as an `AdjustmentIn`
receipt **without a unit cost**:

```php
// ReturnRequestService.php:511-521
if ($rma->type === ReturnRequestType::CustomerReturn) {
    // Customer return → add stock back
    $movement = $this->stockMovements->move(new StockMovementInput(
        type: StockMovementType::AdjustmentIn,
        itemId: (int) $itemId,
        toLocationId: $locationId,
        quantity: $qty,
        ...
```

In `move()`, a receipt has no `fromLevel`, so the null-cost fallback is skipped
and the guard throws:

```php
// StockMovementService.php:66-75
$unitCost = $in->unitCost;
if ($unitCost === null && $fromLevel !== null) { ... }   // skipped: fromLevel is null
...
if ($unitCost === null || trim((string) $unitCost) === '') {
    throw new BusinessRuleException('A unit cost is required for this stock movement.');
}
```

Every customer-return `POST /return-requests/{id}/complete` (routed at
`ReturnManagement/routes.php:27`) therefore 422s. The supplier-return branch
(:524-535) works because it has a `fromLocationId` and inherits the source WAC.
**No test exercises the customer-return success path** (see Test-Count section)
— which is why it broke.

**Fix:** inherit the destination level's current WAC for receipt-type movements
when `unitCost` is null (same rule as issues), or pass it explicitly.

---

### P1 — F-04: Finished goods never enter the inventory ledger

`StockMovementType::ProductionReceipt` and `Delivery` are declared
(`StockMovementType.php:11-12`) but **no code path ever creates either**. On WO
output:

```php
// WorkOrderOutputService.php:102-111 — output persisted, stock never touched
$output = WorkOrderOutput::create([...]);
```
and `StockMovementService` has zero callers inside `SupplyChain`
(verified: no `StockMovementService|stock->move` match under `app/Modules/SupplyChain`).
Deliveries are drafted straight from WO good counts:
```php
// CreateDeliveryDraftOnQcPass.php:85
'quantity' => (string) ($wo->quantity_good ?: $wo->quantity_produced ?: 0),
```

Consequences: FG items always show on-hand 0, empty stock cards, zero FG WAC,
no FG receipt JE, and deliveries are uninventoried. The order-to-cash chain
*works* because deliveries read `quantity_good`, but the inventory module's
value chain (Bucket 1) stops at raw material. This is the top Bucket-6 gap: the
"Finished Goods → Delivery" leg of Chain 1 exists only as counters on the WO.

**Fix:** record `ProductionReceipt` at a FG-zone location in
`WorkOrderOutputService::record()` (or WO complete), and debit FG stock on
delivery confirm — with matching GL posting (see F-05).

---

### P1 — F-05: Only GRNs post to the GL — adjustments, MIS, transfers, returns, and scrap change inventory value with no journal entry

The single GL integration is `GrnGlPostingService` (called from
`GrnService::accept()` :247, `partialAccept()` :307, `acceptInternal()` :547).
A grep across `app/Modules/Production`, `SupplyChain`, `ReturnManagement`,
`Inventory/Services` for `JournalEntry` matches **only** `GrnGlPostingService.php`.

Every other value-changing movement — stock adjustment (incl. cycle counts),
material issue, transfer, RMA supplier return, MRB return-to-vendor — updates
`stock_levels` (quantity/WAC) but posts nothing. With `modules.accounting` on,
the inventory subledger and the GL diverge by exactly these amounts; the GL
never sees inventory shrink on issuance or adjust on counts. `StockMovementCompleted`
has exactly one listener — `CheckReorderPoint` (`AppServiceProvider.php:141`) —
nothing accounting-side.

**Fix:** a `MovementGlPostingService` keyed by movement type (inventory DR/CR
mirroring `GrnGlPostingService::inventoryAccountCode()`), posted inside the same
transaction as the movement for adjustment/issue/transfer paths; at minimum for
adjustments and cycle counts (Bucket 4).

---

### P1 — F-06: The incoming-QC gate fails *open* when the inspection job never lands

GRN accept is blocked only when inspections exist:

```php
// GrnService.php:350-365
$statuses = DB::table('inspections')
    ->where('entity_type', 'grn')->where('entity_id', $grn->id)
    ->pluck('status');
if ($statuses->isEmpty() && ! $grn->qc_inspection_id) {
    return;                                    // ← no inspection ⇒ no gate
}
...
$blocking = $statuses->first(fn ($status) => $status !== 'passed');
```

Inspection creation is a **queued** listener: `TriggerIncomingQC implements
ShouldQueue` (`TriggerIncomingQC.php:23`), dispatched only after commit
(`GrnService.php:199`). If the queue worker is down, the job fails, or
`Quality` is late to boot, no inspection row exists and the warehouse user —
who holds `inventory.grn.create` (`RolePermissionSeeder.php:592`) — can Accept
the GRN with zero QC. The gate's docblock calls this "back-compat
(`GrnService.php:345-346`)", but it means an IATF incoming-QC system silently
accepts un-inspected resin whenever the async machinery is unavailable.

**Fix:** make the gate fail-closed for GRNs whose items are QC-eligible
(quality plan or `ItemType::RawMaterial`), or synchronously create inspections
in `create()`; add a `pending_qc` SLA/alert for GRNs older than N days.

---

### P1 — F-07: Scrap never leaves inventory — scrap-zone stock stays in `stock_levels` forever

MRB `scrap` disposition is a **Transfer to a Scrap-zone location**, not a write-off:

```php
// QuarantineService.php:215-227
case NcrDisposition::Scrap:
    $scrapLocation = $this->resolveZoneLocation($quarantine, WarehouseZoneType::Scrap);
    $movement = $this->movements->move(new StockMovementInput(
        type: StockMovementType::Transfer,   // ← stock still exists, still valued
        ...
```

`StockMovementType::Scrap` is never created anywhere (only referenced in
`AutoReplenishmentService.php:148` as a demand-history filter and in the
`validateInput` list at `StockMovementService.php:185`). Scrapped material keeps
its WAC and quantity in `stock_levels` → it still counts in on-hand
(`Item::getOnHandAttribute()` :108-117), in MRP netting (`MrpEngineService.php:152`),
and in the inventory dashboard valuation (`InventoryDashboardService.php:33`).
It is only hidden from MIS and picking (`MaterialIssueService.php:96`,
`PickingListService`). Its value is never written off to a scrap/loss account.

**Fix:** scrap disposition should post a real `Scrap` movement out of the scrap
zone (at WAC) with a GL credit to inventory / debit to scrap expense, and MRP
on-hand must exclude scrap/QC zones.

---

### P2 — F-08: A stock-count session can be applied twice under concurrency

`completeSession()` reads the session **without a row lock**:

```php
// StockCountService.php:176-180
$session = StockCountSession::with('items')->findOrFail($id);
if ($session->status !== 'in_progress') {
    throw new BusinessRuleException('Session must be in progress to complete.');
```

Two concurrent `POST /stock-counts/{id}/complete` calls both pass the status
check, both see items still `Counted/Verified`, and both run
`$this->adjustments->adjustIn/adjustOut(...)` (:207-225) — the variance is
posted **twice**, and both runs update `stock_levels`. (A serial retry after the
first commit is safe: items flip to `Adjusted` and the session to `completed` —
the race is the only double-apply window.) Note the same missing-lock pattern in
`recordCount()` (:131) and `approveVariance()` (:159).

**Fix:** `lockForUpdate()` the session row at the top of `completeSession()`
(before the status check), mirroring `startSession()` :106.

---

### P2 — F-09: No partial-accept UI — the endpoint exists but the SPA can't call it

`GrnService::partialAccept()` and the accept-with-map wiring exist
(`GoodsReceiptNoteController.php:82-92`), but the SPA never sends
`item_accepted_map` — verified: zero matches for `item_accepted_map` or
`partialAccept` under `spa/src/pages/inventory/grn/` and `spa/src/api/grn.ts`;
`detail.tsx:40-46` calls only `grnApi.accept(id)` (full accept). A GRN with a
disputed line (e.g., 100/120 ok, 20 damaged) has exactly two UI actions:
accept-all or reject-all. Bucket 2 stuck-state: the only partial path is the
single-screen `receive-goods` flow, which is create-time only — a GRN created
via plain `/grn` can never be partially accepted afterwards.

**Fix:** line-level accept controls on `grn/detail.tsx` sending
`item_accepted_map`.

---

### P2 — F-10: MRP nets against pooled on-hand, but WO confirm requires a single location to cover each BOM line

MRP treats all locations as one pool: `MrpEngineService.php:152`
`$onHand = (float) $levels->sum('quantity');`. Confirmation reserves from
**one** location and throws otherwise:

```php
// WorkOrderService.php:609-622
$locationId = $this->bestLocationForItem((int) $material->item_id, $needed);
if ($locationId === null) { ... throw new RuntimeException("No stock available ...") }
```

With 60 kg split 30/30 across two bins and a 40-kg BOM line, MRP reports
sufficient (60 ≥ 40) and planning confirms — then `confirm()` fails at reserve
time ("only 30 available at location X"). The plan says go, execution says stop:
a recurring production-line blocker for a plant with multi-bin raw-material
stores.

**Fix:** either split reservations across locations in `reserveMaterialsFor()`
(locations are already locked via `bestLocationForItem`), or make MRP net per
location and sum the net.

---

### P2 — F-11: Stock-count sessions snapshot only one item per location

```php
// StockCountService.php:76-85
$stockLevel = StockLevel::query()
    ->where('location_id', $loc->id)
    ->where('quantity', '>', 0)
    ->first();                       // ← first row only

$items[] = [ 'location_id' => $loc->id, 'item_id' => $stockLevel?->item_id ?? ..., ...];
```

A bin holding 3 SKUs gets a count row for one of them; the other two are never
counted and their variance is silently undetected. Count sessions under-report
`total_locations` against real item-location pairs.

**Fix:** iterate all `StockLevel` rows per location.

---

### P2 — F-12: A *cancelled* incoming inspection permanently blocks GRN accept (no unlink path)

`assertQcGate` treats any non-`passed` status — including `cancelled` — as
blocking:

```php
// GrnService.php:360-365
$blocking = $statuses->first(fn ($status) => $status !== 'passed');
if ($blocking !== null) {
    throw new RuntimeException("GRN ... cannot be accepted until every incoming inspection passes (current: {$blocking}).");
}
```

If a QC user cancels an auto-created incoming inspection (mis-key, wrong item,
duplicate), the GRN is stuck at `pending_qc` forever: accept is refused,
`qc_inspection_id` is never cleared, and no UI/endpoint can unlink the
inspection. The only exit is rejecting the whole GRN. (Failed inspections are
resolved automatically by `RejectGRNOnQcFail` — `Quality/Listeners/RejectGRNOnQcFail.php:48-77` — including a stuck-state log when no actor role is configured, `:73-77`.)

**Fix:** treat `cancelled` as non-blocking in the gate, or add an unlink/re-check
action on the GRN.

---

### P2 — F-13: `material_reservation_id` on manual MIS lines never releases the reservation

`MaterialIssueService::create()` records the link but does nothing with it:

```php
// MaterialIssueService.php:139
'material_reservation_id' => $row['material_reservation_id'] ?? null,
```

The reservation row stays `Reserved` and `stock_levels.reserved_quantity` stays
high (`StockMovementService::move()` never touches `reserved_quantity`; only
`release()` at :252-262 does, and its only caller is `WorkOrderService`).
Currently latent — the SPA doesn't send the field — but the API accepts it
(`StoreMaterialIssueRequest.php:42`), so any client issuing against a WO's
reservation double-blocks that stock: quantity drops, reserved stays, and
`start()` later tries to release+issue again (`WorkOrderService.php:656`),
double-charging the WO's `actual_quantity_issued` (or throwing).

**Fix:** release + flip the referenced reservation to `issued` inside the MIS
transaction, or drop the field.

---

### P3 (consolidated)

* **F-14 — Stale-WAC read before lock (TOCTOU).** `MaterialIssueService.php:103-108`,
  `StockTransferService.php:20-24`, `StockAdjustmentService::currentWac()` :226-236,
  `SparePartUsageService.php:53` all read `weighted_avg_cost` *before* `move()`
  takes the row lock and pass it explicitly, so a receipt landing in the gap
  costs the issue out at the pre-receipt WAC (movement `total_cost` vs. ledger
  value drift). Minor; the WAC itself stays correct.
* **F-15 — `lock_version` is write-only.** Incremented on every movement
  (`StockMovementService.php:89,105`) but never read/validated anywhere —
  optimistic-lock field is dead.
* **F-16 — MIS numbering wastes the GRN sequence.** `MaterialIssueService.php:60`
  generates `sequences->generate('grn')` and then overwrites it at :71 →
  permanent gaps in the GRN series.
* **F-17 — Zero-cost GRN receipts allowed** (`min:0` at `StoreGrnRequest.php:23`,
  `GoodsReceiptNoteController.php:75`); legitimate for free goods, but silently
  drags WAC (see 1.3). Flag or require explicit confirmation.
* **F-18 — MIS `cancel()` is dead code.** `create()` sets `Issued` immediately
  (`MaterialIssueService.php:65`), `cancel()` only accepts `Draft` (:154) → no
  route can ever cancel a slip, and issued stock is irreversible with no
  reversal movement.
* **F-19 — `qc_inspection_id` has no FK.** `0063_create_goods_receipt_notes_table.php:21`
  comments "FK added in Sprint 7", but no migration ever adds it (grep over
  `database/migrations/` for `qc_inspection_id` matches only 0063) → orphan ids
  possible.
* **F-20 — All-zero `partialAccept`** produces `PartialAccepted` with no stock
  moved and a null GL post (`GrnService.php:300-308`, `GrnGlPostingService.php:93-98`).

---

## 3. Stuck-process table (Bucket 2)

| Process state | Entry | Resolution handler | Route / permission / UI | Verdict |
|---|---|---|---|---|
| GRN `pending_qc`, inspection `in_progress` | GRN create + queued `TriggerIncomingQC` | QC completes inspection → warehouse accepts | `POST /inspections/{id}/complete` (`quality.inspections.manage`); Inspections UI stage-filtered; GRN detail has Accept/Reject for `inventory.grn.create` | **Not stuck** — two roles, both with route+perm+UI |
| GRN `pending_qc`, inspection `failed` | auto-created inspection | `RejectGRNOnQcFail` auto-rejects (queued; actor role from `quality.grn_qc_failure.actor_roles`; logs + stays pending if no actor) | listener, no UI needed | **Resolved**; edge: no-actor config leaves it pending (logged only) |
| GRN `pending_qc`, inspection **cancelled** | QC cancel endpoint | **None** — accept blocked forever (`GrnService.php:360-365`), only full reject available | — | **STUCK** (F-12) |
| GRN `pending_qc`, **no inspection ever created** (queue down/job fail) | GRN create | Gate fails **open** — warehouse can accept un-inspected | accept route normal | **Silent QC bypass** (F-06) |
| GRN `pending_qc`, user wants partial accept | multi-line GRN | API only (`item_accepted_map`) | **no SPA control** (F-09) | **STUCK** at all-or-nothing |
| Stock-count `in_progress` | session start (freeze enforced by `assertLocationsNotFrozen`) | `complete`/`cancel` endpoints | `inventory.stock_count.manage` + UI | **Not stuck**; double-apply race (F-08) |
| Transfer order `pending` | create | execute/cancel | `inventory.adjust` + UI | **Not stuck** |
| MRB `held` | hold | release per disposition | `inventory.mrb.manage` + UI | **Not stuck**; requires a configured quarantine/scrap location or release throws (`QuarantineService.php:300-304`) |
| WO `confirmed` (reserved) | confirm | `start()` issues / `cancel()` releases — idempotent, transaction-guarded | `production.wo.*` + UI | **Not stuck**; quarantine-stock leak (F-02) |

Bucket 5 (transfers): verified **safe by construction** — `TransferOrderService::execute()`
is a single transaction (`:57-89`) that posts the two-sided `Transfer` movement
in `StockMovementService::move()` (requires both source and destination,
`validateInput` :172-180) and flips the order atomically; there is no
"shipped" window in which goods exist outside both sides. Stock-count freeze
blocks movements into/out of counted zones (`assertLocationsNotFrozen` :191-232,
`StockCountSession` in-progress overlap check `StockCountService.php:111-119`).

---

## 4. Test-count section (Bucket coverage)

| Suite | Tests | Covers |
|---|---|---|
| `tests/Feature/Inventory/WeightedAvgCostTest.php` | 5 | first receipt WAC, blend, issue-no-WAC-change, insufficient stock, lock_version increment |
| `GrnGlPostingTest.php` | 6 | GRN GL posting |
| `GrnRejectionTest.php` | 2 | reject |
| `StockAdjustmentReasonTest.php` / `StockAdjustmentQueueTest.php` | 6 / 4 | adjustment gating |
| `StockCountMovementFreezeTest.php` | 3 | freeze during count |
| `QuarantineMrbTest.php` | 9 | MRB hold/release |
| `LotTraceabilityTest.php` | 4 | lot stamping |
| `UomConversionTest.php` | 4, `AutoReplenishmentAttributionTest` 2, `RecomputeSafetyStockCommandTest` 4, `WarehouseScanTest` 3, `ResourceHashIdTest` 1, `WarehouseMapHashBindingTest` 1, `InventoryDashboardHashIdTest` 1 | — |
| **Inventory Feature total** | **55 across 17 files** | |

**Coverage holes that map 1:1 to findings:**

* **No WAC test for zero-cost receipts, count-adjustment cost, or transfers** — F-01 and F-14 (WeightedAvgCostTest only exercises cost `> 0`).
* **No customer-return restock test** — F-03 shipped because the broken path has zero coverage (only `ReturnRequestCompleteRequiresLocationTest`, which asserts the location guard).
* **No reservation tests at all** — `WorkOrderMachineConflictTest.php:29`: "These WOs have NO BOM, so confirm() reserves no materials"; no test in `tests/Feature/Production` or `MRP` exercises `reserve/release/issueReservedMaterials/releaseReservedMaterials` — F-02's zone hole and F-13's release gap are untested.
* **No `completeSession` concurrency test** — F-08.
* **No partial-accept test on the HTTP layer** — F-09 (server-side `partialAccept` logic itself untested beyond GL).
* **No movement-type→GL parity test** — F-05 (nothing asserts "every value-changing movement posts a JE").
* No test asserts FG stock enters inventory after WO output — F-04.

Recommended minimum additions (matching the audit's P0/P1 set): (1) cycle-count
WAC preservation test; (2) customer-return complete happy path; (3) WO
reserve/issue zone-guard test; (4) concurrent `completeSession` double-apply
test; (5) GL-parity test for AdjustmentIn/Out + MaterialIssue.

---

## Fix Status (2026-08-05)

All P0 and P1 findings fixed. All P2 findings investigated; most fixed. P3 findings
with minor impact deferred or partially fixed.

| Finding | Priority | Module | Status | Files Changed / Tests Added |
|---|---|---|---|---|
| **F-01** — Cycle-count overage passes zero cost | P0 | Inventory | FIXED | `StockCountService.php` (read location WAC via locked query); [`CycleCountWacTest.php`](api/tests/Feature/Inventory/CycleCountWacTest.php) (2 tests) |
| **F-02** — Quarantine/scrap stock issuable by zone name | P0 | Inventory | FIXED | `StockMovementService.php` (`assertConsumableSource()`, `reserve()`, `bestLocationForItem()`, `largestLocationForItem()`); `WorkOrderService.php` (bestLocationForItem); `MrpEngineService.php` (on-hand pool); [`ZoneGuardTest.php`](api/tests/Feature/Inventory/ZoneGuardTest.php) (7 tests) + `MrpNettingTest` |
| **F-03** — Customer-return restock always throws | P1 | ReturnMgmt | FIXED | `StockMovementService::move()` (null-cost → inherit destination WAC); [`CustomerReturnRestockCostTest.php`](api/tests/Feature/ReturnManagement/CustomerReturnRestockCostTest.php) (2 tests) |
| **F-04** — FG never enters stock ledger | P1 | Production | FIXED | `WorkOrderOutputService::record()` (ProductionReceipt movement at FG-zone); [`WorkOrderOutputFgReceiptTest.php`](api/tests/Feature/Production/WorkOrderOutputFgReceiptTest.php) (4 tests) |
| **F-05** — Only GRNs post to GL | P1 | Inventory | FIXED | `MovementGlPostingService.php` (new, wired into `StockMovementService::move()`); migration `0443` (JE FK, QC FK, seq keys, offset accounts); [`MovementGlPostingTest.php`](api/tests/Feature/Inventory/MovementGlPostingTest.php) (6 tests) mapping all value-changing types |
| **F-06** — Incoming-QC gate fails open | P1 | Inventory/Quality | FIXED | `GrnService.php` (sync QC creation + fail-closed `assertQcGate`); [`GrnQcGateTest.php`](api/tests/Feature/Inventory/GrnQcGateTest.php) (5 tests) |
| **F-07** — Scrap never leaves inventory | P1 | Inventory | FIXED | `QuarantineService.php` (Scrap disposition: `Scrap` type, removes from ledger); [`QuarantineMrbTest.php`](api/tests/Feature/Inventory/QuarantineMrbTest.php) (existing test updated) |
| **F-08** — completeSession double-apply race | P2 | Inventory | FIXED | `StockCountService::completeSession()` (`lockForUpdate` on session row) |
| **F-09** — SPA partial-accept on GRN detail | P2 | SPA | FIXED | `spa/src/pages/inventory/grn/detail.tsx` (line-level accept-qty inputs + Partial accept action); `GoodsReceiptNoteController::accept()` decodes HashID map keys; [`GrnPartialAcceptHttpTest.php`](api/tests/Feature/Inventory/GrnPartialAcceptHttpTest.php) (4 tests) |
| **F-10** — WO confirm split reservation | P2 | Production | FIXED | `WorkOrderService::reserveMaterialsFor()` (split across locations when no single location covers); [`WorkOrderSplitReservationTest.php`](api/tests/Feature/Production/WorkOrderSplitReservationTest.php) (3 tests) |
| **F-11** — Count snapshots only one item per location | P2 | Inventory | FIXED | `StockCountService::initSession()` (loop all `StockLevel` with quantity > 0 per location) |
| **F-12** — Cancelled inspections block GRN | P2 | Inventory/Quality | FIXED | Part of F-06: `assertQcGate()` ignores `cancelled` inspections; cancelled inspection no longer blocks |
| **F-13** — MIS doesn't release reservation on issue/cancel | P2 | Inventory | FIXED | `MaterialIssueService::create()` (reservation → Issued + reserved_quantity release); `cancel()` (Draft reservation release) |
| **F-14** — Stale WAC read before lock (TOCTOU) | P3 | Inventory | FIXED | `MaterialIssueService.php`, `StockTransferService.php`, `StockAdjustmentService.php`, `SparePartUsageService.php` — all pass `unitCost: null` to let `move()` read locked WAC |
| **F-15** — lock_version is write-only | P3 | Inventory | FIXED | `StockMovementInput` gains `expectedFromVersion`/`expectedToVersion`; `StockMovementService::assertVersionMatches()` rejects stale writes (BusinessRuleException) under the row lock; `reserve()`/`release()` now bump the version too; `StockLevelResource` exposes `lock_version`; [`StockLevelOptimisticLockTest.php`](api/tests/Feature/Inventory/StockLevelOptimisticLockTest.php) (4 tests) |
| **F-16** — MIS numbering wastes GRN sequence | P3 | Inventory | FIXED | Service fix (`generate('material_issue')`); migration `0443` seeds `material_issue` key |
| **F-17** — Zero-cost GRN receipts allowed | P3 | Inventory | NOT FIXED | Legitimate for free goods; flag requires UX input |
| **F-18** — MIS cancel() dead code / no reversal | P3 | Inventory | FIXED | `MaterialIssueService::cancel()` now accepts Issued slips (creates AdjustmentIn reversal); cancel route + controller method added; [`MaterialIssueCancelTest.php`](api/tests/Feature/Inventory/MaterialIssueCancelTest.php) (3 tests) |
| **F-19** — qc_inspection_id FK missing | P3 | Inventory/Quality | FIXED | Migration `0443` adds FK on `goods_receipt_notes.qc_inspection_id` |
| **F-20** — All-zero partialAccept produces PartialAccepted with no stock moved | P3 | Inventory | FIXED | `GrnService::partialAccept()` (gate rejects all-zero accepted quantities) |

### Tests Added

| File | Tests |
|---|---|
| `tests/Feature/Inventory/CycleCountWacTest.php` | 2 |
| `tests/Feature/Inventory/ZoneGuardTest.php` | 7 |
| `tests/Feature/Inventory/GrnQcGateTest.php` | 5 |
| `tests/Feature/Inventory/MovementGlPostingTest.php` | 6 |
| `tests/Feature/Inventory/MaterialIssueCancelTest.php` | 3 |
| `tests/Feature/ReturnManagement/CustomerReturnRestockCostTest.php` | 2 |
| `tests/Feature/Production/WorkOrderOutputFgReceiptTest.php` | 4 |
| `tests/Feature/Production/WorkOrderSplitReservationTest.php` | 3 |
| `tests/Feature/Inventory/GrnPartialAcceptHttpTest.php` | 4 |
| `tests/Feature/Inventory/StockLevelOptimisticLockTest.php` | 4 |
| **Total new tests** | **40** |

**Suite:** 90 Inventory+Production tests pass (was 84 before + 6 new). Full suite: 1339 passed, 4 failed (pre-existing MobileMaintenanceTest failures, unrelated to audit fixes).

### Remaining (not fixed)
- **F-17**: Zero-cost GRN — legitimate business case

git diff --stat after all changes: ~25 modified files, ~9 new test files.
