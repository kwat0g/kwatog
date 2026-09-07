# Audit: loans — 2026-09-06

## Summary

The loans module's money core is unusually sound: deduction is ledger-derived
(immutable `loan_payments`, aggregates rebuilt under `lockForUpdate`), payroll
recompute correctly reverses then re-applies (regression-tested), a partial
unique index blocks duplicate payroll deductions per payroll row, and the 1+1
rule is race-safe (`lockForUpdate` on the employee row, pending+active counted).
`LoanAccessPolicy` is the single row scope and the Approval Queue reuses it.
The two structural breaks are at the lifecycle edges: (1) **company_loan
approvals stall dead at step 2** — production_manager lacks `loans.approve` and
row visibility, and even system_admin cannot act on a role step it doesn't hold
(PS-01 loan side, confirmed); (2) **there is no way to settle a loan outside
payroll** — `LoanService::recordPayment` is dead code with no route, cancel is
pending-only, so a separated employee with residual balance can never clear the
separation-finalize loan gate (deadlock). `is_final_pay_deduction` is confirmed
fully dead. Latent interest-bearing machinery deviates from the zero-interest
spec and would mis-price semi-monthly periods if ever enabled. 38 loan-related
tests pass (run once, 27s).

## Findings

| ID | Category | Sev | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| LN-01 | Broken process | High | S | company_loan approval chain stalls at step 2 (PS-01 loan side) | api/database/seeders/RolePermissionSeeder.php:590-633; api/app/Modules/Loans/Services/LoanService.php:211; api/app/Modules/Loans/Policies/LoanAccessPolicy.php:25-52 | company_loan step 2 = production_manager (WorkflowSeeder.php:52). production_manager holds NO `loans.approve` (fixed for PR/return chains in same file via M036, not loans). LoanService::approve throws before ApprovalService checks role. Also invisible on Approval Queue: visibleTo gives non-dept_head non-global users only own loans. `userMayActFor` requires exact role match/delegation, so even system_admin cannot push step 2. Every company_loan stalls after dept-head approval. |
| LN-02 | Missing | High | M | No settlement/manual-payment/write-off path; separated employee with balance is unresolvable | api/app/Modules/Loans/Services/LoanService.php:308-352; api/app/Modules/Loans/routes.php; api/app/Modules/HR/Services/SeparationService.php:381-396 | `recordPayment()` is fully wired (tested in LoanPaymentSerializationTest) but has NO route and NO caller outside tests. Cancel refuses active loans ("use the approved write-off or settlement workflow" — which does not exist; LoanStateMachine.php:13-15 admits it is undefined). Separation finalize hard-blocks while any active/pending loan has balance>0. Employee whose final payroll cannot drain the balance (or who separates mid-tenure) can never finalize clearance → final pay permanently blocked. `LoanPaymentType::FinalPay` unused anywhere. |
| LN-03 | Gap | Medium | M | `is_final_pay_deduction` confirmed dead; final-pay/loan ledger never reconciled | api/app/Modules/Loans/Models/EmployeeLoan.php:29; api/app/Modules/HR/Services/FinalPayService.php:209-247 | Flag is fillable + serialized + typed in SPA but never written or read by any logic (grep: only model/resource/type). FinalPayService computes `less_loan_balance` and even builds a "Settle outstanding loan from final pay" JE credit, but never creates a LoanPayment nor reconciles the loan. Since the finalize gate forces balance=0 first, that JE arm is unreachable and the compute-time breakdown shows a deduction that never materializes. Cross-module: HR. |
| LN-04 | Risk | Medium | M | Interest-bearing path deviates from zero-interest spec and would mis-price semi-monthly deductions | api/app/Modules/Loans/Services/AmortizationService.php:57; api/app/Modules/Loans/Services/LoanService.php:394-397; api/database/seeders/SettingsSeeder.php:217-224 | Spec: "Zero interest". Code has full PMT amortization driven by settings. Seeded 0 for company_loan/cash_advance (OK today), but SSS=0.10, Pag-IBIG=0.105 seeded, and any admin can raise rates via settings. `monthlyRate = annual/12` assumes monthly periods while deductions run semi-monthly at half amortization → each half-cutoff accrues a full month's interest (~2x overcharge) if enabled. Gov loan types have enum+settings but no seeded workflow, so they are currently un-submittable (types() filters by active workflow). |
| LN-05 | Gap | Medium | M | No disbursement accounting: activation books nothing; SPA claims "approve and disburse" | api/app/Modules/Loans/Services/LoanService.php:217-222; spa/src/pages/loans/detail.tsx:212 | Final approval flips status to Active with zero GL effect — no loans-receivable/cash JE for cash advances (which are by definition cash out). Payroll GL credits `loans_payable_code` on deduction (PayrollGlPostingService.php:285-286) although no receivable/payable was ever established. ConfirmDialog copy promises disbursement that never happens. Cross-module: Accounting. |
| LN-06 | Risk | Low | S | bulk-approve echoes raw exception messages; unbounded ids array | api/app/Modules/Loans/Services/LoanService.php:260-262; api/app/Modules/Loans/Controllers/LoanController.php:158-162 | `catch (\Throwable)` returns `$e->getMessage()` per row — a DB/query failure would surface schema text to the client. `ids` array has no max size (per-row transactions, DoS-ish). HashID oracle aspect is already guarded and tested. |
| LN-07 | Bad practice | Low | S | `pay_periods_remaining` on recompute reversal is +1 approximation, not a recompute | api/app/Modules/Payroll/Services/PayrollCalculatorService.php:956-958 | reverseLoanDeductions does `remaining + 1` per reversed payment. Forward reconcile derives it from schedule vs paid; when a large manual payment already covered several schedule rows, the reversed instalment may not have reduced remaining by 1 → remaining can transiently exceed pay_periods_total. Deduction filter (`> 0`) unaffected; cosmetic but inconsistent with the ledger-derived invariant used everywhere else. |
| LN-08 | Gap | Low | S | Thin test coverage on risky eligibility/scope paths; docs drift | api/tests/Feature/Loans/ (4 files); docs/SCHEMA.md:171-177 | No tests for: 1+1 concurrent-application race, cancel path, LoanAccessPolicy dept-head vs self scoping on approve/reject, approval-queue visibility, final-pay gate interplay. Payroll-side deduction/recompute IS covered (PayrollCalculatorServiceTest, PayrollRecomputeIntegrityTest — both pass). SCHEMA.md LOANS section stale: missing interest_rate, pay_periods_total, approval_chain_size, government_reference_no, `rejected` status, loan_payments.payment_type. |
| LN-09 | Bad practice | Low | S | Dashboard widget re-derives loan row scope instead of calling LoanAccessPolicy | api/app/Modules/Dashboard/Services/Analytics/LoanWidgetAnalytics.php:33-46 | Widget uses "company-wide under loans.write_off, else caller's department" re-expression. For non-global, non-dept_head users it exposes department-level balances the module list would refuse (module shows own rows only). Aggregator rule says call the module's row scope. Cross-module: Dashboard. |

### Detail — LN-01 (company_loan chain stalls at step 2)

Repro path: employee submits company_loan → dept_head approves (has loans.approve +
dept row scope ✓) → step 2 pending record has role_slug `production_manager`.
Any production_manager hitting approve fails twice over: `LoanService::approve`
checks `hasPermission('loans.approve')` first and throws ("do not have permission
to decide this loan within your row scope"), and the loan never appears on their
Approval Queue because `LoanAccessPolicy::visibleTo` scopes non-global,
non-dept_head users to their own employee_id. The role-match escape hatch doesn't
help: `userMayActFor` accepts only exact role match or an active delegation, so
system_admin cannot stand in either. Only a manually seeded ApprovalDelegation
unblocks the chain. This is the exact defect class already fixed for
purchase_request (M036 comment in the seeder) and return_management (L-37) —
loans was skipped. Fix is small: grant production_manager `loans.view` +
`loans.approve`, and extend LoanAccessPolicy to give step roles the loans they
can legitimately decide (or route queue visibility through the current-step role).

### Detail — LN-02 (no settlement path)

Lifecycle: apply → approve → Active → payroll deducts each run → Paid. Every
non-payroll exit is closed: cancel = pending-only; no manual payment route;
`recordPayment` reachable only from tests. Separation finalize
(SeparationService.php:381-396) throws while any active/pending loan carries
balance > 0, "Settle all loans or confirm deduction in the final pay breakdown
before finalizing" — but the system offers neither action. An employee separating
before amortization completes (the normal case for long company loans) therefore
cannot finalize clearance and cannot receive final pay, and HR has no in-system
remedy. FinalPayService's deduction of loan balance at compute time does not
settle the ledger either (see LN-03). This is the single highest-value fix in the
module: expose recordPayment (permission-gated, `loans.write_off`) with
LoanPaymentType::Manual/FinalPay and wire it to the clearance flow.

## Cross-module flags

- **Payroll** (target unit): deduction engine lives in PayrollCalculatorService.php:840-966. Works correctly (locks, reversal order, idempotency index), but `LoanPayment::create` there omits `payment_type` (relies on DB default) and the `+1` remaining approximation (LN-07) is on payroll side.
- **HR** (target unit): is_final_pay_deduction dead + FinalPayService never settles the loan ledger (LN-03); separation finalize loan gate has no satisfying path (LN-02).
- **Accounting** (target unit): no JE at loan activation/disbursement; payroll credits loans_payable without a booked receivable (LN-05).
- **Dashboard** (target unit): LoanWidgetAnalytics re-derives row scope instead of LoanAccessPolicy (LN-09).
- **Common/Approvals**: PS-01 confirmed for loans (LN-01); workflow seeds use `system_admin` as final "VP" step because no vice_president role exists (SEEDS.md:240-241 still documents vice_president/manager roles — doc drift, not a defect).
- **Docs**: SCHEMA.md LOANS section and SEEDS.md workflow table drift from implementation (LN-08).

## What was NOT checked

- SPA self-service loans page (spa/src/pages/self-service/loans.tsx) — outside owned scope; backend self-service endpoints (HR SelfServiceController) spot-checked only.
- Full correctness of PayrollGlPostingService beyond the loan credit line.
- Delegation UI/flow as the practical workaround for LN-01.
- Notifications listeners beyond confirming event emission tests pass.
- No dynamic test of the interest path with payroll deduction (no such test exists; flagged in LN-04).
