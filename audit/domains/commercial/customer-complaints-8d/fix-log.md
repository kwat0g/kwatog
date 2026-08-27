# M034 — Customer complaints / 8D fix log

Session date: 2026-08-25  
Module status: 🔁 Needs Re-audit

## Existing working-tree fixes verified

The claimed module already contained uncommitted complaint-path changes made after the prior report. They were preserved and checked rather than rewritten:

- **M034-F01:** `api/app/Modules/CRM/Services/ComplaintService.php:178-257` now uses complaint/report lock ordering, authoritative finalization checks, and post-commit finalization events.
- **M034-F03:** `ComplaintService.php:52-62,370-418` now uses an explicit lifecycle matrix and lock/reload transitions.
- **M034-F04/F07:** `api/app/Modules/B2B/Resources/CustomerPortalComplaintResource.php:19-45`, the complaint boundary in `CustomerPortalService.php:303-345`, and `api/app/Modules/CRM/Controllers/ComplaintController.php:103-126` provide safe portal/PDF publication gates.
- **M034-F05 core:** `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:76-184` now bounds candidate scans and claims SLA tiers under a row lock; `NotificationService.php:142-170` makes in-app inserts transactional.
- **M034-F06:** `api/app/Modules/CRM/Requests/StoreComplaintRequest.php:60-137` and `ComplaintService.php:389-443` validate source identity and assignee authority at both boundaries.
- **M034-F10/F11:** `spa/src/pages/crm/complaints/create.tsx:39-73,160-188` and the portal complaint API/UI provide searchable source selectors, bounded pagination, and history filters.

## Changes made in this session

- **M034-F02:** Added `ComplaintService::ensureQualityCompletionReady()` at `api/app/Modules/CRM/Services/ComplaintService.php:327-368`. Before: resolve/close required only a generated NCR handoff. After: both transitions require a finalized 8D report and a closed, dispositioned NCR; `close` follows only `resolved` in the transition matrix.
- **M034-F02 polish:** Updated `spa/src/pages/crm/complaints/detail.tsx:149-160,217-326,397-414` so lifecycle actions and confirmation copy match the server-side quality gate.
- **M034-F10 hardening:** Updated `api/app/Modules/CRM/Controllers/ComplaintController.php:71-84` to pass only validated 8D fields to the service.
- **M034-F10 polish:** Added debounced sales-order search to `spa/src/pages/crm/complaints/create.tsx:39-73,173-188`.
- **M034-F12:** Added `api/database/migrations/2026_08_25_160100_protect_customer_complaint_retention.php:11-29`, changing customer complaint history from cascading deletion to restricted deletion.
- **Regression coverage:** Added completion-gate cases to `api/tests/Feature/CRM/ComplaintLifecycleTransitionTest.php` and a retention case to `api/tests/Feature/CRM/ComplaintSourceValidationTest.php`; updated the portal 8D fixture to represent a closed/dispositioned NCR.

## Verification

- PHP lint passed for the changed M034 PHP files.
- Complaint route listing passed and showed the expected endpoints/middleware.
- Scoped ESLint passed for the changed internal complaint pages.
- Scoped `git diff --check` passed.
- Backend focused tests were attempted on `ogami_test_m034_20260825` but stopped before assertions because unrelated migration `2026_08_25_210000_enforce_one_active_holiday_per_date.php:42` tries to drop an index-backed constraint incorrectly.
- SPA-wide typecheck was attempted but is blocked by unrelated existing errors in assets and return-management files; no M034 error was reported.

## Deferred

- **M034-F05 residual:** durable per-recipient/channel SLA delivery ledger and retry semantics; requires shared notification/queue coordination.
- **M034-F08:** cancellation route/reason/NCR correction policy needs a product decision.
- **M034-F09:** permission split needs the shared RBAC owner and role-assignment rollout.
- Clean backend migration/test evidence and browser/e2e evidence remain pending.

The module must be released through `release-module.sh` so the lock is removed and the registry is refreshed.

## Re-audit fixes applied

### M034-R01 — durable SLA delivery ledger

- `api/database/migrations/2026_08_26_000000_create_complaint_8d_escalation_deliveries.php:13-35`
  and `api/app/Modules/CRM/Models/Complaint8dEscalationDelivery.php:11-43`
  add one unique delivery record per complaint/tier with status, attempts,
  recipient count, idempotency key, timestamps, and last error.
- `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:119-248`
  now claims and sends under the complaint transaction/row lock, marks the
  ledger sent with the compatibility JSON marker, and keeps an empty audience
  pending. `:250-405` records failed attempts as retryable instead of losing
  the outcome in logs only.
- Before: `sla_alert_levels` was the only claim marker and a failed send left
  no per-tier attempt or retry state. After: the ledger is the durable
  complaint/tier outcome while the JSON remains a compatibility summary.

### M034-R02 — cancelled portal source order

- `api/app/Modules/B2B/Requests/Customer/CreateComplaintRequest.php:27-43`
  rejects an owned cancelled order with a field-level validation error; the
  authoritative CRM check remains at `ComplaintService.php:441-445`.
- `api/tests/Feature/B2B/CustomerPortalServiceTest.php:414-440` covers the
  negative API path and verifies no complaint is created.
- Before: request validation checked only existence and ownership. After: it
  rejects known cancelled orders early without weakening the transaction-time
  race guard.

### M034-R03 — portal complaint audit action

- `api/app/Modules/B2B/Services/CustomerPortalService.php:281-298` now writes
  `customer.complaint.submitted`, matching the contract assertion at
  `api/tests/Feature/B2B/CustomerPortalServiceTest.php:398-412`.
- Before: the service wrote `customer_cmp.submit`. After: the stable action
  identifier is consistent for audit consumers and tests.

## Current verification

- PHP lint passed for the current-session CRM/B2B production, migration, model,
  and test files.
- Scoped `git diff --check` passed.
- The focused Docker feature suite still cannot reach assertions because the
  shared `ogami_test` reset currently finds duplicate `migrations`/`roles`
  relations before any test runs. An earlier reset attempt reached unrelated
  `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`
  and PostgreSQL rejected dropping `holidays_date_name_unique` while its
  same-named table constraint still required the index. Neither dependency was
  modified from this module session.

## Still deferred

- **M034-R04:** cancellation policy needs an explicit product/quality decision
  on reason, actor, transition, audit, and customer-visible behavior.
- **M034-R05:** permission split needs the RBAC owner's role/action matrix.
- Clean-database integration, concurrency, and browser evidence remain pending
  after the shared migration issue is repaired.

---

# Execution session — 2026-08-27

`migrate:fresh` works again, so this session is the first to actually RUN the
R01 SLA ledger. Own database: `ogami_test_c8d`.

## M034-R06 — the entire SLA delivery ledger was dead code (P1, Broken)

**One production bug caused all five reported `Complaint8dSlaTest` failures.**

`api/app/Modules/CRM/Models/Complaint8dEscalationDelivery.php:19-21` declared no
`$table`, so Eloquent inferred the table from the class name. `Str::snake` does
not split a digit from the preceding word, so
`Complaint8dEscalationDelivery` → `complaint8d_escalation_delivery` →
`complaint8d_escalation_deliveries` — while the migration
`api/database/migrations/2026_08_26_000000_create_complaint_8d_escalation_deliveries.php:13`
creates `complaint_8d_escalation_deliveries` (underscore before the `8`).

Every read and write of the ledger therefore threw
`SQLSTATE[42P01] Undefined table: relation "complaint8d_escalation_deliveries"
does not exist`. Because `advanceOne()` wraps its transaction in
`catch (Throwable)` → `recordFailure()` → `Log::warning`, and `recordFailure()`
touches the same missing table inside its own `catch (Throwable)` → `Log::error`,
**the failure was swallowed twice and never surfaced.** Observed proof from
`storage/logs/laravel.log` during a single-test run:

```
local.ERROR: Complaint8dEscalationService: failure could not be recorded
  {"complaint_id":1,"error":"SQLSTATE[42P01]: Undefined table: 7 ERROR:
   relation \"complaint8d_escalation_deliveries\" does not exist ...
```

Production impact, not just test impact: **no 8D SLA escalation notification
could ever be delivered.** Every tier for every overdue complaint threw before
the notification send, was logged at warning level, and returned `[]`, so
`crm:complaint-8d-escalate` reported `d3=0 d4=0 finalize=0` forever while
quality staff were never told a containment or root-cause window had lapsed.

- Before (`Complaint8dEscalationDelivery.php:19-21`):
  ```php
      use HasFactory;

      protected $fillable = [
  ```
- After (`Complaint8dEscalationDelivery.php:19-27`):
  ```php
      use HasFactory;

      /**
       * Eloquent infers `complaint8d_escalation_deliveries` from the class name
       * (Str::snake does not split a digit from the preceding word), which is not
       * the migrated table. Complaint8DReport pins its table for the same reason.
       */
      protected $table = 'complaint_8d_escalation_deliveries';

      protected $fillable = [
  ```

The sibling model `api/app/Modules/CRM/Models/Complaint8DReport.php:17` already
pins `protected $table = 'complaint_8d_reports';` for exactly this reason, so the
convention existed and the new model simply missed it.

### Direction-of-fix note (both guarantees were real)

The two guarantee-bearing tests were **not** fixture bugs, and neither guarantee
was missing — both were correctly implemented and merely unreachable:

- `test_failed_notification_rolls_back_tier_claim_and_inbox_rows`: the rollback is
  genuinely present. `Complaint8dEscalationService.php:113-249` creates the
  delivery row *inside* the `DB::transaction`, so a throwing
  `NotificationService::send` rolls back the claim, the `notifications` inserts,
  and `sla_alert_levels` together; the outer `catch` then re-records a retryable
  `pending` row in a fresh transaction via `recordFailure()` at `:342-398`. No
  weakening was needed.
- `test_d3_idempotent_re_run_does_not_double_fire`: the `(complaint_id, tier)`
  unique index plus the `lockForUpdate()` claim at
  `Complaint8dEscalationService.php:141-159` already enforce single-fire. The
  claim was **not** weakened to make the test pass.

The fixture also does not have the `CustomerComplaint::ncr()` direction problem
flagged for this session — `Complaint8dSlaTest` never links an NCR.

## Verification

`docker compose exec -T -e DB_DATABASE=ogami_test_c8d api php artisan test --filter='Complaint8dSlaTest'`

```
   PASS  Tests\Feature\CRM\Complaint8dSlaTest
  ✓ not yet due is silent
  ✓ d3 overdue with empty d3 containment fires once
  ✓ d3 idempotent re run does not double fire
  ✓ d4 fires after d3 already recorded
  ✓ terminal complaint is skipped
  ✓ failed notification rolls back tier claim and inbox rows
  ✓ no active recipient keeps overdue tier retryable

  Tests:    7 passed (21 assertions)
```

All 5 previously-failing tests pass. 7/7 green, 0 failures.

## M034-R07 — F12 retention test aborted its own PG transaction (fixture bug)

Not in the reported five; surfaced once the suite could actually run.
`ComplaintSourceValidationTest::test_customer_force_delete_cannot_remove_complaint_history`
failed with:

```
   FAILED  Tests\Feature\CRM\ComplaintSourceValidationTest >…  QueryException
  SQLSTATE[25P02]: In failed sql transaction: 7 ERROR:  current transaction is
  aborted, commands ignored until end of transaction block (... SQL: select
  exists(select * from "customer_complaints" where ("customer_id" = 9 and
  "description" = Retention guard test)) as "exists")
  8   tests/Feature/CRM/ComplaintSourceValidationTest.php:136
```

**Production code is correct** — the F12 RESTRICT foreign key fired exactly as
intended, and the caught `QueryException` is the proof. The defect is in the
fixture: PostgreSQL aborts the *enclosing* transaction on a constraint violation,
and `RefreshDatabase` holds one open around the whole test, so the follow-up
`assertDatabaseHas` at `:136` could not execute. The test could never pass on
PostgreSQL regardless of the schema.

- `api/tests/Feature/CRM/ComplaintSourceValidationTest.php:17` — added
  `use Illuminate\Support\Facades\DB;`
- `api/tests/Feature/CRM/ComplaintSourceValidationTest.php:129-140`
  - Before: `$customer->forceDelete();`
  - After: `DB::transaction(static fn () => $customer->forceDelete());`

A nested `DB::transaction` makes Laravel emit a `SAVEPOINT` and roll back to it on
exception, so the outer transaction survives and the retention assertion runs.

```
   PASS  Tests\Feature\CRM\ComplaintSourceValidationTest
  ✓ create rejects an order from another customer
  ✓ create rejects a product not present on the order
  ✓ create rejects an inactive assignee
  ✓ http intake returns 422 for mismatched sales order
  ✓ http intake rejects an undecodable customer hash
  ✓ customer force delete cannot remove complaint history

  Tests:    6 passed (13 assertions)
```

## Regression sweep — every previously-unexecuted claim now verified

Each class run separately (memory budget: 3 agents share the host).

| class | result |
|---|---|
| `Tests\Feature\CRM\Complaint8dSlaTest` | 7 passed (21 assertions) |
| `Tests\Feature\CRM\ComplaintSourceValidationTest` | 6 passed (13 assertions) |
| `Tests\Feature\CRM\ComplaintLifecycleTransitionTest` | 3 passed (13 assertions) |
| `Tests\Feature\CRM\Complaint8dFinalizeGuardTest` | 6 passed (10 assertions) |
| `Tests\Feature\CRM\ComplaintNcrHandoffTest` | 2 passed (12 assertions) |
| `Tests\Feature\CRM\Complaint8dDueAtStampingTest` | 1 passed (7 assertions) |
| `Tests\Feature\B2B\CustomerPortalServiceTest` | 23 passed (88 assertions) |

Total 48 passed / 164 assertions / 0 failed. This retires the plan's
"Already addressed and requiring regression verification" section: F01, F02, F03,
F04, F06, F07, F10, F11, F12, R02 and R03 are now executed, not merely linted.

- **R02** verified by `✓ create complaint rejects cancelled source order`.
- **R03** verified by the `'action' => 'customer.complaint.submitted'` assertion at
  `api/tests/Feature/B2B/CustomerPortalServiceTest.php:408`, matching
  `api/app/Modules/B2B/Services/CustomerPortalService.php:292`.
- **R01** verified by all 7 `Complaint8dSlaTest` cases, after R06 above made the
  ledger reachable at all.

Also checked and clean: no other model in any module reproduces the R06
table-inference bug (swept every `api/app/Modules/*/Models/*.php` whose class name
contains a digit for a missing `protected $table`; zero hits).

## Decisions required — NOT made by this session

Both remaining plan items are genuine human calls. Options and evidence below;
no option was chosen.

### M034-R04 — lifecycle states: implement them, or retire the statuses?

Confirmed still true in current code:

- `api/app/Modules/CRM/Enums/ComplaintStatus.php:10,14` defines
  `Investigating = 'investigating'` and `Cancelled = 'cancelled'`; `:16-19`
  treats only the latter as terminal.
- No investigation or cancellation route exists in
  `api/app/Modules/CRM/routes.php:64-84`.
- `ComplaintService` has no transition into either state (the lifecycle matrix
  admits only resolve from `open`/`investigating` and close from `resolved`).

New evidence this session — **the unreachable status is published as a filter**:

- `api/app/Modules/CRM/Controllers/ComplaintController.php:46-49` returns
  `ComplaintStatus::cases()` verbatim from `/complaints/options`, so the internal
  complaint list offers a selectable **Cancelled** filter that can only ever
  return zero rows.
- The SPA carries matching dead branches: chip variants at
  `spa/src/pages/crm/complaints/index.tsx:19` and
  `spa/src/pages/crm/complaints/detail.tsx:32`, a terminal check at
  `detail.tsx:152`, and a stepper branch at `detail.tsx:156`.
- `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:65` excludes
  `cancelled` from SLA candidates — so the status is load-bearing in code while
  being unreachable in data.

The `investigating` state needs the same policy decision: whether starting the
8D investigation should be an explicit auditable transition or whether the
status should be retired in favor of the report workflow alone.

Options:

- **(A) Implement cancellation.** Needs a decision on: who may cancel (a new
  permission, or the existing manage gate); whether a reason is mandatory and
  free-text or a coded list; whether cancellation is allowed after 8D
  finalization or an NCR exists, and if so what happens to that NCR (voided,
  left open, or requiring its own disposition first); and what the customer
  portal shows a complainant whose complaint was cancelled.
- **(B) Retire the status.** Remove `Cancelled` from the enum, drop the dead SPA
  branches, and stop publishing it from `/complaints/options`. Requires
  confirming no persisted row already holds `'cancelled'` and accepting that
  misfiled complaints are corrected some other way (today: none).
- **(C) Keep as-is but stop advertising it.** Minimal: filter `Cancelled` out of
  the `options` response so the UI stops promising an unreachable filter, leaving
  the enum case for future use.

Blocking question for product/quality: **should investigation be an explicit
state transition, and is cancelling a customer complaint permitted at all
under IATF 16949 record-retention expectations? If so, may cancellation happen
after an NCR has been raised?** That is a quality-records question, not an
engineering one, so it is not answered here.

### M034-R05 — split the single `crm.complaints.manage` gate?

Confirmed still true in current code:

- All ten internal complaint routes — `options`, `index`, `show`, `store`,
  `retry-ncr`, `8d` update, `8d/finalize`, `resolve`, `close`, `8d/pdf` — gate on
  `permission:crm.complaints.manage` at
  `api/app/Modules/CRM/routes.php:65-84`.
- `api/database/seeders/RolePermissionSeeder.php:317` seeds
  `crm.complaints.manage` as the only complaint permission.

Consequence: read-only review, 8D authoring, 8D finalization, lifecycle closure,
NCR retry, and formal Certificate-style PDF download are one indivisible grant.
Anyone who may look at a complaint may also finalize its 8D and close it.

This is squarely "changing who can see what", so it is not decided here. The
matrix the RBAC owner needs to fill in — the six distinguishable actions above
against the seeded roles (`qc_inspector`, `production_manager`, `department_head`,
`system_admin`, and whether `employee` gets read) — plus whether finalization
should be maker-checker separated from authoring, is the input required. Once
that exists the change is mechanical: new permission slugs, route gate swaps, SPA
`PermissionGuard`/action gates, and a matrix test.

## Final status of the plan

| item | state |
|---|---|
| R01 durable SLA delivery ledger | **fixed and verified** (was dead code; R06) |
| R02 reject cancelled portal source order | verified |
| R03 normalize portal complaint audit action | verified |
| R04 lifecycle state policy | **deferred — needs product/quality decision** |
| R05 permission split | **deferred — needs RBAC owner's matrix** |
| R06 ledger table name (new this session) | fixed and verified |
| R07 F12 retention fixture (new this session) | fixed and verified |
| R08 complaint update email rendering | **fixed and verified** |
| R09 invalid internal customer filter | **fixed and verified** |
| R10 NCR list/UI contract | **fixed and verified** |

Still not covered, and honestly out of reach here: **two-worker concurrency** for
the SLA claim. The single-process idempotency path is proven, and the
`(complaint_id, tier)` unique index plus `lockForUpdate()` are the right
mechanism, but a genuine two-writer race was not executed — 3 agents share
3.7 GiB on this host and a second concurrent PHP worker risks the OOM that killed
the stack earlier today. Browser/e2e evidence for the complaint flows is likewise
not available in this session.

# Execution session — 2026-08-27 (continued)

## M034-R08 — complaint update email rendering (P1, Broken)

The red regression run reproduced two production failures: rendering
`CustomerComplaintUpdateMail` called `label()` on `ComplaintStatus`, and the
missing-customer-email fallback called the same invalid method before writing
the internal notification. The shared `NcrSeverity` enum used by the Blade
view also has no `label()` method.

The fix is contained in the complaint notification path:

- `api/app/Modules/CRM/Listeners/EmailCustomerOnComplaintUpdated.php:61-67`
  now converts a backed enum value with `Str::headline`.
- `api/resources/views/emails/customer/complaint-update.blade.php:1-20`
  derives both status and severity values and renders them without calling an
  unavailable enum method.
- `api/tests/Feature/CRM/ComplaintEmailTest.php:28-74` covers the valid-email
  mailable render and missing-email internal fallback.

## M034-R09 — invalid internal customer filter (P2, Incomplete)

`ComplaintController::index` previously turned an invalid customer hash into
`null`, and `ComplaintService::list` interpreted that as no customer filter.
The controller now substitutes an impossible positive-key-space value (`-1`)
for an invalid supplied hash at
`api/app/Modules/CRM/Controllers/ComplaintController.php:30-40`. The regression
at `api/tests/Feature/CRM/ComplaintSourceValidationTest.php:115-139` confirms a
malformed filter returns no complaints rather than all complaints.

## M034-R10 — NCR list/UI contract drift (P2, Incomplete)

The internal list query selected only NCR status even though the resource
returned severity, and the resource omitted disposition although the server
requires it for resolve/close. The list projection at
`api/app/Modules/CRM/Services/ComplaintService.php:70-77` now includes both;
`CustomerComplaintResource.php:46-54` exposes disposition; and the SPA type
and completion gate at `spa/src/types/crm.ts:201` and
`spa/src/pages/crm/complaints/detail.tsx:149-152` mirror the server contract.
The API regression is at `ComplaintSourceValidationTest.php:141-169`.

## Continued-session verification

The new tests were first run red before each fix and green after the fix. The
full M034 sweep, including `ComplaintEmailTest`, runs on isolated PostgreSQL
database `ogami_test_m034_20260827`: 62 tests and 205 assertions passed with
0 failures. PHP lint, `git diff --check`, SPA typecheck, and scoped ESLint pass.
Laravel Pint still reports pre-existing
formatting drift in several legacy files; no unrelated formatter rewrite was
included. Two-worker concurrency and browser/e2e evidence remain deferred.
