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
