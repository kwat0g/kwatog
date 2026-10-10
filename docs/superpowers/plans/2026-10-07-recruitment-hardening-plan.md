# Recruitment Hardening Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to execute task-by-task with review checkpoints. Delegation is not required or authorized by this document. Steps use checkbox syntax. This is an implementation handoff; the current request authorizes planning, not application edits, commits, or deployment.

**Goal:** Close every recruitment audit gap and deliver a reliable, accessible candidate-to-employee process.

**Architecture:** Keep shared recruitment invariants in the existing HR service and state machines. Reuse approvals, confidential document storage/PDF rendering, immutable application events, outbox delivery, calendar aggregation, settings, and established React controls. Add only the offer, candidate-recovery, privacy-cleanup, and malware-scanning boundaries the missing behavior requires.

**Tech Stack:** Laravel/PHP, PostgreSQL 16, Redis/queues, React/TypeScript, React Query, React Hook Form/Zod, Vitest/Testing Library, Playwright. ClamAV is the only planned new infrastructure dependency; no new application library is needed.

**Spec:** [Recruitment hardening design](../specs/2026-10-07-recruitment-hardening-design.md).

## Global Constraints

- Preserve unrelated worktree changes and existing form edits.
- No new Composer/browser library; private ClamAV is the only new infrastructure dependency.
- Hired reserves headcount; approval and acceptance evidence gate employee conversion.
- Retention review occurs after 12 calendar months; no automatic deletion or legal-hold bypass.
- Business-timezone date-only deadlines are inclusive; current configured default is `Asia/Manila`.
- Do not fabricate historical evidence/company identity, expose private candidate data to approvers, or bypass compensation controls.
- Reuse existing workflows, audit events, outbox, vault, calendar, settings, and UI patterns.
- Use additive migrations and isolated synthetic test data; never reseed over operator-owned settings.
- No commits are required by this planning request; a later authorized commit contains only reviewed task changes.

## How to execute

Each numbered task is a small, independently reviewable change set, not a promise that every task fits one sitting. Follow its checkboxes: add the named failing tests, run the focused command, implement only its contract, then rerun and inspect the scoped diff. The listed files are responsible seams, not permission to overwrite existing edits. Split a task further if its first implementation slice cannot be verified independently.

Every task's final gate is its listed **Run** command with passing output and a scoped diff review. The final test-and-review step is required even where its checkbox names a more specific verification. If an authorized execution session requests commits, commit only that reviewed task; never blanket-stage the dirty worktree.

Test snippets below are concrete regression seeds, not the complete suite. Snippets using `$this->hrUser`, `$this->posting`, or `$this->application` belong inside the existing `RecruitmentApplicationTest` class, which already defines those fixtures. Public snippets belong inside `PublicRecruitmentTest`. New suites use `Tests\TestCase`, existing factory/seed setup, and direct model creation following those suites; do not introduce a repository-wide test-fixture framework. API paths below are relative to `/api/v1`; identifiers are hash IDs externally, integer foreign keys internally.

Use the following isolated PHP test database. The audit's temporary database was removed; do not assume it still exists. First inspect whether this exact new database exists; create only when absent:

```bash
docker exec ogami-db psql -U ogami -d postgres -Atc "SELECT datname FROM pg_database WHERE datname = 'ogami_test_recruitment_plan_20261007'"
docker exec ogami-db createdb -U ogami ogami_test_recruitment_plan_20261007
docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=Recruitment
```

Do not run the `createdb` command when the inspection returns the database name. `api/tests/phpunit-bootstrap.php` accepts the `ogami_test_*` override. Tests must not point at `ogami`, a shared browser database, or a production database. Concurrency tests use committed fixtures and distinct PostgreSQL connections/processes, not a surrounding `RefreshDatabase` transaction. Keep database/schema/file/cache/queue isolation for real browser tests; Task 22 supplies that harness.

Frontend commands run in the existing SPA container:

```bash
docker exec ogami-spa npm run test:run -- src/pages/hr/recruitment src/pages/careers
docker exec ogami-spa npm run build
docker exec ogami-spa npm run audit:tokens
```

## Delivery order

| Phase | Tasks | Exit condition |
|---|---|---|
| 1. Safety and reliable truth | 01–07 | Ownership, concurrency, slots, deadlines, feature availability, permissions, and totals are safe |
| 2. Complete recruiter workflow | 08–11 | Interviews, terminal correction, ownership, overdue work, and communication are explicit |
| 3. Offers and onboarding | 12–14 | Approved, accepted terms gate hiring and atomic employee handoff |
| 4. Candidate recovery and data safety | 15–18 | Submission recovery, strong capabilities, scanning, and controlled retention work |
| 5. Process/UI polish | 19–21 | Candidate and recruiter interfaces meet accessibility and recovery requirements |
| 6. Integrated proof and rollout | 22–23 | Real journeys, migrations, security checks, runbooks, and staged rollout are verified |

Critical fixes in Phase 1 do not wait for the offer or redesign work. Server and client changes that alter a contract ship together after an additive/dual-compatible preparation release. Do not leave an unsafe legacy implicit-advance path active indefinitely.

The existing-label/error/target-size fixes within Task 19 can ship immediately after their own tests, independent of later publication/recovery fields. Treat those accessibility defects as safety work, not a redesign dependency. Do not defer visible server errors until every new workflow is built.

## Shared contracts

### Schema ownership

Create only these additive recruitment migrations, with names checked for collision before execution:

| Migration suffix (prefix `2026_10_07_`) | Contents | Owner |
|---|---|---|
| `120000_add_recruitment_concurrency_columns.php` | `lock_version` default 1 on postings, applications, interviews; nullable unique converted-employee guard after duplicate preflight | 02–03 |
| `120100_add_recruitment_workflow_fields.php` | Application owner/next action/due/stage entry/provenance/closed time/reason; interview status/completion/duration/mode/instructions/reason; checks for new lifecycle values | 08–10 |
| `120200_create_recruitment_notices_table.php` | Encrypted candidate-safe snapshot, scalar references, status, attempt timestamps, redacted error code, unique decision dedupe key | 11 |
| `120300_create_recruitment_offers_table.php` | Offer versions/terms/approval linkage/documents/response evidence; application's nullable current-offer pointer and captured legacy-review eligibility | 12–14 |
| `120400_add_recruitment_candidate_recovery_fields.php` | Tracking column widened to 64 and nullable for revocation; submission key/fingerprint; one-time recovery tokens and checksum | 15–16 |
| `120500_add_recruitment_resume_scan_fields.php` | Scan state/result/time/checksum; replacement upload version | 17 |
| `120600_add_recruitment_privacy_review_fields.php` | Consent version, review/hold/deletion state, nullable candidate PII, redacted cleanup ledger | 18 |
| `120700_add_recruitment_publication_fields.php` | Work arrangement/location, explicit salary currency/pay period, dedicated careers copy setting | 19 |
| `120800_seed_recruitment_policy_defaults.php` | Missing permissions/workflow/settings only; no replacement of operator values | 06, 12, 18, 23 |

All new indexes have deterministic short names. PostgreSQL check constraints are preflighted and introduced without rewriting unknown values. Reference existing lifecycle-guard migrations instead of editing migrations already applied. Avoid a new generic migration helper solely for these tables.

Schema details shared by later tasks:

- `lock_version` is an integer starting at 1; use atomic increments only inside the responsible lock. `stage_time_source` accepts `recorded`, `event`, or `estimated`. `owner_user_id` is nullable; `next_action` is nullable text limited to 500 characters at validation.
- `recruitment_offers` has `job_application_id`, integer `version`, integer `lock_version`, lifecycle `status`, `evidence_mode` (`normal` or `legacy_review`), nullable `requested_by`, typed terms, approval timestamps, `document_id`, response timestamps/channel/reference, and private evidence reference. `(job_application_id, version)` is unique. Salary is `decimal(12,2)` serialized as a string; currency is a validated three-letter currency supported by current payroll. Pay type is the existing `monthly` or `semi_monthly`. Approval amount stays an exact decimal string. `current_offer_id` points only to that application's offer, checked under its lock.
- `requires_legacy_offer_review` is set only for already-hired, unconverted applications when offer controls are introduced. The live API cannot set it. A legacy review never clears the requirement without current real approval.
- `recruitment_notices` has `job_application_id`, optional interview/offer reference, `notice_type`, unique `dedupe_key`, encrypted `snapshot`, `status` (`pending`, `sending`, `sent`, `failed`, `suppressed`), `lock_version`, `attempts`, lease timestamp, transport acceptance time, and redacted error code. Snapshot contains destination/candidate-safe facts; outbox contains only notice ID. Use existing framework encrypted casts and application key, not new encryption code.
- Submission key is a UUID nullable for historical rows; `(job_posting_id, submission_key)` is unique. Fingerprint is a keyed HMAC of canonical normalized input plus file checksum. Candidate recovery tokens have unique SHA-256 token hash, application reference, expiry and consumed timestamps; token plaintext appears only in the verified email link. Cleanup removes tokens and fingerprints.
- Resume replacement has an integer upload version and separately referenced pending object/checksum/state; the active clean object is not overwritten by a pending replacement. The scanner and replacement paths share one service.
- Privacy states are `not_requested`, `queued_review`, `held`, `approved`, `cleaning`, `failed`, or `complete`, with review/hold/approval/cleanup timestamps and redacted reason references. PII becomes nullable; existing application events remain immutable. The deletion ledger contains stable opaque application identity, approval actor/time, blob cleanup references and status, not candidate contact data.

### Responsible baseline paths

Existing PHP files are under `api/app/Modules/HR` unless otherwise stated: `Services/RecruitmentService.php`; `Controllers/RecruitmentApplicationController.php`, `RecruitmentPostingController.php`, `PublicRecruitmentController.php`, `EmployeeController.php`; `Requests/AdvanceApplicationRequest.php`, `StoreInterviewRequest.php`, `StoreJobPostingRequest.php`, `UpdateJobPostingRequest.php`, `PublicApplicationRequest.php`, `StoreEmployeeRequest.php`; `Resources/JobApplicationResource.php`, `JobPostingResource.php`, `PublicJobPostingResource.php`, `ApplicationInterviewResource.php`; `Models/JobApplication.php`, `JobPosting.php`, `ApplicationInterview.php`; `Support/RecruitmentApplicationStateMachine.php`, `RecruitmentPostingStateMachine.php`, `RecruitmentNotificationRecipients.php`; and `routes.php`. Those paths own the current request, invariant, serialization, and permission boundaries; do not duplicate them in a second module.

Existing frontend pages are `spa/src/pages/hr/recruitment/index.tsx`, `postings/index.tsx`, `postings/create.tsx`, `postings/edit.tsx`, `postings/detail.tsx`, `applications/index.tsx`, and `applications/detail.tsx`. Shared recruitment contracts are `spa/src/api/recruitment.ts`, `spa/src/api/public-recruitment.ts`, and `spa/src/types/recruitment.ts`. Candidate pages are `spa/src/pages/careers/index.tsx`, `detail.tsx`, and `track.tsx`. Settings warning belongs in `spa/src/pages/admin/settings.tsx`. Shared UI controls remain in `spa/src/components/ui`; tokens are in `spa/src/styles/tokens.css`.

### API facts and actions

Application resources add `lock_version`, `stage_entered_at`, `stage_time_source` (`event`, `recorded`, `estimated`), `closed_at`, `closure_reason`, owner, next action/due, `available_actions`, and communication/offer summaries. Posting resources add `lock_version`, `occupied_slots`, `available_slots`, `is_expired`, `is_accepting`, prerequisite health, and actions. Interview resources add their version, lifecycle status, completion evidence, duration/mode/instructions, and candidate-safe schedule data.

`available_actions` is a list of stable keys: `advance_screening`, `schedule_interview`, `advance_offer`, `reject`, `withdraw`, `reopen`, `correct_interview`, `prepare_offer`, `send_offer`, `record_offer_response`, `mark_hired`, `rescind_hire`, `convert_employee`, `assign_owner`, `set_next_action`, `download_resume`, and `retry_notice`, as applicable. Posting actions are `edit`, `publish`, `close`, `reopen`, `archive`, and `restore`. Never expose a key merely because the actor has a broad permission; prerequisites and current state also matter.

The stage endpoint remains `PATCH /hr/recruitment/applications/{application}/stage`:

```json
{
  "action": "advance",
  "expected_stage": "screening",
  "to_stage": "interview",
  "expected_version": 4,
  "interview": {
    "scheduled_at": "2026-10-09T09:00:00+08:00",
    "interviewer_name": "Assigned interviewer",
    "meeting_mode": "onsite",
    "duration_minutes": 30,
    "location": "Configured interview location"
  }
}
```

Allowed actions are `advance`, `reject`, `withdraw`, `reopen`, and `rescind_hire`, with explicit expected/target state/version. Reasons are required for terminal/correction actions and trimmed before validation. Preserve `rejection_reason` for reject compatibility; use `reason` for new actions. The service receives typed expected/target enums and actor, not a raw request object. Errors use stable `code` plus human-readable `message`; `409 stale_recruitment_state` includes current stage/version.

Additional interfaces:

| Endpoint | Body/query | Authorization and result |
|---|---|---|
| `GET /hr/recruitment/summary` | Posting, department, owner, applied-date filters | Recruitment view; exact aggregates, distributions, cohort metrics and provenance counts |
| `PATCH /hr/recruitment/applications/{application}/assignment` | Owner hash ID/null, next action/null, due/null, expected version | View + applications; updated application |
| `PATCH /hr/recruitment/interviews/{interview}` | Action `reschedule`, `complete`, `cancel`, `no_show`, or `correct`; expected version; action-specific evidence | Applications or correction permission; updated interview and parent version |
| `POST /hr/recruitment/applications/{application}/offers` | Validated terms, expected application version | Applications; creates draft version |
| `PATCH /hr/recruitment/offers/{offer}/act` | Action `submit`, `approve`, `reject_approval`, `send`, `accept`, `decline`, or `cancel`; expected version; evidence/reason | Action-specific permission and saved approval scope |
| `GET /hr/recruitment/offers/{offer}` | None | HR or saved approver scope; role-appropriate redacted resource |
| `GET /hr/recruitment/offers/{offer}/document` | None | Same scoped access; confidential attachment |
| `POST /hr/recruitment/applications/{application}/legacy-offer-evidence` | Actual historic terms/delivery/response evidence, reason, expected version | Hire + correction, migration-marked unconverted hires only; creates review version requiring current maker-checker approval |
| `POST /hr/recruitment/notices/{notice}/retry` | Expected notice status/version | View + applications; requeues safe undelivered notice |
| `POST /public/recruitment/job-postings/{posting}/apply` | Existing form, UUID `Idempotency-Key` header | Anonymous, rate limited; `201` first receipt, `200` exact replay |
| `POST /public/recruitment/applications/track` | Tracking code | Capability; candidate-safe response, no-store |
| `POST /public/recruitment/applications/recover` | Posting hash ID and email | Always generic `202`, independently rate limited |
| `POST /public/recruitment/applications/recover/consume` | One-time token | Token is hashed at rest; atomic rotation; fresh tracking capability |
| `POST /public/recruitment/applications/withdraw` | Tracking code, reason | Capability; explicit confirmation; idempotent terminal response |
| `POST /public/recruitment/applications/resume` | Tracking code and file; expected upload version | Capability; quarantined replacement; no premature removal of original |
| `POST /public/recruitment/applications/interview-calendar` | Tracking code | Capability; candidate-safe current scheduled interview `.ics`, no-store |
| `POST /public/recruitment/applications/privacy-request` | Tracking code, requested action/reason | Capability; generic acknowledgement; records review request |
| `GET /hr/recruitment/privacy-reviews` | Review state/date/hold filters | Privacy permission; paginated review queue |
| `PATCH /hr/recruitment/applications/{application}/privacy` | Action `hold`, `release_hold`, `approve_delete`, or `decline_delete`; reason; expected version | Privacy permission; queued cleanup, never synchronous destructive bulk deletion |

Default new grants are explicit: `hr.recruitment.correct` and `hr.recruitment.privacy` to `hr_officer` and `system_admin`; `hr.recruitment.offers.approve` to `production_manager`, `vice_president`, and `system_admin`. All remain subject to actor separation and record/workflow scope; administrative access is not a maker-checker bypass. Custom roles receive none automatically.

The default mutation signatures introduced in Task 02 are `advanceStage(JobApplication $application, ApplicationStage $expectedStage, ApplicationStage $toStage, int $expectedVersion, ?array $interviewData, User $actor): JobApplication` and `rejectApplication(JobApplication $application, ApplicationStage $expectedStage, int $expectedVersion, string $reason, User $actor): JobApplication`. All service callers must adopt them. Posting writes take `int $expectedVersion` before the actor. Assignment uses `assignApplication(JobApplication $application, array $assignment, int $expectedVersion, User $actor): JobApplication`. Summary and overdue helpers consume validated hash-ID filters resolved server-side, never raw SQL/request strings.

Normal successful application submission returns a capability receipt; an unkeyed duplicate returns generic `202` check-email guidance without `tracking_code`. The frontend handles that separate acknowledgement explicitly and never renders a false new receipt. Candidate receipt/recovery notices use the existing encrypted notice/outbox route. The fingerprint is a server-keyed HMAC, not a plaintext or readily reversible copy of candidate fields.

Focused new service contracts:

```php
// RecruitmentNoticeService
public function record(JobApplication $application, string $type, array $candidateSafeSnapshot, string $dedupeKey): RecruitmentNotice;
public function deliver(RecruitmentNotice $notice): void;
public function retry(RecruitmentNotice $notice, int $expectedVersion, User $actor): RecruitmentNotice;

// RecruitmentRecoveryService
public function request(string $postingHashId, string $email): void;
public function consume(string $plaintextToken): string; // Returns the rotated tracking capability.

// RecruitmentResumeService
public function stageReplacement(JobApplication $application, UploadedFile $file, int $expectedUploadVersion): JobApplication;
public function scan(JobApplication $application, int $uploadVersion): void;

// RecruitmentPrivacyService
public function queueDueReviews(): int;
public function approveDeletion(JobApplication $application, int $expectedVersion, User $actor, string $reason): JobApplication;
public function cleanApprovedApplication(JobApplication $application): void;
```

Imports resolve to the HR models created by their tasks, existing auth `User`, and `Illuminate\Http\UploadedFile`. Add `Models/RecruitmentNotice.php` in Task 11. Scalar `RecruitmentNoticeRequested` accepts only `int $noticeId`. Do not expose these internal methods as unvalidated public write APIs.

Narrow public self-service remains available when intake is disabled. Internal read-only wind-down resources must not offer write actions. Static routes precede bound identifier routes. Existing endpoint/envelope conventions apply; do not introduce a new API protocol.

## Phase 1 — Safety and reliable truth

### Task 01: Seal interview ownership and request boundaries

**Files:** Modify `api/app/Modules/HR/Requests/AdvanceApplicationRequest.php`, `Requests/StoreInterviewRequest.php`, `Controllers/RecruitmentApplicationController.php`, `Services/RecruitmentService.php`, and `api/tests/Feature/HR/RecruitmentApplicationTest.php`.

- [ ] Add `test_nested_interview_cannot_override_application_or_actor` and a direct-service boundary test. Reproduce the audit attack using another application plus forged `created_by`; assert `422`, no interview insertion, unchanged stage, and no mail/event.
- [ ] Run the focused test and confirm failure on the current implementation.
- [ ] Restrict nested array keys with Laravel's allowed-key validation. Controllers use only validated data. The service independently selects accepted interview fields, then assigns the real application and actor last; remove left-biased ownership union. Apply the same boundary to scheduling and updating.
- [ ] Rerun tests; inspect every `createInterviewRecord`, `scheduleInterview`, and `ApplicationInterview::create` caller. Preserve legitimate scheduling fields and existing actor/event behavior.

Regression core inside the existing class:

```php
$this->application->forceFill(['stage' => ApplicationStage::Screening])->save();
$this->actingAs($this->hrUser)->patchJson(
    "/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage",
    ['action' => 'advance', 'interview' => [
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'interviewer_name' => 'Interviewer',
        'job_application_id' => 999999,
        'created_by' => 999999,
    ]],
)->assertUnprocessable();
$this->assertDatabaseCount('application_interviews', 0);
$this->assertSame(ApplicationStage::Screening, $this->application->fresh()->stage);
```

After Task 02, add valid expected/target/version fields so the test still isolates ownership injection rather than passing due to missing concurrency fields.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=nested_interview_cannot_override`.

### Task 02: Require explicit, versioned decisions

**Depends on:** 01. **Files:** New concurrency migration; modify `Models/JobApplication.php`, `Models/JobPosting.php`, `Models/ApplicationInterview.php`, application/posting requests/controllers, `Services/RecruitmentService.php`, state machines, `spa/src/api/recruitment.ts`, `spa/src/types/recruitment.ts`, affected recruitment pages, and existing recruitment tests. Add `spa/src/pages/hr/recruitment/applications/detail.test.tsx`.

- [ ] Add `test_stale_advance_cannot_skip_offer_to_hired`, stale rejection/edit/status tests, and a UI test that a conflict refreshes without an automatic second mutation. Do not let the new test fail merely because offer prerequisites changed.
- [ ] Add version columns and typed decision arguments. Every writer updates versions under locks. Authorization uses requested target and locked state. Return `409` before side effects on stage/version mismatch; reject missing explicit fields once the new client is deployed.
- [ ] Update every direct service caller and all frontend mutation payloads. Show the intended target in confirmation copy. Disable duplicate submission locally, but do not treat the disabled button as concurrency protection.
- [ ] Verify old tabs receive an upgrade/refresh error, not implicit advancement. Add migration preservation/downgrade guard tests in `api/tests/Feature/HR/RecruitmentMigrationTest.php`.

Regression core, starting from an interview-stage application with verified interview evidence:

```php
$version = $this->application->fresh()->lock_version;
$url = "/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage";
$decision = ['action' => 'advance', 'expected_stage' => 'interview',
    'to_stage' => 'offer', 'expected_version' => $version];
$this->actingAs($this->hrUser)->patchJson($url, $decision)->assertOk();
$this->patchJson($url, $decision)->assertConflict()
    ->assertJsonPath('code', 'stale_recruitment_state');
$this->assertSame(ApplicationStage::Offer, $this->application->fresh()->stage);
```

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=stale_advance_cannot_skip` and the new detail Vitest suite.

### Task 03: Reserve headcount at Hired and serialize conversion

**Depends on:** 02. **Files:** `Services/RecruitmentService.php`, `Controllers/EmployeeController.php`, posting state machine/model/resource, `Requests/StoreEmployeeRequest.php`, concurrency migration, `api/tests/Feature/HR/RecruitmentApplicationTest.php`; new `api/tests/Feature/HR/RecruitmentConcurrencyTest.php`.

- [ ] Add `test_hired_reserves_final_slot_before_conversion`, `test_closed_posting_can_finish_hiring`, `test_slot_reduction_cannot_remove_occupied_capacity`, and `test_conversion_replay_returns_linked_employee` before changing behavior.
- [ ] Lock prerequisites as needed, then posting/application before checks and before employee creation. Count all hired applications, not converted-only applications. Make `open`/`closed` to `filled` legal only through capacity completion. Prevent overfill and unsafe slot reduction; expose occupied/available totals.
- [ ] Move the conversion existing-link check inside the transaction before `EmployeeService::create`. Reuse the existing employee/onboarding outbox behavior. Add the unique nullable employee link constraint after a read-only duplicate preflight.
- [ ] Prove concurrent final-slot requests produce one success and one conflict; concurrent conversion produces one employee and two responses referencing it. Use two real PostgreSQL connections/processes with a deterministic barrier; no sleep-based race test and no SQLite substitute.

Capacity regression assertion after hiring the first candidate for a one-slot posting:

```php
$this->assertDatabaseHas('job_postings', ['id' => $this->posting->id, 'status' => 'filled']);
$this->assertDatabaseHas('job_applications', [
    'id' => $this->application->id, 'stage' => 'hired', 'converted_employee_id' => null,
]);
```

Before Task 14, use the current legitimate offer-stage fixture. After Task 14, extend it with an accepted offer; do not disable the new hire guard in tests.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentConcurrencyTest|hired_reserves|closed_posting_can_finish|slot_reduction|conversion_replay'`.

### Task 04: Make prerequisites and publication dates safe

**Depends on:** 03. **Files:** Posting requests/service/model/resources, `api/app/Modules/HR/Services/DepartmentService.php`, `Services/PositionService.php`, `spa/src/pages/hr/recruitment/postings/create.tsx`, `edit.tsx`, `detail.tsx`; existing posting/public tests and new `RecruitmentMigrationTest.php`.

- [ ] Add tests for an active posting blocking archival, historical archived relations returning `200`, deleted/inactive department/position rejection, position/department mismatch, and inclusive end-of-day intake.
- [ ] Share prerequisite locks with department/position archival to close the create-versus-archive race. Load soft-deleted historical relations and null-check resources. Validate active prerequisites on create, publish, relationship edit, and conversion; return field-level recovery messages.
- [ ] Normalize new date-only deadlines to business-timezone end-of-day. Update frontend date conversion so it does not accidentally shift dates through UTC. Preserve explicit timestamps and report ambiguous legacy midnight values rather than rewriting them.
- [ ] Add midnight/day-end/next-day tests using the configured timezone; cover opening an expired posting and correcting prerequisites without destroying history.

Public regression core after setting a new date-only deadline through the posting API:

```php
$this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-09 17:00:00', 'Asia/Manila'));
$this->getJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}")->assertOk();
$this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-10 00:00:00', 'Asia/Manila'));
$this->getJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}")
    ->assertStatus(410);
```

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentPostingTest|PublicRecruitmentTest|RecruitmentMigrationTest'`.

### Task 05: Close intake consistently when the module is disabled

**Depends on:** 04. **Files:** `api/app/Modules/HR/routes.php`, public/recruitment controllers/service, `api/app/Modules/Admin/Controllers/SettingsController.php`, `spa/src/routes/hrRoutes.tsx`, recruitment pages and feature-setting warning UI; new `api/tests/Feature/HR/RecruitmentAvailabilityTest.php`.

- [ ] Add the full design availability matrix as data-driven tests: listings/detail/apply, tracking/recovery, read-only internal access, all writes, and employee conversion.
- [ ] Remove the recruitment feature gate from permission-gated internal reads only; retain `feature:hr`. Gate all recruitment writes and public intake using the existing setting/middleware semantics. Keep candidate tracking/recovery independently capability-authorized.
- [ ] Add disable warning with active/unconverted counts and wind-down banners. Read-only resources return no write actions. Do not alter unrelated feature middleware behavior globally.
- [ ] Prove disabled intake returns the documented reason, existing tracking still works, and there is no route/service bypass, including direct conversion through `/hr/employees`.

Required assertions after setting `modules.recruitment` false through `SettingsService`:

```php
$this->getJson('/api/v1/public/recruitment/job-postings')
    ->assertOk()->assertJsonCount(0, 'data');
$this->getJson("/api/v1/public/recruitment/job-postings/{$posting->hash_id}")
    ->assertStatus(410)->assertJsonPath('code', 'recruitment_unavailable');
```

`$posting` is the suite's explicitly created open posting. Exercise service-level writes too, not only HTTP middleware.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=RecruitmentAvailabilityTest`.

### Task 06: Align actions, backend permissions, and routes

**Depends on:** 02, 05. **Files:** Recruitment resources/requests/controllers/service; new `api/app/Modules/HR/Support/RecruitmentActions.php`; `api/database/seeders/RolePermissionSeeder.php`; policy-default migration; `spa/src/types/recruitment.ts`, recruitment detail pages, `spa/src/routes/hrRoutes.tsx`, employee create page; new `api/tests/Feature/HR/RecruitmentPermissionTest.php` and application detail Vitest tests.

- [ ] Add a permission/action matrix covering view-only, manage-only, applications-only, hire-only, employee-create-only, combined conversion access, correction, privacy, and offer approver. Include direct URLs and API calls, not just hidden buttons.
- [ ] A small `RecruitmentActions` helper computes keys using the same current-state/prerequisite methods the service guards use. Do not duplicate a second transition table. Expose blocked prerequisites as explanations where useful.
- [ ] Require hire plus employee-create for conversion, and employee-view before exposing an employee link usable by that actor. Make hire-only actions render even without application-management access, while unauthorized advancement/rejection stays absent.
- [ ] Add only explicit new correction/privacy/offer approval permissions with reviewed default grants. Existing roles must not inherit broad candidate visibility through the approval board or generic document vault. Validate new permission slugs in role editor options and route audit.

Action regression core:

```php
$response->assertJsonMissing(['available_actions' => ['mark_hired']]);
$this->assertNotContains('mark_hired', $response->json('data.available_actions'));
$this->assertNotContains('convert_employee', $response->json('data.available_actions'));
```

Use a legitimate offer-stage application and an applications-only actor; include a separate hire-only success case. Assert the backend rejects a forged action regardless of UI rendering.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=RecruitmentPermissionTest`; `docker exec ogami-spa npm run audit:rbac`.

### Task 07: Replace page-derived metrics with exact aggregates

**Depends on:** 03, 05. **Files:** New `api/app/Modules/HR/Controllers/RecruitmentSummaryController.php`; recruitment routes; resources/controller query seams; `spa/src/api/recruitment.ts`, `types/recruitment.ts`, recruitment dashboard; new `api/tests/Feature/HR/RecruitmentSummaryTest.php`, `spa/src/pages/hr/recruitment/index.test.tsx`.

- [ ] Create more records than the current 5-posting/10-application panels and assert totals include every matching record. Cover expired open postings, terminal applications, unconverted hires, and pagination/filter consistency.
- [ ] Implement database grouped counts with one consistent filter scope. Return `stage_counts`, `total_applications`, `active_applications`, `accepting_postings`, `occupied_slots`, `available_slots`, and `awaiting_conversion`; add overdue/cohort metrics after Tasks 10 and 14. Keep bounded recent lists separate.
- [ ] Remove `reduce`-over-current-page totals. Label distribution denominators and route each metric to the matching URL filters. A failed summary request renders retry/error, never zeros disguised as success.
- [ ] Eager-load bounded rows; test that query count does not grow per application. Do not add a dashboard cache until a measured query problem requires it.

Required assertion for a seeded 120-application cohort, with 20 in screening:

```php
$this->actingAs($viewer)->getJson('/api/v1/hr/recruitment/summary')
    ->assertOk()->assertJsonPath('data.total_applications', 120)
    ->assertJsonPath('data.stage_counts.screening', 20);
```

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=RecruitmentSummaryTest` and dashboard Vitest tests.

## Phase 2 — Complete recruiter workflow

### Task 08: Introduce evidence-based interview lifecycle

**Depends on:** 02, 04. **Files:** Workflow migration; new `api/app/Modules/HR/Enums/InterviewStatus.php`; interview model/request/resource; application controller/service; public tracking selector; application detail UI/API/types; existing application tests and new `api/tests/Feature/HR/RecruitmentInterviewLifecycleTest.php`.

- [ ] Add tests for future-outcome rejection, past scheduled interview completion, no-show, cancellation, rescheduling, terminal-parent scheduling/edit rejection, and rejection cancelling all future rounds.
- [ ] Add statuses and action-specific evidence/validation. Complete only credible nonfuture rounds; pending/null normalization is compatibility cleanup, not fabricated completion. Offer advancement requires verified passed evidence and no unresolved scheduled rounds.
- [ ] Lock posting/application before interview, increment both relevant versions, and record immutable redacted events. Terminal decisions cancel scheduled rounds atomically. Candidate tracking selects only eligible scheduled interviews; old/future irrelevant rows do not leak.
- [ ] Capture duration/mode/instructions/timezone. UI separates scheduling from recording outcomes and explains missing prerequisites. Candidate emails contain schedule changes only; private evaluation changes never trigger outcome emails.

Expected regression response:

```php
$response->assertUnprocessable()->assertJsonValidationErrors(['completed_at']);
$this->assertDatabaseHas('application_interviews', [
    'id' => $interview->id, 'status' => 'scheduled', 'outcome' => null,
]);
```

Create a future scheduled interview and attempt `action=complete` with a passed outcome. Also test that a later future round cannot hide an earlier missing outcome.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentInterviewLifecycleTest|RecruitmentApplicationTest'`.

### Task 09: Add withdrawal, rescission, and controlled correction

**Depends on:** 03, 06, 08. **Files:** Application enum/state machine/service/request/resources; workflow migration checks; application detail UI/types; existing state-machine tests and new `api/tests/Feature/HR/RecruitmentCorrectionTest.php`.

- [ ] Add state-table tests for withdrawal from non-hired stages, reasoned rejection/withdrawal reopening, denied direct jumps, unconverted-hire rescission, and converted-hire correction denial.
- [ ] Implement `withdrawn` and explicit closure reasons. Reopen only to screening/interview with correction permission and new prerequisites. Keep previous decisions/interviews visible; append events rather than editing immutable evidence.
- [ ] Rescind only an unconverted hire; release its occupied slot and move filled to closed when capacity exists. Never automatically reopen intake. Converted hires use employee separation, not recruitment rollback.
- [ ] Use `ReasonDialog` for reasons and clear consequences. Posting cancellation closes intake; closing individual candidates requires explicit reviewed decisions, not an implicit bulk rejection.

Required invariants:

```php
$this->assertSame('withdrawn', $application->fresh()->stage->value);
$this->assertSame('closed', $posting->fresh()->status->value);
$this->assertDatabaseHas('recruitment_application_events', [
    'job_application_id' => $application->id, 'event_type' => 'application.hire_rescinded',
]);
```

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentCorrectionTest|RecruitmentStateMachineTest'`.

### Task 10: Make ownership, stage age, and overdue work actionable

**Depends on:** 07–09. **Files:** Workflow migration/backfill command; application model/controller/request/resource/service; new `api/app/Modules/HR/Support/RecruitmentOverdueQuery.php`; `api/app/Console/Commands/CheckRecruitmentBottlenecks.php`; summary controller; API/types/application list/detail/dashboard; existing bottleneck tests and new `RecruitmentAssignmentTest.php`.

- [ ] Add tests that note edits do not reset stage age, pending/null past rounds are overdue, future rounds do not mask old unresolved rounds, explicit due dates win, and terminal applications disappear from active work queues.
- [ ] Implement owner assignment and next-action update with versions. Validate active capable users; expose unassigned/unavailable owner queues. Record exact stage entry on transitions and provenance on event-based/estimated backfills.
- [ ] Reuse one SQL overdue selector across list, summary, and command; preserve existing thresholds, deduplication, authorized recipients, and dry-run. Owner-specific notifications go only to currently authorized owners; fallback stays the existing recipient helper.
- [ ] Add posting/department/owner/unassigned/date/overdue URL filters and stable sorting. Show stage age and next action without treating estimated historic timestamps as exact. Add exact overdue counts to Task 07.

Regression core:

```php
$enteredAt = $application->stage_entered_at;
$this->actingAs($hr)->postJson(
    "/api/v1/hr/recruitment/applications/{$application->hash_id}/notes",
    ['body' => 'Follow-up recorded.'],
)->assertSuccessful();
$this->assertTrue($enteredAt->equalTo($application->fresh()->stage_entered_at));
```

Preserve the existing `body` notes payload and API contract.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentAssignmentTest|RecruitmentBottleneckCommandTest|RecruitmentSummaryTest'`.

### Task 11: Persist candidate-safe communication and retry evidence

**Depends on:** 08–10. **Files:** Notice migration/model; new `api/app/Modules/HR/Events/RecruitmentNoticeRequested.php`, `Listeners/SendRecruitmentNotice.php`, `Services/RecruitmentNoticeService.php`; `api/app/Common/Services/OutboxEventCodec.php`; listener registration using `api/app/Providers/ModuleServiceProvider.php` conventions; recruitment mails/templates/service/resource/routes; new `api/tests/Feature/HR/RecruitmentNotificationTest.php`.

- [ ] Add rollback-no-mail, lost-queue-publication recovery, mutable-interview snapshot, obsolete-notice suppression, private-outcome-no-mail, transport failure, and guarded retry tests.
- [ ] Create encrypted immutable candidate-safe notice snapshots transactionally. Outbox payload contains only the notice identifier; register the scalar event explicitly in its allowlist. The synchronous listener sends through the existing transport and marks transport acceptance, not queue acceptance.
- [ ] Suppress stale schedules/cancelled/terminal or privacy-revoked notices. Use captured schedule facts; do not serialize mutable Eloquent interview/application models into delayed candidate email. Reuse existing branding and delivery-failure notifications.
- [ ] Show pending/failed/sent/suppressed status and manual retry. Retry only eligible failed/pending messages; duplicate worker delivery respects notice state. Document the transport-acceptance crash window and privacy cleanup ownership of encrypted snapshots.

Required no-leak assertions:

```php
$this->assertStringNotContainsString('Private evaluation', $candidateMail->render());
$this->assertStringNotContainsString('candidate@example.com', json_encode($outbox->payload));
$this->assertSame('suppressed', $notice->fresh()->status);
```

Set the terminal state before dispatch for the last assertion; separately verify a legitimate live notice sends and records `sent`.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=RecruitmentNotificationTest`.

## Phase 3 — Offers and onboarding

### Task 12: Model versioned offers and reuse maker-checker approvals

**Depends on:** 06, 08–11. **Files:** Offer migration and policy-default migration; new `api/app/Modules/HR/Models/RecruitmentOffer.php`, `Enums/RecruitmentOfferStatus.php`, `Services/RecruitmentOfferService.php`, `Requests/StoreRecruitmentOfferRequest.php`, `Requests/ActOnRecruitmentOfferRequest.php`, `Controllers/RecruitmentOfferController.php`, `Resources/RecruitmentOfferResource.php`, `Policies/RecruitmentOfferAccessPolicy.php`; modify application model/routes, `api/database/seeders/WorkflowSeeder.php`, `RolePermissionSeeder.php`, `api/app/Common/Support/ApprovalTypeRegistry.php`, `ApprovalSourceScope.php`; new `api/tests/Feature/HR/RecruitmentOfferTest.php`.

- [ ] Add creation/versioning tests, submitter-cannot-approve, distinct-checker/approver tests, missing-workflow tests, amended-terms-reapproval tests, stale decisions, and approver-only redaction/row-scope tests.
- [ ] Implement offer versions and current-offer pointer under application locks. Validate decimal salary/currency/pay type, active prerequisites, proposed start date and expiry. Terms are editable only in draft; sent/accepted terms require a new version. Keep lifecycle status server-owned.
- [ ] Submit to a new `recruitment_offer` workflow using the existing production-manager/vice-president default chain and `ApprovalService`. Use existing approval snapshots/history and maker-checker controls. Add default workflow/permissions only when missing; retain operator changes.
- [ ] Register the offer in both the approval type registry and row scope. An approver can read only pending offers eligible under the saved chain and offers they participated in; HR recruitment-view holders follow normal HR access. The resource omits contact details, resume, private notes, and unrelated application metadata for approvers.

Concrete service contract:

```php
public function createDraft(JobApplication $application, array $terms, int $expectedVersion, User $actor): RecruitmentOffer;
public function submit(RecruitmentOffer $offer, int $expectedVersion, User $actor): RecruitmentOffer;
public function approve(RecruitmentOffer $offer, int $expectedVersion, User $actor, ?string $remarks = null): RecruitmentOffer;
public function rejectApproval(RecruitmentOffer $offer, int $expectedVersion, User $actor, string $reason): RecruitmentOffer;
```

Each definition lives in `RecruitmentOfferService`; no abstract offer provider/interface. All action paths lock prerequisites when needed, then posting/application/offer before approval rows.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentOfferTest|ApprovalBoardScopeTest|ApprovalBoardTest'`.

### Task 13: Generate/send offers and record actual candidate responses

**Depends on:** 11–12. **Files:** Offer service/controller/request/resource, new `api/resources/views/pdf/recruitment-offer.blade.php`, candidate-safe offer email template; `api/app/Common/Enums/DocumentType.php`, `api/app/Common/Controllers/DocumentController.php`; new `api/app/Console/Commands/ExpireRecruitmentOffers.php`; `api/routes/console.php`; offer/notification/document access tests.

- [ ] Add approved-only-send, confidential-PDF, vault-download unauthorized, offer response evidence/time, stale-version acceptance, expiry, decline, and sent-terms-immutability tests.
- [ ] Render through `PdfRenderService` and store through `DocumentVaultService`; use a new confidential `recruitment_offer` document type. Both direct offer downloads and generic vault list/show/download must apply the offer row-scope policy. Do not let `admin.audit_logs.view` become a fallback route to candidate offer bytes.
- [ ] Persist the offer-send notice with document version/hash in its immutable snapshot. Sending requests set a pending delivery fact; `sent_at`/`sent` require transport acceptance or explicit documented manual delivery evidence. Only the exact delivered version can be accepted.
- [ ] Record response time, channel, actor, evidence reference/private attachment, and version. Decline closes the application as withdrawn with `offer_declined`, cancelling rounds. Expiry marks only the offer; HR receives an action reminder and may close/reissue. It never auto-hires or auto-rejects.

Acceptance evidence shape:

```json
{
  "action": "accept",
  "expected_version": 3,
  "responded_at": "2026-10-12T15:30:00+08:00",
  "response_channel": "email",
  "evidence_reference": "Verified reply retained under the approved candidate-record policy"
}
```

Require evidence appropriate to the selected channel; validate actual timestamps and avoid personal detail in immutable events. Attachment upload uses private storage, size/MIME checks, and the same scanning gate as Task 17. If scanning is not deployed yet, use evidence references rather than accepting unscanned attachments.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentOfferTest|RecruitmentNotificationTest|DocumentControllerTest|DocumentVaultServiceTest'`.

### Task 14: Gate Hired and prefill atomic onboarding from accepted terms

**Depends on:** 03, 12–13. **Files:** Recruitment/offer service, employee controller/request, conversion resource, `spa/src/pages/hr/employees/create.tsx`, existing `spa/src/components/hr/EmployeeForm.tsx`, recruitment application detail/API/types; existing employee create tests, recruitment application/concurrency/offer tests; summary cohort metrics.

- [ ] Add hired-without-acceptance denied, accepted-wrong-version denied, conversion-term-tampering denied, changed-start-date-reapproval, legacy-unconverted-hire evidence, and simultaneous conversion tests.
- [ ] Require the current accepted, delivered, approved offer to mark Hired. Recheck accepted terms and prerequisites at conversion; preserve Task 03's slot reservation and transactional employee/onboarding outbox behavior.
- [ ] Prefill employment type, pay type, exact `basic_monthly_salary` or `semi_monthly_rate`, proposed `date_hired`, department/position, and candidate contact. Do not infer statutory/personal mandatory fields. Salary/currency/start-date changes require an amended approved offer. Reject unsupported currency/pay mapping rather than guessing.
- [ ] Provide the explicit legacy-evidence endpoint for hires captured by `requires_legacy_offer_review` during the additive migration. Require hire plus correction permissions, actual historic terms/delivery/acceptance evidence, and a reason. Create an `evidence_mode=legacy_review` version and submit it through the current maker-checker chain; only its actual current approval clears conversion blocking. Store the historic delivery/response times and today's review/approval times separately; never fabricate a historic send or approval. Existing converted hires remain untouched. Expose onboarding checklist/link with appropriate employee permissions.
- [ ] Extend exact summary with event-based stage reach and median time-to-hire; exclude estimated timestamps with explicit counts and cohort denominator. Keep historical converted hires in headcount, even after separation.

Frontend regression assertions:

```ts
expect(screen.getByLabelText(/Monthly salary/i)).toHaveValue('25000.00');
expect(screen.getByLabelText(/Date hired/i)).toHaveValue('2026-10-19');
expect(screen.getByLabelText(/Birth date/i)).toHaveValue('');
```

Use the actual shared employee form labels and input type behavior; number inputs may expose numeric rather than string values, so assert the form's serialized decimal value as well. This task must not bypass the existing shift/onboarding logic or change ordinary nonrecruitment employee creation.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentApplicationTest|RecruitmentOfferTest|RecruitmentConcurrencyTest|EmployeeWelcomeNotificationTest'`; `docker exec ogami-spa npm run test:run -- src/pages/hr/employees/create.test.tsx`.

## Phase 4 — Candidate recovery and data safety

### Task 15: Make public submission replay-safe and privacy-preserving

**Depends on:** 04–05, 11. **Files:** Recovery migration; public application request/controller/service; application model; `spa/src/api/public-recruitment.ts`, `spa/src/pages/careers/detail.tsx`; reuse `spa/src/lib/requestKey.ts`; public tests and new `spa/src/pages/careers/detail.test.tsx`.

- [ ] Add exact-key replay, changed-payload conflict, concurrent first submission, lost-response retry after posting closure, failed-transaction file cleanup, and duplicate-email-no-code-disclosure tests.
- [ ] Store posting/submission UUID uniqueness and normalized payload/resume checksum fingerprint. Handle database uniqueness races by returning the same receipt. Replays run before the now-closed intake check but still authenticate receipt knowledge through exact key and fingerprint. Never let an arbitrary new key reveal an existing candidate.
- [ ] Keep a nonsecret UUID for the current attempt; do not regenerate it automatically on network retry. If a previously attempted payload changes, explicitly start a new attempt. Preserve form/file state when possible; explain when refresh requires reselecting the file. No public PII draft persistence.
- [ ] Duplicate-email responses become generic check-email/recovery guidance; update the existing test that currently expects explicit existence disclosure. Rate-limit by IP and a hashed posting/email bucket without logging the email; avoid CAPTCHA until measured abuse requires it.

Exact replay regression core inside `PublicRecruitmentTest` using its existing valid payload:

```php
$key = (string) \Illuminate\Support\Str::uuid();
$first = $this->withHeader('Idempotency-Key', $key)->postJson($url, $payload)->assertCreated();
$again = $this->withHeader('Idempotency-Key', $key)->postJson($url, $samePayload)->assertOk();
$this->assertSame($first->json('tracking_code'), $again->json('tracking_code'));
$this->assertDatabaseCount('job_applications', 1);
```

`$samePayload` contains a newly opened upload with identical bytes, not the already-consumed test file handle. Assert one referenced resume and no abandoned uploaded object. For unkeyed duplicates, assert no capability field in the response.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=PublicRecruitmentTest`; careers detail Vitest suite.

### Task 16: Strengthen tracking and add verified recovery/self-service

**Depends on:** 09, 11, 15. **Files:** Recovery migration; new `api/app/Modules/HR/Models/RecruitmentRecoveryToken.php`, `Services/RecruitmentRecoveryService.php`, focused public request classes; public controller/routes; notice service/templates; public API/types/track page; `spa/src/routes/careersRoutes.tsx`; web/proxy log-redaction and response-header configuration; new `api/tests/Feature/HR/RecruitmentRecoveryTest.php`, track Vitest tests.

- [ ] Add legacy-code compatibility, new 128-bit code format, identical known/unknown recovery response, request-does-not-revoke-old-code, one-time consumption, expiry/race, rate-limit, revoked-code, and private-field-exclusion tests.
- [ ] Generate `RCT-` plus 32 hexadecimal characters; widen before issuing. Store only hashes of recovery tokens, use 30-minute expiry, invalidate consumed tokens under the application lock, and rotate tracking only after email verification. Keep legacy capabilities until rotation/deletion; never show them through unauthenticated recovery requests.
- [ ] Move updated tracking to POST-body lookup. Direct links use fragment, clear it after load, and apply no-store/no-referrer/noindex. Redact legacy tracking GET paths in application/proxy logs and remove their route after a documented compatibility window; no bearer code in telemetry or error reporting.
- [ ] Add verified withdrawal and privacy request; terminal responses are idempotent and converted hires cannot be withdrawn here. Reserve the resume-replacement endpoint for Task 17's scan/version rules. Self-service remains available while intake is disabled.

Capability assertions:

```php
$this->assertMatchesRegularExpression('/^RCT-[a-f0-9]{32}$/', $newCode);
$this->postJson('/api/v1/public/recruitment/applications/track', ['tracking_code' => $oldCode])
    ->assertNotFound();
$this->postJson('/api/v1/public/recruitment/applications/recover/consume', ['token' => $token])
    ->assertStatus(410);
```

The latter two assertions run after successful one-time consumption. Before consumption, the old code must still work even after repeated recovery requests. Token failure messages remain generic.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentRecoveryTest|PublicRecruitmentTest'`; track Vitest suite.

### Task 17: Quarantine resumes and fail closed on malware scanning

**Depends on:** 15–16. **Files:** Scan migration; new `api/app/Modules/HR/Services/RecruitmentResumeService.php`, `Jobs/ScanRecruitmentResume.php`, `api/app/Console/Commands/RescanRecruitmentResumes.php`; application request/model/controller/resource; candidate replacement endpoint/UI; `api/config/recruitment.php`, `api/.env.example`, `docker/php/Dockerfile`, `docker-compose.yml`, `docker-compose.prod.yml`; new `api/tests/Feature/HR/RecruitmentResumeTest.php`.

- [ ] Add clean/infected/failed/timeout tests, denied pending download, checksum mismatch, original-filename sanitization, unauthorized download, failed replacement preservation, simultaneous replacement version conflict, and legacy pending-state tests.
- [ ] Use a concrete `RecruitmentResumeService` with upload/quarantine/checksum/download and scan operations. Invoke installed `clamdscan --stream --no-summary` using Symfony Process argument arrays and a 30-second timeout against private ClamAV. Do not expose the daemon to host/public networks or add an extensible antivirus-provider framework.
- [ ] Scanner health requires reachable daemon, valid signatures updated within 48 hours, and bounded limits. Application submission stays saved during scanner outages, but downloads remain blocked. Only matching clean checksum bytes are downloadable as safe original-name attachments with `nosniff` and no public URL.
- [ ] Backfill existing files as pending in bounded rescan batches. Candidate replacement uses capability plus upload version; quarantine new bytes, retain the referenced old clean resume until replacement is clean, and delete unreferenced temporary bytes safely. Failed/infected replacements never replace a valid resume silently. Share scan validation with offer-evidence attachments where enabled.
- [ ] Verify EICAR in an isolated scanner/runtime with a harmless test fixture. Scanner timeout/failure never becomes `clean`. Document scanner health, retry, file permissions, and monitoring before enabling the gate.

Focused service assertions after a simulated infected result:

```php
$this->assertSame('infected', $application->fresh()->resume_scan_status);
$this->actingAs($viewer)->getJson(
    "/api/v1/hr/recruitment/applications/{$application->hash_id}/resume",
)->assertStatus(423);
```

Use HTTP `423 resume_unavailable` for blocked scans and `404` for missing/revoked bytes. Unit failure injection is allowed; release acceptance still requires a real EICAR scan and daemon-outage proof.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=RecruitmentResumeTest`.

### Task 18: Add review-based retention, legal holds, and provable cleanup

**Depends on:** 09, 11, 13, 16–17. **Files:** Privacy migration/default setting migration; new `api/app/Modules/HR/Services/RecruitmentPrivacyService.php`, `Controllers/RecruitmentPrivacyController.php`, `Jobs/DeleteRecruitmentCandidateData.php`, `Models/RecruitmentDeletionLedger.php`; new commands `QueueRecruitmentRetentionReviews.php`, `ReapplyRecruitmentDeletionSuppressions.php`; `api/routes/console.php`; privacy-related resources/routes/UI; `api/app/Common/Services/BackupService.php` maintenance restore seam; new `api/tests/Feature/HR/RecruitmentPrivacyTest.php` and privacy operations runbook.

- [ ] Add 12-calendar-month boundary, active-unconverted exclusion, legal hold, explicit deletion approval, permission denial, candidate verification, blob failure/retry, no-PII tombstone, and backup-restore suppression tests.
- [ ] Record consent policy version and closure-based review date. Daily scheduler queues review only. Privacy officers hold/release/approve per record with reasons and concurrency guards; no silent mass deletion and no recruitment removal of linked employee history.
- [ ] On approval, atomically revoke capabilities/tokens and suppress notices. An idempotent job clears nullable candidate fields, private notes/interview free text, resume/evidence blobs, encrypted notice snapshots, and request fingerprints. Preserve redacted stage/actor events and aggregate history. Deletion state becomes complete only after every owned blob cleanup succeeds.
- [ ] Create a durable redacted suppression ledger indexed by opaque application identity. Persist a copy outside the restored database in the protected operational storage used by the restore procedure, with independent retention and integrity validation. `BackupService` must reapply post-backup deletions while maintenance mode remains active before reopening traffic. If the suppression ledger cannot be verified, restore does not declare privacy-safe completion.
- [ ] Record backup expiry, legal-hold rules, failed-delete recovery, recipient-mail limitations, external copy obligations, and organization/legal review of the privacy text. Do not claim 12 months is a statutory requirement or that backups disappear instantly.

Required cleanup assertions:

```php
$this->assertNull($application->fresh()->email);
$this->assertNull($application->fresh()->tracking_code);
\Illuminate\Support\Facades\Storage::disk('local')->assertMissing($resumePath);
$this->assertDatabaseHas('recruitment_application_events', ['job_application_id' => $application->id]);
$this->assertSame('complete', $application->fresh()->deletion_status);
```

If blob deletion fails, the first two security revocations still hold but `deletion_status` remains retryable, not complete. A hold must prevent enqueueing cleanup entirely. Nullable-field changes include resource/null-safe name handling, uniqueness semantics, and existing reports.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='RecruitmentPrivacyTest|RecruitmentRecoveryTest|RecruitmentNotificationTest'`.

## Phase 5 — Process and UI polish

### Task 19: Polish careers content, forms, and recovery states

**Depends on:** 04, 15–18. **Files:** Publication migration; posting requests/resources; missing-setting-only seeds/migration; `spa/src/pages/careers/index.tsx`, `detail.tsx`, `track.tsx`, `spa/src/api/public-recruitment.ts`, `types/recruitment.ts`; reuse Input/Textarea/Button/useSeo/form safety; careers Vitest suites.

- [ ] Add accessible label/error tests, resume and consent server errors, valid-field `aria-invalid=false`/absent, `503` tracking error not invalid-code, `429` retry information, preserved typed values/file, and success/recovery navigation tests.
- [ ] Add actual work arrangement/location and explicit salary currency/pay period to publication fields. Validate disclosed range consistently, including partial bounds; render localized currency through public facts, not authenticated-layout currency globals. Requirements render as readable bullets. Dedicated careers introduction must not reuse manufacturing hero copy.
- [ ] Use existing `fieldSize="lg"` and touch-sized primary buttons where appropriate. Connect all error props/labels/descriptions; show an announced summary and focus the first invalid field. Do not create a new form system or change global sizing for unrelated pages.
- [ ] Classify `404`, `409`, `410`, `413`, `422`, `429`, and network/server failure separately. Preserve state and offer safe retry. Resume errors must be visible; upload selection remains keyboard-accessible. Disable public PII draft persistence while keeping navigation protection.
- [ ] Success includes copyable receipt/capability, direct fragment-based tracking, email expectations, and next steps without an invented response SLA. Closed role pages show helpful alternatives; use noindex/no-store where appropriate. Emit `JobPosting` structured data only for open accepting postings with actual employer/location/salary facts.

Testing Library checks after forcing a resume `422`:

```ts
expect(await screen.findByRole('alert')).toHaveTextContent(/resume/i);
expect(screen.getByLabelText(/Resume/i)).toHaveAttribute('aria-invalid', 'true');
expect(screen.getByLabelText(/First name/i)).toHaveValue('Juan');
```

Separately assert valid labeled textareas are not invalid on initial render. No made-up company/legal/address placeholder may appear in candidate-facing copy.

**Run:** `docker exec ogami-spa npm run test:run -- src/pages/careers`; `docker exec ogami-spa npm run audit:tokens`; `docker exec ogami-spa npm run build`.

### Task 20: Polish internal posting/application and approval interfaces

**Depends on:** 06–14, 18. **Files:** Recruitment dashboard/posting/application pages; new minimal `spa/src/pages/hr/recruitment/offers/detail.tsx` and `privacy/index.tsx`; recruitment routes/API/types; existing approvals index/type options; `spa/src/api/download.ts` reuse; focused page tests.

- [ ] Add permission-dependent action rendering, explicit target confirmations, stale conflict recovery, terminal stepper, posting reopen, draft restore requirements, protected resume download filename, and affected-query invalidation tests.
- [ ] Give each page one clear next action plus owner/due/stage-age context. Use real interview/offer/communication states and terminal labels. Reuse `ReasonDialog`, `FormActions`, existing timelines/tables/filters. Hire-only and restricted offer approver pages must remain usable without application-management or broad candidate-view permissions.
- [ ] Posting detail adds read-only preview/share, expiry/headcount/prerequisite context, close/reopen explanation, and explicit vacancy recovery. Create/edit requirements use a single form source of truth; remove parallel tag state that draft restoration can desynchronize. Preserve the user's existing form edits.
- [ ] Resume and offer buttons call `downloadAuthenticatedFile` and respect scan/authorization state; do not force a generic filename that hides the server's safe original filename. Show pending downloads and clear errors.
- [ ] Standardize recruitment query keys in `spa/src/api/recruitment.ts` and a small exported invalidation function using the existing query client. Every affected mutation refreshes posting/application lists/details/history, exact summary, overdue queue, and affected approvals/calendar. No new global state store is needed.
- [ ] Add the scoped offer page, approvals-board link, owner/next-action controls, and privacy review queue using existing layouts. Approval-only resources/links never fetch full application PII. Deleted candidates show a tombstone; privacy actions explain irreversibility and pending cleanup accurately.

Minimal protected download call:

```ts
await downloadAuthenticatedFile(`/hr/recruitment/applications/${application.id}/resume`, {
  errorMessage: 'Resume could not be downloaded. Check scan status and try again.',
});
```

No `filename` override is needed; the existing helper reads `Content-Disposition`. Test server filename preservation rather than reimplementing header parsing.

**Run:** `docker exec ogami-spa npm run test:run -- src/pages/hr/recruitment src/pages/approvals`; `docker exec ogami-spa npm run audit:rbac`; `docker exec ogami-spa npm run build`.

### Task 21: Integrate scheduled interviews with the existing calendar

**Depends on:** 08, 10, 16, 20. **Files:** `api/app/Common/Services/CalendarAggregatorService.php`, `Controllers/CalendarController.php`, `api/app/Modules/HR/Controllers/PublicRecruitmentController.php`, HR routes/notice templates, `spa/src/types/calendar.ts`, existing calendar page layer rendering/options, candidate track page/API; extend `api/tests/Feature/Calendar/CalendarAggregatorTest.php`; calendar/recruitment focused browser checks.

- [ ] Add recruitment interview layer visibility, disabled-module wind-down visibility, terminal/cancelled exclusion, department filter, timezone/day boundary, count/truncation, and no-private-fields tests.
- [ ] Add `recruitment_interview` to layer permission/limit/label metadata and controller validation, using the existing calendar response shape and bounded query pattern. Require recruitment view; offer-approver-only users do not gain interview/candidate data.
- [ ] Query only scheduled interview-stage rounds. Link to the permitted application detail. Put actual ISO time and duration in safe metadata while preserving the existing day-based calendar fields; do not turn all calendar types into a new scheduling system.
- [ ] Add the frontend union/layer handling and display explicit time/timezone. Provide the native candidate `.ics` download endpoint from the shared contract; stable event UID and increasing sequence prevent misleading duplicate events on reschedule. No third-party calendar synchronization or OAuth integration.

Calendar assertions:

```php
$response->assertJsonPath('data.0.type', 'recruitment_interview');
$this->assertArrayNotHasKey('email', $response->json('data.0.meta'));
$this->assertArrayNotHasKey('outcome', $response->json('data.0.meta'));
```

Implement native `.ics` as a small tested response in the existing public recruitment controller, not a library; verify escaping, UID/sequence, UTC timestamps, and cancellation semantics. Send cancellation/update exports from the captured candidate-safe notice data; current capability downloads return no event for a terminal/cancelled interview. Use the interview's existing version as event sequence and an opaque stable interview UID.

**Run:** `docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=CalendarAggregatorTest`.

## Phase 6 — Integrated proof and rollout

### Task 22: Build isolated real-backend recruitment journeys

**Depends on:** 01–21. **Files:** New `scripts/recruitment-e2e-fixture.php`, `spa/e2e-real/recruitment.spec.ts`, `spa/e2e-real/recruitment-recovery.spec.ts`, `spa/e2e-real/recruitment-permissions.spec.ts`; reuse `spa/playwright.real.config.ts` and `spa/e2e-real/helpers.ts`; new `docs/testing/recruitment-verification.md`.

- [ ] Create a recruitment fixture script guarded to exact `ogami_test_recruitment_e2e_20261007`; refuse any other database before writes. Seed only test-tagged actors/postings/candidates with synthetic contacts. Use existing role/factory patterns and test mail transport, not real candidate email.
- [ ] Provision a dedicated API/browser runtime with its own storage, compiled views, cache/session/queue namespace, database, and test frontend URL. Never switch `ogami-api` live configuration or share another audit runtime. Record exact runtime start commands and resolved URL in the verification document before running specs.
- [ ] Cover draft/publish; public apply and lost-response retry; screening/interview/outcome; approved/sent/accepted offer; last-slot hire; conversion/onboarding; close/reopen; withdrawal/correction; recovered tracking; malware replacement; legal hold and approved cleanup. Use actual API/backend; `page.route()` is forbidden in `e2e-real`.
- [ ] Cover view/manage/applications/hire/approver/privacy roles and disabled-module behavior. Attempt unauthorized direct URLs and API actions as well as visual assertions. No synthetic fixture may bypass the business invariants the journey claims to prove.
- [ ] Capture desktop 1440×1000 and mobile 390×844, keyboard-only modal/form journeys, focus return, labels/errors, live announcements, contrast, no unintended horizontal scrolling, and 44 CSS-pixel primary targets. Test network-failure rendering in unit/mock tests separately; do not describe mocked failures as real-backend proof.
- [ ] Run PostgreSQL concurrent headcount/conversion/recovery/replacement tests, actual ClamAV EICAR/outage checks, email retry/suppression, and isolated backup restoration with independent deletion suppression. Preserve traces/screenshots/results with synthetic-only data.

Run only after the dedicated runtime URL is verified:

```bash
E2E_BASE_URL=http://localhost:18081 npx playwright test -c playwright.real.config.ts --project=real-chromium recruitment
```

Run from `spa`. Port `18081` is the planned isolated recruitment runtime port; inspect availability and provision it before use. The harness refuses a missing URL. If occupied, use a verified unused port and document the resolved URL; never assume the process answering the port uses the test database.

**Stop condition:** Every audited journey has a passing focused test or explicitly recorded release blocker. Unrelated failing suites remain documented; do not patch unrelated features just to make a global command green.

### Task 23: Preflight, stage deployment, and document operating procedures

**Depends on:** 22. **Files:** New `api/app/Console/Commands/AuditRecruitmentIntegrity.php`; `docs/operations/recruitment-runbook.md`, `docs/testing/recruitment-verification.md`; reviewed settings/workflow/permissions migration; production deployment/backup/scanner configuration seams already in use.

- [ ] Implement read-only `recruitment:audit-integrity --json` listing overfilled postings, missing/inactive prerequisites, duplicate/missing employee links, legacy ambiguous deadlines, unverified historical interview outcomes, weak/legacy capability counts, unscanned/missing files, pending offer evidence, failed notices, and privacy holds/cleanup failures. Output opaque identifiers/counters, not candidate names/contact/files. No repairs unless a distinct explicitly reviewed repair operation is selected.
- [ ] Prove migrations preserve record counts, employee links, exact decimal terms, legacy codes/timestamps, and operator settings. Downward migrations refuse truncating long codes or dropping populated offer/privacy data. Code rollback keeps additive schema and disables only new writes; never restore an old unsafe implicit-advance client/server combination.
- [ ] Stage rollout: additive schema; safe ownership/capacity/explicit target server+client; workflow/interview/summary; configured offers with legacy review; recovery and capability headers; healthy scanner then bounded legacy scanning; privacy dry-run then review actions; final UI polish. Stage blocks progress when its acceptance gate fails.
- [ ] Verify real company/legal/contact identity, salary currency/pay period, business timezone, offer approvers, candidate/privacy copy, private storage permissions, scanner health, scheduler, queue/outbox recovery, and authorized notification recipients. Missing configuration is a launch blocker, not permission to invent values.
- [ ] Run focused backend/frontend/build/route/token checks plus relevant cross-module approval/document/employee/calendar/backup suites. Record command, environment/database, exit status, assertions, and limitations; no completion claim based solely on the earlier 36-test baseline.
- [ ] Publish HR operating procedure: publishing/closing intake, vacancy correction, owner/overdue triage, interview outcome/no-show, offer approval/send/response, legacy evidence, hire/conversion, failed notices/scans, verified candidate requests, holds/deletion cleanup, and backup restore privacy checks. Monitor conflict/overfill denials, stale clients, notice/scan/cleanup failures, overdue counts, and scheduler health through existing logging/alert surfaces without PII.
- [ ] Hand off scoped diff and acceptance evidence. Application deployment, material cleanup, and commits occur only in an authorized execution session, not while producing this plan.

Final commands, in addition to each task's focused proof:

```bash
docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter=Recruitment
docker exec -e DB_DATABASE=ogami_test_recruitment_plan_20261007 ogami-api php artisan test --filter='PublicRecruitmentTest|ApprovalBoardScopeTest|DocumentControllerTest|CalendarAggregatorTest|EmployeeWelcomeNotificationTest'
docker exec ogami-spa npm run test:run -- src/pages/hr/recruitment src/pages/careers src/pages/hr/employees/create.test.tsx
docker exec ogami-spa npm run build
docker exec ogami-spa npm run audit:rbac
docker exec ogami-spa npm run audit:tokens
git diff --check
```

The integrity command against deployment data is read-only; run it with the intended deployment's verified environment separately from tests. Do not include production `migrate`, bulk deletion, or blanket worktree staging in an automated verification command.

Runbook commands introduced by the tasks are `recruitment:audit-integrity --json` (read-only), `recruitment:expire-offers` (updates offer expiry only), `recruitment:rescan-resumes --limit=100 --dry-run` (report before bounded enqueue), `recruitment:queue-retention-reviews --dry-run` (reports review candidates, no deletion), and `recruitment:reapply-deletion-suppressions --ledger=<validated-path> --dry-run` (maintenance restore preflight). Daily expiry/review schedules and existing hourly bottleneck schedule use overlap protection. Live scan/review/restore writes require their documented execution context; none run during planning.

## Audit coverage ledger

IDs are assigned here to the completed audit's findings so execution can close every item without relying on chat history. Severity describes risk, not implementation size.

| ID | Gap or improvement | Priority | Tasks |
|---|---|---|---|
| R01 | Interview ownership/actor injection through nested request and left-biased union | P1 | 01, 02, 22 |
| R02 | Stale implicit advancement skips offer and unexpectedly hires | P1 | 02, 22 |
| R03 | Converted-only headcount and filled-state bypass allow overfill | P1 | 03, 09, 14, 22 |
| R04 | Closed posting cannot finish final hire/conversion | P1 | 03, 04, 14 |
| R05 | Date-only deadline closes at midnight; timezone uncertainty | P1 | 04, 19, 23 |
| R06 | Terminal candidates retain upcoming/editable interviews and misleading mail | P1 | 08, 09, 11, 21 |
| R07 | Disabled recruitment still accepts public applicants while HR is blocked | P1 | 05, 16, 22 |
| R08 | Department/position archival and null relations break historical pages/hiring | P1 | 04, 14, 23 |
| R09 | Dashboard page counts/funnel denominator misrepresent all candidates | P1 | 07, 10, 14, 20 |
| R10 | Hire/applications/conversion actions disagree with API/route permissions | P1 | 06, 12, 20, 22 |
| R11 | Resume/consent errors hidden; tracking outage presented as invalid code | P1 | 15, 16, 19 |
| R12 | Future interview passed outcome unlocks offer without actual interview | P2 | 08, 12, 22 |
| R13 | Pending/null outcomes and future rounds mask overdue work; note edits reset age | P2 | 08, 10 |
| R14 | Missing reopen UI and incomplete query invalidation leave stale process state | P2 | 02, 09, 20 |
| R15 | Missing labels and false invalid attributes; modal/focus/error accessibility | P1 | 19, 20, 22 |
| R16 | No explicit owner, next action, due date, or stage-age queue | P2 | 10, 20 |
| R17 | No versioned approved/sent/accepted offer evidence | P1 | 12, 13, 14 |
| R18 | No withdrawal, offer decline, no-show, rescission, controlled correction | P2 | 08, 09, 13, 16 |
| R19 | Candidate retention/holds/verified deletion and backup cleanup missing | P1 | 18, 22, 23 |
| R20 | Resume MIME/size/private storage does not provide malware scanning | P1 | 17, 22, 23 |
| R21 | Lost receipt/retry and short bearer code have no verified recovery | P2 | 15, 16, 22 |
| R22 | Reporting lacks complete filters, outcomes, cohort and duration provenance | P2 | 07, 10, 14 |
| R23 | Public salary formatting depends on auth runtime; location/pay period unclear | P2 | 19 |
| R24 | Manufacturing hero copy and absent company settings degrade careers trust | P2 | 19, 23 |
| R25 | Small touch targets, dense requirement tags, unclear success/terminal states | P2 | 19, 20, 22 |
| R26 | Generic resume filename/download handling obscures original file/recovery | P2 | 17, 20 |
| R27 | Posting form requirements have parallel draft/tag state | P2 | 20 |
| R28 | Employee handoff omits accepted terms and onboarding context | P2 | 14, 20 |
| R29 | Delayed model-based mail, failed delivery, and manual retry lack durable truth | P1 | 11, 13, 18 |
| R30 | Interview scheduling lacks duration/mode/calendar/timezone affordances | P2 | 08, 19, 21 |
| R31 | No posting preview/share, closed-link recovery, or truthful structured data | P3 | 19, 20 |
| R32 | Sequential tests do not prove races, real journeys, privacy or operational recovery | P1 | 22, 23 |

## Completion checklist

- [ ] Every R01–R32 row has evidence and no unresolved P1 release blocker.
- [ ] Hired reserves slots; accepted versioned offer evidence gates hire/conversion; closed intake does not strand candidates.
- [ ] No public/private permission bypass, candidate PII in approval/calendar/outbox/audit/log surfaces, unscanned file download, or stale-state silent mutation.
- [ ] Candidate retry, tracking recovery, withdrawal, upload replacement, errors, and mobile/keyboard use are verified.
- [ ] Retention queues review after 12 calendar months, respects holds, and proves approved cleanup and restored-backup suppression.
- [ ] Historic data/operator settings survive migration; unsafe schema rollback is refused; legacy corrections are explicit and evidenced.
- [ ] Final handoff includes scoped files, executed commands/results, remaining limitations, and runbook. No speculative integration or redesign work is added beyond this plan.

## Planning self-review

Reviewed against the companion design: all 32 coverage rows map to tasks; policies, state/API fields, role grants, service contracts, migration ownership, and release gates are explicit. The review corrected a legacy-offer migration loophole by requiring real current maker-checker approval, made calendar export a defined endpoint rather than optional scope, and confirmed the existing notes payload is `body`. Execution checkboxes remain unchecked intentionally. No application change, deployment, or new test success is claimed by this planning document.
