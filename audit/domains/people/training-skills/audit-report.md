# M017 — People / Training & Skills audit report

Audit date: 2026-08-24  
Claim: people / training-skills  
Registry tier: 4  
Status: 📋 Plan Ready  
Session recommendation: separate-recommended

## Verdict

Production-readiness score: **48/100 — not ready for an unqualified release**.

The module has a credible baseline: permission-gated HR routes, hash-ID binding, transactional catalog and assignment writes, soft-delete recovery routes, a scheduled tiered expiry command, a primary matrix UI, self-service row scoping, and a passing focused API suite. Release is held back by a department-head subresource scope bypass, an unguarded and unsynchronized training state machine, a skill-assignment modal that cannot satisfy the API contract, missing certificate evidence handling, alert markers that can advance without durable delivery, unbounded matrix queries, and several incomplete secondary/UI contracts. The passing tests are mainly positive-path tests and do not cover those production-risk branches.

## Evidence checked

- Refreshed the registry, atomically claimed M017, reviewed the dirty worktree, current branch, dependency status, and recent changes, and preserved all pre-existing application changes.
- Reviewed catalog, employee training, skills, matrix, and self-service routes/controllers/requests/resources/models/enums; hash-ID binding; role grants; DepartmentScope conventions; migrations; audit traits; scheduler/command/settings; notification behavior; SPA routes/pages/API/types; and available tests.
- Containerized focused API suite passed: **27 tests / 95 assertions** including self-service isolation.
- Route registration and PHP syntax checks passed.
- SPA typecheck and full ESLint passed.
- SPA production build was blocked by the pre-existing root-owned `tsconfig.tsbuildinfo`; no source/type error was reported.
- No browser journey, production-like dataset, concurrent transaction harness, or live notification-provider delivery was available.

## Strengths

- HR endpoints are separated by explicit view/manage permissions, and training/skill models use the shared hash-ID binding rather than exposing raw identifiers (`api/app/Modules/HR/routes.php:158-224`; `api/app/Common/Traits/HasHashId.php:18-67`).
- Training and skill catalog models carry audit logging and soft deletion; training and employee-skill restore routes opt into `withTrashed()` (`api/app/Modules/HR/Models/Training.php:15-39`; `Skill.php:13-26`; `routes.php:184-186,217-224`).
- Assignment, completion, cancellation, catalog mutation, and skill mutation are wrapped in database transactions (`api/app/Modules/HR/Services/EmployeeTrainingService.php:24-36,45-75`; `TrainingService.php:38-55`; `EmployeeSkillService.php:23-67`).
- Expiry work is scheduled daily with overlap protection and one-server execution, and thresholds are configurable with descending-order validation (`api/routes/console.php:266-272`; `TrainingExpiryService.php:159-173`; `api/database/migrations/0295_seed_supplier_hr_crm_and_quality_sla_settings.php:24-26`).
- The primary matrix has a working hash-ID department filter, explicit trained/expired/gap statuses, summary counts, loading/error/empty states, and accessible cell labels (`TrainingMatrixController.php:25-110`; `spa/src/pages/hr/training/matrix.tsx:46-55,138-155,211-228`).
- Self-service training reads are scoped from the authenticated user’s linked employee and have positive isolation tests (`SelfServiceController.php:513-531`; `api/tests/Feature/HR/SelfServiceTrainingsTest.php:31-77`).

## Findings

### M017-F01 — Broken/high: employee training and skill subresources bypass department-head row scope

Priority: **P1**  
Category: Broken  
Scope: **medium**  
Recommendation: **separate-recommended**

The `department_head` role receives `hr.employees.view` and `hr.employees.trainings.view` (`api/database/seeders/RolePermissionSeeder.php:670-688`). The employee master service applies `DepartmentScope` to employee list/show reads, limiting that grant to the user’s department plus self (`api/app/Modules/HR/Services/EmployeeService.php:44-58,149-163`). The training and skill subresource controllers instead query by the bound employee ID without passing the acting user or applying the same scope (`api/app/Modules/HR/Controllers/EmployeeTrainingController.php:23-32`; `EmployeeSkillController.php:21-29`). Their routes expose those reads directly under the view permission (`api/app/Modules/HR/routes.php:159-160,210-212`).

An authenticated department head who knows another employee’s hash ID can therefore read that employee’s training and skill records directly, even though the normal employee resource is department-scoped. Hash IDs identify rows; they do not enforce authorization. This is a cross-department HR data exposure.

Action: apply the centralized department scope to employee subresources, or authorize the bound employee/record against the acting user before query/response. Add negative tests for a second department, including direct hash-ID requests and record mutation attempts for any custom role granted manage.

### M017-F02 — Broken/high: training lifecycle transitions are unrestricted and not serialized

Priority: **P1**  
Category: Broken  
Scope: **medium**  
Recommendation: **separate-recommended**

`recordCompletion()` unconditionally changes any bound record to `completed`, and `cancel()` unconditionally changes any bound record to `cancelled`; neither method checks the current enum state or locks/reloads the authoritative row (`api/app/Modules/HR/Services/EmployeeTrainingService.php:39-75`). The HTTP layer only checks the broad manage permission (`api/app/Modules/HR/Requests/CompleteEmployeeTrainingRequest.php:9-21`; `EmployeeTrainingController.php:50-67`). A cancelled or expired record can be completed again, and a completed record can be cancelled while retaining completion/expiry fields.

The duplicate guard is also a read-then-insert check without `lockForUpdate()` or an equivalent conflict mapping (`EmployeeTrainingService.php:78-99`). The database uniqueness key includes nullable `scheduled_for` (`api/database/migrations/0191_create_employee_trainings_table.php:17-31`), so PostgreSQL permits multiple null-date rows; same-date races can surface as an unhandled uniqueness error. The current tests cover sequential happy paths and one sequential duplicate only (`api/tests/Feature/HR/EmployeeTrainingAssignTest.php:60-113`).

Action: define and enforce the allowed transition graph, reload and lock the row inside each transition transaction, use an expected-state predicate, make assignment idempotency explicit for nullable dates, map unique conflicts to validation, and add two-connection PostgreSQL race tests.

### M017-F03 — Broken/high: the employee detail “Assign skill” flow always misses a required field

Priority: **P1**  
Category: Broken  
Scope: **small**  
Recommendation: **separate-recommended**

The API requires `acquired_date` when assigning a skill (`api/app/Modules/HR/Requests/AssignEmployeeSkillRequest.php:18-27`). The employee detail modal’s form type contains only `skill_id` and `proficiency_level`, and its mutation forwards exactly that object (`spa/src/pages/hr/employees/detail.tsx:1137-1144`). The API client exposes the same incomplete payload shape (`spa/src/api/hr/employee-skills.ts:5-9`).

Selecting a skill and proficiency in the normal SPA workflow submits a request that should receive 422; the modal has no acquired-date input, expiry input, or field-level API error display. The available API suite has no employee-skill assignment feature test, so this contract break is not caught.

Action: align the client type/form with the request contract, provide acquired/expiry inputs or an explicit documented default, surface validation errors, and add a browser/API contract test for a successful assignment.

### M017-F04 — Incomplete/high: certification evidence is represented as an unchecked path, not a managed document

Priority: **P1**  
Category: Incomplete  
Scope: **medium**  
Recommendation: **separate-recommended**

Training completion and skill assignment accept `certificate_path` / `certification_document_path` as arbitrary strings (`api/app/Modules/HR/Requests/CompleteEmployeeTrainingRequest.php:16-21`; `AssignEmployeeSkillRequest.php:18-27`; `UpdateEmployeeSkillRequest.php:18-27`). The services persist those strings directly (`EmployeeTrainingService.php:52-59`; `EmployeeSkillService.php:34-45,52-60`), and both resources return the raw path (`EmployeeTrainingResource.php:26-35`; `EmployeeSkillResource.php:24-35`). There is no M017 upload, private-download, MIME/size validation, or authorized certificate route in `api/app/Modules/HR/routes.php:158-224`.

The module can display a compliance-looking certificate field without proving that a file exists, belongs to the record, is private, or can be retrieved. An internal storage path is also unnecessarily exposed to API consumers. This is insufficient for a certification evidence workflow.

Action: use the private document-storage pattern already used by employee documents, persist file metadata and ownership, expose an authorized download endpoint (or signed short-lived URL), and test upload, replacement, missing-file, and cross-employee access behavior.

### M017-F05 — Broken/medium: revoked employee skills cannot be safely re-assigned

Priority: **P1**  
Category: Broken  
Scope: **small-to-medium**  
Recommendation: **separate-recommended**

`employee_skills` has a permanent unique `(employee_id, skill_id)` constraint (`api/database/migrations/0232_create_skills_tables.php:22-35`), while migration 0444 adds soft deletes to that table (`api/database/migrations/0444_add_soft_deletes_to_all_tables.php:11-20`) and `EmployeeSkill` uses `SoftDeletes` (`api/app/Modules/HR/Models/EmployeeSkill.php:15-35`). `assertNoDuplicate()` uses the default query, so it does not see a trashed row; `revoke()` soft-deletes it, and a later assignment reaches the database unique constraint (`api/app/Modules/HR/Services/EmployeeSkillService.php:64-67,176-187`).

The ordinary “remove old skill, assign renewed skill” workflow can therefore fail with a database exception. The restore endpoint exists, but the SPA has no restore/reassignment recovery path and the focused tests do not exercise either branch.

Action: choose one lifecycle policy: restore/update the existing row, or replace the unique key with an active-row partial unique index and handle restore conflicts. Add API coverage for revoke → reassign and restore.

### M017-F06 — Broken/high: expiry alerts can be marked delivered when no notification was persisted

Priority: **P1**  
Category: Broken  
Scope: **medium**  
Recommendation: **separate-recommended**

The expiry loop calls `notify()` and then unconditionally writes `last_alert_level` and increments `alerts_sent` (`api/app/Modules/HR/Services/TrainingExpiryService.php:80-95`). `notify()` returns early when there are no recipients (`:119-124`), and `NotificationService` can also produce no inbox/email rows when the normalized recipient set is empty or all channels are disabled (`api/app/Common/Services/NotificationService.php:89-93,138-140`). The scheduler’s `withoutOverlapping()` protects the scheduled command process, not manual/parallel invocations (`api/routes/console.php:266-272`).

An expiry can consequently be marked at T30/T14/T7/expired even though nobody received a durable notification, and two concurrent checks can both observe the old marker, send duplicate alerts, and overwrite the same record. The tests prove sequential same-day idempotency but not delivery failure or concurrency (`api/tests/Feature/HR/TrainingExpiryAlertTest.php:87-100`).

Action: make alert claiming and delivery state explicit, persist a durable notification/outbox result before advancing the marker, do not count empty delivery as sent, and use row locking or a unique alert key for concurrent checks. Add recipient-empty, channel-opt-out, retry, and two-run tests.

### M017-F07 — Incomplete/medium: secondary skills matrix APIs disagree with the primary matrix contract

Priority: **P1**  
Category: Incomplete  
Scope: **medium**  
Recommendation: **separate-recommended**

The primary `/hr/training/matrix` endpoint explicitly decodes a department hash ID and compares dates at the start of day (`api/app/Modules/HR/Controllers/TrainingMatrixController.php:25-45,59-69`). The separate `/hr/skills/matrix` endpoint passes `$request->integer('department_id')` directly to the service even though the SPA and the rest of the HR API use hash IDs (`api/app/Modules/HR/Controllers/EmployeeSkillController.php:55-64`; `spa/src/api/hr/training-matrix.ts:4-10`). The two implementations also disagree on a skill expiring today: the secondary service uses `isPast()`, while the primary matrix compares against `startOfDay()` (`api/app/Modules/HR/Services/EmployeeSkillService.php:94-114`; `TrainingMatrixController.php:45,63-69`).

The gap endpoint treats any employee-skill row as coverage, including an expired row (`EmployeeSkillService.php:139-150`), while the primary matrix represents expired coverage separately. No SPA caller or focused API test covers the secondary matrix/gap routes; the UI uses only `/hr/training/matrix` (`spa/src/routes/hrRoutes.tsx:189-209`). This leaves a dormant API surface with contradictory operational answers.

Action: select one canonical matrix contract, decode/validate hash filters consistently, define whether expired skills count as gaps, remove or wire the secondary endpoints, and test the same record through every supported matrix path.

### M017-F08 — Incomplete/high: matrix and gap queries are unbounded in both rows and Cartesian work

Priority: **P1**  
Category: Incomplete  
Scope: **medium-to-large**  
Recommendation: **separate-recommended**

Both matrix implementations load all active employees and all active skills with `get()`, then build employee × skill results in PHP (`api/app/Modules/HR/Controllers/TrainingMatrixController.php:32-43,51-80`; `api/app/Modules/HR/Services/EmployeeSkillService.php:74-131`). Gap analysis loads every active employee after a full `pluck()` of assigned IDs (`EmployeeSkillService.php:139-161`). There is no request validation, page/limit, export job, or dataset-size guard on these endpoints (`api/app/Modules/HR/routes.php:168-170,189-195`).

The current tests use tiny fixtures, so they verify shape but not production latency or memory. A larger workforce or skills catalog can make the matrix request time out or exhaust PHP memory, and the UI has no fallback for a partially completed large response.

Action: define bounded pagination/filtering or a deliberately asynchronous export, push status/coverage aggregation into SQL where practical, cap cell counts, and add a production-sized performance regression.

### M017-F09 — Incomplete/polish: archive and form contracts are not fully discoverable or aligned

Priority: **P2**  
Category: Polish  
Scope: **small-to-medium**  
Recommendation: **same-session only after hardening**

The training list correctly sends `trashed`, and the restore route uses `withTrashed()`, but the show route does not (`api/app/Modules/HR/routes.php:178-186`). The SPA still renders an Edit action for archived rows (`spa/src/pages/hr/training/list.tsx:84-93`), so clicking Edit from the archived view calls an active-only show binding and reaches a 404. The form accepts `validity_months` down to zero (`spa/src/pages/hr/training/form.tsx:27-33,117-123`), while both API requests require a present value to be at least one (`api/app/Modules/HR/Requests/StoreTrainingRequest.php:27-36`; `UpdateTrainingRequest.php:27-36`).

The Skills catalog route exists but is not present in the sidebar’s HR navigation (`spa/src/routes/hrRoutes.tsx:205-209`; `spa/src/components/layout/Sidebar.tsx:600-614`). The employee training completion icon also has no visible text/aria label in the detail action (`spa/src/pages/hr/employees/detail.tsx:944-957`). These are not the primary release blockers, but they make supported operations harder to discover and less accessible.

Action: disable or redirect archived edit, align validity validation, add Skills to the intended navigation, label icon-only actions, confirm destructive skill removal, and add a browser pass for archive/restore, keyboard focus, and validation errors.

## Missing evidence / open questions

- No two-connection PostgreSQL race test was available for assignment, lifecycle transitions, or expiry alerts.
- No authenticated Playwright journey was available for the employee detail tabs, skill assignment, matrix filtering, archive/restore, or department-head access.
- No production-sized matrix benchmark or live queue/notification delivery rehearsal was available.
- M024 owns the employee self-service SPA handoff; the backend read endpoint is tested, but no current SPA consumer was found for `/hr/self-service/trainings` (`api/app/Modules/HR/routes.php:240-264`; `SelfServiceController.php:513-531`).

## Audit-session decision

No implementation fix is applied in this session. The findings are not predominantly small: the highest-value work crosses authorization scope, state-machine serialization, document storage, notification delivery, query scaling, and a separate self-service handoff. A same-session patch would leave the P1 controls unresolved.
