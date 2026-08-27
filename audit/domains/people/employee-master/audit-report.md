# M014 — Employee master audit report

- Audit date: 2026-08-27
- Domain/module: people / employee-master
- Card: M014
- Tier/surface: 1 / L
- Claimed target: people/employee-master
- Test database: ogami_test_m014_agent_c
- Status: Plan Ready
- Source changes: none; this session produced audit artifacts only.

## Scope and method

This re-audit read the current module registry, inherited audit workflow
documents, M014 status/report/plan/fix log, implementation, tests, frontend
consumers, documentation, scoped git diff, and file mtimes. The registry was
not regenerated or edited. At claim time the only unrelated worktree change
was the coordinator's generated audit/00-MODULE-REGISTRY.md; the M014
source/test scope had no uncommitted diff.

The audit used three passes:

1. Discovery — lifecycle, account, row-scope, onboarding, leave, import, and
   document surfaces.
2. Hardening — negative paths, state invariants, storage boundaries,
   concurrency, and sensitive-data policy.
3. Polish — API/documentation/UI contract drift and recovery affordances.

## Outcome

M014 remains Plan Ready. The focused suite is healthy, but the remaining
issues include lifecycle/RBAC, leave-credit, storage, and shared import
rollback decisions. Most fixes are medium/large or cross-module, so no
production fix was safe to land in this session. No new fix-log entry is
appropriate because no source or test fix was completed.

The previous audit's completed F-001 through F-006, F-008 through F-013, and
F-015 were not reopened where current code still proves their intended guard
or behavior. F-007 remains the import-rollback residual below. F-014 remains
the unresolved PII policy item below.

## Discovery pass

### R-001 — Terminal employees can receive a new active account

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: UserProvisioningService.php:39-66 locks the employee and checks
  only for an existing account before creating is_active=true; it never
  checks EmployeeStatus.php:7-15. The bulk path fetches every non-deleted
  employee at UserProvisioningService.php:199-207, and the account routes
  are permission-only at routes.php:94-99.
- Impact: A resigned, terminated, or retired employee with no linked user can
  be provisioned as an active login, bypassing the terminal lifecycle.
- Recommendation: Define the allowed employee statuses for provisioning,
  reject terminal statuses in the service and bulk path, and add negative
  tests for every terminal status.

### R-002 — Restoring an archived employee does not restore account access

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: Archive deactivates the linked account before soft-deleting at
  EmployeeService.php:329-346. EmployeeController.php:252-255 restores only
  the employee row. UserProvisioningService.php:82-104 has deactivation but
  no reactivation operation, and SystemAccountSection.tsx:145-153 exposes
  only Deactivate Account.
- Impact: An employee restored from archive keeps an inactive account and
  cannot log in; provisioning again encounters the existing account instead
  of repairing it.
- Recommendation: Choose an explicit restore policy and implement a locked,
  audited account reactivation or a clear “restore requires admin repair”
  state with matching UI and tests.

### R-003 — Employee mutation and account routes do not apply the read row scope

- Classification: Missing
- Tags: [large] [separate-recommended]
- Evidence: Read scope is centralized through DepartmentScope at
  EmployeeService.php:42-60 and re-applied for show at :151-165. Mutation
  routes are permission-only at routes.php:76-82; controllers pass no actor
  at EmployeeController.php:240-255, and update/delete perform no scope check
  at EmployeeService.php:263-287 and :329-345. Account status/provision/
  deactivate/reset and bulk paths likewise receive only permission middleware
  and raw models at EmployeeAccountController.php:24-74 and
  UserProvisioningService.php:199-207.
- Impact: Default seeded roles are currently conservative, but a custom grant
  that gives an actor write/account permission without global visibility can
  mutate or operate on an arbitrary employee hash.
- Recommendation: Make every employee write and account operation require an
  actor and the same explicit row-scope policy, with tests for a
  department-scoped actor using update, delete, restore, photo, and account
  endpoints.

### R-004 — Onboarding completion is never invalidated after source data is removed

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: OnboardingService.php:62-94 only fills null timestamps when a
  shift, account, government IDs, or banking exists. maybeComplete at
  OnboardingService.php:308-313 only sets completed_at and never clears it.
  Those source fields are nullable in UpdateEmployeeRequest.php:67-99, while
  EmployeeService.php:263-325 updates the employee without recomputing the
  tracker.
- Impact: Removing bank/ID/shift/account data can leave the corresponding
  onboarding step and completed_at falsely complete.
- Recommendation: Recompute from canonical state after relevant writes,
  clear stale derived timestamps/completion, and add removal and
  re-completion regression tests.

### R-005 — New-hire leave balances can be full-year instead of prorated

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: EmployeeService.php:223-240 seeds the current calendar year with
  each leave type's full default_balance. The registered listener is specified
  to prorate at InitializeLeaveBalances.php:14-23 and computes credits at
  :38-66, but insertOrIgnore preserves the row already seeded by the service
  (unique key: 0027_create_employee_leave_balances_table.php:13-24).
- Impact: A mid-year hire can receive a full annual entitlement; a
  backdated hire can also be seeded into now's year while the listener uses
  the hire year.
- Recommendation: Pick one owner for initialization, use the hire year and
  prorated credits consistently, and test Jan 1, mid-year, year-end, and
  backdated hires.

## Hardening pass

### R-006 — Restoring an employee document revives a row with no file

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: EmployeeDocumentService.php:47-55 deletes the physical local file
  before soft-deleting the document; restore at :57-62 only calls
  document->restore(); download at :64-75 returns null when the file is
  absent. The restore route is exposed with withTrashed at routes.php:135-139.
- Impact: A restored document appears in the record set but cannot be
  downloaded, undermining clearance/final-pay evidence.
- Recommendation: Retain or quarantine the file until permanent retention
  expiry, or remove the restore contract; make delete/restore/download tests
  cover the chosen retention policy.

### R-007 — Document upload validation does not match storage columns or cleanup

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: StoreEmployeeDocumentRequest.php:16-21 accepts document_type up to
  100 characters. Migration 0018_create_employee_documents_table.php:13-19
  defines document_type 50, file_name 200, and file_path 500. The service
  stores the file before EmployeeDocument::create at
  EmployeeDocumentService.php:30-44 and has no cleanup if the insert fails.
- Impact: A valid HTTP request can fail at the database boundary or leave an
  orphaned file after a failed row insert.
- Recommendation: Align request/database limits, define filename handling,
  and add compensating cleanup or an outbox/after-commit storage workflow.

### R-008 — Employee photo replacement and deletion are not failure-atomic

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: uploadPhoto deletes the old local/public copies at
  EmployeeController.php:263-271 before storing the new file and updating the
  row at :273-274. deletePhoto deletes storage before clearing the row at
  :318-326. Neither operation coordinates storage and database failure.
- Impact: A disk or database failure can leave a database path with no file,
  an orphaned new file, or a lost old photo.
- Recommendation: Store the replacement first, update the row transactionally,
  then delete old storage after commit; make deletion idempotent and test
  failed-storage/database paths.

### R-009 — Employee import rollback still removes only the top-level employee

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: EmployeeImporter.php:150-166 delegates creation to
  EmployeeService and can auto-create a position at :184-193. The common
  commit records only the returned model at
  MasterDataImportService.php:144-152; rollback deletes only each tracked
  model at :180-190. The employee's hired history, onboarding, leave,
  shift, outbox, and import-created position are not batch records.
- Impact: A committed employee rollback can leave dependent rows, outbox
  effects, or an auto-created position behind. The fix owner is the shared
  import pipeline, outside this module-only session.
- Recommendation: Extend the import contract to register every created
  aggregate/dependent record or provide a module rollback hook, then add an
  employee commit/rollback integration test. Do not solve this by editing the
  shared service in an M014-only change.

### R-010 — Employee CSV dates accept values rejected by regular employee creation

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: EmployeeImporter.php:92-98 and :262-272 only parse dates and
  compare regularization to hire date. StoreEmployeeRequest.php:74 and
  :101-102 additionally enforce minimum age, hire-date bounds, and
  regularization not before hire date.
- Impact: Import can create an underage or future-dated employee that the
  normal create endpoint would reject.
- Recommendation: Define the intentional import contract, align safety
  invariants with StoreEmployeeRequest, and add underage/future/bad-order
  CSV tests.

### R-011 — Employee update permits regularization before hire date

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: UpdateEmployeeRequest.php:92-93 validates date_regularized only
  against today; StoreEmployeeRequest.php:101-102 also requires
  after_or_equal:date_hired.
- Impact: An edit can make the employment timeline internally impossible even
  though the create path rejects the same relationship.
- Recommendation: Validate against the existing or submitted effective hire
  date and add an update regression test.

### R-012 — Employment-type changes are accepted without employment history

- Classification: Missing
- Tags: [medium] [separate-recommended]
- Evidence: UpdateEmployeeRequest.php:87 accepts employment_type. The service
  captures it in the original snapshot at EmployeeService.php:283-285, but
  the history branches at :290-311 cover only department, position, and
  regularized date.
- Impact: A material employment classification change has no auditable
  from/to record or approver.
- Recommendation: Add a dedicated history change type/payload and approval
  semantics, or make employment type immutable outside a workflow.

### R-013 — Hire-date edits silently bypass employment history

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: UpdateEmployeeRequest.php:92 permits date_hired. EmployeeService.php:283-311 snapshots no date_hired value and creates no history entry for it after update at :287.
- Impact: A tenure-affecting correction can change silently without an audit
  trail or an explicit correction workflow.
- Recommendation: Prohibit post-hire edits or route them through a correction
  workflow with old/new dates, approval, and downstream reconciliation.

### R-014 — Saving an unchanged regularized date creates duplicate history

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: EditEmployeePage cleanup submits all nonempty form fields at
  edit.tsx:16-25, and EmployeeForm.tsx:184-189 initializes
  date_regularized from the existing employee. EmployeeService.php:305-310
  creates a Regularized history row whenever the key is present and non-null,
  without comparing old and new values.
- Impact: Re-saving an employee can create repeated “regularized” events with a
  null from_value.
- Recommendation: Compare persisted and submitted dates and record only a
  real transition, with a regression test for an unchanged edit.

### R-015 — Bulk provisioning silently drops invalid or unknown employee IDs

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: BulkProvisionAccountsRequest.php:29-38 drops hashes that do not
  decode. UserProvisioningService.php:199-203 queries only found rows, and
  EmployeeAccountController.php:77-86 builds total/results from that reduced
  list.
- Impact: A successful response can report fewer employees than the caller
  selected without identifying invalid or missing IDs.
- Recommendation: Preserve one result per input, distinguish invalid,
  missing, and skipped rows, and return a validation error or explicit
  per-input failure.

### R-016 — Bulk provisioning returns internal exception text to callers

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: UserProvisioningService.php:219-225 reports a Throwable but puts
  its raw message into the failed result.
- Impact: Database, filesystem, or infrastructure details can be disclosed to
  a provisioning caller.
- Recommendation: Log the exception with a correlation ID and return a stable
  generic message while preserving actionable diagnostics in server logs.

### R-017 — Concurrent password resets are not serialized on the user row

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: resetPasswordForEmployee reads the relation before the transaction
  at UserProvisioningService.php:110-116. resetPasswordForUser starts a
  transaction but does not re-read or lock the user at :119-145.
- Impact: Two resets can overwrite each other's password; the first
  notification can deliver a temporary password that is no longer valid, and
  password history can describe stale state.
- Recommendation: Re-read and lock the user inside the transaction, make the
  winning reset/notification explicit, and add a concurrent reset test.

### R-018 — Generated account email selection races the unique constraint

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: generateEmail checks candidate availability in a loop at
  UserProvisioningService.php:232-245, while users.email is unique at
  0004_create_users_table.php:13-21. Two concurrent provisions can select the
  same free candidate before either insert commits.
- Impact: A normal concurrent hire can fail with a database uniqueness error
  instead of retrying or returning a controlled result.
- Recommendation: Use a reservation/locking strategy or catch the unique
  violation and retry candidate generation inside the transaction.

### R-019 — PII visibility policy is still undefined beyond named sensitive fields

- Classification: Incomplete
- Tags: [large] [separate-recommended]
- Evidence: EmployeeResource.php:37-54 exposes full address, mobile, and
  emergency-contact fields, and :81-85 exposes linked-user email when loaded.
  The seeded department_head role has hr.employees.view at
  RolePermissionSeeder.php:721-740, while the sensitive permission is named
  specifically for IDs/TIN/bank at RolePermissionSeeder.php:58-75.
  EmployeeMasterExport.php:151-160 also registers email/mobile without a
  sensitive permission.
- Impact: The system has no approved field-level matrix for department-scoped
  PII, so teams cannot prove whether the current response/export is allowed or
  intentionally public to internal roles.
- Recommendation: Obtain HR/legal policy for each PII field, encode it in
  resources/exports/requests, and add role-negative tests. Do not broaden or
  narrow access by guesswork.

## Polish pass

### R-020 — Process documentation still advertises the removed direct separation endpoint

- Classification: Incomplete
- Tags: [small] [separate-recommended]
- Evidence: PROCESS-FLOWS.md:1158-1164 lists
  PATCH /api/v1/hr/employees/{employee}/separate, but the current route is the
  clearance-backed POST at api/app/Modules/HR/routes.php:109-111.
- Impact: Operators and API consumers can follow a route that no longer exists
  or infer that separation bypasses clearance.
- Recommendation: Update the shared process documentation and API examples
  after the canonical route contract is confirmed.

### R-021 — Shared pay-type/schema documentation still describes retired daily pay

- Classification: Incomplete
- Tags: [small] [separate-recommended]
- Evidence: PROCESS-FLOWS.md:912-920 and SCHEMA.md:97-101 still describe
  monthly/daily and daily_rate. Current PayType.php:22-25 supports
  monthly/semi_monthly, and migration 0437_retire_daily_pay_type.php:49-60
  widens pay_type and renames daily_rate to semi_monthly_rate.
- Impact: QA, integrations, and operators can submit or document an obsolete
  pay contract.
- Recommendation: Update shared process/schema/pattern references in a
  separately coordinated documentation change and add a current contract
  example.

### R-022 — Document UI calls deletion permanent but offers no recovery action

- Classification: Polish
- Tags: [small] [same-session-ok]
- Evidence: the detail page describes deletion as “permanently removed” at
  detail.tsx:654-674, while the API exposes a soft-delete restore route at
  routes.php:135-139 and a restore service at
  EmployeeDocumentService.php:57-62.
- Impact: The UI gives no operator path to recover a mistakenly deleted
  clearance document and contradicts the API's recoverable row state.
- Recommendation: Either expose a permissioned, audited restore action or
  remove the restore contract and permanently delete rows by policy.

## Verification

- Unique database created for this card: ogami_test_m014_agent_c.
- Focused backend command used DB_DATABASE=ogami_test_m014_agent_c and
  DB_CONNECTION=pgsql against the container database. The selected classes
  were UserProvisioningTest, AutoProvisionUserOnHireTest, OnboardingTest,
  EmployeeDataScopeTest, MasterDataImportTest, EmployeeMasterExportTest,
  ExportAuthorizationTest, and ExportModuleContractTest:
  49 tests passed, 193 assertions.
- PHP syntax checks passed for the audited EmployeeService,
  UserProvisioningService, OnboardingService, EmployeeDocumentService,
  EmployeeController, EmployeeAccountController, EmployeeImporter,
  UpdateEmployeeRequest, StoreEmployeeDocumentRequest, and
  InitializeLeaveBalances files.
- Targeted SPA ESLint passed with zero warnings for HR employee pages and
  components. SPA typecheck (tsc --noEmit) exited 0.
- The module source/test scope remained unchanged; only this report and its
  action plan are new session writes. A final scoped git diff --check is
  required before commit.
