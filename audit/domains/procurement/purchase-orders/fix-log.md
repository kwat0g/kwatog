# M037 — Purchase Orders Fix Log

Session: 2026-08-25  
Claimed module: `procurement / purchase-orders`  
Final status: `🔁 Needs Re-audit`

## Session work

### M037-F01 — critical auto-PO lifecycle

File: `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:44-180`

Before: the live path used the unregistered sequence key `po`, wrote the
non-enum/non-constraint state `pending_vp`, used float currency arithmetic, did
not create `ApprovalService` records, and notified configured VP roles even
though the canonical PO workflow starts with Purchasing/Finance.

After: the service locks the item and preferred supplier inside one transaction,
uses `PurchaseOrderStatus` values and the registered `purchase_order` sequence,
calculates subtotal/VAT/total with `Money`, submits the PO through
`ApprovalService`, and notifies the first canonical approval role. The path now
enters `pending_approval` and uses the existing PO approval records.

Verification: `php -l` passed. Added
`api/tests/Feature/Purchasing/AutoPurchaseOrderServiceTest.php:48-98` covering
the enum state, money total, approval records, rejection of `pending_vp`, and
retry idempotency. The test could not reach assertions because the shared
`ogami_test` PostgreSQL database had no `migrations` table.

Policy still pending: the process flow and Inventory caller promise a VP-routed
bypass (`docs/PROCESS-FLOWS.md:488-493`,
`api/app/Modules/Inventory/Services/AutoReplenishmentService.php:49-54`), while
the selected implementation uses the canonical workflow seeded at
`api/database/seeders/WorkflowSeeder.php:68-74`. This requires a product
decision and isolated verification.

### M037-F05 — two-connection lifecycle race coverage

File: `api/tests/Feature/Purchasing/PurchaseOrderTwoConnectionRaceTest.php:44-175`

Before: the action plan called for concurrency evidence, but the module only
had same-process stale-model coverage; that cannot prove a losing request waits
on the authoritative PostgreSQL row lock before running its state guard.

After: added three PostgreSQL/`pcntl` harness tests covering stale draft update
versus submit, stale delete versus submit with the converted source PR left
untouched, and stale approval versus a concurrent terminal transition. The
tests use an explicit held PO-row lock and a second database connection, then
assert the losing operation cannot mutate or reopen state.

Verification: `php -l` and `git diff --check` passed for the new test. The
focused PHPUnit run could not reach setup because the configured PostgreSQL
host `db` is not resolvable; no race assertion ran.

## Current findings re-checked

The following production changes were already present in the shared worktree
when this session claimed the module; they were re-checked and not rewritten:

- F02: supplier PO DTO and portal types align at
  `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:48-62` and
  `spa/src/types/b2b.ts:46-59`.
- F03: PR-line IDs are carried and validated at
  `spa/src/pages/purchasing/purchase-orders/create.tsx:145-207` and
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:761-783`.
- F04/F05: restore, update, delete, and submit use trashed binding or locked
  re-reads at `api/app/Modules/Purchasing/routes.php:60-70` and
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:293-380,640-673`.
- F06/F07: incoterm and budget recalculation paths are present at
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:321-357` and
  `:326-337`.
- F08/F12/F14: lifecycle/permission/reason checks align at
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:423-520`,
  `api/app/Modules/Purchasing/Requests/RejectPurchaseOrderRequest.php:16-27`,
  and `spa/src/pages/purchasing/purchase-orders/detail.tsx:125-143,449-470`.
- F10: supplier FormData calls no longer set a manual multipart header at
  `spa/src/api/b2b/supplier.ts:165-185`.
- F13/F15: hash-string types and list/overdue filters align at
  `spa/src/types/purchasing.ts:126-203` and
  `spa/src/pages/purchasing/purchase-orders/index.tsx:146-207,315-321`.

## Scope correction

The supplier resource is a B2B dependency. An incidental capability edit was
backed out during this session so the claimed purchasing module did not modify
dependency code. The accepted-GRN capability mismatch remains logged as F09
and is explicitly deferred to a separately scoped B2B change.

## Verification performed

- Registry refreshed and M037 atomically claimed as the first eligible unlocked
  Tier-3 Plan Ready module after dependency ordering.
- `php -l api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php` —
  passed.
- `php -l api/tests/Feature/Purchasing/AutoPurchaseOrderServiceTest.php` —
  passed.
- `git diff --check` for the session implementation/test files — passed.
- `spa`: `npm run typecheck` — failed only on unrelated existing errors in
  `src/pages/assets/detail.tsx` (missing `qrcode` module and implicit `any`)
  and `src/pages/return-management/detail.tsx` (duplicate JSX attribute).
- Focused backend Purchasing/B2B run was not a valid product gate: the shared
  run reported failures across the selected classes and was interrupted; the
  isolated new test failed before assertions because the shared test schema was
  missing the `migrations` table.

## Deferred items and why

- F01 route choice and dedicated test verification: requires product decision
  and a usable isolated PostgreSQL schema.
- F09 supplier capability contract: cross-module B2B change, outside this claim.
- F11 purchasing-officer SoD overlap: requires human/RBAC policy decision and
  shared seeder changes.
- F16 direct-create contradiction: docs/API/UI policy decision required.
- HTTP, race, multipart, portal, and filter acceptance coverage: blocked by the
  shared test database and must run in the dedicated verification environment.

M037 therefore remains `🔁 Needs Re-audit`; it is not a fresh Plan Ready bounce.

---

# Fix log — re-audit 2026-08-30

Claimed `RECLAIMED`. Prior session's work was already committed in `167de85e`
but **verified by reading, not by probe** (its own log records the shared
`ogami_test` database had no `migrations` table). All measurements below are
against a dedicated PostgreSQL database `ogami_test_po`, since dropped.

Four contained fixes. Every one confirmed red against unmodified `HEAD` source
before being applied — baseline output quoted per fix.

## M037-F18 — bill variance flags were dropped by the PO projection

File: `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:129` → `:140`

Before:
```php
'bills:id,bill_number,total_amount,balance,status,purchase_order_id',
```

After (comment abridged; four columns added):
```php
'bills:id,bill_number,total_amount,balance,status,purchase_order_id,due_date,has_variances,three_way_overridden,three_way_match_snapshot',
```

`PurchaseOrderResource.php:97-100` reads all four. Because
`AppServiceProvider.php:229-240` deliberately omits
`preventAccessingMissingAttributes()`, the unselected columns read as `null`
instead of throwing, `(bool) null` became `false`, and
`Bill::threeWayReviewStatus()` fell through to `'matched'`.

Measured before (bill row genuinely `has_variances = true`,
`three_way_match_snapshot = {"overall_status":"blocked"}`):
```
[VARIANCE-FLAG] DB row: has_variances=true three_way_overridden=false snapshot={"overall_status":"blocked"} due_date=2026-09-30
[VARIANCE-FLAG] PO show() payload bills[0]={... "due_date":null,"has_variances":false,"three_way_overridden":false,"three_way_review_status":"matched"}
[VARIANCE-FLAG] threeWayReviewStatus() on a FULLY loaded Bill = manual_review
[VARIANCE-FLAG] SPA chip would render: SUCCESS/Matched (truth = WARNING/Variance)
```

Measured after: payload carries `has_variances: true`,
`three_way_overridden: false`, `due_date: "2026-09-30"`,
`three_way_review_status: "manual_review"`.

## M037-F19 — oversized money 500'd instead of 422

Files: `api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php:58,60`
and `api/app/Modules/Purchasing/Requests/UpdatePurchaseOrderRequest.php:47,49`

Before:
```php
'items.*.quantity'   => ['required', 'decimal:0,2', 'min:0.01'],
'items.*.unit_price' => ['required', 'decimal:0,2', 'min:0'],
```

After:
```php
'items.*.quantity'   => ['required', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
'items.*.unit_price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999.99'],
```

`decimal:0,2` already refused `1.999` and `1e3` (both 422 before and after — the
half of the sibling-module defect that was already closed here). The missing
piece was an upper bound. `decimal(15,2)` admits 13 integer digits and the line
total is a *product* of the two fields, so both are capped an order below the
column ceiling.

Measured before:
```
[MONEY unit_price=1.999]              status=422  (correct)
[MONEY unit_price=1e3]                status=422  (correct)
[MONEY unit_price=100000000000000000] status=500 SQLSTATE[22003]: Numeric value out of range ... numeric field overflow
[MONEY unit_price=999999999999999.99] status=500 SQLSTATE[22003]
[MONEY quantity=100000000000000000]   status=500 SQLSTATE[22003]
```
Measured after: all three overflow cases 422 with the correct field key;
`unit_price = 9999999.99` still posts 201.

## M037-F20 — raw integer primary keys in error bodies

File: `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:263,273,432`

Before / after:
```php
- "PR line {$line->id} has no vendor assignment."
+ 'PR line "'.($line->description ?? 'unnamed').'" has no vendor assignment.'

- "PR line {$line->id} has no authoritative unit price."
+ 'PR line "'.($line->description ?? 'unnamed').'" has no authoritative unit price.'

- "Vendor has no approved PPAP for item #{$line->item_id}. …"
+ $label = $line->item?->code ?? $line->description ?? 'unnamed item';
+ "Vendor has no approved PPAP for item {$label}. …"
```
The PPAP loop now eager-loads `item:id,code,name` so the label costs no N+1.

Measured before, from the conversion path:
`Expected: PR line 1 has no vendor assignment.`
Measured after: `PR line "Polypropylene resin" has no vendor assignment.`, and a
`/\bline \d+\b/` assertion confirms no bare PK remains.

## M037-F21 — cancelling an already-cancelled PO succeeded

File: `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:611-613`

Added after the received/closed guard:
```php
if ($row->status === PurchaseOrderStatus::Cancelled) {
    throw new BusinessRuleException('This purchase order is already cancelled.');
}
```

Measured before: three consecutive `cancel()` calls all succeeded, each
appending another `Cancelled: <reason>` block to `remarks`, re-running
`supplierDispatches->cancelForPurchaseOrder()` and
`reopenSourcePrIfLastLink()`, and recording another `PurchaseOrderCancelled`
message on the `p2p` outbox — one logical cancellation published repeatedly.
Measured after: the second call raises `BusinessRuleException` and `remarks` is
byte-identical to its post-first-cancellation value.

---

## Regression test

`api/tests/Feature/Purchasing/PurchaseOrderAuditHardeningTest.php` — 4 tests,
27 assertions. **Confirmed red against unmodified HEAD**: the three production
files were replaced with their `git show HEAD:` extracts and the suite re-run.

```
⨯ bill variance flags survive the purchase order projection
⨯ oversized money is a validation error not a database overflow
⨯ cancelling an already cancelled purchase order is refused
⨯ conversion errors do not leak raw primary keys
  Failed asserting that false is identical to true.
  Expected response status code [422] but received 500.
  Failed asserting that two strings are identical.
  Expected: PR line 1 has no vendor assignment.
  Tests: 4 failed (5 assertions)
```

All four go red, one per fix — none is a pass-either-way regression lock. The
fixes were then re-applied and the restoration proven byte-identical to the
tested state:
```
$ sha256sum -c /tmp/m037base/after.sha256
app/Modules/Purchasing/Services/PurchaseOrderService.php: OK
app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php: OK
app/Modules/Purchasing/Requests/UpdatePurchaseOrderRequest.php: OK
```
Re-run after restore: `Tests: 4 passed (27 assertions)`.

## Verification

- `php -l` — clean on all four files.
- `./vendor/bin/phpstan analyse <4 paths> --memory-limit=1G` — **`[OK] No errors`**.
- `./vendor/bin/pint --test` — the three production files fail. **Inheritance
  proven**: their `git show HEAD:` extracts were written to `/tmp/pintbase` with
  the repo's `pint.json` and produce byte-identical fixer lists —
  `PurchaseOrderService.php`: `fully_qualified_strict_types, control_structure_braces,
  unary_operator_spaces, braces_position, statement_indentation,
  not_operator_with_successor_space, single_line_empty_body,
  blank_line_before_statement, ordered_imports, binary_operator_spaces,
  phpdoc_align`; both Requests: `ordered_imports, binary_operator_spaces`.
  Pre-existing, not introduced. Not touched. The one file I authored,
  `PurchaseOrderAuditHardeningTest.php`, had genuinely new violations and now
  reports `{"tool":"pint","result":"passed"}`.
- `php artisan test tests/Feature/Purchasing` — **145 passed, 1 failed**; the
  failure was my own scratch probe deliberately triple-cancelling a PO, which the
  F21 guard now correctly refuses. That probe has been deleted. No pre-existing
  Purchasing test regressed.
- `php artisan test tests/Feature/Accounting/AccountsPayableHardeningTest.php
  tests/Feature/B2B` — **150 passed, 1 failed**. The failure is the known AP
  defect at `AccountsPayableHardeningTest.php:229`
  (`assertArrayNotHasKey('payments', $data)` on `SupplierBillResource`), now
  confirmed by a fourth session. `git diff --name-only HEAD` returns nothing
  under `Accounting`, so it is not attributable to this session. Not fixed —
  it belongs to AP.
- Scratch probes deleted; `ogami_test_po` dropped.

## Deferred, with reasons

| item | why not now |
|---|---|
| F17 row scope (a) breadth + (b) `show`/`pdf` unscoped | (a) is a human decision about who may see what — options written up in the action plan, not acted on. (b) is a real authorization gap but the fix shape depends on (a)'s answer. |
| Two ₱50,000 thresholds, one inert | Choosing which governs changes **who may approve**. Explicitly out of bounds. |
| `AVG(unit_cost)` GRN cost basis (F22) | One line, but it moves the variance gate in both directions, i.e. changes whether a supplier is paid without review. |
| Stranded `partially_received` PO (F24) | The missing transition writes off an outstanding purchase commitment; needs a permission and an approval policy. |
| No header↔lines DB guard | Unreachable via the API (both write paths recompute; measured exact). Needs a trigger, not a CHECK. Disproportionate today. |
| SPA money floats, PO edit surface, HashID the 3WM response, duplicate-`item_id` keying | Medium-scope work, no decision needed; several cross into Accounting/SPA files outside this module. |
| `purchasing.open_pos` counts archived POs; GRN raw-PK error message | Dashboard and Inventory modules. Reported, not touched. |
| F09 / F11 / F16 from 2026-08-25 | Unchanged and still open questions; none re-opened by this session's measurements. |

Final status: `🔁 Needs Re-audit` — four contained defects fixed and verified;
the remaining plan is gated on decisions this session is not entitled to make.
