# M005 — Approval workflows fix log

Session: 2026-08-25 (Asia/Manila)  
Claim: `platform/approval-workflows` was atomically claimed before execution; the lock remained owned through verification.

## Disposition

All nine Plan Ready items were implemented within the approval-workflows scope. The module is ready for release as `✅ Verified`.

## Fixes

The “before” references below are the baseline (`HEAD`) locations; the “after” references are the final working-tree locations.

| Finding | Before | After |
|---|---|---|
| F-01 — resubmission/current state | `api/app/Common/Services/ApprovalService.php:41-66` deleted open rows and stored no attempt/workflow identity; `:85-163` evaluated all rows for actions and terminal state. | `api/database/migrations/0476_harden_approval_workflow_identity.php:22-123` adds and backfills attempt/current/identity fields; `api/app/Common/Services/ApprovalService.php:41-108,182-219` serializes submit, supersedes stale rows, snapshots workflow policy, and scopes helpers to current rows; `api/app/Common/Traits/HasApprovalWorkflow.php:16-31` separates current records from full history. |
| F-02 — delegation-aware inbox | `api/app/Common/Services/ApprovalBoardService.php:64-171` considered only the direct role; dashboard counters likewise matched only the direct role. | `api/app/Common/Services/ApprovalBoardService.php:235-293` includes active delegated roles and redacts cards without module visibility; `api/app/Common/Models/ApprovalDelegation.php:83-134` validates the delegator’s current role; `api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:425-433` and `api/app/Modules/Dashboard/Services/BadgeService.php:177-186` use effective roles/current rows. |
| F-03 — workflow identity for SLA policy | `api/app/Common/Services/ApprovalEscalationService.php:160-187` matched the first definition sharing step order and role. | `api/app/Common/Services/ApprovalEscalationService.php:165-218` uses the stored workflow snapshot/definition and falls back to global policy for ambiguous legacy rows; new records receive identity in `api/app/Common/Services/ApprovalService.php:80-100`. |
| F-04 — module visibility | `api/app/Common/Services/ApprovalBoardService.php:118-144` rendered every supported card to a user with the broad board permission. | `api/app/Common/Support/ApprovalTypeRegistry.php:17-107` centralizes per-module permissions; `api/app/Common/Services/ApprovalBoardService.php:160-193,274-293` hides unauthorized history and masks delegated active cards. |
| F-05 — unbounded board/sweeps | `api/app/Common/Services/ApprovalBoardService.php:69-84` loaded all pending rows and a fixed unbounded-to-callers history slice; `api/app/Common/Services/ApprovalEscalationService.php:23-27,57-61,115-119` used whole-result `get()` sweeps. | `api/app/Common/Services/ApprovalBoardService.php:52-106,206-224,350-375` applies bounded limits, truncation metadata, current-row filters, and batched source reads; `api/app/Common/Services/ApprovalEscalationService.php:25-31,61-67,121-127` uses current-row filters and `chunkById(100)`. |
| F-06 — escalation links | `api/app/Common/Services/ApprovalEscalationService.php:250-263` used a basename map that missed `EmployeeLoan` and fell back to `/admin/audit-logs`. | `api/app/Common/Support/ApprovalTypeRegistry.php:17-107` covers all supported types; `api/app/Common/Services/ApprovalEscalationService.php:297-300` uses the registry and encoded IDs with `/approvals` as the safe unknown fallback. |
| F-07 — reserved/enforced workflow lifecycle | `api/database/seeders/WorkflowSeeder.php:171-177` seeded every definition without an active/reserved state. | `api/database/seeders/WorkflowSeeder.php:171-187` marks only wired types active; `api/app/Common/Services/ApprovalService.php:53` and `api/app/Modules/Loans/Services/LoanService.php:38-40,177-181` refuse inactive definitions. |
| F-08 — automation attribution | `api/app/Common/Services/ApprovalEscalationService.php:223-245` could persist an automatic decision with a null automation actor. | `api/app/Common/Services/ApprovalEscalationService.php:260-280` requires an active configured automation actor before mutation; regression coverage is `api/tests/Feature/Approval/ApprovalAutoResolveTest.php:221-241`. |
| F-09 — SPA options/count/history UX | `spa/src/pages/approvals/index.tsx:56-68,126-127` had no options error state or server-side bounds, and actioned cards had no explicit view affordance. | `api/app/Common/Controllers/ApprovalBoardController.php:25-45`, `spa/src/api/approvals.ts:15-26`, `spa/src/types/approvals.ts:36-63`, and `spa/src/pages/approvals/index.tsx:51-247,387-390` add bounded pagination, truncation/load-more controls, retry/SLA fallback messaging, and explicit actioned-card navigation wording. |

## Verification record

- Isolated Postgres backend suite: `docker compose exec -T -e DB_DATABASE=ogami_m005_test api vendor/bin/phpunit tests/Unit/ApprovalServiceTest.php tests/Feature/Approval tests/Feature/Approvals tests/Feature/Purchasing/ApprovalWorkflowTest.php tests/Feature/Dashboard/ApprovalsWidgetTest.php tests/Feature/Dashboard/BadgeControllerTest.php` — **58 passed / 243 assertions**.
- Dedicated current-attempt regression: **1 passed / 6 assertions** (`test_current_attempt_controls_terminal_state_helpers`).
- PHP lint across all touched M005 PHP files: **passed**.
- Targeted SPA ESLint for approvals API/types/page: **passed**.
- Repository-wide SPA typecheck is blocked by an unrelated pre-existing dirty file, `spa/src/pages/crm/sales-orders/create.tsx:169` (invalid escaped template-literal character; subsequent parse errors follow). That file is outside M005 and was not modified.
- `git diff --check` reports only the same unrelated CRM file’s existing trailing whitespace at `spa/src/pages/crm/sales-orders/create.tsx:170`.
- The shared `ogami_test` database had unrelated background test workers mutating its schema, so the final backend evidence uses the dedicated `ogami_m005_test` database.

## Deferred findings

None. All F-01 through F-09 were addressed within module scope; auth-session, RBAC, and audit-activity modules were treated as read-only dependencies.
