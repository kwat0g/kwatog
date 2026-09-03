# M014 — employee-master fix log

Implementation session: 2026-08-25

The module was resumed from `🔁 Needs Re-audit` with the existing audit report
and action plan. Findings were fixed in plan order; F-014 remains a deliberate
policy question and is recorded below as deferred.

## Fixed items

- F-001 — `api/app/Modules/HR/Requests/UpdateEmployeeRequest.php:89-96`, `api/app/Modules/HR/Services/EmployeeService.php:266-279`, and `api/app/Modules/HR/Support/EmployeeStateMachine.php:12-39`. Generic updates now reject lifecycle status and compensation changes, and separation status changes use an explicit `TRANSITIONS` map with history source status.
- F-002 — `api/app/Modules/HR/routes.php:107-109`, `spa/src/api/hr/employees.ts:101-104`, and `spa/src/pages/hr/employees/detail.tsx:126,1317-1389`. The obsolete direct separate endpoint is gone; the UI calls the clearance-backed `POST /employees/{employee}/separation` flow and describes initiation accurately.
- F-003 — `api/app/Modules/HR/Requests/ProvisionAccountRequest.php:17-25` and `api/app/Modules/HR/Services/UserProvisioningService.php:24-76,248-267`. Caller-supplied email and role are prohibited; the service resolves the employee email/server setting and only permits the configured least-privileged employee role.
- F-004 — `api/app/Modules/HR/Services/EmployeeService.php:339-359`. Archiving locks the employee, deactivates the linked account, revokes sessions/tokens, then soft-deletes the employee in one transaction.
- F-005 — `api/app/Modules/HR/Requests/UpdateEmployeeRequest.php:50-83`, `api/app/Modules/HR/Resources/EmployeeResource.php:66-76`, `spa/src/components/hr/EmployeeForm.tsx:156-194,389-440`, and `spa/src/pages/hr/employees/edit.tsx:14-25,105-106`. Masked sensitive values are not loaded into an unprivileged edit form, sensitive bank metadata is gated, and edit cleanup omits protected fields.
- F-006 — `api/app/Modules/HR/Requests/UpdateEmployeeRequest.php:89-96`, `api/app/Modules/HR/Services/EmployeeService.php:272-279`, and `spa/src/components/hr/EmployeeForm.tsx:330-373`. Compensation and pay type cannot be changed through generic employee edit; the form redirects users to salary adjustments.
- F-007 — `api/app/Modules/HR/Imports/EmployeeImporter.php:39-173` now validates canonical fields and delegates creation to `EmployeeService`, preserving hired history, onboarding, shift, leave-balance, and outbox side effects. Committed-batch dependent cleanup remains deferred because the generic rollback owner is outside this module's allowed scope.
- F-008 — `api/app/Modules/HR/Exports/EmployeeMasterExport.php:26-83` allow-lists requested columns, gates salary columns, applies `DepartmentScope`, and decodes department filters safely. Unknown or invalid filters cannot widen the export.
- F-009 — `api/app/Modules/HR/routes.php:125-141`, `api/app/Modules/HR/Requests/StoreEmployeeDocumentRequest.php:8-25`, and `api/app/Modules/HR/Services/EmployeeDocumentService.php:18-119` separate view/upload/delete permissions and enforce employee row scope for list, upload, delete, restore, and download.
- F-010 — `api/app/Modules/HR/Services/DepartmentService.php:53-65` eagerly loads parents for tree responses; the resource already emits the loaded `parent_id`, preserving the SPA tree relationship.
- F-011 — `api/app/Modules/HR/Services/DepartmentService.php:70-151` validates missing parents, self/descendant cycles, active same-department heads, and archived employees; `spa/src/pages/hr/departments/index.tsx:350-421` exposes head assignment during edit.
- F-012 — `api/app/Modules/HR/Services/UserProvisioningService.php:74-76,128-141`, `api/app/Modules/HR/Notifications/EmployeeWelcomeNotification.php:12-32`, and `api/app/Modules/HR/Notifications/EmployeePasswordResetNotification.php:10-25` queue notifications after commit and revoke sessions/tokens during reset/deactivation. API responses report queued delivery rather than claiming synchronous delivery.
- F-013 — `api/app/Modules/HR/Exports/EmployeeMasterExport.php:134-150` returns exact two-decimal money strings through `Money::round2`; no float conversion remains in employee money export resolvers.
- F-015 — `spa/src/pages/hr/employees/index.tsx:41-82,322-323`, `spa/src/pages/hr/employees/detail.tsx:191`, and `spa/src/pages/hr/salary-adjustments/index.tsx:137` include suspended/retired status tiles and use semi-monthly terminology.

## Deferred human decision

- F-007 — The shared import rollback path still soft-deletes only the tracked
  employee model, so a committed employee rollback can leave import-created
  positions/dependent records. This requires a separately scoped change to the
  common import pipeline; it was not changed here.
- F-014 — Address, contact, emergency-contact, linked-user email, and related
  PII still need an HR/legal field-level visibility matrix. The existing
  `hr.employees.view_sensitive` permission is explicitly named for government
  IDs, TIN, and bank data; expanding it to all PII without policy approval
  would be a guess. No broader exposure policy was invented in this session.

## Verification

- Focused backend run before the final state-machine conversion fix: 36 tests
  passed and one test exposed the string-to-enum conversion, which was fixed at
  `api/app/Modules/HR/Services/SeparationService.php:337`.
- A clean retry was blocked by an unrelated concurrent worktree migration,
  `api/database/migrations/2026_08_25_140000_add_artifact_key_to_bank_file_records.php`,
  whose malformed `use` statements stop `RefreshDatabase` before assertions.
  That file is outside this module and was not modified.
- Targeted PHP syntax checks passed for all changed PHP files.
- Targeted SPA ESLint passed with zero warnings.
- SPA typecheck has no employee-module errors; the repository-wide command still
  reports pre-existing `qrcode`/implicit-any errors in `spa/src/pages/assets/detail.tsx`.
- `git diff --check` passed for the audited changes.

---

## Session 2026-08-27 — coordinator-authorised HR test-fix batch (4 named tests)

Scope: fix 4 named failing tests and only the code paths they exercise. This was
NOT a re-audit of employee-master, and the two non-employee-master modules below
were NOT claimed or audited — only their named test was touched.

### Prior-session status: done vs done-but-unverified

The 2026-08-25 implementation session above claims F-001…F-013 and F-015 fixed,
but its own Verification section records that the clean retry never completed
(`RefreshDatabase` was blocked by a malformed migration in another worktree).
So every item in that list was **written but never executed** until now.

Verified green by this session (the tests actually ran):
- **F-001 / F-006** — confirmed live. `EmployeeService::update()` now refuses
  lifecycle-status and compensation keys; `SalaryAdjustmentGateTest` exercises
  the compensation half and passes (5/5).

Still done-but-unverified (no test in this batch reaches them): F-002, F-003,
F-004, F-005, F-007, F-008, F-009, F-010, F-011, F-012, F-013, F-015.
Deferred as before: F-007 (import rollback), F-014 (PII matrix).

### employee-master — fixed

- **`api/tests/Feature/HR/SalaryAdjustmentGateTest.php:7,44-70`** —
  `test_direct_employee_update_cannot_change_salary` encoded the *pre-F-006*
  contract. Before: called `EmployeeService::update()` with salary keys and
  asserted only `assertSame('20000.00', …)`, i.e. it expected the salary to be
  **silently discarded** and the call to return normally. After F-006 the
  service throws `BusinessRuleException` at
  `api/app/Modules/HR/Services/EmployeeService.php:278`, so the test errored in
  its *act*, not its setup. The guard was NOT weakened. The test now asserts
  both halves — the refusal (`try`/`fail()`/`catch`, chosen over
  `expectException` so execution continues) and the invariants the test is
  named for: salary unchanged and no `employee_salary_history` row written.
  Added the `BusinessRuleException` import and put the retired-contract history
  in the docblock so the next reader does not "restore" silent discard.

### employee-master — finding, not fixed (no test reaches it)

- `prohibited` vs `array_key_exists` mismatch. `UpdateEmployeeRequest.php:96-98`
  uses Laravel's `prohibited`, which passes when a key is present but **empty**;
  `EmployeeService.php:275-277` keys the throw off `array_key_exists`. A payload
  `{"basic_monthly_salary": null}` therefore clears HTTP validation and then
  fails in the service with a record-state message instead of a field error.
  Not reachable from the shipped SPA — `spa/src/pages/hr/employees/edit.tsx:22-24`
  deletes `basic_monthly_salary`, `semi_monthly_rate` and `pay_type` before
  submitting — so this only affects direct/third-party API callers. Left alone
  deliberately: making the service ignore a null key would let
  `$employee->update()` write NULL over a real salary, which is worse than the
  current loud refusal. The correct fix is to tighten the FormRequest, which is
  an API-contract change and out of this batch's scope.

### people/recruitment-careers — fixed (NOT claimed; test-only, for that owner)

Both failures were the documented seed trap, confirmed against the live schema
(`job_postings.posting_number` varchar(20), `job_applications.application_number`
varchar(20), `job_applications.tracking_code` varchar(10) — from
`0248_create_job_postings_table.php:15` and `0249_create_job_applications_table.php:15,17`).
**No production writer can overflow these columns**, so no source change was
needed: `RecruitmentService.php:51` / `:150` use the `document_sequences`
generator (`JP`/`JA` monthly, pad 4 → `JP-202608-0001`, 14 chars) and
`generateTrackingCode()` at `RecruitmentService.php:678-693` builds exactly
`'RCT-'` + 6 chars = 10. Fixture-only defects:

- **`api/tests/Feature/HR/RecruitmentPostingTest.php:140`** —
  `test_posting_with_applications_cannot_be_archived`.
  Before: `'RCT-ARCH'.substr(uniqid(), -4)` = 12 chars into `tracking_code`
  varchar(10) → `SQLSTATE[22001] … character varying(10)`.
  After: `'RCT-A'.substr(uniqid(), -5)` = 10 chars.
- **`api/tests/Feature/HR/RecruitmentBottleneckCommandTest.php:106`** —
  `test_converted_hired_application_does_not_raise_a_bottleneck`.
  Before: `'JP-BOT-HIRED-'.substr(uniqid(), -8)` = 21 chars into `posting_number`
  varchar(20) → `SQLSTATE[22001] … character varying(20)` (this is the failure
  the suite reported; it aborted before reaching the next one).
  After: `'JP-BH-'.substr(uniqid(), -8)` = 14 chars.
- **`api/tests/Feature/HR/RecruitmentBottleneckCommandTest.php:121`** — second,
  latent overflow in the same test, masked by the one above.
  Before: `'RCT-H'.($converted ? 'CONV' : 'OPEN').substr(uniqid(), -2)` = 11 chars
  into `tracking_code` varchar(10).
  After: `'RCT-'.($converted ? 'C' : 'U').substr(uniqid(), -5)` = 10 chars; the
  C/U letter still keeps the two loop rows distinct under the UNIQUE index.

Left alone (fits, but at the limit): the same test's `application_number`,
`'JA-BOT-HIRED-'.('C'|'U').substr(uniqid(), -6)` = exactly 20/20. Deterministic,
so it passes, but it has no headroom.

### people/training-skills — NOT fixed, blocked on a policy decision

`EmployeeTrainingAssignTest::test_training_lifecycle_rejects_recompletion_and_cancelling_completed_record`
is a **real state-machine hole, not a fixture problem** — the fixture does reach
`Completed` (the first `complete` returns 200). It fails at test line 161, the
*re-completion* assertion. The *cancel* assertion at line 165 is fine and needs
no change: `completed -> cancelled` is already absent from the transition table.

Root cause: `api/app/Modules/HR/Support/EmployeeTrainingStateMachine.php:37-39`
short-circuits `$current === $target` to a silent `return`, so
`EmployeeTrainingService::recordCompletion()` (`:88`) proceeds on an
already-completed record and rewrites `completed_at`, recomputes `expires_at`
from the new date, clears `last_alert_level`/`last_alert_at` and can swap the
certificate — all with a 200.

**Why this was not fixed: two committed tests require opposite behaviour.**

| test | added | requires |
|---|---|---|
| `EmployeeTrainingExpiresAtTest::test_recompletion_resets_alert_state` (`:67-89`) | `d035e062`, 2026-06-15, deliberate `test(t3.4.b)` | re-completion **succeeds**; it is the retake/recert path and must reset the alert marker |
| `EmployeeTrainingAssignTest::…rejects_recompletion…` (`:143-171`) | `167de85e`, 2026-08-26, the "NOT REVIEWED" batch commit | re-completion **422s**; a completed record is immutable |

Deleting the three-line short-circuit makes the second pass and the first fail —
verified, not assumed (run output in Verification below). Note the newer test and
the state machine landed in the *same* unreviewed commit, and the state machine
as written cannot satisfy its own new test: it was committed red.

The change was therefore **reverted to the committed behaviour** so this session
does not trade a reported failure for an unreported one. Only an explanatory
comment remains at `EmployeeTrainingStateMachine.php:37-53`; `git diff` on that
file is comment-only.

**Decision required (recertification policy — HR/IATF 16949, not an agent call).**
A forklift or safety certification lapses and is retaken; the question is whether
the retake overwrites the existing competence record or creates a new one.

- **Option A — re-completion is the retake path (keep today's behaviour).**
  Delete `EmployeeTrainingAssignTest.php:158-161`, keep the rest of that test
  (the cancel assertion and the final `assertDatabaseHas` still pass).
  One test file changes, no source change. Cost: a completed record's
  `completed_at`/`expires_at`/certificate stay silently rewritable, and the
  record keeps no history of prior completions — weak IATF traceability.
- **Option B — a completed record is immutable; a retake is a new row.**
  Delete the three-line short-circuit at `EmployeeTrainingStateMachine.php:37-39`
  and rewrite `EmployeeTrainingExpiresAtTest::test_recompletion_resets_alert_state`
  to assert a *new* record. Reaches further than this batch: the retake needs a
  create path, and `EmployeeTrainingService::assertNoOpenAssignment()` (`:153-175`)
  currently rejects a duplicate when the employee has an "open **or completed**"
  assignment for that training on that date, so a same-date retake is blocked
  today. Also loses the `last_alert_*` reset that the 2026-06-15 test protects
  unless the new row starts clean.
- **Option C — allow re-completion only once the prior certification has lapsed**
  (i.e. `completed_at >= expires_at`, optionally minus a renewal window).
  This happens to make *both* existing tests pass as written — test A re-completes
  2026-06-02 deep inside a 2027-06-01 validity window, test B re-completes exactly
  on its 2026-07-01 expiry. Recorded because it is worth knowing, **not**
  recommended by default: it invents a renewal rule from two fixtures and would
  refuse early renewal, which many plants allow (e.g. retake 30 days before
  expiry). Needs an explicit policy number before implementing.

Related, deliberately untouched: the same blanket `$current === $target` no-op
exists in `api/app/Modules/CRM/Services/ComplaintService.php`,
`api/app/Modules/ReturnManagement/Support/ReturnRequestStateMachine.php`,
`api/app/Modules/Loans/Support/LoanStateMachine.php` and
`api/app/Modules/Quality/Support/InspectionStateMachine.php`. Whether idempotent
self-transitions are safe there is per-domain and outside this batch; other
agents were live on those areas this session.

### Verification (actual output, this session)

Own database `ogami_n_hr`, one class per `--filter`, never the full suite.

Baseline, before any change:
- `SalaryAdjustmentGateTest` — 1 failed, 4 passed. `BusinessRuleException:
  Compensation changes must go through the salary adjustment workflow.` at
  `app/Modules/HR/Services/EmployeeService.php:278`.
- `RecruitmentPostingTest` — 1 failed, 6 passed. `SQLSTATE[22001] … value too
  long for type character varying(10) … values (JA-ARCHIVE-9a8690ed, 4,
  RCT-ARCH90fe, …)`.
- `EmployeeTrainingAssignTest` — 1 failed, 8 passed. `Expected response status
  code [422] but received 200.` at `EmployeeTrainingAssignTest.php:161`.

After the fixes:
- `SalaryAdjustmentGateTest` — **5 passed** (12 assertions).
- `RecruitmentPostingTest` — **7 passed** (24 assertions).
- `RecruitmentBottleneckCommandTest` — **4 passed** (8 assertions).
- `EmployeeTrainingAssignTest` — **9 passed** (31 assertions) *with the
  state-machine change applied*; reverted to **1 failed / 8 passed** (the
  original reported failure) once the policy conflict was found.
- Regression classes for the state-machine change: `TrainingExpiryAlertTest`
  9 passed, `SelfServiceTrainingsTest` 3 passed, `EmployeeTrainingExpiresAtTest`
  **1 failed / 2 passed** — `BusinessRuleException: Employee training is already
  completed.` This is the failure that forced the revert.
- After the revert: `EmployeeTrainingExpiresAtTest` 3 passed,
  `EmployeeTrainingAssignTest` back to 1 failed / 8 passed.
- `php -l` clean on all four changed PHP files.

Net for this batch: **3 of 4 tests fixed**, 1 escalated as a policy decision with
both patches specified above. No SPA file was changed, so no typecheck/lint run
was required.

---

### RESOLVED 2026-09-04 — recertification policy (Option B)

Decision made by the project owner: **a completed training record is immutable;
a retake creates a NEW assignment record.** Implemented in this session:

- `EmployeeTrainingStateMachine::transition()` no longer no-ops on
  `$current === $target` — self-transitions (re-complete, re-cancel) throw, so
  the `/complete` endpoint returns 422 on a completed record and its
  `completed_at`/`expires_at`/certificate/alert fields can no longer be
  silently rewritten.
- `TrainingExpiryService::check()` only evaluates the **newest** completed row
  per (employee, training), so a superseded cert cannot fire expiry alarms
  while a newer one is current.
- `EmployeeTrainingExpiresAtTest::test_retake_creates_a_new_record_that_starts_clean`
  replaces the old recompletion test: the retake record starts clean (null
  alert state, own `expires_at`) and the original keeps its signed-off history.
- `EmployeeTrainingAssignTest::test_training_lifecycle_rejects_recompletion_and_cancelling_completed_record`
  now passes as written (422 on both).

Verification: full `tests/Feature/HR` 172 passed, `TrainingExpiryAlertTest` +
`SelfServiceTrainingsTest` + badge/expiry filters green, full Feature suite
green for this change set.
