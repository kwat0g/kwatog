# Audit: permission-scoping — 2026-09-06

## Summary

The aggregator layer is in good shape where the 2026-09 AQ-1/2/3 audit touched it: the Approval
Queue reuses each module's row scope verbatim (`ApprovalSourceScope`) on BOTH open and history
columns with masked cards for delegated out-of-scope steps, Global Search reuses the employee and
PO scopes verbatim (no TIN, soft-deletes excluded, feature toggles honoured), widget permission
stripping is shared by the plain and rich render paths, dashboard dispatch is fully
permission-derived (rarest-permission wins, no role-name branches anywhere in the module), and
the Action Center gates sources exactly like the owning modules' READ routes. The serious finding
is the inverse of the AQ defect: PO and company-loan chain approvers are INVISIBLE on the board
(their modules' row scopes have no chain-participant branch, unlike the PR policy) AND their
approve endpoints reject their role for lack of permission — step 2 of both workflows is
unreachable in normal use. Badges and the purchasing widgets still count company-wide where the
module row scope is department/self-scoped (aggregate-only exposure). Test suite for the unit
passes 566/570; the 4 failures are pollution-flakes in Outbox/Chain tests that pass in isolation.

## Findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| PS-01 | Broken process | High | M | PO step-2 (finance) and company-loan step-2 (production_manager) approvers can neither see nor act on their pending steps — chains stall | api/app/Common/Support/ApprovalSourceScope.php:89,91; api/app/Modules/Purchasing/routes.php:67; api/app/Modules/Loans/routes.php:20; api/database/seeders/WorkflowSeeder.php:48-55,68-75 | Board reuses PO/Loan row scopes that lack the chain-participant branch the PR policy has; approve endpoints need `purchasing.po.approve` / `loans.approve`, which the step roles don't hold; ApprovalService requires the step role slug. No seeded role can advance the step |
| PS-02 | Risk | Medium | M | Badge counts ignore module row scopes — company-wide counts for department-scoped roles (leaves, overtime, approvals, profile_requests) | api/app/Modules/Dashboard/Services/BadgeService.php:172-188,215-242,282-289 | `leaves` counts all PendingDept leaves for a `leave.approve_dept` holder (module list: own dept only, LeaveRequestService.php:153); `overtime` counts all pending OT (module list: dept-scoped, OvertimeService.php:173-188); `approvals` counts steps the board hides; aggregate-only leak + badge/board disagreement; no test pins it |
| PS-03 | Risk | Medium | M | `purchasing.open_prs` / `purchasing.open_pos` widgets serve company-wide aggregates to row-scoped viewers | api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:134-135; Services/Analytics/CoreWidgetAnalytics.php:254-282; api/database/seeders/DashboardRoleLayoutSeeder.php:87,109 | Scalars count ALL open PR/PO; rich `poStatusMix` gives company-wide PO status mix to any `purchasing.view` holder; department_head/impex_officer module lists are own-creations/dept-scoped. Violates the rule the codebase wrote for `loans.outstanding` (DashboardWidgetDataService.php:191-196) |
| PS-04 | Risk | Low | S | Team widgets gated on self-scoped permissions granted to EVERY role (`leave.view`, `attendance.view`) | api/database/seeders/DashboardWidgetSeeder.php:210-211; api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:123-129,415-418 | AQ gate pattern (self-scoped permission as global gate), but exposure is limited to own-department aggregate counts (no names/rows). Gate on a real team-read permission or document the deliberate aggregate exception |
| PS-05 | Other | Low | S | ApprovalTypeRegistry payroll entry + board docblock describe a surface that cannot exist | api/app/Common/Support/ApprovalTypeRegistry.php:53-60; api/app/Common/Services/ApprovalBoardService.php:18-20 | Payroll never calls ApprovalService (zero refs in api/app/Modules/Payroll); PayrollPeriod approval is its own approve/request_correction flow → no payroll approval_records ever exist; `test_payroll_cards_stay_permission_gated` pins dead behaviour |
| PS-06 | Broken process | Medium | M | Escalation/reminder notifies one arbitrary role holder; SLA auto-resolve never transitions the underlying document | api/app/Common/Services/ApprovalEscalationService.php:274-292,302-314,36-41 | `resolveCurrentApprover` = `orderBy('id')->first()` of the role: with several dept heads the reminder goes to the lowest-id user, who may be out of the step's department scope; messages carry only the class basename. Auto-resolve flips approval_records only — leave would read "approved" step while the request stays PendingDept. Dormant: no seeded workflow sets `auto_resolve_*` |
| PS-07 | Bad practice | Low | S | Board PO card summary leaks raw integer vendor_id | api/app/Common/Services/ApprovalBoardService.php:444 | `'Purchase order — vendor #'.$source->vendor_id` — raw auto-increment ID in an API response (HashID rule) |
| PS-08 | Gap | Low | S | No visibility-matrix test for badge row scope | api/tests/Feature/Dashboard/BadgeControllerTest.php | Tests permission gating and counts, but no case asserts a department_head's leaves/overtime badge follows the module row scope — the PS-02 class is unpinned (contrast: ApprovalBoardScopeTest, GlobalSearchTest, PurchaseOrderListScopeTest) |
| PS-09 | Other | Low | S | `/chains` SPA guard (crm.sales_orders.view) ≠ bottleneck API gate (dashboard.view_bottlenecks) | spa/src/routes/dashboardRoutes.tsx:96-99; api/routes/api.php:120-121 | A sales_officer opens /chains but the bottleneck panel 403s; UX inconsistency, not a leak |
| PS-10 | Other | Low | M | Test pollution: OutboxTest + WorkOrderChainDurabilityTest fail in the combined run, pass alone | api/tests/Feature/Infrastructure/OutboxTest.php; api/tests/Feature/Production/WorkOrderChainDurabilityTest.php | 4 fails / 566 pass in the unit filter run; both classes green in isolation — state leaking between tests in the chain/outbox area |

### PS-01 — PO and company-loan approval chains stall at step 2 (High, Broken process)

Two independent failures compound, and both hit the same two workflows:

1. **Visibility (this unit's surface).** `ApprovalSourceScope` faithfully reuses each module's row
   scope — but `PurchaseOrderAccessPolicy::visibleTo` (api/app/Modules/Purchasing/Policies/PurchaseOrderAccessPolicy.php:38-62)
   admits only `purchasing.po.approve` holders, department heads (own creations + dept-PR-linked POs)
   and own creations; `LoanAccessPolicy::visibleTo` (api/app/Modules/Loans/Policies/LoanAccessPolicy.php:25-52)
   admits only system_admin/finance_officer/hr_officer, department heads (own dept) and self.
   Neither has the **chain-participant branch** that `PurchaseRequestAccessPolicy::visibleTo`
   explicitly grew (`orWhereHas('approvalRecords', …)` for plant-wide step roles). So the seeded
   step-2 approvers — `finance_officer` on purchase_order, `production_manager` on company_loan —
   see zero cards on the Approval Queue: finance never creates POs; the production manager's loan
   list is self-only. The `approvals` badge, which counts pending `approval_records` by role slug
   with no row scope (BadgeService.php:176-187), still tells them work waits — badge says N, board
   shows nothing.
2. **Action (cross-module wiring).** Even a visible step could not be actioned: the PO approve route
   requires `purchasing.po.approve` (api/app/Modules/Purchasing/routes.php:67), held only by
   purchasing_officer and system_admin — finance_officer has NO purchasing permissions
   (RolePermissionSeeder roleCatalog). The loans approve route requires `loans.approve`
   (api/app/Modules/Loans/routes.php:20), which production_manager does not hold. And
   `ApprovalService::userMayActFor` requires the actor's role slug to equal the step's role slug
   (no permission wildcard), so purchasing_officer/system_admin cannot stand in either.

Net effect: every submitted PO stalls at the finance step and every company loan at the manager
step in normal use; only a manually-created delegation (finance_officer → a po.approve holder)
unsticks it. This is exactly the defect class the codebase already fixed once: the M036 comment in
RolePermissionSeeder (production_manager's PR step, "the approve route rejected the only role
that step accepts and every submitted PR stalled at step 2") and L-37 for return_management.
Fix needs both halves: give step roles the approve permission (or an `*.approve_step` slug) AND
add the chain-participant branch to the PO/Loan row scopes so the board surfaces the queue.

### PS-02 — Badges count company-wide where modules are row-scoped (Medium, Risk)

The badge layer is the one aggregator that never got the AQ-1/2/3 treatment. Four badges gate on
the module READ permission and then count globally: `leaves` (a department_head holding only
`leave.approve_dept` sees the count of every department's PendingDept requests, while
LeaveRequestService scopes their list to own department + self), `overtime` (same shape —
OvertimeService::list:173-188 scopes department heads to their department), `approvals` (counts
pending steps whose underlying rows the board hides — e.g. other departments' leave/PR steps
routed to the department_head slug), and `profile_requests` (gated on `hr.employees.view`, the
department-tier permission, counting every pending profile request company-wide). Exposure is
aggregate counts only — no rows, names or amounts — which keeps this at Medium; the operational
damage is that badge and board disagree (badge 7, board 3) and that company-wide activity levels
are disclosed to department-scoped roles. No test pins badge counts to a row scope.

### PS-03 — Purchasing widgets hand company-wide figures to row-scoped viewers (Medium, Risk)

`purchasing.open_prs` and `purchasing.open_pos` (scalar, DashboardWidgetDataService.php:134-135)
count every open PR/PO in the company behind the bare `purchasing.view` gate, and the rich path
(`CoreWidgetAnalytics::poStatusMix`, CoreWidgetAnalytics.php:254-282) adds a company-wide PO
status distribution. `purchasing.view` is held by department_head, production_manager and
impex_officer, whose module lists are scoped to own creations (+ department reach for the head;
+ chain rows for the manager's PRs only). department_head's default layout seeds
`purchasing.open_prs` and impex_officer gets both tiles, so the over-exposure is seeded, not
theoretical. The file already contains the exact rule this violates — the `loans.outstanding`
comment: "A company-wide count here would hand that role figures its own module refuses it."
Aggregates only (poStatusMix emits status + count; no vendor names or amounts), hence Medium.
Fix: run the PR/PO counts through the access policies (or scope to the caller's visible set),
mirroring `outstandingLoans()`.

## Cross-module flags

- **Purchasing unit**: PS-01 half 2 — PO approve route permission vs workflow step role
  (finance_officer cannot hold step 2); also `PurchaseOrderAccessPolicy` needs the
  chain-participant branch its PR sibling has. Related to PU-01/PU-02 (PO surface bypasses its own
  policy) — the board/search reuse is faithful; the policy itself is under-scoped for chain work.
- **Loans unit**: PS-01 half 2 — company_loan step 2 (production_manager) lacks `loans.approve`;
  LoanAccessPolicy has no chain-participant branch. Note also LoanAccessPolicy's role-slug global
  tier (`isGlobal`) is the last role-name branch among the reused scopes — fine while reused
  verbatim, but it is the seam that drifts.
- **Attendance unit**: `OvertimeService::list:173-188` hand-rolls a role-slug ladder
  (`system_admin`/`hr_officer`) instead of DepartmentScope — the module scope PS-02 should reuse
  is itself role-name-coupled (REC-11's stated anti-pattern).
- **Workflow seeding**: WorkflowSeeder's own comment lists RESERVED-but-unwired workflows
  (bill_payment, work_order, ncr, …) — "wire or drop before pilot" still open.
- **Chain/infra test units**: PS-10 pollution flake (OutboxTest, WorkOrderChainDurabilityTest
  green alone, red in suite).

## What was NOT checked

- CalendarAggregatorService (adjacent aggregator, calendar.view granted to every role) — spot
  check only; it does apply department/self scoping to leave entries, but no full matrix run.
- ActivityFeedService, NotificationService/Digest internals, AlertEngineService alert-creation
  logic (read paths only, via Action Center/Badge consumers).
- Role-dashboard content correctness (AdminDashboardService, HrDashboardService, etc.) beyond
  confirming route permission gates and no role-slug branches.
- Module-internal correctness of the reused scopes (audited as dependencies; defects flagged
  above) and the PO/loan approve endpoints' FormRequests.
- Full Playwright/SPA visual behaviour; SPA checked at guard/route/API-client level only.
- The `dashboard.chain_recovery.manage` replay semantics under concurrent replay (service
  exception paths read; no race exercise).
