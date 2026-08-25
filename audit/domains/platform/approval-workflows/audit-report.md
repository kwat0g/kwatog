# M005 — Approval workflows audit report

- Audit session: 2026-08-24
- Domain/module: `platform/approval-workflows`
- Tier/surface: Tier 1 / M
- Dependencies: `auth-session`, `rbac`, `audit-activity`
- Roles: system admin, HR, Finance, Production, PPC, Purchasing, Warehouse, QC, Maintenance, Impex, department head, employee, driver
- Prior disposition: `🔲 Not Started`
- Current disposition: `📋 Plan Ready`

## Scope covered

The audit covered the shared approval state machine and every current consumer found in the repository:

- `ApprovalService`, `ApprovalBoardService`, `ApprovalEscalationService`, signature/PDF payload construction, approval models, delegation model/service, and the escalation command/schedule.
- Approval-board routes, delegation routes, role/permission seeding, workflow/settings seed data, migrations, and the dashboard approval worklist.
- Leave, purchasing, loans, salary-adjustment, return-management, and payroll integration points.
- SPA board API/types/page, focused API tests, and design-system interaction expectations.

## Discovery summary

The core service creates one row per configured workflow step and correctly uses decimal-string threshold comparisons (`api/app/Common/Services/ApprovalService.php:20-67`). Approve/reject operations run in transactions and lock the selected pending row (`api/app/Common/Services/ApprovalService.php:82-139`). Direct role checks, active delegation mutation authority, self-approval protection, threshold boundaries, and the normal multi-step purchase path are covered by the focused suite.

The module also carries higher-risk cross-module behavior. Approval records are polymorphic and have no attempt/workflow-definition identity (`api/database/migrations/0010_create_approval_records_table.php:13-29`). The board is a read-only aggregator over leave, purchasing, loans, and payroll (`api/app/Common/Services/ApprovalBoardService.php:12-30`); the route and permission are intentionally cross-cutting (`api/routes/api.php:140-146`, `api/database/seeders/RolePermissionSeeder.php:772-789`). Scheduled reminders, escalations, and optional automatic approve/reject run every six hours (`api/routes/console.php:111-115`, `api/app/Console/Commands/RunApprovalEscalations.php:19-29`).

## Findings and disposition

### F-01 — Broken: resubmission history is retained but terminal state is not scoped to the current attempt

`ApprovalService::submit()` deliberately keeps approved/rejected rows and deletes only pending/skipped rows (`api/app/Common/Services/ApprovalService.php:43-66`). The same method says callers should treat the latest non-terminal row per step as authoritative, but `records()` orders only by `step_order` and does not model or select an attempt (`api/app/Common/Services/ApprovalService.php:142-169`). `isFullyApproved()` evaluates every historical row, while `isRejected()` returns true if any historical row is rejected (`api/app/Common/Services/ApprovalService.php:154-164`).

Therefore, a rejected attempt that is submitted again can have a new fully approved set of rows while the service still reports `isRejected() === true` and `isFullyApproved() === false`. The printable signature builder also emits one row for every historical approval record, so a resubmitted step can appear twice (`api/app/Common/Support/ApprovalSignatureBuilder.php:27-30`, `78-89`). The existing resubmission test proves history is preserved and a new pending row exists, but does not assert terminal-state behavior (`api/tests/Unit/ApprovalServiceTest.php:283-333`).

This can prevent downstream status transitions that rely on `isFullyApproved()`—for example purchasing, loans, salary adjustments, and returns (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:346-369`, `api/app/Modules/Loans/Services/LoanService.php:210-224`, `api/app/Modules/HR/Services/SalaryAdjustmentService.php:63-78`, `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:219-247`). The remediation needs an explicit current-attempt/version model or a rigorously enforced current-row query, plus PDF/board semantics and migration/regression coverage.

Disposition: `[large][separate-recommended]` — approval state and audit-history contract.

### F-02 — Broken: delegated approvers can act but cannot see the delegated inbox

The mutation path correctly accepts a delegate through `ApprovalDelegation::activeDelegatesFor()` (`api/app/Common/Services/ApprovalService.php:172-185`), and the feature test proves an employee with a department-head delegation can approve (`api/tests/Feature/Approvals/ApprovalDelegationTest.php:69-95`). The board’s `roleSlugsFor()` returns only the user’s direct role (`api/app/Common/Services/ApprovalBoardService.php:164-171`), then categorizes cards by direct equality (`api/app/Common/Services/ApprovalBoardService.php:118-127`).

The dashboard worklist delegates to this same board service and its comments claim delegation-aware behavior (`api/app/Modules/Dashboard/Services/Analytics/ApprovalsWidgetAnalytics.php:19-24`, `46-69`), so the mismatch affects both `/approvals` and the dashboard tile. A delegate may be authorized at the record endpoint but have no “My action required” card or badge for that work.

Disposition: `[medium][separate-recommended]` — shared authorization/read-model contract; add delegation-aware board and dashboard tests, including expiry and role-change cases.

### F-03 — Broken: automatic resolution can choose the wrong workflow policy

`ApprovalEscalationService::resolvePolicyForRecord()` loads every workflow definition for every record and stops at the first definition whose `step_order` and `role_slug` match (`api/app/Common/Services/ApprovalEscalationService.php:153-192`). The implementation comment explicitly acknowledges that multiple workflow types can share the pair and that “the first matching workflow’s policy wins.” The approval-record schema stores neither `workflow_type` nor `workflow_definition_id` (`api/database/migrations/0010_create_approval_records_table.php:13-29`).

With automatic resolution enabled, the resulting action can be an approve, reject, or escalation decision from an unrelated workflow. The setting is currently seeded disabled, but it is a production-configurable automatic status transition (`api/database/seeders/SettingsSeeder.php:124-145`). Existing tests cover one unique matching definition and a default policy, not ambiguity across two workflows (`api/tests/Feature/Approval/ApprovalAutoResolveTest.php:149-180`).

Disposition: `[large][separate-recommended]` — stamp workflow identity/version at submission, resolve policy from that identity, and add an ambiguity/migration regression before enabling auto-resolve.

### F-04 — Incomplete: the cross-module board has no explicit financial/payroll visibility boundary

`approvals.board.view` is merged into every seeded role (`api/database/seeders/RolePermissionSeeder.php:772-789`). The board returns requester identity, amount/principal, summaries, action remarks, and actor information (`api/app/Common/Services/ApprovalBoardService.php:203-215`, `223-263`) for purchase requests/orders, employee loans, and payroll periods. The endpoint has only `auth:sanctum` plus that broad permission (`api/routes/api.php:140-146`); it does not apply per-module permissions, department scope, or a separate masking policy.

This may be intentional for a company-wide approval worklist, but the current contract is not documented or tested against least-privilege expectations. In particular, “Awaiting others” and recent approved/rejected history can expose financial amounts and remarks to roles that do not hold the underlying module’s read permission. Product/security owners need to choose: company-wide metadata, role/department masking, or per-module authorization.

Disposition: `[medium][separate-recommended]` — policy decision first, then backend and UI tests for allowed fields and links.

### F-05 — Incomplete/operations: board and scheduled sweeps are unbounded and still perform per-card queries

The board loads every pending row with no limit or pagination (`api/app/Common/Services/ApprovalBoardService.php:68-75`). Its “batch-load” loop still executes one source-table query per active approvable (`api/app/Common/Services/ApprovalBoardService.php:95-109`), and each actioned card performs another source-table query (`api/app/Common/Services/ApprovalBoardService.php:223-231`). Actioned history is capped at 200 before deduplication, while the response summary counts the uncapped arrays after filtering (`api/app/Common/Services/ApprovalBoardService.php:77-84`, `130-160`).

The reminder, escalation, and auto-resolve jobs likewise call `get()` on all eligible approval rows (`api/app/Common/Services/ApprovalEscalationService.php:23-27`, `57-61`, `114-119`). `withoutOverlapping` prevents the normal scheduler from running two copies, but it does not bound memory or make a partially completed sweep resumable. A growing approval ledger can therefore cause slow board requests, query amplification, and long scheduled-job runtimes.

Disposition: `[medium][separate-recommended]` — define board pagination/limits and batch source loading; use chunking/claiming or a durable sweep cursor for scheduled work; add large-volume query/memory coverage.

### F-06 — Broken: escalation links do not cover the board’s loan and payroll types

The board declares `EmployeeLoan` and `PayrollPeriod` as supported types (`api/app/Common/Services/ApprovalBoardService.php:34-41`), but escalation link mapping handles `LoanApplication` and has no payroll entry (`api/app/Common/Services/ApprovalEscalationService.php:250-263`). An `EmployeeLoan` or payroll approval notification therefore falls through to `/admin/audit-logs` and appends the hash without the entity route expected by the SPA. The fallback is especially misleading because the notification presents itself as an approval escalation.

Disposition: `[small][separate-recommended]` — make the shared type/link registry authoritative for notifications and add one link assertion per supported approvable type. Kept separate because the registry should be aligned with the visibility and board contract rather than patched in isolation.

### F-07 — Missing/incomplete: seeded workflow definitions overstate what is wired into production paths

The seeder distinguishes enforced and reserved workflows and explicitly says reserved definitions must not be presented as working (`api/database/seeders/WorkflowSeeder.php:14-21`), but it still inserts all definitions with the same schema and no active/reserved state (`api/database/seeders/WorkflowSeeder.php:22-169`, `171-179`). Repository call-site discovery found `ApprovalService::submit()` for leave, purchasing, loans, salary adjustment, and returns, but no submit path for the reserved department-transfer, asset-disposal, separation-clearance, maintenance, 8D, work-order, or NCR definitions. Payroll is also seeded as a workflow while `PayrollPeriod` does not use `HasApprovalWorkflow` (`api/app/Modules/Payroll/Models/PayrollPeriod.php:19-22`), and the service call graph contains no payroll `ApprovalService::submit()`.

This makes configuration/seed inspection suggest that more approval workflows are active than the application actually enforces. It also leaves the definitions visible to the auto-resolution scanner, which is already ambiguous by role/order. Either wire each workflow through its owning module and board/link map, or model reserved definitions explicitly and keep them out of runtime policy lookup.

Disposition: `[medium][separate-recommended]` — cross-module product/scope decision and seed/schema cleanup.

### F-08 — Incomplete: automatic decisions can be written without a responsible actor

For `approve`/`reject`, the scheduler selects the first active user whose role is in `system.automation.actor_roles`, but it does not require one to exist before mutating the record (`api/app/Common/Services/ApprovalEscalationService.php:223-237`). The row can be marked approved or rejected with `approver_id = null` and only the generic remark “Auto-resolved by SLA policy.” The test fixture always creates a system-admin actor (`api/tests/Feature/Approval/ApprovalAutoResolveTest.php:21-31`), so the missing-actor path is not covered.

Disposition: `[small][separate-recommended]` — require a configured system actor or add a first-class system principal/audit attribution; test missing, inactive, and multi-role actor configuration before enabling the feature.

### F-09 — Polish/incomplete: board filter/configuration failures and count semantics are not surfaced cleanly

The SPA renders the board error state, but the options query has no error/loading handling and silently falls back to only the “All” filter (`spa/src/pages/approvals/index.tsx:56-72`). If options fail, the SLA countdown is also silently replaced by age-only chips. The API slices approved/rejected cards to 50 but reports the full pre-slice counts (`api/app/Common/Services/ApprovalBoardService.php:150-160`), so the UI chip can claim more cards than are displayed and offers no pagination affordance. Actioned cards also reuse “Open record to act” wording even though the card is already terminal (`spa/src/pages/approvals/index.tsx:307-341`).

The board otherwise follows the design-system requirements for keyboard-reachable interactive cards, visible focus rings, text-plus-colour status, and skeleton/empty/error states (`spa/src/pages/approvals/index.tsx:105-124`, `253-303`; `docs/DESIGN-SYSTEM.md:280-287`, `523-532`).

Disposition: `[small][same-session-ok]` for the UI contract cleanup, but deferred because the overall finding set is majority `separate-recommended`.

## Verification

- `docker compose run --rm api php artisan test --filter='ApprovalServiceTest|ApprovalAutoResolveTest|ApprovalDelegationTest|ApprovalThresholdBoundaryTest|ApprovalWorkflowTest|ApprovalsWidgetTest|ApprovalDelegationAuthorityTest'` — **PASS**, 52 tests / 133 assertions. PHPUnit emitted pre-existing doc-comment metadata deprecation warnings in unrelated tests.
- `npm run typecheck` in `spa/` — **PASS**.
- Targeted `npx eslint src/pages/approvals/index.tsx src/api/approvals.ts src/types/approvals.ts --report-unused-disable-directives --max-warnings 0` in `spa/` — **PASS**.
- No source changes were made in this session; the approval-workflows directory contains audit artifacts only.

The module is released as `📋 Plan Ready`: the normal paths pass focused tests, but the remaining issues affect approval state, delegation visibility, automated decisions, cross-module data access, and production-scale query behavior.
