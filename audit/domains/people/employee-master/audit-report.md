# M014 — Employee master audit report

- Audit date: 2026-08-24
- Domain/module: people / employee-master
- Tier: 1
- Surface: L
- Status: Plan Ready
- Source changes: none; this session produced audit artifacts only.

## Executive summary

The employee master happy paths are covered and the focused existing test suite is healthy, but several lifecycle, authorization, sensitive-data, import/export, and hierarchy paths are not production-safe. The largest risks are alternate separation and account-provisioning paths, arbitrary export columns, and the importer bypassing canonical employee creation side effects.

The findings below are classified as Broken, Missing, Incomplete, or Polish. Because most actionable items require cross-module decisions or medium/large changes, the module is Plan Ready and no production fixes were applied in this audit session.

## Findings

### F-001 — Generic employee update can mutate lifecycle status

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: UpdateEmployeeRequest.php:81-90 accepts every EmployeeStatus value; EmployeeService.php:257-270 removes only salary and then updates the employee; EmployeeService.php:272-320 records other history changes but not status changes.
- Impact: A caller with ordinary employee-edit permission can set an employee to resigned, terminated, or retired without separation reason/date, clearance, final pay, account deactivation, or a lifecycle history record. The same path can also move a separated employee back to an active state.
- Recommendation: Remove status from the generic update contract or route each transition through a lifecycle service with state guards, required separation data, history, and authorization.

### F-002 — Direct “Separate” action bypasses clearance and final-pay workflow

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: routes.php:76-82 exposes the direct separate endpoint; EmployeeService.php:327-356 only changes status and writes history. The canonical flow is routes.php:108-110 and SeparationService.php:265-345, which locks clearance/employee state and posts final pay. Account deactivation is performed by DeactivateAccountOnClearanceComplete.php:28-55.
- Impact: The employee detail UI can create a separated employee without clearance, final pay, or linked-account deactivation.
- Recommendation: Replace the direct action with a transition into the canonical separation workflow, or explicitly enforce the same invariants in one shared domain service.

### F-003 — Account provisioning trusts caller-supplied role and email

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: ProvisionAccountRequest.php:17-39 validates only a role string/hash shape; UserProvisioningService.php:51-65 creates an active user from the supplied email and role_id. HR officers receive the provisioning permission in RolePermissionSeeder.php:454-470, and the SPA submits user-selected values in CreateAccountModal.tsx:65-71.
- Impact: A provisioning-capable HR user can submit a system_admin role hash or an attacker-controlled valid email. The resulting credentials are delivered to the supplied address, and the new account is active immediately.
- Recommendation: Resolve role and email server-side from an employee/account policy, allow-list assignable roles, require explicit elevation for privileged roles, and require verified employee contact data.

### F-004 — Archiving an employee does not deactivate the linked account

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: EmployeeService.php:359-362 only soft-deletes the employee. The user relation uses a null-on-delete foreign key in 0118_add_employee_id_fk_to_users_table.php:36-42; account deactivation is a separate operation in UserProvisioningService.php:94-120. AuthService.php:47-60 permits login based on is_active, while SelfServiceController.php:46-58 excludes the archived employee and returns 404.
- Impact: An archived employee can retain an active login but lose the employee record needed by self-service. This creates both an access-control inconsistency and a broken user experience.
- Recommendation: Make archive a coordinated employee/account state transition, or reject archiving while an active linked account exists.

### F-005 — Masked sensitive values can be written back during ordinary edit

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: EmployeeResource.php:69-74 and :97-120 mask IDs and bank data for callers without sensitive-data permission. EmployeeForm.tsx:152-190 uses those values as defaults and :404-409 submits the banking fields. UpdateEmployeeRequest.php:25-35 normalizes digits and :92-93 accepts the bank value.
- Impact: A user who can edit but cannot view sensitive values can submit masked values back to the API, corrupting government IDs or bank data without intending to change them.
- Recommendation: Omit masked fields from update payloads unless explicitly changed, or use write-only sensitive-field endpoints with server-side permission and complete-value validation.

### F-006 — Salary fields are shown but silently discarded by ordinary edit

- Classification: Incomplete
- Tags: [small] [separate-recommended]
- Evidence: EmployeeForm.tsx:328-357 renders salary inputs; EmployeeService.php:257-269 unsets salary fields from the update payload. The supported salary-adjustment path is routes.php:145-150, while the salary-history branch at EmployeeService.php:287-301 is unreachable from the generic update.
- Impact: Users can edit salary fields, receive a successful response, and see no change. This is misleading and can cause payroll data to be assumed current when it is not.
- Recommendation: Remove the fields from the generic form and link to the salary-adjustment workflow, or route the fields through the approved maker-checker/payroll process.

### F-007 — CSV importer bypasses canonical employee creation and incomplete rollback

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: EmployeeImporter.php:90-136 calls Employee::create directly. EmployeeService.php:172-251 also creates hired history, shift, onboarding, leave balances, and an outbox event. EmployeeImporter.php:151-158 auto-creates positions, but MasterDataImportService.php:144-165 records only returned models for rollback. Importer normalization/date handling is at EmployeeImporter.php:90-134 and :169-179, while the canonical store rules are StoreEmployeeRequest.php:81-94.
- Impact: Imported employees can lack required dependent records/events, and a failed batch can leave auto-created positions behind. Import data also follows weaker normalization/validation than the regular create path.
- Recommendation: Define an import-specific application service that invokes the canonical creation policy, validates all rows before mutation, and tracks every created dependent record for rollback.

### F-008 — Configurable export accepts arbitrary columns and bypasses row scope

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: ExportController.php:107-129 accepts requested columns without intersecting them with the module registry. BaseModuleExport.php:48-59 resolves arbitrary row properties. Employee.php:31-65 contains sensitive IDs, bank data, and salary, while EmployeeMasterExport.php:31-48 builds an unscoped employee query. The list path applies DepartmentScope in EmployeeService.php:40-58, but export does not.
- Impact: A caller with export permission can request unregistered sensitive fields, and future/custom grants can export employees outside the caller’s row scope.
- Recommendation: Enforce a server-side per-module column allow-list, sensitive-column permissions, and the same row-scope policy used by employee listing before constructing the export query.

### F-009 — Document permission and employee row scope are too broad

- Classification: Missing
- Tags: [medium] [separate-recommended]
- Evidence: routes.php:126-142 authorizes document upload with hr.employees.documents.view and uses the general edit permission for deletion. StoreEmployeeDocumentRequest.php:9-21 authorizes only the view permission. EmployeeDocumentService.php:17-40 and :50-55 receive no user or row-scope context.
- Impact: A role intended only to view documents can upload, and a custom-scoped document viewer can access another employee’s documents if it knows the employee hash.
- Recommendation: Separate view/upload/delete permissions and pass the authenticated actor through a policy that applies employee row scope to every document operation.

### F-010 — Department tree omits parent_id and can clear hierarchy on edit

- Classification: Broken
- Tags: [medium] [same-session-ok]
- Evidence: DepartmentService.php:50-60 eager-loads headEmployee but not parent. DepartmentResource.php:18-26 emits parent_id only when the parent relation is loaded. The SPA tree builder requires parent_id at departments/index.tsx:42-63, and the edit form initializes/submits it at :334-393.
- Impact: The API returns departments as roots; editing an existing child can submit null parent_id and flatten the hierarchy.
- Recommendation: Include parent_id directly in the resource or eager-load parent, and add a regression test for read-edit-save preservation of a child department.

### F-011 — Department hierarchy and head validation are incomplete

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: DepartmentService.php:76-85 rejects only a department being its own parent; it does not detect longer cycles. StoreDepartmentRequest.php:51-61 and UpdateDepartmentRequest.php:49-58 decode parent/head values without existence, same-tree, or active-employee validation. DepartmentResource.php:18-26 exposes the relation, but the SPA form at departments/index.tsx:334-405 has no head field.
- Impact: A cyclic hierarchy can be persisted, invalid heads can be referenced, and administrators cannot manage the department head through the provided UI.
- Recommendation: Add cycle detection and relational validation, define head eligibility, and expose head management if it is part of the module contract.

### F-012 — Account notifications and reset-session handling are incomplete

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: UserProvisioningService.php:38-91 sends the welcome notification inside the transaction. WelcomeNotification.php:12-27 and PasswordResetNotification.php:12-23 are ordinary Notification classes rather than ShouldQueue jobs. Password reset mutates and sends inside UserProvisioningService.php:136-170 without revoking existing sessions/tokens; the controller reports success at EmployeeAccountController.php:60-66.
- Impact: A notification failure can roll back account provisioning after external side effects, and a password reset does not invalidate existing authenticated sessions. The API response does not distinguish delivery failure from successful email dispatch.
- Recommendation: Dispatch after commit through a queue, make delivery state explicit, and revoke sessions/tokens whenever credentials are reset or an account is deactivated.

### F-013 — Employee export converts money to floating-point values

- Classification: Incomplete
- Tags: [small] [separate-recommended]
- Evidence: EmployeeMasterExport.php:81-100 casts salary/allowance values to float; SpreadsheetExportService.php:85-100 writes non-string numeric values. Money.php:7-11 explicitly requires a non-floating-point money contract.
- Impact: Spreadsheet exports can introduce rounding or representation errors in payroll-related values.
- Recommendation: Export decimal strings or fixed-precision spreadsheet values and add exact-value tests for all money columns.

### F-014 — Department-head PII exposure needs an explicit policy decision

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: EmployeeResource.php:37-54 returns address, contact, and emergency-contact data; :67-82 also returns bank name and linked-user email. Only salary/IDs/account data are gated at :64-74. The department-head permissions in RolePermissionSeeder.php:670-688 grant ordinary employee viewing but do not express field-level PII restrictions.
- Impact: Department heads may receive broader personal data than the least-privilege policy intends. This is a policy gap rather than an assumption that every listed field is forbidden.
- Recommendation: Confirm the PII matrix with HR/legal, then implement field-level resource policies and tests for each role.

### F-015 — Employee UI labels/status summary are inconsistent with the domain contract

- Classification: Polish
- Tags: [small] [same-session-ok]
- Evidence: Employee detail labels the compensation value “Daily rate” at detail.tsx:190-198, while the API/export contract supports semi-monthly compensation at EmployeeMasterExport.php:91-100 and EmployeeForm.tsx:343-357. The status tiles at index.tsx:41-73 omit suspended and retired even though the status type includes them at types/hr.ts:52-55.
- Impact: The UI can mislead users about pay cadence and hide valid employee states from the summary.
- Recommendation: Use the domain pay-period label and include all supported statuses or explicitly document why some are excluded.

## Verification

- Focused API tests in the project container: 32 passed, 107 assertions.
- SPA TypeScript check: passed with npm run typecheck.
- Targeted SPA ESLint: passed with --max-warnings 0.
- PHP syntax checks for the audited HR/API files: passed.
- git diff --check on audited paths: passed.
- A host-side PHPUnit attempt was not usable because the host could not resolve the compose database hostname db; the same focused suite passed inside the project container.

## Disposition

No production source fixes were applied. F-010 and F-015 are suitable for a small same-session follow-up, but the majority of findings require separate recommended work and/or cross-module policy decisions. Keep M014 at Plan Ready and schedule implementation/re-audit work in dependency order.
