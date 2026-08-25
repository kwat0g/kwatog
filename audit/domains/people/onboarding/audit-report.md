# M015 — People / Onboarding audit report

Audit date: 2026-08-24  
Claim: people / onboarding  
Registry tier: 4  
Status: 📋 Plan Ready  
Session recommendation: separate-recommended

## Verdict

Production-readiness score: **46/100 — not ready for an unqualified release**.

The module has a coherent employee onboarding tracker, protected read/recompute
routes, durable employee-creation wiring, a scheduled reminder command, and a
visible SPA stepper. Two workflow controls are not production-complete: the
required `Dept Team Notified` step has no product completion path, and the stale
reminder job silently sends nothing while recording the reminder as sent. The
remaining work crosses workflow authorization, notifications, UI actions, and
regression coverage, so no production-code fix was applied during this audit.

## Evidence checked

- Refreshed the registry, confirmed no competing lock, atomically claimed M015,
  reviewed the dirty worktree, current branch, and recent commits, and kept all
  pre-existing application changes untouched.
- Reviewed the onboarding routes/controller, employee row-scope behavior and
  role grants, onboarding service/model/resource, employee create/update hooks,
  employee shift assignment schema, account-provisioning listener, migration,
  settings, scheduler/command, notification service/catalog, and shared hash-ID
  binding behavior.
- Reviewed the SPA API client, employee-detail mount, stepper loading/error
  states, notification link contract, and available HR/browser test files.
- `php artisan route:list --path=hr/employees` — onboarding GET and recompute
  POST routes are registered.
- PHP lint over the onboarding/employee surface — passed.
- `php artisan test tests/Feature/HR/OnboardingTest.php tests/Feature/HR/EmployeeDataScopeTest.php`
  — blocked before assertions: PostgreSQL host `db` could not be resolved; **9
  tests / 0 assertions**.
- `npm run typecheck` in `spa` — passed.
- `npm run lint` in `spa` — passed.
- `npm run build` — blocked by a pre-existing root-owned
  `spa/tsconfig.tsbuildinfo` (`EACCES`), before the Vite build ran.
- No dedicated onboarding SPA unit/E2E test was found, and no live authenticated
  API/SPA environment or production-like HR dataset was available.

## Strengths

- The module is behind Sanctum and the HR feature gate, and both onboarding
  routes require `hr.employees.edit` (`api/app/Modules/HR/routes.php:25,102-106`).
  The seeded department-head role has employee view but not employee edit, and
  the existing scope regression test expects its onboarding request to be 403
  (`api/database/seeders/RolePermissionSeeder.php:670-688`;
  `api/tests/Feature/HR/EmployeeDataScopeTest.php:103-126`).
- Employee creation and onboarding initialization run inside the employee
  service transaction, with a one-row-per-employee unique foreign key
  (`api/app/Modules/HR/Services/EmployeeService.php:172-215`;
  `api/database/migrations/0120_create_employee_onboarding_table.php:16-32`).
- Account provisioning is a durable, queued hire listener and the onboarding
  service derives the account step from the linked user relation
  (`api/app/Providers/AppServiceProvider.php:350-354`;
  `api/app/Modules/HR/Listeners/AutoProvisionUserOnEmployeeHire.php:16-51`;
  `api/app/Modules/HR/Services/OnboardingService.php:69-72`).
- The SPA has an explicit loading skeleton and error state, and it only mounts
  the stepper for users with the employee-edit permission
  (`spa/src/components/hr/OnboardingStepper.tsx:18-60`;
  `spa/src/pages/hr/employees/detail.tsx:140-145`).

## Findings

### M015-F01 — Missing/broken: the required department-notification step cannot be completed in the product

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

`EmployeeOnboarding::stepKeys()` includes `dept_team_notified`, and
`isComplete()` requires every listed step to have a timestamp
(`api/app/Modules/HR/Models/EmployeeOnboarding.php:45-55,77-85`). The service
does provide `markStep()`, but the only repository call is the feature test;
production routes expose only GET status and POST recompute
(`api/app/Modules/HR/Services/OnboardingService.php:88-105`;
`api/tests/Feature/HR/OnboardingTest.php:89-99`;
`api/app/Modules/HR/routes.php:102-106`). The SPA stepper is read-only and has
no mutation control or action (`spa/src/components/hr/OnboardingStepper.tsx:38-59`;
`spa/src/api/hr/onboarding.ts:15-24`).

As a result, an ordinary hire can never reach `completed_at` through the
supported application flow unless another hidden caller writes the field. The
test that proves completion calls the service directly, so it does not prove a
user can finish the workflow.

Action: decide whether this is an HR attestation or a canonical notification
event. Then add an actor-authorized, idempotent completion action and a visible
SPA control (or derive it from a durable notification/outbox event). Record the
actor/time in the audit trail, prevent arbitrary step-key mutation, and add
same-department/unauthorized and full-completion API tests.

### M015-F02 — Broken/high: stale onboarding reminders report success without delivering a notification

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The scheduled command is wired daily and calls the service
(`api/routes/console.php:146-151`; `api/app/Console/Commands/SendOnboardingReminders.php:13-23`).
The service conditionally calls `NotificationService::notifyRole()`
(`api/app/Modules/HR/Services/OnboardingService.php:155-177`), but the current
notification service exposes `send()`, `sendInApp()`, and legacy `notify()` —
there is no `notifyRole()` method (`api/app/Common/Services/NotificationService.php:47-76,178-195`).
The `method_exists()` guard therefore skips delivery without raising an error,
then line 177 marks the row sent and line 178 increments the success count.

Even if a role helper is added later, the payload uses `link` while the shared
notification contract and SPA consume `link_to`
(`api/app/Modules/HR/Services/OnboardingService.php:163-171`;
`api/app/Common/Services/NotificationService.php:47-51`;
`spa/src/pages/notifications/index.tsx:128-132`). The default notification
catalog also has no onboarding reminder type in its Chain 3 entries
(`api/app/Common/Services/NotificationCatalog.php:186-202`). The existing
feature test verifies only the returned count and timestamp, not that a durable
notification row exists (`api/tests/Feature/HR/OnboardingTest.php:102-121`).

Action: resolve the configured HR audience explicitly and call the shared
notification API with a catalogued type such as `hr.onboarding.stale` and a
`link_to` employee URL. Update `reminder_sent_at` only after the notification
insert succeeds; define retry behavior for an empty audience or delivery
failure. Test the notification row, link, preference behavior, and retry path.

### M015-F03 — Incomplete/medium: the read status endpoint mutates onboarding state

Priority: **P2**  
Scope: **small-to-medium**  
Recommendation: **same-session only after the workflow contract is settled**

`GET /onboarding` calls `status()`, which calls `recompute()`; recompute can
`firstOrCreate()` the tracker, set step timestamps, save it, and set
`completed_at` (`api/app/Modules/HR/Controllers/EmployeeOnboardingController.php:17-21`;
`api/app/Modules/HR/Services/OnboardingService.php:52-85,116-131`). The POST
recompute controller then calls recompute and immediately calls status, causing
the same write path twice (`api/app/Modules/HR/Controllers/EmployeeOnboardingController.php:24-30`).

This is especially surprising for a read endpoint and makes UI viewing a
state-changing operation. It also obscures whether completion was an HR action
or a side effect of opening the employee page.

Action: split a pure projection from the write-side recompute, or explicitly
document the read-side reconciliation and wrap it in a safe transactional
operation. Return the recomputed result from the POST without a second write,
and add a GET-does-not-write test if the API is made pure.

### M015-F04 — Incomplete/high: critical onboarding behavior lacks executable regression coverage

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The existing service feature test covers initialization, account detection,
direct service-level completion, and stale-row counting, but it does not call
the HTTP onboarding routes, prove the department-notification action exists, or
assert notification delivery (`api/tests/Feature/HR/OnboardingTest.php:49-121`).
The row-scope suite covers the department-head denial, but no dedicated SPA
onboarding test or onboarding browser spec exists. The focused backend suite
could not execute in this environment because the PostgreSQL host was
unavailable, and the production SPA build could not write its pre-existing
root-owned build-info file.

Action: add API tests for HR/admin success, department-head and other-role
denial, archived/missing employees, completion transition, reminder delivery,
retry, and notification preferences. Add a focused stepper test/browser smoke
path for loading, error, incomplete, completion, and the new action. Run the
suite in CI with a writable dependency/build cache and PostgreSQL.

## Polish pass

- The stepper follows the existing design-token vocabulary and provides a
  skeleton plus visible error copy (`spa/src/components/hr/OnboardingStepper.tsx:24-59`).
- The UI is informational only: it has no retry button, no action affordance,
  and no explanation of why an incomplete step is blocked. This is currently a
  consequence of M015-F01 and should be addressed with that workflow rather
  than as a cosmetic-only patch.
- No independent visual blocker was promoted above the completion and reminder
  failures.

## Production-audit assessment

### Blockers / high-value risks

1. M015-F01 makes the seven-step onboarding state machine unable to complete
   through the supported product surface.
2. M015-F02 causes the scheduled stale-reminder job to claim delivery while
   sending no notification and suppressing future retries.
3. M015-F04 leaves these workflow and notification controls without executable
   HTTP/UI regression proof.

### Evidence still missing

- A live PostgreSQL run of the onboarding and row-scope feature tests.
- Authenticated API proof for HR/admin success and non-HR denial on both routes.
- A notification-database assertion showing the stale reminder type, recipient,
  `link_to`, and retry behavior.
- A supported browser path that completes `Dept Team Notified` and reaches
  `completed_at`.
- A writable SPA build environment and production-like employee/account data.

### Next action

Do not promote M015 to Verified. In a separate hardening session, first define
and implement the department-notification completion contract and repair the
reminder delivery pipeline. Then add route/UI regression coverage and decide
whether status reconciliation remains a write-side operation.
