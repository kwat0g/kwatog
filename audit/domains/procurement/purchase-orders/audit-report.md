# M037 — Purchase Orders Audit Report

Session: 2026-08-25  
Claimed module: `procurement / purchase-orders`  
Audit status at close: `🔁 Needs Re-audit`

## Why this was re-audited

The registry presented M037 as `📋 Plan Ready`, but the original report and
plan predated substantial uncommitted changes in the purchase-order service,
requests, routes, SPA, and supplier-facing DTO. The old `fix-log.md` recorded
no production fixes, so the existing findings could not be accepted without a
current evidence pass. This report supersedes the earlier discovery while
retaining its finding IDs for traceability.

## Scope and method

The audit is limited to the purchasing purchase-order module. Dependency code
was read only for contract and workflow context; no dependency module was
audited or changed. Evidence was checked in the backend service, requests,
resources, routes, lifecycle enum/constraint, the internal PO pages, and the
supplier-portal boundary. Findings are classified as `Broken`, `Missing`,
`Incomplete`, or `Polish`.

## Discovery

The live module contains:

- Internal CRUD and lifecycle routes in `api/app/Modules/Purchasing/routes.php:56-71`,
  including draft mutation, submit, approval, rejection, send, cancel, close,
  soft-delete restore, and PDF endpoints.
- The primary state and financial boundary in
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:142-206` and
  `:293-380`; money is calculated through `Money`, and lifecycle writes use
  service-only enum assignments.
- PR provenance validation in
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:740-787`, with
  hash-ID request resolution in
  `api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php:25-54`.
- A critical-shortage auto-PO path in
  `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:44-153`.
  It is live from the Inventory caller at
  `api/app/Modules/Inventory/Services/AutoReplenishmentService.php:49-64`.
- Internal list/create/detail surfaces in
  `spa/src/pages/purchasing/purchase-orders/index.tsx:146-250`,
  `spa/src/pages/purchasing/purchase-orders/create.tsx:99-207`, and
  `spa/src/pages/purchasing/purchase-orders/detail.tsx:125-191`.
- A supplier-facing DTO and status/action boundary read from the B2B module at
  `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:11-62` and
  `api/app/Modules/B2B/Services/SupplierPortalService.php:49-189`.

No current route is an obvious stub. The main unresolved gaps are policy
decisions at module boundaries: the direct-create contract conflicts with the
current API/UI rule that every manual PO must originate from an approved PR,
and the critical auto-PO documentation still says “routed to VP” while the
canonical workflow has Purchasing, Finance, and thresholded VP steps.

## Findings and current disposition

### M037-F01 — Broken, P0: critical auto-PO used an impossible lifecycle state

The pre-session implementation generated the unknown sequence key `po`, wrote
`pending_vp` even though the enum and database constraint only accept
`pending_approval`, and did not create durable approval records. The current
implementation now uses the registered `purchase_order` sequence and canonical
enum at `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:101-135`,
inside a transaction with item/supplier locks at `:44-80`, and submits through
`ApprovalService` at `:135`. The enum and database contract are
`api/app/Modules/Purchasing/Enums/PurchaseOrderStatus.php:7-17` and
`api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:26`.

Technical repair: fixed in this session. A focused integration test was added
at `api/tests/Feature/Purchasing/AutoPurchaseOrderServiceTest.php:48-98`, but
the shared test database had no `migrations` table before assertions could run.

Open policy question: `PROCESS-FLOWS.md:488-493` and the Inventory caller
(`AutoReplenishmentService.php:49-54`) promise a VP-routed bypass, while
`api/database/seeders/WorkflowSeeder.php:68-74` defines the standard three-step
PO workflow. The canonical choice made in this session must be confirmed or
replaced consistently across workflow seed data, notifications, documentation,
and the test gate.

### M037-F02 — Broken, P0: supplier PO item contract mismatch

The current supplier DTO maps the portal contract explicitly at
`api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:48-62`, and
the portal types use the same string IDs and field names at
`spa/src/types/b2b.ts:46-59`. The controller returns that DTO for list/detail at
`api/app/Modules/B2B/Controllers/SupplierPortalController.php:76-99`.

Current disposition: fixed by pre-existing worktree changes. No B2B dependency
file was modified in this session.

### M037-F03 — Broken, P1: PR-line provenance was dropped on manual create

The internal create form carries `purchase_request_item_id` from the selected
PR at `spa/src/pages/purchasing/purchase-orders/create.tsx:145-155` and sends it
at `:189-207`. The service preserves and validates the source line and selected
item at `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:761-783`;
the converter also supplies the source line at `:264-279`.

Current disposition: fixed by pre-existing worktree changes. This needs the
blocked HTTP test gate before it can be marked verified.

### M037-F04 — Broken, P1: restore could not resolve soft-deleted POs

The route opts into trashed binding at
`api/app/Modules/Purchasing/routes.php:60-64`, and the service re-reads with
`withTrashed()` and locks before restoring at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:661-673`.

Current disposition: fixed by pre-existing worktree changes.

### M037-F05 — Broken, P0: draft update/delete were stale/race unsafe

Both draft mutation paths now execute in `DB::transaction()` and lock/re-read
the authoritative PO before checking `Draft`: update at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:293-305`, delete
at `:640-658`. Submit also locks before creating approval records at `:364-380`.

Current disposition: fixed in the current worktree. A real two-connection race
test remains part of the verification gate; the shared database prevented that
verification in this session.

### M037-F06 — Broken, P1: accepted incoterm was dropped

Incoterm is validated on create/update at
`api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php:35-46` and
`UpdatePurchaseOrderRequest.php:31-45`, persisted by the service at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:181-196` and
`:326-337`, returned at
`api/app/Modules/Purchasing/Resources/PurchaseOrderResource.php:65-70`, and
rendered in the internal form/detail and PDF at
`spa/src/pages/purchasing/purchase-orders/create.tsx:317-322`,
`spa/src/pages/purchasing/purchase-orders/detail.tsx:179-190`, and
`api/resources/views/pdf/purchase-order.blade.php:27-33`.

Current disposition: fixed by pre-existing worktree changes; supplier/PDF
round-trip still needs an isolated contract test.

### M037-F07 — Incomplete, P1: budget state could remain stale after editing

The locked draft update recalculates the amount and budget assessment at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:321-348`, and
clears warning/acknowledgment fields when no department is present at
`:345-357`. Approval still asserts/enforces the current PO budget before the
locked approval write at `:388-432`.

Current disposition: fixed in the current worktree. Upward/downward amount
coverage remains unverified because the focused suite could not initialize its
test schema.

### M037-F08 — Incomplete, P1: draft approval actions were exposed without records

The service now permits approval/rejection only for `PendingApproval` at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:423-432` and
`:507-520`; the SPA exposes those actions only for that state at
`spa/src/pages/purchasing/purchase-orders/detail.tsx:125-143`.

Current disposition: fixed by pre-existing worktree changes. The terminal
rejection-as-cancelled policy remains documented in the UI at
`detail.tsx:449-470` and should be confirmed against the business vocabulary.

### M037-F09 — Incomplete, P1: supplier actions are only partly reflected by the DTO

The supplier service gates acknowledgement, shipment, and document operations
by status at `api/app/Modules/B2B/Services/SupplierPortalService.php:58-78`,
`:192-235`, and `:317-321`; invoice submission also requires an accepted GRN at
`:436-464`. However, the read-only supplier DTO still advertises
`can_submit_invoice` for all sent/receiving-stage POs without checking accepted
GRN state at `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:32-37`.

Current disposition: endpoint hardening is present, but the capability
contract is incomplete. This is a cross-module B2B change and was deliberately
not modified under the purchasing-only scope. It requires a separate contract
decision/test.

### M037-F10 — Broken, P1: multipart uploads manually set the boundary header

The supplier API now passes `FormData` directly without a manually forced
`Content-Type` header at `spa/src/api/b2b/supplier.ts:165-185`.

Current disposition: fixed by pre-existing worktree changes. Browser upload
smoke coverage remains pending.

### M037-F11 — Incomplete, P1: purchasing officer role still overlaps maker/checker permissions

The high-severity conflict rule is seeded at
`api/database/seeders/SodConflictRuleSeeder.php:22-27`, while the
`purchasing_officer` role receives the full purchasing module, including both
PO create and approve, at `api/database/seeders/RolePermissionSeeder.php:616-621`.
`ApprovalService` blocks the same submitter from acting, but that is not the
same policy as removing the role-level conflict.

Current disposition: deferred for an explicit RBAC/SoD decision. Changing the
role seeder or shared authorization policy would exceed this module’s scope;
the plan remains `Needs Re-audit` until the organization chooses maker/checker
overlap versus role separation/exception monitoring.

### M037-F12 — Incomplete, P2: cancel visibility needed permission gating

The cancel route requires `purchasing.po.create` at
`api/app/Modules/Purchasing/routes.php:64-70`, and the internal detail page now
checks the same permission before rendering Cancel at
`spa/src/pages/purchasing/purchase-orders/detail.tsx:142-143`.

Current disposition: fixed by pre-existing worktree changes; permission UI
coverage remains unverified.

### M037-F13 — Incomplete, P2: internal PO IDs needed hash-string typing

The internal line type now declares string IDs at `spa/src/types/purchasing.ts:126-137`,
and the resource returns the hash-encoded source line at
`api/app/Modules/Purchasing/Resources/PurchaseOrderItemResource.php:13-20`.

Current disposition: fixed by pre-existing worktree changes.

### M037-F14 — Incomplete, P2: rejection reason validation was weaker than UI copy

The API now trims and enforces a 10–500 character reason at
`api/app/Modules/Purchasing/Requests/RejectPurchaseOrderRequest.php:16-27`; the
SPA uses the same ten-character minimum at
`spa/src/pages/purchasing/purchase-orders/detail.tsx:449-460`.

Current disposition: fixed by pre-existing worktree changes.

### M037-F15 — Polish, P2: list filters and overdue navigation were incomplete

The service supports vendor, overdue, date-range, and PO-number search filters
at `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:71-91`; the SPA
exposes those controls at `spa/src/pages/purchasing/purchase-orders/index.tsx:146-207`
and preserves the overdue predicate in the queue link at `:315-321`.

Current disposition: fixed by pre-existing worktree changes.

### M037-F16 — Missing/question: direct-create policy is contradictory

The process document says both “Convert from approved PR” and “Create directly”
are valid PO sources at `docs/PROCESS-FLOWS.md:539-549` and repeats “or create
directly” in its test procedure at `:566-568`. The live API requires
`purchase_request_id` at `api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php:35-42`,
and the UI requires an approved PR before rendering the form at
`spa/src/pages/purchasing/purchase-orders/create.tsx:104-109` and `:238-254`.

This is intentionally a question, not an assumed bug: decide whether the
process document is stale or the direct-create path is missing, then align the
request contract, UI, service exception for system-generated bypasses, and
acceptance tests.

## Polish pass

The internal list and detail pages use the design-system primitives (`PageHeader`,
`FilterBar`, `DataTable`, `Panel`, `Chip`, and `Button`) at
`spa/src/pages/purchasing/purchase-orders/index.tsx:183-250` and
`detail.tsx:179-259`. Amounts and identifiers use monospace values, lifecycle
states use text-bearing chips, and the list has an overflow-safe table wrapper.
This matches the Atelier requirements for dense tables, mono/tabular numbers,
and status text in `docs/DESIGN-SYSTEM.md:19-21`, `:225-241`, and `:435-444`.
The accessibility requirements for focus, labels, and table headings are stated
at `docs/DESIGN-SYSTEM.md:524-531`; no new purchase-order-specific polish
defect was found in the current diff.

The supplier DTO is intentionally smaller than the internal resource, which is
the correct boundary, but its capability field noted in F09 is a completeness
problem rather than a visual defect.

## Verification limits

- PHP syntax and targeted diff checks passed for the session changes.
- `spa`: `npm run typecheck` failed on unrelated existing errors in
  `spa/src/pages/assets/detail.tsx:6,67` and
  `spa/src/pages/return-management/detail.tsx:902`.
- The selected backend purchasing/B2B run could not be treated as product
  evidence: the shared run reported failures across the selected classes and
  was interrupted; the newly added focused test failed before assertions because
  the shared `ogami_test` database had no `migrations` table. Do not mark this
  module verified until the suite runs against a dedicated PostgreSQL test
  database.

## Open decisions before verification

1. Confirm the critical-shortage approval route: canonical PO workflow or a
   genuine VP-only workflow.
2. Decide whether manual direct PO creation is supported; reconcile
   `PROCESS-FLOWS.md`, the request/UI, and tests.
3. Resolve the purchasing-officer maker/checker permission overlap.
4. Align the supplier capability DTO with the accepted-GRN invoice gate in a
   separately scoped B2B change.

Until those decisions and the isolated verification gate are complete, M037
remains `🔁 Needs Re-audit`.

---

# Re-audit — 2026-08-30

Session: 2026-08-30 · Claimed `RECLAIMED` (orphan lock from 2026-08-25, holder
`codex-coordinator-blocker-quarantine`, 105h stale).

## What the previous session actually left

**Committed, log written, nothing uncommitted.** Every file the 2026-08-25
session claimed is present at HEAD and was swept into `167de85e`
("chore: remaining uncommitted work from ~50 crashed audit sessions") —
`AutoPurchaseOrderService.php`, `AutoPurchaseOrderServiceTest.php` and
`PurchaseOrderTwoConnectionRaceTest.php` all carry that commit. The working tree
holds no purchase-order changes. This is the **sixth** module in this pipeline
where an orphan lock meant completed work rather than abandonment.

The material difference from the other five: that session **verified nothing**.
Its own log records the reason — the shared `ogami_test` database had no
`migrations` table, and the PostgreSQL host was unresolvable — so F01–F16 were
re-checked *by reading the code*, not by probe. This re-audit therefore treats
every prior finding as unverified and measures it against real PostgreSQL rows in
a dedicated database (`ogami_test_po`).

## Result of re-probing the prior findings

Of F01–F16, **none reproduce as originally described**; the fixes committed in
`167de85e` hold up under measurement. F09/F11/F16 remain open *questions*, which
is what they were. What the prior session could not see, because it never ran
anything, is a set of defects its reading pass missed entirely — F17 through F24
below. Two of them (F18, F19) are money defects reachable from the UI today.

## Method

Three probe suites executed against `ogami_test_po` (own database; `db`/`redis`
left running), then deleted. 27 invariants exercised; the full matrix is in the
fix-log. Static: `php -l`, PHPStan (clean), Pint (inheritance proven, see below).

---

## Broken

### M037-F18 — a blocked three-way match renders as a green "Matched" on the PO page (P0, FIXED)

`PurchaseOrderService::show()` projected the `bills` relation to six columns:

```
'bills:id,bill_number,total_amount,balance,status,purchase_order_id',
```

while `PurchaseOrderResource.php:97-100` reads four columns outside that
projection — `due_date`, `has_variances`, `three_way_overridden`, and
`three_way_match_snapshot` (via `Bill::threeWayReviewStatus()`).
`AppServiceProvider.php:229-240` deliberately does **not** call
`preventAccessingMissingAttributes()`, so an unselected column reads as `null`
rather than throwing. `(bool) null` is `false`, and `threeWayReviewStatus()` fell
through to `'matched'`.

Measured on a bill whose row genuinely carried
`has_variances = true, three_way_match_snapshot = {"overall_status":"blocked"}`:

| | value |
|---|---|
| DB row | `has_variances=true` `overridden=false` `snapshot=blocked` `due_date=2026-09-30` |
| `GET /purchase-orders/{h}` payload | `"has_variances":false` `"three_way_overridden":false` `"due_date":null` `"three_way_review_status":"matched"` |
| same Bill, fully loaded | `threeWayReviewStatus() = manual_review` |
| SPA chip it drives | **SUCCESS/Matched** (truth: WARNING/Variance) |

Three branches in `spa/src/pages/purchasing/purchase-orders/detail.tsx` had
therefore **never once rendered** — `:119,122` (header 3-way chip), `:296-302`
(Billing panel step), `:384-388` (Linked-records match chip). This is the same
class as the supplier-portal panels that never rendered, but worse: the outer
gate at `detail.tsx:117` is a *key-presence* test (`'has_variances' in b`), and
the key is always emitted, so the chip always renders and always takes the green
arm. A PO whose bill is blocked on a real quantity or price variance displays
"Matched" and "Matched within tolerance". The truth was visible only on the
bill's own page. No test asserted it.

Fixed at `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:140`.

### M037-F19 — oversized money returns 500 SQLSTATE[22003] instead of 422 (P1, FIXED)

The sibling-module money defect recurs here in *half* its usual form. The
FormRequests already used `decimal:0,2`, which correctly refuses `1.999` and
`1e3` (both measured 422). What was missing was an upper bound:

| input on `items.*.unit_price` | before |
|---|---|
| `1.999` | 422 ✓ |
| `1e3` | 422 ✓ |
| `100000000000000000` | **500** `SQLSTATE[22003] numeric field overflow` |
| `999999999999999.99` | **500** `SQLSTATE[22003]` |
| `items.*.quantity = 100000000000000000` | **500** |

`decimal(15,2)` admits 13 integer digits, and the line total is a *product* of
quantity and unit_price, so both factors need bounding. Fixed at
`StorePurchaseOrderRequest.php:58,60` and `UpdatePurchaseOrderRequest.php:47,49`.

Note: in the testing environment the 500 body carried the full SQL statement and
`Host: db`. In production `APP_DEBUG=false` suppresses that, so the disclosure is
environment-dependent; the 500 itself was the defect.

### M037-F20 — raw integer primary keys in user-facing error bodies (P2, FIXED)

Three messages interpolated a database PK:

- `:263` `"PR line {$line->id} has no vendor assignment."`
- `:273` `"PR line {$line->id} has no authoritative unit price."`
- `:428` `"Vendor has no approved PPAP for item #{$line->item_id}."`

Measured verbatim from the conversion path: `PR line 1 has no vendor
assignment.` These are not the `{"id":<pk>,"reason":"Not found."}` existence
oracle four other modules shipped — the 404 bodies here are clean (`{"message":""}`
for `999999`, `1`, and a non-hash string alike, no oracle) — but they violate the
HashID rule and hand the caller `purchase_request_items` / `items` PKs.

Fixed at `:263`, `:273`, `:432` — the messages now name the line description or
item code.

### M037-F21 — cancelling an already-cancelled PO succeeds and re-publishes the chain event (P2, FIXED)

Measured: `cancel()` on a PO already in `cancelled` returned **ALLOWED**. It is
not idempotent — it appends a second `Cancelled: <reason>` block to `remarks`,
calls `supplierDispatches->cancelForPurchaseOrder()` again, re-runs
`reopenSourcePrIfLastLink()`, and records **another** `PurchaseOrderCancelled`
message on the `p2p` outbox. One logical cancellation, published twice to every
downstream chain listener. Fixed at `:611-613`.

### M037-F22 — the GRN cost basis is a plain SQL `AVG`, not the documented weighted average (P1, NOT FIXED — gate outcome)

`ThreeWayMatchService.php:48` aggregates the received cost as
`DB::raw('AVG(unit_cost) as avg_cost')`. CLAUDE.md states inventory valuation is
**weighted average cost**; a plain `AVG` ignores quantity.

Measured on a PO line of 100 @ ₱100.00 received as 99 @ ₱100.00 then 1 @ ₱1,000.00:

| | value |
|---|---|
| reported `grn_unit_cost` | **550.0000** |
| true quantity-weighted cost | 109.00 |
| resulting `grn_price_variance_pct` | 81.82% |
| outcome for a correct bill of 100 @ ₱100.00 | **blocked** |

It fails both directions. Here it false-blocks a correct bill. Mirror the
receipts (1 @ ₱100, 99 @ ₱1,000) and `AVG` is still 550 while the true weighted
cost is 991 — so a bill at ₱550 would **pass** the gate 44% below the real
received cost.

Not fixed: changing this changes which bills clear the variance gate, i.e.
whether a supplier is paid without review. Out of bounds for unilateral action.

### M037-F23 — `purchasing.open_pos` counts soft-deleted POs (P2, outside module — report only)

`api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:135` runs
`DB::table('purchase_orders')->whereNotIn('status', [...])->count()` with no
`whereNull('deleted_at')`. Measured with one live and one archived PO:
**`DB::table` = 2, Eloquent = 1**. Same shape as journal-ledger's ₱111-vs-₱999.

The purchase-order module's own aggregates are clean: `list()` = 1,
`list(trashed=with)` = 2, `Eloquent::sum` = ₱111.00 vs `DB::table::sum` = ₱1,110.00
(the raw figure is not reachable from any module code path), and
`ProcurementChainController` uses Eloquent, verified `po_sent: 1` not 2.

---

## Missing

### M037-F24 — a partially-received PO whose supplier will not ship the remainder is terminally stuck (P1, question)

Measured on a PO at `partially_received` after receiving 4 of 10 through the real
GRN path — **every** exit is refused:

| action | result |
|---|---|
| `close()` | refused — "Only fully received POs can be closed." |
| `cancel()` | refused — "Cannot cancel a PO with GRNs." |
| `update()` | refused — "Only draft POs can be edited." |
| `delete()` | refused — "Only draft POs can be deleted." |

There is no short-close, force-close, or write-off transition. The PO stays in
the open-PO aggregates and the overdue queue permanently. This is a genuine
functional gap, but supplying the missing transition is a policy decision about
who may write off an outstanding purchase commitment — flagged, not invented.

### No database guard that the header agrees with its lines

Measured: deleting every `purchase_order_items` row leaves `total_amount = 112.00`
with zero lines; setting the header to ₱999,999.00 against lines summing to
₱100.00 is accepted. The only CHECK constraint on `purchase_orders` is
`purchase_orders_status_lifecycle_check` (the enum), and there are **no triggers**.

Not currently exploitable: `create()` and `update()` both recompute the header
from `normalizeLines()`, lines are written nowhere else, and the API path was
measured exact — a three-line PO of `3 × 0.01 + 7 × 33.33 + 11 × 0.07` produced
`subtotal = 234.11` equal to `SUM(lines.total) = 234.11` (BCMath, no drift).
So this is missing defence-in-depth, not a live divergence. A header-vs-child-sum
rule is not expressible as a PostgreSQL CHECK (no subqueries) and would need a
trigger — deferred as scope disproportionate to a currently unreachable path.

### No PO edit path in the SPA

`PUT /purchase-orders/{po}` is live and `purchaseOrdersApi.update`
(`spa/src/api/purchasing/purchase-orders.ts:17-18`) exists, but has **zero call
sites**: there is no `edit.tsx`, and `detail.tsx` renders no Edit button. A draft
PO with a wrong line, quantity, price or incoterm can only be submitted or
cancelled. This also strands live backend behaviour — `PurchaseOrderService.php:348`
re-runs `budget->assess()` on update precisely so a changed amount re-derives its
budget warning, and no SPA path can reach it. `delete` and `restore`
(`:19-20`) are likewise dead, and archived POs are undiscoverable: the backend
supports `trashed=with|only` via `TrashedFilter::apply` (`:70`) but the list page
declares no `trashed` param and never imports `<ArchiveFilter>`, which two
sibling pages in the same module do use.

---

## Incomplete

### M037-F17 — the PO row scope is decorative, and search and list disagree (handed over from global-search; QUESTION, not acted on)

Two separate defects, and the second subsumes the first.

**(a) The department tier disagrees.** `PurchaseOrderService::list()` gates it on
the **role slug** `department_head` (`:102`); `GlobalSearchService.php:246-255`
gates the same tier on the **permission** `purchasing.pr.approve`. Measured on a
PO in the caller's department authored by someone else:

| role | `purchasing.view` | `pr.approve` | `po.approve` | in `list()` | in search |
|---|---|---|---|---|---|
| `department_head` | Y | Y | n | **1** | yes |
| `production_manager` | Y | Y | n | **0** | **yes** |
| `warehouse_staff` | n | n | n | 0 | n/a (403) |
| `employee` | n | n | n | 0 | n/a (403) |
| `qc_inspector` | n | n | n | 0 | n/a (403) |

Search is broader than the list for `production_manager`, exactly as reported.
The symmetric difference of the two roles' `purchasing.*` permission sets is
**empty** — both hold precisely `{purchasing.view, purchasing.pr.approve}` — so
global-search's claim that no permission distinguishes them is confirmed. Across
all modules the only slug that even *encodes* "department head" is
`leave.approve_dept`, which names leave, not procurement.

**(b) `show` and `pdf` apply no row scope at all.** This is the larger finding.
`PurchaseOrderController::show()` takes the route-bound model and calls
`PurchaseOrderService::show()`, which accepts **no `?User` parameter**. There is
no FormRequest, so no `authorize()`, and no policy check. Measured: a
`production_manager` for whom `list()` returns **0 rows** fetched the PO by hash
id and received **HTTP 200** with the full record — vendor, line items with unit
prices, approval records with approver names, linked GRNs, and linked bills with
amounts and balances. Strictly *more* than the list row would have shown.

The hash id is not a secret to that user: global search hands it over
(`GlobalSearchService.php:266`), as do the approvals Kanban and the dashboard
upcoming-delivery panels. `GET .../pdf` has the same gate and the same absence of
scope, so the same document is retrievable as a rendered PDF.

Fixing (a) without (b) leaves the row scope ornamental. Both belong to the same
human decision, stated in the action plan.

### The ₱50,000 threshold is read from settings, and the setting does not govern approval

Measured, and this is the clearest single result of the re-audit. There are two
thresholds:

1. `approval.po.vp_threshold` — a **settings** row (seeded 50000 by migration
   `0289`), operator-editable via `UpdateSettingRequest.php:65`, read by
   `BusinessPolicyService::purchaseOrderVpThreshold()`. It sets **only** the
   `requires_vp_approval` boolean, which is read by nothing but
   `PurchaseOrderResource.php:31` (the UI "VP req." chip) and a list filter.
2. `workflow_definitions.steps[2].threshold = "50000.00"` — seeded in
   `WorkflowSeeder.php:73`, and the **only** value that decides whether the VP
   step is `pending` or `skipped` (`ApprovalService.php:86-89`).

With the setting moved to ₱1,000 and a PO totalling ₱1,680.00:

```
requires_vp_approval = true          <- the UI says VP approval is required
vp_step_action       = skipped       <- the VP is not in the chain at all
```

The PO displays as requiring VP approval and is fully approved without a VP ever
seeing it. An operator editing the visible threshold changes the label and
nothing else. (Commit `91ad2efe` dropped `workflow_definitions.amount_threshold`
as "one approval threshold, not two"; two remain, one of them inert.)

**Correctly implemented parts, for the record:** the governing comparison is
`Money::lt($amount, $threshold)` on decimal strings, not floats, with the
threshold stored as a JSON string; and the threshold is evaluated on the
**VAT-inclusive** total — measured `subtotal = 45,000.00`, `vat = 5,400.00`,
`total = 50,400.00` → VP step `pending`. So ₱45,000 of goods triggers VP
approval because of tax. Both thresholds agree on VAT-inclusive, so this is
consistent; whether it is *intended* is a question.

Not acted on: choosing which threshold governs changes who may approve.

### Duplicate `item_id` across two PO lines double-counts the billed quantity

`matchForPo` keys both PO lines and bill lines by `item_id`
(`ThreeWayMatchService.php:70`). A PO with two lines of the same item, correctly
billed as one line of 20, was measured as **two** lines each showing
`bill_quantity = 20.00` and each flagged `qty_variance/block`. It over-blocks
rather than leaks, so no money escapes — but a legitimate bill is refused.

### A bill priced *below* the PO also blocks

`:89` uses `abs($billPrice - $poPrice)`. Measured: bill at ₱50.00 against a PO at
₱100.00 → `price_variance/block`, 50%. Favourable-to-the-buyer divergence is
gated identically to unfavourable. Plausibly intentional (any divergence wants
review) — flagged as a question.

### `(float)` on money in the threshold flag

`:192` and `:333` compute `requires_vp_approval` as `(float) $total >= $threshold`,
and `BusinessPolicyService::purchaseOrderVpThreshold()` returns a PHP `float`.
`:261` uses `(float) $unitPrice <= 0`. At ₱50,000.00 exactly the float and
decimal comparisons agree, and F19 now rejects magnitudes where float precision
would matter, so no behaviour differs today. Left alone because the flag it feeds
is display-only and the real defect is the divergence above; changing it would
flip a user-visible chip for no correctness gain.

---

## Polish

- **SPA money floats.** `index.tsx:286` float-sums up to 25 `total_amount`
  strings per bucket; `create.tsx:166-169,184,185,424-427` float-computes
  subtotal, VAT, total and per-line totals. `create.tsx:186-187` makes a
  *decision* on a float — `total >= policies.data.purchase_order_vp_threshold`,
  where the threshold itself crosses the wire as a JSON number
  (`BusinessPolicyService.php:38-45` returns `float`). The server recomputes
  everything, so nothing wrong is persisted; what is wrong is the figure the
  operator confirms against. `spa/src/lib/money.ts` exists and is used by exactly
  one unrelated page.
- `detail.tsx:242-246` renders money as `Number(x).toFixed(2)`, bypassing
  `formatPeso`, so the Total column reads `1234567.00` four rows under a StatCard
  reading `₱1,234,567.00`.
- `ThreeWayMatchResult` emits raw integer `po_id` and `item_id`
  (`Support/ThreeWayMatchResult.php`, `ThreeWayMatchService.php:126,161,239`);
  `ThreeWayMatchController::show()` is the only PO-adjacent endpoint with no
  `JsonResource`. Disclosure, not IDOR — nothing is sent back — but it
  contradicts the HashID rule, and the SPA types it `number`
  (`types/purchasing.ts:289,292`).
- `detail.tsx:47-52` fires a second request to `/purchase-orders/options` to
  label a status the payload already labels (`status_label`,
  `PurchaseOrderResource.php:25`, absent from the PO TypeScript type though
  present on `PurchaseRequest`).
- `quantity_accepted_pct` (`PurchaseOrderResource.php:71`) costs two `SUM`
  queries per row and is rendered nowhere; the list does not eager-load `items`,
  so a 25-row page issues ~100 extra aggregate queries, half for a value nothing
  displays. `items[].quantity_accepted` and `quantity_remaining` — the
  IATF-relevant accepted figures — are emitted and never shown.

---

## Verified sound (measured, no defect)

- **Status machine.** Every illegal transition named in the brief is refused:
  cancel a received or closed PO; send a draft, pending, already-sent or
  cancelled PO; approve a draft, already-approved or cancelled PO; reject an
  approved PO; close a sent, partially-received, cancelled or already-closed PO;
  amend a sent, approved or received PO; re-submit a pending PO; submit a
  cancelled PO; delete a sent or approved PO; restore a live PO. Receiving is
  refused against `draft`, `pending_approval`, `cancelled`, `closed` and
  `received`. The enum is also enforced in PostgreSQL by
  `purchase_orders_status_lifecycle_check`.
- **`partially_received` → `received` requires the full quantity**, per line and
  on *accepted* not merely received quantity: `GrnService::refreshPoStatus()`
  uses `bccomp($l->quantity_accepted, $l->quantity, 3) >= 0` for **every** line.
- **A PO cannot be closed with an outstanding balance** — `close()` requires
  `Received`.
- **Over-receipt is refused, including in aggregate across partial receipts.**
  After receiving 4 of 10, a second receipt of 7 was refused: "only 6.000
  remaining" — the guard is `bcsub(quantity, quantity_received)` against the
  running total inside the locked transaction, with a settings-driven tolerance.
  A single-shot 50-against-10 is refused identically.
- **Bill above PO price → blocked. Bill with no GRN at all → blocked** ("you
  cannot pay for goods that were never received"). **Quantity variance within
  the 5% tolerance → `matched/ok`; beyond → `blocked`.**
- **Line-level divergence hidden by a matching sum → blocked.** A bill of
  20 × ₱100 on item A and 0 on item B, summing to the PO's ₱2,000 exactly, was
  correctly flagged `qty_variance/block` on line A.
- **The variance override is recorded and attributable**: `bills` carries
  `three_way_overridden`, `three_way_overridden_by`, `three_way_overridden_at`,
  `three_way_override_reason` and `three_way_match_snapshot`.
- **Self-approval is refused** — `ForbiddenActionException` "You cannot act on a
  record you submitted", from `ApprovalService::approve()` resolving `created_by`.
- **An amendment cannot ride an old approval.** `update()` is Draft-only and
  there is no path from `pending_approval` back to `draft` (rejection is
  terminal → `cancelled`), so no amendment can cross the threshold after
  submission. `submit()` recomputes the chain from `total_amount` each time.
- **The supplier copy cannot diverge from the internal record** — by
  immutability, not snapshotting. `supplier_order_dispatches` holds no copy of PO
  contents (only channel/status/attempts/metadata), and the PO is unamendable
  after `sent`.
- **Permission gate: all 15 endpoints return 403** for a role without
  `purchasing.*` — including `index`, `options` and `pdf`.
- **The restore route binds `withTrashed()` and works** —
  `routes.php:63` carries `->withTrashed()`, and a soft-deleted PO restored to
  HTTP 200 with `trashed = false`. Unlike two sibling modules, this route is not
  a 404-for-every-target.
- **Error bodies carry no raw ids** — 404 for `999999`, `1` and a non-hash string
  alike, all `{"message":""}`. No existence oracle.
- **A live PO whose vendor is archived does not 500** — `show()` returns 200 with
  `vendor: null` (unlike AR's aging report). The blank vendor on the detail page
  is a data-presentation gap, not a crash.
- **The approval chain has 3 steps, not CLAUDE.md's 4** — `purchasing_officer` →
  `finance_officer` → `system_admin` (VP, thresholded), per
  `WorkflowSeeder.php:70-74`. Code, seeder and enum agree. Confirms the standing
  correction: audit against the seeder, not CLAUDE.md's sentence.
- **`ApprovalService` sends no notifications**, confirming the
  purchase-requests handoff for this module too, and **the VP step is the one the
  threshold skips**, not one that is notified.

## Not applicable

**GL posting.** The Purchasing module contains **no** journal-entry reference at
all — programmatically confirmed: zero files under
`api/app/Modules/Purchasing/` mention `JournalEntry`, `JournalEntryService` or
`postJournal`. No PO transition (approve, send, receive, close) posts to the
ledger; P2P posting happens downstream in Accounting on the Bill and GRN. There
is therefore no PO-driven write path on which to test closed-period refusal, and
accounting decision #12 (`created_by` discarded on source-linked entries, making
maker-checker inert) has **no consequence in this module** — it owns no posting.

## Verification limits

- The 3-way findings F22 and the duplicate-`item_id` behaviour were measured
  through `ThreeWayMatchService` directly rather than through a posted Bill;
  the service is the gate, so the measurement is of the gate.
- Cross-tenant supplier isolation was not re-tested — already verified by
  `supplier-portal` (21 of 25 routes, no leak).
- The SPA money-float findings were read, not executed in a browser.
- No two-process race probe was run this session; the prior session's
  `PurchaseOrderTwoConnectionRaceTest` remains at HEAD and passed as part of the
  146-test Purchasing run.
