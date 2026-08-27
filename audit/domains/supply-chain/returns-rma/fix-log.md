# M046 — fix log

## Historical audit session — 2026-08-25

- No application source fixes were applied during the earlier audit session. The module was left `📋 Plan Ready` for dedicated implementation work.
- That session recorded 55 backend tests / 273 assertions and a passing SPA typecheck at its then-current source revision.

## Fresh audit session — 2026-08-25

- The earlier report/plan were invalidated because the module source had substantial uncommitted changes after that report and those changes were not represented in this log.
- Re-ran discovery, hardening, and polish against the current working tree. No production source files were changed by this session.
- PHP syntax passed for the Return Management API files.
- Current SPA typecheck is not green: `spa/src/pages/return-management/detail.tsx:902` has duplicate `minLength` JSX attributes, in addition to unrelated asset QR-code errors.
- The shared backend test database was not stable enough for a clean rerun under parallel sessions; the refreshed report records the observed result and the verification limitation.
- Open findings and the ordered handoff are in `audit-report.md` and `action-plan.md`.

## Implementation session — 2026-08-25

- **RMA-001 fixed:** `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:239-250` now preserves the associative source-kind keys before resolving the source ID, so invoice, sales-order, and delivery branches receive their intended kind and authoritative source limit.
- **RMA-003 fixed:** `api/app/Modules/ReturnManagement/Requests/StoreReturnRequestRequest.php:155-163` and `ReturnRequestService.php:249-254` require product provenance for non-finance stockable customer returns; `spa/src/pages/return-management/create.tsx:145-150,183-195,218-225` now preserves and submits the selected source product for create and draft-edit flows.
- Added API regression coverage for invoice, sales-order, and delivery source resolution, authoritative prices, source allocations, and missing product provenance in `api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php:573-735`.
- Verification: PHP syntax checks, `git diff --check`, and `spa/npm run typecheck` passed. The focused API tests could not execute because the configured PostgreSQL test host `db` was unavailable (`SQLSTATE[08006]`, `ogami_test`); no passing test result is claimed.
- Deferred from the next ordered item: **RMA-002/RMA-004** require a product/operations decision on invoice-less SO/delivery credit-note policy and the downstream Replace/Refund outcomes. I did not guess that policy. Items 4-11 remain pending behind that decision and the unavailable database verification.

## Runtime-verification session — 2026-08-27

Context: the previous session's RMA-001/RMA-003 code landed but was **never executed**
(`SQLSTATE[08006]`, no database). `migrate:fresh` works now, so this session ran the
module against a private database (`ogami_test_rma`) and worked the observed failures.

Starting point measured, not assumed — 17 failures across 8 classes:
`ReturnRequestScenarioTest` 5, `CustomerReturnRestockOnDisposeTest` 3,
`CustomerReturnRestockCostTest` 2, `SupplierReturnShipOnDisposeTest` 2,
`DispositionTest` 1, `SupplierReturnLifecycleTest` 1,
`Notifications\SupplierReturnShipNotificationTest` 3.

They are **four independent clusters**, not one. In particular the 500s and the 422s do
NOT share a cause (evidence in the cluster notes below).

### Cluster A — `settledQuantity()` ignored a recorded receipt count (PRODUCTION BUG, money + stock)

`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:954-959` (before) →
`:950-980` (after).

Before:

```php
return (bool) $item->receipt_recorded
    ? (string) $item->returned_quantity
    : (string) $item->quantity;
```

After: the flag stays authoritative when set (so `receive()`'s explicit zero remains a
recorded no-return), but a **positive `returned_quantity` with the flag unset is also a
receipt**, and only zero falls back to the requested quantity.

Why this is the service's defect and not a fixture gap — the flag's own migration,
`api/database/migrations/2026_08_25_190000_harden_return_source_allocations.php:29-34`,
declares that exact invariant and backfills it:

> "Existing positive counts were explicit physical receipts in the pre-flag schema.
> Zero remains intentionally unrecorded because the old default cannot distinguish
> 'not counted' from 'none returned'."
> `->where('returned_quantity', '>', 0)->update(['receipt_recorded' => true])`

`settledQuantity()` was the one reader that did not honour it. Consequences, both real:

- **Stock:** the customer restock leg is a `Transfer`/`Scrap` *out of quarantine*, and
  quarantine holds `returned_quantity`. Asking for `quantity` made the ledger refuse
  goods that never arrived — `InsufficientStockException: needed 10.000, available 8.000`.
- **Money:** `creditableAmount()` and the credit-note line amount
  (`ReturnRequestService.php:1161`) both read `settledQuantity()`, so the customer was
  credited for 10 units when 8 came back — ₱200 over-credit on a ₱100 line. The
  regression test that existed to catch exactly this
  (`test_customer_credit_note_is_based_on_the_returned_quantity`, comment
  "8 returned × 100.00 = 800.00 credited, NOT the 10 originally requested") had been
  silently disabled by the flag gate.

No costing behaviour was touched: weighted-average cost is still inherited from the
destination level, and the WAC assertions in `CustomerReturnRestockCostTest` pass
unchanged. Only the quantity that moves was corrected.

### Cluster B — supplier-credit fixtures built an unposted Bill (TEST fixture gap)

7 of the 17 failures were one message: `The target bill does not have a posted journal
entry.`, raised by `api/app/Modules/Accounting/Services/CreditNoteService.php:283-288`.

This is a correct Accounting invariant — a vendor credit cannot be applied to a bill that
never reached the GL — and Accounting is a dependency module I must not modify. The
fixtures were creating a `Bill` with no `journal_entry_id`, which is not a state the
billed-GRN path can produce in production.

Fixed by reusing the existing canonical fixture rather than inventing one: a
`postedJournalEntry()` helper copied from
`api/tests/Feature/Accounting/CreditNoteTest.php:61-73`, wired into each bill.

- `api/tests/Feature/ReturnManagement/SupplierReturnLifecycleTest.php:64-88` (helper),
  `:159` (`'journal_entry_id' => $this->postedJournalEntry($by)->id`)
- `api/tests/Feature/ReturnManagement/SupplierReturnShipOnDisposeTest.php:56-80` (helper),
  `:151`
- `api/tests/Feature/ReturnManagement/DispositionTest.php:55-79` (helper), `:407`
- `api/tests/Feature/Notifications/SupplierReturnShipNotificationTest.php:59-83` (helper),
  `:172`

This also answers the brief's question directly: **the 422s and the 500s did NOT share a
cause.** The 500s were Cluster A (`InsufficientStockException` on the quarantine issue
leg); the 422s were this unposted-bill fixture gap. They only looked related because both
surface on `dispose()`.

### Cluster B2 — `moved_quantity` test expectations still assumed the old float sum

`ReturnRequestResource::moved_quantity` (`:96-103`) used to sum through `(float)`, which
stringified to `'8'`. It was correctly rewritten to `bcadd(..., 3)` — the decimal-safe
form audit finding RMA-011 asks for, and consistent with the per-line
`ReturnRequestItemResource::moved_quantity` (`:53-56`), which returns the raw
`decimal(12,3)` string. The two tests asserting the trimmed float output were never
updated.

Corrected the expectations, NOT the resource — reintroducing a float sum to make a string
comparison pass would undo RMA-011 and put quantities back through a float:

- `api/tests/Feature/ReturnManagement/CustomerReturnRestockOnDisposeTest.php:193`
  `'8'` → `'8.000'`
- `api/tests/Feature/ReturnManagement/SupplierReturnShipOnDisposeTest.php:256`
  `'18'` → `'18.000'`

### Cluster C — scenario fixtures contradicted the finance-only disposition matrix

`DispositionType::allowedFor()` narrows a finance-only RMA to `no_return` only, and keeps
`return_to_supplier` off the customer matrix entirely. `DisposeReturnRequest::rules():36-46`
enforces that at the boundary. Three fixtures predated it.

Added one shared helper instead of repeating the three-column `forceFill`:
`ReturnRequestScenarioTest::asFinanceOnly()` at
`api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php:113-134`.

1. `test_customer_credit_note_is_based_on_the_returned_quantity` (`:281-307`) — had
   `finance_only = true` **and** disposition `restock`, which the matrix refuses. Now uses
   `no_return`, the one finance-only disposition, and drops the pointless `location_id`.
   The ₱800.00 assertion is unchanged and is now the regression guard for Cluster A.

2. `test_scrapped_lines_are_not_credited_back_to_the_customer` → renamed
   `test_a_customer_line_cannot_be_routed_onward_to_the_supplier` (`:309-334`). The method
   name never described the body: it has posted `return_to_supplier`, not `scrap`, since
   the file was written (`git show ad159dd4`). That disposition is now unreachable on a
   customer RMA by design, so the test asserted an impossible path. It now asserts the live
   guard — 422 with a `dispositions.0.disposition` error — while keeping the original
   `assertNull($rma->credit_note_id)` intent, plus `disposition_status` stays null.
   **No production behaviour was changed here.** See the open question below on whether
   scrapped lines should be creditable, which the stale name hints at but does not settle.

3. `test_submit_fails_loudly_when_the_approval_chain_cannot_be_opened` (`:476`) and
   `test_approve_reports_failure_instead_of_silently_doing_nothing` (`:492`) — built an
   RMA whose line has no item, no product and no source line, then submitted it as a
   *stockable* return. Both now go through `asFinanceOnly()`.
   Note the first of these was **passing for the wrong reason**: it expects 422 from the
   missing workflow definition but was getting 422 from `resolveSource()`'s
   "Choose exactly one invoice, sales-order, or delivery line". It now fails/passes on the
   condition it names.

### Cluster D — the previous session's new tests violated `invoice_items` NOT NULL

The RMA-001/RMA-003 coverage added on 2026-08-25 was never executed, so two of its
fixtures had never hit the database. `invoice_items.revenue_account_id` and
`invoice_items.description` are both NOT NULL.

- Added `revenueAccountId()` helper (account 4010, matching the other RMA fixtures) at
  `api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php:75-83`, plus the
  `Account` import at `:7`.
- Supplied `revenue_account_id` + `description` on both `InvoiceItem::create()` calls:
  `:600-608` and `:642-650`.

### Plan item 6 — RMA-007 the `return_management` feature flag was bypassable

The feature was seeded (`SettingsSeeder.php:452`) and the fail-closed middleware existed
(`app/Common/Middleware/CheckFeature.php`), but neither the API nor the browser routes
consumed it. Disabling the module hid the sidebar entry and left every RMA endpoint and
URL reachable to anyone still holding `return_management.*`.

- `api/app/Modules/ReturnManagement/routes.php:13` — `['auth:sanctum']` →
  `['auth:sanctum', 'feature:return_management']`, matching the other feature-gated module
  groups. The gate wraps the whole group so the per-action permission checks stay nested
  *inside* the feature boundary.
- `spa/src/routes/advancedRoutes.tsx:45-52` — the four RMA routes are now wrapped in
  `<Route element={<ModuleGuard module="return_management" />}>`, the same shape the
  forecasting and budgeting blocks already use.

Coverage added at `api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php:756-798`
— a read (`GET /return-requests`) and a write (`POST .../dispose`) both assert 403 with
`code: feature_disabled`, and the write additionally asserts the RMA was not mutated.

### Plan item 9 — RMA-012 the SPA type gate was red

`spa/src/pages/return-management/detail.tsx:901-902` passed `minLength={10}` to
`ReasonDialog` twice (`TS17001`). Removed the duplicate; the surviving prop is unchanged,
so the 10-character rejection-reason rule is preserved.

### Plan item 10 — RMA-013 retry control was gated inconsistently by role

The page-header "Retry Quality handoff" button used `canInspect`
(`detail.tsx:431`) while the identical action inside the warning banner used `canManage`
(`:581`), so a QC inspector saw a retry affordance in one placement and not the other. The
backend route is `permission:return_management.inspect`
(`api/app/Modules/ReturnManagement/routes.php:32`), so `canInspect` is the correct policy.

`spa/src/pages/return-management/detail.tsx:581` — `canManage` → `canInspect`, with a
comment naming the shared policy so the two placements do not drift again.

### Plan item 5a — RMA-008 disposition completeness is now a service invariant

`DisposeReturnRequest::withValidator()` was the only place requiring a one-to-one
disposition map. `dispose()` itself `continue`s past any line it has no entry for
(`ReturnRequestService.php:886-888`) and then marks the RMA `disposed` (`:937`), so any
non-HTTP caller — queued workflow, console command, future controller — could terminalise
an RMA and strand the undecided lines. Disposition is one-shot and irreversible (credit
note, GRN reversal, stock movement), so the invariant belongs on the service.

`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:988-1042` —
`assertDispositionMatrix()` now also rejects a duplicate line ("Each return line may take
only one disposition.") and an incomplete set ("Every return line needs a disposition — N
line(s) are undecided."). It already ran before every side effect (`:860`), so no call-site
change was needed.

Coverage at `api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php:783-838`
drives the SERVICE directly (not HTTP) for both the partial set and the duplicate line, and
asserts no side effect survives the refusal — `disposition_status`, `credit_note_id` and
every line's `disposition` stay null.

### Plan item 5b — RMA-011 float/int quantity decisions removed

RMA quantities are `decimal(12,3)`. Three places converted them through `float`/`(int)`
before making a decision. None is money, but each changes a real output at the boundary.

1. **Quality batch size** — `ReturnRequestService.php:783-792`. Was
   `(int) ceil((float) $productItems->sum(...))`. The float step can read an exact `3.000`
   as `3.0000000004` and ceil it to 4, inflating the AQL sample. Now sums with `bcadd` at
   3 dp and rounds up once on the exact decimal string.
2. **PO receipt status** — `:1168-1182`. Was `(float)` sums compared with `<`, which can
   classify a fully-received fractional PO as `partially_received` (or the reverse). Now
   `bccomp(..., 3)`.
3. **NCR affected quantity** — `:911`. Was `(int)` on the raw quantity, i.e. truncation:
   8.4 affected units were reported as 8, understating the defect in the Pareto data. Now
   rounds up.

Extracted `wholeUnits(string): int` at `:988-1001` so the ceiling rule exists once, and
routed 1 and 3 through the existing `settledQuantity()` instead of repeating its
`returned_quantity > 0 ? … : quantity` expression inline — same rule, one definition, and it
now honours a recorded explicit zero.

Coverage at `api/tests/Feature/ReturnManagement/ReturnInspectionHandoffTest.php:266-330` —
a data provider driving `inspect()` with fractional line quantities and asserting the
resulting `Inspection::batch_quantity` in both directions: `1.500 + 1.500 → 3` (must NOT
inflate to 4) and `1.200 + 2.200 → 4` (must NOT truncate to 3), plus a single sub-unit line
`0.400 → 1`.

### Plan item 4 — RMA-005 stale reservations and RMA-006 missing database guards

**RMA-005 — a draft-time reservation was never re-checked.**
`refreshAuthoritativeLineContract()` called `reserveSource()` only when NO active
allocation existed, so an allocation created against 5 available units survived the
source document being cut to 1 and still passed submit — reserving more than the source
could back.

- `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:364-388` — extracted
  `remainingSourceQuantity(kind, sourceId, excludeAllocationIds)` from the body of
  `reserveSource()`, so the availability arithmetic exists once and a re-check can exclude
  the allocation being re-checked (otherwise a line competes with itself and every submit
  fails).
- `:415-450` — new `revalidateSourceAllocation()`. A changed source selection releases the
  old allocation and re-reserves; an unchanged source is re-measured against the current
  limit and refused if it no longer fits; a still-valid reservation is realigned to the
  line quantity rather than duplicated.
- `:521-528` — submit now revalidates instead of skipping.

**RMA-006 / RMA-005 — database backstops.**
New migration
`api/database/migrations/2026_08_26_040000_harden_return_request_status_and_allocations.php`:

- `return_requests.status` CHECK over the eight `ReturnRequestStatus` values. Migration
  2026_08_13_221000 guarded `inspection_handoff_status` and `quarantine_status` on this
  table but skipped `status` itself, so a direct writer could persist an unsupported value
  and the failure surfaced later as an enum-hydration error on read.
- `return_request_source_allocations`: `quantity >= 0`, `unit_price >= 0`, and
  `source_kind IN (invoice_item, sales_order_item, delivery_item, grn_item)`. An
  unresolvable kind previously went undetected until `sourceLimit()` threw
  "Unsupported return source line" on the next reservation.

It fails loudly (listing the offending row count, changing nothing) rather than silently
skipping if pre-existing data violates a guard, and is idempotent + reversible.

**Naming note — worth reading before adding the next migration.** This was first written
as `0479_harden_...` per the "highest numbered + 1" convention, and the allocation guards
silently did nothing. Laravel orders by full filename, so every `04xx_` file sorts BEFORE
every `2026_` file ('0' < '2'), and `return_request_source_allocations` is created by
`2026_08_25_190000`. The table did not exist when `0479_` ran, so `Schema::hasTable()`
returned false and three of the four guards were skipped — invisibly, because the
`return_requests` guard *did* apply (that table dates from `0158_`) and the migration
reported success. A numbered migration cannot constrain a table created by a
timestamp-style one; hence the timestamp name, explained in the file header.

### Plan item 8 — RMA-010 source availability was optimistic and reservations invisible

`sourceOptions()` advertised the raw document quantity and the SPA labelled it
"available", while the authoritative rejection only happened later inside
`reserveSource()`. Two operators saw the same headroom, the second got a late submit
error, and neither could see the reservation that caused it.

- `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:364-397` — new
  public `activeAllocationsBySource(kind, sourceIds)`: reserved quantity per source line,
  one grouped query per kind (never per line), excluding released allocations and
  rejected/cancelled RMAs — the same predicate `remainingSourceQuantity()` uses, so the
  displayed figure cannot disagree with the one that enforces.
- `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php:94-140` —
  `reservedFor()` + `remainingOnLine()` helpers; `:150-215` and `:243-270` add
  `remaining_quantity` to invoice, sales-order, delivery and GRN lines. PO and bill lines
  deliberately get none: `sourceLimit()` resolves no PO/bill kind, so a number there would
  imply a reservation that does not exist. Clamped at zero.
- `api/app/Modules/ReturnManagement/Resources/ReturnRequestItemResource.php:26-43` —
  `source_allocation` (kind, quantity, unit price, reserved-at) behind `whenLoaded`, so the
  list endpoint pays nothing; eager-loaded in `show()` only
  (`ReturnRequestController.php:400`).
- `spa/src/types/returnManagement.ts:140-156` — `remaining_quantity` on
  `ReturnSourceLine`, documented as optional and 3 dp.
- `spa/src/pages/return-management/create.tsx:175-186` — labels now read the reservable
  amount ("3.000 returnable of 5.00 delivered") with a fallback to the document quantity.

Precision note: `remaining_quantity` is reported at 3 dp (the precision of
`return_request_source_allocations` and of an RMA line) while the sibling `quantity` keeps
its own document's cast — `invoice_items.quantity` is decimal:2. The two therefore differ
in trailing zeros on the same line. That is deliberate and is commented at both the
producer and the type; consumers must compare numerically.

Coverage at `api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php:995-1055` —
asserts the full line is reservable before any RMA, that creating a 2-unit RMA drops
`remaining_quantity` 5.000 → 3.000 while `quantity` stays 5.00, and that the reservation is
then traceable from the RMA detail response.


### Verification

Module suite on a private database (`ogami_test_rma`):

```
docker compose exec -T -e DB_DATABASE=ogami_test_rma api php artisan test \
  --filter='ReturnManagement|SupplierReturnShipNotificationTest|ReturnRestockNotificationTest|ChainBottleneckServiceTest'
Tests:    89 passed (426 assertions)
Duration: 63.62s
```

Starting point was **17 failed**. `RefreshDatabase` runs `migrate:fresh`, so this run is
also proof that the new migration does not break the repo-wide migration sequence.

SPA gates (run with the repo's local toolchain, no container needed):

```
spa$ node_modules/.bin/tsc --noEmit
src/pages/assets/detail.tsx(6,20): error TS2307: Cannot find module 'qrcode' …
src/pages/assets/detail.tsx(67,14): error TS7006: Parameter 'dataUrl' implicitly has an 'any' type.
```

Those two are the pre-existing assets QR-code errors the audit report explicitly scoped out
of M046. **No return-management errors remain** — the `detail.tsx:902 TS17001` that made this
module's gate red is gone.

```
spa$ node_modules/.bin/eslint src/pages/return-management/{detail,create}.tsx \
        src/routes/advancedRoutes.tsx src/types/returnManagement.ts --max-warnings 0
(clean, exit 0)
```

`php -l` clean on every changed PHP file.

Known unrelated failures observed while checking for collateral damage — NOT caused by this
slice, NOT in this module:

- `Dashboard\BadgeControllerTest::test_widened_scope_badge_counts_track_recent_rows` —
  `uq_emp_training_assignment_key` unique violation on an `employee_trainings` fixture.
  Nothing here touches training. `ChainBottleneckServiceTest`, the only other suite
  referencing `return_request`, is green.
- `spa` prettier reports the return-management pages as unformatted — but so are the files
  this session did NOT edit, and `advancedRoutes.tsx` is indented one space throughout.
  Pre-existing repo-wide state; reformatting would bury a 15-file review diff in
  whitespace, so it was left alone.

## Deferred — and why

Two plan items are **blocked on a human business decision**, which the audit report already
raised as questions for product/operations. I did not guess them; both change money
movement.

**Plan item 3 — RMA-002 + RMA-004 (customer resolution / credit policy).**

- *RMA-002*: `dispose()` creates a customer credit only when the RMA has an `invoice_id`
  (`ReturnRequestService.php:913`). A customer return sourced from a sales order or delivery
  with no root invoice therefore reaches a terminal state with **no credit note at all** —
  silently. The API and SPA offer SO and delivery sources, and `createCreditNote()` already
  accepts those source variants, so the code supports both policies at once.
  **Question:** should an invoice-less SO/delivery-sourced return issue a credit note, or
  must invoice provenance be mandatory before approval? Either answer is a small change;
  choosing wrong either strands a customer credit or books one with no invoice to apply it
  against.
- *RMA-004*: `Replace` and `Refund` are offered as resolutions (seeded by
  `0334_seed_return_option_settings`) and persisted, but nothing downstream consumes them.
  `replacement_wo_id` exists on the model and migration with no relation and no writer, and
  there is no refund path anywhere in the module. An operator can select Replace or Refund
  and reach a terminal RMA with the chosen outcome never executed and never flagged as
  pending work. **Question:** implement customer replacement work orders + refund
  settlement, or withdraw those two options and surface an explicit "pending manual
  outcome"? Leaving them selectable but inert is the one option that is definitely wrong.

**Plan item 7 — RMA-009 (supplier draft contract).** The service comments promise that a
supplier draft may exist before the PO/GRN selectors are filled
(`ReturnRequestService.php:154-168`), but the code still requires a unit price and the SPA
schema requires a source line per non-finance supplier line. So the documented incomplete
draft is unreachable through the UI, and an API caller can only reach it by inventing a
provisional price. **Question:** support a genuinely incomplete supplier draft (nullable,
clearly provisional price, no source reservation), or require source-complete creation and
delete the service's promise? This decides whether a reservation may exist without a price —
which the new `unit_price >= 0` guard now has an opinion about.

### Open question surfaced by this session (no code changed)

`test_scrapped_lines_are_not_credited_back_to_the_customer` has carried that name since the
file was written, while its body has *always* posted `return_to_supplier`, never `scrap`
(verified with `git show ad159dd4`). The name asserts a rule the code does not implement:
`createCreditNote()` skips only `null` and `return_to_supplier` dispositions, so a
**scrapped customer line IS credited**.

That reads correct to me — the customer returned goods and is owed the credit whether or not
we can resell them; scrapping is our loss — which would make the `dispose()` docblock claim
that scrapped lines are excluded (`ReturnRequestService.php:915-917`) the wrong part. I did
**not** change the behaviour or that comment, because it is a money decision.
**Question for finance:** confirm scrapped customer returns are creditable. If yes, the
docblock should be corrected; if no, `createCreditNote()` needs `scrap` added to its skip
list and credit amounts change.

## Status

Released `🔁 Needs Re-audit` — not because anything is broken, but because plan items 3 and
7 are unimplemented pending the decisions above. Items 1, 2, 4, 5, 6, 8, 9, 10 and 11 are
implemented and runtime-verified.

## Re-audit session — 2026-08-27

- Claim: `supply-chain/returns-rma` (M046), acquired with `audit/scripts/claim-module.sh`.
- No production source changes were made. The existing implementation fixes were rechecked
  with the focused Return Management/notification suite: **72 tests, 324 assertions passed**
  using only `DB_DATABASE=ogami_test_m046_roll_c`.
- PHP lint, focused RMA ESLint, and `git diff --check` passed. SPA typecheck still reports
  only the unrelated `src/pages/assets/detail.tsx` missing `qrcode` module/implicit-any errors.
- Current findings recorded in `audit-report.md`: RMA-014 source status checks are picker-only;
  RMA-015 accepts quantity precision beyond `decimal(12,3)` (PostgreSQL rounds 1.0009 to 1.001);
  RMA-016 source options stop at 100 documents per source type.
- Status remains `📋 Plan Ready`; RMA-002/RMA-004/RMA-009 require business decisions and the
  remaining implementation is separate-session work. No fix-log entry was needed for a source
  fix because none was made.







