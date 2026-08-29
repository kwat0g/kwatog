# M019 — People / Leave Management Audit Report

Audit date: 2026-08-27
Agent: batch4-agent-a
Claimed module: M019 (people/leave-management)
Database used for verification: ogami_test_m019_agent_a
Final status: 📋 Plan Ready

## Scope and gate

The refreshed module registry identifies M019 as a tier-4 module with a medium
surface. The atomic claim succeeded for the preferred target:

    audit/scripts/claim-module.sh people leave-management
    result: CLAIMED

I read the registry, M019 inventory, prior report/action plan/fix log, current
implementation and tests, scoped git diff, and file mtimes. Before this
session's documentation updates, the module implementation had no uncommitted
diff. Dependencies were read only for context; no dependency, shared root
configuration, or generated registry file was changed.

The current plan is not eligible for same-session implementation: the open work
is predominantly medium/large and separate-recommended, includes cross-module
employee-master behavior and financial/calendar contract decisions, and the
browser suite is infrastructure-blocked. No production code was fixed in this
session.

## Verification evidence

Focused checks completed:

- docker compose exec -T -e DB_DATABASE=ogami_test_m019_agent_a api php artisan test tests/Feature/Leave --no-coverage
  — PASS, 52 tests / 440 assertions.
- docker compose exec -T -e DB_DATABASE=ogami_test_m019_agent_a api php artisan test tests/Feature/Notifications/LeaveNotificationTest.php --no-coverage
  — PASS, 5 tests / 8 assertions.
- PHP lint over api/app/Modules/Leave — PASS.
- php artisan route:list --path=leaves — PASS, 20 leave routes registered.
- Scoped SPA ESLint over leave pages/API — PASS with zero warnings.
- npm run typecheck in the SPA container — PASS.
- git diff --check — PASS.

The focused Playwright command was attempted:

    docker compose exec -T spa npx playwright test \
      e2e/chain-leave.spec.ts e2e/mobile/self-service-mobile.spec.ts

All 18 selected tests stopped before application assertions because the
container lacks both the Chromium headless shell and Firefox executables:

    /root/.cache/ms-playwright/chromium_headless_shell-1223/.../chrome-headless-shell
    /root/.cache/ms-playwright/firefox-1522/firefox/firefox

The failed run created spa/test-results as root-owned output. It was moved to
the exact container temporary path /tmp/ogami-e2e-artifacts-m019-agent-a;
spa/test-results is absent from the workspace. This is an environment blocker,
not an application assertion result.

A focused runtime probe on the unique database also confirmed the year-end
no-salary and archived-type cases described below. The scratch database is
isolated from ogami_test; no shared test database was used.

## Discovery pass — previously hardened findings

The following earlier findings were rechecked against current code and focused
tests and are closed for this audit:

- F01 department-head decision scope: api/app/Modules/Leave/Services/LeaveRequestService.php:303-325,639-658;
  regression coverage in api/tests/Feature/Leave/LeaveRequestHardeningTest.php:115-135.
- F02 cancellation owner/HR authorization:
  api/app/Modules/Leave/Services/LeaveRequestService.php:441-467;
  api/tests/Feature/Leave/LeaveRequestHardeningTest.php:137-157.
- F03 locked payroll attendance protection:
  api/app/Modules/Leave/Services/LeaveRequestService.php:504-506,596;
  api/app/Modules/Leave/Services/AttendanceDateMutabilityGuard.php:20-58;
  api/tests/Feature/Leave/LeaveRequestHardeningTest.php:160-192.
- F04 half-day attendance safety is explicit in the current contract:
  api/app/Modules/Leave/Services/LeaveRequestService.php:514-530;
  api/tests/Feature/Leave/LeaveRequestHardeningTest.php:194-234.
- F05 retry-safe year-end rollover:
  api/app/Console/Commands/ResetLeaveBalancesForYear.php:112-146;
  api/tests/Feature/Leave/YearEndLeaveReconciliationTest.php:111-140.
- F06 cross-year request rejection:
  api/app/Modules/Leave/Services/LeaveRequestService.php:184-188;
  api/tests/Feature/Leave/LeaveRequestHardeningTest.php:351-366.
- F07 required submission documents:
  api/app/Modules/Leave/Requests/StoreLeaveRequestRequest.php:40-43;
  api/app/Modules/Leave/Services/LeaveRequestService.php:211-222;
  api/tests/Feature/Leave/LeaveRequestHardeningTest.php:274-304.
- F08 active leave type and missing balance validation:
  api/app/Modules/Leave/Services/LeaveRequestService.php:206-239;
  api/tests/Feature/Leave/LeaveRequestHardeningTest.php:306-349.
- F09 archived leave-type restore:
  api/app/Modules/Leave/routes.php:18-20;
  api/app/Modules/Leave/Services/LeaveTypeService.php:45-54;
  api/tests/Feature/Leave/LeaveRequestHardeningTest.php:369-381.

## Hardening pass — current findings

### M019-F10 — Incomplete / P1 — hire-date proration is bypassed by synchronous balance seeding

api/app/Modules/HR/Services/EmployeeService.php:223-240 synchronously
upserts the current year's balance using the leave type's full
default_balance. The queued listener
api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:38-66 computes
hire-year proration but uses insertOrIgnore, so it cannot replace the row
already created by the employee service. A mid-year hire can therefore retain
full-year credits instead of the prorated amount. This crosses the
employee-master dependency boundary and was not changed here.

### M019-F11 — Incomplete / P1 — regression coverage does not pin all leave contracts

The focused Leave and notification suites are green, but executable coverage is
still absent for several high-risk contracts: supporting-document read/action,
missing-salary year-end handling, carryover API round-trip, conversion-rate
bounds, archived relation responses, active/inactive and Sunday/half-day
calendar semantics, invalid CLI/API years, and notification-link routing.
The two selected Playwright suites could not run because the browser binaries
are absent. This makes the current green result narrower than the module
surface.

### M019-F12 — Incomplete / P1 — required supporting documents are write-only

api/app/Modules/Leave/Services/LeaveRequestService.php:480-488 stores an
uploaded document on a private local disk. The resource exposes only the
boolean has_document at
api/app/Modules/Leave/Resources/LeaveRequestResource.php:33-35; there is no
authorized download/view route in api/app/Modules/Leave/routes.php:35-47.
The HR detail page
spa/src/pages/leaves/detail.tsx:128-147 renders the reason and decision
information but no document review action. The existing test proves storage,
not that an authorized reviewer can inspect the required evidence.

### M019-F13 — Broken / P1 — no-salary year-end encashment destroys days without value or recovery

api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:122-150 zeroes a positive
balance before recording its disposition. dailyRate() returns zero when the
employee has no authoritative monthly/semi-monthly salary at
api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:210-215; the adjustment is
created only when cashValue > 0 at lines 146-150. The disposition is still
recorded with converted days and cash_value zero at lines 154-163, with no
failure or manual recovery path.

Runtime evidence on ogami_test_m019_agent_a produced:

    {"days_converted":"5.0","cash_value":"0.00","adjustments":0,"remaining":"0.0"}

This conflicts with the payroll calculator's fail-closed no-rate behavior at
api/app/Modules/Payroll/Services/PayrollCalculatorService.php:180-184.

### M019-F14 — Incomplete / P1 — carryover and conversion policy fields are not an API round-trip

The carryover migration/model and SPA expose max_carryover_days:
api/database/migrations/0263_add_max_carryover_days_to_leave_types.php:10-23,
api/app/Modules/Leave/Models/LeaveType.php:18-33, and
spa/src/pages/leaves/types.tsx:26-48,108-116,173-184. However,
StoreLeaveTypeRequest.php:28-38 and UpdateLeaveTypeRequest.php:27-40 omit
the field, while LeaveTypeResource.php:14-27 omits it from responses.
The UI can display a field and submit successfully while the backend silently
discards it. The year-end job consumes the cap at
api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:105,132-135, so this is
an unreachable business rule through the management API.

The same contract needs an explicit conversion-rate invariant: the requests
allow up to 9.99 at StoreLeaveTypeRequest.php:36 and
UpdateLeaveTypeRequest.php:38, while the year-end job clamps the value to
0..1 at ProcessYearEndLeave.php:105. An operator-provided value can therefore
be silently changed at processing time.

### M019-F15 — Broken / P1 — year-end payroll money uses floats

api/app/Common/Support/Money.php:7-12 establishes the repository invariant
that currency is represented as exact strings and that floats are not used for
money. The year-end job instead uses float daily rates, converted days, and
cash values at
api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:146-160,210-237, then
rounds and formats the result. This is financial payroll input and can produce
cent-level errors at decimal boundaries. It needs an explicit exact-money and
rounding contract plus adversarial tests.

### M019-F16 — Broken / P1 — leave calendar counts rows, not active employee/day coverage

api/app/Modules/Leave/Controllers/LeaveCalendarController.php:41-52 counts
active employees for headcount but does not constrain the leave query to active
employees. At lines 57-63 it subtracts approved request rows from headcount. A
terminated employee's approved leave can therefore reduce the present count
even though that employee is excluded from headcount.

The same row count mishandles fractions and overlap:
LeaveRequestService.php:259-265 intentionally permits AM and PM half-day
requests for one employee/day, while the calendar marks only the half-day
metadata at LeaveCalendarController.php:73-80 and still counts each request as
one absent employee. A single half-day is also counted as a full absence.
Finally, request filing excludes Sundays at
LeaveRequestService.php:469-477, but the calendar loops dates without the same
non-working-day rule. A runtime probe on the unique database confirmed a
terminated employee's approved request appeared in calendar counts:

    {"headcount":3,"approved_count":1,"present_count":2,"employees_on_leave":1}

The module needs a stated calendar semantic (active population, unique
employee/day, fractional AM/PM weighting, and Sunday/holiday treatment) before
the implementation can be corrected safely.

### M019-F17 — Incomplete / P1 — year-end input validation and UI copy drift

The API request validates years from 2020 through 2099 at
api/app/Modules/Leave/Requests/ProcessYearEndLeaveRequest.php:16-21, while
the UI input permits 2100 at spa/src/pages/leaves/year-end.tsx:48-55.
The CLI casts arbitrary input to an integer without equivalent validation at
api/app/Console/Commands/ProcessYearEndLeaveCommand.php:40-42; values such as
abc can become year zero and 2100 can bypass the API contract. The UI says it
processes only leave types marked year-end convertible at
year-end.tsx:44-46, but the job selects all active types at
ProcessYearEndLeave.php:75-107. Validation and copy should be centralized.

### M019-F18 — Incomplete / P1 — archived leave types break historical reads

api/app/Modules/Leave/Models/LeaveType.php:14-16 soft-deletes types.
LeaveBalanceController.php:45-49 and SelfServiceController.php:157-163 eager-load
the normal relation, so an archived type disappears.
EmployeeLeaveBalanceResource.php:19-23 then emits leave_type: null, although
the SPA type declares it non-null at spa/src/types/leave.ts:34-42 and pages
dereference it at spa/src/pages/leaves/create.tsx:88,
spa/src/pages/self-service/leave.tsx:147, and
spa/src/pages/leaves/detail.tsx:162-172. Historical requests similarly lose
their type identity through the normal relation in
LeaveRequestService.php:104-112 and the resource's null output.

Runtime evidence after archiving a type on the unique database:

    {"id":"GqkbAVwxd1","leave_type":null,"year":2026,"total_credits":"5.0","used":"0.0","remaining":"5.0"}

The API should retain an immutable historical label or load soft-deleted
relations, and the SPA should be null-safe while that contract is implemented.

### M019-F19 — Broken / P1 — approved/rejected notification links target an unregistered route

api/app/Modules/Leave/Listeners/NotifyOnLeaveApproved.php:26-31 and
NotifyOnLeaveRejected.php:26-31 generate /self-service/leaves/{hash_id}.
The SPA registers only /self-service/leave and /self-service/leaves at
spa/src/routes/selfServiceRoutes.tsx:35-39; there is no :id route, and
spa/src/pages/self-service/leaves.tsx:1-4 is only a list-page alias. An
employee following an approved or rejected outcome notification reaches a
404, while HR links use a registered detail route. The link and an
owner-scoped detail/read action need one consistent contract.

### M019-F20 — Polish / P2 — archived leave types still present an unusable Edit action

spa/src/pages/leaves/types.tsx:120-136 renders Edit for archived rows. The
update route at api/app/Modules/Leave/routes.php:15-17 does not include
soft-deleted models, so the action cannot succeed. Hide or disable Edit for
archived scope, or explicitly provide a restore-then-edit flow.

### M019-F21 — Broken / P2 — a full-day Sunday request can be created with zero days

LeaveRequestService.php:469-477 computes days using a business-day helper that
excludes Sundays, but the submission request does not reject a date range whose
computed days are zero and the create path proceeds at
LeaveRequestService.php:201-203,273-285. The SPA's estimate also permits the
zero result at spa/src/pages/leaves/create.tsx:92-103. A direct Sunday
submission can therefore create a pending/approved zero-day leave record,
depending on workflow state, instead of being rejected as a non-working date.
This should be covered alongside the calendar's non-working-day contract.

### M019-F29 — Incomplete / P2 — the zero-business-day guard exempts half-days, so a Sunday half-day is still accepted

`LeaveRequestService.php:201-208` computes `$days = $halfDayPeriod !== null ? 0.5 :
businessDaysInclusive(...)`, then refuses only `$days <= 0.0`. A half-day request
is therefore never measured against the business-day calendar at all: an `am` or
`pm` request dated on a Sunday yields `0.5`, passes the guard, and is created.

It then debits 0.5 credits on approval (`LeaveRequestService.php:349`) while
`markAttendance()` skips the date entirely (`LeaveRequestService.php:504-506`), so
the balance moves and no attendance record acknowledges it.

This is visible in the suite rather than hidden: `HalfDayLeaveOverlapTest::test_am_then_pm_on_same_day_do_not_collide`
passed on the Sunday run precisely because half-days bypass the guard, while its
full-day sibling in the same file failed. Not fixed here — it changes a validation
rule that governs a balance debit, which the gate puts in the
`separate-recommended` bucket.

## Polish pass

The scoped ESLint and typecheck are clean, and current detail/list screens
follow the surrounding component conventions. The actionable polish item is
M019-F20. The year-end copy mismatch is included in M019-F17 because it also
misstates executable business scope. No unrelated UI or shared design-system
files were changed.

## Disposition

Status: 📋 Plan Ready.

No same-session fixes were applied. M019-F20 is the only small
same-session-ok candidate, while the financial, calendar, historical-data,
notification, and dependency work is separate-recommended; the total plan is
not small and does not have a same-session majority. The exact browser binary
absence remains a verification blocker for the selected Playwright tests.

The next session should first agree the employee-master proration ownership and
the year-end/calendar money contracts, then implement the ordered actions in
action-plan.md, add focused regression tests, install/provision the required
Playwright browsers in the test environment, and rerun the blocked browser
checks.

---

# Re-audit — 2026-08-30

Agent: batch5 (parallel session, 3 of 3)
Claimed module: M019 (people/leave-management) — `claim-module.sh people leave-management` → **CLAIMED**
Database used for verification: `ogami_test_leave` (created for this session, dropped at the end)
Prior status on entry: 🔁 Needs Re-audit
Final status released: 🔁 Needs Re-audit

## What changed since the 2026-08-27 report

Three commits landed on this module after that report was written, and the report
was never updated to reflect them:

| commit | closes |
|---|---|
| `a2deee8c fix(leave): reject zero-business-day ranges` | M019-F21 |
| `9e7a1b12 fix(leave): align year-end year validation` | M019-F17 |
| `4b22e26e fix(leave): enforce year-end validation contract` | M019-F17 (contract follow-up) |

`f0c69a23 Fix archived leave type management actions` closes M019-F20.

So **F17, F20 and F21 are implemented**, and the fix-log's sessions 3–6 describe
them accurately. The working tree was clean on entry (`git status --short` empty).
Everything below was re-measured against that state, not inferred from the log.

## Re-verification of the previously open findings

Each was re-run, not re-read. Probe output is quoted verbatim from
`tests/Feature/Leave/M019ReauditProbeTest.php` (a temporary probe, removed before
release; the two findings it justified fixing now carry permanent coverage).

| finding | still reproduces? | evidence |
|---|---|---|
| F10 hire-date proration bypassed | **YES**, and worse than reported | see M019-F28 below |
| F11 coverage gaps | **YES** | no test exists for `show` row-scope, document read, carryover round-trip, calendar semantics; Playwright browsers still absent |
| F12 documents are write-only | **YES** | `grep -rn 'has_document' spa/src` → only `spa/src/types/leave.ts:58`, a type declaration. No page renders it, no route serves the file. |
| F13 no-salary encashment destroys days | **YES** | `[PROBE H] {"days_converted":"5.0","cash_value":"0.00","adjustment_id":null,"remaining":"0.0"}` |
| F14 carryover/conversion not a round-trip | **YES** | `[PROBE E] response has max_carryover_days key: false \| stored db value: NULL` |
| F16 calendar counts rows, not coverage | **YES**, with sharper numbers | `[PROBE G] {"headcount":2,"approved_count":2,"present_count":0,"on_leave_rows":2}` |
| F17 year-end validation drift | **CLOSED** | `9e7a1b12`, `4b22e26e`; `api/resources/contracts/leave-year-end-validation.json` is the shared source |
| F18 archived types break historical reads | **YES**, and it is a crash, not a null | see M019-F27 below |
| F19 notification link is unrouted | **YES** | `api/app/Modules/Leave/Listeners/NotifyOnLeaveApproved.php:29` and `NotifyOnLeaveRejected.php:67` emit `/self-service/leaves/{hash_id}`; `spa/src/routes/selfServiceRoutes.tsx:37-38` registers only `/self-service/leave` and `/self-service/leaves`, so `spa/src/App.tsx:97` `path="*"` serves NotFound |
| F20 archived Edit action | **CLOSED** | `f0c69a23` |
| F21 zero-business-day range | **CLOSED** in production code, but it introduced M019-F24 |

## What is sound — measured, so it is not re-litigated

These are negative results. They were probed by execution because the assignment
named them as the module's highest-risk surfaces.

**Balance arithmetic cannot be driven negative by concurrent approval.** Two
independent PostgreSQL backends (pcntl fork, separate connection), two distinct
non-overlapping requests of 2.0 days each against a 2.0-day balance. The parent
held the balance row with `lockForUpdate`, the child queued behind it:

    [PROBE B] child=child:App\Modules\Leave\Exceptions\InsufficientLeaveBalanceException|Insufficient leave balance (0.0 remaining; 2 requested).
    [PROBE B] final used=2.0 remaining=0.0 total=2.0
    [PROBE B] statuses r1=approved r2=pending_hr

`LeaveBalanceService::consume()` (`api/app/Modules/Leave/Services/LeaveBalanceService.php:28-53`)
locks the row inside the transaction and recomputes `remaining` from
`total_credits - used` rather than decrementing, so the value is derived and
self-healing. `InsufficientLeaveBalanceException extends BusinessRuleException`
(`api/app/Modules/Leave/Exceptions/InsufficientLeaveBalanceException.php:9`), so
the loser gets a 422 with authored copy, not a 500.

**The approval state machine refuses every illegal transition.** All eight probes
were refused with the correct typed exception:

    [PROBE J4] cross-department approveDept: ForbiddenActionException — Department heads may only decide leave requests for their own department.
    [PROBE J5] self-approval: ForbiddenActionException — You cannot act on a record you submitted.
    [PROBE J6] out-of-order approveHR: BusinessRuleException — Only requests pending HR approval can be approved here.
    [PROBE J7] approveDept again: BusinessRuleException — Only requests pending department head approval can be approved here.
    [PROBE J7] approveHR again: BusinessRuleException — Only requests pending HR approval can be approved here.
    [PROBE J7] reject an approved row: BusinessRuleException — Only pending requests can be rejected.
    [PROBE J8] head moved to another department: ForbiddenActionException — Department heads may only decide leave requests for their own department.

**Row-level filtering is server-side and holds for `employee` and `driver`.**

    [PROBE J1] employee → other's request detail: HTTP 403
    [PROBE J2] driver → other's request detail: HTTP 403
    [PROBE J3] employee → other's balances: HTTP 403

`LeaveRequestController::show()` (`api/app/Modules/Leave/Controllers/LeaveRequestController.php:65-91`)
carries its own scope ladder rather than relying on the `permission:leave.view`
middleware, which every one of the 13 roles holds via
`RolePermissionSeeder::selfService()` (`api/database/seeders/RolePermissionSeeder.php:786-787`).
`LeaveRequestService::list()` delegates to the shared `DepartmentScope`
(`api/app/Modules/Leave/Services/LeaveRequestService.php:153-162`).

**A cancelled request releases exactly what it reserved; a rejected one reserves
nothing to release.** The balance is consumed only in `approveHR()`
(`LeaveRequestService.php:349`) and restored only when the prior status was
`Approved` (`LeaveRequestService.php:457,464-468`). `reject()` never touches the
balance, correctly, because nothing is held at submission — submission only
*checks* (`LeaveRequestService.php:231-244`). Double-cancel is refused
(`LeaveRequestService.php:454-456`). The one case where release is wrong is
M019-F22 below, and it is not a double-release: it is a release into a year whose
balance was already settled.

**`LR-YYYYMM-NNNN` is correct and monthly-reset.**

    [PROBE I] LR-202608-0001, LR-202608-0002, LR-202608-0003
    [PROBE I] sequence row: {"id":5,"document_type":"leave_request","prefix":"LR","year":2026,"month":8,"last_number":3}

## Hardening pass — new findings

### M019-F22 — Broken / P0 — cancelling an approved request after year-end resurrects credits that were already encashed and paid

`api/app/Modules/Leave/Services/LeaveBalanceService.php:69-70` restores by
recomputing `remaining = total_credits - max(0, used - days)`. That is correct
while `used` reflects only consumption. `ProcessYearEndLeave` breaks the premise:
at `api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:140-141` it sets
`used = total_credits` and `remaining = 0` to mark the year settled. A later
cancellation of an approved request from that year therefore *reduces* `used` and
hands the days back.

Measured on `ogami_test_leave`. 10 credits, one 1-day request approved
(used 1.0, remaining 9.0), year-end run for the same year with
`is_convertible_year_end = true, conversion_rate = 1.00`, which encashed the
9.0 remaining days into an **approved** `PayrollAdjustment` the next payroll run
pays. HR then cancelled the approved request:

    [PROBE A] encashed_days=9.0 post_cancel used=9.0 remaining=1.0 total=10.0

The employee has been paid cash for the whole 9.0-day remainder **and** now holds
1.0 leave day in that same year. `EmployeeLeaveBalance` has no "closed" flag and
`restore()` has no year-end awareness, so nothing refuses this. The resurrected
credit is spendable: `submit()` only requires a balance row for the request's year
(`LeaveRequestService.php:231-241`) and the request window allows backdating by
`leave.request.past_window_days`.

Cross-module: the cash already left through `PayrollAdjustment`
(`ProcessYearEndLeave.php:224-246`), so correcting this is a payroll-visible
decision, not a local one.

### M019-F23 — Broken / P1 — a declared holiday inside a leave range is debited as a leave day, and its attendance premium is overwritten

`LeaveRequestService::businessDaysInclusive()`
(`api/app/Modules/Leave/Services/LeaveRequestService.php:474-483`) excludes only
Sunday. It never reads `holidays`. `markAttendance()`
(`LeaveRequestService.php:496-563`) loops the same Sunday-only rule and, at
`LeaveRequestService.php:538-550`, force-writes `day_type_rate => 1.00` over
whatever the row held — while leaving `holiday_type` untouched.

Measured: a Mon–Wed range with a **regular holiday** on the Tuesday, and a
pre-existing attendance row for that Tuesday carrying
`status=holiday, holiday_type=regular, day_type_rate=2.00`:

    [PROBE C] range=2026-09-28..2026-09-30 holiday=2026-09-29 days_debited=3.0
    [PROBE C] holiday attendance after approval: status=on_leave holiday_type='regular' day_type_rate=1.00

Two distinct consequences:

1. The employee is debited 3.0 leave credits for a range containing a day nobody
   was scheduled to work.
2. The attendance row now carries the contradictory pair
   `holiday_type='regular'` with `day_type_rate=1.00`. That row is payroll input.
   The snapshot at `LeaveRequestService.php:521-527` does restore `2.00` on
   cancellation, so the premium is recoverable — but `approved` is the state
   payroll computes from, so an uncancelled leave over a holiday is computed at
   the wrong rate.

Attribution: the holiday table is Attendance's (`api/app/Modules/Attendance/Models/Holiday.php`)
and the rate consumer is Payroll. The **defect** is Leave's: Leave is the writer
that discards the premium. Not fixed here — it changes a computed pay figure.

Related, and attributed to Attendance rather than fixed here: `holidays.is_recurring`
exists (`api/app/Modules/Attendance/Models/Holiday.php:18`) but is only meaningful
within the stored year, so once leave's business-day math *does* consume holidays
it inherits that limitation.

### M019-F24 — Broken / P1 — the Leave regression suite is red one day in seven

`a2deee8c` added the zero-business-day guard at
`api/app/Modules/Leave/Services/LeaveRequestService.php:204-208`. Six fixtures
across three test files build their dates as `now()->addWeek()`, which preserves
the weekday, so every one of them lands on a Sunday when the suite runs on a
Sunday and is refused by the new guard.

Measured on the same database, same commit, differing only in clock:

| `APP_TIMEZONE` | local date | result |
|---|---|---|
| `Asia/Manila` (project default, `api/config/app.php:15`) | 2026-08-30 **Sunday** | **7 failed / 68 passed**, 500 assertions |
| `UTC` | 2026-08-29 Saturday | **75 passed / 0 failed**, 523 assertions |

The seven: `HalfDayLeaveOverlapTest` ×2, `LeaveRequestBulkApproveTest` ×4,
`LeaveRequestVisibilityTest::test_only_hr_or_admin_may_file_for_another_employee`.
All seven fail with the same `BusinessRuleException` from
`LeaveRequestService.php:205`; none is an assertion failure about behaviour.

This is a fixture defect, not a production one, and the correct pattern already
exists in this module: `LeaveRequestHardeningTest::workDate()`
(`api/tests/Feature/Leave/LeaveRequestHardeningTest.php:66-73`) skips Sundays,
which is why that file's 10 tests are unaffected. The affected files already guard
the *year* boundary (`LeaveRequestBulkApproveTest.php:37-41`) but not the weekday.

**Fixed in this session** — see fix-log.

### M019-F25 — Incomplete / P1 — a vacant department-head seat wedges every leave request in that department

`ApprovalService::approve()`/`reject()` require the actor's role slug to equal the
step's `role_slug` (`api/app/Common/Services/ApprovalService.php:133-135,160-162`),
and step 1 of `leave_request` is `department_head`
(`api/database/seeders/WorkflowSeeder.php:26-29`). `hasPermission` short-circuits
for `system_admin`, so `assertDepartmentDecisionScope`
(`LeaveRequestService.php:644-663`) lets HR and admin through — but ApprovalService
then refuses them, because neither holds the *role*:

    [PROBE D] hr_officer  approveDept: ForbiddenActionException — Only users with role 'department_head' can approve this step.
    [PROBE D] hr_officer  reject:      ForbiddenActionException — Only users with role 'department_head' can reject this step.
    [PROBE D] system_admin approveDept: ForbiddenActionException — Only users with role 'department_head' can approve this step.
    [PROBE D] system_admin reject:      ForbiddenActionException — Only users with role 'department_head' can reject this step.

The request stays `pending_dept` with no one able to decide it. This is not
hypothetical: `[PROBE J8]` shows a department head who *moves department* also
loses the ability to decide requests they were mid-chain on, correctly and
fail-closed — which is exactly how the seat becomes vacant.

Two escapes exist and neither is reachable by an operator:

- `ApprovalDelegation` — `admin.delegations.manage_any` lets an admin delegate a
  separated head's authority (`api/app/Modules/Admin/Services/ApprovalDelegationService.php:51-60`,
  routes at `api/app/Modules/Admin/routes.php:238-242`). **There is no SPA surface
  at all**: `grep -rln delegation spa/src` returns nothing. API-only.
- HR override-`cancel` (`LeaveRequestService.php:450-452`). This works, but it is
  the wrong semantic — it records no `rejection_reason` and attributes the action
  to a cancellation rather than a decision.

Attribution: the wedge is produced by Leave's workflow definition plus the shared
`ApprovalService`; the missing UI is Admin's. Reported, not fixed — it is an RBAC
and state-machine change.

### M019-F26 — Broken / P1 — a `conversion_rate` above 1.0 is accepted, stored, and then silently overridden at payout

`StoreLeaveTypeRequest.php:36` and `UpdateLeaveTypeRequest.php:38` allow
`conversion_rate` up to `9.99`. `ProcessYearEndLeave.php:105` clamps it with
`max(0.0, min(1.0, …))`. The operator's configured policy is therefore discarded
on the path that produces cash.

Measured: created a type through the management API with `conversion_rate: 2.50`,
then ran year-end against a 10.0-day balance and a ₱22,000 monthly salary:

    [PROBE E2] stored conversion_rate=2.50 days_converted=10.0 cash=10000.00

The stored value is `2.50`; the payout used `1.0`. Nothing warns, and nothing in
the response or the SPA reveals that the configured rate is unreachable. This is
the same defect family as F14 (`max_carryover_days` silently discarded) but on a
money-producing path, which is why it is recorded separately. Not fixed — it
changes a computed pay figure and needs a stated invariant, not a guess about
whether >1.0 means "unreachable" or "a premium".

### M019-F27 — Broken / P1 — F18's consumer side is a hard crash, not a null

The 2026-08-27 report described the archived-type case as
`leave_type: null`. Measured, it is worse: `EmployeeLeaveBalanceResource:19-23`
uses `whenLoaded('leaveType', …)`, which returns `MissingValue` when the relation
is loaded-but-null, so the key is **omitted from the payload entirely**:

    [PROBE F] before={"id":"G6ONoVpjvd","code":"VL","name":"Vacation Leave"} after="ABSENT"

`spa/src/types/leave.ts:38` declares `EmployeeLeaveBalance.leave_type` as
non-nullable, so no consumer guards it. Six sites dereference it directly:

- `spa/src/pages/leaves/detail.tsx:162` — `b.leave_type.id`
- `spa/src/pages/leaves/detail.tsx:169` — `b.leave_type.code`
- `spa/src/pages/leaves/create.tsx:111` — `b.leave_type.id`
- `spa/src/pages/leaves/create.tsx:184` — `selectedBalance.leave_type.code`
- `spa/src/pages/self-service/leave.tsx:159` — `b.leave_type.id`
- `spa/src/pages/self-service/leave.tsx:328` — `selectedBalance.leave_type.name`

Archiving a single leave type therefore throws in the leave filing form and the
HR detail page for every employee holding a balance in that type. The payload
shape was proved by execution, and the crash was then **observed** in a DOM
render: `spa/src/pages/leaves/detail.test.tsx` against the pre-fix
`detail.tsx` produces

    TypeError: Cannot read properties of undefined (reading 'id')

`spa/src/pages/leaves/index.tsx:217,413` and
`spa/src/pages/self-service/leave.tsx:98` already use `?.` with an em-dash
fallback, which is the pattern the six sites are missing.

**The SPA null-safety half is fixed in this session** — see fix-log. The API
contract half (retain an immutable historical label, or load soft-deleted
relations) is F18 and remains deferred: it is a response-contract decision.

### M019-F28 — Incomplete / P1 — F10 is two defects, not one

Re-reading `api/app/Modules/HR/Services/EmployeeService.php:224-240` against
`api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:37-68` confirms the
reported race and adds a second, distinct fault:

1. As reported: the synchronous path writes `total_credits = default_balance` with
   `updateOrInsert`; the queued listener writes the prorated figure with
   `insertOrIgnore`, which cannot replace the existing row. A mid-year hire keeps
   full-year credits.
2. **Not previously reported:** the two paths key different years. The
   synchronous path uses `now()->year` (`EmployeeService.php:225`); the listener
   uses `date_hired->year` (`InitializeLeaveBalances.php:38-39`). An employee
   recorded with a `date_hired` in a prior calendar year therefore ends up with
   **two** balance rows — full credits for the current year and prorated credits
   for the hire year — rather than one wrong row.

Both are employee-master behaviour. Not touched, per scope.

## Polish pass

- No hardcoded colours in the module:
  `grep -rnE '#[0-9a-fA-F]{3,8}\b|rgb\(|bg-(red|green|blue|…)-[0-9]'` over
  `spa/src/pages/leaves`, `spa/src/api/leave`, `spa/src/types/leave.ts` → no hits.
- The actionable polish items are the six unguarded `leave_type` dereferences in
  M019-F27 (fixed) and the missing document-review control in F12 (deferred —
  it needs a route that does not exist).
- F20's archived-Edit fix is present and covered by
  `spa/src/pages/leaves/types.test.tsx`.

## Questions needing a human decision

1. **Is a holiday inside a leave range a leave day?** (M019-F23.) Current code
   debits it. Philippine practice generally pays the holiday as a holiday and
   does not consume a leave credit. Whichever way this is answered changes a
   balance figure, so it is not mine to decide.
2. **What does `conversion_rate > 1.0` mean?** (M019-F26.) Either the validation
   should refuse it, or the year-end clamp should stop overriding it. The two
   readings pay different amounts.
3. **What should cancelling an approved request in a dispositioned year do?**
   (M019-F22.) Refuse the cancellation, cancel without restoring, or restore and
   claw back the encashment. All three are defensible; all three move money.
4. **Should the leave workflow be 2 steps or 4?** `CLAUDE.md` states
   "Approval chain: Staff → Dept Head → Manager → Officer → VP (4 levels)".
   Leave implements exactly 2 (`WorkflowSeeder.php:26-29`, and
   `LeaveRequestStatus` has exactly two pending states). Code, seeder and enum
   agree with each other, so this reads as a deliberate exception to the general
   rule rather than a defect — but the doc and the code disagree.
5. **Who may download a required supporting document, and for how long is it
   kept?** (F12, carried forward unanswered from session 2.)
6. **Should advance cross-year filing be possible?** (Carried forward from
   session 2's open questions; unchanged.)

## Verification evidence

| check | result | exact command |
|---|---|---|
| Leave suite BEFORE, project default clock | **7 failed / 68 passed**, 500 assertions | `docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_leave api php artisan test tests/Feature/Leave --no-coverage` |
| Leave suite, clock shifted off Sunday | **75 passed / 0 failed**, 523 assertions | same, plus `-e APP_TIMEZONE=UTC` |
| Re-audit probes | 10 probes, all reported above | `… api php artisan test tests/Feature/Leave/M019ReauditProbeTest.php --no-coverage` |
| Two-connection balance race | PASS, no negative balance | PROBE B, pcntl fork + separate PostgreSQL connection |

Not verified, stated plainly:

- **No browser was run.** Playwright's Chromium/Firefox binaries are still absent
  from the container (F11), so `e2e/chain-leave.spec.ts` was not executed. The
  M019-F27 crash is asserted from the measured payload shape, not observed in a
  browser.
- **No production-like payroll dataset.** M019-F22's downstream effect
  (an encashment adjustment applied by a real payroll run) was not rehearsed
  end-to-end; the adjustment row's existence and approval state were verified,
  its application was not.
- Vitest was run only for the file changed in this session, not the SPA suite.

## Fixes applied in this session

Two contained items only; see `fix-log.md` for the file:line before/after and
`action-plan.md` for why nothing else was eligible.

- **M019-F24** — fixed. `tests/Feature/Leave` now passes on the project default
  clock on a Sunday: **75 passed / 0 failed, 523 assertions**, the same count the
  UTC baseline produced. New shared trait
  `api/tests/Feature/Leave/BusinessDayFixtures.php`; four test files moved onto it.
- **M019-F27 (SPA half)** — fixed. Six unguarded `leave_type` dereferences guarded,
  `spa/src/types/leave.ts` corrected to `leave_type?: … | null`, and
  `spa/src/pages/leaves/detail.test.tsx` added. The test was confirmed to **fail**
  against unmodified `detail.tsx` with the predicted `TypeError`, and the temporary
  revert used to prove that was verified restored with `sha256sum -c` → `OK`.

### Final verification

| check | result | command |
|---|---|---|
| `tests/Feature/Leave` AFTER, project default clock (Asia/Manila, Sunday) | **75 passed / 0 failed**, 523 assertions | `docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_leave api php artisan test tests/Feature/Leave --no-coverage` |
| `php -l` on the 5 changed/added PHP files | **PASS** | in-container loop |
| PHPStan on the same 5 files | **PASS — no errors** | `./vendor/bin/phpstan analyse … --memory-limit=1G` |
| Laravel Pint on the same 5 files | 2 issues, **both proven inherited** | see below |
| SPA ESLint on the 5 changed/added SPA files | **PASS**, `--max-warnings 0` | `npx eslint … --max-warnings 0` |
| SPA `npm run typecheck` | **PASS** — the previously reported `qrcode` blocker is gone | `tsc --noEmit` |
| SPA Vitest, `src/pages/leaves` | **3 files / 4 tests passed** | `npm run test:run -- src/pages/leaves` |

Pint inheritance proof — the same two files fail with the same rule sets before
and after my changes, so no pre-existing style was pulled into the diff:

    # HEAD extracts (git show HEAD:api/tests/Feature/Leave/<f>.php)
    FAIL  4 files, 2 style issues
    ⨯ HalfDayLeaveOverlapTest.php      binary_operator_spa…
    ⨯ LeaveRequestBulkApproveTest.php  class_definitio…

    # working tree, after this session
    FAIL  5 files, 2 style issues
    ⨯ tests/Feature/Leave/HalfDayLeaveOverlapTest.php     binary_operator_spaces
    ⨯ tests/Feature/Leave/LeaveRequestBulkApproveTest.php class_definition, fun…

`LeaveRequestVisibilityTest.php` and `LeaveRequestHardeningTest.php` were Pint-clean
at HEAD and remain clean; the new `BusinessDayFixtures.php` is clean.

The scratch database `ogami_test_leave` was dropped after these runs.
