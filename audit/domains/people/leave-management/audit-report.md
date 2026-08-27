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
