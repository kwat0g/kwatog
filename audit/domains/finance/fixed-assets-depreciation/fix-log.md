# M031 — Fixed Assets & Depreciation fix log

Audit date: 2026-08-25  
Claim: `finance / fixed-assets-depreciation`  
Session result: `🔁 Needs Re-audit`

## Fixes applied

### M031-F01 — Money/centavo arithmetic

- Before: `Asset` and disposal/depreciation services converted financial
  values to binary floats and rounded only at persistence/JE boundaries.
- After: monthly depreciation, book value, disposal proceeds, book value,
  gain/loss, per-asset rows, accumulated balances, and consolidated totals use
  `Money` string arithmetic (`api/app/Modules/Assets/Models/Asset.php:76-110`,
  `api/app/Modules/Assets/Services/AssetService.php:154-177`,
  `api/app/Modules/Assets/Services/DepreciationService.php:73-137,242-265`).
  Each depreciation row is rounded before the same row amount is aggregated
  into the journal.
- Verification: boundary unit coverage for cost/salvage equality and declining
  balance salvage capping was added at
  `api/tests/Unit/Assets/AssetMoneyCalculationTest.php:13-38`.

### M031-F02 — Period eligibility, ordering, and retry identity

- Before: any shaped year/month could be posted out of order using the current
  accumulated balance, with no durable period-level journal identity.
- After: completed-period validation, acquisition/disposal-month eligibility,
  chronological prior-period checks, explicit chronological backfill, legacy
  row reconciliation, and run summary reconciliation are enforced in
  `api/app/Modules/Assets/Services/DepreciationService.php:39-70,141-240,280-359`.
  `asset_depreciation_runs` persists the unique period/journal summary via
  `api/app/Modules/Assets/Models/AssetDepreciationRun.php:1-35` and
  `api/database/migrations/2026_08_25_111000_create_asset_depreciation_runs_table.php:9-27`.
  The command and HTTP entry points reject current/future periods and expose
  explicit backfill only at
  `api/app/Console/Commands/RunMonthlyDepreciation.php:20-81` and
  `api/app/Modules/Assets/Controllers/AssetDepreciationController.php:58-69`.
- Verification: the idempotent backfill and durable-handoff fixtures were
  updated at `api/tests/Feature/Assets/AssetDepreciationCommandTest.php:39-72`
  and `api/tests/Feature/Assets/AssetDepreciationDurableHandoffTest.php:113-126`.

### M031-F03 — Lifecycle locking and financial immutability

- Before: update/delete checked stale in-memory state, schedule fields could
  change after depreciation, and custody history did not prevent deletion.
- After: update and delete re-read the asset under row lock;
  useful-life/salvage changes are rejected after depreciation history, and any
  depreciation or transfer history blocks deletion
  (`api/app/Modules/Assets/Services/AssetService.php:82-115,199-214`).

### M031-F04 — Disposal date, proceeds, reason, and audit contract

- Before: disposal accepted unbounded dates, treated the reason as optional,
  and did not persist it.
- After: the request bounds disposal to today, the service rejects dates before
  acquisition, checks the canonical posting-period guard, requires a trimmed
  reason, stores it, and includes it in the disposal journal description
  (`api/app/Modules/Assets/Requests/DisposeAssetRequest.php:16-22`,
  `api/app/Modules/Assets/Services/AssetService.php:125-196`).
  The new persisted field is exposed by the resource and migration at
  `api/app/Modules/Assets/Resources/AssetResource.php:41-44` and
  `api/database/migrations/2026_08_25_110000_add_disposal_reason_to_assets.php:9-19`.
  The detail modal now collects date/reason and surfaces server errors at
  `spa/src/pages/assets/detail.tsx:88-102,268-305`.

### M031-F05 — Transfer custody race and status guards

- Before: transfer creation/approval did not lock the asset, require active
  status, reject overlapping pending requests, or re-check the source
  department before moving custody.
- After: create and approve lock the asset, validate lifecycle/source state,
  reject another pending request, and use an explicit transition map in
  `api/app/Modules/Assets/Services/AssetTransferService.php:38-155`.
  Regression coverage for disposed assets and duplicate pending requests is at
  `api/tests/Feature/Assets/AssetTransferTest.php:148-196`.

### M031-F06 — Transfer HTTP/SPA surface

- Deferred: the route/UI reactivation was deliberately not retained. The
  repository roadmap still marks asset transfers `DEPRECATE / HIDE` until live
  rows, custody policy, and approval ownership exist
  (`docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md:484-486`), which conflicts
  with treating the retained code as a live workflow. The route group and SPA
  routes remain hidden at `api/app/Modules/Assets/routes.php:31-45` and
  `spa/src/routes/assetsRoutes.tsx:22-27`. This needs a product decision and
  re-audit; no UI/API surface was re-enabled on assumption.

### M031-F07 — Permission gate alignment

- Before: edit controls used `assets.create`, and the depreciation action used
  `assets.depreciation.view` while the API requires update/run permissions.
- After: the edit route/detail action use `assets.update`, and the list/runner
  use `assets.depreciation.run` (`spa/src/routes/assetsRoutes.tsx:23-27`,
  `spa/src/pages/assets/detail.tsx:139-147`,
  `spa/src/pages/assets/index.tsx:77-82`).

### M031-F08 — API/UI field contract

- Before: create omitted depreciation method/insurance fields; edit posted
  immutable acquisition fields and could clear the department; depreciation
  list rows omitted journal identity.
- After: the TypeScript contract includes the backend asset fields and a
  restricted `UpdateAssetData` shape (`spa/src/types/assets.ts:1-75`), create
  renders the supported method/insurance fields
  (`spa/src/pages/assets/create.tsx:20-51,97-143`), edit makes immutable fields
  read-only and preserves the department (`spa/src/pages/assets/edit.tsx:19-65`),
  and the depreciation list includes `journal_entry_id`
  (`api/app/Modules/Assets/Controllers/AssetDepreciationController.php:15-45`).
  Missing salvage defaults are normalized to `Money::zero()` in
  `api/app/Modules/Assets/Services/AssetService.php:60-76,106-115`.

### M031-F09 — QR rendering

- Before: the asset deep-link URL was used directly as an image source and
  downloaded as a PNG.
- After: the pinned `qrcode` runtime package and types are declared in
  `spa/package.json:42,61` (with the matching lockfile); the detail page turns
  the URL payload into a real PNG data URL, renders it, provides a valid
  download, and offers a link fallback on generation failure
  (`spa/src/pages/assets/detail.tsx:3-75,217-250`).

### M031-F10 — January/recovery UI polish

- Before: January defaulted to month `0`, and both depreciation surfaces hid
  server validation/closed-period messages behind a generic toast.
- After: both pages calculate the prior calendar month with rollover and show
  the API error message (`spa/src/pages/assets/DepreciationRunner.tsx:20-34`,
  `spa/src/pages/admin/depreciation.tsx:13-26`).

### M031-F11 — Acquisition/maintenance linkage

- Deferred: this remains a human/product-scope question. Implementing machine,
  mold, vehicle, custodian, and maintenance ownership would create
  cross-module writers and a new auditable timeline; no safe module-local fix
  was inferred from the existing register contract.

## Verification and limits

- PHP lint passed for all changed Assets PHP files, commands, migrations, and
  the new unit test.
- PHPStan reported `No errors` for the Assets module and depreciation commands.
- `AssetMoneyCalculationTest` passed: 2 tests, 4 assertions.
- Scoped ESLint passed for the changed asset SPA files.
- A disposable-copy targeted TypeScript check covering the asset API/types,
  pages, depreciation page, and asset routes passed with `npx tsc -p
  tsconfig.m031.json`. The repository-wide check remains non-green because of
  pre-existing unrelated CRM/budgeting/type errors, including a syntax-broken
  `spa/src/pages/crm/sales-orders/create.tsx`.
- `php artisan route:list --path=asset` passed and showed no transfer routes;
  the live Assets/depreciation surface lists 11 routes.
- Module-scoped `git diff --check` passed.
- Fifteen targeted Assets feature tests were attempted but reached 0
  assertions because PostgreSQL host `db` is unavailable in this checkout
  (`SQLSTATE[08006]`). No database-backed result is treated as passing.
- No browser/E2E run, live PostgreSQL migration rehearsal, or two-connection
  race test was available.

## Remaining work

- F06 requires an explicit product decision on whether the hidden transfer
  workflow is retired or reactivated after its ownership policy is defined.
- F11 requires the same kind of human scope decision for acquisition,
  maintenance, and custody linkage.

These pending items are why the module is released as `🔁 Needs Re-audit` rather
than `✅ Verified`.

## Execution session — 2026-08-27

This is the first session in which the module's committed code was actually
executed. Everything above was written while `migrate:fresh` was broken
repo-wide, so F01–F05 and F07–F10 were source-only. Run on a private database
(`ogami_w3_ast`) so no other suite could tear the schema down mid-run.

### M031-F12 — `AssetDepreciationCommandTest` missing-actor case: fixture never reached the guard

The assigned failure was
`test_backfill_reports_missing_automation_actor_instead_of_succeeding`
→ `Expected status code 1 but received 0`.

**Verdict: fixture defect, not a swallowed condition.** The command reports
correctly; the test never reached the branch it names.

- The guard is present and ordered correctly in
  `api/app/Console/Commands/RunMonthlyDepreciation.php:65-70` — it resolves the
  actor *before* doing any work and returns `self::FAILURE` with an explicit
  reason. The four guards ahead of it (`:33-63`) also all return `FAILURE`.
  There is no path on which a missing actor produces `SUCCESS`, and no
  `catch (Throwable) → Log::*` anywhere in the command. So this is **not** the
  8D-escalation pattern (work throws, summary prints zeros, exit 0).
- What actually happened: `SystemActorService::resolve()`
  (`api/app/Common/Services/SystemActorService.php:31-35`) returns the first
  **active** user whose role slug is in `system.automation.actor_roles`, seeded
  live as `["system_admin"]` by
  `api/database/migrations/0379_seed_automation_actor_roles.php:12-14`.
  `tests/Feature/Admin/RbacConcurrencyTest.php:31` declares
  `protected array $connectionsToTransact = []`, which switches RefreshDatabase's
  per-test transaction OFF for that class (its forked children need the fixtures
  on a second connection). Its `cleanupConcurrencyFixtures()` does not delete the
  ACTIVE `system_admin` rows it committed, and unlike
  `tests/Feature/Accounting/AccountingPeriodPostingConcurrencyTest.php:61-66` it
  does not reset `RefreshDatabaseState::$migrated`, so those users survive for the
  rest of the PHPUnit process. `tests/Feature/Admin` sorts before
  `tests/Feature/Assets`, so the full suite handed this test a live actor. The
  command then ran with an actor, did its (zero-asset) work and correctly exited 0.
- Reproduced deterministically, not inferred:
  `--filter='RbacConcurrencyTest|AssetDepreciationCommandTest'`
  → `1 failed, 4 passed`, exactly the reported message.
  `--filter='AssetDepreciationCommandTest'` alone → `3 passed`.
- Before: the test asserted the guard's output/exit code while silently
  *assuming* an empty `users` table
  (`api/tests/Feature/Assets/AssetDepreciationCommandTest.php:33-38`).
- After: the premise is owned by the test — eligible actors are deactivated
  inside the test's own (rolled-back) transaction and
  `SystemActorService::resolve()` is asserted null before the command runs
  (`api/tests/Feature/Assets/AssetDepreciationCommandTest.php:34-62`). The
  expectation is unchanged: still `expectsOutput(...)` + `assertExitCode(1)`.
  This mirrors the precedent already documented for the same leak at
  `api/tests/Feature/Admin/UserAdministrationHardeningTest.php:59-72`.
- Verification: `RbacConcurrencyTest|AssetDepreciationCommandTest` →
  **5 passed (26 assertions)**; the previously failing case now passes under the
  exact ordering that broke it.
- Not scheduled: `api/routes/console.php:31-36` schedules
  `assets:request-monthly-depreciation` (monthly, 1st @ 03:00), **not**
  `assets:run-monthly-depreciation`. The command in this test is the operator /
  backfill path only, so a false success here would not have been an unattended
  blind spot.
- The scheduled path was checked for the same pattern and is clean:
  `RequestMonthlyDepreciation` only stages an outbox row, and
  `api/app/Modules/Assets/Listeners/RunMonthlyDepreciationOnRequested.php:35-47`
  has **no** `try/catch`, so the `RuntimeException` raised by
  `api/app/Modules/Assets/Jobs/RunMonthlyDepreciationJob.php:43-45` on a missing
  actor propagates, exhausts `tries = 3` and lands in `failed_jobs` via
  `:49-57`. `ChainListenerRunService::recordOutcome('completed', …)` is reached
  only after the job returns, so a failed period cannot be recorded as
  completed. No fix needed here.

### M031-F13 — P0 MONEY: every zero-proceeds disposal was impossible (fixed)

Found by execution, not by reading. Scrapping an asset for nothing — a worn-out
mold, a written-off machine, the most common disposal on this shop floor — could
never be recorded.

- Reproduction: `AssetService::dispose()` with `disposal_amount = '0.00'` threw
  `BusinessRuleException: Each line must have exactly one of debit or credit
  greater than zero.` from
  `api/app/Modules/Accounting/Services/JournalEntryService.php:650`, rolling the
  whole disposal transaction back. The operator sees a ledger error that names
  nothing they entered.
- Cause: the proceeds line and the accumulated-depreciation reversal were emitted
  **unconditionally** (previously `api/app/Modules/Assets/Services/AssetService.php:174-177`),
  so either one became a `debit = 0.00 / credit = 0.00` line whenever its amount
  was zero. Two ordinary disposals hit it:
  1. zero proceeds — and `DisposeAssetRequest.php:20` explicitly allows
     `disposal_amount` `min:0`, so validation invites exactly this input;
  2. disposal of an asset with no depreciation posted yet (reversal line zero).
  The loss and gain lines were already conditional; these two were not.
- Why it was invisible: the only pre-existing disposal coverage
  (`api/tests/Feature/Assets/AssetDisposeDoublePostingRaceTest.php:51-70`) uses
  cost 100000, accumulated 20000 **and** proceeds 90000 together, so all three
  lines were non-zero. No test disposed an asset over HTTP at all.
- After: lines are built only for non-zero amounts, and the degenerate case
  (zero cost, zero proceeds, no accumulated depreciation — reachable because
  `StoreAssetRequest.php:37` allows `acquisition_cost` `min:0`) is refused with a
  message naming the cause instead of producing an entry-less disposal
  (`api/app/Modules/Assets/Services/AssetService.php:164-197`). Dropping a zero
  line changes no balance: it carries no accounting information and acquisition
  cost is credited either way.
- Verification: new
  `api/tests/Feature/Assets/AssetDisposalJournalLinesTest.php` — **4 passed
  (47 assertions)**. It asserts, per scenario, that every line carries exactly
  one of debit/credit, that debits equal credits and equal `total_debit`, and the
  exact signed net per account:
  - zero-proceeds scrap of a ₱12,000 asset with ₱1,000 accumulated → no cash
    line, accum +1000.00, loss +11000.00, PPE −12000.00;
  - never-depreciated write-off → no reversal line, loss +12000.00, PPE −12000.00;
  - fully depreciated, sold for ₱500 → no loss line, gain −500.00.
- Considered and deliberately **not** changed: `DisposeAssetRequest` leaves
  `remarks` `nullable` while the service requires a non-empty reason
  (`AssetService.php:158-161`). That ordering is intentional and documented at
  `AssetService.php:154-157` — the closed-period rejection must stay actionable
  for a client that omits the newer field — and
  `api/tests/Feature/Common/BusinessRuleRenderingTest.php:185-218` depends on it
  by posting a disposal with no `remarks` and asserting the closed-period
  message. Making the field `required` would have replaced that with a validation
  error and broken a deliberate test.

### M031-F01/F02 verified by execution (measured)

The money and period logic committed source-only is now proven against
PostgreSQL:

- **Rounding reconciles exactly.** Three assets whose monthly figures are all
  non-terminating (₱10,000/36 = 277.7…, ₱999.99/12 = 83.33…, ₱4,444.44/60 =
  74.07…) posted rows 277.78 + 83.33 + 74.07 = **435.18**, and the consolidated
  journal's `total_debit` and `total_credit` were both **435.18**. The journal is
  summed from the same rounded rows, so no residue can appear.
- **A full life exhausts exactly.** A ₱10,000 / 3-year asset backfilled across
  36 periods produced 36 rows summing to **10000.00** with a final row of
  **277.70** (the capped remainder, not another 277.78), `accumulated = 10000.00`,
  `book_value = 0.00`, and 36 journals whose debits total 10000.00. Re-running the
  next month posted `posted_count = 0` with **no** journal — an exhausted asset
  produces no empty entry.
- **Declining balance is bounded.** ₱10,000 / 5 years / ₱1,000 salvage opened at
  300.00 (= (10000 − 1000) × 2/5 ÷ 12) and decayed to 162.97 by month 19 without
  ever breaching salvage.
- **Out-of-order posting is refused with an actionable message**, not silently
  computed off the current balance: running 2026-07 for an asset acquired
  2026-01 raised `Depreciation period 2026-01 is missing for asset AST-P-GAP; run
  the explicit backfill workflow first.`

### M031-F14 — depreciation history filter took a raw integer ID (fixed)

- Before: `AssetDepreciationController::index()` filtered with
  `->where('asset_id', (int) $request->input('asset_id'))`, while
  `spa/src/api/assets.ts:32` types the parameter `asset_id?: string` because every
  identifier this API publishes is a HashID. A hash cast to `0`, so asking for one
  asset's depreciation history returned **HTTP 200 with an empty page** — a filter
  answering a different question than the one asked, with no error to notice.
  It also contradicts CLAUDE.md's rule that `id` is always a HashID string.
  Latent rather than live: no SPA page passes the parameter yet, so the first one
  to use it would have shown a permanently empty history.
- After: the value is decoded with `App\Common\Support\HashId::decode()` and an
  undecodable filter raises a 422 on `asset_id` instead of silently widening or
  emptying the result
  (`api/app/Modules/Assets/Controllers/AssetDepreciationController.php:20-35`).
- Verification: new
  `api/tests/Feature/Assets/AssetDepreciationListFilterTest.php` — **2 passed
  (13 assertions)**: filtering by the published `hash_id` returns exactly that
  asset's row (200.00 of two rows), and both `asset_id=not-a-hash` and a raw
  `asset_id=1` are refused 422 with a validation error on the field.

## Decisions needed — NOT taken in this session

### D-M031-1 (money, P0) — disposal-month depreciation leaves the ledger and the register disagreeing, and the reported loss depends on cron timing

Measured on PostgreSQL with one asset: ₱12,000, 5-year straight line (₱200/month),
acquired 2026-01-01, disposed **2026-06-15** for zero proceeds. Only the *order of
operations* differs between the two runs:

| order | Accumulated Depreciation account | Loss on Disposal | `assets.accumulated_depreciation` |
|---|---|---|---|
| dispose on Jun 15, then the monthly cron posts June on Jul 1 | DR 1000.00 / CR 1200.00 → **CR 200.00 left standing** | **11,000.00** | 1,200.00 |
| the cron posts June first, then the operator disposes | DR 1200.00 / CR 1200.00 → nets to **0.00** | **10,800.00** | 1,200.00 |

In the first ordering the contra-asset account keeps a ₱200 credit for an asset
whose cost has already been credited out of PPE, so the asset register no longer
reconciles to the GL, and the register says ₱1,200 accumulated while the disposal
entry reversed ₱1,000. Both journals balance, so nothing raises.

The mechanism is explicit in the code, not incidental:
`api/app/Modules/Assets/Services/DepreciationService.php:190-195` deliberately
keeps a disposed asset in service for a period when
`disposed_date >= periodStart`, while
`api/app/Modules/Assets/Services/AssetService.php:164-170` reverses accumulated
depreciation **as it stands at the moment of disposal**. The scheduler runs a month
on the 1st of the following month (`api/routes/console.php:31-36`), so for any
mid-month disposal the depreciation lands after the disposal entry.

Related and answered by the same policy: the convention is currently **a full month
at both ends**. Measured — an asset acquired 2026-06-20 and disposed 2026-06-21 was
charged a full ₱200.00 for June (one day of ownership), and correctly nothing for
July.

Options (each changes a reported money figure, which is why none was picked):
- **(a) Depreciate through the disposal month, then dispose.** `dispose()` posts (or
  requires) the final month's depreciation before computing gain/loss. Loss becomes
  10,800.00; accum nets to zero. Needs a rule for a disposal recorded into an
  already-closed period.
- **(b) No depreciation in the disposal month.** Change the predicate at
  `DepreciationService.php:194` to `disposed_date > periodEnd`. Loss stays
  11,000.00; accum nets to zero; June expense drops by 200.00.
- **(c) Keep the half-open convention but make `dispose()` reverse the *full*
  scheduled accumulated depreciation for the disposal month.** Preserves current
  expense recognition and nets accum to zero, at the cost of `dispose()` having to
  compute a depreciation figure the depreciation service owns.

Whichever is chosen, the invariant worth asserting afterwards is that the
accumulated-depreciation contra-account nets to zero for a disposed asset. There is
no test asserting that today.

### D-M031-2 — depreciation journals lose their maker (this is decision #12, confirmed here)

Measured, for the record, because it is a second affected writer rather than a new
finding: `DepreciationService.php:103-110` resolves an explicit actor and passes it
to `JournalEntryService::create()`, but the entry carries
`reference_type = 'asset_depreciation'`, so
`api/app/Modules/Accounting/Services/JournalEntryService.php:139` discards it. Probe
result: `je.created_by = NULL`, `je.posted_by = 1`, actor id 1 — and across a
36-period backfill, **0 of 36** depreciation journals recorded a maker. Disposal
journals are affected identically (`reference_type = Asset::class`).

This is decision #12 in `audit/OVERNIGHT-2026-08-27.md:152-174` and is explicitly
not this session's to fix; nothing here was changed on that ground. Noted only so
the decision is costed against Assets as well as payroll, and because depreciation
and disposal are unattended, scheduled financial postings.

### D-M031-3 — the two deferred product decisions are unchanged

F06 (retire vs restore the hidden asset-transfer surface) and F11
(acquisition/maintenance/custody linkage) remain exactly as recorded above. No new
evidence emerged; the transfer service itself is now proven to work — see the
verification section.

## Verification — 2026-08-27 (executed, not inferred)

Database `ogami_w3_ast`, owned by this session alone, so no other suite could
`migrate:fresh` underneath it.

```
php artisan test tests/Feature/Assets tests/Unit/Assets
Tests: 24 passed (115 assertions)   Duration: 31.34s
```

Per class: `AssetDepreciationCommandTest` 3, `AssetDepreciationDurableHandoffTest`
4, `AssetDepreciationListFilterTest` 2 (new), `AssetDisposalJournalLinesTest` 4
(new), `AssetDisposeDoublePostingRaceTest` 1, `AssetTransferRejectRaceTest` 1,
`AssetTransferTest` 6, `AssetUpdateVsDisposeRaceTest` 1,
`Unit\AssetMoneyCalculationTest` 2.

Regression on shared surfaces touched by the disposal change:

```
php artisan test --filter='BusinessRuleRenderingTest'   → 8 passed (18 assertions)
php artisan test --filter='RbacConcurrencyTest|AssetDepreciationCommandTest'
                                                        → 5 passed (26 assertions)
```

The second command is the exact ordering that produced the reported failure; it was
`1 failed, 4 passed` before the fixture fix.

- PHPStan: `[OK] No errors` for `app/Modules/Assets` plus both depreciation
  commands, after every change.
- `php -l` clean on all changed and added PHP files.
- Pint: the module was **already** failing at `HEAD` on
  `AssetService.php` (`fully_qualified_strict_types`) and
  `AssetDepreciationController.php` (`braces_position`, `single_line_empty_body`,
  …), verified by running Pint against the `git show HEAD:` copies. The edits add
  no new violation and both new test files are Pint-clean. Pre-existing style was
  left alone rather than mixed into this diff.

### Status of the original findings after execution

| Finding | State |
|---|---|
| F01 money/centavo arithmetic | **Verified by execution** — exact reconciliation and full-life exhaustion measured |
| F02 period policy/order/retry | **Verified by execution** — backfill idempotency, gap refusal, run identity |
| F03 lifecycle locking | **Verified by execution** — `AssetUpdateVsDisposeRaceTest`, `AssetDisposeDoublePostingRaceTest` |
| F04 disposal contract | **Fixed further** — F13 found zero-proceeds disposal was impossible; closed-period path verified |
| F05 transfer custody races | **Verified by execution** — 6 tests + reject race |
| F06 transfer live surface | Deferred — product decision (unchanged) |
| F07 permission gate alignment | **Unverified** — SPA-only; Vitest/Playwright blocked by root-owned `spa/node_modules` |
| F08 API/UI field contract | Backend half **fixed and verified** (F14 HashID filter); SPA half unverified for the same reason |
| F09 QR rendering | **Unverified** — SPA-only, same blocker |
| F10 January/recovery UI polish | **Unverified** — SPA-only, same blocker |
| F11 acquisition/maintenance linkage | Deferred — product decision (unchanged) |
| F13 zero-proceeds disposal | **Fixed and verified** (new) |
| F14 depreciation filter HashID | **Fixed and verified** (new) |

### Limits of this session

- No SPA verification of any kind. `spa/node_modules` is root-owned, so Vite,
  Vitest and Playwright cannot run; the `spa` service is intentionally down. F07,
  F09, F10 and the SPA half of F08 are therefore still source-only.
- No full-suite run (explicitly out of bounds on this host).
- D-M031-1 and D-M031-2 are open decisions, so the disposal-month ledger
  reconciliation and journal attribution remain as measured above.

### Cross-module note for the coordinator (not fixed here — outside this module)

`tests/Feature/Admin/RbacConcurrencyTest.php:31` sets
`protected array $connectionsToTransact = []` and commits ACTIVE `system_admin`
users that its cleanup does not delete, without resetting
`RefreshDatabaseState::$migrated`. Those rows survive for the remainder of the
PHPUnit process, so **any** later test whose premise is "no active system admin" or
"no eligible automation actor" is silently disarmed. It has now done exactly that
twice: `UserAdministrationHardeningTest` documents it at `:59-72` and works around
it, and it caused this module's reported failure. The precedent fix is one method,
already used by
`tests/Feature/Accounting/AccountingPeriodPostingConcurrencyTest.php:61-66`:

```php
public static function tearDownAfterClass(): void
{
    RefreshDatabaseState::$migrated = false;
    parent::tearDownAfterClass();
}
```

`tests/Feature/Accounting/AccountingPeriodDuplicateRecoveryTest.php:39` calls
`DB::commit()` with no such reset either. Both are in other modules' scope, and
fixing them needs an `Admin` + `Accounting` re-run this session could not afford, so
they are reported rather than changed.

## Resumed-session verification — 2026-08-25

- No additional production-code fix was applied in this resumed verification
  session. The existing F01–F05 and F07–F10 implementation remains in the
  working tree at the file/line locations recorded above.
- Scoped PHP lint passed for the Assets module, depreciation commands, related
  migrations, and Assets tests. PHPStan passed with `No errors` for
  `app/Modules/Assets` and both depreciation commands. The pure Money coverage
  passed: 2 tests, 4 assertions (`api/tests/Unit/Assets/AssetMoneyCalculationTest.php:13-38`).
- Scoped SPA ESLint and module diff-check passed for the changed asset files.
  An asset-only TypeScript check could not complete because this checkout has
  no installed `qrcode` runtime/types despite declarations in
  `spa/package.json:42,61` and `spa/package-lock.json:27,2133,5185`; it also
  surfaced the pre-existing `import.meta.env` typing error at
  `spa/src/api/client.ts:250`.
- The host feature run could not resolve Compose hostname `db`. Running the
  same suite inside the API container reached 16 tests: 6 progressed without
  setup errors and 10 errored before assertions because the shared
  `ogami_test` schema is stale/incomplete (`accounts`/`permissions` missing and
  `roles.deleted_at` absent). No database-backed result is treated as passing.
- F06 remains deferred pending a product decision to retire the hidden transfer
  implementation or restore its guarded API/SPA workflow. F11 remains deferred
  pending a product decision on cross-module asset, custodian, and maintenance
  ownership. Both are human-input blockers, so the module remains
  `🔁 Needs Re-audit`.

## Re-audit verification — 2026-08-27

- No production or test code was changed in this re-audit. The current module
  diff is empty; the only working-tree change outside this module is the
  coordinator's generated registry timestamp.
- Backend execution on `DB_DATABASE=ogami_test_m031_agent_b` passed:
  `tests/Feature/Assets tests/Unit/Assets` → **24 tests, 115 assertions**.
  PHP lint and PHPStan also passed. Pint still reports the inherited module
  baseline (27 files, 20 issues); it was not reformatted here.
- SPA execution passed: `npm run test:run` → **41 files, 282 tests**;
  `npm run typecheck` and scoped ESLint passed. No M031 browser/E2E test is
  present, so F07–F10 remain browser-unverified.
- The current disposal-month probe reproduced the open P0 decision: after
  five prior months, disposing a ₱12,000 asset on 2026-06-15 for zero and then
  running June leaves asset accumulated depreciation at `1200.00` while the
  accumulated-depreciation account has net `-200.00` (account 1410).
- The restore probe soft-deleted an asset and showed default hash binding raises
  `ModelNotFoundException`, while `resolveSoftDeletableRouteBinding()` finds
  it. The live route does not opt into trashed binding.
- No fixes were applied because the ordered plan is majority
  `separate-recommended` and exceeds the small-scope gate. Open work is
  classified as F15 Broken, F16 Incomplete, F17 Broken, F18 Broken, and F19
  Missing in `audit-report.md`.

## M031-F17 — Restore soft-deleted assets by published hash — 2026-08-28

- Before: `PATCH /api/v1/assets/{asset}/restore` used the default route binding,
  so `HasHashId` excluded the soft-deleted asset before `AssetController::restore`
  could run.
- After: the restore route opts into the established soft-deletable binding
  convention with `->withTrashed()` while retaining the existing
  `permission:assets.delete` middleware (`api/app/Modules/Assets/routes.php:21-24`).
  No transfer, salvage, disposal, or other asset route was changed.
- Regression: `AssetRestoreRouteTest` creates and soft-deletes an asset, proves an
  employee receives HTTP 403 and the row remains archived, then proves a
  finance officer restores the same record through its published `hash_id`
  (`api/tests/Feature/Assets/AssetRestoreRouteTest.php:18-54`).
- Verification:
  - `docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_m031_f17_20260828 api php artisan test tests/Feature/Assets/AssetRestoreRouteTest.php --no-coverage` — **PASS: 1 test, 5 assertions** on the disposable PostgreSQL database.
  - `docker compose run --rm --no-deps api sh -lc 'php -l app/Modules/Assets/routes.php && php -l tests/Feature/Assets/AssetRestoreRouteTest.php'` — **PASS: both files have no syntax errors**.
  - `docker compose run --rm --no-deps api ./vendor/bin/pint --test tests/Feature/Assets/AssetRestoreRouteTest.php` — **PASS**.
  - `docker compose run --rm --no-deps api ./vendor/bin/pint --test app/Modules/Assets/routes.php` — **FAIL: inherited `method_argument_space` alignment in the existing route file**; unrelated route whitespace was intentionally preserved.
  - `docker compose run --rm --no-deps api ./vendor/bin/phpstan analyse app/Modules/Assets/routes.php tests/Feature/Assets/AssetRestoreRouteTest.php --memory-limit=1G` — **PASS: no errors**.
  - `git diff --check` — **PASS**.
