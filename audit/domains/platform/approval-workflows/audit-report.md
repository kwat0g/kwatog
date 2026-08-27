# M005 — Approval workflows audit report

- Audit session: 2026-08-27
- Claimed card: `platform/approval-workflows` (M005)
- Claim command: `bash audit/scripts/claim-module.sh platform approval-workflows`
- Tier/surface: Tier 1 / M
- Dependencies: `auth-session`, `rbac`, `audit-activity`
- Inherited status: `🔁 Needs Re-audit`
- Session disposition: `📋 Plan Ready`

## Scope and evidence reviewed

This re-audit covered the inherited module audit artifacts, current implementation,
consumers, tests, working-tree diff, and modification times. The implementation review
included `ApprovalService`, `ApprovalBoardService`, `ApprovalEscalationService`, the
approval/delegation models, signature builder, workflow/link registry, routes, scheduler,
role/permission and workflow/settings seeders, and the Leave, Purchasing, Loans, Payroll,
HR salary-adjustment, and ReturnManagement consumers. The SPA board API/types/page,
dashboard worklist, focused API tests, inherited docs, and design-system requirements were
also checked.

The coordinator's generated `audit/00-MODULE-REGISTRY.md` change was present before this
session and was not regenerated or edited. There was no source/test diff in this module at
the start of the session; current mtimes show the common approval source and focused tests
were last bulk-touched on 2026-08-26, while the inherited audit artifacts predate this
session.

## Pass 1 — discovery

The normal approval path is internally coherent for the cases covered by the inherited
tests: submission snapshots the active workflow and stamps attempt/current/version fields
(`api/app/Common/Services/ApprovalService.php:39-105`), decisions lock the current pending
row (`api/app/Common/Services/ApprovalService.php:119-176`), and terminal helpers filter
current rows (`api/app/Common/Services/ApprovalService.php:188-219`). The focused tests
also confirm current-attempt resubmission behavior, threshold boundaries, delegation
windows, and the bounded board path.

Discovery found that the board registry is narrower than the live approval producer graph,
and that the scheduler operates on row state rather than workflow-step reachability. The
findings are detailed below under the pass where their primary risk appears.

### R-01 — Missing: live return-request approvals are absent from the board/link registry

ReturnManagement explicitly submits `return_request` records for the Admin and approval-board
surfaces (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:563-581`), and
the workflow is active in the seeder (`api/database/seeders/WorkflowSeeder.php:159-179`).
`ApprovalTypeRegistry::TYPES` contains only Leave, PurchaseRequest, PurchaseOrder,
EmployeeLoan, and PayrollPeriod (`api/app/Common/Support/ApprovalTypeRegistry.php:19-61`),
so `ReturnRequest` has no board kind, source-table metadata, permission boundary, or direct
link. Unknown classes deliberately fall back to `/approvals`
(`api/app/Common/Support/ApprovalTypeRegistry.php:112-116`), which is still unable to
render an RMA because the controller and SPA accept only the five registered kinds
(`api/app/Common/Controllers/ApprovalBoardController.php:24-30`,
`spa/src/types/approvals.ts:1-5`).

This drops a live approval from `my_action`/history and makes escalation links for it land
on a board that cannot show the record.

Classification: **Missing**.

Disposition: `[medium][separate-recommended]` — align the registry, per-module row policy,
controller validation, SPA types/routes, escalation links, and ReturnManagement regression
coverage as one cross-module contract.

## Pass 2 — hardening

### R-02 — Broken: SLA sweeps process unreachable future steps

Submission creates every non-threshold workflow step as `pending`
(`api/app/Common/Services/ApprovalService.php:85-103`), while normal decisions expose only
the earliest pending step through `nextStep()` (`api/app/Common/Services/ApprovalService.php:193-195`).
The reminder, escalation, and auto-resolve queries select every current pending row without
requiring it to be the next reachable step
(`api/app/Common/Services/ApprovalEscalationService.php:25-29`, `61-65`, `121-125`).
The board deduplicates to the earliest row only for presentation
(`api/app/Common/Services/ApprovalBoardService.php:108-115`); that does not constrain the
scheduler.

Runtime checks on `ogami_test_m005_agent_d` reproduced both effects. A newly submitted
two-step Leave chain caused `runReminders()` to return `2` and stamp `reminder_sent_at`
on both step 1 and step 2. When only step 2 was escalated, `runAutoResolve()` approved step
2 while step 1 remained pending. A later reachable step can therefore consume its reminder,
be escalated, or be auto-decided before its predecessor is approved.

Classification: **Broken**.

Disposition: `[large][separate-recommended]` — define an authoritative active-step query or
state, apply it to every scheduler operation, and add multi-step reminder/escalation/
auto-resolve regressions.

### R-03 — Broken: automatic approval decisions do not advance the source lifecycle

`autoResolveRecord()` writes the approval row and, for rejection, skips later approval rows,
but never invokes the owning module's transition, side effects, or outbox/event path
(`api/app/Common/Services/ApprovalEscalationService.php:274-292`). Normal Leave approval,
for example, updates the request status, approver attribution, balance, attendance, and
outbox event after the shared decision (`api/app/Modules/Leave/Services/LeaveRequestService.php:303-345`);
Loans similarly transition the source to Active only after the chain is fully approved
(`api/app/Modules/Loans/Services/LoanService.php:204-230`).

The isolated runtime probe enabled the seeded auto-resolve setting for a Leave chain and
observed: two approval rows `approved`, `isFullyApproved() === true`, but the LeaveRequest
still at `pending_dept`. The same mismatch would strand rejection and skip downstream
domain cleanup. The feature is disabled by default, but it is an exposed admin-configurable
status transition (`api/database/seeders/SettingsSeeder.php:124-145`).

Classification: **Broken**.

Disposition: `[large][separate-recommended]` — design a domain-aware automation adapter or
disable approve/reject auto-resolution until every supported consumer has an atomic,
idempotent lifecycle hook and event/outbox behavior.

### R-04 — Broken: auto-resolve can overwrite a human decision after the read

`runAutoResolve()` loads stale candidate models in a chunk and later passes each model to
`autoResolveRecord()` (`api/app/Common/Services/ApprovalEscalationService.php:120-139`).
The transaction in `autoResolveRecord()` updates by model identity without re-locking or
rechecking `action = pending` and `is_current = true`
(`api/app/Common/Services/ApprovalEscalationService.php:274-281`). Human approve/reject
does lock and re-read the pending row before writing
(`api/app/Common/Services/ApprovalService.php:121-142`). If the human decision commits
between the scheduler's query and its update, the scheduler can overwrite the terminal
decision.

Classification: **Broken**.

Disposition: `[medium][separate-recommended]` — lock and re-read inside the auto-decision
transaction, use a conditional current-pending update, and add a two-connection race test.

### R-05 — Broken: the board bypasses module row-level visibility

The board endpoint is intentionally cross-cutting and is granted to every seeded role
(`api/routes/api.php:140-146`, `api/database/seeders/RolePermissionSeeder.php:835-840`,
`879-883`). For each registered type, `userCanView()` accepts a broad module permission
(`api/app/Common/Support/ApprovalTypeRegistry.php:101-109`), and the board then returns
full cards for any matching permission without invoking the source module's row policy
(`api/app/Common/Services/ApprovalBoardService.php:158-195`, `291-302`, `335-345`).

This conflicts with Leave's explicit row-level controller scope: `leave.view` is part of
self-service permissions (`api/database/seeders/RolePermissionSeeder.php:775-789`), while
the Leave show route permits only the request owner, the owner's department head, or HR
(`api/app/Modules/Leave/Controllers/LeaveRequestController.php:65-82`).

The isolated runtime check created a foreign Leave request and queried the board as an
ordinary employee. The employee received an `awaiting_others` card containing the leave
number, direct record link, and date-range summary, even though the Leave controller would
reject that same foreign record. The same pattern affects financial history cards and
remarks through the actioned-card path.

Classification: **Broken**.

Disposition: `[large][separate-recommended]` — choose and document the board's company-wide
versus row-scoped policy, then enforce it with per-type policy adapters or deliberate
masking and tests for employee, department head, finance, HR, and admin views.

### R-06 — Broken: delegated board cards can dead-end at the entity API

The shared service correctly grants authority to an active delegate
(`api/app/Common/Services/ApprovalService.php:222-235`), and the board includes delegated
role slugs (`api/app/Common/Services/ApprovalBoardService.php:229-241`). But a delegated
user without the underlying module permission receives a redacted card whose link is back
to `/approvals` (`api/app/Common/Services/ApprovalBoardService.php:275-287`). The actual
Purchasing approve route still requires `purchasing.pr.approve`
(`api/app/Modules/Purchasing/routes.php:19-37`), and the Leave action routes similarly
require `leave.approve_dept` or `leave.approve_hr`
(`api/app/Modules/Leave/routes.php:34-46`).

The existing delegation test proves only a direct service call by an employee delegate
(`api/tests/Feature/Approvals/ApprovalDelegationTest.php:69-95`); it does not prove that a
delegate can follow the board card and complete the HTTP action. The resulting UI either
loops back to the board or receives a route-level 403 despite the shared service granting
the delegated authority.

Classification: **Broken**.

Disposition: `[medium][separate-recommended]` — make delegated authority and module route/
row policy agree, choose a safe delegated-card data contract, and add HTTP approve/reject
tests for active, expired, revoked, and role-changed delegations.

### R-07 — Broken: SLA notifications reach only the first direct role holder

Approval decisions are role-based: any active user whose role matches may act, and active
delegates may also act (`api/app/Common/Services/ApprovalService.php:222-235`). The board
uses the same role/delegation set for every caller (`api/app/Common/Services/ApprovalBoardService.php:229-241`).
The scheduler instead resolves one direct-role user with `orderBy('id')->first()` and never
consults `ApprovalDelegation::activeDelegatesFor()`
(`api/app/Common/Services/ApprovalEscalationService.php:302-313`); reminders send to that
single user (`api/app/Common/Services/ApprovalEscalationService.php:31-45`). Escalations
likewise start from only that one approver (`api/app/Common/Services/ApprovalEscalationService.php:67-87`).

When a role has multiple eligible approvers, other direct approvers and all delegated
covering users receive no reminder/escalation even though they can act on the row. This is
an authority/notification mismatch, not merely a UI preference.

Classification: **Broken**.

Disposition: `[medium][separate-recommended]` — either assign one explicit approver or
notify the complete eligible audience, including active delegates, with deduplication and
recipient-count tests.

## Pass 3 — polish, documentation, and coverage

### R-08 — Incomplete: Payroll is advertised as a board kind without a shared approval producer

`ApprovalTypeRegistry` and the board controller expose `payroll`
(`api/app/Common/Support/ApprovalTypeRegistry.php:53-60`,
`api/app/Common/Controllers/ApprovalBoardController.php:24-30`), but the seeded Payroll
workflow is inactive (`api/database/seeders/WorkflowSeeder.php:77-83`, `171-188`).
`PayrollPeriod` does not use `HasApprovalWorkflow`
(`api/app/Modules/Payroll/Models/PayrollPeriod.php:19-22`), and payroll period approval is
a separate status/audit implementation (`api/app/Modules/Payroll/Services/PayrollPeriodService.php:937-1000`),
not an `ApprovalService::submit()` producer. The options endpoint and SPA therefore offer a
kind that normally has no `approval_records` rows and cannot represent the actual payroll
maker-checker flow.

Classification: **Incomplete**.

Disposition: `[medium][separate-recommended]` — either remove payroll from the shared board
or build an explicit adapter for the custom PayrollPeriod lifecycle; test the chosen contract.

### R-09 — Incomplete: inherited schema documentation omits the current approval contract

`docs/SCHEMA.md` still describes only the original workflow and approval columns
(`docs/SCHEMA.md:42-46`). The current schema adds `is_active` to workflow definitions and
attempt/current/workflow-definition/version/snapshot fields to approval records
(`api/database/migrations/0476_harden_approval_workflow_identity.php:14-39`), plus reminder,
escalation, and auto-resolution state represented by the model
(`api/app/Common/Models/ApprovalRecord.php:16-35`). The docs also do not describe the
delegation table/authority contract.

Operators and future auditors following the inherited schema map can therefore miss the
fields that determine current-attempt selection, workflow policy attribution, and SLA
state.

Classification: **Incomplete**.

Disposition: `[small][separate-recommended]` — update the shared schema and approval-pattern
docs in a documentation-focused session after the final active-step/board contract is
chosen.

### R-10 — Missing: focused tests do not lock the discovered cross-module invariants

`ApprovalBoardTest` creates only synthetic PurchaseRequest rows and asserts broad access
using another PurchaseRequest (`api/tests/Feature/Approvals/ApprovalBoardTest.php:34-96`);
it has no Leave row-scope, RMA registry, Payroll adapter, or delegated HTTP-action case.
`ApprovalAutoResolveTest` uses synthetic `TestApprovable` records and checks ledger actions
(`api/tests/Feature/Approval/ApprovalAutoResolveTest.php:33-55`, `149-180`), but has no
active-step ordering, source-lifecycle, concurrency, or scheduler-recipient assertion.
`ApprovalDelegationTest` covers the shared service and delegation CRUD, not an entity action
route (`api/tests/Feature/Approvals/ApprovalDelegationTest.php:69-95`, `185-224`). There is
also no test reference to `ApprovalTypeRegistry` in the approval test set.

The green baseline is therefore insufficient evidence for the security and state contracts
above.

Classification: **Missing**.

Disposition: `[medium][separate-recommended]` — add regression tests alongside each chosen
implementation, with at least one two-connection race and one real Leave/PR/RMA source
lifecycle case.

### R-11 — Polish: the “Awaiting others” card uses an action CTA

The board renders `ActiveCard` for both “My action required” and “Awaiting others”
(`spa/src/pages/approvals/index.tsx:148-186`), while the shared card footer always says
“Open record to act” (`spa/src/pages/approvals/index.tsx:346-349`). A user viewing a row that
must be decided by another role is given inaccurate action wording. Terminal cards already
use distinct “Open record to view” language (`spa/src/pages/approvals/index.tsx:388-390`).

Classification: **Polish**.

Disposition: `[small][same-session-ok]` — pass a view/action mode to the card and add a
focused rendering assertion. Deferred because the overall M005 plan is not majority
same-session-safe.

## Inherited findings re-audited as resolved

The current source and focused tests confirm that the inherited attempt/version, board
delegation classification, workflow snapshot policy lookup, bounded board/scheduler scans,
actor requirement, and registered-type escalation-link fixes are present. Examples include
current-row helpers (`api/app/Common/Services/ApprovalService.php:188-219`), workflow
snapshot identity (`api/app/Common/Services/ApprovalService.php:78-103`), bounded board and
chunked scheduler queries (`api/app/Common/Services/ApprovalBoardService.php:52-105`,
`api/app/Common/Services/ApprovalEscalationService.php:21-147`), and the active-actor guard
(`api/app/Common/Services/ApprovalEscalationService.php:261-281`). They are not repeated as
open findings here.

The inherited fix log's Purchasing F-011 remains a separate Purchasing/business decision
about a dead department-head auto-approval branch. This session did not modify or claim that
dependency.

## Verification

All API commands below used `DB_DATABASE=ogami_test_m005_agent_d`; the shared `ogami_test`
database was not used.

- `migrate:fresh --seed --env=testing --force` — **PASS** on the isolated database.
- Focused core suite (`ApprovalServiceTest`, `ApprovalAutoResolveTest`, `ApprovalBoardTest`,
  `ApprovalDelegationTest`, `ApprovalDelegationAuthorityTest`) — **44 passed, 93 assertions**.
- Focused consumer suite (`LeaveRequestVisibilityTest`, `LeaveRequestHardeningTest`,
  `Purchasing/ApprovalWorkflowTest`, `ApprovalThresholdBoundaryTest`,
  `Dashboard/ApprovalsWidgetTest`) — **33 passed, 119 assertions**.
- Runtime probes on the same DB reproduced R-02, R-03, and R-05 as described above.
- Approval PHP lint for all common approval/delegation classes — **PASS**.
- SPA approval-file ESLint — **PASS**.
- Full SPA `npm run typecheck` — **PASS**.

No source or test fixes were implemented in this session. The module remains Plan Ready
because the open work is dominated by cross-module authorization/state policy, scheduler
semantics, and concurrency design.
