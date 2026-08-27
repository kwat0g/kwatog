# M019 — People / Leave Management fix log

Audit date: 2026-08-25 (session 1) · 2026-08-26 (session 2, resumed)  
Module status: 🔁 Needs Re-audit  
Implementation status: **F01–F09 applied (session 1) and now runtime-verified on PostgreSQL and in
Chromium (session 2); F10 deferred — employee-master scope + product decision; F11 has one residual
gap (Vitest), blocked on a root-owned directory; F20 applied (session 3)**

## Session 2 (2026-08-26) — runtime verification of the inherited work, and the fixtures it invalidated

Session 1's F01–F09 code was committed (`167de85e`) but never executed: no PostgreSQL was
reachable, so the fix-log's "Focused Leave feature suite: BLOCKED" line stood. Session 2 ran it.

Baseline on entry, `ogami_test_s2`, `tests/Feature/Leave` + `LeaveNotificationTest`:
**12 failed / 45 passed (418 assertions)**. All 10 `LeaveRequestHardeningTest` tests — the
executable evidence for F01, F02, F03, F04, F06, F07, F08, F09 — passed on the first run, so
the inherited production code is confirmed correct. Every one of the 12 failures was a
**pre-existing fixture that the new invariants invalidated**, not a defect in them.

### Verdict on the 10 "Leave balance is not initialized" failures — FIXTURE GAP, not a defect

The guard at `api/app/Modules/Leave/Services/LeaveRequestService.php:232-236` is the intended
production contract, and the evidence is threefold:

1. **The production creation path always satisfies it.**
   `api/app/Modules/HR/Services/EmployeeService.php:224-241` seeds one
   `employee_leave_balances` row per active leave type, for the current year, synchronously
   inside the same transaction as the employee insert. A real employee therefore always has a
   current-year balance row. Every failing test builds its employee with
   `Employee::factory()` or a raw `DB::table('employees')->insert`, bypassing that service.
2. **Failing open was the documented defect.** `audit-report.md:117-125` (F08) records that
   without this guard submission proceeds, and HR approval then hits
   `LeaveBalanceService::consume()`'s `firstOrFail()`, which the controller does not catch —
   an HTTP 500. `action-plan.md:18` states the acceptance criterion verbatim: "Inactive types
   and missing balance rows fail at submission with controlled copy; HR approval never turns a
   seeding gap into a 500." The guard is that criterion.
3. **The typed-exception choice corroborates it.** `BusinessRuleException` renders 422 with
   actionable copy ("Contact HR before submitting"), which is the "typed 422/manual-recovery
   state for seeding gaps" the report asked for. `LeaveRequestHardeningTest::test_missing_balance_is_rejected_at_submission`
   pins it deliberately.

Seeding the balance in the fixtures is therefore restoring a precondition production
guarantees — not suppressing a signal.

### S2-01 — `HalfDayLeaveOverlapTest` fixture never initialized a balance (4 failures)

- Failure: `BusinessRuleException: Leave balance is not initialized for this employee, leave
  type, and year.` thrown from `LeaveRequestService.php:233` on the *first* `submit()` of each
  test, i.e. before the overlap rule under test was reached. Only
  `test_half_day_must_be_single_date` passed, because its `InvalidArgumentException` is raised
  at line 197 — ahead of the balance lookup.
- Before: `api/tests/Feature/Leave/HalfDayLeaveOverlapTest.php:129-140` — `makeFixtures()`
  returned `[Employee::factory()->create(), LeaveType::query()->first()]` with no balance row.
- After: `api/tests/Feature/Leave/HalfDayLeaveOverlapTest.php:130-166` — `makeFixtures()` now
  inserts an `EmployeeLeaveBalance` (10 credits) for the current year **and the next**, since
  the request dates are `now()`-relative and a late-December run would otherwise land in a
  year with no row. Comment cites `EmployeeService::create()` as the path being stood in for.

### S2-02 — `LeaveRequestBulkApproveTest` fixture never initialized a balance (4 failures)

- Failure: same `BusinessRuleException` at `LeaveRequestService.php:233`, on all four tests.
- Before: `api/tests/Feature/Leave/LeaveRequestBulkApproveTest.php:49-58,104-121,152-162,211-219`
  — four independent employee fixtures, no balance rows.
- After: added one `balanceFor(Employee, LeaveType, float $credits = 20.0)` helper at
  `api/tests/Feature/Leave/LeaveRequestBulkApproveTest.php:27-49` (same two-year rationale) and
  called it before each `submit()` at lines 74, 128, 180 and 236.

### S2-03 — `LeaveRequestVisibilityTest::only_hr_or_admin_may_file_for_another_employee` (1 failure)

- Failure: `Expected response status code [201] but received 422` at
  `LeaveRequestVisibilityTest.php:217` — the HTTP surface of the same missing balance. The test
  read HR's 422 as "HR was refused", which inverts the rule it exists to pin.
- Before: `api/tests/Feature/Leave/LeaveRequestVisibilityTest.php:79-82` — three factory
  employees, no balance rows.
- After: `api/tests/Feature/Leave/LeaveRequestVisibilityTest.php:80-107` — `setUp()` seeds a
  balance for all three employees (`alphaHead`, `alphaMember`, `betaMember`) for this year and
  the next. The seven read-scope tests in the file were already green and stay green.

### S2-04 — `LeaveOverlapTwoConnectionHarnessTest` fixture never initialized a balance (1 failure)

- **Not** the shared two-connection harness defect. Three other modules fail theirs with
  `SQLSTATE[42703] column "key" does not exist`; this one failed with
  `-'success' / +'error:Leave balance is not initialized for this employee, leave type, and
  year. Contact HR before submitting.'` at `LeaveOverlapTwoConnectionHarnessTest.php:77` — the
  forked child's `submit()` was refused, so the harness proved nothing about the employee lock.
  Owned by this module and fixed here.
- Before: `api/tests/Feature/Leave/LeaveOverlapTwoConnectionHarnessTest.php:40-43` — raw
  inserts for department/position/employee/leave-type/workflow, but no
  `employee_leave_balances` row.
- After: `api/tests/Feature/Leave/LeaveOverlapTwoConnectionHarnessTest.php:44-56` — raw insert
  of a 10-credit balance for **year 2026**, matching the harness's hardcoded `2026-10-05`…
  `2026-10-07` request dates (not `now()`-derived, so no two-year hedge is needed here).

### S2-05 — `LeaveNotificationTest` dept-head approver was unlinked, so F01 refused it (2 failures)

- Failure: `ForbiddenActionException: Department heads may only decide leave requests for their
  own department.` from `LeaveRequestService.php:654`, reached via `:307` (`approveDept`) and
  `:423` (`reject`).
- Diagnosis — fixture gap, and the guard is right. `userWithRole('department_head')` creates a
  `User` with **no `employee_id`**, so `assertDepartmentDecisionScope()` resolves a null
  approver department and fails closed. That is the same rule the visibility matrix already
  pins for reads — `LeaveRequestVisibilityTest::test_an_unlinked_department_head_sees_nothing`
  ("must collapse to nothing, never widen to everything"). A department head who cannot *see* a
  request must not be able to *decide* it, so refusing an unlinked approver is the correct
  production behaviour; the fixture simply predates the guard.
- Before: `api/tests/Feature/Notifications/LeaveNotificationTest.php:126,166` — both used
  `$this->userWithRole('department_head')`.
- After: added `deptHeadFor(LeaveRequest $req)` at
  `api/tests/Feature/Notifications/LeaveNotificationTest.php:53-79`, which resolves the
  request employee's `department_id` and links the approver to a fresh employee in that same
  department. Call sites updated at lines 150 and 190. `userWithRole()` is retained — the
  `hr_officer` test still uses it, correctly, because HR holds the company-wide override.

### Session 2 result

`tests/Feature/Leave` + `tests/Feature/Notifications/LeaveNotificationTest`:
**57 passed / 0 failed (448 assertions)** — up from 45 passed / 12 failed, with **no backend
production code changed**: all twelve were fixtures. F11's PostgreSQL half is now satisfied. (The
browser half, below, did surface one real production defect.)

## Session 2 (browser half) — the self-service leave page was crashing, and the E2E mocks hid it

Getting Playwright to run at all needed a workaround for a root-owned directory (see
"Environment defects" below). Once it ran, `chain-leave.spec.ts` produced a **reproducible** real
failure — not a flake, and not a fixture-only problem.

### S2-06 — session 1 moved the self-service page onto a new endpoint and left two mocks behind

- Failure: `e2e/chain-leave.spec.ts:127` "employee files leave → status pending_dept" timed out on
  `getByRole('button', { name: /new request/i })`. The page snapshot showed the app shell rendering
  normally with `main` replaced by the ErrorBoundary — *"Something went wrong … ERR-932L8C"*. So
  the whole page was dead, not just a missing button.
- Root cause chain, and it is worth stating exactly because it is a trap the harness documents:
  1. Session 1 changed the read at `spa/src/pages/self-service/leave.tsx:117` from
     `leaveRequestsApi.list()` (`/leaves/requests`) to `selfServiceApi.leaveRequests()`
     (`/hr/self-service/leave-requests`). Verified the new route exists:
     `route:list --path=self-service` → `GET api/v1/hr/self-service/leave-requests`. The narrower
     self-scoped endpoint is the **correct** read for a self-service page, so the production change
     stands.
  2. The spec still mocked the abandoned URL, so the new one fell through to the auto
     `apiFallback` fixture in `e2e/fixtures.ts`, which answers `{}` by design.
  3. `selfServiceApi.leaveRequests` returns `r.data` — the whole envelope, not `r.data.data`. So
     `data` came back **truthy** (`{}`) with `data.data === undefined`, and
     `data.data.length` threw a TypeError. This is precisely the hazard `fixtures.ts` warns about
     in its own comment: *"A fallback must be indistinguishable from absent, not a
     plausible-looking impostor."* For a function returning `r.data.data` the `{}` fallback is
     indistinguishable from absent; for one returning `r.data` it is an impostor.
  4. Separately, session 1's F08 SPA half added `params: { is_active: 'true' }` at
     `spa/src/api/self-service.ts:127`, so the types URL gained a query string and the spec's
     `'**/api/v1/leaves/types'` glob stopped matching it (a Playwright glob `*` does not span the
     `?`). That alone would have emptied the leave-type `<Select>`.
- Before: `spa/e2e/chain-leave.spec.ts` mocked `'**/api/v1/leaves/types'` and
  `'**/api/v1/leaves/requests?*'`.
- After: `spa/e2e/chain-leave.spec.ts:129-142` mocks `'**/api/v1/leaves/types*'`, and
  `:174-187` replaces the stale list mock with `'**/api/v1/hr/self-service/leave-requests*'`. The
  POST mock at `:162-173` is unchanged and still correct — `fileLeaveSelf` still posts to
  `/leaves/requests` (`spa/src/api/self-service.ts:135-148`).

### S2-07 — one malformed response should not blank the page (the defect S2-06 exposed)

- Before: `spa/src/pages/self-service/leave.tsx:207,208,231,244,245` read `data.data.length` and
  `data.data` directly off the envelope at four sites, while `pendingCount` on the line above was
  already written defensively as `(data?.data ?? [])`. A response missing its `data` array
  therefore threw during render and the ErrorBoundary swallowed the entire page — including the
  `isError` "Couldn't load leaves / Retry" branch that exists for exactly this situation.
- After: `spa/src/pages/self-service/leave.tsx:203-212` narrows once with `const rows = data?.data ?? []`
  and lines 217, 218, 241, 254, 255 read `rows`. The `data &&` guards are deliberately kept so the
  empty state still cannot appear during the initial load — semantics are identical whenever
  `data.data` is an array, and degrade to "no rows" instead of a crash when it is not.
- This is a real robustness fix, not test-shaping: after S2-06 the spec passes with or without it.

### S2-08 — stale mock field in the bulk-actions fixture

- Before: `spa/e2e/bulk-actions.spec.ts:34` mocked `document_path: null`, a field
  `LeaveRequestResource` no longer sends — session 1 replaced it with `has_document` so the
  private-disk path is never exposed to a client.
- After: `spa/e2e/bulk-actions.spec.ts:39-41` sends `has_document: false` with a note on why the
  path is absent. No behavioural change (nothing reads either field today — see open question 2),
  but the fixture now describes the real payload.

### Session 2 browser result

`chain-leave.spec.ts` + `bulk-actions.spec.ts` on desktop-chromium: **15 passed / 0 failed**, from
11 failed / 4 passed. Each spec also confirmed green in isolation (4/4 and 11/11).

## Fixes applied (session 1)

### M019-F01 — department decision scope

- Before: single and bulk department decisions checked only workflow role and permission; a department head could mutate another department's request.
- After: api/app/Modules/Leave/Services/LeaveRequestService.php:303-324,415-438,631-653 locks the request, requires the approver's department to match the target employee, and applies the same check to single/bulk approve and reject paths. HR/admin users with the company-wide HR approval grant retain the explicit override.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:101-135.

### M019-F02 — cancellation authorization and actor audit

- Before: any caller with leave.create could cancel an arbitrary request; cancellation did not record who acted.
- After: api/app/Modules/Leave/Services/LeaveRequestService.php:441-465 locks the request, permits only the request owner or HR/admin override, and records cancelled_by/cancelled_at. The schema, relation, and resource are in api/database/migrations/2026_08_25_210000_harden_leave_request_integrity.php:13-25, api/app/Modules/Leave/Models/LeaveRequest.php:29-70, and api/app/Modules/Leave/Resources/LeaveRequestResource.php:46-51.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:137-158.

### M019-F03 — payroll-locked attendance dates

- Before: leave approval/cancellation directly rewrote or deleted attendance without checking finalized/disbursed/voided payroll periods.
- After: api/app/Modules/Leave/Services/LeaveRequestService.php:327-354,583-629 uses the existing authoritative AttendanceDateMutabilityGuard before every attendance mutation, inside the approval/cancellation transaction. A guard failure rolls back status and balance side effects.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:160-192.

### M019-F04 — half-day representation and attendance rollback

- Before: AM/PM approval was written as a full-day on_leave row and cancellation could delete a pre-existing punch.
- After: api/app/Modules/Leave/Services/LeaveRequestService.php:491-575,583-629 snapshots the prior row, leaves an existing DTR intact for half-day leave, marks only full-day rows, and restores/deletes only the exact lineage it owns. The snapshot column is added by api/database/migrations/2026_08_25_210000_harden_leave_request_integrity.php:13-17.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:194-272. The selected canonical half-day representation (the leave request plus unchanged date-level DTR) should be confirmed in a payroll integration rehearsal.

### M019-F05 — rollover retry preservation

- Before: January updateOrInsert reset an already-consumed target balance's used and remaining values on retry.
- After: api/app/Console/Commands/ResetLeaveBalancesForYear.php:110-145 locks and preserves an existing target row; only a missing row is inserted.
- Regression coverage: api/tests/Feature/Leave/YearEndLeaveReconciliationTest.php:132-140.

### M019-F06 — cross-year balance allocation

- Before: one request could span December/January while approval and cancellation charged only the start year's balance.
- After: the service rejects cross-year ranges at api/app/Modules/Leave/Services/LeaveRequestService.php:179-188, mirrored by both SPA forms at spa/src/pages/leaves/create.tsx:33-38 and spa/src/pages/self-service/leave.tsx:41-50.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:351-367.

### M019-F07 — server-owned required documents

- Before: callers supplied arbitrary document_path strings and required document metadata was never enforced.
- After: api/app/Modules/Leave/Requests/StoreLeaveRequestRequest.php:40-43 accepts validated uploads; api/app/Modules/Leave/Services/LeaveRequestService.php:211-222,480-487 enforces required files and stores them on the private local disk. The resource exposes only has_document at api/app/Modules/Leave/Resources/LeaveRequestResource.php:31-35; both filing forms provide upload controls at spa/src/pages/leaves/create.tsx:152-162 and spa/src/pages/self-service/leave.tsx:308-316.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:274-304. Retention duration and secure HR document-view/download policy remain unspecified and should be confirmed during re-audit.

### M019-F08 — active type and balance invariants

- Before: inactive types and missing balances could enter the workflow, with a later missing-row firstOrFail becoming a 500.
- After: submission now requires an active type and initialized locked balance at api/app/Modules/Leave/Services/LeaveRequestService.php:206-239; consume/restore fail with typed business errors at api/app/Modules/Leave/Services/LeaveBalanceService.php:27-72. The SPA filters active types at spa/src/api/leave/index.ts:23-26 and spa/src/api/self-service.ts:126-128.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:306-349 and api/tests/Feature/Leave/LeaveBalanceTest.php:205-212.

### M019-F09 — archive restore

- Before: the soft-deleted type could not bind to the restore route.
- After: api/app/Modules/Leave/routes.php:15-20 opts the restore route into withTrashed(), and api/app/Modules/Leave/Services/LeaveTypeService.php:43-55 performs a locked transactional restore exposed by api/app/Modules/Leave/Controllers/LeaveTypeController.php:52-57.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:369-380.

## Deferred items

- **M019-F10 remains deferred — hard scope constraint plus an open business decision.**
  The two competing initializers are `api/app/Modules/HR/Services/EmployeeService.php:224-241`
  (synchronous, full-year credit, `updateOrInsert`) and
  `api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:38-66` (queued, hire-date prorated,
  `insertOrIgnore` — therefore a no-op whenever the synchronous path ran first). Both live in the
  **employee-master** module, which this audit claim may read but not modify. Session 2 confirmed
  the conflict is still present and unchanged. Deciding which path owns proration is also a
  product question (does a mid-year hire get a full-year credit or a prorated one, and is the
  answer retroactive for employees already created?), so it needs a human decision, not a guess.
- **M019-F11 is now largely closed.** The PostgreSQL API/integration half is done — see the
  session 2 section above and the verification table below. Remaining gap: **Vitest** was still
  not run (see the environment note below).

## Environment defects found in session 2 (not this module's code — reported, not fixed)

1. **`spa/node_modules/.vite-temp` is root-owned** (`drwxr-xr-x root root`, uid 0). Vite's default
   `bundle` config loader must write a timestamped module there, so `npm run dev`, `vite build`,
   Vitest and Playwright's `webServer` all die with
   `EACCES: permission denied, open '…/node_modules/.vite-temp/vite.config.ts.timestamp-….mjs'`.
   `spa/test-results/` is root-owned too, which additionally breaks Playwright's reporter
   (`EACCES … test-results/.last-run.json`). This is the blocker both earlier sessions recorded as
   "SPA unit tests/browser smoke: NOT RUN". Repair needs root and one command:
   `sudo rm -rf spa/node_modules/.vite-temp spa/test-results`. There is no passwordless sudo in
   this workspace, so session 2 could not do it.
   Session 2 worked around it *without touching any tracked file* — an ephemeral
   `spa/vite.e2e-s2.config.mts` (`import.meta.dirname` instead of `__dirname`, `cacheDir` under
   `/tmp`) run via `vite --configLoader runner`, plus `playwright test --output=/tmp/…`. The
   ephemeral config has been deleted; nothing about it remains in the tree.
2. **`qrcode` is imported but not installed** (`spa/src/pages/assets/detail.tsx:6`). It fails
   `npm run typecheck` (2 of the 3 remaining errors) and makes Vite log
   "Failed to run dependency scan. Skipping dependency pre-bundling." Assets module, not Leave.
3. **`spa/src/pages/return-management/detail.tsx:902`** — `TS17001: JSX elements cannot have
   multiple attributes with the same name`. Return-management module, not Leave.
4. **`api/tests/Feature/Calendar/CalendarAggregatorTest.php:45`** calls
   `$departments->lastOrFail()`, which does not exist on an Eloquent Collection —
   `BadMethodCallException` in `setUp()` fails all 7 tests in the file. The equivalent leave test
   uses `->last()` correctly (`LeaveRequestVisibilityTest.php:72`), so this looks like a
   mis-copied line. It also poisons whatever runs after it in the same process:
   `tests/Feature/HR/SelfServiceOwnerScopeTest.php` failed alongside it and **passes** both alone
   and when run with the Leave suite. Calendar is a cross-cutting Common surface, not Leave.
5. **Playwright cold-start flake at 4 workers.** With pre-bundling skipped (defect 2), a first
   run of `chain-leave` + `bulk-actions` timed out 11 of 15 tests at 30 s; the same specs pass
   15/15 once Vite is warm. Worth knowing before reading a first-run E2E failure as a regression.

## Open questions for a human

1. **Next-year leave cannot be filed in advance.** `EmployeeService::create()` seeds balances for
   the **current year only**, and next-year rows appear only when the January rollover command
   runs (`api/routes/console.php`, first 7 days of January). Since F08 now requires an initialized
   balance and F06 rejects cross-year ranges, an employee filing in e.g. November for January
   dates is refused with "Leave balance is not initialized … Contact HR" — even though the
   request-date window allows 365 days ahead
   (`api/database/migrations/0318_seed_remaining_reporting_window_settings.php:15-16`). This is a
   controlled 422 with actionable copy, so it is strictly better than the 500 it replaced, but
   whether advance cross-year filing should be *possible* is a policy call, not a code defect.
   Fixing it would mean seeding N+1 balances at hire, or lazily initializing on first request for
   a future year.
2. **Required-document retention and HR read access are still unspecified.** F07 stores the upload
   on the private disk and the resource exposes only `has_document`
   (`api/app/Modules/Leave/Resources/LeaveRequestResource.php:35`). Session 2 confirmed **no page
   reads `has_document`** — `grep -rn 'has_document' spa/src/pages spa/src/api` returns nothing —
   and there is no download route. So an approver currently cannot see or open the document the
   system just made mandatory. Closing this needs decisions on who may download, for how long the
   file is kept, and whether the link is signed; it is new surface, not a repair.

## Verification performed

### Session 2 (2026-08-26) — `ogami_test_s2`, PostgreSQL

| Check | Result | Notes |
|---|---|---|
| `tests/Feature/Leave` + `LeaveNotificationTest` — BEFORE | 12 failed / 45 passed | 418 assertions. All 12 were stale fixtures; see the session 2 section. |
| `tests/Feature/Leave` + `LeaveNotificationTest` — AFTER | **57 passed / 0 failed** | 448 assertions. Includes the pcntl two-connection harness and all 10 hardening tests. |
| Leave + adjacent modules | **96 passed / 0 failed** | 644 assertions. Adds `HR/SelfServiceOwnerScopeTest`, `Auth/RoleResponsibilityAlignmentTest`, `Payroll/PayrollCalculatorServiceTest` — the three suites outside `tests/Feature/Leave` that touch leave models/endpoints. |
| PHP syntax | PASS | `php -l` on all five modified test files. |
| Route registration | PASS | `route:list --path=self-service` confirms `GET api/v1/hr/self-service/leave-requests` exists — the endpoint the self-service page moved onto. |
| SPA typecheck | PASS for Leave | `tsc --noEmit`: 3 errors remain, all in `assets/detail.tsx` and `return-management/detail.tsx` (environment defects 2 and 3). Zero in Leave. |
| Targeted SPA lint | PASS | ESLint clean on `src/pages/leaves`, `src/pages/self-service/leave.tsx`, `src/api/leave`, `src/types/leave.ts`, `e2e/chain-leave.spec.ts`, `e2e/bulk-actions.spec.ts`. |
| Playwright `chain-leave` + `bulk-actions` — BEFORE | 11 failed / 4 passed | 1 reproducible real failure (self-service ErrorBoundary) + 10 cold-start timeouts. |
| Playwright `chain-leave` + `bulk-actions` — AFTER | **15 passed / 0 failed** | desktop-chromium. Confirmed reproducible: `bulk-actions` alone 11/11, `chain-leave` alone 4/4. |
| Playwright environment baseline | 33 passed / 1 failed | `e2e/rbac-roles.spec.ts` — run to prove the Vite workaround is sound rather than masking failures. The single failure is a quality-module page, not Leave. |
| Vitest (`npm run test:run`) | NOT RUN | Blocked by environment defect 1; needs root. |
| Live API / payroll integration rehearsal | NOT RUN | No production-like HR/payroll dataset. F04's canonical half-day representation still wants that rehearsal. |

### Session 1 (2026-08-25)

| Check | Result | Notes |
|---|---|---|
| PHP syntax | PASS | Leave PHP, Leave tests, rollover command, and migration passed php -l. |
| Route registration | PASS | php artisan route:list --path=leaves shows 20 routes. |
| Diff whitespace | PASS | git diff --check passed for targeted tracked changes. |
| SPA typecheck | PASS | npm run typecheck. |
| Targeted SPA lint | PASS | ESLint passed all changed Leave API/page/type files. |
| Full SPA lint | BLOCKED | Four pre-existing errors remain in unrelated hook/accounting/quality files. |
| Focused Leave feature suite | BLOCKED | PostgreSQL host db unresolved; 52 tests reported, 0 assertions. |
| SPA unit tests/browser smoke | NOT RUN | Prior audit's root-owned Vite cache/live-auth blockers remain. |
| Live API/payroll integration | NOT RUN | No reachable PostgreSQL/production-like dataset. |

## Release handoff

Release M019 as 🔁 Needs Re-audit — **not because any plan item is unfixed in code, but because
two of the eleven are genuinely not this session's to close.** F01–F09 are implemented and now
runtime-proven on PostgreSQL and in Chromium. F10 needs an employee-master change plus a product
decision on proration ownership. F11 has one residual gap (Vitest), blocked on a root-owned
directory.

The next session should, in order: (1) get root to run
`sudo rm -rf spa/node_modules/.vite-temp spa/test-results` and install `qrcode`, then run Vitest;
(2) take the F10 proration decision with the product owner and apply it in employee-master;
(3) answer the two open questions above — advance cross-year filing, and document
retention/download — since the second currently leaves an approver unable to view a document the
system requires. Only then is ✅ Verified defensible.

## Session 3 (2026-08-27) — M019-F20 archived leave type actions

- Before: the leave-type management modal rendered Edit for every row, including archived rows
  whose normal update route excludes soft-deleted models.
- After: `spa/src/pages/leaves/types.tsx` derives archived state from the existing `deleted_at`
  resource field (with the archived-only scope as a safe fallback), hides Edit for archived rows,
  and keeps Restore as their available archive action. Active rows retain Edit and Archive. The
  SPA leave type contract now declares the existing optional `deleted_at` field in
  `spa/src/types/leave.ts`.
- Regression coverage: `spa/src/pages/leaves/types.test.tsx` verifies active Edit/Archive actions,
  archived Restore-only actions in the All view, and Restore-only actions in the Archived view.
- Verification: focused Vitest passed (1 file / 1 test) using runner mode with an ephemeral
  `/tmp` cache because the normal config cannot write the root-owned `spa/node_modules/.vite-temp`;
  targeted ESLint passed. Full SPA typecheck remains blocked by the pre-existing missing `qrcode`
  module errors in `spa/src/pages/assets/detail.tsx`.

Module status: 🔁 Needs Re-audit — M019-F20 is applied; the remaining open findings stay deferred.

## Session 4 (2026-08-27) — M019-F21 zero-business-day leave rejection

- Applied the server-side guard in `api/app/Modules/Leave/Services/LeaveRequestService.php` immediately after the existing business-day calculation. Full-day ranges with a computed total of zero now raise a typed 422 business-rule response before balance, overlap, approval, or persistence work can create a record.
- Aligned both leave filing forms (`spa/src/pages/leaves/create.tsx` and `spa/src/pages/self-service/leave.tsx`) with the same Monday–Saturday estimate and added the matching full-day date-range validation message. Half-day behavior remains unchanged.
- Added `api/tests/Feature/Leave/LeaveBusinessDayValidationTest.php` covering API rejection/no record for a Sunday-only range and the existing one-day pending behavior for a valid business day. Focused API verification: **2 passed / 0 failed (7 assertions)** on `DB_DATABASE=ogami_test_m019_f21`.
- Extended verification: the complete `tests/Feature/Leave` suite passed **54/54 (447 assertions)** on the same database; targeted ESLint passed for both changed SPA forms; PHP lint passed for the changed service and regression.
- SPA `npm run typecheck` remains blocked by the pre-existing unrelated `src/pages/assets/detail.tsx` missing `qrcode` module/type errors. No dependency change was made.

M019-F21 is applied. M019-F10/F12–F19 remain deferred and are not part of this change; final status remains 🔁 Needs Re-audit.
