# M020 — Loans & Cash Advances audit report

Audit date: 2026-08-24  
Domain: People  
Tier: 2  
Audit status: Plan Ready pending release  
Audit classification vocabulary: Broken, Missing, Incomplete, Polish

## Scope and method

This session audited the Loans & Cash Advances surface and its read-only dependencies: employee identity/pay data, approval workflows, payroll deductions, final pay/separation, notifications, RBAC, database migrations, API clients, and the employee/admin loan screens. No source files outside this module's audit artifact directory were changed.

At claim time, the registry had no unlocked Auditing/Fixing or Needs Re-audit module. M020 was the first eligible unlocked Tier-2 Not Started module after the currently locked finance modules; its documented dependencies are employee-master, payroll-period-processing, and approval-workflows. The dependency cycle is recorded as a deliberate, smallest-justified exception to strict topological order.

## Discovery

| Surface | Evidence inspected | Result |
| --- | --- | --- |
| API and authorization | `api/app/Modules/Loans/routes.php:5-21`, `LoanController.php:71-192`, loan requests/resources | 13 loan routes are registered; hashed IDs and per-action permissions exist, but row scope and cancellation permissions are inconsistent. |
| Domain and persistence | `api/app/Modules/Loans/Models/*`, `Services/*`, `Enums/*`, migrations `0029`, `0030`, `0245`, `2026_08_13_212000`, `2026_08_13_221000` | Transactional loan/payment paths exist; money scale, transition, ledger, and payment-reference gaps remain. |
| Approval and notifications | `ApprovalService.php:82-185`, `WorkflowSeeder.php:14-55`, `LoanService.php:210-274`, loan listeners | Ordered role approval and self-approval protection are present; department scope and final-versus-step notification semantics are not complete. |
| Payroll and separation | `PayrollCalculatorService.php:831-957`, `FinalPayService.php:198-250`, `SeparationService.php:289-305` | Payroll records deductions, but final-pay settlement does not close the loan ledger and reversal bypasses canonical reconciliation. |
| Frontend | `spa/src/pages/loans/*`, `spa/src/pages/self-service/loans.tsx`, `spa/src/api/*`, `spa/src/types/*`, route guards | Atelier components and loading/error/empty states are used; the self-service preview contract is broken and several status/permission displays are misleading. |
| Tests and documentation | loan feature tests, `docs/PROCESS-FLOWS.md:1024-1084`, `docs/USER-MANUAL.md:103-108`, `docs/AUTO-BROWSER-TESTS.md:96-127` | Amortization coverage is useful, but database-backed tests need the test database and there is no browser coverage for this module. Documentation still describes a zero-interest company/cash-only model. |

## Findings

### F-001 — Interest-rate precision is lost and the unit is displayed incorrectly

Classification: **Broken**  
Severity: **Critical**  
Plan: `large`, `separate-recommended`

Evidence:

- `api/database/migrations/0285_seed_loan_policy_settings.php:13-20` seeds Pag-IBIG at `0.105` and defines the value as a decimal annual rate.
- `api/database/migrations/0029_create_employee_loans_table.php:18-20` stores `interest_rate` as `decimal(5,2)`; `api/app/Modules/Loans/Models/EmployeeLoan.php:33-38` also casts it to two decimals.
- `api/app/Modules/Loans/Services/LoanService.php:379-387` returns a fractional rate for amortization, so `0.105` can persist as `0.11` and be used as 11% thereafter.
- `spa/src/pages/loans/detail.tsx:121` renders the fractional API value directly with a percent sign, while `create.tsx:124` multiplies by 100.

Impact: Pag-IBIG schedules, balances, and displayed rates can disagree. A 10.5% policy can become 11% in persisted calculations and appear as `0.11%` in the detail view.

Recommendation: choose one canonical rate unit, preserve sufficient decimal scale (or basis points) through persistence and domain calculations, and centralize percent formatting. Add persistence, preview, reconciliation, and UI assertions for 10.5%.

### F-002 — Department-head loan scope is bypassed by the approval permission

Classification: **Broken**  
Severity: **Critical**  
Plan: `large`, `separate-recommended`

Evidence:

- `api/database/seeders/RolePermissionSeeder.php:670-688` gives `department_head` `loans.view` and `loans.approve`, while the role contract says the role is department-scoped and has no write-off permission.
- `api/app/Modules/Loans/Services/LoanService.php:82-100` treats every user with `loans.approve` as unrestricted finance, so the department-head branch is unreachable for list filtering.
- `api/app/Modules/Loans/Controllers/LoanController.php:71-89` skips ownership/department checks for every user with `loans.approve`; approve, reject, and bulk approve do not add a department check.
- `api/app/Modules/Loans/Services/ApprovalService.php:82-185` checks ordered role eligibility and self-approval, but does not enforce the department boundary.

Impact: a department head can list, inspect, approve, or reject loans belonging to another department. This is both a data-disclosure and authorization-integrity issue.

Recommendation: implement a reusable loan visibility/decision policy and re-check it inside the locked approve/reject/bulk operations. Keep finance/system-admin global scope, department-head own-department scope, and employee self-service scope explicit. Add cross-department denial tests.

### F-003 — Active cancellation can make an outstanding debt disappear from collection

Classification: **Broken**  
Severity: **Critical**  
Plan: `large`, `separate-recommended`

Evidence:

- `api/app/Modules/Loans/routes.php:20` protects cancellation with `loans.write_off`.
- `api/app/Modules/Loans/Services/LoanService.php:277-285` permits Pending or Active loans and only changes status to Cancelled; it accepts no actor, reason, settlement amount, or accounting reference.
- `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:837-840` collects only Active loans.
- `api/app/Modules/HR/Services/FinalPayService.php:291-305` checks only Active/Pending loans before finalization.
- `spa/src/pages/loans/detail.tsx:86-88` exposes the cancel action using `loans.approve`, not the backend's `loans.write_off` permission.

Impact: cancelling an Active loan with a positive balance removes it from payroll and final-pay selection without a payment, write-off journal, or audit reason. Department heads can also see a cancel control that the API will reject because they do not have `loans.write_off`.

Recommendation: replace the ad hoc status update with an explicit state machine and write-off/settlement command. Require reason, actor, approval/SoD, journal reference, and a clear treatment of remaining balance. Align the frontend permission with the backend.

### F-004 — Final-pay loan recovery is not posted to the loan ledger

Classification: **Missing**  
Severity: **Critical**  
Plan: `large`, `separate-recommended`

Evidence:

- `api/app/Modules/HR/Services/FinalPayService.php:198-250` re-reads the live balance and posts a credit to Loans Payable, but does not create a `LoanPayment`, call `LoanService::reconcileAggregates()`, or mark the loan as settled/final-pay deducted.
- `api/app/Modules/HR/Services/SeparationService.php:289-305` blocks finalization when an active/pending positive-balance loan exists, while telling the operator to “confirm deduction in the final pay breakdown”; no such confirmation-to-ledger path exists in the inspected loan routes (`api/app/Modules/Loans/routes.php:5-21`).
- `api/app/Modules/Loans/Enums/LoanPaymentType.php:7-18` defines a final-pay payment type, but the production final-pay path does not use it.

Impact: the journal and the loan row can disagree. Final-pay completion is blocked for the very loans that the final-pay journal knows how to recover, and any future retry lacks an authoritative idempotency key.

Recommendation: make final-pay confirmation and loan settlement one transaction: lock the clearance and loan, create an idempotent `final_pay` payment row, reconcile the loan, persist the recoverable/unrecovered amount, then post the balanced journal. Add retry and separation tests.

### F-005 — Self-service amortization preview has an incompatible request and response contract

Classification: **Broken**  
Severity: **High**  
Plan: `medium`, `separate-recommended`

Evidence:

- `api/app/Modules/Loans/Controllers/LoanController.php:147-162` requires `loan_type` and returns an array of rows with `period`, `amount`, `principal`, `interest`, and `remaining_after`.
- `spa/src/api/self-service.ts:105-112` calls the shared preview endpoint without `loan_type`.
- `spa/src/types/self-service.ts:199-209` and `spa/src/pages/self-service/loans.tsx:288-315` expect an object containing `monthly_amortization` and `schedule[*].running_balance`.
- `spa/src/pages/self-service/loans.tsx:231-239` does not include the selected loan type in the query key or request.

Impact: the self-service preview request is rejected with 422 because `loan_type` is missing. If only the request were patched, the page would still read the wrong response shape and can fail while rendering.

Recommendation: define one shared API contract, include loan type in the request/query key, and add a backend feature test plus a frontend query/render test for zero-interest and interest-bearing types.

### F-006 — Loan money validation and salary limits use floats at financial boundaries

Classification: **Broken**  
Severity: **High**  
Plan: `large`, `separate-recommended`

Evidence:

- `api/app/Modules/Loans/Requests/StoreLoanRequest.php:28-37` validates principal as generic `numeric|min:1`, not a two-decimal money value.
- `api/app/Modules/HR/Controllers/SelfServiceController.php:132-167` applies the same generic numeric amount validation before forwarding to the canonical loan service.
- `api/app/Modules/Loans/Services/LoanService.php:116-132` casts the authoritative BCMath salary and configured multiplier to floats before calculating and formatting the cap; request scheduling/persistence is in `LoanService.php:138-207`.
- `api/app/Common/Support/Money.php:7-12` explicitly requires string/centavo money arithmetic and forbids float use.

Impact: a three-decimal request can be used to generate a schedule from one value and persist a database-rounded value; float boundary comparisons can accept or reject a principal incorrectly at the salary cap.

Recommendation: normalize and validate principal as `decimal:0,2` at both entry points, then use `Money`/BCMath strings for cap comparisons and schedule inputs. Add cap-boundary and over-precision tests.

### F-007 — Reconciliation is not historical and payroll reversal bypasses the canonical ledger rebuild

Classification: **Broken**  
Severity: **High**  
Plan: `large`, `separate-recommended`

Evidence:

- `api/app/Modules/Loans/Services/LoanService.php:330-368` accepts `$asOf` but sums all payments, including future-dated rows, and derives remaining periods from payment-row count rather than an as-of schedule or paid amount.
- `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:884-888` passes a historical payroll date to reconciliation, but the reconciliation query has no date filter.
- `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:923-955` reverses payments by directly subtracting aggregates, incrementing periods, and deleting rows instead of invoking the canonical reconciliation path.

Impact: a recompute can change historical balances using payments that occur later, and repeated/partial/manual payments can make remaining periods and denormalized fields diverge from the immutable payment ledger.

Recommendation: define as-of semantics, reconcile from a filtered immutable ledger, derive remaining collectible amount from the schedule/ledger rather than row count, and make payroll reversal an auditable reversal plus canonical rebuild.

### F-008 — Payment ledger controls and operational payment entry are incomplete

Classification: **Missing**  
Severity: **High**  
Plan: `large`, `separate-recommended`

Evidence:

- `api/app/Modules/Loans/Services/LoanService.php:289-327` has a safe internal `recordPayment()` transaction, but `api/app/Modules/Loans/routes.php:5-21` exposes no payment route and the frontend has no payment-entry surface in the audited loan pages.
- `api/database/migrations/0030_create_loan_payments_table.php:15-23` leaves `payroll_id` without a foreign key and has no database checks for nonnegative amount or allowed payment type.
- `api/app/Modules/Loans/Models/LoanPayment.php:10-22` does not use the module's audit-log trait.
- The same migration permits `manual` and `final_pay` payment types, but only payroll code currently creates payment rows in the inspected production paths.

Impact: finance cannot perform or trace a controlled manual payment, final-pay recovery has no ledger entry, deleted payroll references can leave orphaned payment metadata, and payment edits/deletions lack an auditable trail.

Recommendation: add a permissioned payment command/API with idempotency and reversal semantics, foreign-key/reference integrity, immutable audit records, and database/application validation.

### F-009 — Intermediate approval is announced as final approval

Classification: **Incomplete**  
Severity: **High**  
Plan: `medium`, `separate-recommended`

Evidence:

- `api/app/Modules/Loans/Services/LoanService.php:219-229` emits `LoanDecided(..., true)` after every approval call, even when `isFullyApproved()` is false and the loan remains Pending.
- `api/app/Modules/Loans/Listeners/NotifyOnLoanDecided.php:21-49` turns the event into an “Approved” employee notification.

Impact: an employee can receive an approved message before the final workflow step has completed.

Recommendation: emit a distinct approval-step event, and emit the final decision event only when the loan transitions to Active or Rejected. Test one-step and multi-step workflows.

### F-010 — Government loan types are declared but not configured as usable workflows

Classification: **Missing**  
Severity: **High**  
Plan: `medium`, `separate-recommended`

Evidence:

- `api/app/Modules/Loans/Enums/LoanType.php` includes SSS and Pag-IBIG types.
- `api/database/seeders/WorkflowSeeder.php:14-21,39-55` seeds enforced loan workflows only for company loan and cash advance.
- `api/app/Modules/Loans/Services/LoanService.php:35-56` returns only loan types with configured workflows.
- `api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php:24-53` and `NotifyOnLoanDecided.php:21-49` label every non-cash type as Company Loan.

Impact: the government types exist in enums/settings/tests but are omitted from the configured request options and are mislabeled if introduced through another path. Their reference-number and approval semantics are not a complete user-facing workflow.

Decision required: confirm whether SSS/Pag-IBIG are in M020 scope. If yes, seed workflows, expose government references, calculate/display labels consistently, and test them end to end; if no, remove or explicitly de-scope the exposed types/settings.

### F-011 — Self-service status chips classify rejected loans as successful

Classification: **Polish**  
Severity: **Medium**  
Plan: `small`, `same-session-ok`

Evidence: `spa/src/pages/self-service/loans.tsx:72-87` maps every non-pending, non-active, non-paid/non-closed status to the success variant, including rejected loans. The design-system mapping requires rejected/failed to use danger and cancelled to use neutral (`docs/DESIGN-SYSTEM.md:429-433`).

Impact: a rejected application is visually presented as a successful state.

Recommendation: map each loan status explicitly to the design-system variant and add a small status-matrix test or story.

### F-012 — Loan UI progress and operational lists are misleading at scale

Classification: **Incomplete**  
Severity: **Medium**  
Plan: `medium`, `same-session-ok`

Evidence:

- `spa/src/pages/loans/detail.tsx:59-64` calculates progress from `total_paid / principal`, which can reach 100% while interest-bearing balance remains.
- `spa/src/pages/loans/create.tsx:40-44` loads only the first 100 employees without search/pagination.
- `api/app/Modules/HR/Controllers/SelfServiceController.php:97-100` loads an employee's complete loan history without pagination.
- `spa/src/pages/loans/detail.tsx:121` has the independent rate-unit display defect described in F-001.

Impact: operators can misread repayment completion and large employee/loan histories can become slow or incomplete.

Recommendation: base progress on total due/balance, use searchable paginated employee selection and loan history, and fix the rate display with the canonical formatter from F-001.

### F-013 — Feature and abuse boundaries are not aligned

Classification: **Incomplete**  
Severity: **Medium**  
Plan: `medium`, `separate-recommended`

Evidence:

- `api/app/Modules/HR/routes.php:240-264` places self-service loan routes under the HR feature group, while `api/app/Modules/Loans/routes.php:5-21` uses the Loans feature group.
- `api/app/Modules/Loans/routes.php:13-14` exposes authenticated amortization preview without a throttle; the calculation accepts the configured maximum number of periods.

Impact: disabling the Loans feature may not consistently disable self-service loan access, and an authenticated user can repeatedly spend server-side calculation time on preview requests.

Recommendation: align backend feature middleware with the product feature contract and add a narrow throttle/cache for preview requests.

### F-014 — Product documentation does not describe the configured loan policy

Classification: **Incomplete**  
Severity: **Low**  
Plan: `small`, `same-session-ok`

Evidence: `docs/PROCESS-FLOWS.md:1024-1048` and `docs/USER-MANUAL.md:103-108` describe zero-interest company/cash loans and a one-month cap, while current settings/migrations configure interest-bearing government types and up to 60 periods (`api/database/migrations/0285_seed_loan_policy_settings.php:13-21`, `0325_seed_loan_period_setting.php`).

Impact: operators and testers can follow rules that do not match the active implementation.

Recommendation: update the documentation after the government-type scope decision and publish the actual cap, interest, approval, cancellation, payment, and final-pay rules.

## Strengths and retained controls

- `api/app/Modules/Loans/Services/LoanService.php:138-207,210-232,289-327` uses transactions and row locks for request, approval, rejection, and internal payment recording.
- `api/app/Modules/Loans/Services/ApprovalService.php:82-185` enforces ordered role steps and blocks self-approval.
- `api/database/migrations/2026_08_13_212000_add_loan_payroll_payment_idempotency.php:20-65` adds PostgreSQL payroll-payment idempotency for payroll deductions.
- `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:837-888` locks active loans in stable order and records payroll deductions in the payment table.
- Self-service loan reads are scoped to the authenticated employee (`api/app/Modules/HR/Controllers/SelfServiceController.php:46-100`), and loan resources return money values as strings (`api/app/Modules/Loans/Resources/EmployeeLoanResource.php:12-57`).
- The audited frontend uses shared Atelier components, semantic tables, monospaced/tabular numeric cells, and loading/error/empty states. Token discipline is clean across 769 SPA files.

## Verification evidence

- `./audit/scripts/regenerate-registry.sh`: passed (`Registry regenerated.`).
- `php artisan route:list --path=loans --env=testing --no-ansi`: passed; 13 loan routes registered.
- PHP syntax checks for the audited loan, HR, payroll, and final-pay files: passed.
- `npm run typecheck`: passed.
- Targeted ESLint for the audited loan API/types/pages: passed.
- `npm run audit:tokens`: passed; 769 files checked.
- Focused backend command `php artisan test --filter='InterestBearingAmortizationTest|LoanPolicyConfigurationTest|SelfServiceLoanLifecycleTest|LoanPaymentSerializationTest|LoanBulkApproveTest' --env=testing --no-ansi`: 7 amortization tests passed; 7 database-backed tests could not start because PostgreSQL host `db` could not be resolved. This is an environment limitation, not a product-pass claim.
- `docker compose ps`: no services were running, so database-backed integration and browser/e2e evidence were not available in this session.

## Release decision

The majority of findings are financial-integrity, RBAC, state-machine, or cross-module changes and are `separate-recommended`. No production-code fix was applied in this audit session. Release M020 as **Plan Ready** with the ordered action plan and preserve the open questions above for the implementation session.

---

# Re-audit — 2026-08-30

Auditor session: parallel batch of 3 (this session held `people/loans-cash-advances`).
Lock state at claim: **RECLAIMED** (orphan from 2026-08-29T23:20Z per the script's own
staleness check; the previous *substantive* session was 2026-08-25).

## Provenance of the prior session's work — determined, not assumed

The 2026-08-25 session's `fix-log.md` was **fully written**, and every fix it claimed is
**committed**. `git status` for `api/app/Modules/Loans`, `spa/src/pages/loans`,
`spa/src/pages/self-service/loans.tsx` and `api/database/migrations` is clean; the work
landed in `167de85e` ("chore: remaining uncommitted work from ~50 crashed audit sessions"),
which touches `LoanController.php`, `EmployeeLoan.php`, `LoanPayment.php`, the new
`Policies/LoanAccessPolicy.php`, `StoreLoanRequest.php`, `EmployeeLoanResource.php`,
`LoanService.php`, the new `Support/LoanRate.php` and `Support/LoanStateMachine.php`,
`routes.php`, and `0477_harden_loan_precision_and_payment_integrity.php`.

**This is the third module in this pipeline where an "orphan lock" meant completed,
committed work rather than abandonment.** This re-audit is therefore verification, not
recovery.

### Which of the prior session's 14 findings still reproduce

Re-measured empirically against real PostgreSQL rows in a dedicated database
(`ogami_test_loans`), not read off the diff.

| # | Prior finding | Status now | Evidence |
| --- | --- | --- | --- |
| F-001 | Interest-rate precision lost, unit displayed wrong | **Fixed** | `interest_rate` is `numeric(8,6)` (`0477_…php:122`), cast `decimal:6` (`EmployeeLoan.php:35`), `LoanRate::normalize` rejects >6dp (`LoanRate.php:24`); resource ships both `interest_rate` and `interest_rate_percent` (`EmployeeLoanResource.php:26-27`); `detail.tsx:122` uses `formatPercent` (Intl `style:'percent'`, scales ×100) |
| F-002 | Department-head scope bypassed by `loans.approve` | **Fixed** | `LoanAccessPolicy` (new file); applied at `LoanService.php:91,211,267,284` and `LoanController.php:76,122`. Measured: `employee` role `list()` returned 1 own row, 0 foreign; `GET /loans/{foreign}` → **403**; a `department_head` outside the department → **422 "not …within your row scope"** |
| F-003 | Active cancellation erases collectible debt | **Fixed (guard); write-off still absent** | `cancel()` refuses non-pending (`LoanService.php:289-291`); measured `cancel(active)` and `cancel(paid)` both REFUSED. The approved write-off/settlement path F-003 asked for still does not exist |
| F-004 | Final-pay recovery not posted to the loan ledger | **STILL OPEN — and worse than described** | see R-002 |
| F-005 | Self-service preview contract incompatible | **Fixed** | both callers send `{loan_type, principal, pay_periods}` and consume the flat row array (`spa/src/api/loans/index.ts:35-37`, `spa/src/api/self-service.ts:151-158`, `self-service/loans.tsx:283-306`) |
| F-006 | Floats at money boundaries | **Fixed on the backend; one float remains in the SPA** | `normalizeMoneyAmount` regex-gates 2dp (`LoanService.php:413-426`); cap uses `Money::mul` on `monthlyEquivalentSalary()` (`:109-113`). Measured: at-cap accepted, +₱0.01 refused, `1000.005` refused, `0.00`/`-500.00` refused. Remaining float: `detail.tsx:62-64` (see R-008) |
| F-007 | Reconciliation not historical; payroll reversal bypasses canonical rebuild | **STILL OPEN, both halves** | see R-003, R-005 |
| F-008 | Payment ledger controls / manual payment entry missing | **Partly fixed; the operational half still open** | 0477 added `amount > 0`, a `payment_type` CHECK and the `payrolls` FK; `LoanPayment` now has `HasAuditLog`. But `LoanService::recordPayment()` has **zero callers in `app/`** and no route — see R-006 |
| F-009 | Intermediate approval announced as final | **Fixed** | `LoanService.php:216-229` emits `LoanDecided` only when `isFullyApproved()` |
| F-010 | Government loan types declared but not configured | **STILL OPEN** | see R-004 / Q-1 |
| F-011 | Self-service chips classify rejected as success | **Fixed** | both surfaces now delegate to `chipVariantForStatus` (`Chip.tsx:46-106`): pending→warning, active/paid→success, cancelled→neutral, rejected→**danger** |
| F-012 | Progress/lists misleading at scale | **Partly fixed** | progress now uses total due (`detail.tsx:62`) but does it in floats (R-008); employee select is searchable (`create.tsx:44-52`) but still an un-paginated 100-row slice; self-service history still un-paginated |
| F-013 | Feature/abuse boundaries not aligned | **Preview throttled; the feature-gate half STILL OPEN and now measured** | see R-007 |
| F-014 | Documentation does not describe configured policy | **STILL OPEN** | tied to Q-1 |

Six of fourteen still reproduce in whole or part (F-004, F-007, F-008, F-010, F-013, F-014),
plus F-012's residue. **Five findings below are new to this re-audit** and none of them
appear in the prior report: R-001 (no company loan can ever be disbursed), R-005b (payroll
overdraws on a drifted aggregate), R-007 (measured feature-gate bypass), R-009 (raw PK in
an error body), R-010 (dead surfaces both directions).

## Method

Every claim below was measured, not inferred. Probes ran inside PHPUnit against
PostgreSQL 16 in a **dedicated** database created for this session
(`ogami_test_loans`, dropped at release) — never the shared `ogami_test`. Concurrency
was tested with a **second, independent PostgreSQL connection** committing rows outside
the `RefreshDatabase` transaction, because a single transactional connection cannot
demonstrate lock contention against itself. No live HTTP server was used.

---

## Broken

### R-001 — No company loan can ever be disbursed: the approval chain is permanently stalled at step 2

Classification: **Broken** · Severity: **Critical** · Scope `medium` · **separate-recommended**

`WorkflowSeeder.php:47-56` seeds the `company_loan` chain as
`department_head → production_manager → finance_officer → system_admin`.
`RolePermissionSeeder.php:149-154` defines exactly four loan permissions
(`loans.view`, `loans.create`, `loans.approve`, `loans.write_off`) and grants them to
`system_admin` (`:473`), `hr_officer` (`:490`), `finance_officer` (`:536`) and — view+approve
only — `department_head` (`:733`). **`production_manager` holds no `loans.*` permission at
all**, and it is not in `LoanAccessPolicy::isGlobal()` (`LoanAccessPolicy.php:95-100`), so it
fails the route middleware (`routes.php:20`), the service permission check
(`LoanService.php:211`) *and* `canDecide()`.

Measured — walking each seeded chain with a real user holding each step's role slug, each a
different employee so the self-approval guard is not what is being measured:

```
--- company_loan: 4 steps: department_head -> production_manager -> finance_officer -> system_admin
  step 1 (department_head   ) loans.approve=yes => APPROVED, loan status now pending
  step 2 (production_manager) loans.approve=NO  => BLOCKED: You do not have permission to
                                                   decide this loan within your row scope.
  FINAL status = pending
--- cash_advance: 3 steps: department_head -> finance_officer -> system_admin
  step 1..3 => APPROVED / APPROVED / APPROVED
  FINAL status = active
```

Over HTTP, `production_manager` gets **403** on `PATCH /loans/{loan}/approve`.

Impact: `company_loan` is one of only two requestable loan types (R-004). Every company
loan an employee files sits Pending forever — never Active, never disbursed, never
deducted. Cash advance is unaffected. This is the same class of defect the seeder itself
already documents for other modules in comments at `RolePermissionSeeder.php:446-450` and
`:578-585`. Note also that `hr_officer` holds `loans.approve` but appears at **no** step of
either loan chain, so that grant is unusable.

Decision required (Q-2): grant `production_manager` `loans.view`+`loans.approve`, or remove
the step. Either is a deliberate RBAC change to who may approve borrowing, and
`RolePermissionSeeder.php` is explicitly flagged unreviewed in `167de85e` ("30 hunks from
multiple sessions, RBAC"). Do not guess.

### R-002 — Final-pay loan recovery is unreachable; an employee with an outstanding loan cannot be separated

Classification: **Broken** · Severity: **Critical** · Scope `large` · **separate-recommended** (owner: M023 `separation-final-pay`)

Two gates make each other's work impossible:

- `SeparationService.php:296-312` refuses to finalize a clearance when **any** loan with
  `status IN ('active','pending') AND balance > 0` exists, advising the operator to
  "Settle all loans or confirm deduction in the final pay breakdown before finalizing."
- `FinalPayService.php:405-412` computes `less_loan_balance` as
  `SUM(balance) WHERE status IN ('active','pending') AND loan_type = 'company_loan'`, and
  `:240-248` emits the "Settle outstanding loan from final pay" journal line from it.

So the line is only ever computed **after** the gate has guaranteed every such balance is
zero. Measured on a real clearance-shaped fixture:

```
[INV-14 final pay] outstanding loan balance=5000.00
  SeparationService blocking count=1 (>0 => finalize refused)
  FinalPayService loanBalances()=5000.00
  => DEADLOCK: finalize is refused while a balance exists, and once the balance is 0
     loanBalances() is 0 — the recovery JE line is UNREACHABLE
  no final_pay LoanPayment rows exist (LoanPaymentType::FinalPay is unused)
```

The repo's own `tests/Feature/HR/ClearanceLoanBlockTest.php:63-98` **locks this in**: it
asserts 422 whenever `balance > 0` and 200 only when `balance = '0.00'`.

Three consequences:
1. There is no "confirm deduction in the final pay breakdown" route anywhere in
   `api/app/Modules/Loans/routes.php` or the HR self-service routes. The advice in the
   error message names a workflow that does not exist.
2. A separating employee with an outstanding loan is a **hard block with no path forward**
   except an out-of-band manual DB edit — and there is no manual payment API either (R-006).
3. `LoanPaymentType::FinalPay` (`LoanPaymentType.php:9`) is dead: even if recovery
   happened, no `LoanPayment` row would be written, so the journal and the loan row would
   disagree.

Also: `loanBalances()` filters `loan_type='company_loan'` and `openCashAdvance()` filters
`cash_advance`, so an `sss_loan`/`pagibig_loan` balance is invisible to final pay while
still blocking finalize forever.

### R-003 — `reconcileAggregates($asOf)` writes an aggregate that contradicts the payment ledger

Classification: **Broken** · Severity: **High** · Scope `medium` · **separate-recommended**

`LoanService.php:354-357` filters the ledger to `payment_date <= $asOf` and then writes
`total_paid` and `balance` from that filtered sum (`:367-374`). `PayrollCalculatorService.php:889-890`
passes the period's own `payroll_date`. Recomputing an **earlier** period after a later one
has already collected therefore rewrites the loan as if the later payment never happened.

Measured — ledger holds ₱1,000.00 in two committed payments (2026-03-15, 2026-04-15);
recompute the March period:

```
[INV-7 as-of recompute] reconcile(asOf=2026-03-15) wrote total_paid=500.00 balance=500.00 status=active
  => AGGREGATE NOW DISAGREES WITH THE LEDGER (the later payment was dropped)
```

The loan is now ₱500.00 short-credited on its own denormalized row while the immutable
ledger says it is settled. Because payroll's overdraw clamp reads that denormalized row and
not the ledger (R-005b), the loan is then re-collected.

### R-004 — Interest-bearing loan types are configured, unrequestable, and still reachable through the preview endpoint

Classification: **Broken** · Severity: **High** · Scope `medium` · **separate-recommended**

`CLAUDE.md` states **"Loans: Zero interest."** Measured configuration:

```
[INV-1b configured annual_interest_rate per type]
company_loan  => 0
cash_advance  => 0
sss_loan      => 0.1
pagibig_loan  => 0.105
[INV-1b offered types] company_loan, cash_advance
```

`0285_seed_loan_policy_settings.php:13-16` seeds the two government rates nonzero. They
cannot be *requested*: `LoanService::types()` (`:43-50`) only offers types with an active
workflow, and `request()` throws "No approval workflow is configured…" (`:172-174`) —
`WorkflowSeeder` seeds no `sss_loan`/`pagibig_loan` chain at all. But
`LoanController::previewAmortization` (`:135-150`) validates `loan_type` against the **whole
enum**, so the interest-bearing schedule is served to any authenticated user:

```
[PREVIEW REACH]
  company_loan   requestable=yes preview HTTP=200 total_repaid=10000.00
  cash_advance   requestable=yes preview HTTP=200 total_repaid=10000.00
  sss_loan       requestable=NO  preview HTTP=200 total_repaid=10549.86  <-- INTEREST CHARGED
  pagibig_loan   requestable=NO  preview HTTP=200 total_repaid=10577.79  <-- INTEREST CHARGED
```

And the `employee` role — which holds **no** `loans.*` permission — receives HTTP 200 from
this route (it is auth-only by design, `routes.php:13-16`). So the one loan endpoint an
ordinary employee can reach is the one that quotes them a rate on a product that cannot be
applied for, at ₱577.79 of interest on a ₱10,000 loan the company says is interest-free.

Note the seed itself passes money-shaped values through a `float` parameter
(`0285_…php:38: private function insert(string $key, float $value, …)`). `0.105` happens to
round-trip through `json_encode`, so this is latent rather than active.

`docs/PROCESS-FLOWS.md:1024-1048` and `docs/USER-MANUAL.md:103-108` still describe the
zero-interest, one-month-cap model, while `0325_seed_loan_period_setting.php` seeds
`loans.max_pay_periods = 60`. See Q-1.

### R-005 — Payroll reversal corrupts `pay_periods_remaining`; and R-005b — payroll overdraws because it clamps on the denormalized balance

Classification: **Broken** · Severity: **High** · Scope `large` · **separate-recommended** (owner: M021 `payroll-period-processing`)

**R-005 (period accounting).** `PayrollCalculatorService::reverseLoanDeductions` (`:920-957`)
restores state by hand — `$loan->pay_periods_remaining = $loan->pay_periods_remaining + 1`
per reversed payment row (`:948`) — while `reconcileAggregates` **derives** the same field
from schedule coverage (`LoanService.php:450-463`). Semi-monthly collection writes two
payment rows per schedule period (`:864-866` halves the amortization), so the two rules
cannot agree. Measured, void-and-re-run of both halves of one month on a 2-period loan:

```
[VOID/RERUN] after two semi-monthly halves of 250: total_paid=500.00 balance=500.00 remaining=1 (derived)
  after reverse of both payrolls: total_paid=0.00 balance=1000.00 remaining=3 ledger_sum=0 rows=0
  expected remaining for an untouched 2-period loan = 2
  => REVERSAL CORRUPTED pay_periods_remaining (3 != 2)
```

Money (`total_paid`, `balance`) is restored correctly; the schedule length is not. The field
is a live filter — `applyLoanDeductions` selects `where('pay_periods_remaining','>',0)`
(`:841`) — so an inflated value keeps a loan in the collection set past its schedule. The
same routine also `delete()`s ledger rows (`:955`), so the "immutable payment ledger" the
reconciliation comments rely on is not immutable.

**R-005b (overdraw).** `applyLoanDeductions` clamps the deduction against
`$loan->balance`, the **denormalized column** (`:860-863`), whereas `recordPayment` clamps
against a freshly summed ledger (`LoanService.php:318-325`). Given the drifted row R-003
produces, payroll collects again. Measured:

```
[CLAMP SOURCE] schedule total due=1000.00, ledger before=1000.00, denormalized balance said 1000.00
  payroll deducted: 250.00
  ledger after=1250.00 (overpaid by 250.00)
  => OVERDRAW: applyLoanDeductions clamps on the denormalized balance column, not the ledger
```

₱250.00 taken from an employee's pay against a loan that was already settled. R-003 and
R-005b compose into a real over-collection, which is why they must be fixed together.

### R-009 — `bulk-approve` returns raw integer primary keys in its error body (existence oracle)

Classification: **Broken** · Severity: **Medium** · Scope `small` · **same-session-ok**

`LoanService::bulkApprove` builds failure rows as `['id' => $id, 'reason' => …]` with `$id`
the **decoded integer PK** (`LoanService.php:249, 254`), and `LoanController::bulkApprove`
returns `$results['failed']` verbatim (`:174-179`). `HashIdFilter::decode` accepts raw
integers in **every** environment, so a caller can post PKs directly. Measured:

```
[INV-13 bulk-approve error body] status=200
  {"data":{"approved":[],"failed":[{"id":24,"reason":"Only pending loans can be approved."},
                                   {"id":999999,"reason":"Not found."}]}}
  failed row: id=24 (integer)      reason=Only pending loans can be approved.
  failed row: id=999999 (integer)  reason=Not found.
```

Two leaks in one body: the raw `employee_loans.id`, and a `reason` that distinguishes
"Not found." from a real business refusal — i.e. an enumeration oracle over the loan table.
The client cannot even use the value, since it submitted HashIDs. `tests/Feature/Loans/LoanBulkApproveTest.php:47`
currently **asserts** the leak (`assertContains(99999, $failedIds)`).

### R-008 — Loan progress is computed by adding two decimal strings as JS floats

Classification: **Broken** · Severity: **Low** (display only) · Scope `small` · **same-session-ok**

`spa/src/pages/loans/detail.tsx:62-64`:

```ts
const totalDue = Number(loan.total_paid) + Number(loan.balance);
const remainingPercent = totalDue > 0 ? Math.min(100, (Number(loan.total_paid) / totalDue) * 100) : 0;
```

`total_paid` and `balance` are typed `string` (`spa/src/types/loans.ts:24-26`) precisely
because `decimal(15,2)` exists to avoid binary-float error. Two money strings are parsed to
double and added. It drives only the progress bar (`:111`, `:114`), so no persisted amount
is affected — but it is the exact pattern `CLAUDE.md` forbids, in the module where it
matters most. `spa/src/lib/chains/loan.ts:15-16` also `parseFloat`s both fields, though only
for `> 0` / `<= 0` comparisons.

---

## Missing

### R-006 — There is no way to record a loan payment outside payroll; the service method that would is dead code

Classification: **Missing** · Severity: **High** · Scope `large` · **separate-recommended**

`LoanService::recordPayment()` (`:301-345`) is correct — row lock, ledger-derived balance,
overdraw refusal, transactional reconcile — and measured clean:

```
[INV-6 overdraw]
  after 2x333.33: total_paid=666.66 balance=333.34 remaining=1 status=active
  overpay 500.00 => REFUSED
  after final 333.34: total_paid=1000.00 balance=0.00 remaining=0 status=paid
  payment after settlement => REFUSED: Only active loans accept payments.
```

It has **no caller anywhere in `api/app/`** (`grep -rn 'recordPayment' app/` finds only
`Accounting\BillService`, unrelated). No route exposes it (`routes.php:8-23`), and no SPA
surface calls one. `LoanService::activeForPayroll()` (`:379-385`) is likewise dead — payroll
queries `EmployeeLoan` directly (`PayrollCalculatorService.php:838-849`). Consequently
`LoanPaymentType::Manual` and `::FinalPay` are both unreachable: every `loan_payments` row
in the system is written by payroll. Finance cannot settle a loan early, cannot correct a
mis-deduction, and cannot unblock the separation deadlock in R-002.

### R-011 — "One loan at a time" has no database constraint; it rests entirely on one application lock

Classification: **Missing** · Severity: **Medium** · Scope `small` · **same-session-ok** (with a production preflight)

The good news first, because it contradicts the usual finding: the guard **is** real and
**does** hold under true concurrency. `request()` takes `Employee::lockForUpdate()` *before*
the `exists()` check (`LoanService.php:134-144`), and the self-service path forwards to the
same method (`SelfServiceController.php:275-283`) rather than duplicating it. Proven with
two independent connections against a committed employee row:

```
[LOCK-PROOF] statements taking FOR UPDATE inside request():
  select * from "employees" where "employees"."id" = ? and "employees"."deleted_at" is null limit 1 for update
  ...
  => employees row lock: YES | employee_loans lock: YES

[RACE] B held 1 employee row(s). A's request() blocked on that lock: YES
  rows created by A: 0
  SQLSTATE[55P03]: Lock not available: canceling statement due to lock timeout
                   CONTEXT: while locking tuple (0,2) in relation "employees"
  => SERIALIZED: two concurrent requests for one employee cannot both pass the exists() check
```

The gap is defence in depth. There is no partial UNIQUE index equivalent to payroll's
`payroll_cycle_claims`:

```
[NO-CONSTRAINT] committed ACTIVE company_loans for one employee: 2 inserted, err=none
  => No UNIQUE index backs "one loan at a time".
```

Any future writer that does not route through `request()` — a seeder, an import, a new
service — silently breaks the invariant. The precedent the codebase already sets is
`CREATE UNIQUE INDEX … WHERE …` (see `2026_08_13_212000_add_loan_payroll_payment_idempotency.php:31-35`).
Recommended shape: `UNIQUE (employee_id, loan_type) WHERE status IN ('pending','active')`,
with the same preflight-and-refuse pattern that migration uses. It changes no correct
behaviour, but it converts a would-be duplicate from a 422 into a 500, so it needs a
production preflight before it ships.

---

## Incomplete

### R-007 — Disabling the Loans module leaves a live loan-creation endpoint (measured)

Classification: **Incomplete** · Severity: **High** · Scope `medium` · **separate-recommended** (owner: HR module)

The Loans routes are behind `feature:loans` (`api/app/Modules/Loans/routes.php:8`); the
self-service loan routes are behind `feature:hr` (`api/app/Modules/HR/routes.php:25`, routes
at `:258-259`). The prior audit inferred this; it is now measured:

```
[FEATURE TOGGLE] toggled modules.loans = false
  BEFORE: loans_index=403 ss_read=200 ss_apply=201
  AFTER : loans_index=403 ss_read=200 ss_apply=201
  loans created while module OFF: 1
```

A loan was **created and persisted with the module switched off**. (The `loans_index=403`
column is inconclusive for the toggle itself — the `employee` role used for the probe lacks
`loans.view` either way — but the creation arm is decisive.) Employees keep filing loans
into a module no operator can list, approve, cancel or deduct.

The self-service loan routes also carry **no `permission:` middleware and no throttle**
(contrast `throttle:10,1` on the adjacent overtime route at `:268`), and validate `amount`
as bare `numeric|min:1` without `decimal:0,2` (`SelfServiceController.php:263-268`) —
divergent from `StoreLoanRequest.php:34`. Over-precision is still caught downstream by
`normalizeMoneyAmount()`, but surfaces as a generic 422 message rather than a field error.

### R-012 — The double-deduction guard covers one shape of replay, not the cutoff

Classification: **Incomplete** · Severity: **Medium** · Scope `medium` · **separate-recommended**

`loan_payments_payroll_deduction_unique` is `UNIQUE (loan_id, payroll_id) WHERE payroll_id IS NOT NULL AND payment_type = 'payroll_deduction'`
(`2026_08_13_212000_…php:31-35`). It stops the exact repeat and nothing else:

```
[DOUBLE-DEDUCT SCOPE]
  same (loan,payroll) 2nd payroll_deduction: REFUSED (loan_payments_payroll_deduction_unique)
  same (loan,payroll) but payment_type=manual:   ACCEPTED
  second payroll, SAME cutoff dates:             ACCEPTED
  payroll_id NULL, same date, twice:             ACCEPTED
  ledger total now 752.00 against a 1000.00 loan (5 rows)
```

Payroll periods may legitimately share dates when their scopes are disjoint
(`CLAUDE.md`, "Payroll period scope"), so two `payrolls` rows can cover one cutoff for one
employee, and each may collect. Payroll's own answer to exactly this class of problem is a
**cycle key** — `payroll_cycle_claims` UNIQUE `(employee_id, cycle_key)` — not a
per-row-pair key. Loan collection has no equivalent, and relies on the payroll guard to
prevent the same employee being paid twice per cutoff rather than on any loan-side key.

### R-010 — Dead surfaces in both directions

Classification: **Incomplete** · Severity: **Medium** · Scope `small`–`medium` · **same-session-ok** (per item)

**Route with no client.** `POST /loans/bulk-approve` (`routes.php:17`,
`LoanController.php:156-180`) has **no SPA caller** — no `loansApi.bulkApprove` exists in
`spa/src/api/loans/index.ts:17-38`, and `spa/src/pages/loans/index.tsx` has no row
selection at all. It is also the endpoint carrying R-009's raw-PK leak.

**Page with a route but no navigation.** All four loan pages are routed
(`spa/src/routes/hrRoutes.tsx:160-171`, `spa/src/routes/selfServiceRoutes.tsx:43`), but
`/hr/loans` has **no Sidebar entry** — `Sidebar.tsx`'s Human Resources section
(`:584-676`) goes Leave → Payroll. Because `CommandPalette.tsx:35` and
`hooks/useRecentPageTracker.ts:8` both derive from Sidebar's exported `SECTIONS`, the admin
Loans module is absent from the command palette and recent-pages too. It is reachable only
by typed URL, the Loans tab on `spa/src/pages/hr/employees/detail.tsx:815`, or the
`loans.outstanding` dashboard widget (`DashboardWidgetSeeder.php:100`).

**Curated empty-state copy that never renders.** `spa/src/lib/emptyStateCopy.ts:81-89`
defines `/hr/loans` copy with `actionRoute: '/hr/loans/create'`, but
`loans/index.tsx:87-94` hard-codes its own `<EmptyState>` instead of `<ListEmptyState>`.

**Unused client capability.** `loansApi.approve` accepts `remarks`
(`spa/src/api/loans/index.ts:26`) that `detail.tsx:39` never passes.

### R-013 — SPA route guards check the wrong loan permissions

Classification: **Incomplete** · Severity: **Medium** · Scope `small` · **same-session-ok**

All three admin loan routes gate on `anyOf={['loans.approve','loans.write_off']}`
(`spa/src/routes/hrRoutes.tsx:162, 166, 170`), but the endpoints those pages call require
`loans.view` (index/options/show) and `loans.create` (types/store). The mismatch cuts both
ways: a `loans.view`-only holder is refused a list the backend would serve — including the
seeded `loans.outstanding` widget's "Open →" to `/hr/loans`, gated on `loans.view`
(`DashboardWidgetSeeder.php:272`) — while a `loans.write_off`-only holder is admitted and
then 403s, leaving `create.tsx`'s type radio group (`:126-136`) silently empty with no
error state. Two in-page controls have the inverse mismatch: the "New request" button and
the empty-state action gate on `loans.create` (`index.tsx:70`, `:91-92`) but navigate to a
route that refuses `loans.create`. No **seeded** role is currently view-only, so this is
latent today; the backend enforces independently either way, so it is UX correctness, not
an access-control hole.

### R-014 — Documentation describes a policy the code does not implement

Classification: **Incomplete** · Severity: **Low** · Scope `small` · **separate-recommended** (blocked on Q-1)

`docs/PROCESS-FLOWS.md:1024-1048` and `docs/USER-MANUAL.md:103-108` describe zero-interest
company/cash loans with a one-month cap. The code implements a 1× monthly-equivalent cap
(correctly — see below), up to 60 periods (`0325_seed_loan_period_setting.php`), and two
interest-bearing types (R-004). Unblocked by Q-1.

### R-018 — `approve()` reveals a loan's status to a caller outside its row scope

Classification: **Incomplete** · Severity: **Low** · Scope `small` · **separate-recommended**

`LoanService::approve()` checks the loan's status (`:208-210`) **before** it checks the
caller's row scope (`:211-213`); `reject()` has the same order (`:264-269`). A
`department_head` acting on a loan belonging to another department therefore receives
"Only pending loans can be approved." when the loan is not pending, and the row-scope
refusal only when it is — leaking one bit of state about a record they may not see. Measured
indirectly: a cross-department `department_head` on a Pending loan gets 422 "…within your row
scope", which is the correct arm; the other arm is reached whenever the loan has already
been decided.

Fix is to hoist the scope check above the state check in both methods. Deliberately **not**
done in this session: both guards refuse, so swapping them cannot change any accepted
outcome, but it does change which message a legitimate operator sees on the approval path,
and this audit does not make unilateral changes to loan approval behaviour.

---

## Polish

### R-015 — Admin amortization preview fires per keystroke into a 10/min throttle

Classification: **Polish** · Severity: **Medium** · Scope `small` · **same-session-ok**

`spa/src/pages/loans/create.tsx:88-95` posts to `/loans/preview-amortization` from a
`useEffect` keyed on a raw `watch()` of `principal` (`:67`) — one request per keystroke —
and the route carries `throttle:sensitive` (10/min). Typing `15000.00` exhausts the bucket.
The call has no `.catch`, so the resulting 429 becomes an unhandled rejection with no user
feedback, and no ordering guard, so a late response can install a stale schedule.
`spa/src/pages/self-service/loans.tsx:222-223` already debounces for exactly this reason.

### R-016 — Self-service loan errors and counts are lossy

Classification: **Polish** · Severity: **Low** · Scope `small` · **same-session-ok**

`spa/src/pages/self-service/loans.tsx:108` replaces the server's 422 message with a generic
`toast.error('Failed to submit loan request.')`, discarding real sentences the backend
returns via `abort(422, $e->getMessage())` (`SelfServiceController.php:284-286`) — including
the active-loan and salary-cap refusals. The admin page does this correctly with
`applyServerValidationErrors` (`create.tsx:111`). Also `periods` is missing from
`defaultValues` (`:210`) against a `z.coerce.number()` schema (`:26`), so an untouched field
yields "Expected number, received nan"; and `:118` reports `${data.history.length} past` —
the value truncated by `self_service.history_limit` — as if it were the total.

### R-017 — Employee/loan lists are un-paginated slices

Classification: **Polish** · Severity: **Low** · Scope `medium` · **separate-recommended** (HR module)

`create.tsx:44-52` requests `per_page: 100` with server-side debounced search but no
pagination; `EmployeeService.php:146` hard-caps `per_page` at 100. With 200+ active
employees the dropdown silently shows the first 100. Self-service loan history is
un-paginated on both sides (`SelfServiceController.php:224-236`); past
`self_service.history_limit`, older loans are unreachable with no notice. Neither loan list
shows a stale/`isPlaceholderData` affordance (a repo-wide convention gap, not loans-only).

---

## Verified-correct controls (measured, not assumed)

These are the invariants the prompt asked to be checked that **hold**. Recording them
matters as much as the failures.

- **Zero-interest schedules sum exactly to the principal**, remainder on the last
  instalment, across deliberately non-dividing combinations:
  `P=1000.00 n=3`, `P=10000.00 n=7`, `P=9999.99 n=13`, `P=1.00 n=6`, `P=12345.67 n=11` —
  all exact. No centavo parked on no instalment.
- **The cap uses `Employee::monthlyEquivalentSalary()`, correctly for both pay types.**
  `monthly` (basic ₱20,000) → cap ₱20,000.00; `semi_monthly` (rate ₱10,000) → cap
  **₱20,000.00**, i.e. `rate × 2`, not the half-month figure. Comparison is `bccomp`
  (`LoanService.php:148`) against a `Money::mul` product (`:113`), no float.
- **Cap boundary and money discipline:** at-cap accepted, `+₱0.01` refused, `1000.005`
  refused, `0.00` refused, `-500.00` refused, and a null/absent pay rate refused with an
  explicit `BusinessRuleException` rather than a zero cap.
- **One-loan/one-CA slot rule** holds sequentially, holds under true two-connection
  concurrency (R-011), and correctly treats company loan and cash advance as separate slots.
- **Slot release:** freed by `rejected`, `cancelled` and `paid`; correctly **not** freed
  while `pending`. A voided payroll run leaves the loan Active, so the slot stays occupied —
  which is right.
- **No overdraw on the `recordPayment` path**, including a final instalment larger than the
  regular one (₱333.34 vs ₱333.33), overpayment refusal, and refusal of any payment after
  settlement.
- **Every illegal status transition is refused:** `approve(active)`, `approve(rejected)`,
  `reject(active)`, `cancel(active)`, `cancel(paid)`, `reconcileAggregates(pending)`,
  `recordPayment(pending)`. `LoanStateMachine::TRANSITIONS` (`:21-28`) deliberately omits
  active cancellation.
- **Self-approval is refused** — a `finance_officer` who is also the borrower gets
  "You cannot act on a record you submitted." (`ApprovalService.php:129-132`), checked
  *before* the role/delegation test so no delegate or admin can bypass it.
- **Sequential step order is enforced** — `ApprovalService.php:122-135` takes the lowest
  pending step of the current attempt under `lockForUpdate()` and matches its role slug.
- **Row-level scope is server-side.** `employee` role `list()` → 1 own row, 0 foreign;
  `GET /loans/{foreign}` → 403; cross-department `department_head` → 422. The `employee`
  role is refused by **every** loans route except `preview-amortization`, which is auth-only
  by design (see R-004 for why that is still a problem).
- **Resources emit no raw PKs.** `EmployeeLoanResource.php:16, 19, 51` and
  `LoanPaymentResource.php:16` all use `hash_id`; money fields are `(string)` casts. The
  only raw-PK leak in the module is R-009, in a controller, not a resource.
- **Migration 0477 landed as claimed** — `employee_loans.interest_rate` is
  `numeric(8,6)`, and `loan_payments` carries `amount > 0`, a `payment_type` CHECK and the
  `payrolls` FK, each guarded by a preflight that refuses rather than silently skipping.

## Open questions requiring a human decision

- **Q-1 — Are `sss_loan` and `pagibig_loan` in scope, and is "zero interest" still the
  policy?** `CLAUDE.md` says zero interest; migration `0285` seeds 10% and 10.5%; commit
  `1eea7eb7` is titled "…interest-bearing loans". The three are irreconcilable. Every
  downstream item (R-004, R-014, whether `AmortizationService::generateWithInterest` should
  exist at all) waits on this. **Do not guess — it changes what employees repay.**
- **Q-2 — Who approves a company loan at step 2?** Grant `production_manager`
  `loans.view`+`loans.approve`, or delete the step, or substitute another role. R-001 is a
  total functional block either way, but the fix is an RBAC decision about who may authorise
  borrowing.
- **Q-3 — What is the intended final-pay recovery workflow (R-002)?** The error message
  promises a "confirm deduction in the final pay breakdown" step that does not exist.
  Deciding this also fixes the unrecovered-residual rule and settles whether
  `LoanPaymentType::FinalPay` is real.
- **Q-4 — Is active-loan write-off ever permitted?** `LoanStateMachine` deliberately omits
  it and `cancel()` refuses it, which is the safe default, but there is then no way to
  dispose of an uncollectible loan (interacting with Q-3).
- **Q-5 — Should `loans.max_pay_periods = 60` stand** against the documented one-month cap,
  given the cap on principal is 1× monthly equivalent? A 60-period schedule on a
  one-month-salary principal is ₱333/month on ₱20,000 — plausible, but it contradicts the
  manual.

## Could not verify

- **Browser/E2E behaviour.** No Playwright run was attempted; `spa/e2e/` has no loans spec.
  The SPA findings (R-008, R-013, R-015, R-016, R-017) are from source reading plus the
  backend contracts they call, not from a rendered page.
- **`LoanService::list()` cross-department leakage for a `department_head` whose own
  employee row has a null `department_id`.** `LoanAccessPolicy::visibleTo` (`:44-51`) omits
  the department clause entirely in that case, narrowing to self — safe — but I did not
  construct the fixture.
- **Whether the dev/production `settings` table matches `0285`'s seeded rates.** Measured
  values are from a freshly migrated database. `MEMORY.md` records that the running Postgres
  drifts from the seeders, so live rates may differ.
- **Behaviour of `reverseLoanDeductions` when a loan has been `paid` and then partially
  manually repaid**, because no manual payment path exists to produce that state (R-006).
