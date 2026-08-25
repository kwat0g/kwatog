# M015 — People / Onboarding fix log

Audit date: 2026-08-24  
Fix session: 2026-08-25  
Module status: 🔁 Needs Re-audit  
Implementation status: **Plan implemented; live backend/browser verification remains pending**

## Session record

- Refreshed the registry and atomically claimed people / onboarding as the first
  unlocked `📋 Plan Ready` module after higher-priority candidates were skipped
  because they were locked elsewhere.
- Read the existing audit report and action plan in full and continued directly
  with the planned fixes, without repeating discovery or rewriting findings.
- Kept unrelated dirty worktree changes untouched. The temporary Node loader
  used for frontend checks wrote only under `/tmp`.

## Fixes

| Finding | File:line | Before | After | Verification |
|---|---|---|---|---|
| F01 | `api/app/Modules/HR/Services/OnboardingService.php:97-132`; `api/app/Modules/HR/Controllers/EmployeeOnboardingController.php:33-41`; `api/app/Modules/HR/routes.php:101-107` | `dept_team_notified` had only an unrestricted service helper and no product route. | Added an explicit HR-attestation action, active-user/`hr.employees.edit` authorization, row locking, idempotent timestamping, activity audit event with actor/time, and a guarded route. | PHP lint and route-list pass; HTTP success/denial/archived/missing cases are covered in the feature test. |
| F02 | `api/app/Modules/HR/Services/OnboardingService.php:165-267`; `api/database/migrations/2026_08_25_230000_seed_onboarding_notification_roles.php:9-24`; `api/app/Common/Services/NotificationCatalog.php:199-203`; `api/app/Modules/Admin/Requests/UpdateSettingRequest.php:72` | The scheduler called nonexistent `notifyRole()`, used `link`, and marked reminders sent even when nothing was delivered. | Resolves a configured active HR audience, calls `sendInApp()` with catalogued `hr.onboarding.stale` and `link_to`, updates the marker only after the durable call, and leaves empty/failing delivery retryable. | PHP lint, catalog-sync unit test, and focused reminder tests added; live DB execution is blocked by the unavailable PostgreSQL host. |
| F03 | `api/app/Modules/HR/Services/OnboardingService.php:58-163`; `api/app/Modules/HR/Controllers/EmployeeOnboardingController.php:19-30` | GET status reconciled implicitly and POST recompute reconciled twice through `status()`. | Reconciliation is now explicitly documented and transactional; POST uses `recomputeStatus()` to project the tracker it already recomputed, avoiding the duplicate write. | PHP lint and the canonical-data reconciliation test added; live DB execution remains pending. |
| F04 / polish | `api/tests/Feature/HR/OnboardingTest.php:141-302`; `spa/src/components/hr/OnboardingStepper.test.tsx:1-78`; `spa/e2e/hr-onboarding.spec.ts:1-77`; `spa/src/api/hr/onboarding.ts:20-29`; `spa/src/components/hr/OnboardingStepper.tsx:17-129`; `spa/src/lib/notificationMeta.ts:102-107` | No HTTP/UI completion action, retry control, focused frontend test, or onboarding browser smoke path existed. | Added route/auth/retry/preference/idempotency coverage, a focused stepper test, a browser smoke spec, the API mutation, retry/error UI, completion refresh, and notification metadata. | Focused ESLint passes; focused Vitest passes 13/13; browser execution is blocked by an unrelated pre-existing `Sidebar.tsx` `LuAward` runtime error after cache permissions were bypassed. |

## Verification performed

| Check | Result | Notes |
|---|---|---|
| PHP syntax | PASS | All changed PHP production, migration, route, and feature-test files passed `php -l`. |
| Route registration | PASS | `php artisan route:list --path=hr/employees` shows GET status, POST recompute, and POST department-team attestation. |
| Scoped diff whitespace | PASS | `git diff --check` clean for changed tracked module/integration files. |
| Focused SPA lint | PASS | Onboarding API, stepper, tests, notification metadata, and E2E spec pass ESLint. |
| Focused SPA unit tests | PASS | 2 files / 13 tests passed using a temporary Node loader and `/tmp` cache workaround. |
| Backend onboarding feature suite | BLOCKED | PostgreSQL host `db` could not be resolved before assertions. |
| SPA typecheck | BLOCKED | Existing unrelated errors: missing `qrcode` types, an implicit `dataUrl` type in assets, and duplicate JSX attributes in return management. |
| SPA full lint | BLOCKED | Existing unrelated hook-dependency errors in chain progress, journal entries, and quality inspections. |
| SPA build | BLOCKED | Pre-existing root-owned `spa/tsconfig.tsbuildinfo` prevents the build from writing. |
| Onboarding browser smoke | BLOCKED | With a temporary writable Vite cache, the app reaches a pre-existing `LuAward is not defined` runtime error in dirty `spa/src/components/layout/Sidebar.tsx`; the onboarding spec itself could not mount. |

## Deferred for re-audit

- Run `tests/Feature/HR/OnboardingTest.php` against a reachable PostgreSQL test
  service and confirm all new HTTP, activity, notification, preference, and
  retry assertions.
- Run the browser smoke after the unrelated Sidebar runtime error and writable
  SPA cache/build issues are resolved.
- Re-run the full SPA typecheck/lint/build gate in a clean dependency/build
  environment.

These are verification constraints, not a return to `📋 Plan Ready`; the
planned implementation and focused frontend tests are complete.
