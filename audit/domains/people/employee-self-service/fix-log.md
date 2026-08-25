## Fix log

### F01 — owner isolation for self-service records and payslips

- Before: the DTR, leave, and payslip screens called shared back-office list endpoints and relied on a `scope=self` client parameter; the direct payslip download did not prove ownership.
- After: dedicated self-service routes were added in `api/app/Modules/HR/routes.php:243-247`; `SelfServiceController` now scopes attendance, leave requests, list payslips, and the PDF download to the authenticated employee in `api/app/Modules/HR/Controllers/SelfServiceController.php:106-214`.
- SPA callers now use the owner-only API in `spa/src/api/self-service.ts:36-54` and `spa/src/pages/self-service/{dtr,leave,payslips}.tsx:117-125`.
- Added regression coverage for list isolation and cross-owner download rejection in `api/tests/Feature/HR/SelfServiceOwnerScopeTest.php:25-65`.

### F02 — published certificate catalogue and direct-route policy

- Before: the catalogue and direct document URLs could expose outputs without consistently enforcing year rules and finalized/disbursed, non-voided payroll publication state.
- After: `documents()` derives availability from published payrolls, direct certificate methods enforce the catalogue and year constraints, and the shared publication policy is applied in `api/app/Modules/HR/Controllers/SelfServiceController.php:578-652` and `api/app/Modules/HR/Controllers/SelfServiceController.php:719-742`.
- Document generation also rejects years without published payroll in `api/app/Modules/HR/Services/SelfServiceDocumentService.php:87-123` and `api/app/Modules/HR/Services/SelfServiceDocumentService.php:154-205`.

### F03 — exact statutory aggregation

- Before: contribution and tax totals were accumulated/rendered through floating-point values.
- After: all statutory accumulation uses the centavo-safe `Money` helper and string values in `api/app/Modules/HR/Services/SelfServiceDocumentService.php:154-223`; PDF templates render the already-rounded strings in `api/resources/views/pdf/contribution-certificate.blade.php` and `api/resources/views/pdf/bir-2316.blade.php`.
- Added a decimal-boundary regression test for `0.10 + 0.20` in `api/tests/Feature/HR/SelfServiceDocumentMoneyTest.php:23-46`.

### F04 — bounded history with complete active/scheduled state

- Before: loan and training responses were unbounded and could omit lifecycle records depending on the existing query split.
- After: active/pending loans and scheduled trainings remain complete while historical records are capped by `self_service.history_limit` in `api/app/Modules/HR/Controllers/SelfServiceController.php:216-240` and `api/app/Modules/HR/Controllers/SelfServiceController.php:660-681`.
- Added lifecycle/limit coverage in `api/tests/Feature/HR/SelfServiceLoanLifecycleTest.php` and `api/tests/Feature/HR/SelfServiceTrainingsTest.php`.

### F05 — regression and role-surface coverage

- Before: there was no targeted coverage for owner isolation, certificate policy, exact statutory totals, or failure states on the mobile self-service surface.
- After: backend owner/document/limit tests were added, and mobile tests now cover navigation plus home, profile-request, and notification-catalogue failures in `spa/e2e/mobile/self-service-mobile.spec.ts:17-46` and subsequent failure-case tests.

### F06 — mobile self-service navigation

- Before: self-service used only the generic desktop-oriented application shell.
- After: `SelfServiceLayout` adds a responsive, feature/permission-gated bottom navigation with safe-area padding in `spa/src/layouts/SelfServiceLayout.tsx:13-43`, mounted around the self-service routes in `spa/src/routes/selfServiceRoutes.tsx:22-51`.

### F07 — explicit partial-failure states

- Before: successful sibling queries could leave the page showing an incomplete or empty-looking result when another request failed.
- After: the home page includes the self-service home query in its error state (`spa/src/pages/self-service/index.tsx:84-115`); profile update requests expose retryable errors (`spa/src/pages/self-service/profile.tsx:95-103`, `spa/src/pages/self-service/profile.tsx:354-390`); notification preferences require both preference and catalogue queries before rendering the matrix (`spa/src/pages/self-service/notification-preferences.tsx:57-151`).

## Verification

- Passed: PHP syntax checks for the changed controller/service/tests.
- Passed: `php artisan route:list --path=hr/self-service --json`; all owner-only routes are registered.
- Passed: targeted ESLint for changed self-service files.
- Blocked by unrelated workspace errors: SPA `npm run typecheck` reports errors only in `src/pages/admin/backups.tsx`, `src/pages/assets/detail.tsx`, and `src/pages/return-management/detail.tsx`; no changed self-service file is listed.
- Passed: targeted `git diff --check`.
- Blocked, no assertions executed: focused PHP tests cannot resolve the configured PostgreSQL host `db` (`SQLSTATE[08006]`).
- Blocked before browser tests: Playwright's Vite web server cannot write its existing `spa/node_modules/.vite-temp` cache (`EACCES`).
- Full SPA lint still reports four pre-existing dependency-rule errors outside this module; no changed self-service file was reported.

All seven planned code fixes are applied. Runtime backend and browser verification remain pending because of the two environment blockers above; the module is released as `🔁 Needs Re-audit` so those checks can be rerun without treating this as a fresh audit.
