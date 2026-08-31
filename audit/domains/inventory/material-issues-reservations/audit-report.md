# Material Issues & Reservations Module Audit

Module: `inventory/material-issues-reservations` (`M042`)  
Session: 2026-08-25  
Claim: `./audit/scripts/claim-module.sh inventory material-issues-reservations` → `CLAIMED`

## Re-audit reason

The previous report and plan were written around 00:25–00:27 local time. The
fix log recorded no production changes, but M042 files were modified later in
the shared worktree, including `MaterialIssueService.php`, the material-issue
request/UI, picking, stock movement, and cancellation tests. Those changes could
invalidate the earlier findings, so this session re-ran discovery, hardening,
and polish against the current files. Dependency modules were read for
contracts only; they were not audited or modified.

## Discovery

The current module contains:

- List/show/create/cancel HTTP routes. Reads use `inventory.view`; creation and
  cancellation use `inventory.issue.create`; the picking endpoint also uses the
  broad `inventory.view` permission (`api/app/Modules/Inventory/routes.php:129-150`).
- A transactional create service that creates every slip as `issued`, moves
  stock, records line costs, and optionally changes a reservation
  (`api/app/Modules/Inventory/Services/MaterialIssueService.php:58-155`).
- A cancellation service that now locks the slip and its lines before reversal,
  with a sequential stale-request regression test
  (`api/app/Modules/Inventory/Services/MaterialIssueService.php:167-225`,
  `api/tests/Feature/Inventory/MaterialIssueCancelTest.php:97-122`).
- Reservation rows created by the production confirmation flow and consumed by
  the production-start flow; that dependency behavior was read for integration
  context only (`api/app/Modules/Production/Services/WorkOrderService.php:864-875,914-959`).
- Picking suggestions backed by stock levels plus a movement-derived preferred
  lot (`api/app/Modules/Inventory/Services/PickingListService.php:23-41,121-202`,
  `api/app/Modules/Inventory/Services/StockLocationSummaryService.php:57-115`).
- SPA list, create, detail, and picking pages. Lot/UOM inputs now exist on the
  create form, but reservation selection and cancellation controls do not
  (`spa/src/pages/inventory/material-issues/index.tsx:31-87`,
  `spa/src/pages/inventory/material-issues/create.tsx:25-284`,
  `spa/src/pages/inventory/material-issues/detail.tsx:22-92`,
  `spa/src/pages/warehouse/picking.tsx:17-178`).
- Database status allow-lists and additive lot/UOM columns, but no current
  foreign keys or quantity/value checks for all issue/reservation links
  (`api/database/migrations/0065_create_material_issue_slips_table.php:13-29`,
  `api/database/migrations/0066_create_material_issue_slip_items_table.php:13-28`,
  `api/database/migrations/0067_create_material_reservations_table.php:13-27`,
  `api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:35-37`,
  `api/database/migrations/2026_08_25_150000_harden_inventory_contracts.php:18-22`).

Existing tests cover cancellation, lot stamping, quarantine exclusion, stock
count freezes, GL handoff, and production reservation splitting. There is still
no focused HTTP create contract for reservation matching, work-order identity,
idempotency, or role denial, and no SPA/browser coverage for these pages.

## Hardening findings

### M042-F01 — Incomplete / decision required: ledger-issued slips are presented as waiting for physical picking

Classification: **Incomplete**  
Priority: **P0**  
Scope: large  
Session recommendation: `separate-recommended`

`create()` persists `MaterialIssueStatus::Issued` before the stock movement and
there is no draft/pick/complete route (`api/app/Modules/Inventory/Services/MaterialIssueService.php:60-70,109-121`,
`api/app/Modules/Inventory/Enums/MaterialIssueStatus.php:7-24`,
`api/app/Modules/Inventory/routes.php:146-150`). The picking page then queries
`status=issued`, labels the records “ready to pick”, and its action only
navigates to a detail page with no pick transition
(`spa/src/pages/warehouse/picking.tsx:23-50,156-169`).

Question for the process owner: is the create command itself the authoritative
physical issue, in which case the picking surface must be advisory/view-only,
or must a draft → picked/issued lifecycle be added so stock and GL post only at
the physical transition? This cannot be safely inferred from the current code.

### M042-F02 — Broken: reservation-backed issue checks availability before releasing the reservation

Classification: **Broken**  
Priority: **P0**  
Scope: large  
Session recommendation: `separate-recommended`

`StockMovementService::move()` computes available stock as quantity minus
reserved quantity (`api/app/Modules/Inventory/Services/StockMovementService.php:116-129`).
`MaterialIssueService::create()` calls `move()` first, then changes the linked
reservation and releases the quantity (`api/app/Modules/Inventory/Services/MaterialIssueService.php:109-134`).
The valid reserved quantity is therefore rejected when no unreserved stock is
available; if unrelated unreserved stock covers it, the reservation can be
marked fully issued while only part of its quantity is released.

### M042-F03 — Broken: reservation and work-order identity are not validated as one locked contract

Classification: **Broken**  
Priority: **P0**  
Scope: large  
Session recommendation: `separate-recommended`

The request validates only existence for `work_order_id`, item/location, and
the reservation field (`api/app/Modules/Inventory/Requests/StoreMaterialIssueRequest.php:22-45`).
The service locks a reservation only after the stock movement and never checks
that it is still `reserved`, belongs to the submitted work order, matches the
submitted item/location, or covers the submitted quantity
(`api/app/Modules/Inventory/Services/MaterialIssueService.php:126-134`). It also
does not lock/re-read the work order or require an allowed production status.
The reservation field is not decoded by `ResolvesHashIds`, even though the
frontend contract uses hash IDs for other foreign keys
(`api/app/Modules/Inventory/Requests/StoreMaterialIssueRequest.php:22-29`,
`spa/src/api/inventory/material-issues.ts:10-23`).

### M042-F04 — Broken: manual issue slips do not update work-order material actuals

Classification: **Broken**  
Priority: **P0**  
Scope: large  
Session recommendation: `separate-recommended`

Manual issue creation writes stock and a slip but never updates
`work_order_materials.actual_quantity_issued`, `actual_cost`, `cost_variance`,
or `variance` (`api/app/Modules/Inventory/Services/MaterialIssueService.php:109-152`).
The production-start integration updates those fields for its own direct stock
movements (`api/app/Modules/Production/Services/WorkOrderService.php:946-958`).
The create page sends a work-order ID (`spa/src/pages/inventory/material-issues/create.tsx:101-115`),
so the manual path can leave stock/GL and production actuals inconsistent.

### M042-F05 — Incomplete: cancellation race is fixed, but reversal provenance is incomplete

Classification: **Incomplete**  
Priority: **P0**  
Scope: medium  
Session recommendation: `separate-recommended`

The later edit correctly locks and re-reads the slip and lines before reversal,
so the old double-reversal race is addressed
(`api/app/Modules/Inventory/Services/MaterialIssueService.php:169-218`). However,
an issued slip with a linked reservation never changes that reservation back to
`released`; only the draft branch does so (`api/app/Modules/Inventory/Services/MaterialIssueService.php:185-214`).
Cancellation also accepts no reason and persists no cancellation actor/reason
fields, while the route passes an unrestricted `Request`
(`api/app/Modules/Inventory/Controllers/MaterialIssueSlipController.php:43-47`).

### M042-F06 — Broken: create has no durable idempotency boundary

Classification: **Broken**  
Priority: **P0**  
Scope: medium  
Session recommendation: `separate-recommended`

Each create request generates a new sequence number and enters the stock
movement loop; the slip schema has no operation key or unique identity
(`api/app/Modules/Inventory/Services/MaterialIssueService.php:60-74`,
`api/database/migrations/0065_create_material_issue_slips_table.php:13-29`).
The SPA only disables its submit button while the request is pending
(`spa/src/pages/inventory/material-issues/create.tsx:273-279`). A committed
request followed by a network retry can therefore consume stock and post GL
twice.

### M042-F07 — Broken: issue totals still truncate instead of using canonical money rounding

Classification: **Broken**  
Priority: **P1**  
Scope: medium  
Session recommendation: `separate-recommended`

The movement record uses explicit half-up rounding, but the issue service
calculates line and slip totals with bare BCMath scale-2 additions
(`api/app/Modules/Inventory/Services/MaterialIssueService.php:123-152`). The
shared `Money` helper documents half-up rounding and the repository forbids
float money arithmetic (`api/app/Common/Support/Money.php:7-12,88-99`,
`CLAUDE.md:447-453`). A four-decimal WAC boundary can make the slip total differ
from the movement/GL total.

### M042-F08 — Missing: database integrity is incomplete for links and quantities

Classification: **Missing**  
Priority: **P1**  
Scope: medium  
Session recommendation: `separate-recommended`

`material_issue_slips.work_order_id` and `material_reservations.work_order_id`
remain unsigned IDs with deferred-FK comments, and
`material_issue_slip_items.material_reservation_id` has only an index
(`api/database/migrations/0065_create_material_issue_slips_table.php:16,26`,
`api/database/migrations/0066_create_material_issue_slip_items_table.php:21,27`,
`api/database/migrations/0067_create_material_reservations_table.php:16,25`).
The later migration adds lot/UOM columns only
(`api/database/migrations/2026_08_25_150000_harden_inventory_contracts.php:18-22`).
There are no database checks for positive quantities or nonnegative issue costs.

### M042-F09 — Broken: inactive, blocked, and inactive-item sources remain issuable

Classification: **Broken**  
Priority: **P1**  
Scope: medium  
Session recommendation: `separate-recommended`

The issue service loads a location and rejects only quarantine/scrap zones
(`api/app/Modules/Inventory/Services/MaterialIssueService.php:88-107`). The
central movement guard has the same limited zone check
(`api/app/Modules/Inventory/Services/StockMovementService.php:301-329`). It does
not enforce `items.is_active`, `warehouse_locations.is_active`, or the persisted
`warehouse_locations.is_blocked`; the request also uses plain `exists`
(`api/app/Modules/Inventory/Requests/StoreMaterialIssueRequest.php:39-43`).
The warehouse tree exposes locations without filtering these operational states
(`api/app/Modules/Inventory/Services/WarehouseService.php:18-29`).

### M042-F10 — Broken: issue and picking responses expose raw internal IDs

Classification: **Broken**  
Priority: **P1**  
Scope: small  
Session recommendation: `separate-recommended`

The issue resource returns `work_order_id` directly and does not load a
work-order number/summary (`api/app/Modules/Inventory/Resources/MaterialIssueSlipResource.php:14-29`,
`api/app/Modules/Inventory/Services/MaterialIssueService.php:45-51`). The
list/detail types still declare the field as a number and the list renders
`WO#<raw id>` (`spa/src/types/inventory.ts:286-299`,
`spa/src/pages/inventory/material-issues/index.tsx:50-59`). Picking output also
uses raw work-order, item, and location IDs
(`api/app/Modules/Inventory/Services/PickingListService.php:33-39,46-66,79-107`).
This violates the repository hash-ID contract (`CLAUDE.md:568-572`).

### M042-F11 — Incomplete: reservation, lot, UOM, and precision data are still not end-to-end

Classification: **Incomplete**  
Priority: **P1**  
Scope: large  
Session recommendation: `separate-recommended`

Lot/UOM columns and request inputs were added, and the service now passes the
lot into the movement (`api/database/migrations/2026_08_25_150000_harden_inventory_contracts.php:18-22`,
`api/app/Modules/Inventory/Services/MaterialIssueService.php:109-145`). The
reservation field still has no options endpoint or form control, is typed as a
number in the SPA, and is not decoded in the request
(`spa/src/api/inventory/material-issues.ts:15-23`,
`spa/src/pages/inventory/material-issues/create.tsx:72-115`). The frontend accepts
four decimal places while the request and database accept three
(`spa/src/pages/inventory/material-issues/create.tsx:28-34`,
`api/app/Modules/Inventory/Requests/StoreMaterialIssueRequest.php:39-45`,
`api/database/migrations/0066_create_material_issue_slip_items_table.php:18-20`).
The detail resource does not expose the reservation link
(`api/app/Modules/Inventory/Resources/MaterialIssueSlipItemResource.php:14-31`).

### M042-F12 — Broken: picking authorization is broader than the seeded permission

Classification: **Broken**  
Priority: **P1**  
Scope: small  
Session recommendation: `separate-recommended`

The permission catalog explicitly defines `inventory.picking.view`
(`api/database/seeders/RolePermissionSeeder.php:203-218`), but the API and SPA
route use `inventory.view` (`api/app/Modules/Inventory/routes.php:129-130`,
`spa/src/routes/inventoryRoutes.tsx:83-84`). The page only disables its final
button and still fetches the issued-slip list for any inventory viewer
(`spa/src/pages/warehouse/picking.tsx:18-33,156-169`). Client-side disabling is
not an authorization boundary.

### M042-F13 — Incomplete: FEFO remains a movement-history heuristic, not lot-authoritative stock

Classification: **Incomplete**  
Priority: **P1**  
Scope: medium  
Session recommendation: `separate-recommended`

The later `preferredLot()` edit nets tagged movement quantities and filters to
positive net lots (`api/app/Modules/Inventory/Services/StockLocationSummaryService.php:57-115`),
which is safer than selecting the earliest historical receipt. But issue rows
with no lot stamp do not decrement any tagged lot, while stock levels remain
aggregated by item/location (`api/app/Modules/Inventory/Services/MaterialIssueService.php:109-121`,
`api/database/migrations/0056_create_stock_levels_table.php:13-28`). The picking
page can therefore still present a historical lot/expiry as exact after an
untagged physical issue. Either implement lot-quantity accounting or label and
constrain the result as a location-level heuristic.

### M042-F14 — Missing: cancellation/correction is not available in the live SPA

Classification: **Missing**  
Priority: **P1**  
Scope: medium  
Session recommendation: `separate-recommended`

The API exposes cancellation, but the API client has no cancel method and the
detail page has no reason input, confirmation, cancel mutation, or refreshed
result (`api/app/Modules/Inventory/Controllers/MaterialIssueSlipController.php:43-47`,
`spa/src/api/inventory/material-issues.ts:5-25`,
`spa/src/pages/inventory/material-issues/detail.tsx:37-89`).

### M042-F15 — Polish: list filters and primary action are incomplete

Classification: **Polish**  
Priority: **P2**  
Scope: small  
Session recommendation: `same-session-ok` after the semantic fixes

The service supports status and date ranges (`api/app/Modules/Inventory/Services/MaterialIssueService.php:31-42`),
but the page exposes only the search input and no header create action
(`spa/src/pages/inventory/material-issues/index.tsx:62-83`). This misses the
design-system list pattern of header actions, filter bar, and dense paginated
table (`docs/DESIGN-SYSTEM.md:492-500`).

## Controls currently working or improved

- Issue creation and stock movements are transaction-wrapped, and the movement
  boundary locks stock levels and posts the GL handoff
  (`api/app/Modules/Inventory/Services/MaterialIssueService.php:60-155`,
  `api/app/Modules/Inventory/Services/StockMovementService.php:53-188`).
- Quarantine/scrap source exclusion, count freezes, and movement-level GL
  handoff remain centralized (`api/app/Modules/Inventory/Services/StockMovementService.php:301-377`).
- Cancellation now serializes on the authoritative slip row and has sequential
  stale-request coverage (`api/app/Modules/Inventory/Services/MaterialIssueService.php:169-224`,
  `api/tests/Feature/Inventory/MaterialIssueCancelTest.php:97-122`).
- Lot/UOM fields now exist in the current issue-line migration, model, request,
  service, resource, and create form, although the end-to-end contract remains
  incomplete (`api/database/migrations/2026_08_25_150000_harden_inventory_contracts.php:18-22`,
  `api/app/Modules/Inventory/Models/MaterialIssueSlipItem.php:16-27`,
  `spa/src/pages/inventory/material-issues/create.tsx:187-234`).
- SPA pages are lazy-loaded and provide loading/error/empty/stale handling in
  the inspected list/detail paths, but the role/action completeness is not yet
  sufficient for verification (`spa/src/routes/inventoryRoutes.tsx:97-102`,
  `spa/src/pages/inventory/material-issues/index.tsx:44-83`).

## Verification evidence

This re-audit was evidence-based from the current source. The prior focused
backend suite reached setup but failed before assertions because PostgreSQL host
`db` could not resolve; the prior SPA typecheck and token audit passed. No new
runtime claim is made here until the refreshed plan is implemented and the
database-backed suites can run.

## Audit conclusion

M042 remains `📋 Plan Ready`. The first ordered item contains an unresolved
physical-process decision, and the majority of remaining items affect inventory
quantities, GL values, idempotency, permissions, or cross-module work-order
accounting. No production-code fix was applied during this re-audit; the
refreshed action plan records the required human decision and implementation
sequence.



---

# Re-audit — 2026-09-01 (session 4)

Claim: `RECLAIMED` (orphan lock, 2026-08-25, 154h stale).

## Status: IN PROGRESS (skeleton committed before probing)

Prior-work assessment: three prior sessions produced 15 findings (M042-F01…F15)
and applied **zero production-code fixes**. The prior fix log self-flags that its
focused PHP suite "reached setup but failed before assertions because PostgreSQL
host `db` could not resolve" — i.e. **no finding in this module has ever been
runtime-verified**. This session's job is therefore to measure each of them, and
to execute the reservation-invariant matrix.

Where the code actually lives: `api/app/Modules/Inventory/` (owned this session)
plus `spa/src/pages/inventory/material-issues/`, `spa/src/pages/warehouse/picking.tsx`.
Reservation *writers* also live in `api/app/Modules/Production/Services/WorkOrderService.php`
and `api/app/Modules/Maintenance/Services/SparePartUsageService.php` — out of
module, report-only.

(Findings appended below as measured.)
