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
