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

---

# M023 re-audit — 2026-08-30

Status: 🔁 Needs Re-audit
Prior history above is preserved. Database: isolated `ogami_test_sep`.

## What the reclaimed lock actually contained

The lock was a 108h orphan from 2026-08-25. Unlike four other modules in this
pipeline, where an orphan lock meant *completed and committed* work, this one
meant **plan-only**: `git log` over the module paths shows the last touch was the
sweep commit `167de85e`, and it carried exactly one M023 file
(`SeparationService.php`, +17/-6 — the canonical POST initiation wiring). No
other module file changed since `411e42ff` / `79a5184c`. The fix-log's claim of
"no production-code fixes" is therefore accurate.

Critically, **the four earlier sessions never measured anything**: their own notes
record "34 failures and 0 assertions" from a shared, concurrently-resetting test
database, and no product evidence. All 13 prior findings still reproduce. This
session measured them, and found **five further defects**, four of which move
money.

## Newly measured findings

### M023-F014 — P0, Broken: an already-paid 13th month is paid a second time

- Classification: Broken
- Tags: [small fix] [separate-recommended — changes what a leaver is paid]
- Evidence: `FinalPayService::proRatedThirteenthMonth()` at
  `api/app/Modules/HR/Services/FinalPayService.php:379-403` reads the accrual for
  the separation year and returns `accrued_amount` without consulting
  `thirteenth_month_accruals.is_paid`. That column exists and is authoritative —
  `ThirteenthMonthService.php:64` and `:107` skip a paid accrual, and `:433-452`
  sets it only on the finalized payment path, exactly as CLAUDE.md describes.
- Measurement: accrual `accrued_amount=10000.00, is_paid=true, paid_date=2026-05-01`
  → `pro_rated_13th_month` = **`10000.00`**, identical to the `is_paid=false` case.
  An employee who received their 13th month in the December run and separates the
  following May is paid it again in full.
- Note the asymmetry: because `accrue()` freezes a paid accrual, "already paid"
  really does mean nothing further is owed, so the correct figure is zero.

### M023-F015 — P0, Broken: last salary is paid twice for a committed payroll period

- Classification: Broken
- Tags: [small fix] [separate-recommended — changes what a leaver is paid]
- Evidence: `FinalPayService::lastSalaryProRated()` at `FinalPayService.php:277-303`
  selects the period containing the separation date excluding only `Voided`, then
  returns zero only when the status is `Disbursed`. `PayrollPeriodStatus::isLocked()`
  (`api/app/Modules/Payroll/Enums/PayrollPeriodStatus.php:38-41`) treats
  `Finalized` as money-committed alongside `Disbursed`; CLAUDE.md's "never unlock
  finalized" says the same. Final pay also creates no `payroll_cycle_claims` row,
  so it sits entirely outside the no-double-pay guard.
- Measurement, one employee, one ₱3,125.00 computed payroll row, by period status:

  | period status | `last_salary_pro_rated` | correct? |
  |---|---|---|
  | draft | 3125.00 | ok (period will not pay) |
  | computed | 3125.00 | ok |
  | approved | 3125.00 | **double** — a checker has signed off |
  | finalized | 3125.00 | **double** — locked, committed |
  | disbursed | 0.00 | ok |
  | voided | 0.00 | **lost** — never paid, and final pay declines to cover it |

  Both directions are wrong: `finalized`/`approved` pay ₱3,125 twice, `voided`
  drops it entirely (recoverable only if a replacement run happens to cover the
  employee).
- Second, independent inconsistency: for the *same* employee and separation date,
  the payroll-row path returned `3125.00` (half-basic × calendar-day fraction)
  while the DTR fallback path returned **`4545.45`** (monthly ÷ 22 work-days × 5
  day-equivalents) — a ₱1,420.45 spread decided purely by whether payroll happened
  to have computed a row.

### M023-F016 — P0, Broken: leave conversion is uncapped and never debits the balance

- Classification: Broken
- Tags: [medium] [separate-recommended — changes what a leaver is paid]
- Evidence: `FinalPayService::unusedConvertibleLeaveValue()` at
  `FinalPayService.php:331-341` computes `SUM(remaining * lt.conversion_rate)` and
  multiplies by the daily rate. The year-end path clamps the same rate:
  `api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:105` —
  `max(0.0, min(1.0, (float) $lt->conversion_rate))`. `conversion_rate` is
  validated to `max:9.99` (`StoreLeaveTypeRequest.php:36`) on a `decimal(3,2)`
  column. Nothing anywhere debits the balance after a separation payout.
- Measurement, 5.0-day balance, ₱1,000.00/day, verified through a *successful*
  finalize (HTTP 200):

  | `conversion_rate` | leave value paid | `remaining` after finalize |
  |---|---|---|
  | 1.00 | 5,000.00 | **5.0** |
  | 2.00 | 10,000.00 | **5.0** |
  | 9.99 | **49,950.00** | **5.0** |

  So the payout can exceed the entitlement ~10×, and the balance survives
  finalization intact — available to be encashed again at year-end.
- **Inherited leave finding does reach a final-pay figure** (measured end to end):
  with the balance correctly zeroed after a year-end encashment, final pay values
  the leave at `0.00`. Calling `LeaveBalanceService::restore()` — which
  `LeaveRequestService::cancel()` at `LeaveRequestService.php:464-467` invokes
  unconditionally for any approved request, with no check that the year was
  already encashed — restores `remaining` to `9.0`, and final pay then pays
  **`9000.00`** for days already encashed and paid. The leave-side fix is not
  ours; the reportable gap on our side is that final pay trusts `remaining`
  verbatim and never debits it.

### M023-F017 — P0, Broken: an out-of-range separation date is accepted and zeroes payroll irrecoverably

- Classification: Broken → **FIXED this session (pre-hire half)**
- Evidence/measurement: `POST /hr/employees/{e}/separation` with
  `separation_date=2020-06-15` on an employee hired `2024-01-01` returned **201**;
  `separation_date=2035-01-01` also returned **201**. With the 2020 date on record,
  `PayrollCalculatorService::employedDayFraction()` measured **`0.0000`** for a
  normal 2026-05-16..31 cutoff (it takes the EARLIEST `separation_date`, so the
  employment window is empty). Every later cutoff pays zero basic pay, and no
  cancel/correct transition exists to undo it.
- Fixed: the pre-hire case is now refused in `initiate()`. A future-dated
  separation is still allowed, because notice periods make that normal.
- **Open question:** the far-future case (2035) is still accepted. Any upper bound
  is a policy number, not a derivable invariant — see the questions section.

### M023-F018 — P1, Broken: a zero-value final pay can never be finalized

- Classification: Broken
- Tags: [medium] [separate-recommended — touches GL]
- Evidence: `FinalPayService::postJournalEntry()` at `FinalPayService.php:243-257`
  always emits the salaries debit line, and skips the cash credit line only when
  `net = 0`. When `gross_plus` is also `0.00`, every line is zero and the journal
  validator refuses the entry.
- Measurement: an employee owed nothing (breakdown all `0.00`) → `PATCH .../finalize`
  = **422 "Each line must have exactly one of debit or credit greater than zero."**
  The clearance is left at `completed` with no way forward and no cancel route.
  Together with R-002 this makes **two** classes of employee impossible to separate.

## Prior findings — re-measured

| # | verdict | measurement |
|---|---|---|
| F001 cross-department signing | **reproduces** | a `department_head` signed a `Finance` item → **200** |
| F002 maker-checker absent | **reproduces** | compute + finalize share `hr.separation.finalize`; one admin did both |
| F003 no cancel/restart/blocked recovery | **reproduces** | only 6 clearance routes exist; `ClearanceStatus::Cancelled` unreachable |
| F004 loan deadlock (= R-002) | **reproduces** | see below |
| F005 mutable final-pay evidence | reproduces (read-only) | deductions re-read and overwritten at post time |
| F006 checklist can be uncompletable | **reproduces** | `["dup:cleared","dup:pending",…]`, stuck `in_progress` → **FIXED** |
| F007 history array contract | **reproduces** | `to_value` reads back `string`, `from_value` `array` → **FIXED** |
| F008 remarks not persisted | **reproduces** | `remarks = NULL`, absent from response → **FIXED** |
| F009 notification recipients/link | reproduces (read-only) | setting holds only `hr_officer`, `finance_officer` |
| F010 list contract | **reproduces** | search ignored; `per_page` 0/-5/abc → 200; HashID `employee_id` → **`SQLSTATE[22P02]`** → **FIXED** |
| F011 raw signer PK + per-row JE query | **reproduces** | `"signed_by":30` in payload → **FIXED** (leak); N+1 JE query remains |
| F012 SPA lifecycle/status gaps | reproduces | `completed → 'info'`, design system says `success` |
| F013 legacy permission/request | reproduces | `hr.employees.separate` + `SeparateEmployeeRequest` still present, route gone |

## R-002 — independently confirmed (handoff from `loans-cash-advances`)

Verified without relying on the loans session's account:

- `SeparationService::finalize()` at `SeparationService.php:314-331` refuses while
  any `employee_loans` row has `status IN (active,pending) AND balance > 0`.
- `FinalPayService::loanBalances()` at `FinalPayService.php:405-412` sums **the
  same statuses**. Gate and recovery are therefore mutually exclusive: whenever the
  deduction would be non-zero the gate refuses, and whenever the gate passes the
  deduction is zero. The credit line at `FinalPayService.php:246-248` is
  unreachable for an outstanding loan.
- Measured: `compute()` reported `less_loan_balance = 5000.00` — the operator is
  *shown* the deduction — then `PATCH .../finalize` returned **422**, and the loan
  balance was still **5000.00**. The 422 text instructs the operator to "confirm
  deduction in the final pay breakdown"; the six existing clearance routes contain
  no such action.
- Three dead artifacts of the intended workflow exist: `LoanPaymentType::FinalPay`
  (`api/app/Modules/Loans/Enums/LoanPaymentType.php:11`, referenced nowhere but its
  own definition), and `employee_loans.is_final_pay_deduction`
  (`0029_create_employee_loans_table.php:30`) which is in `$fillable`, `$casts` and
  `EmployeeLoanResource.php:39` but is **never read as a decision** by any service.
  The schema anticipated final-pay recovery; the code refuses instead.
- `ClearanceLoanBlockTest.php` asserts the current behaviour and was left
  untouched, because this session did not change it.

## Invariants executed

| invariant | measured result | probe |
|---|---|---|
| last salary prorated exactly once | **FAIL** — twice for `approved`/`finalized`; lost for `voided` | compute() across all 6 period statuses |
| mid-cutoff separation date honoured | **PASS** — 4545.45 for 5 pre-separation DTR days; a post-separation day excluded | DTR fallback path |
| no double-proration | **PASS** — reads already-prorated `payroll.basic_pay` verbatim; does not re-prorate | payroll-row path |
| finalize twice refused | **PASS** — 422 "already finalized"; exactly **1** JE | two sequential finalizes after a 200 |
| finalize incomplete clearance refused | **PASS** — 422 "All clearance items must be signed" | finalize an `in_progress` clearance |
| reopen after finalize refused | **PASS** — recompute 422 "closed clearance"; sign 422 "Clearance is closed" | post-finalize mutations |
| delete a finalized clearance | **PASS (vacuous)** — 405, no route exists | DELETE |
| separate an already-separated employee | **PASS** — 422 "Employee is already separated" | POST on a `resigned` employee |
| separation before hire refused | **was FAIL (201)** → **PASS after FIX-1** | POST with 2020 date, hired 2024 |
| future separation refused | **accepted (201)** — intentional for notice periods; *far*-future unbounded | POST with 2035 date |
| total equals sum of components exactly | **PASS unclamped** (BCMath exact); **FAIL clamped** | see below |
| 13th month neither double-paid nor lost | **FAIL** — `is_paid=true` still paid 10000.00 | accrual with `is_paid` both ways |
| leave conversion capped at balance | **FAIL** — 49,950.00 paid on a 5-day balance at rate 9.99 | rates 1.00 / 2.00 / 9.99 |
| leave conversion debits the balance | **FAIL** — `remaining` = 5.0 after a successful finalize | post-finalize balance read |
| closed-period refusal | **PASS** — 422 with a clear message; clearance stayed `completed`, `journal_entry_id` NULL | closed 2026-05, finalize |
| journal balances and equals the rows | **PASS** — debit 8333.33 = credit 8333.33; debit = `gross_plus`, credit = `net`; JE date = separation date | finalize + line sums |
| loan recovery reachable (R-002) | **FAIL** — unreachable by construction | above |
| last-admin reachability from the listener | **FAIL** — active `system_admin` **1 → 0** | listener invoked directly |
| permission gate per endpoint | **PASS** — `employee` role got **403 on all 7**, list and options included | per-endpoint sweep |
| row scope for `employee` | **PASS (vacuous)** — the role holds no `hr.separation.*` permission, so no row is reachable | as above |
| raw-id-free error bodies | **PASS** — 422 bodies carry no integer PK | not-found item key |
| raw-id-free success bodies | **was FAIL** (`"signed_by":30`) → **PASS after FIX-6** | sign then show |

Clamped-total detail: with `gross_plus=0.00` and `gross_less=99999.00`, `net`
clamps to `0.00` while `gross_plus − gross_less = −99999.00`. The ₱99,999
un-recovered deduction is recorded **nowhere** in the breakdown — the JE quietly
recovers only `min(plus, less)` (`FinalPayService.php:239-241`) and the remainder
stays on the books with no audit field naming it. An auditor reading the breakdown
cannot see that anything was left unrecovered.

## Account deactivation on clearance — reachability confirmed

`DeactivateAccountOnClearanceComplete.php:54` calls
`UserProvisioningService::deactivateForEmployee()`, which at
`api/app/Modules/HR/Services/UserProvisioningService.php:82-104` performs
`$user->update(['is_active' => false])` with **no last-administrator guard**.
Measured: with exactly one active `system_admin` linked to a separating employee,
invoking the listener took active administrators from **1 → 0**. This listener is
an *unattended*, queued path — no operator confirms it. The service is HR's file
and was **not modified**; reported only, per scope.

## Money discipline

Clean overall. `Money` BCMath decimal strings throughout `FinalPayService`;
`Money::INNER` used for intermediate precision; no `(float)` or `round()` on money
in this module's PHP. The two SQL aggregates
(`SUM(remaining * conversion_rate)`, `SUM(quantity * replacement_unit_cost)`)
operate on PostgreSQL `numeric`, so they are exact. The SPA has **no** `Number()`,
`parseFloat` or `toFixed` on money in this module. `ClearanceResource`'s
`progress_pct` uses float `round()`, but it is a percentage, not money.

The float that *does* matter is outside this module:
`ProcessYearEndLeave.php:105` casts `conversion_rate` to float and `round()`s
days — the divergence behind F016.

## Dead surfaces — both directions

**None.** All six backend clearance routes have a client caller in
`spa/src/api/separations.ts`, and both pages are routed
(`spa/src/routes/hrRoutes.tsx:179,181`). The one half-dead surface was the
`search` parameter the SPA sent and the API ignored — now implemented (FIX-5).
Dead *code* rather than dead surface: `SeparateEmployeeRequest` +
`hr.employees.separate` (F013), `LoanPaymentType::FinalPay`, and
`employee_loans.is_final_pay_deduction`.

## Questions requiring a human decision

1. **Loan recovery on separation (R-002).** What should happen to an outstanding
   loan when an employee leaves? Options are characterised in `action-plan.md`;
   none was implemented.
2. **13th month already paid (F014).** Confirm that "already paid" means zero is
   owed in final pay. The evidence says yes, but it reduces a leaver's payment by
   the full accrual, so it needs sign-off.
3. **Last salary for `approved`/`finalized`/`voided` periods (F015).** Which
   payroll statuses mean "payroll will pay this, so final pay must not"? And
   should a `voided` last period be covered by final pay or by a replacement run?
4. **Leave conversion rate (F016).** Should separation use the same 1.0 clamp as
   year-end, or is a >1.0 rate a deliberate separation benefit? And should a
   separation payout debit the balance?
5. **Far-future separation dates (F017).** What upper bound is acceptable — a
   fixed window, or a configurable `hr.separation.max_days_ahead`?
6. **Zero-value final pay (F018).** Skip the journal entry, post a zero-value
   memo entry, or require a cancel transition instead?
7. **Checklist ownership and maker-checker (F001/F002).** Unresolved since the
   first audit; still the gate on items 1-3 of the plan.

## Could not verify

- **No authenticated browser journey.** Per-department signing, Finance
  finalization, and the SPA's rendering of cancelled/blocked states and server
  error text were not exercised in a real browser. Chromium is required for that
  (the SPA suite measures layout), and no login server was run.
- **No production data scan** for existing duplicate checklist keys or
  double-encoded `employment_history.to_value` rows. FIX-4 corrects new writes
  only; the size of the existing population is unknown.
- **Two-connection concurrency** for compute-vs-finalize races was not re-run;
  `RefreshDatabase` hides uncommitted rows from a second connection, so a naive
  probe reports a false "no lock". The existing
  `SeparationLifecycleConcurrencyTest` passes but covers initiation replay only.
- **`PayrollCalculatorService` is owned by a live session** and changed under this
  audit. F015's period-status table was measured against the tree as of this
  session; re-confirm after that session lands.
