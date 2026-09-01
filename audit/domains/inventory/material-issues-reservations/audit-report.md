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

## Status: IN PROGRESS — static discovery + baseline MEASURED; invariant probe running

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

### Environment (verified, this session)

- `docker compose ps`: `ogami-db` and `ogami-redis` up and healthy; **the `api`
  service is not running** — all PHP is executed through
  `docker compose run --rm --no-deps -e DB_DATABASE=… api …`.
- `psql -U ogami -d postgres -c "select 1;"` → `1`.
- Own database created: `ogami_test_matiss`.
- **Real baseline: `tests/Feature/Inventory` = 177 passed / 621 assertions / exit 0**
  (81.75s). Non-zero assertions, and it matches the neighbouring
  `warehouse-stock-control` session's stated baseline exactly, so the harness is
  genuinely executing.

### Handoff verification (`warehouse-stock-control`)

`reserve()` and `release()` both now call `assertPositiveQuantity()` before doing
anything (`api/app/Modules/Inventory/Services/StockMovementService.php:387`,
`:423`, guard at `:441-448`). The neighbour's fix is present in the tree I
inherited, and its regression test `reservations reject non positive quantities`
is green in my baseline run.

## HTTP-vs-service coverage split (MEASURED)

Five routes are in scope (`api/app/Modules/Inventory/routes.php:129,152-155`):

| Route | Permission | Any HTTP test? |
|---|---|---|
| `GET /inventory/material-issues` | `inventory.view` | **none** |
| `GET /inventory/material-issues/{materialIssueSlip}` | `inventory.view` | **none** |
| `POST /inventory/material-issues` | `inventory.issue.create` | **none** |
| `DELETE /inventory/material-issues/{materialIssueSlip}` | `inventory.issue.create` | yes — `api/tests/Feature/Inventory/MaterialIssueCancelTest.php:134` |
| `GET /inventory/picking-lists/mis/{materialIssueSlip}` | `inventory.view` | **none** |

**1 of 5 routes has any HTTP coverage.** `grep -rn "material-issues\|picking-lists" api/tests/`
returns exactly one hit, the cancel route. In particular **`POST /material-issues`
— the endpoint that moves stock and posts GL — has never been exercised over
HTTP by any test**; every existing test calls `MaterialIssueService::create()`
directly, which bypasses `StoreMaterialIssueRequest` entirely. That is the same
blind spot that hid the `POST /inventory/grn` 500 for six days.

## Findings measured by static reading (evidence cited; runtime confirmation in the probe section)

### M042-N01 — Broken (P0): a reservation-backed issue is checked against
*unreserved* stock, so a reservation cannot be drawn against

`StockMovementService::move()` computes availability as on-hand minus reserved
and refuses when short (`StockMovementService.php:118-124`).
`MaterialIssueService::create()` calls `move()` **first** (`:109`) and only then
looks at the linked reservation and calls `release()` (`:126-134`). A reservation
therefore subtracts from the very pool the issue it exists for must draw from.
Confirms prior **M042-F02** by code path; runtime result below.

### M042-N02 — Broken (P0): the reservation link is consumed with no identity,
status or quantity contract — one work order's issue can silently destroy another's reservation

`MaterialIssueService.php:126-134` is the whole of it:

```php
$res = MaterialReservation::query()->lockForUpdate()->find($actualId);
if ($res) {
    $res->update(['status' => ReservationStatus::Issued, 'released_at' => now()]);
    $this->movements->release($itemId, $locId, $qty);
}
```

`if ($res)` is the only guard. Nothing checks that the reservation

- is still `reserved` (an already-`issued` or `released` row is re-consumable),
- belongs to the `work_order_id` on the slip,
- matches the submitted `item_id` / `location_id`,
- covers the submitted quantity.

And `release()` is called with **the issued quantity, not the reservation's
quantity**. Two distinct corruptions follow, both of which break
`reserved == Σ(active reservations)`:

- issue *less* than reserved → the row flips to `Issued` while the unreleased
  remainder stays counted in `stock_levels.reserved_quantity` forever (no code
  path can ever release it, since the row is no longer `reserved`);
- issue *more* than reserved → `release()` over-releases, and because
  `reserved_quantity` is a single per-(item, location) scalar it is **another
  work order's reservation that gets decremented**. The victim's row still says
  `reserved`, so the ledger and the reservation table disagree.

This is broader than prior **M042-F03**, which described only the missing
validation, not the cross-reservation destruction.

### M042-N03 — Missing (P0): reservations have no HTTP surface at all

`grep -rn "reservation" api/app/Modules/*/routes.php api/routes/*.php` returns
**zero hits**. There is no endpoint to list, create, release or inspect a
`material_reservations` row anywhere in the system. Reservations are written only
by `Production\Services\WorkOrderService` (out of module) and read only
incidentally. So warehouse staff — the role that owns this module — cannot see
what is reserved, cannot release a reservation, and the create form cannot offer
one to pick (the SPA has no options call for it). The half of the module named in
its own title is effectively invisible.

### M042-N04 — Missing (P1): nothing ages a reservation, and there is no column to age it by

`material_reservations` (`api/database/migrations/0067_create_material_reservations_table.php:13-27`)
has `reserved_at`, `released_at`, `status` — and **no `expires_at`**.
`grep -rni "reserv" api/routes/console.php` returns **zero hits**: no scheduled
command touches reservations. This is the `material-review-board` shape (nothing
ages a hold), not the 8D shape (an ager that lies) — there is no ager to lie.
Combined with N02 and N06 a reservation can be orphaned permanently while still
consuming availability. **Question for a human: is reservation expiry in scope?**
Nothing in the schema suggests it was ever designed, so this is reported, not
assumed to be a defect.

### M042-N05 — Broken (P1): the issue resource leaks a raw integer work-order PK

`MaterialIssueSlipResource.php:16` returns `'work_order_id' => $this->work_order_id`
— the raw bigint. Confirms prior **M042-F10**. `spa/src/types/inventory.ts` types
it as a number and the list renders `WO#<raw id>`.

### M042-N06 — Missing (P1): no FK on either `work_order_id`, nor on the reservation link

- `material_issue_slips.work_order_id` — `unsignedBigInteger`, comment
  `// FK in Sprint 6` (`0065_…:16`).
- `material_reservations.work_order_id` — same (`0067_…:16`).
- `material_issue_slip_items.material_reservation_id` — `unsignedBigInteger`,
  index only, no FK (`0066_…:21,27`).

So a slip or reservation can point at a work order that does not exist, and
deleting a work order leaves both dangling. Confirms prior **M042-F08**.

### M042-N07 — Broken (P1): the reservation id is the one foreign key that is *not* hash-decoded

`StoreMaterialIssueRequest::hashIdFields()` covers `work_order_id`,
`items.*.item_id`, `items.*.location_id` — but **not**
`items.*.material_reservation_id` (`:22-29`), whose rule is
`['nullable','integer','exists:material_reservations,id']` (`:44`). The client is
therefore required to send a **raw internal PK** for that one field, against the
project-wide HashID contract. Confirms prior **M042-F11** in part.

### M042-N08 — Incomplete (P2): picking is gated on `inventory.view`, not the
`inventory.picking.view` permission that exists for it

`inventory.picking.view` is defined in the catalog
(`api/database/seeders/RolePermissionSeeder.php:224`) and held by
`warehouse_staff` (`:667`), but the route uses `inventory.view`
(`api/app/Modules/Inventory/routes.php:129`). The permission is real and
assigned; the gate simply does not use it, so every inventory viewer
(`qc_inspector`, `purchasing_officer`, …) can read picking lists. This is
privilege *broadening*, not an impossible approval chain — downgraded from prior
**M042-F12**'s P1.

### M042-N09 — Polish: line/slip totals truncate where the movement rounds half-up

`MaterialIssueService.php:142,151` use `bcadd($x, '0', 2)`, which truncates.
`StockMovementService::round2()` (`:450-456`) adds `0.005` first, i.e. half-up.
So a line whose exact total is `1.0005` stores `1.00` on the slip and `1.00` on
the movement… but the *slip total* accumulates at scale 4 and truncates once at
the end, so multi-line slips can differ from the sum of their movements by cents.
Confirms prior **M042-F07** as a real inconsistency; magnitude measured below.

### Prior findings NOT reproduced / downgraded

- **The `numeric|min:0` validation family does NOT apply here.**
  `items.*.quantity_issued` is `['required','decimal:0,3','min:0.001']`
  (`StoreMaterialIssueRequest.php:41`) — the same correct shape that made
  `material-review-board` clean, not the `numeric|min:0` shape that broke nine
  siblings. Prediction going into the probe: `1e3`/`1e17`/`10.00005` are all
  **rejected**, not silently truncated. Measured result below.
- **CLAUDE.md correction #6 does not bite this module.** `document_sequences` has
  no `material_issue` row in the dev database, but rows are lock-or-created on
  demand by `DocumentSequenceService::generate()` (`:60-85`) and the required
  *config* entry exists (`settings['documents.sequence_config']` contains
  `"material_issue":{"prefix":"MI"…}`, verified by query). Numbering works.
- **`MaterialIssueStatus::Draft` exists** (`Enums/MaterialIssueStatus.php:9`) but
  `create()` hard-codes `Issued` (`MaterialIssueService.php:67`) and no route can
  produce a draft. The Draft branch of `cancel()` (`:200-216`) — the only code
  that correctly releases a reservation — is therefore **unreachable through any
  HTTP path**. This sharpens prior M042-F01/F05 rather than refuting them.

## MEASURED — reservation invariant matrix (probe run 1: 10 tests / 14 assertions / exit 0)

Executed against PostgreSQL 16 in `ogami_test_matiss`. Raw probe output quoted verbatim.

### What HOLDS (good news, verified against independent SQL)

| Invariant | Probe | Measured |
|---|---|---|
| `available = on_hand − reserved` | reserve 30, issue 10 of 100, then read `stock_levels` with raw SQL, *not* through Eloquent | `qty=90.000 reserved=30.000 avail=60.000` — exact |
| `reserved ≤ on_hand` | reserve 101 against 100 on hand | refused, `InsufficientStockException` |
| same, cumulative | reserve 60, then reserve 60 more | second refused; `reserved` stays `60.000` |
| **stock cannot be issued out from under a reservation** | reserve all 100, then a *plain* issue of 10 with no reservation link | refused — `Insufficient available stock at location 4 for item 4: needed 10, available 0.000` |
| release floor | `release(999)` against 20 reserved | clamped to `0.000`, never negative |
| double release | release 999 then release 20 | `0.000`, idempotent |
| negatives at **every** reserve/release/issue call site | `reserve(-50)`, `release(-999)`, issue `-50` | all three `InvalidMovementException`; `reserved` untouched at `0.000` |

The neighbour's `assertPositiveQuantity()` handoff is verified working, and the
issue path is covered by it too (`move()`'s own `validateInput()` at
`StockMovementService.php:276-278`).

### M042-N01 — Broken (P0) — CONFIRMED BY MEASUREMENT: a reservation cannot be drawn against by the issue it exists for

```
[I5] *** F02 REPRODUCES: a reservation cannot be drawn against.
     InsufficientStockException — Insufficient available stock at location 5
     for item 5: needed 10, available 0.000.
```

Reserve 100 of 100 for a work order, then issue 10 **citing that reservation's
id**: refused. `move()` (`StockMovementService.php:118`) subtracts
`reserved_quantity` from availability, and `create()` calls `move()` at
`MaterialIssueService.php:109` — *before* the reservation is released at `:132`.
So reserving stock makes it unissuable **including to the work order that
reserved it**. The feature is not merely unguarded, it is inverted: a reservation
is a promise that converts into a refusal. Prior **M042-F02 reproduces exactly.**

### M042-N02a — Broken (P0) — NEW: a partial issue orphans the reservation remainder in the ledger forever

```
[I6] reservation status=issued qty=50.000; level reserved=45.000;
     sum(active reservations)=0
```

Reserve 50, issue 5 against it. The reservation row flips to `issued` **in full**
while `release()` is called with the *issued* quantity (5), so `reserved_quantity`
drops only to 45. Result: **45.000 units are held reserved in `stock_levels` with
zero `reserved` rows backing them.** No code path can ever release them — every
releaser keys on `status = reserved`, and this row is now `issued`. The 45 units
are permanently unissuable and permanently invisible.

`reserved_quantity` = 45.000 vs `Σ(active reservations)` = 0. The two disagree.

### M042-N02b — Broken (P0) — NEW: issuing against one reservation silently destroys another work order's reservation

```
[I7] after issuing 100 vs reservation A(10): level.reserved=0.000;
     reservation B status=reserved qty=90.000; sum(active)=90.000
```

200 on hand. WO-101 reserves 10; WO-102 reserves 90; `reserved_quantity = 100`.
Now issue **100** citing reservation A — which covers only 10. Accepted. Then
`release($itemId, $locId, '100')` runs, and because `reserved_quantity` is a
single per-(item, location) scalar with no per-reservation accounting, **it is
WO-102's 90 units that get released.** WO-102's row still reads `reserved`,
quantity 90, and believes it is protected. It is not: its stock is now free for
anyone to take.

`reserved_quantity` = 0.000 vs `Σ(active reservations)` = 90.000.

This is a cross-work-order integrity failure, materially worse than prior
**M042-F03**, which described only the missing validation.

### M042-N02c — Broken (P0) — NEW: a spent reservation is replayable, and the replay steals a third party's fresh reservation

```
[I8] *** re-use of an ISSUED reservation ACCEPTED. reserved: 0.000
     -> (3rd party +10) 10.000 -> 0.000; res.status=issued
```

`if ($res)` at `MaterialIssueService.php:130` never checks the status. Issue 10
against reservation A (reserved → 0). A third party then reserves 10 of its own
(reserved → 10). Re-submit the **same** issue against the **same, already-`issued`**
reservation: accepted, and `release()` runs again — taking the third party's 10.
So a retried or duplicated create request does not merely double-consume stock
(prior M042-F06), it also silently strips an unrelated reservation.

### The single root cause

All three are one defect: **`stock_levels.reserved_quantity` is an unattributed
scalar, and `material_reservations` rows are never reconciled against it.**
`release()` takes a bare `(item, location, quantity)` and cannot know whose
reservation it is decrementing. Any fix has to either (a) release exactly the
reservation's own outstanding quantity under the same lock that flips its status,
or (b) derive `reserved_quantity` from `Σ(active reservations)` rather than
maintaining it independently. Both change what a reservation *means*, so this is
**not** containment work.

## MEASURED — concurrency (two concurrent OS processes, wall-clock epoch barrier)

Run against a **persistent** database (`ogami_race_matiss`, migrated, no
`RefreshDatabase` — which would have hidden uncommitted rows from the second
connection and faked a "no lock" result). Barrier is absolute epoch-ms, so the
`APP_TIMEZONE=Asia/Manila` vs UTC-container skew cannot affect it.

### Race 1 — two work orders reserving the last unit: **exactly one winner** ✓

10 on hand; both processes call `reserve(10)` at the same instant.

```
B: WON
A: LOST InsufficientStockException
report: qty=10.000 reserved=10.000 sum(active)=10.000 issue_lines=0
```

`reserve()` takes a genuine `lockForUpdate()` (`StockMovementService.php:404`
via `lockOrCreate()`) and re-reads availability **inside** the lock, so it is the
correct shape — not the pre-read that let two stock-count sessions both reach
`in_progress`. Ledger and reservation table agree.

### Race 2 — two issues citing the SAME reservation: **BOTH WON** ✗ (P0)

20 on hand, one 10-unit reservation. Two processes each issue 10 citing that
reservation id, simultaneously.

```
B: WON
A: WON
report: qty=0.000 reserved=0.000 sum(active)=0 issue_lines=3
  res#1 status=issued qty=10.000
```

**A 10-unit reservation authorised 20 units of issue.** This is the concurrent
form of M042-N02c, and it is the *worse* of the two shapes the pipeline has seen:
the reservation row **is** locked (`MaterialIssueService.php:129`,
`lockForUpdate()->find()`), so the two transactions correctly serialise — but
there is no status or quantity check *inside* that lock for the serialisation to
protect. A real lock guarding an absent guard is more dangerous than a missing
lock, because the code reads as if it were safe.

### Race 3 — incidental: `DocumentSequenceService` loses a first-of-month race
**(out of module — `api/app/Common/Services/`, report only)**

On the very first `material_issue` number of the month, both processes found no
`document_sequences` row and both tried to `insert` it
(`api/app/Common/Services/DocumentSequenceService.php:64-77`):

```
A: LOST UniqueConstraintViolationException — SQLSTATE[23505] duplicate key
B: WON
```

The lock-or-create uses a plain `insert()`, not the `insertOrIgnore()`-then-relock
pattern that `StockMovementService::lockOrCreate()` uses correctly
(`StockMovementService.php:233`). A unique violation is not retried by
`DB::transaction()`'s deadlock retry, so **the first two concurrent documents of
any type in any month can hard-fail with a 500-class error.** This is a shared
helper used by every module's numbering; it is not mine to fix. Reported to the
coordinator.

## MEASURED — remaining invariants

### M042-N10 — Broken (P0): material can be issued to a work order in ANY state, including cancelled

```
[I17] planned=ISSUE OK | confirmed=ISSUE OK | in_progress=ISSUE OK |
      paused=ISSUE OK | completed=ISSUE OK | closed=ISSUE OK | cancelled=ISSUE OK
```

All **7/7** `WorkOrderStatus` cases accept an issue. `StoreMaterialIssueRequest`
validates only `exists:work_orders,id` (`:34`) and `create()` never loads the work
order at all. Material can be consumed against a cancelled or closed work order,
and the cost lands nowhere recoverable.

### M042-N11 — Broken (P0): an issue may exceed the BOM requirement without limit, and never updates work-order actuals

```
[I18] bom_quantity=5 issued 40 -> actual_quantity_issued=0.000, variance=0.000,
      actual_cost='0.00', cost_variance='0.00'
```

Issued **8× the BOM requirement**: accepted silently. And every actuals column on
`work_order_materials` stayed at zero. Meanwhile the production-start path *does*
maintain them (`Production/Services/WorkOrderService.php:946-958`). Confirms prior
**M042-F04** by measurement.

### M042-N12 — Incomplete (P0): two disjoint issue paths, neither doing the other's bookkeeping

`grep -rn "MaterialIssueSlip\|MaterialIssueService" api/app/Modules/Production/ api/app/Modules/Maintenance/`
returns **zero hits**. So:

| path | creates a slip? | moves stock? | updates WO actuals? |
|---|---|---|---|
| `MaterialIssueService::create()` (this module, the HTTP route) | yes | yes | **no** |
| `Production\WorkOrderService` start/reserve (`:930-942`, `:946-958`) | **no** | yes | yes |

This is the "bypasses the canonical service" shape, but symmetrical: there is no
single canonical issue path. Production consumes material with no issue document
at all (nothing for a warehouse to sign, nothing to cancel), and the documented
path leaves production accounting untouched. Which one is authoritative is a
**process-owner question**, not something to infer.

### M042-N13 — Broken (P1): issue slips and reservations are fully mutable, with zero triggers

```
[I13] eloquent slip_number=HACKED-EL; raw=HACKED-SQL;
      line qty->9999.000 while stock_level stays 90.000; raw delete=DELETED;
      non-internal pg_triggers on the 3 tables=0
```

Probed all four ways on a slip that had already moved stock:

- Eloquent `forceFill()->save()` — rewrote `slip_number` to `HACKED-EL`;
- raw SQL `UPDATE` — rewrote it again to `HACKED-SQL` and `total_value` to 99999;
- raw SQL rewrote a line's `quantity_issued` from `10` to `9999` while
  `stock_levels.quantity` stayed at `90.000` — **document and ledger silently
  disagree by 9989 units**;
- raw SQL `DELETE` of a line succeeded.

`pg_trigger` count (non-internal) across `material_issue_slips`,
`material_issue_slip_items`, `material_reservations` = **0**. Same exposure as
`stock_movements` (N4, deferred by `warehouse-stock-control`) and the two Quality
modules. Per that handoff, any fix must be **column-scoped** — reported, not
unilaterally triggered.

### M042-N14 — Broken (P1): a new issue can be created against a soft-deleted item at a soft-deleted location, and archiving destroys the traceability of existing slips

```
[I19] item trashed=y | loc trashed=y | list=200 | show=200 |
      show.items=[{"id":"dGypLxpvAg","item":null,"location":null,
                   "quantity_issued":"10.000","unit_cost":"25.0000","total_cost":"250.00",...}]
      | new issue on trashed item/loc=ACCEPTED
```

Two distinct defects:

1. **Fails OPEN on create.** `exists:items,id` / `exists:warehouse_locations,id`
   (`StoreMaterialIssueRequest.php:39-40`) do not exclude soft-deleted rows, and
   the service does not check `deleted_at`. An archived item at an archived
   location is still issuable — the same fail-open shape as `goods-receiving`'s
   incoming-QC gate.
2. **Existing slips lose their identity.** `show` still returns 200, but
   `item` and `location` both come back **`null`** because the resource's
   `whenLoaded` relations silently skip trashed rows
   (`MaterialIssueSlipItemResource.php:15-27`). A financial record retains
   `250.00` of value with no record of *what* was issued or *from where*. For an
   IATF 16949 traceability chain that is a material loss, not cosmetics.

### M042-N09 — Broken (P1) — CONFIRMED with a discriminating value: the slip disagrees with the stock movement (and therefore the GL) by a centavo per line

My first probe used WAC `0.3335` (exact total `1.0005`) and measured
`line=1.00, slip=1.00, movement=1.00` — **no divergence**, because half-up and
truncation agree at that value. That probe did not discriminate; I re-ran with a
value that does:

```
[I26] qty 1 @ wac 1.0050 (exact 1.0050) => line.total_cost=1.00,
      slip.total_value=1.00, movement.total_cost=1.01  [truncate=1.00, half-up=1.01]
```

`MaterialIssueService.php:142,151` use `bcadd($x,'0',2)` (truncates);
`StockMovementService::round2()` (`:450-456`) adds `0.005` first (half-up). The
document says ₱1.00, the inventory ledger and the GL handoff say ₱1.01. Prior
**M042-F07 reproduces** — and note it took a deliberately chosen value to show it,
which is why it had never been caught.

### M042-N15 — Broken (P1) — MEASURED: the picking payload and the 422 error bodies leak raw integer PKs

```
[I21] show data.work_order_id=1 (real WO pk=1); raw match=YES
[I21] over-issue 422 body={"message":"Insufficient available stock at
      location 2 for item 2: needed 99999.000, available 95.000."}
      real item pk=2 loc pk=2
[I20] picking body: {"data":{...,"lines":[{"item_id":1,...,
      "preferred_location":{"id":1,...},"suggestions":[{"location":{"id":1,...
```

Three separate raw-PK oracles, all in **200/422 responses from live routes**:
the issue resource's `work_order_id` matches the real work-order PK exactly; the
insufficient-stock message enumerates the raw item and location PKs; and the
picking payload uses raw ids for item, location and work order throughout
(`PickingListService.php:33-39,46-66,79-107`). Confirms prior **M042-F10**.

### M042-N08 — Incomplete (P2) — MEASURED: three roles read picking lists without holding `inventory.picking.view`

```
[I24] warehouse_staff:     index=200 show=200 picking=200 store=201  [view=y issue=y picking=y]
[I24] system_admin:        index=200 show=200 picking=200 store=201  [view=y issue=y picking=y]
[I24] qc_inspector:        index=200 show=200 picking=200 store=403  [view=y issue=n picking=n]
[I24] production_manager:  index=200 show=200 picking=200 store=403  [view=y issue=n picking=n]
[I24] purchasing_officer:  index=200 show=200 picking=200 store=403  [view=y issue=n picking=n]
[I24] employee:            index=403 show=403 picking=403 store=403  [view=n issue=n picking=n]
```

**Both registry roles complete their part** (`system_admin`, `warehouse_staff`
get 200/200/200/201) — no repeat of the `finance_officer` shape from N5 next
door. `employee` is correctly refused everywhere. But three roles that do **not**
hold `inventory.picking.view` still get **200** on picking, because the route
gates on `inventory.view` (`routes.php:129`). The permission exists and is
seeded to `warehouse_staff`; the gate just does not use it.

### Reservation lifecycle gaps (measured)

- **Orphaned by a vanishing work order.** `[I12] reservation for a nonexistent
  WO: rows=1, level.reserved=25.000; FK on material_reservations.work_order_id =
  NONE.` The reservation keeps consuming 25 units of availability forever.
- **Nothing ages it.** `[I27] material_reservations cols: id,item_id,work_order_id,
  location_id,quantity,status,reserved_at,released_at,created_at,updated_at` —
  no `expires_at`, and zero scheduled commands reference reservations. There is
  no ager, so neither failure mode (a lying exit code, or nothing at all) applies
  in the 8D sense: this is the `material-review-board` shape.
- **Cancelling an issued slip does not restore the reservation.**
  `[I15] after cancel of an issued reservation-backed slip: res.status=issued,
  released_at=set, level.reserved=0.000, qty=200.000.` The stock came back but
  the work order's protection did not — the only code that resets a reservation
  is the `Draft` branch (`MaterialIssueService.php:200-216`), which **no HTTP
  path can reach** because `create()` hard-codes `Issued` (`:67`). Confirms prior
  **M042-F05**.

### Status machine — 6 cells walked

```
[I14] cancel(draft)     = OK, qty 99.000 -> 99.000   (correct: no stock to reverse)
      cancel(issued)    = OK, qty 98.000 -> 99.000   (correct: reversal posts)
      cancel(cancelled) = BusinessRuleException       (correct)
      issue(vs reserved reservation) = OK, res -> issued
      issue(vs issued   reservation) = OK, res -> issued   ✗ replay accepted
      issue(vs released reservation) = OK, res -> issued   ✗ a RELEASED reservation
                                                             is re-consumable and
                                                             flips back to issued
```

3 slip-status cells × cancel, plus 3 reservation-status cells × create. The two
reservation cells marked ✗ are guard gaps, not transitions — `if ($res)` is the
whole check. The `released → issued` cell is a state regression: a reservation
that was deliberately given up is silently reactivated and re-released.

### Invariants that HOLD (measured, and worth recording as such)

- **`reserved ≤ on_hand` cannot be violated by an adjustment either.**
  `[I11] refused: InsufficientStockException — needed 60, available 0.000.`
  An `adjustment_out` of 60 under a 100-unit reservation is refused, because
  `move()` applies the same availability rule to every source movement.
- **Return-to-stock preserves WAC.** `[I16] wac after receipt=32.5000; after
  cancel-reversal=31.8182 (qty 110.000); value-neutral expectation=31.8181.`
  Reversing at the original unit cost is value-neutral; the 0.0001 gap is my
  expectation being computed with `bcdiv` truncation against the service's
  half-up `round4()`, not a divergence in the service. **No defect** — and
  cancelling twice is refused, so it cannot be returned twice.
- **Quantity validation is CLEAN — the `numeric|min:0` family does NOT reproduce.**
  My prediction going in was that it would not, and that is what I measured:

  ```
  [I22] 1.999 => 201 stored=1.999   (exact, no truncation to 2.00)
        1e3 => 422 | 1e17 => 422 | 1e20 => 422 | 10.00005 => 422
        0 => 422 | -5 => 422 | 0.0001 => 422 | array payload => 422
  ```

  `['required','decimal:0,3','min:0.001']` (`StoreMaterialIssueRequest.php:41`)
  is the correct shape — the same one that made `material-review-board` clean.
  No overflow, no silent truncation, no `ValueError`, and a map payload is
  rejected rather than coerced. **This prior-finding family is disproved here.**

### M042-N07 — MEASURED: the reservation id contract is inverted

```
[I23] reservation hash_id (dGypLxpvAg) => 422
      {"message":"The items.0.material_reservation_id field must be an integer."}
      raw pk (1) => 201
```

The API **refuses** the hash id and **accepts** the raw internal PK — the exact
inverse of the project-wide contract. `material_reservation_id` is absent from
`hashIdFields()` (`StoreMaterialIssueRequest.php:22-29`). Any client following
the documented convention cannot use the reservation feature at all.

### Self-cancellation (question, not filed as a defect)

```
[I25] same warehouse user created (201) and cancelled (200) its own issue
```

One `warehouse_staff` user both created and reversed its own stock movement, with
no second party and no reason recorded (the route takes a bare `Request` and
`cancel()` persists no actor/reason — `MaterialIssueSlipController.php:43-47`).
For a warehouse correcting its own slip this may well be intended. **Question for
the process owner**: does reversing an already-posted stock movement and its GL
entry require a checker, as journal entries do? Filed as a question, not a
finding, because a maker-checker requirement here is a policy decision.

## Prior-work assessment — how many of the 15 prior findings reproduce

The prior three sessions wrote 15 findings, applied **zero** fixes, and honestly
self-flagged that nothing was runtime-verified. On first real execution:

| Prior | Verdict |
|---|---|
| F01 lifecycle decision | **Stands, and sharpened** — `create()` hard-codes `Issued` (`:67`) and the only reservation-releasing code is the unreachable `Draft` branch. Still a genuine process-owner question. |
| F02 availability checked before release | **REPRODUCES** (I5) — measured refusal, `available 0.000`. |
| F03 reservation identity unvalidated | **REPRODUCES and is worse** — measured cross-work-order destruction (I7), replay (I8), and a 2× over-consume under real concurrency (Race 2). |
| F04 WO actuals not updated | **REPRODUCES** (I18) — all actuals columns stayed 0 after issuing 8× the BOM. |
| F05 reversal provenance / reservation not restored | **REPRODUCES** (I15). |
| F06 no idempotency boundary | **REPRODUCES** — Race 2 committed two slips for one reservation; nothing keys on an operation id. |
| F07 truncation vs half-up | **REPRODUCES** (I26) — but only at a deliberately chosen value; my first, non-discriminating probe showed no divergence. |
| F08 missing FKs / checks | **REPRODUCES** (I12) — `FK on material_reservations.work_order_id = NONE`. |
| F09 inactive/blocked sources issuable | **PARTLY REPRODUCES** — measured for **soft-deleted** item *and* location (I19, accepted). `is_active`/`is_blocked` not separately probed — see "could not verify". |
| F10 raw internal IDs exposed | **REPRODUCES** (I20, I21) — three separate oracles in live 200/422 bodies. |
| F11 reservation/lot/UOM not end-to-end | **REPRODUCES** (I23) — hash id 422, raw PK 201. |
| F12 picking authorization too broad | **REPRODUCES but DOWNGRADED to P2** (I24) — real privilege broadening (3 roles get 200 without the permission), but not an impossible approval chain, and both registry roles work. |
| F13 FEFO is a movement heuristic | **Stands by reading** — not runtime-probed; the picking payload does present a lot/expiry as exact. |
| F14 no cancel UI | **Stands by reading** — no cancel method in `spa/src/api/inventory/material-issues.ts`. |
| F15 list filters/action incomplete | **Stands by reading** (Polish). |

**13 of 15 reproduce or are sharpened; 1 downgraded (F12, P1→P2); 1 partly
verified (F09).** Plus **6 new findings** the prior sessions missed
(N02a/N02b/N02c cross-reservation corruption, N10 any-WO-state, N13 mutability,
N14 archived-item fail-open, N12 two disjoint issue paths, and the out-of-module
`DocumentSequenceService` race).

**And one prior-finding family disproved:** the `numeric|min:0` validation
pattern does not apply here (I22) — as I predicted before probing, so that
prediction is confirmed rather than refuted.

## Critical interaction — why these must be fixed TOGETHER, not incrementally

**Fixing N01 alone would amplify N02b.** N01's spurious refusal (a
reservation-backed issue rejected because `move()` sees `available 0`) is today
accidentally *limiting the blast radius* of N02b: many of the over-release paths
never execute because the issue is refused first. Correct the ordering — release
the reservation before the movement — without also fixing the per-reservation
accounting, and issues that currently fail safely would start succeeding **and
over-releasing other work orders' reservations**. That is a strictly worse state
than today.

This is the single strongest argument for handing the reservation cluster off as
one unit of work rather than fixing the "obvious" ordering bug in isolation.

## Verification evidence (this session)

- Baseline `tests/Feature/Inventory`: **177 passed / 621 assertions / exit 0**.
- Probe suite `ZzM042ProbeTest`: 24 probes executed across 5 runs; all reported
  results above are from runs with **non-zero assertions and exit 0** except
  where a probe error is stated and then fixed and re-run.
- Race probes: 3 scenarios, two concurrent OS processes each, persistent database.
- The database container was cycled by another session mid-run once ("the database
  system is starting up", 0 assertions — the documented tell). That run was
  discarded and re-executed after the container returned healthy; no result above
  comes from it.

## Could NOT verify (stated plainly)

- **`items.is_active` / `warehouse_locations.is_active` / `is_blocked`** as
  distinct from soft-deletion (prior F09's other half). I measured the
  soft-delete case only.
- **SPA behaviour.** No browser or component test was run; all SPA claims in this
  report are from reading source, not execution.
- **FEFO/lot correctness (F13)** beyond observing that the picking payload
  presents a lot as exact.
- **GL figures downstream of the centavo divergence (N09)** — I measured the
  movement/slip disagreement but did not trace it into `journal_entry_lines`.
