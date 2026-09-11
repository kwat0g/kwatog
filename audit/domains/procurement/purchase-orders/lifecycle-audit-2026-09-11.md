# PO ↔ Supplier lifecycle audit — 2026-09-11

Scope: `purchase_orders.status` state machine, supplier-portal capabilities, GRN feedback,
three-way match / billing, dispatch states, dashboards/alerts, notifications, scorecard.
Read-only audit. Findings are grouped and numbered; severity is HIGH (incorrect money/goods
state or a user-visible dead end), MEDIUM (misleading UI or missing guard), LOW (hygiene).

Drivers reported by the user:
- "Acknowledge PO" flips the PO to **Sent** — a status that should mean *OGAMI transmitted it*.
- The supplier can **Submit Invoice** while the PO is only **Sent** (no accepted receipt).
- There is no way to set the **required delivery date** when creating a PO.

---

## A. PO status model and transitions

**A1 — `received` / `partially_received` do not mean what they say (MEDIUM).**
`GrnService.php:1353-1398` — `Received` is set when every line's `quantity_accepted >= quantity`
(i.e. QC-passed), but `PartiallyReceived` when any line's `quantity_received > 0` (arrived,
pre-QC). A PO whose goods all arrived but are pending QC reads as `partially_received`.
*Fix:* document the predicate, or split `received_accepted` / `awaiting_qc`.

**A2 — Rejection is stored as `cancelled` (MEDIUM).**
`PurchaseOrderService.php:687` — `reject()` sets `Cancelled` and emits `PurchaseOrderCancelled`.
A rejected PO is indistinguishable from an operator cancellation everywhere downstream.
*Fix:* add a `rejected` status and event (or persist `rejection_reason`/`rejected_at`).

**A3 — `can_cancel` is advertised for sent/partially-received POs that can never be cancelled (HIGH).**
`PurchaseOrderAccessPolicy.php:101-111` allows `Sent`/`PartiallyReceived`; the service refuses
any PO with a GRN (`PurchaseOrderService.php:773-775`), and a **draft** GRN is auto-staged the
moment a PO is sent (`GrnService.php:294-347`). So the button is offered and always refused.
*Fix:* allow cancelling when all GRNs are `draft`, or drop sent/partially-received from `canCancel`.

**A4 — `restore` route vs service guard disagree (MEDIUM).**
Route needs `purchasing.po.manage` (`routes.php:68`); service calls `canManageDraft`, which now
needs `purchasing.po.create` (`PurchaseOrderAccessPolicy.php:88-99`). A holder of only `po.manage`
passes the route then gets 403. *Fix:* align on one permission or add a `canRestore` method.

**A5 — Receive-before-send strands a PO (LOW/MEDIUM).**
`GrnService::create()` accepts an `Approved` PO (`:109-115`) and flips it to `PartiallyReceived`,
after which `markAsSent()` (requires `Approved`, `PurchaseOrderService.php:717`) can never run;
`sent_to_supplier_at` stays null. *Fix:* forbid receipt before `Sent`, or accept `PartiallyReceived` in send.

---

## B. Action / capability flags vs backend

**B1 — `is_billable` disagrees with the billing service (HIGH).**
`PurchaseOrderResource.php:27-31` marks `Sent|PartiallyReceived|Received` billable, but
`BillService.php:184-187` blocks only `Cancelled|Closed` and requires an accepted GRN
(`:866-889`). Billable-but-refused and billable-but-hidden both occur.
*Fix:* derive `is_billable` from the same accepted-GRN predicate the service enforces.

**B2 — `can_submit_invoice` true without an accepted GRN (HIGH).**
`SupplierPurchaseOrderResource.php:37` vs `SupplierPortalService.php:482-490`.
*Fix:* include "accepted GRN exists" in the capability.

**B3 — No `can_schedule_delivery` capability (MEDIUM).**
`SupplierPortalService.php:699` guards schedules on `SUPPLIER_SCHEDULE_STATUSES`; the SPA reuses
`can_update_shipment` (`delivery-schedules.tsx:82`). *Fix:* add the explicit flag.

**B4 — SPA fallback action map duplicates the policy (LOW/MEDIUM).**
`spa/src/pages/purchasing/purchase-orders/detail.tsx:58-67` is a second state machine that has
already drifted (its `can_cancel` ignores the GRN guard). *Fix:* render `data.actions` only.

---

## C. GRN lifecycle and PO feedback

**C1 — Partially accepted receipts are unbillable (HIGH).**
`GrnService::partialAccept()` writes real `quantity_accepted` + stock (`:605-686`), but every bill
path requires `GrnStatus::Accepted` (`BillService.php:302-307,877`; `SupplierPortalService.php:485`).
Accepted goods exist with no payable. *Fix:* allow billing `partial_accepted` for the accepted qty.

**C2 — Over-receipt tolerance is per-GRN, not cumulative (MEDIUM).**
`GrnService.php:197-212,413-424` re-grants the full tolerance each receipt. *Fix:* cap cumulative received at ordered × (1 + tolerance).

**C3 — `partial_accepted` has no distinct PO mapping (LOW).**
`refreshPoStatus()` ignores GRN status entirely (`:1353-1398`). *Fix:* unify the accepted predicate.

**C4 — Reject writes the rejecter into `accepted_by`/`accepted_at` (LOW).**
`GrnService.php:699-704,1169-1173`. *Fix:* add `rejected_by`/`rejected_at`.

---

## D. Supplier portal

**D1 — A supplier's own draft invoice is invisible (MEDIUM).**
`SupplierPortalService::SUPPLIER_VISIBLE_BILL_STATUSES` excludes `Draft` (`:80-85`), and
`logout` isn't relevant here — `invoiceDetail()` 404s drafts (`:600-603`). `submitInvoice()`
creates a Draft (`:494-505`) that the supplier then cannot see. *Fix:* include the supplier's own draft bills.

**D2 — Portal invoice draft bills **ordered** qty, not accepted qty (MEDIUM).**
`SupplierPortalService.php:466-473` uses `$poItem->quantity`; the auto-staged path uses
`quantity_accepted` (`BillService.php:331-352`). The draft overstates the payable.
*Fix:* build from the accepted GRN lines.

**D3 — Supplier `deliveries()` exposes internal draft/rejected GRNs (LOW).**
`SupplierPortalService.php:617-630`. *Fix:* restrict to supplier-relevant statuses.

**D4 — Portal dashboard open/pending counts contradict `scopeOpen` (MEDIUM).**
`SupplierPortalService.php:99-104` (`open_po_count` = approved,sent; `pending_delivery_count` = sent)
vs `PurchaseOrder.php:111-118` (approved,sent,partially_received). *Fix:* reuse `scopeOpen()`.

---

## E. Three-way match and billing

**E1 — `status` mixes "missing line" with "quantity variance" (LOW).** `ThreeWayMatchService.php:86-117`.
**E2 — Stock bill can be created against an `Approved` PO server-side while the UI hides it (MEDIUM).** `BillService.php:182-191` vs B1.
**E3 — Override maker-checker at post, not create (by design, LOW).** `BillService.php:212-220,443-448`.

---

## F. Supplier dispatch state machine

**F1 — `portal_available` is recorded as a `manual_required` outcome (LOW/MEDIUM).** `PrepareSupplierDispatch.php:35-42`.
**F2 — Benign notes stored in `last_error` (LOW).** `SupplierDispatchService.php:243-245`.
**F3 — `recoverOne()` can force `confirmed` from status alone (LOW).** `SupplierDispatchService.php:312-334`.

---

## G. Dashboards, alerts, consumers

**G1 — "Open POs" widget counts `closed` as open (MEDIUM).** `DashboardWidgetDataService.php:135`.
**G2 — Purchasing KPI open/overdue use non-standard sets (MEDIUM).** `PurchasingDashboardService.php:40-49`.
**G3 — Warehouse incoming queue omits `approved` POs the GRN service accepts (LOW/MEDIUM).** `WarehouseDashboardService.php:99` vs `GrnService.php:109-115`.
**G4 — Purchasing "upcoming deliveries" includes `approved`; warehouse excludes it (LOW).** `PurchasingDashboardService.php:200-204`.
**G5 — `cancelled` (and thus rejected) maps to the chain's `closed` step (MEDIUM).** `ChainDefinitions.php:86-97`.
**G6 — Cross-module hardcoded status lists (MEDIUM hygiene).** See Reference Table C.
**G7 — Return recalc can roll a `sent` PO back to `approved` (MEDIUM).** `ReturnRequestService.php:1288-1292`.
**G8 — No PO-overdue alert in the alert engine (LOW).** `AlertEngineService.php`.

---

## H. Notifications and events

**H1 — No notification on send/cancel/reject or GRN reject (LOW/MEDIUM).** Wired events listed in `AppServiceProvider.php:337-346`.
**H2 — `supplier.dispatch_action_required` miscategorized (LOW).** `NotificationCatalog.php:234`.
**H3 — Rejection reuses the cancellation event (MEDIUM).** `PurchaseOrderService.php:697-703`.

---

## I. Supplier performance

**I1 — On-time baseline is supplier-mutable and nulls are dropped (MEDIUM).**
`SupplierPerformanceService.php:284-312` reads `purchase_orders.expected_delivery_date`, which the
supplier can overwrite via `SupplierPortalService.php:227,292-295`; null rows are skipped.
*Fix:* freeze the required date; add `confirmed_delivery_date`; bucket "no promise" separately.

**I2 — Period anchoring differs per metric (LOW/MEDIUM).** `SupplierPerformanceService.php:80-84` vs `:294-296`.
**I3 — Quality fallback counts `partial_accepted`/`pending_qc` as failures (MEDIUM).** `SupplierPerformanceService.php:361-376`.
**I4 — Lead-time variance uses `po.date` for both anchors (LOW; revised date moves it).** `:431-479`.

---

## Reference Table A — PO transitions, route permission, service guard, action flag

| Action | Route | Route permission | Service guard | `actionsFor` |
|---|---|---|---|---|
| create | POST `/purchase-orders` | `purchasing.po.create` | PR must be approved unless system-generated | draft owner |
| update | PUT `/{po}` | `purchasing.po.create` | `Draft` + `canManageDraft` | `can_update` |
| delete | DELETE `/{po}` | `purchasing.po.create` | `Draft` + `canManageDraft` | `can_delete` |
| restore | PATCH `/{po}/restore` | `purchasing.po.manage` | `canManageDraft` (needs `po.create`) | *(none)* |
| submit | PATCH `/{po}/submit` | `purchasing.po.create` | `Draft` | `can_submit` |
| approve | PATCH `/{po}/approve` | `purchasing.po.approve` | `PendingApproval` + budget/PPAP/SoD | `can_approve` |
| reject | PATCH `/{po}/reject` | `purchasing.po.approve` | `PendingApproval` → `Cancelled` | `can_reject` |
| send | PATCH `/{po}/send` | `purchasing.po.send` | `Approved` + `canSend` | `can_send` |
| cancel | PATCH `/{po}/cancel` | `purchasing.po.create` | no GRNs | `can_cancel` |
| close | PATCH `/{po}/close` | `purchasing.po.create` | `Received` + `canClose` | `can_close` |
| acknowledge-budget | PATCH `/{po}/acknowledge-budget` | `budgeting.approve` | `canAcknowledgeBudget` | `can_acknowledge_budget` |
| pdf | GET `/{po}/pdf` | `purchasing.view` | `canView` | `can_print` |

## Reference Table B — supplier capability flag vs true precondition

| Capability | Flag predicate | True precondition | Match |
|---|---|---|---|
| `can_acknowledge` | `status === approved` | `Approved` | ✅ (but semantics wrong — see target design) |
| `can_update_shipment` | `sent, partially_received` | `SUPPLIER_SHIPMENT_STATUSES` | ✅ |
| `can_upload_document` | `sent, partially_received` | `SUPPLIER_DOCUMENT_STATUSES` | ✅ |
| `can_submit_invoice` | `sent, partially_received, received` | same **plus accepted GRN** | ❌ B2 |
| delivery schedule | *(none; SPA reuses `can_update_shipment`)* | `SUPPLIER_SCHEDULE_STATUSES` | ❌ B3 |

## Reference Table C — hardcoded PO-status lists to update when the enum grows

`PurchaseOrder::scopeOpen` (111-118); `PurchaseOrderService` (83-90, 245/347/412);
`PurchaseOrderAccessPolicy` (97, 104-110, 116, 123, 207); `PurchaseOrderResource` (27-31);
`SupplierDispatchService` (175-180, 325-336); `GrnService` (109-113, 305, 1218-1220, 1373-1379);
`SupplierPortalService` (50-78, 99-104); `SupplierPurchaseOrderResource` (34-37);
`BillService` (184-187); `DashboardWidgetDataService` (135); `PurchasingDashboardService`
(40-44, 47-49, 116-123, 200-204); `WarehouseDashboardService` (99); `CoreWidgetAnalytics` (256-264);
`InventoryDashboardService` (70-74); `BarcodeScanResolverService` (148); `MrpEngineService` (968-972);
`BudgetConsumptionService` (31-33); `ProcurementChainController` (43-46); `ReturnRequestService`
(1288-1292); `ChainDefinitions` (86-97); `VendorSourcingService` (310, 327);
`PurchaseRequestAccessPolicy` (210).

---

## Target design (agreed)

```
draft → pending_approval → approved → sent ─┬─→ acknowledged ─→ partially_received → received → closed
                                             ├─→ supplier_proposed   (awaiting purchasing)
                                             └─→ supplier_declined   (purchasing re-sources/cancels)
```
- `sent` = OGAMI transmitted the PO. `acknowledged` = supplier accepted as-is.
- Supplier sees/acts only on `sent`.
- Supplier response = accept / propose changes (qty, price, date per line) / decline, with reason.
- Purchasing reviews: accept (applies changes) / reject / counter.
- `expected_delivery_date` = required date (supplier cannot write); `confirmed_delivery_date` = agreed date; scorecard measures confirmed when present, else required.
- `can_submit_invoice` requires an accepted GRN.

## Prioritised fix order

1. C1 partially-accepted receipts unbillable.
2. A3 cancel flag vs GRN guard.
3. B2 / D1 / B1 capability-vs-service mismatches.
4. D2 supplier invoice uses ordered qty.
5. Status rename (sent vs acknowledged) + all Reference Table C consumers.
6. A1/A2 status semantics (`received`, `rejected`).
7. Phased negotiation + required/confirmed dates (the agreed feature).
