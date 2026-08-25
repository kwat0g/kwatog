# M016 — People / Recruitment & Careers audit report

Audit date: 2026-08-25  
Claim: `people / recruitment-careers`  
Registry tier: 4  
Status: `📋 Plan Ready`  
Session recommendation: `separate-recommended`

## Audit scope and verdict

This is a fresh audit of the recruitment-careers module. The previous report was written before the changes recorded in `fix-log.md`; the module has since received material changes to authorization, lifecycle transitions, conversion, event history, filtering, pagination, and the SPA. The current working tree is already dirty in many unrelated areas, so this audit did not modify application source outside this module.

The module is substantially more complete than the prior baseline, but it is not ready for verification. The highest-risk remaining findings are:

- the applications permission can advance an application into `Hired`, despite `hr.recruitment.hire` being a separate permission;
- public submission and interview editing have race/state-integrity gaps;
- notification recipients are selected by role slug without checking the recruitment view permission;
- the public feature-toggle behavior is inconsistent and requires a product decision;
- lifecycle rules, event snapshots, and regression coverage are not yet explicit enough for safe release.

The combined plan is intentionally deferred to a dedicated fix session because it includes RBAC, concurrency, lifecycle/state-machine, and cross-surface changes.

## Evidence baseline

### What exists

- Private recruitment routes are grouped under authenticated HR and recruitment feature middleware, and resource permissions are separated into view/manage/applications/hire in `api/app/Modules/HR/routes.php:297-326` and `api/database/seeders/RolePermissionSeeder.php:367-371`.
- Job postings, applications, interviews, notes, and immutable-looking application events have migrations/models. The event model rejects updates/deletes after creation in `api/app/Modules/HR/Models/RecruitmentApplicationEvent.php:55-77`.
- Posting creation, application submission, stage changes, interview operations, notes, and conversion use service methods; the changed paths generally use `DB::transaction()` and row locks, for example `api/app/Modules/HR/Services/RecruitmentService.php:44-99`, `:186-249`, and `:448-500`.
- The private SPA has posting/application lists, detail pages, archive controls, pagination, interview outcome controls, notes, history, resume download, and conversion prefill. Public careers pages support listing, detail, application, and tracking.

### What is still missing or unverified

- There is no recruitment-specific Playwright/browser suite under `spa/e2e`; the existing files are for unrelated HR flows.
- There is no two-connection regression coverage for the public-submit/archive race, interview-outcome/stage race, or concurrent conversion/slot behavior.
- There is no recruitment `StateMachine` with an explicit `TRANSITIONS` map. Current transition rules are distributed between enum methods and service branches.
- Focused backend execution is currently blocked before the test body by an unrelated migration failure: `2026_08_25_210000_enforce_one_active_holiday_per_date.php:42` attempts to drop `holidays_date_name_unique` while the database reports that it is required by a constraint. The SPA typecheck is also blocked by unrelated existing errors in `spa/src/pages/assets/detail.tsx:6,67` and `spa/src/pages/return-management/detail.tsx:902`.

## Findings

### F01 — Broken — application permission can perform hiring transition

`hr.recruitment.applications` protects the change-stage route, while `hr.recruitment.hire` is separately defined for “Mark Hired & Convert to Employee” in `api/app/Modules/HR/routes.php:314-325` and `api/database/seeders/RolePermissionSeeder.php:367-371`. The controller sends every non-reject action to `RecruitmentService::advanceStage()` under only the applications permission in `api/app/Modules/HR/Http/Controllers/RecruitmentApplicationController.php:90-104`. The service’s normal `next()` path allows `Offer` to become `Hired` in `api/app/Modules/HR/Services/RecruitmentService.php:186-226`.

An applications-only user can therefore reach the hiring state without the dedicated hire permission. Conversion authorization is separately protected, but that does not repair the unauthorized state transition or its notifications/history. Enforce the hire permission at the transition boundary (or split the endpoint/action), and add an applications-only regression test for `Offer → Hired`.

### F02 — Broken — converted hires are reported as unconverted bottlenecks

The bottleneck command scans aged applications across all stages in `api/app/Console/Commands/CheckRecruitmentBottlenecks.php:72-116`. Its `Hired` branch reports “marked hired but not converted” based on age/stage, but does not require `converted_employee_id` to be null. A hired application that has already been converted can consequently continue to generate the same bottleneck alert.

Filter the condition to unconverted hires, and add a command test covering both converted and unconverted `Hired` applications.

### F03 — Incomplete — public recruitment bypasses the recruitment feature toggle

Private recruitment routes carry `feature:recruitment` in `api/app/Modules/HR/routes.php:297-312`, while public careers routes carry only throttling in `api/app/Modules/HR/routes.php:329-335`. The public controller independently queries open postings and accepts applications in `api/app/Modules/HR/Http/Controllers/PublicRecruitmentController.php:20-79`.

This is inconsistent if the recruitment feature flag is intended to disable the whole module. It may be intentional if the public careers site is always public, so this is also a product question rather than an assumption. Confirm the intended boundary; then either apply the feature middleware to public routes and define the public disabled response, or document/test the deliberate exception and make the UI behavior explicit.

### F04 — Broken — public application acceptance is not revalidated inside the transaction

`PublicRecruitmentController::apply()` checks posting status and closing time before calling the service in `api/app/Modules/HR/Http/Controllers/PublicRecruitmentController.php:49-63`. `RecruitmentService::submitApplication()` then starts its transaction and creates the application using the already-bound posting without locking and rechecking the posting’s current status/deadline in `api/app/Modules/HR/Services/RecruitmentService.php:101-133`.

A posting can be closed, archived, or otherwise changed between the controller check and the insert, allowing an application after the business deadline or during an archive race. Re-lock and revalidate the posting in the write transaction, then add a two-connection/concurrency regression test.

### F05 — Broken — interview edits can bypass the application state boundary

`updateInterview()` locks only the `ApplicationInterview` row and then loads the application in `api/app/Modules/HR/Services/RecruitmentService.php:308-357`. It does not lock/recheck the application’s stage or terminal state before changing the outcome. The Offer gate separately queries for a passed interview while advancing the application in `api/app/Modules/HR/Services/RecruitmentService.php:191-211`.

The private controller exposes the update under the applications permission in `api/app/Modules/HR/Http/Controllers/RecruitmentApplicationController.php:118-129`, and the SPA keeps the outcome selector available for existing interviews whenever the user has that permission in `spa/src/pages/hr/recruitment/applications/detail.tsx:343-374`. Depending on timing, an outcome can be changed after the application has advanced to Offer, Hired, or Rejected, invalidating the gate and historical meaning.

Define the allowed terminal/edit policy, lock the parent application and interview in a consistent order, revalidate the stage, and cover both sequential and concurrent edits. The UI must reflect the same policy.

### F06 — Incomplete — posting archive/update lifecycle is not atomic or policy-complete

Posting deletion checks Draft/no applications and then deletes directly from the controller in `api/app/Modules/HR/Http/Controllers/RecruitmentPostingController.php:120-131`; it does not lock the posting or use a transaction around the check and delete. Public submission can therefore race with the archive decision. Posting updates do lock a row in `api/app/Modules/HR/Services/RecruitmentService.php:57-64`, but the request permits changes to department, position, slots, dates, and other lifecycle fields in `api/app/Modules/HR/Http/Requests/UpdateJobPostingRequest.php:36-55`, while the service applies the payload without an explicit status/immutability policy.

Make archive and update invariants explicit, perform check-and-write atomically, and decide which fields remain editable once applications exist or a posting is open/closed. Add tests for archive/submission and update/application races.

### F07 — Incomplete — workflow event history is not a complete immutable audit snapshot

Interview update events record only a subset of the interview state: the service builds the before/after data around interview ID, outcome, and scheduled time in `api/app/Modules/HR/Services/RecruitmentService.php:319-357`. Location, interviewer, and notes changes are therefore not reconstructible from the event history. The event table also cascades with application deletion and nulls the actor on user deletion in `api/database/migrations/2026_08_25_231000_create_recruitment_application_events_table.php:13-33`; the model-level guards in `api/app/Modules/HR/Models/RecruitmentApplicationEvent.php:55-77` do not provide database-level immutability or retention protection.

Decide the required audit retention and redaction contract, include all business-relevant changed fields (with PII-safe values), and enforce the chosen immutability/retention behavior at the persistence boundary. Add tests for snapshots and attempted mutation.

### F08 — Broken — HR notifications are not permission-safe

`notifyHr()` selects active users from configured role slugs and uses their addresses in `api/app/Modules/HR/Services/RecruitmentService.php:644-679`. The bottleneck command repeats role-based selection in `api/app/Console/Commands/CheckRecruitmentBottlenecks.php:196-211`. The setting request validates only that `hr.recruitment.notification_roles` is an array with at least one value in `api/app/Modules/Admin/Http/Requests/UpdateSettingRequest.php:251-253`; it does not constrain the configured roles to users with `hr.recruitment.view`.

The configured recipient set can include active users who should not see candidate names, resume links, or tracking URLs. Resolve recipients through the permission assignment (while preserving the intended fallback), validate or safely ignore invalid configured roles, and test the recipient boundary.

### F09 — Incomplete — transition and exception conventions are not explicit at the domain boundary

`ApplicationStage::next()` encodes a linear path in `api/app/Modules/HR/Enums/ApplicationStage.php:40-53`, while job-posting transitions are a `match` inside `JobPostingStatus::canTransitionTo()` in `api/app/Modules/HR/Enums/JobPostingStatus.php:24-31`; additional requirements such as the passed-interview gate live in service conditionals in `api/app/Modules/HR/Services/RecruitmentService.php:193-216`. There is no module-specific state machine with an explicit `TRANSITIONS` constant, and same-state/idempotent behavior is not defined uniformly.

Several policy failures use generic `BusinessRuleException`/abort paths without a stable error code and HTTP status contract; `BusinessRuleException::errorCode()` is nullable in `api/app/Common/Exceptions/BusinessRuleException.php:28-45`, and posting deletion uses a bare 422 abort in `api/app/Modules/HR/Http/Controllers/RecruitmentPostingController.php:120-128`. Consolidate transition policy at the domain boundary, use the repository’s `StateMachine`/`TRANSITIONS` convention, and standardize `DomainException(message, 'CODE', httpStatus)` behavior where required by the module contract.

### F10 — Incomplete — SPA does not expose or persist the full backend workflow contract

The backend interview update endpoint accepts schedule, location, interviewer, notes, and outcome in `api/app/Modules/HR/Http/Controllers/RecruitmentApplicationController.php:118-126`, but the application detail page currently exposes only the outcome selector for existing interviews in `spa/src/pages/hr/recruitment/applications/detail.tsx:343-374`. Stage filters are local state rather than URL-backed state in `spa/src/pages/hr/recruitment/applications/index.tsx:35-41,97-103`, and posting status/archive filters are only partially URL-backed in `spa/src/pages/hr/recruitment/postings/index.tsx:38-45,123-138`.

The posting detail page sends a `trashed` query value while loading the posting in `spa/src/pages/hr/recruitment/postings/detail.tsx:50-53,162-164`, but the show action does not consume that filter and the route is already configured to bind trashed postings in `api/app/Modules/HR/routes.php:303-305`; this makes the archive scope misleading. Align the API/UI contract, make filter state shareable/restorable, and either implement or remove the unused archive parameter.

### F11 — Polish — accessibility, opaque surfaces, and validation feedback need finishing

The design system requires opaque surfaces and linked form labels in `docs/DESIGN-SYSTEM.md:15-17,523-527`. Public application controls have labels without corresponding `htmlFor`/input IDs in `spa/src/pages/careers/detail.tsx:176-200,235-238`, and the tracking form has no visible label for its input in `spa/src/pages/careers/track.tsx:66-76`. Recruitment detail panels use translucent-looking opacity variants such as `bg-danger-bg/5` and `bg-accent/5` in `spa/src/pages/hr/recruitment/applications/detail.tsx:190-212,475-486`. Posting create/edit forms do not consistently attach server error props to salary, slots, employment, and deadline controls in `spa/src/pages/hr/recruitment/postings/create.tsx:146-176`.

These are polish findings rather than backend correctness failures, but they affect keyboard/screen-reader use, visual consistency, and the ability to recover from validation errors. Add explicit label associations, use approved opaque surface tokens, and display field-level server errors consistently.

### F12 — Missing — regression evidence for the new hardening paths is incomplete

Existing feature coverage exercises list filtering, archive behavior, stage/reject/interview flows, conversion authorization, history, and terminal actions in `api/tests/Feature/HR/RecruitmentApplicationTest.php:76-315` and `api/tests/Feature/HR/RecruitmentPostingTest.php:85-181`. Public behavior is covered in `api/tests/Feature/HR/PublicRecruitmentTest.php:54-229`, and basic bottleneck behavior is covered in `api/tests/Feature/HR/RecruitmentBottleneckCommandTest.php:22-94`.

The suite does not cover the F01 applications-only hiring transition, F02 converted-hire deduplication, F03 public feature behavior, F04/F05/F06 concurrency/state races, F07 complete event snapshots, or F08 permission-filtered notifications. There is no recruitment browser suite. Test execution is currently blocked by the unrelated holidays migration described above, so these cases still need to be added and run after the environment blocker is resolved.

## Questions requiring an explicit product/domain decision

These are recorded rather than guessed:

1. Is a passed interview required before every `Offer → Hired` transition, or only before `Interview → Offer`?
2. Are posting `slots` a hard cap that must reject excess hires, or only a signal used to mark a posting Filled?
3. When converting an applicant, must department/position be preserved from the posting, or may HR edit those values before creating the employee?
4. Is the public recruitment feature supposed to obey `feature:recruitment`, and is `closes_at` inclusive through the displayed date or expired at its start?

## Verification performed

- PHP lint passed for the recruitment controllers, requests, resources, models, enums, service, command, and event migration.
- Recruitment route listing passed and showed the expected private/public routes; the public routes currently expose only throttling middleware.
- Targeted recruitment ESLint passed.
- SPA token audit passed (`782` files checked).
- Full SPA typecheck remains blocked by the unrelated `qrcode`, implicit-any, and duplicate-JSX-attribute errors noted above.
- A focused backend test could not reach its test body because the unrelated holidays migration failed. Browser and concurrency verification were not run.

## Audit conclusion

The module should remain `📋 Plan Ready`. No application source fix was made during this audit, and the existing `fix-log.md` remains the record of the prior hardening work. Execute the ordered action plan in a dedicated session, resolve the four decision gates, then re-audit the affected findings before marking the module verified.
