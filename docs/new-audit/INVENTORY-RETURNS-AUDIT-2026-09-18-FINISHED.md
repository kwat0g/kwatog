# Inventory + Return Management Audit

Date: 2026-09-18
 
## Re-audit 2026-09-23

Current verdict: **FINISHED (remediated or explicitly dispositioned)**.

- Inventory: search imports, stock-card transfer/WAC arithmetic, zone and location reclassification, inactive-location use, item/parent restore binding, count snapshot/completion controls, decimal read paths, incoming-QC fractional handling, and GRN/material-issue idempotency are fixed and regression-tested.
- Return Management: product/item identity, duplicate NCR/disposal effects, finance-only approval, source-state checks, source-lot equality, supplier bill provenance, replacement VAT, and per-line MRB movement traceability are fixed and regression-tested.
- Explicit policy dispositions: single-screen fractional receiving remains fail-closed and requires line-level QC; a credit against a fully-paid supplier bill is finalized and remains unapplied until Finance records a vendor refund or later payable offset.
- Full current classification: `RE-AUDIT-REGISTER-2026-09-19.md`.
Scope: `api/app/Modules/Inventory/` (stock ledger, locations/zones, transfers, adjustments,
counts, material issues, picking, barcode, quarantine/MRB, GL, item master, receiving) and
`api/app/Modules/ReturnManagement/` (RMA).
Claims are marked **[confirmed]** (file:line, seed, or code read) or **[assumption/unverified]**.

---

## 1. Executive summary

Inventory is a real double-entry-style stock ledger: every stock mutation funnels through
`StockMovementService::move()`, which locks `(item, location)` rows, enforces availability,
recomputes weighted-average cost, writes `stock_movements`, and posts GL in the same
transaction. Zones quarantine/scrap are excluded from consumption. The re-audit remediated
the historical search/import and stock-card defects, then closed the remaining inventory
integrity gaps around location lifecycle, counts, decimals, QC, lots, and retries.

Return Management is a full RMA subsystem (customer + supplier) with a state machine, a
two-step regular approval chain (plus a finance-only workflow), a Quality handoff, credit
notes, and supplier replacement POs. The
re-audit closed the cross-party source boundary, VAT inheritance, finance-only workflow,
duplicate-disposal/NCR, lot, bill-line, and MRB movement-linkage risks. Fully-paid supplier
bills remain an explicit unapplied-credit settlement workflow rather than being mutated after
payment.

---

## 2. Flow diagram

```mermaid
flowchart TD
    subgraph Ledger["Inventory ledger (linchpin)"]
      MOVE["StockMovementService::move()<br/>lock levels -> availability -> WAC -> movement -> GL"]
      LVL["stock_levels (quantity, reserved_quantity, weighted_avg_cost)"]
      RES["reserve()/release() (no movement, no GL)"]
      MOVE --> LVL
      RES --> LVL
    end
    GRN["GrnService (receipt -> pending_qc)"] -->|"GrnReceipt"| MOVE
    MIS["MaterialIssueService"] -->|"MaterialIssue"| MOVE
    PROD["WorkOrderOutputService"] -->|"ProductionReceipt"| MOVE
    DEL["DeliveryService"] -->|"Delivery (out)"| MOVE
    QTN["QuarantineService (MRB hold/release)"] -->|"Transfer/Scrap/ReturnToVendor"| MOVE
    XFER["TransferOrderService -> StockTransferService"] -->|"Transfer"| MOVE
    ADJ["StockAdjustmentService"] -->|"AdjustmentIn/Out"| MOVE
    CNT["StockCountService"] -->|"AdjustmentIn/Out"| ADJ
    MOVE --> OUT["outbox StockMovementCompleted"]
    OUT --> RP["CheckReorderPoint -> AutoReplenishmentService (draft PR / critical auto-PO)"]
    OUT --> MRP["QueueMrpOnStockMovementCompleted -> replan SOs"]
    MOVE --> GL["MovementGlPostingService (Dr/Cr inventory vs consumption/adjustment/GRNI)"]

    subgraph QC["Incoming QC"]
      GRN --> TIQ["TriggerIncomingQC -> inspections"]
      TIQ --> PASS{"inspection outcome"}
      PASS -- pass --> ACC["AcceptGrnOnIncomingQcPass -> GrnService::accept -> GL + bill"]
      PASS -- fail --> REJ["RejectGRNOnQcFail -> GrnService::reject -> supplier RMA"]
    end

    subgraph RMA["Return Management"]
      RREQ["ReturnRequestService::create (reserves source allocation)"] --> SUB["submit -> approval chain dept_head -> production_manager"]
      SUB --> REC["receive (customer lines -> AdjustmentIn to quarantine)"]
      REC --> INS["inspect -> Quality return inspection"]
      INS --> DIS["dispose -> NCR + credit note / replacement PO"]
      DIS --> COMP["complete -> move stock"]
    end
    REJ --> RREQ
    QTN --> RREQ
    NCR["NcrService return_to_supplier"] --> RREQ
    RREQ --> CN["CreditNoteService (customer draft / supplier finalize+apply)"]
```

---

## 3. Original walkthrough (2026-09-18)

This section records the source trace at the time of the original audit. Where it differs from
the current implementation, §7 and the 2026-09-23 re-audit verdict take precedence.

### 3.1 The ledger — `StockMovementService::move()`

Every stock change funnels through `move()` (`StockMovementService.php:53`):
1. `validateInput` (positive qty; receipts need a destination, issues a source; Transfer needs
   two distinct locations) and `SourceReferenceRegistry::assertValid`.
2. Transaction with **3 deadlock retries**; count-freeze guard unless `bypassCountFreeze`.
3. `lockAffectedLevels()` locks both `(item,location)` rows sorted by location id;
   `insertOrIgnore` creates a missing level (`stock_levels_item_loc_unique`).
4. Optimistic-lock check on `lock_version` when the caller supplies an expected version.
5. Unit cost: explicit → source WAC → destination WAC (`0.00` if fresh).
6. Issue side: availability = `quantity − reserved_quantity`; shortfall throws
   `InsufficientStockException`; source WAC unchanged.
7. Receipt side: recompute WAC (`round4((oldQty*oldWac + qty*unitCost)/newQty)`).
8. Insert `stock_movements`, record `StockMovementCompleted` via the outbox, then
   `MovementGlPostingService::postFor()` in the same transaction; a `manual_required` GL
   handoff stages a replay event.

`reserve()`/`release()` only mutate `reserved_quantity` (no movement, no GL), reject
non-positive magnitudes, exclude quarantine/scrap, and assert the location is not frozen.
**Remediated:** `StockLevel`, `Item`, `ItemResource`, dashboard, replenishment, and stock-card
read paths now preserve decimal quantities and costs with BC Math/string serialization; the
ledger and read models share the same precision contract.

Movement types (`StockMovementType.php:9-20`): `grn_receipt`, `material_issue`,
`production_receipt`, `delivery`, `transfer`, `adjustment_in`, `adjustment_out`, `scrap`,
`return_to_vendor`, `cycle_count`, `opening`. `isIssue()` is dead; `CycleCount` and `Opening`
have no service producer (Opening only seeded).

### 3.2 Locations and zones

`WarehouseZoneType`: `raw_materials|staging|finished_goods|spare_parts|quarantine|scrap`.
Quarantine/scrap cannot satisfy `MaterialIssue`/`Delivery` (`assertConsumableSource`), cannot
be reserved, cannot be a picking source, and cannot be a receiving target. Adjustment/scrap/
return-to-vendor/transfer may touch them (MRB mechanics). **`is_blocked` is never written by
any code and is not fillable**, so the receiving blocked-location guard is dead; the legacy
`warehouse_locations.current_*` projection has no writer.

### 3.3 Transfers, adjustments, counts

- `TransferOrderService`: create (`Pending`) → execute (`Transferred`, one `Transfer`
  movement) → cancel; no approval, route permission `inventory.adjust`. `StockTransferService`
  is a thin wrapper; the old `/stock-transfers` route and controller are commented out.
- `StockAdjustmentService`: `create()` posts immediately when below
  `inventory.adjustment_approval_threshold` (default 0 = gate disabled), else saves `Pending`
  for `approve()`; `approve()` requires `inventory.adjust.approve`; count reconciliation uses
  current WAC with `bypassCountFreeze`.
- `StockCountService`: `Draft → InProgress → Completed`/`Cancelled`; start freezes locations
  and blocks overlapping sessions; `completeSession` gates on
  `inventory.stock_count.variance_tolerance_pct` (over-tolerance requires the item be
  `Verified` via `approveVariance`), then auto-reconciles variances through adjustments.
  **`approveVariance` and `completeSession` share `inventory.stock_count.manage`** — the
  maker can verify their own count.

### 3.4 Operational services

- `MaterialIssueService`: issue slips, multi-UOM, quarantine/scrap block, releases the
  reservation before issuing; `cancel()` reverses issued lines.
- `PickingListService`: FEFO/FIFO suggestions excluding quarantine/scrap; `generateForMis`
  wired, `generateForWorkOrder` is test-only ("future use").
- `QuarantineService` (MRB): hold requires a failed inspection + related NCR, moves stock to
  a quarantine location; release by disposition (`rework|use_as_is` → Transfer, `scrap` →
  Scrap, `return_to_supplier` → ReturnToVendor + opens a supplier RMA). The scrap branch
  discards the resolved scrap location (validation by side-effect; no destination recorded).
- `BarcodeScanResolverService`: `WO-`/`PO-`/`GRN-` prefixes → typed entity + actions.
- `SafetyStockRecomputeService`: `SS = Z·σ·√lead_time`, nightly.
- `AbcClassificationService`: no route reaches it (`ItemController::recomputeAbc` unrouted) — dead.
- `InventoryDashboardService`: 30 s cache but N+1 across active items.

### 3.5 GL

`MovementGlPostingService` maps AdjustmentIn/CycleCount (Dr inventory / Cr adjustment),
AdjustmentOut (reverse), MaterialIssue/Scrap (Cr inventory / Dr consumption), ReturnToVendor
(Cr inventory / Dr GRNI), ProductionReceipt (reverse consumption); GrnReceipt/Transfer/
Opening/Delivery are non-GL here. Idempotent via `stock_movements.journal_entry_id`; failures
leave a replayable `manual_required` handoff. `GrnGlPostingService` posts cumulative
Dr inventory-item-type / Cr GRNI. **Gate inconsistency confirmed:** GRN uses
`requiredBool('modules.accounting')` (throws, rolling back the receipt) while the movement
and payroll services use `get('modules.accounting', false)` (silently skip).

### 3.6 Events

`StockMovementCompleted` (outbox) → `CheckReorderPoint` (may raise a reorder PR or, for
critical items, a bypass auto-PO) and `QueueMrpOnStockMovementCompleted` (replan affected
SOs). GRN → `TriggerIncomingQC`; `InspectionPassed` → `AcceptGrnOnIncomingQcPass` and
`VerifyCoaOnIncomingQcPass`; `InspectionFailed` → `RejectGRNOnQcFail`;
`StockMovementGlPostingRequested` → queued retry. All wired explicitly in
`AppServiceProvider.php:294-359`.

### 3.7 Item master

`Item` carries type, UOM, standard cost, reorder method/point, safety stock, MOQ, lead time,
`is_critical`, `abc_class`. **No per-item valuation method** — weighted average is global and
implicit. `item_type` and `unit_of_measure` are frozen once movements exist. Finished-goods
items link to CRM `Product` only by convention (`items.code == products.part_number`,
`item_type=finished_good`); no FK.

### 3.8 Return Management

State machine: `draft → pending_approval → approved → received → inspected → completed`,
with `rejected`/`cancelled` side exits and one recovery edge `inspected → received`.
Approval chain `return_request`: `department_head → production_manager` (`WorkflowSeeder.php:180-187`,
active). Lines reserve a source allocation (invoice/SO/delivery/GRN) capped by that source's
quantity. `receive()` records physical return; **customer item-backed lines** are moved into a
quarantine location (`AdjustmentIn`); supplier lines move nothing. `inspect()` stages Quality
inspections per product; `dispose()` auto-creates NCRs for scrap/rework and creates credit
notes (customer = **draft**; supplier = finalized + applied to the open bill) plus an optional
replacement **PO** (no replacement work order). `complete()` moves any remaining lines.
System entry `openSupplierReturnForReversedGoods()` is shared by GRN rejection, NCR
`return_to_supplier`, and MRB release, using `reversal_already_applied` and per-line
`stock_movement_quantity` to prevent double quantity-reversal/movement.

---

## 4. Branch points

| # | Where | Condition | Paths |
|---|---|---|---|
| B1 | `move()` | issue vs receipt (from/to location) | decrement+WAC unchanged / increment+WAC recompute |
| B2 | `move()` | destination WAC null | cost 0.00 / inherit destination WAC |
| B3 | `move()` | `lock_version` mismatch | refuse / proceed |
| B4 | `move()` | source in quarantine/scrap for issue/delivery | block / allow |
| B5 | `reserve()` | zone quarantine/scrap | block / reserve |
| B6 | `assertLocationsNotFrozen` | count session in progress | freeze / move |
| B7 | Adjustment `create` | value > threshold | `Pending` (no movement) / post immediately |
| B8 | `completeSession` | over-tolerance & not Verified | abort session / reconcile |
| B9 | GRN accept | QC gate all passed/cancelled | accept+GL+bill / refuse |
| B10 | GRN reject | — | reverse PO + supplier RMA / (throw rolls back) |
| B11 | `TriggerIncomingQC` | quality plan / raw-material item | plan inspection / fallback / skip (no QC) |
| B12 | Quarantine release | disposition | Transfer / Scrap / ReturnToVendor+RMA |
| B13 | RMA `receive` | customer item-backed vs supplier | quarantine AdjustmentIn / no movement |
| B14 | RMA `inspect` | Quality handoff fails | `manual_required` + retry event / inspected |
| B15 | RMA `dispose` | disposition matrix | NCR / credit note / replacement PO |
| B16 | supplier bill already paid | credit remains linked, finalized and unapplied | Finance refund / later payable offset |

---

## 5. Permission gates

| Action | Permission |
|---|---|
| Items/categories/UOM mutate | `inventory.items.manage`; view `inventory.view` |
| Warehouse/zones/locations | `inventory.warehouse.manage` |
| Adjustments create / approve | `inventory.adjust` / `inventory.adjust.approve` |
| Transfer orders | `inventory.adjust` |
| Stock counts view / manage | `inventory.stock_count.view` / `.manage`; distinct-user checks govern variance approval and completion |
| Picking list | `inventory.picking.view` |
| GRN create/finalize/accept/reject | `inventory.grn.create` |
| Material issues | `inventory.issue.create` |
| MRB view / manage | `inventory.mrb.view` / `.manage` |
| GL retry | `accounting.journal.post` |
| RMA view/manage/approve/receive/inspect/dispose/complete | `return_management.*`; regular and finance-only RMAs use their respective approval chains |

---

## 6. Glossary

- **WAC** — weighted average cost per `(item, location)`, recomputed only on receipts.
- **reserved_quantity** — earmarked stock, no movement/GL; availability gates on it.
- **Count freeze** — an in-progress stock count blocks movements at its locations.
- **MRB / quarantine** — held stock excluded from consumption; released by disposition.
- **`reversal_already_applied`** — supplier-RMA flag preventing double quantity reversal.
- **`stock_movement_quantity`** — per-line idempotency stamp for RMA stock movements.
- **`stock_movement_id`** — per-line link to the exact stock movement, including MRB-opened supplier RMAs.
- **Source allocation** — RMA line reservation against exactly one invoice/SO/delivery/GRN line.

---

## 7. Remediation and dispositions

1. **Closed — search and stock-card defects.** Both list services import the shared search
   operator. Unfiltered transfers are rendered as net-zero and stock-card WAC reconstruction
   uses the same BC Math precision as the ledger, with regression coverage.
2. **Closed — location integrity.** Zone reclassification and location reassignment are
   blocked while stock or reservations remain. All movement sources and destinations require
   active location/warehouse topology, and blocked receiving targets fail closed.
3. **Closed — restore topology.** Item, warehouse, zone, and location restore routes resolve
   soft-deleted records correctly; child restores require an active, restored parent chain.
4. **Closed — count controls.** Starting a count refreshes its location/item snapshot under
   locks, incomplete lines block completion, and both variance approval and final completion
   require a distinct checker.
5. **Closed — decimal arithmetic.** Stock-level accessors, item resources, dashboard values,
   replenishment quantities, and stock-card reads preserve decimal strings and use BC Math for
   comparisons and calculations.
6. **Closed — incoming QC.** Fractional quantities are ceiling-counted in asynchronous
   line-level inspections instead of truncated. The legacy single-screen integer contract
   rejects fractional eligible receipts rather than accepting them without valid QC evidence.
7. **Closed — receiving and issue retries.** GRN and material-issue idempotency keys are
   operation-scoped rather than actor-scoped, fingerprints exclude the authenticated actor,
   and duplicate side effects are covered by replay tests.
8. **Closed — RMA boundaries.** Product/item mapping, source status, party ownership, source
   lot equality, bill/PO/GRN provenance, replacement VAT inheritance, duplicate disposal/NCR
   prevention, and ordinary per-line movement linkage are enforced in the service boundary.
9. **Closed — MRB traceability.** System-opened supplier RMAs now retain the exact MRB release
   movement ID on each line, while the existing quantity stamp prevents a second physical move.
10. **Closed — finance-only workflow.** Finance has a dedicated approval workflow and the
    permissions needed to open and act on finance-only RMAs.
11. **Explicit policy — paid supplier bills.** A supplier credit against a fully-paid bill is
    finalized and remains unapplied. `CreditNoteService` correctly refuses applying it to a
    paid bill; Finance must record a vendor refund or offset it against a later payable.
12. **Accepted cleanup debt.** Dead/unwired movement types and legacy aliases remain cleanup
    candidates, but do not create an inventory, accounting, quality, or authorization failure.
    The deliberately broad RMA row visibility is also retained as a documented policy choice.

---

## 8. Assumptions vs confirmed facts

**Confirmed from code/seed/tests:** the `move()` contract and movement types; zone and
location restrictions; transfer/adjustment/count lifecycles; the quarantine/MRB disposition
mechanics; GL mappings and feature gating; event wiring; the return state machine, approval
chain, allocation/reservation and disposition behavior; the remediation items in §7; and the
focused Inventory, Quality, and Return Management regression suites.

**Assumptions / not verified:**

- A1. The focused Inventory/RMA/Quality regression set passed: 239 tests, 888 assertions.
  Full-suite attempts did not complete cleanly: PostgreSQL schema setup/teardown reported
  deadlocks and duplicate/missing-table errors; one run also failed the existing
  `CarbonDiffSignConventionTest` in `WoOperationService`. The full suite is not claimed green.
- A2. The SPA inventory screens were not inspected.
- A3. Whether `inventory.adjustment_approval_threshold`, `over_receipt_tolerance_pct`,
  variance tolerance and `modules.accounting` are set in every environment determines which
  branches fire; no live settings were read.
- A4. The exact `Quality` NCR/CoC behavior is only summarized here (its own audit is queued).
- A5. WAC correctness was verified with a large-value worked fixture covering receipt, WAC
  rounding, issue, and closing stock-card value.
