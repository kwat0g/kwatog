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
