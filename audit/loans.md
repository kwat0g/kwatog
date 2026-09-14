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

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Re-audit verdict

The original approval-chain stall is repaired in the current source: the seeded
company-loan/cash-advance step roles now hold the route permissions, the loan row
scope includes plant-wide chain participants, and the current walk tests cover the
VP and production-manager steps. The prior no-settlement finding is only partly
closed: the API route and final-pay ledger bridge now exist, but the owned Loans SPA
still cannot invoke manual settlement. The money ledger remains transactionally
strong, but an as-of payroll reconciliation can overwrite the current aggregate from
only a historical subset of payments. Disbursement accounting, final-pay flag
semantics, dashboard scope reuse, and notification handoffs remain unresolved.

### Prior findings — changed status

| ID | Current status | Current evidence |
|---|---|---|
| LN-01 | **Resolved in current source; deployment residual below** | `api/database/seeders/WorkflowSeeder.php:40-56` names `production_manager` and `vice_president`; `api/database/seeders/RolePermissionSeeder.php:600-615,671-721` grants the act permissions; `api/app/Modules/Loans/Policies/LoanAccessPolicy.php:83-105,143-151` adds chain-participant visibility/decision scope; `api/tests/Feature/Loans/LoanApprovalChainWalkTest.php:76-166` walks both chains. |
| LN-02 | **Partially mitigated; operator path still incomplete** | `api/app/Modules/Loans/routes.php:23` and `api/app/Modules/Loans/Controllers/LoanController.php:119-143` expose a permission-gated manual payment route; `api/app/Modules/HR/Services/FinalPayService.php:96-108,341-372` records final-pay payments in the loan ledger. No `recordPayment` method exists in `spa/src/api/loans/index.ts:17-37`, and `spa/src/pages/loans/detail.tsx:86-96` has no settlement action/form. Residual balances after final-pay recovery still require a direct API call rather than the supported Loans UI. |
| LN-03 | **Partially mitigated; policy/implementation mismatch remains** | Payroll now skips rows where `is_final_pay_deduction` is false at `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:941-948`, and final pay creates `final_pay` ledger rows. However, `api/app/Modules/HR/Services/FinalPayService.php:564-580` includes every active/pending company loan and cash advance without the flag, while `api/app/Modules/Loans/Models/EmployeeLoan.php:22-30` and `api/app/Modules/Loans/Services/LoanService.php:177-190` expose the flag but never establish its value as part of the loan request policy. `api/tests/Feature/HR/FinalPayTest.php:343-375` deliberately expects a false-flag loan to be deducted, confirming the unresolved semantics. |
| LN-04 | **Mitigated for active company/cash workflows; latent residual risk** | Current seeded company-loan and cash-advance rates are zero at `api/database/seeders/SettingsSeeder.php:217-220`, and `LoanService::types()` filters out inactive/unwired government workflows at `api/app/Modules/Loans/Services/LoanService.php:41-63`. The latent path still uses a monthly PMT rate at `api/app/Modules/Loans/Services/AmortizationService.php:47-69`, while payroll treats every non-cash loan as the half-amortization company-loan case at `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:959-965`. Enabling a government workflow would need an explicit period/rate policy and regression coverage. |
| LN-05 | **Open** | Final approval still only transitions the row to `active` at `api/app/Modules/Loans/Services/LoanService.php:216-228`; no disbursement or receivable/cash journal is created. Payroll later credits `loans_payable` at `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:285-286`. The SPA still promises “approve and disburse” at `spa/src/pages/loans/detail.tsx:207-215`. |
| LN-06 | **Open** | `api/app/Modules/Loans/Services/LoanService.php:246-264` still catches every throwable and returns `$e->getMessage()`, while `api/app/Modules/Loans/Controllers/LoanController.php:185-201` validates `ids` as an array without a maximum size. |
| LN-07 | **Open** | `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:1029-1064` still reverses each payroll payment with `pay_periods_remaining + 1`, bypassing the canonical schedule-derived `LoanService::remainingPeriods()` logic. A manual partial payment can make the reported remaining count exceed the schedule after recompute. |
| LN-08 | **Partially mitigated; source-of-truth drift remains** | Current scope contains seven Loans feature-test files, including settlement, policy, bulk-approval, and full-chain-walk coverage, rather than the four files recorded in `audit/SCOPE-MAP-2026-09-06.md:34`. Coverage still lacks a real two-connection request race, cancellation route/lifecycle coverage, and final-pay gate integration. `docs/SCHEMA.md:171-177` still omits current loan columns/types, `docs/SEEDS.md:240-243` still describes the old manager/VP workflow shape, and `api/tests/Feature/Loans/LoanApprovalChainTest.php:21-27,129-157` manually uses `system_admin` as the final step instead of the current seeded VP chain. |
| LN-09 | **Open and broader than previously recorded** | The rich widget still reconstructs scope at `api/app/Modules/Dashboard/Services/Analytics/LoanWidgetAnalytics.php:35-67`, and the scalar widget independently does so at `api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:263-280`. Both use department-wide fallbacks that do not match `api/app/Modules/Loans/Policies/LoanAccessPolicy.php:49-105` for roles such as `production_manager`, which receive own/chain-participant rows rather than all departmental rows. |

### New and materially changed findings

| ID | Category | Severity | Effort | Finding | Exact location | Evidence / reproduction |
|---|---|---|---|---|---|---|
| LN-10 | Risk | High | M | Historical payroll reconciliation rewinds the current loan aggregate | `api/app/Modules/Loans/Services/LoanService.php:358-384`; caller `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:983-996` | `reconcileAggregates()` accepts an `$asOf` date, sums only `payment_date <= $asOf`, then writes `total_paid`, `balance`, `pay_periods_remaining`, `end_date`, and status back to the live loan row. Reproduction: record a later manual payment on an active loan, then recompute an earlier payroll period; the payroll path re-saves an aggregate that excludes the later immutable payment. Subsequent deduction logic reads the rewound balance, so the stored aggregate no longer equals the full payment ledger until another unbounded reconciliation. `RecordLoanPaymentRequest.php:20-22` also accepts future dates, making this path easy to trigger through the API. |
| LN-11 | Broken process | Medium | M | Loan submission and intermediate approval notifications target the wrong stage | `api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php:35-48`; `api/database/migrations/0376_seed_workflow_notification_roles.php:16-20`; `api/app/Modules/Loans/Services/LoanService.php:204-228` | The first workflow step is `department_head` (`api/database/seeders/WorkflowSeeder.php:40-55`), but the seeded submission audience is only `finance_officer`, and the message says “awaiting Finance approval”. `LoanService::approve()` emits `LoanDecided` only when the entire chain is final, so no next-step notification is emitted after department-head, manager, or finance approval. The approval board can still expose work, but a normal notification-driven handoff sends it to the wrong role and omits intermediate handoffs. |
| LN-12 | Gap | High | M | Existing databases are not migrated from the old loan workflow steps to VP | `api/database/migrations/0483_grant_chain_step_roles_their_route_permissions.php:19-24,28-57`; `api/database/seeders/WorkflowSeeder.php:201-208` | The migration backfills permissions only; it does not update `workflow_definitions` rows. The new role layout exists only when `WorkflowSeeder` is run. On an existing database whose `company_loan` row still has `system_admin` as the final step, `LoanService::request()` snapshots that old active definition and continues routing business approval to IT even though the source seeder now says `vice_president`. The migration comment explicitly limits itself to grants and says fresh installs get them from the seeder. |
| LN-13 | Bad practice | Low | S | Approval controls are rendered from permission presence, not the current approval step | `spa/src/pages/loans/detail.tsx:86-96`; backend contract `api/app/Modules/Loans/Resources/EmployeeLoanResource.php:40-56` | Any user for whom `can('loans.approve')` is true sees Approve/Reject on every pending loan opened by the page; the page does not derive or check the pending approval record role. A department head can open a department loan waiting on Finance, or a chain participant can see a row after their step, and receives a predictable 403/422 only after clicking. The backend correctly rejects out-of-turn actions, so this is not an authorization bypass, but it violates the approval-action pattern and creates misleading available actions. |
| LN-14 | Bad practice | Low | S | Payroll loan payments rely on a database default instead of recording their enum type | `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:983-990`; default `api/database/migrations/0030_create_loan_payments_table.php:17-20` | The payroll path creates `LoanPayment` without `payment_type`; it currently works only because the column default is `payroll_deduction`. The Loans model has a `LoanPaymentType` enum but `api/app/Modules/Loans/Models/LoanPayment.php:18-23` does not cast the field to it. A future schema/default change can silently misclassify payroll ledger rows or fail at the database check instead of being explicit in the canonical writer. |

### Clean areas

- HashID boundaries remain present on `EmployeeLoan` and `LoanPayment`, and the in-scope SPA API uses string identifiers rather than integer URLs (`api/app/Modules/Loans/Models/EmployeeLoan.php:7-20`, `spa/src/api/loans/index.ts:20-37`).
- Loan request, approval, rejection, cancellation, manual payment, and final-pay settlement mutations are transaction-wrapped; payment writes lock the authoritative loan row before checking balance and rebuilding aggregates (`api/app/Modules/Loans/Services/LoanService.php:127-201,204-231,267-354`).
- The one-loan-per-type request race is serialized on the employee row and checks both pending and active rows (`api/app/Modules/Loans/Services/LoanService.php:132-143`); the current chain-walk and settlement tests cover the principal approval and payment paths at source level.
- Payroll payment idempotency and positive/payment-type database guards are present in the current migration history (`api/database/migrations/2026_08_13_212000_add_loan_payroll_payment_idempotency.php:17-39`, `api/database/migrations/0477_harden_loan_precision_and_payment_integrity.php:78-150`).
- The Loans list and create pages use TanStack Query, skeleton/error/empty/data states, mutation loading states, server validation mapping, semantic chips, and decimal strings in API-facing types (`spa/src/pages/loans/index.tsx:23-30,85-99`, `spa/src/pages/loans/create.tsx:26-35,103-119,211-216`, `spa/src/types/loans.ts:15-71`).
- Approval-board registration and chain-role permission drift coverage now include both enforced loan workflow types (`api/app/Common/Support/ApprovalTypeRegistry.php:44-52`, `api/tests/Feature/Approvals/ApprovalChainRolePermissionDriftTest.php:41-52,106-150`).

### Verification limits

- This is a read-only source audit at `56e0d431`; no tests, Docker, Artisan, browser, queue, migration, or database commands were run.
- The current worktree contains unrelated pre-existing edits in other audit files and `spa/playwright.config.ts`; none were changed. `audit/loans.md` was only appended with this section.
- Source-level tests were read but not executed, so the approval-chain walk, settlement route, migration ordering, queue notification delivery, and concurrent/as-of aggregate behavior have no runtime verification in this re-audit.
- `spa/src/pages/self-service/**` and its HR-owned self-service endpoints remain outside the Loans unit's owned page/API scope; they were not used to close any finding here.
- Existing deployed workflow/settings rows, role assignments, database constraints, and notification preferences cannot be inferred from source alone; LN-12 specifically requires a migration-state check in a deployment-like database.

### No code changes

No application code, migration, test, registry, or roadmap file was modified. Only this dated re-audit section was appended to `audit/loans.md`.
