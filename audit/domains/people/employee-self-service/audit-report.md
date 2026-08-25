# M024 — People / Employee Self-Service audit report

Audit date: 2026-08-24  
Claim: people / employee-self-service  
Registry tier: 4  
Status: 📋 Plan Ready  
Session recommendation: separate-recommended

## Verdict

Production-readiness score: **38/100 — blocked for an unqualified release**.

The module has a good owner-scoped HR API surface for its dedicated home, loan, overtime, profile, document, and training endpoints. Profile changes are whitelisted, transactional, and split into HR/Finance approval where bank fields are involved. The release is blocked by shared DTR/leave/payslip list calls that broaden a department head's self-service view to department data, and by statutory certificate endpoints that bypass the catalogue/year-end contract and payroll-period closure. Financial totals also use floating-point arithmetic, while the current regression suite does not exercise the document, profile-update, or cross-role self-service contracts. No production-code fix was applied during this audit.

## Evidence checked

- Refreshed the repository-local registry with `./audit/scripts/regenerate-registry.sh`, preserved the pre-existing dirty worktree, and atomically claimed M024 with `./audit/scripts/claim-module.sh people employee-self-service`.
- Reviewed the M024 API routes/controller/services, the shared Attendance/Leave/Payroll read paths used by self-service, role grants, SPA routes/API/pages/layout, mobile E2E coverage, and the relevant design/user-manual/QA contracts. Dependency modules were read for scope evidence only and were not modified.
- `/audit/scripts/...` from the supplied procedure was not an absolute path in this checkout; the equivalent repository-local `./audit/scripts/...` scripts were used.
- `php artisan route:list --path=hr/self-service --json` — passed; 15 self-service routes are registered under `auth:sanctum` and the HR feature gate (`api/app/Modules/HR/routes.php:25,240-264`).
- PHP lint over the M024 controller and services — passed.
- `php artisan test tests/Feature/HR/SelfServiceHomeTest.php tests/Feature/HR/SelfServiceLoanLifecycleTest.php tests/Feature/HR/SelfServiceOvertimeLifecycleTest.php tests/Feature/HR/SelfServiceTrainingsTest.php --no-coverage` — blocked before assertions: **13 failed / 0 assertions** because PostgreSQL host `db` could not be resolved (`SQLSTATE[08006]`). This is an environment blocker, not a passing functional result.
- `npm run typecheck` in `spa` — passed.
- `npm run lint` in `spa` — passed.
- No live authenticated API/SPA environment, writable browser/Vite cache, production-like payroll states, or finalized-payroll certificate rehearsal was available.

## Strengths

- Dedicated M024 routes are behind Sanctum and the HR feature gate, and `SelfServiceController::currentEmployee()` rejects unlinked users and resolves only `auth.user.employee_id` (`api/app/Modules/HR/routes.php:25,240-264`; `api/app/Modules/HR/Controllers/SelfServiceController.php:31-58`).
- The dedicated loan, overtime, profile, document, and training paths use the session employee rather than accepting an arbitrary employee identifier. Overtime cancellation/restore also has owner checks and an existing cross-employee test (`api/app/Modules/HR/Controllers/SelfServiceController.php:176-330`; `api/tests/Feature/HR/SelfServiceOvertimeLifecycleTest.php:103-120`).
- Profile updates use an allow-list, record the requester, wrap creation in `DB::transaction()`, and require the Finance leg for bank-account changes (`api/app/Modules/HR/Services/ProfileUpdateRequestService.php:31-69,100-160`).
- Profile responses mask bank and government identifiers, and certificate streams set private/no-store cache headers (`api/app/Modules/HR/Controllers/SelfServiceController.php:332-402`; `api/app/Modules/HR/Services/SelfServiceDocumentService.php:218-226`).
- Existing feature tests cover the home shape/no-linked-employee behavior, loan submission/list/duplicate rejection, overtime lifecycle, training ownership, and profile masking (`api/tests/Feature/HR/SelfServiceHomeTest.php:21-130`; `api/tests/Feature/HR/SelfServiceLoanLifecycleTest.php:26-98`; `api/tests/Feature/HR/SelfServiceTrainingsTest.php:31-77`).

## Findings

### M024-F01 — Broken/critical: self-service list pages inherit department-head scopes

Priority: **P0**  
Scope: **medium**  
Recommendation: **separate-recommended**

The self-service contract says that employees only see their own data, and the role seeder claims the DTR, Leave, and payslip controllers scope those calls to the authenticated employee (`docs/USER-MANUAL.md:271-274`; `api/database/seeders/RolePermissionSeeder.php:724-738`). That is true for the dedicated M024 endpoints, but not for three shared list calls used by the SPA:

- The DTR page sends `scope=self`, but `AttendanceController` forwards raw query parameters and `AttendanceService::list()` has no `scope` handling. A user with `attendance.ot.approve` receives their own rows plus the department rows (`spa/src/pages/self-service/dtr.tsx:128-137`; `api/app/Modules/Attendance/Controllers/AttendanceController.php:27-30`; `api/app/Modules/Attendance/Services/AttendanceService.php:28-79`).
- The Leave page calls `/leaves/requests` without an employee filter. `DepartmentScope` grants department-plus-own visibility to users with `leave.approve_dept` (`spa/src/pages/self-service/leave.tsx:106-116`; `api/app/Modules/Leave/Services/LeaveRequestService.php:130-153`).
- The payslip page calls `/payrolls` without an employee filter. `PayrollController` explicitly gives a `department_head` the department's payroll rows (`spa/src/pages/self-service/payslips.tsx:75-83`; `api/app/Modules/Payroll/Controllers/PayrollController.php:18-47`).

`department_head` receives `selfService()` plus `attendance.ot.approve` and `leave.approve_dept` (`api/database/seeders/RolePermissionSeeder.php:670-688`). As a result, a department head opening the self-service portal can see department DTR, leave, and salary data that the documented employee contract forbids. The existing SPA test only mocks a single employee response and does not assert the request scope or reject a department-head dataset (`spa/e2e/mobile/self-service-mobile.spec.ts:83-103`). A UI query parameter is not an authorization boundary.

Action: define a server-enforced self-service read context. Prefer dedicated self-service read endpoints/services that force the current employee; alternatively, make the shared services accept an authoritative owner-only mode that cannot be omitted by the client. Preserve department scope for back-office screens, and add API/E2E tests proving a department head receives only their own rows through every self-service route while retaining department scope in the HR screens.

### M024-F02 — Broken/high: statutory certificate routes bypass catalogue and payroll-period closure

Priority: **P0**  
Scope: **medium**  
Recommendation: **separate-recommended**

The document catalogue describes BIR 2316 as a prior-year certificate available after year-end closing, but its availability check is only whether any payroll row exists for the prior year (`api/app/Modules/HR/Controllers/SelfServiceController.php:451-485`). The PDF routes then accept a caller-supplied year without checking the catalogue, the prior-year rule, or a closed payroll state (`api/app/Modules/HR/Controllers/SelfServiceController.php:496-510`; `spa/src/api/self-service.ts:84-89`).

The aggregation queries filter by employee, year, and `whereNull(error_message)`, but do not require the related payroll period to be finalized or disbursed (`api/app/Modules/HR/Services/SelfServiceDocumentService.php:150-161,185-203`). Payroll defines `finalized`, `disbursed`, and `voided` as distinct locked states (`api/app/Modules/Payroll/Enums/PayrollPeriodStatus.php:7-15,30-40`). A direct URL can therefore request a current, future, or otherwise non-catalogued year, and an in-progress/non-closed payroll row can be included in a statutory certificate. The endpoint may produce a blank PDF for some years, but it does not fail closed or keep the catalogue and direct-download policies consistent.

Action: make certificate availability and generation share one server-side policy. Decide explicitly whether the authoritative source state is finalized, disbursed, or both; require that state, exclude voided/error rows, enforce the BIR prior-year/year-end rule, and return a controlled 4xx when the year is unavailable. Add direct-URL tests for current/future/old years and payroll states, not only catalogue rendering.

### M024-F03 — Incomplete/high: statutory totals use floating-point money arithmetic

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

Contribution aggregation documents its return values as floats, casts payroll decimal strings to `(float)`, sums them in a `float`, and emits the float total (`api/app/Modules/HR/Services/SelfServiceDocumentService.php:145-177`). BIR totals use the same pattern for gross pay, employee contributions, withholding tax, mandatory deductions, and taxable pay (`api/app/Modules/HR/Services/SelfServiceDocumentService.php:180-215`).

These are statutory/financial documents. Binary floating-point accumulation can introduce centavo-level rounding drift, and the current code does not state a per-field rounding policy before rendering. The certificate can therefore disagree with the authoritative payroll decimal values or with a separately rounded sum.

Action: use the repository's centavo/decimal-safe money representation for aggregation, define statutory rounding at the boundary, preserve decimal strings in the PDF data, and add fixtures with values that expose float drift plus exact per-period/total assertions. This should be implemented together with F02 because period selection and arithmetic correctness form one certificate contract.

### M024-F04 — Incomplete/medium: the self-service history limit is not applied consistently

Priority: **P2**  
Scope: **small-to-medium**  
Recommendation: **same-session-ok**

The `self_service.history_limit` setting is explicitly described as the maximum historical self-service rows returned per request (`api/database/migrations/0324_seed_self_service_history_limit.php:11-15`). Overtime applies it, but the loan endpoint loads every employee loan before splitting active/history and the training endpoint loads every training row with an unbounded `get()` (`api/app/Modules/HR/Controllers/SelfServiceController.php:97-129,191-196,521-530`).

Long-tenured employees can therefore receive disproportionately large responses, and the setting does not provide the protection its label promises. Active/pending items also need a deliberate rule so a history cap does not hide actionable requests.

Action: apply the configured cap to historical loans/trainings or add pagination, while retaining all active/pending items by contract. Add a large-history response test and verify ordering and active-item visibility.

### M024-F05 — Missing/high: critical self-service document, update, and cross-role contracts lack regression coverage

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The current M024 feature tests cover home, profile read/masking, loans, overtime, and training ownership (`api/tests/Feature/HR/SelfServiceHomeTest.php:21-130`; `api/tests/Feature/HR/SelfServiceLoanLifecycleTest.php:26-98`; `api/tests/Feature/HR/SelfServiceOvertimeLifecycleTest.php:29-120`; `api/tests/Feature/HR/SelfServiceTrainingsTest.php:31-77`). No focused M024 coverage was found for employment/contribution/BIR PDF policy, payroll-period state selection, profile-update submission/list behavior, notification-preference failure/ownership behavior, or department-head isolation across the shared DTR/Leave/Payroll list endpoints.

The mobile E2E file covers employee mocks for home, me, leave, payslips, DTR, loans, and profile, but omits documents, overtime, and notification preferences and does not assert the actual owner-filter contract (`spa/e2e/mobile/self-service-mobile.spec.ts:1-8,83-139`). The focused API suite could not execute in this environment because the test database host was unavailable, so these controls currently have neither passing runtime evidence nor negative cross-role regression tests.

Action: add PostgreSQL API tests for every certificate year/state rule, profile update/dual-approval boundary, unlinked user, department-head self-service isolation, and shared-list owner mode. Add browser tests for the real request parameters, mobile navigation, documents, overtime, notification preferences, and partial-query failures. Keep the test database and browser cache writable in CI.

### M024-F06 — Incomplete/medium: the documented mobile bottom navigation is not implemented

Priority: **P1**  
Scope: **medium**  
Recommendation: **same-session-ok**

The user manual defines self-service as a mobile portal with bottom navigation `Home · DTR · Leave · Payslip · Me`, and the QA matrix has a phone-width acceptance row for that navigation (`docs/USER-MANUAL.md:271-274`; `docs/QA-MATRIX.md:64-83`). The actual routes are mounted inside the generic authenticated `AppLayout`, whose mobile shell contains the topbar/sidebar rather than a self-service bottom navigation (`spa/src/App.tsx:47-71`; `spa/src/layouts/AppLayout.tsx:58-107`; `spa/src/components/layout/Topbar.tsx:60-116`). Self-service links are placed in a profile dropdown (`spa/src/components/layout/ProfileDropdown.tsx:30-39,95-121`).

At 390px, the primary employee destinations are therefore behind the hamburger/profile interactions instead of the documented persistent navigation. The existing mobile E2E suite asserts page rendering but does not assert the bottom-nav contract (`spa/e2e/mobile/self-service-mobile.spec.ts:15-139`).

Action: add a self-service mobile shell/bottom navigation at the documented breakpoint, preserve keyboard/screen-reader labels and feature-gated destinations, and assert visibility and navigation at 390px. Keep the generic shell for back-office routes.

### M024-F07 — Incomplete/medium: secondary query failures silently produce partial or empty states

Priority: **P2**  
Scope: **small**  
Recommendation: **same-session-ok**

The home page includes `homeQuery` data in the employee summary, balances, shift, and latest payslip, but its error state only checks `dashboardQuery`; a failed home request can leave the page rendered with missing summary data and no retry for that request (`spa/src/pages/self-service/index.tsx:63-83,104-123`). Profile update requests are queried without exposing `isError` or a retry state, so a failed request list can look like there are no pending changes (`spa/src/pages/self-service/profile.tsx:88-110,158-166`). Notification preferences handles the preference query error but ignores catalogue errors, which can render an empty matrix while the preference data is present (`spa/src/pages/self-service/notification-preferences.tsx:54-64,134-144`).

Action: model each dependent query's loading/error state, provide a retry path, and disable controls that depend on an unavailable catalogue. Add mocked partial-failure tests so the UI does not present incomplete data as a successful self-service view.

## Polish pass

- The dedicated M024 pages use the shared `PageHeader`, `DataTable`, skeleton, empty-state, and design-token vocabulary; primary list pages generally expose an error/retry state (`spa/src/pages/self-service/payslips.tsx:85-100`; `spa/src/pages/self-service/dtr.tsx:128-150`).
- The PDF response is deliberately private and non-cacheable, and profile masking reduces accidental exposure in the normal UI (`api/app/Modules/HR/Services/SelfServiceDocumentService.php:218-226`; `api/app/Modules/HR/Controllers/SelfServiceController.php:332-402`).
- The route comments and SPA API comments clearly state the intended owner-only contract. That clarity makes F01 actionable, but the comments are currently stronger than the shared endpoint behavior (`api/app/Modules/HR/routes.php:240-242`; `spa/src/api/self-service.ts:16-19`; `spa/src/pages/self-service/payslips.tsx:17-20`).

## Production-audit assessment

### Blockers / high-value risks

1. M024-F01 permits department-scoped DTR, leave, and payroll data to appear in a self-service screen for department heads.
2. M024-F02 allows direct statutory-document requests outside the catalogue and without a closed payroll-period guard.
3. M024-F03 can introduce centavo-level statutory total drift through floating-point arithmetic.
4. M024-F05 leaves the highest-risk owner/state contracts without executable regression evidence.

### Evidence still missing

- A PostgreSQL run of the 13 focused M024 tests against a resolvable `db` service.
- Authenticated department-head API/browser proof that self-service DTR, leave, and payslip responses contain only the actor's employee ID while back-office lists remain department-scoped.
- Certificate fixtures for draft/processing/computed/approved/finalized/disbursed/voided periods, arbitrary years, error rows, and exact decimal totals.
- Profile-update endpoint/dual-approval tests, document-download tests, notification-preference partial-failure tests, and 390px bottom-navigation assertions.

### Next action

Do not promote M024 to Verified. In a separate hardening session, first centralize the self-service owner-only read contract and certificate period/year policy, then repair decimal-safe statutory aggregation and add the negative/integration suite. The history cap, mobile shell, and query-error fixes can follow as contained work once the blocking contracts are covered.
