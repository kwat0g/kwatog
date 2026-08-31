# M040 — Warehouse & Stock Control Fix Log

Audit session: 2026-08-25  
Claimed with: `audit/scripts/claim-module.sh inventory warehouse-stock-control`

## Re-audit work

- Re-ran scoped discovery → hardening → polish because Inventory files changed after the prior report and directly affected its findings.
- Rebuilt [audit-report.md](audit-report.md) and [action-plan.md](action-plan.md) against the current working tree.

## Production fixes

None in this session. The application changes referenced by the re-audit pre-date this claim; this session changed only the M040 audit artifacts.

## Deferred plan items and blockers

- Item 1 is pending a human decision on authoritative lot balances/projection and mixed-lot allocation. Implementing without that decision could make the map, transfers, and picking disagree.
- Items 2 and 6 are policy-dependent: empty/unexpected bins, monetary versus quantity variance, approval segregation, and picking reservation versus material issue.
- Items 3–9 remain pending because the plan must be worked in order and item 1 is blocked. See [action-plan.md](action-plan.md) for the complete ordered list; no out-of-order production patch was made.

## Verification record

Command:

```text
cd /home/kwat0g/Desktop/kwatog/api && php artisan test --compact --filter='(WarehouseMapHashBindingTest|WarehouseScanTest|TransferOrderRaceRegressionTest|StockCountCancelRegressionTest|StockCountMovementFreezeTest|CycleCountWacTest|StockAdjustmentReasonTest|StockAdjustmentDoubleApproveRaceTest|StockLevelOptimisticLockTest|ZoneGuardTest)'
```

Outcome: exit code 2; 42 tests failed before assertions because PostgreSQL host `db` could not resolve (`SQLSTATE[08006]`). PHPUnit also emitted doc-comment metadata deprecation warnings. With no production fixes made in this session, there were no fixes to re-check against the unavailable database.

Intended release status: `🔁 Needs Re-audit`.

---

# M040 Fix Log — 2026-09-01 re-audit

Claimed with `audit/scripts/claim-module.sh inventory warehouse-stock-control` → **RECLAIMED**
(stale lock, 153h old).

## Prior-session assessment

The 2026-08-25 log **self-flagged its own verification as unusable** ("42 tests failed
during setup with 0 assertions because PostgreSQL host `db` could not be resolved"). That
is the signature of a missing database, not of broken code. Re-measured: the module's
existing suite is **169 passed / 557 assertions / exit 0** at HEAD. 17 of the 18 prior
findings reproduce; M040-F09 is disproved as a correctness defect.

## Real baseline (before any change)

```
docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_stock api \
  php artisan test tests/Feature/Inventory --no-coverage
→ Tests: 169 passed (557 assertions)   exit 0   67.76s
```

## Production fixes — commit `ae98ee65`

| File | Change |
|---|---|
| `api/app/Modules/Inventory/Services/StockCountService.php` | New `variancePercent()` — bcmath, saturating at the `numeric(8,2)` ceiling; replaces unbounded float arithmetic |
| `api/app/Modules/Inventory/Controllers/StockCountController.php` | `counted_quantity` → `decimal:0,3\|min:0\|max:999999999999.999`; scope's own id now required for that scope; zone must belong to the given warehouse |
| `api/app/Modules/Inventory/Controllers/TransferOrderController.php` | `quantity` → `decimal:0,3\|min:0.001\|max:999999999999.999`; `to_location_id` gains `different:from_location_id` |
| `api/app/Modules/Inventory/routes.php` | `->withTrashed()` on the warehouse, zone and location restore routes |
| `api/app/Modules/Inventory/Services/StockMovementService.php` | New `assertPositiveQuantity()` guarding `reserve()` and `release()` |
| `api/tests/Feature/Inventory/WarehouseStockControlHttpContractTest.php` | New — 8 tests, 64 assertions, HTTP-level |

## Measured before → after

| Probe | Before | After |
|---|---|---|
| `POST /stock-counts/items/{id}/count` system 0.500, counted 6000 | **500** `22003 numeric field overflow` | **200**, `variance_percent` `999999.99` |
| same, counted 190 vs system 200 | 200, `5.00` | 200, `5.00` (unchanged) |
| same, `counted_quantity=1e3` | **500** `ValueError: bcsub(): Argument #1 not well-formed` | **422** |
| same, `1e17` / `1e20` | **500** `22003` | **422** |
| same, `1.999` | 200 stored `1.999` | 200 stored `1.999` (unchanged) |
| `POST /transfer-orders` `1e17` / `1e20` | **500** `22003` | **422** |
| same, `1e3` | 201 stored `1000.000` | **422** |
| same, `10.00005` | 201 stored `10.000` (truncated) | **422** |
| same, `1.999` | 201 stored `1.999` | 201 stored `1.999` (unchanged) |
| same, source == destination | **201**, then execute 422 forever | **422** at create |
| `POST /stock-counts` `scope=warehouse`, no `warehouse_id` | **201**, `total_locations=2` across two warehouses | **422** |
| same, `zone_id` from another warehouse | **201**, `total_locations=1` | **422** |
| same, correct warehouse+zone | 201, `total_locations=1` | 201, `total_locations=1` (unchanged) |
| `PATCH /warehouses|zones|locations/{id}/restore` on trashed rows | **404 / 404 / 404** | **200 / 200 / 200** |
| `reserve(item, loc, '-50')` | `reserved_quantity` → **-40.000** | `InvalidMovementException`, stays `10.000` |
| `release(item, loc, '-999')` | `reserved_quantity` → **959.000** vs on-hand `100.000` | `InvalidMovementException`, stays `10.000` |

## Verification record

```
tests/Feature/Inventory        169 passed / 557 assertions  (before)
tests/Feature/Inventory        177 passed / 621 assertions  (after, exit 0)
tests/Feature/Production + tests/Feature/MRP
                               134 passed / 418 assertions  (exit 0)
                               — the only out-of-module reserve()/release() callers
php -l                         clean on all 5 changed files
phpstan (5 changed + new test) [OK] No errors
pint --test                    4 failures, all byte-identical to HEAD's full rule
                               lists → NEW (mine only): []   (new test file passes)
```

**Red-against-HEAD check:** HEAD copies of all 5 changed files were swapped in and the new
test run against them — **7 of 8 failed**. The 8th
(`an everyday variance percent is exact`) is a **pass-either-way lock**: 5.00% is computed
identically by the old float path and the new bcmath one, so it pins the no-regression
half rather than the bug. My versions were restored and verified byte-for-byte with
`sha256sum -c` (5/5 OK) and `diff -q` (5/5 IDENTICAL).

## Deliberately NOT changed

- **`variance_value` semantics** — stores a quantity in a money column. Changing it changes
  a valuation figure.
- **Count completion with pending items, and empty-session completion** — changes whether
  an existing flow is allowed.
- **Count maker-checker** — changes who may approve.
- **`finance_officer` visibility (N5)** — the fix lives in
  `api/database/seeders/RolePermissionSeeder.php`, outside this module, and moves an
  approval/visibility boundary. Needs a human decision between two shapes.
- **Over-available transfer-order creation** — left at 201 deliberately: pre-creating a
  transfer for stock expected to arrive is plausibly intended, so refusing it would be a
  behaviour change rather than containment. Flagged as a question.
- **Stock-movement immutability (N4)** — needs a column-scoped trigger plus a migration,
  and a decision on the intended reversal path.

## Scratch artefacts removed

`ZzM040AuditProbeTest.php`, `ZzM040Probe2Test.php`, `ZzM040Probe3Test.php`,
`api/storage/app/m040probe/` — all deleted. Databases `ogami_test_stock` and
`ogami_seedcheck` dropped. `git status` confirmed no stray files.

## Local workaround, reverted

Probes could not use `$seed = true` because `DatabaseSeeder` is broken at HEAD (N6). They
seeded only `RolePermissionSeeder`, which is enough for role/permission truth. No
application source was touched for the workaround, and the throwaway `ogami_seedcheck`
database used to confirm the blocker independently was dropped.
