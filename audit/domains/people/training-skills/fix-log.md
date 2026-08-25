# M017 — People / Training & Skills fix log

Audit date: 2026-08-24  
Implementation session: 2026-08-25  
Module status: 🔁 Needs Re-audit

## Session record

- Refreshed the registry and atomically claimed `people / training-skills` as the first unlocked `📋 Plan Ready` module. Per the session protocol, resumed the existing report and action plan instead of repeating discovery.
- Used the repository source-scan guidance to trace the cross-stack M017 surfaces: HR routes/controllers/services/models/migrations, permissions and row scope, notifications, SPA APIs/types/pages, and focused tests.

## Production changes

### F01 — Row-level authorization

- `api/app/Modules/HR/Support/EmployeeCompetenceScope.php:30-68` centralizes the department-plus-self boundary for employee, training, and skill rows using `DepartmentScope` and the acting user.
- `api/app/Modules/HR/Controllers/EmployeeTrainingController.php:29-46,59-87` and `EmployeeSkillController.php:31-45,49-67,110-118` apply the boundary to direct reads, writes, and certificate downloads.
- `api/app/Modules/HR/Controllers/TrainingMatrixController.php:32-37` and `EmployeeSkillService.php:211-216,309-312` scope both matrix/gap surfaces.
- `api/tests/Feature/HR/EmployeeTrainingAssignTest.php:139-171` covers a department head attempting direct cross-department training and skill reads.

### F02 — Lifecycle and assignment concurrency

- `api/app/Modules/HR/Support/EmployeeTrainingStateMachine.php:15-49` defines the legal `TRANSITIONS` table and rejects illegal current-state changes with `BusinessRuleException`.
- `api/app/Modules/HR/Services/EmployeeTrainingService.php:38-68,82-125,136-151` reloads and locks authoritative rows inside transactions for assignment, completion, and cancellation.
- `api/database/migrations/2026_08_25_233000_harden_training_skills_lifecycle.php:54-93` adds a non-null assignment key, backfills scheduled/unscheduled values, and replaces the nullable-date uniqueness key with a race-safe unique key.
- `api/app/Modules/HR/Services/EmployeeTrainingService.php:156-186` checks open assignments under lock and maps the database unique conflict to validation.
- `api/tests/Feature/HR/EmployeeTrainingAssignTest.php:93-138` covers unscheduled duplicates and invalid recompletion/cancellation. A two-connection PostgreSQL race test remains deferred to re-audit because the repository-wide migration chain did not reach the test suite.

### F03 — SPA/API skill assignment contract

- `api/app/Modules/HR/Requests/AssignEmployeeSkillRequest.php:18-29` and `UpdateEmployeeSkillRequest.php:18-29` validate required acquired dates and certificate uploads.
- `spa/src/types/hr.ts:307-334` and `spa/src/api/hr/employee-skills.ts:5-31` carry acquired/expiry dates and multipart evidence correctly.
- `spa/src/pages/hr/employees/detail.tsx:1202-1304` adds required proficiency/acquired-date fields, optional expiry/evidence, and field-level server validation feedback.

### F04 — Private certification evidence

- `api/app/Modules/HR/Services/TrainingEvidenceService.php:13-52` stores evidence on the private local disk, deletes replaced files after commit, and authorizes missing-file handling at download time.
- `api/database/migrations/2026_08_25_233000_harden_training_skills_lifecycle.php:73-79,101-106` adds evidence metadata and uploader ownership to training/skill records.
- `api/app/Modules/HR/Controllers/EmployeeTrainingController.php:79-89`, `EmployeeSkillController.php:110-120`, `routes.php`, and the two resource classes expose named authorized downloads and metadata without raw storage paths.
- `spa/src/pages/hr/employees/detail.tsx:863-1027,1295-1304` provides certificate upload controls for completion and skill assignment.
- `api/tests/Feature/HR/EmployeeTrainingAssignTest.php:218-251` verifies private storage, metadata-only response, and authorized download. Cross-employee download and replacement coverage should be added during re-audit.

### F05 — Revoked skill reassignment

- `api/app/Modules/HR/Services/EmployeeSkillService.php:41-109` locks the employee/skill row, restores a soft-deleted assignment, and updates it instead of colliding with the permanent uniqueness key.
- `api/app/Modules/HR/Services/EmployeeSkillService.php:168-198` keeps revoke/restore transactional and scope-checked.
- `api/tests/Feature/HR/EmployeeTrainingAssignTest.php:173-216` covers revoke → reassign and verifies the original row is restored.

### F06 — Expiry alert delivery and idempotency

- `api/app/Modules/HR/Services/TrainingExpiryService.php:66-113` locks each training row, refuses to advance markers without an eligible delivery target, and transitions expiry through the state machine.
- `api/app/Modules/HR/Services/TrainingExpiryService.php:160-198,200-237` persists per-recipient/channel claims and honors notification preferences before sending.
- `api/database/migrations/2026_08_25_233000_harden_training_skills_lifecycle.php:110-132` and `api/app/Modules/HR/Models/TrainingExpiryAlertDelivery.php:9-41` add the durable unique delivery target.
- `api/tests/Feature/HR/TrainingExpiryAlertTest.php:136-210` covers empty recipients, disabled channels, and persisted delivery claims. A two-process delivery race and downstream email-provider acknowledgement remain deferred.

### F07/F08 — Canonical, bounded matrix and gap APIs

- `api/app/Modules/HR/Controllers/TrainingMatrixController.php:25-127` and `EmployeeSkillController.php:71-108` decode hash filters, cap dimensions at 100, and return truncation metadata.
- `api/app/Modules/HR/Services/EmployeeSkillService.php:200-296` aligns expiry-today semantics with the primary matrix and bounds the secondary matrix/gap responses.
- `spa/src/pages/hr/training/matrix.tsx:128-134` warns users when the safe dimension cap truncates the result.
- `api/tests/Feature/HR/TrainingMatrixTest.php:221-284` covers expiry-today/gap semantics and dimension-cap metadata.

### F09 — UI polish and discoverability

- `spa/src/pages/hr/training/list.tsx:84-93,127-131` disables archived-row editing and navigation.
- `spa/src/pages/hr/training/form.tsx:31,119` aligns validity validation with the API minimum.
- `spa/src/components/layout/Sidebar.tsx:667` adds Skills navigation.
- `spa/src/pages/hr/employees/detail.tsx:944-958,1179-1200` labels the completion icon and confirms destructive skill removal.
- `spa/src/pages/hr/skills/index.tsx:83-84` labels the deactivate icon-only action.
- `M024` self-service SPA consumption remains intentionally untouched; the existing action plan identifies that handoff as M024-owned.

## Verification performed

| Check | Result | Notes |
|---|---|---|
| PHP syntax | PASS | All HR PHP files, M017 migration, and focused HR tests lint successfully. |
| Diff check | PASS | `git diff --check` clean for scoped source, SPA, and audit paths. |
| Route registration | PASS | Employee training/skill subresources, certificate downloads, matrices, and gap routes registered. |
| Targeted SPA ESLint | PASS | M017 pages, APIs, types, and sidebar pass with zero reported errors. |
| Full SPA typecheck | BLOCKED | Pre-existing dirty-worktree errors remain in `calendar/index.tsx`, `assets/detail.tsx`, and `return-management/detail.tsx`; none are M017 files. |
| Compose focused API suite | BLOCKED | Isolated `ogami_m017_test` migration stops in pre-existing `2026_08_25_210000_enforce_one_active_holiday_per_date.php` when PostgreSQL refuses to drop an index backing a constraint. The shared test DB was not reset or altered. |
| Browser smoke | NOT RUN | No authenticated browser harness was available. |
| Two-connection/performance/provider checks | DEFERRED | Requires a clean migration chain, race harness, production-sized dataset, and live notification delivery. |

## Deferred items and release decision

- Keep M017 at `🔁 Needs Re-audit`: the implementation addresses the planned findings, but the Compose-backed release gate and concurrency/browser checks could not be completed because of the repository-wide migration/typecheck blockers.
- Re-audit should add cross-employee evidence-download and replacement tests, two-connection assignment/lifecycle/alert tests, an authenticated browser journey, and a production-sized matrix benchmark.
- Do not modify the M024 self-service consumer from this module; hand off the scoped backend contract to M024.
