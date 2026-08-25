# M016 — People / Recruitment & Careers fix log

Audit date: 2026-08-24  
Fix session: 2026-08-25  
Module status: 🔁 Needs Re-audit  
Implementation status: **Hardening and UI fixes applied; full regression evidence remains pending.**

## Session record

- Refreshed the registry and atomically claimed the existing `📋 Plan Ready` module `people / recruitment-careers` (M016), so this session resumed the existing plan as the dedicated hardening session.
- Read the existing `audit-report.md` and `action-plan.md` in full before changing code. No discovery or plan rewrite was performed.
- Preserved unrelated dirty-worktree changes outside the recruitment/careers implementation and its focused tests.

## Fixes applied

| Finding | Evidence | Before → after |
|---|---|---|
| F01 conversion authorization | `api/app/Modules/HR/Requests/StoreEmployeeRequest.php:20-33`; `api/app/Modules/HR/Controllers/EmployeeController.php:154-190`; `api/tests/Feature/HR/RecruitmentApplicationTest.php:104-149` | Employee creation alone could link a hired application → the `from_application` branch now requires `hr.recruitment.hire`, validates Hired state, and has a custom-role negative test. |
| F06 serialized transitions | `api/app/Modules/HR/Services/RecruitmentService.php:67-100,191-227,256-301,313-365,375-397,453-500` | Stage, rejection, interview, posting-status, restore, and conversion decisions used stale/unlocked rows → authoritative rows are locked and re-checked inside transactions; conversion locks application and posting capacity. |
| F08 atomic/replayable conversion | `api/app/Modules/HR/Controllers/EmployeeController.php:169-190`; `api/app/Modules/HR/Services/RecruitmentService.php:453-500` | Employee creation committed before recruitment handoff and retries could duplicate work → employee creation plus `markConverted()` share an outer transaction, and a retry returns the already-linked employee. |
| F03 archive/restore | `api/app/Modules/HR/Controllers/RecruitmentPostingController.php:26-66,132-139`; `api/app/Modules/HR/routes.php:303-311`; `spa/src/pages/hr/recruitment/postings/index.tsx:39-52,134-138`; `postings/detail.tsx:47-72,145-166`; `api/tests/Feature/HR/RecruitmentPostingTest.php:111-151` | Archived postings disappeared from normal discovery and detail binding was active-only → `trashed=active|with|only`, soft-deleted detail binding, locked restore, SPA archive filter, and archive→restore coverage are present. |
| F02 list query contract | `api/app/Modules/HR/Controllers/RecruitmentPostingController.php:26-66`; `api/app/Modules/HR/Controllers/RecruitmentApplicationController.php:30-74`; `api/tests/Feature/HR/RecruitmentApplicationTest.php:91-102` | Search, sorting, and page-size controls were ignored → both list APIs validate allowlisted filters, sort directions, and bounded `per_page`; applications now search and paginate server-side. |
| F05 interview outcome policy | `api/app/Modules/HR/Services/RecruitmentService.php:204-209,326-358`; `spa/src/pages/hr/recruitment/applications/detail.tsx:98-106,351-366`; `api/tests/Feature/HR/RecruitmentApplicationTest.php:238-284` | HR could not edit outcomes in the normal SPA and Interview→Offer ignored pending outcomes → authorized HR users can set Pending/Passed/Failed and at least one Passed interview is required before Offer. This passed-only rule is the implementation assumption to confirm with product. |
| F07 decision history | `api/database/migrations/2026_08_25_231000_create_recruitment_application_events_table.php:13-39`; `api/app/Modules/HR/Models/RecruitmentApplicationEvent.php:18-79`; `api/app/Modules/HR/Services/RecruitmentService.php:598-630`; `api/app/Modules/HR/Controllers/RecruitmentApplicationController.php:132-138`; `spa/src/pages/hr/recruitment/applications/detail.tsx:433-453` | Application decisions/interview updates had no immutable actor trail → redacted immutable events now record stage, rejection, interview, note, and conversion workflow evidence with actor/correlation fields and a read-only HR history surface. |
| F11 regression coverage | `api/tests/Feature/HR/RecruitmentApplicationTest.php:91-149,238-284`; `api/tests/Feature/HR/RecruitmentPostingTest.php:78-151`; `api/tests/Feature/HR/PublicRecruitmentTest.php:165-190` | Material controls lacked regression fixtures → added authorization, search/pagination, outcome gate/history, archive/restore, department-position invariant, and Hired tracker tests. Full execution is blocked by the unrelated test-bootstrap migration noted below. |
| F09 position/department invariant | `api/app/Modules/HR/Requests/StoreJobPostingRequest.php:39-44`; `api/app/Modules/HR/Requests/UpdateJobPostingRequest.php:39-44`; `api/tests/Feature/HR/RecruitmentPostingTest.php:78-96` | Any existing position could be paired with any department → validation now constrains the position to the selected active department. |
| F10 posting application pagination | `spa/src/pages/hr/recruitment/postings/detail.tsx:53-64,186-201` | Posting detail rendered only one unlabelled page → it requests page/per-page parameters, displays the total, and passes pagination controls to `DataTable`. |
| F04 Hired tracker | `api/app/Modules/HR/Services/RecruitmentService.php:419-426`; `spa/src/pages/careers/track.tsx:49-50,103-158`; `api/tests/Feature/HR/PublicRecruitmentTest.php:165-190` | Hired was omitted from the public progress state → Hired is included as a terminal step and gets an explicit success message. |
| Polish | `spa/src/pages/careers/detail.tsx:36-40,103-109,203-232`; `spa/src/pages/hr/recruitment/applications/detail.tsx:271-291` | Resume upload was a non-keyboard clickable div, public detail had no retry, and resume download had no error path → accessible labelled input with size feedback, retry action, visible download error, loading state, and delayed object-URL cleanup. |

## Verification performed

| Check | Result | Notes |
|---|---|---|
| PHP syntax | PASS | Touched recruitment controllers, service, requests, model/resource, migration, routes, and focused tests all passed `php -l`. |
| Route registration | PASS | `docker compose exec -T api php artisan route:list --path=recruitment --json` registered the HR/public recruitment routes, including history and soft-deleted posting detail. |
| Recruitment event migration | PASS | Applied the new migration in isolation to the PostgreSQL test schema; the table and foreign-key migration completed. |
| SPA targeted lint | PASS | ESLint passed for the touched recruitment/careers API, types, and pages. |
| SPA typecheck | BLOCKED | No recruitment/careers type errors, but the repository still reports unrelated errors in `assets/detail.tsx`, `calendar/index.tsx`, and `return-management/detail.tsx`. |
| Focused PostgreSQL API suite | BLOCKED | `PublicRecruitmentTest`, `RecruitmentApplicationTest`, and `RecruitmentPostingTest` could not reach assertions because `2026_08_25_210000_enforce_one_active_holiday_per_date.php` attempts to drop an index still owned by the old `holidays_date_name_unique` constraint. The run ended with 25 failures and 0 assertions. No source outside this module was changed to bypass it. |
| Browser smoke | NOT RUN | No recruitment-specific Playwright journey or live authenticated browser session was available. |
| Two-connection concurrency | NOT RUN | No dedicated PostgreSQL race harness was available. |

## Deferred / needs re-audit

1. Re-run the focused API suite after the unrelated holiday migration is corrected in its owning module, then add/execute two-connection PostgreSQL race coverage.
2. Run a browser journey for archive→restore, public apply/track, interview outcome editing, and employee conversion.
3. Confirm the business rule that a Passed interview is mandatory before Offer; the current implementation uses that conservative policy because the existing module had no explicit written rule.
4. Re-run the full SPA typecheck/build after the pre-existing `assets`, `calendar`, and `return-management` TypeScript errors and build-cache ownership issue are resolved.

The module is therefore released as `🔁 Needs Re-audit`, not `✅ Verified`.

## Continued fix session — 2026-08-25

This session reclaimed M016 from the registry's `📋 Plan Ready` queue and
executed the existing plan without repeating discovery or rewriting the audit.
The prior hardening changes were preserved; this session added the following
scoped fixes.

### Fixes applied

| Finding | Evidence | Before → after |
|---|---|---|
| F01 hire authorization | `api/app/Modules/HR/routes.php:318`; `api/app/Modules/HR/Requests/AdvanceApplicationRequest.php:13-27`; `api/app/Modules/HR/Services/RecruitmentService.php:224-246`; `api/tests/Feature/HR/RecruitmentApplicationTest.php:151-201` | The stage route accepted `hr.recruitment.applications` for every advance → the route admits either relevant permission, the request requires `hr.recruitment.hire` for an Offer→Hired request, and the service rechecks the actor at the locked transition boundary. Applications-only denial and hire-only success regressions were added. |
| F02 converted-hire bottleneck | `api/app/Console/Commands/CheckRecruitmentBottlenecks.php:83-90,117-123`; `api/tests/Feature/HR/RecruitmentBottleneckCommandTest.php:98-143` | Every aged Hired application entered the scan → the Hired branch is selected only when `converted_employee_id` is null; converted and unconverted cases are covered together. |
| F04 public submission race | `api/app/Modules/HR/Services/RecruitmentService.php:132-145`; `api/tests/Feature/HR/PublicRecruitmentTest.php:141-157` | Public status/deadline checks happened only before the service transaction → the service locks the authoritative posting and rechecks Open/deadline state before inserting, deleting the staged resume when the write is rejected. |
| F06 archive race | `api/app/Modules/HR/Services/RecruitmentService.php:103-119`; `api/app/Modules/HR/Controllers/RecruitmentPostingController.php:120-124`; `api/tests/Feature/HR/RecruitmentPostingTest.php:134-160` | The controller checked status/applications and deleted directly → the service locks, checks, and soft-deletes atomically; a posting with an application remains active. Update mutability and slot-cap semantics remain deferred pending the recorded domain decisions. |
| F08 notification authorization | `api/app/Modules/HR/Support/RecruitmentNotificationRecipients.php:18-42`; `api/app/Modules/HR/Services/RecruitmentService.php:712-716`; `api/app/Console/Commands/CheckRecruitmentBottlenecks.php:203-206`; `api/tests/Feature/HR/RecruitmentBottleneckCommandTest.php:145-169` | Active users selected by configured role slug could receive candidate PII without recruitment view permission → configured role slugs are resolved against live roles and each active recipient must pass effective `hr.recruitment.view` authorization, including overrides/system-admin behavior. |
| F09 transition table | `api/app/Modules/HR/Support/RecruitmentApplicationStateMachine.php:10-58`; `api/app/Modules/HR/Support/RecruitmentPostingStateMachine.php:10-36`; `api/app/Modules/HR/Enums/ApplicationStage.php:39-42`; `api/app/Modules/HR/Enums/JobPostingStatus.php:24-27`; `api/tests/Unit/HR/RecruitmentStateMachineTest.php:15-38` | Application and posting transitions were distributed across enum matches and service branches → explicit module `TRANSITIONS` maps now back the enum helpers and service transition checks. Stable codes for every remaining business-rule failure are still pending the repository's broader exception-contract decision. |
| F10 SPA/API filter contract | `spa/src/pages/hr/recruitment/applications/index.tsx:37-46,96-101`; `spa/src/pages/hr/recruitment/postings/index.tsx:41-53,125-140`; `spa/src/pages/hr/recruitment/postings/detail.tsx:46-58,143-157` | Stage/status/archive filters were local state and posting detail sent an ignored `trashed` parameter → list filters are URL-backed and posting detail no longer exposes/sends the unused archive filter. Full interview-edit UI/API alignment remains deferred with the interview lifecycle policy. |
| F11 frontend polish | `spa/src/pages/careers/detail.tsx:180-237`; `spa/src/pages/careers/track.tsx:72-79`; `spa/src/pages/hr/recruitment/applications/detail.tsx:192-212,476-484`; `spa/src/pages/hr/recruitment/postings/create.tsx:156-173`; `spa/src/pages/hr/recruitment/postings/edit.tsx:194-211` | Public labels were not associated, tracking had no visible label, recruitment panels used translucent surfaces, and posting fields lacked server-error props → labels/IDs, opaque semantic surfaces, and field-level feedback are now explicit. |
| F12 regression evidence | `api/tests/Feature/HR/RecruitmentApplicationTest.php:151-201`; `api/tests/Feature/HR/PublicRecruitmentTest.php:141-157`; `api/tests/Feature/HR/RecruitmentBottleneckCommandTest.php:98-169`; `api/tests/Feature/HR/RecruitmentPostingTest.php:134-160`; `api/tests/Unit/HR/RecruitmentStateMachineTest.php:15-38` | Missing regressions for the changed permission, posting write boundary, bottleneck filter, recipient audience, archive invariant, and transition map → focused tests were added. The unit state-machine suite passes; the feature suite did not reach assertions because the shared `ogami_test` migration state is inconsistent and then hits the unrelated holidays migration constraint at `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`. |

### Verification performed in this session

- PHP lint passed for all changed recruitment services, controllers, requests,
  enums, support classes, commands, and focused tests.
- Targeted recruitment/careers ESLint passed.
- SPA token audit passed: `783` files checked.
- SPA typecheck remains blocked only by the previously known unrelated errors in
  `spa/src/pages/assets/detail.tsx:6,67` and
  `spa/src/pages/return-management/detail.tsx:902`.
- Recruitment route listing passed and shows the stage route using
  `permission_any:hr.recruitment.applications,hr.recruitment.hire`; public routes
  remain unchanged pending the feature-toggle decision.
- Recruitment state-machine unit tests passed: 2 tests, 5 assertions.
- Targeted Pint invocation is blocked by the container's existing
  `ConfigurationJsonRepository.php` directory-read error; no formatter write was
  attempted.
- No recruitment Playwright journey or two-connection race harness was
  available; those remain pending.

### Deferred items and exact questions

The following were not guessed or silently changed:

1. Is a Passed interview required before every `Offer → Hired` transition, or
   only before `Interview → Offer`? The existing conservative Passed gate remains.
2. Are posting slots a hard cap that must reject excess hires, or only a signal
   used to mark a posting Filled?
3. During conversion, must department/position be preserved from the posting, or
   may HR edit them before employee creation?
4. Should public recruitment obey `feature:recruitment`, and is `closes_at`
   inclusive through the displayed date or expired at its start?
5. Are interview edits allowed after Offer/Hired/Rejected? Until this is decided,
   the parent-stage locking/policy change and full edit-surface alignment remain
   pending.
6. What is the required event retention/redaction contract, including whether
   database-level immutability and application-delete cascades are allowed?

Because these decisions and the environment/browser/concurrency evidence remain
pending, M016 is released as `🔁 Needs Re-audit`, not `✅ Verified`.
