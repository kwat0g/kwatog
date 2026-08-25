# M023 — Separation and final pay audit report

Audit date: 2026-08-25  
Status: 📋 Plan Ready  
Release recommendation: separate implementation work required

This is a re-audit of the current shared worktree. The prior report was based on
`HEAD b269eafd`, but M023 source files had substantial uncommitted changes, so
the previous findings were not treated as authoritative.

## Executive assessment

The primary direct-separation bypass has been removed from the current surface:
the employee page now calls the canonical `POST /hr/employees/{employee}/separation`
route, and `EmployeeService::update()` rejects direct status changes. Initiation,
checklist signing, and finalization use database transactions and row locks;
outbox records make the initiation/completion events durable; and final-pay
arithmetic uses the shared decimal-string `Money` helper.

M023 is still blocked for production use. Checklist rows carry department names
but signing is authorized only by one global permission, the seeded separation
workflow is explicitly reserved rather than enforced, and HR/Finance maker-checker
boundaries are not represented. The clearance enum declares cancellation, but no
cancel, restart, blocked-item recovery, or clearance state machine exists, and
payroll still treats a cancelled clearance as an authoritative separation date.
Final-pay computation can be called before clearance completion, while
finalization rejects outstanding loans even though the poster contains a loan
deduction path. History serialization, checklist validation, notification links,
list/resource contracts, and the frontend's cancelled/blocked/error states remain
incomplete.

## Findings

### M023-F001 — P1, Broken: clearance item ownership is not enforced server-side

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: The configured checklist assigns items to Production, Warehouse,
  Maintenance, Finance, HR, and IT at `api/database/migrations/0312_seed_separation_clearance_checklist_setting.php:12-25`.
  The item route has only the global `hr.clearance.sign` middleware at
  `api/app/Modules/HR/routes.php:274-275`, and the controller repeats only that
  same permission check at `api/app/Modules/HR/Controllers/SeparationController.php:60-73`.
  `api/app/Modules/HR/Services/SeparationService.php:214-235` finds the first
  matching key and marks it cleared; the “soft auth check” comment at
  `:228-230` is not an authorization branch.
- Impact: any user with the sign permission can sign any department's item.
  The JSON department label is not connected to the signer's role, employee
  department, or row scope, so least-privilege ownership is not a server-side
  invariant.
- Recommendation: define one authoritative role/item matrix, persist or derive
  item ownership, enforce employee/department scope before mutation, and add
  negative tests for every cross-department signing attempt.

### M023-F002 — P1, Missing: separation workflow and maker-checker permissions are not wired

- Classification: Missing
- Tags: [large] [separate-recommended]
- Evidence: `WorkflowSeeder` labels `separation_clearance` as RESERVED and not
  wired at `api/database/seeders/WorkflowSeeder.php:14-21`, although its intended
  department steps are seeded at `:141-149`. Compute and finalize share the same
  `hr.separation.finalize` permission in `api/app/Modules/HR/routes.php:276-279`
  and `api/app/Modules/HR/Controllers/SeparationController.php:80-92`.
  `hr_officer` receives the entire separation module at
  `api/database/seeders/RolePermissionSeeder.php:459-475`; Finance is not given
  that module in its permission set at `:499-532`; and
  `SeparationService::finalize()` has no initiator/checker comparison at
  `api/app/Modules/HR/Services/SeparationService.php:271-342`.
- Impact: the documented department/Finance flow is not executable through the
  seeded roles, while an HR user with the finalization permission can compute and
  finalize the same separation. The inactive workflow definition can also make a
  UI or operator believe approval exists when the service does not consult it.
- Recommendation: decide the approved role/action matrix, separate view/sign/
  compute/finalize capabilities, enforce maker-checker server-side, and either
  wire the workflow through the canonical approval service or remove the reserved
  definition before pilot.

### M023-F003 — P1, Broken: cancellation, restart, blocked-item recovery, and payroll compensation are absent

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: `ClearanceStatus` declares `pending`, `in_progress`, `completed`,
  `finalized`, and `cancelled` at `api/app/Modules/HR/Enums/ClearanceStatus.php:7-23`,
  but the only clearance mutations registered at
  `api/app/Modules/HR/routes.php:266-280` are sign, compute, and finalize.
  Initiation writes `in_progress` directly and moves the employee to `on_leave`
  at `api/app/Modules/HR/Services/SeparationService.php:114-127`; signing only
  rejects terminal rows and promotes an all-cleared aggregate at `:210-250`.
  The new transition table at `api/app/Modules/HR/Support/EmployeeStateMachine.php:11-25`
  governs employee status, not clearance status. Payroll's authoritative-date
  lookup filters only `deleted_at` and `separation_date` at
  `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:597-609`, not
  `status != cancelled`.
- Impact: an operator cannot cancel or recover a stalled separation, and a
  cancelled row can still cap payroll. The employee can remain `on_leave` with
  no supported compensating transition, while `blocked` is documented in the
  checklist shape but has no resolution path.
- Recommendation: add a centralized clearance transition map and guarded
  transition service, define cancellation/restart/blocked resolution and
  employee/account compensation, require completion invariants at every writer,
  and make payroll use only an active authoritative clearance. Add transition,
  replay, cancellation, restart, payroll-date, and two-connection tests.

### M023-F004 — P1, Broken: final-pay sequencing conflicts with the deduction policy

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: `FinalPayService::compute()` rejects only terminal clearances at
  `api/app/Modules/HR/Services/FinalPayService.php:46-66`; it does not require
  `ClearanceStatus::Completed`. Finalization does require `completed` and a
  computed amount at `api/app/Modules/HR/Services/SeparationService.php:285-293`.
  Finalization then rejects every active/pending positive-balance employee loan at
  `:295-311`, while `FinalPayService::postJournalEntry()` re-reads live loan,
  advance, and property deductions and builds corresponding credit lines at
  `api/app/Modules/HR/Services/FinalPayService.php:200-250`. The rejection text
  tells the operator to “confirm deduction” at `SeparationService.php:305-310`,
  but no such confirmation mutation exists in `routes.php:266-280`.
- Impact: the API allows an out-of-sequence final-pay computation, and the
  canonical finalization path makes its own supported loan-deduction accounting
  branch unreachable for an outstanding loan. Operators have no tested settle,
  accept-deduction, or receivable-recovery path.
- Recommendation: require completed clearance before compute, choose one
  explicit loan/advance/property policy, implement the policy as a durable
  transition with authorization, and test exact-money sequencing and recovery.

### M023-F005 — P2, Incomplete: final-pay evidence is mutable and source snapshots are not auditable

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: Compute stores the breakdown and amount at
  `api/app/Modules/HR/Services/FinalPayService.php:79-100`, but the audit row
  records only the old computed flag/amount and the new amount/actor at `:91-118`.
  At posting time, live deductions are re-read and the breakdown/amount is
  overwritten at `:200-225` without source-row IDs, source versions, policy
  decision, or a complete before/after snapshot.
- Impact: an auditor cannot reconstruct which payroll, leave, loan, advance, and
  property records produced the approved amount or why it changed between compute
  and finalization.
- Recommendation: persist an immutable, versioned computation snapshot with
  source identifiers/versions, actor, timestamp, policy decision, full breakdown,
  and finalization-time revision/recovery evidence.

### M023-F006 — P2, Broken: checklist settings can create an uncompletable clearance

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: Both checklist readers validate only array shape and key presence at
  `api/app/Modules/HR/Services/SeparationService.php:157-169` and `:172-192`.
  They do not require non-empty strings, valid departments, or unique
  `item_key` values. Signing stops at the first matching key and treats an already
  cleared first match as a replay no-op at `:216-225`; completion requires every
  row to be cleared at `:244-250`.
- Impact: duplicate keys can leave a later duplicate permanently pending, while
  blank or non-string values cannot be reliably assigned, signed, or rendered.
- Recommendation: validate a strict schema at settings write and initiation,
  enforce unique non-empty keys and an allow-listed department set, reject or
  repair existing invalid settings, and cover duplicate-key/replay behavior.

### M023-F007 — P2, Broken: separation employment history still violates its array contract

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: Both separation history writes pass `json_encode(...)` into the
  model at `api/app/Modules/HR/Services/SeparationService.php:129-142` and
  `:344-357`. `EmploymentHistory` casts both value columns to arrays at
  `api/app/Modules/HR/Models/EmploymentHistory.php:21-26`, while the resource
  assumes array-shaped values for masking at
  `api/app/Modules/HR/Resources/EmploymentHistoryResource.php:22-26`.
- Impact: canonical initiation/finalization history can read back as a string
  instead of an object and can fail or mis-shape when sensitive keys are masked.
- Recommendation: assign arrays directly, add sensitive/non-sensitive resource
  tests, measure affected rows, and plan a controlled repair/backfill before
  treating the module as verified.

### M023-F008 — P2, Incomplete: canonical initiation accepts remarks but does not persist them on the clearance

- Classification: Incomplete
- Tags: [small] [same-session-ok after lifecycle contract]
- Evidence: The request accepts `remarks` at
  `api/app/Modules/HR/Requests/InitiateSeparationRequest.php:18-24`, and the
  model/resource expose `remarks` at
  `api/app/Modules/HR/Models/Clearance.php:25-40` and
  `api/app/Modules/HR/Resources/ClearanceResource.php:64-66`. The canonical
  `Clearance::create()` payload at
  `api/app/Modules/HR/Services/SeparationService.php:114-122` omits it; the
  value is used only as an employment-history remark at `:129-142`.
- Impact: the employee UI accepts separation context, but the clearance detail
  and list do not round-trip that context.
- Recommendation: persist the validated value on the canonical clearance,
  define editability/audit rules, and add a request/resource regression test.

### M023-F009 — P2, Incomplete: notification recipients and destination do not match the actionable workflow

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: The process flow says initiation notifies HR, department head, and IT
  at `docs/PROCESS-FLOWS.md:1153-1165` and repeats that audience in the chain
  table at `:1348-1355`. The seeded setting contains only `hr_officer` and
  `finance_officer` at
  `api/database/migrations/0373_seed_cross_module_notification_roles.php:15-25`.
  The listener uses that setting and links to the employee detail page rather
  than the clearance detail at
  `api/app/Modules/HR/Listeners/NotifyOnSeparationInitiated.php:21-38`.
- Impact: intended department/IT owners may not be notified, Finance may receive
  a notification without the matching clearance capability, and recipients are
  not taken to the actionable clearance record.
- Recommendation: reconcile the approved audience with the role matrix, link to
  `/hr/separations/{clearance}`, and test recipients, permissions, entity IDs,
  and stale/cancelled notification behavior.

### M023-F010 — P2, Incomplete: the list API does not implement the SPA search/pagination contract

- Classification: Incomplete
- Tags: [small] [same-session-ok after lifecycle contract]
- Evidence: The SPA exposes search and page-size controls at
  `spa/src/pages/hr/separations/index.tsx:67-105`, including `onSearch` at
  `:86-91`. `SeparationService::list()` applies only status, reason, and raw
  `employee_id` filters and ignores `search` at
  `api/app/Modules/HR/Services/SeparationService.php:46-56`. It caps `per_page`
  at 100 but does not validate a positive lower bound.
- Impact: the visible Search field has no server-side effect, and invalid page
  sizes can reach the paginator. Operators cannot reliably find a clearance as
  the list grows.
- Recommendation: define the query contract, search employee name/number and
  clearance number, decode public IDs at the request boundary, and validate
  bounded positive pagination values.

### M023-F011 — P3, Incomplete: clearance resources leak internal signer IDs and perform per-row journal queries

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: `ClearanceResource` returns `clearance_items` unchanged at
  `api/app/Modules/HR/Resources/ClearanceResource.php:43-51`, including raw
  numeric `signed_by` values. It calls `JournalEntry::find()` during every
  serialization at `:52-55`. Neither list nor show eager-loads `journalEntry`:
  `api/app/Modules/HR/Services/SeparationService.php:46-67`.
- Impact: internal user IDs cross the resource boundary, and a list of many
  clearances produces one journal query per serialized row.
- Recommendation: expose a safe signer resource/hash/name, eager-load the
  journal relation, and add resource-contract and query-count tests.

### M023-F012 — P2, Incomplete: frontend status/actions/error handling do not cover the server lifecycle

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: The design system maps `completed` to `success` at
  `docs/DESIGN-SYSTEM.md:425-433`, but both separation pages map it to `info` at
  `spa/src/pages/hr/separations/index.tsx:16-20` and
  `spa/src/pages/hr/separations/detail.tsx:22-24`. Detail actions exclude only
  `finalized`, so cancelled rows can still render sign/finalize actions at
  `spa/src/pages/hr/separations/detail.tsx:90-99` and `:127-131`.
  The chain header has no cancelled/blocked state at `:68-73`; all mutation
  failures are reduced to generic toasts at `:44-63`; and the sign mutation
  sends only `item_key` at `:44-46` even though the API accepts remarks at
  `spa/src/api/separations.ts:19-20`. Blocked items are rendered as “Pending” by
  `:121-124` with no recovery guidance.
- Impact: operators cannot understand or recover cancelled/blocked clearances,
  completed status has the wrong semantic treatment, and actionable server
  messages such as outstanding-loan policy are lost in the browser.
- Recommendation: model every server status and permitted action, suppress
  terminal actions, show safe policy/error messages, add item remarks and
  recovery guidance, and cover the authorized role journey in browser tests.

### M023-F013 — P3, Incomplete: legacy separation contract artifacts remain after the route was removed

- Classification: Incomplete
- Tags: [small] [separate-recommended because it changes RBAC/docs]
- Evidence: The current route surface exposes only the canonical initiation route
  at `api/app/Modules/HR/routes.php:107-109`, and the SPA calls it at
  `spa/src/api/hr/employees.ts:101-104`. However, the old `SeparateEmployeeRequest`
  still authorizes `hr.employees.separate` at
  `api/app/Modules/HR/Requests/SeparateEmployeeRequest.php:11-24`, the permission
  remains in the catalog at
  `api/database/seeders/RolePermissionSeeder.php:58-69`, and the process guide
  still documents the removed PATCH endpoint at `docs/PROCESS-FLOWS.md:1157-1160`.
- Impact: stale permissions, dead request code, and documentation can cause an
  integration or operator to target a contract that no longer exists.
- Recommendation: remove or explicitly deprecate the dead request/permission,
  update the process guide and API inventory to the canonical route, and add a
  route-contract check that rejects the legacy path.

## Strengths observed

- The current employee-detail path uses the canonical separation POST route and
  the old direct status endpoint is absent from the current HR route surface.
- Initiation, item signing, and finalization use `DB::transaction()` and
  authoritative row locks at `SeparationService.php:70-79`, `:195-205`, and
  `:271-280`; completion and initiation are recorded through the outbox at
  `:144-151` and `:257-264`.
- Final-pay calculations use decimal-string `Money` operations at
  `FinalPayService.php:75-88` and `:213-256`; missing source reads fail closed at
  `:431-446`.
- Journal posting has a clearance reference/idempotency recovery path at
  `FinalPayService.php:160-195`, and the completion listener rechecks current
  clearance state before deactivating access at
  `DeactivateAccountOnClearanceComplete.php:32-54`.
- The focused PHP syntax checks passed for the M023 service, controller, model,
  resource, listener, and state-machine files. Targeted SPA ESLint passed;
  token discipline passed for 771 files; route listing found the six expected
  clearance routes; and the static RBAC audit found zero referenced-but-unseeded
  permissions.

## Evidence checked and limitations

- The focused command
  `docker compose run --rm --no-deps api php artisan test --filter='(FinalPay|Separation|Clearance)'`
  was attempted against the shared test database. It ended with 34 failures and
  0 assertions because the migration/test harness was concurrently resetting an
  unstable shared schema and surfaced an unrelated malformed migration import at
  `api/database/migrations/2026_08_25_140000_add_artifact_key_to_bank_file_records.php:5`
  (`IlluminateDatabaseMigrationsMigration`), plus missing/duplicate `migrations`
  and domain tables. The result is not treated as product evidence for M023.
- Full SPA `npm run typecheck` was started but interrupted after multiple
  concurrent TypeScript processes continued running without producing a result;
  no pass claim is made. Targeted ESLint and the token/RBAC static checks are the
  available frontend evidence for this session.
- No authenticated browser journey was available for per-department signing,
  Finance finalization, cancellation/recovery, server-error rendering, or the
  employee-detail initiation redirect. No production data scan was performed for
  duplicate checklist keys or JSON-string employment-history rows.
- Dependency modules were read for payroll, loans, accounting, permissions, and
  workflow context only; no dependency source was modified by this session.

## Release decision

No production-code fixes were applied by this session. The canonical entry-point
changes and other dirty worktree edits predated this audit session and were
preserved. The remaining plan is predominantly large and
`separate-recommended` because it changes lifecycle transitions, permissions/
RBAC, financial deduction policy, notifications, and recovery behavior. M023 is
released as 📋 Plan Ready with the ordered action plan in `action-plan.md`.
