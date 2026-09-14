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

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Re-audit verdict

The 2026-09-10/11 approval-chain remediation is present at this commit: PO and loan row
scopes now admit current plant-wide step participants, the seeded step roles hold the
permissions accepted by their act routes, and the drift tests cover the enforced workflows.
The earlier aggregator defects around badges, purchasing widgets, the payroll registry,
escalation recipient selection/auto-resolution, and the missing badge matrix test remain
unresolved. No scoped application files differ from `56e0d431`; the worktree changes seen
elsewhere are unrelated and were not modified.

### Prior finding status

| ID | Status at 56e0d431 | Current evidence |
|---|---|---|
| PS-01 | Resolved in source; regression coverage present | `PurchaseOrderAccessPolicy.php:49-79` and `LoanAccessPolicy.php:83-93` add chain-participant visibility; `RolePermissionSeeder.php:579-585,700-725` grants the PO/loan act permissions; `ApprovalBoardScopeTest.php:173-225` and `ApprovalChainRolePermissionDriftTest.php:65-150` pin the repaired path. |
| PS-02 | Unresolved | `BadgeService.php:171-230,234-242,281-289` still counts approval, leave, overtime, and profile rows without the owning module row scope. |
| PS-03 | Unresolved | `DashboardWidgetDataService.php:134-135` and `CoreWidgetAnalytics.php:254-284` still count PR/PO rows company-wide behind `purchasing.view`; seeded layouts still expose these keys to department-scoped viewers at `DashboardRoleLayoutSeeder.php:85-90,107-110`. |
| PS-04 | Unresolved | `DashboardWidgetSeeder.php:204-211` still gates team widgets on self-scoped `leave.view`/`attendance.view`; `DashboardLayoutService.php:242-263` makes those widgets pickable by every holder, while `DashboardWidgetDataService.php:123-129` returns department aggregates. |
| PS-05 | Unresolved/dead surface | `ApprovalTypeRegistry.php:53-60` still advertises payroll approval cards, but payroll has its own approve route at `api/app/Modules/Payroll/routes.php:68-73` and no `ApprovalService::submit()` path. |
| PS-06 | Unresolved | `ApprovalEscalationService.php:302-329` still chooses the lowest-ID role holder, and `autoResolveRecord()` at `233-292` changes only approval records, not the underlying document lifecycle. |
| PS-07 | Resolved | PO summaries now use `vendor_name` at `ApprovalBoardService.php:410-450`; the prior raw `vendor_id` card text is gone. |
| PS-08 | Unresolved test gap | `BadgeControllerTest.php:325-373` tests raw approval counts and severity, but still has no department/self visibility matrix for badges. |
| PS-09 | Unresolved, with stronger widget evidence | `/chains` remains guarded by `crm.sales_orders.view` at `spa/src/routes/dashboardRoutes.tsx:96-99`, while the bottleneck API and the `chain.stage_breakdown` widget use `dashboard.view_bottlenecks` (`api/routes/api.php:118-121`; `DashboardWidgetSeeder.php:183`). `production_manager` is a seeded holder of the latter but not the former, so its widget link can land on a denied page. |
| PS-10 | Not reverified | The prior combined-suite pollution evidence was not rerun because this audit was read-only and tests were explicitly prohibited. |

### Findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Reproduction |
|---|---|---|---|---|---|---|
| PS-11 | Broken process | Medium | S | Salary-adjustment approval cards link to a malformed employee URL | `api/app/Common/Support/ApprovalTypeRegistry.php:65-74`; `api/app/Common/Services/ApprovalBoardService.php:337-348,381-391` | The registry correctly declares `link_record=false` and `/hr/employees`, but both board card builders concatenate `$meta['link'].$hashId` instead of calling `ApprovalTypeRegistry::linkFor()`. A pending or completed salary-adjustment card therefore emits `/hr/employees<hash>` rather than `/hr/employees`; selecting it cannot reach the tab where the `hr.salary_adjustments.act` action is available. |
| PS-12 | Risk | Medium | S | Approval board can surface soft-deleted asset and return-request records | `api/app/Common/Support/ApprovalSourceScope.php:49-56,72-81`; `api/app/Common/Services/ApprovalBoardService.php:146-152,168-171,405-419`; `api/app/Modules/Assets/Models/Asset.php:21-26`; `api/app/Modules/ReturnManagement/Models/ReturnRequest.php:25-34` | Asset and return requests are treated as unscoped because they are absent from `ApprovalSourceScope::hasScope()`, but both owning models use `SoftDeletes`. The board then loads sources with `DB::table()` without `whereNull('deleted_at')`. Create an approval record for a soft-deleted asset/RMA and call the board as an `assets.view`/`return_management.view` holder: the owning Eloquent list would exclude the row, while the board can render its number, summary, amount, and link. |
| PS-13 | Risk | Medium | M | Chain bottlenecks disclose every configured audience to any bottleneck-permission holder | `api/app/Common/Controllers/ChainBottleneckController.php:22-57`; `api/routes/api.php:118-121`; `api/app/Common/Services/ChainBottleneckService.php:35-50,782-806` | The API runs `detectAll()` and applies no audience restriction when `?audience` is absent. The route requires only `dashboard.view_bottlenecks`, not the source module permission, and the SPA calls it without an audience at `spa/src/pages/chains/index.tsx:121-124`. A finance or production user can therefore receive QC, purchasing, payroll, and other groups' document numbers/status/hash IDs, despite each detector carrying an intended role audience in settings. |
| PS-14 | Risk | Low | S | Chain bottleneck synthetic document numbers expose raw integer IDs | `api/app/Common/Services/ChainBottleneckService.php:391-399,488-512`; `api/tests/Feature/Chain/ChainBottleneckServiceTest.php:268-276` | The movement detector builds `MOV-{$row->id}` and the payroll detector builds `PAY-{$row->id}` before the shared mapper. `entity_id` is hash-encoded, but `doc_number` remains a raw auto-increment-derived identifier in the API response and SPA display. The existing test explicitly asserts `MOV-{$stale->id}`, pinning the leak. |
| PS-15 | Broken process | Medium | M | Alert read state is global, so one user suppresses another user's unread alert | `api/app/Common/Models/Alert.php:26-34,41-48`; `api/app/Common/Services/AlertEngineService.php:166-175`; `api/app/Common/Controllers/AlertController.php:90-98`; `api/routes/api.php:108-115` | Alerts are global operational rows, but `is_read` is one column on the alert. User A can `PATCH /api/v1/alerts/{hash}/read`; `markRead()` sets the shared row to true, and User B's `/alerts/unread-count` then excludes it. The same shared flag is rendered in Action Center at `ActionCenterService.php:198-210`. There is no per-user read ledger comparable to Laravel notifications. |
| PS-16 | Gap | Medium | M | Approval escalation command reports success after all per-record notification/update failures | `api/app/Common/Services/ApprovalEscalationService.php:31-54,67-97,127-148`; `api/app/Console/Commands/RunApprovalEscalations.php:19-29` | Reminder, escalation, and auto-resolve loops catch every `Throwable`, log it, and return only successful counts. The command always returns `SUCCESS`. If notification delivery or the row update fails for every stale record, the scheduled run prints zero counts and exits 0, indistinguishable from an idle queue; no failed-record count or non-zero outcome is exposed. |
| PS-17 | Risk | Medium | M | HR dashboard panels use self-scoped permissions for company-wide queries, including an ungated global action count | `api/app/Modules/Dashboard/Services/HrDashboardService.php:48-68,246-278,332-392`; `api/tests/Feature/Dashboard/DashboardPanelGateTest.php:187-204` | `pending_leaves` and `leave_calendar_week` are gated by `leave.view`, which is included in every role's self-service grant, but query all leave rows and return employee names. `pending_my_action` has no permission gate and `hrPendingMyAction()` counts all pending HR leave/profile/clearance rows despite its name. A custom role with `dashboard.hr.view` plus the ordinary self-service grant, a supported permission-derived dashboard case, receives company-wide HR queue/calendar data. The existing panel test asserts presence by grant but does not assert row scope. |
| PS-18 | Gap | Medium | M | PR/PO approval submission has no immediate checker notification | `api/app/Common/Services/ApprovalService.php:39-105`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:354-359`; `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:499-514`; `api/app/Common/Services/ApprovalEscalationService.php:21-97` | Shared submission only creates approval records and the PR/PO services update status; neither path sends an in-app notification or dispatches an approval-submitted event. The only shared notification path is a later reminder/escalation sweep. Loan and leave have explicit submitted listeners (`NotifyOnLoanSubmitted.php:24-48`, `NotifyOnLeaveSubmitted.php:18-44`), but Purchasing has no corresponding submitted listener. Submit a PR/PO: the checker gets a board/badge item, but no notification until the configured stale threshold, creating a silent approval queue. |

### Clean areas confirmed

- Approval-board open and history cards share the owning row-scope decision, and delegated out-of-scope cards are redacted rather than exposing source data (`ApprovalBoardService.php:125-153,196-249,321-334`).
- The PO and loan participant-scope remediation is coherent across policy, seeded permissions, board visibility, and the approval-chain drift tests; PS-01 is no longer an open stall at the audited source level.
- Global Search uses Eloquent models for soft-delete exclusion, reuses `DepartmentScope` for employees and `PurchaseOrderAccessPolicy` for POs, honors module toggles, escapes wildcard input, and emits hash IDs (`GlobalSearchService.php:51-61,171-210,236-266,380-431`).
- Dashboard landing dispatch is permission-derived and rarity-ordered without role-name branches (`DashboardDispatchService.php:32-79`), while plain and rich widget layouts share the same permission strip (`DashboardLayoutService.php:61-79,274-311`).
- Action Center no longer duplicates approval work or accepts fabricated approval keys; source permissions, current-queue existence, HashID parsing, and SQL-fault rendering are covered by `ActionCenterControllerTest.php:97-115,194-285,368-402`.
- Dashboard route/API gates, lazy loading, and the main loading/error/empty states are present for the audited dashboard, approval, action-center, chain-recovery, and alert pages. The frontend guards remain UX-only with independent backend enforcement, as required.
- KPI visibility is centralized through `KpiSnapshotService::MODULE_PERMISSIONS`, copied into widget seed permissions, and checked by `WidgetSeedIntegrityTest.php:216-235`.

### Verification limits

- This was a source-reading audit only. No PHPUnit/Vitest/Playwright tests, Docker commands, Artisan commands, database queries, browser sessions, queue runs, scheduler runs, or concurrency harnesses were executed.
- The PS-10 test-pollution status is therefore not independently reconfirmed at this commit.
- No live role matrix, feature-toggle matrix, notification delivery/provider result, or production-sized query plan was available; the reproductions above are static call-path reproductions grounded in current source and existing test fixtures.
- Cross-module row-scope implementations were read only where required to verify aggregator reuse. Defects in a module's own list/detail authorization remain flagged as dependencies rather than re-audited as module findings.

### No code change

No application code, migration, test, registry, roadmap, or other audit content was changed. This section is the only append made to this file.
