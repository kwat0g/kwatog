# M020 — Loans & Cash Advances action plan

Decision: **Plan Ready**. No production-code fixes in this audit session; the majority of work is financial-integrity, RBAC, state-machine, or cross-module and is therefore separate-recommended.

## Ordered implementation actions

1. **Canonicalize rate and money precision** — `[large] [separate-recommended] [F-001, F-006]`
   - Preserve annual rates such as 10.5% without two-decimal truncation; centralize fractional-rate and UI-percent conversion.
   - Validate/normalize all principals to two-decimal money strings before cap checks, schedule generation, and persistence.
   - Replace float-based salary-limit arithmetic with `Money`/BCMath string operations.
   - Acceptance: 10.5% persists and reconciles exactly; three-decimal input is rejected; cap-boundary tests pass.

2. **Enforce row-level RBAC for every loan decision** — `[large] [separate-recommended] [F-002]`
   - Create one visibility/decision policy for list, show, limits, approve, reject, and bulk approve.
   - Keep system-admin/finance global scope and department-head own-department scope; re-check inside locked mutations.
   - Acceptance: cross-department department-head reads and decisions return 403/empty scope; own-department and finance cases remain valid.

3. **Introduce an explicit loan state machine and write-off workflow** — `[large] [separate-recommended] [F-003]`
   - Define allowed transitions and distinguish cancellation, write-off, settlement, rejection, and paid states.
   - Require actor, reason, approval/SoD, audit event, and journal/reference handling for write-off.
   - Align the detail-page action with `loans.write_off`.
   - Acceptance: no positive-balance loan can silently leave payroll/final-pay collection; transition and permission tests cover every edge.

4. **Integrate final-pay recovery with the loan ledger** — `[large] [separate-recommended] [F-004]`
   - Add an idempotent final-pay confirmation/settlement path that creates `LoanPaymentType::FinalPay`, reconciles the loan, and records recoverable/unrecovered balance.
   - Coordinate clearance locking and balanced journal posting in one transaction with retry behavior.
   - Acceptance: final-pay deduction closes/reduces the loan exactly once and separation can finalize only after the ledger/journal agree.

5. **Make reconciliation and payroll reversal ledger-derived** — `[large] [separate-recommended] [F-007, F-008]`
   - Specify as-of dates and filter payment rows accordingly.
   - Derive remaining periods/amount from the schedule and ledger, not payment-row count.
   - Replace direct reversal arithmetic/deletion with auditable reversal entries and canonical reconciliation.
   - Add payment FK/reference integrity, amount/type constraints, immutable audit coverage, and a permissioned manual-payment API/UI.

6. **Repair the self-service preview contract** — `[medium] [separate-recommended] [F-005]`
   - Include `loan_type` in request/query state and choose one response shape for admin and self-service.
   - Add backend contract coverage and frontend render coverage for all supported rate types.

7. **Decide and complete government-loan scope** — `[medium] [separate-recommended] [F-010]`
   - If SSS/Pag-IBIG remain in scope, seed approval workflows, expose/reference government identifiers, and correct labels/rates.
   - If not, remove or explicitly de-scope their settings/types from user-facing behavior.

8. **Separate approval-step notifications from final decisions** — `[medium] [separate-recommended] [F-009]`
   - Emit a step-approved event for intermediate approvals and final decision notifications only on Active/Rejected transitions.
   - Add one-step and multi-step notification tests.

9. **Harden feature and preview boundaries** — `[medium] [separate-recommended] [F-013]`
   - Align HR self-service routes with the Loans feature gate.
   - Add a narrow throttle/cache to amortization preview and test feature-disabled behavior.

10. **Correct low-risk frontend semantics** — `[small] [same-session-ok] [F-011, F-012]`
    - Explicitly map rejected/cancelled statuses to danger/neutral variants.
    - Base progress on total due/balance and use the canonical rate formatter.
    - Add bounded/searchable employee selection and paginated self-service history where the API supports it.

11. **Reconcile documentation with the shipped policy** — `[small] [same-session-ok] [F-014]`
    - Update process flow, user manual, and browser-test role/policy examples after the government-loan and write-off decisions are settled.

## Open decisions

- Are SSS and Pag-IBIG loans part of the supported M020 product scope, or should they be removed from the active policy surface?
- Should an active cancellation mean a true write-off, a pre-disbursement cancellation only, or a settled/reversed loan?
- For final pay that cannot recover the full balance, should the residual remain collectible, be written off through an approval path, or block separation?

---

# Action plan — re-audit 2026-08-30

Ordered by blast radius, not by ease. Every item carries Scope and a Session
recommendation. `separate-recommended` dominates, but each one is justified from the
measurement, not asserted by default — items 8–12 are `same-session-ok` precisely because
they change no amount anyone is paid.

The dividing line used throughout: **does this change a number that lands on a payslip or a
journal entry?** If yes, `separate-recommended` regardless of diff size. If it only refuses
an input that is already invalid, or corrects a display, or removes an identifier from a
response body, it is judged on containment.

## Blocked on a human decision — do not start

| # | Item | Scope | Session | Blocking question |
| --- | --- | --- | --- | --- |
| 0a | R-004 / R-014 — resolve interest policy for `sss_loan`/`pagibig_loan`, then align settings, `previewAmortization` validation, and the two docs | `large` | separate-recommended | **Q-1** |
| 0b | R-001 — restore a completable `company_loan` chain | `medium` | separate-recommended | **Q-2** |
| 0c | R-002 — design the final-pay recovery/settlement workflow | `large` | separate-recommended | **Q-3**, **Q-4** |

## 1 — R-001: make `company_loan` disbursable again

Scope `medium` · **separate-recommended**

`production_manager` sits at step 2 of the seeded chain and holds no `loans.*` permission, so
no company loan has ever reached Active. Either grant it `loans.view`+`loans.approve` in
`RolePermissionSeeder.php` (near the existing block at `:558-601`) **and** add it to
`LoanAccessPolicy::isGlobal()` or give it a department scope, or remove the step from
`WorkflowSeeder.php:51-54`.

Why separate: it is an RBAC change to *who may authorise borrowing*, `RolePermissionSeeder.php`
is explicitly flagged unreviewed in `167de85e` ("30 hunks from multiple sessions, RBAC"), and
the two candidate fixes have different governance meanings (four-eyes vs three-eyes). Needs
Q-2 answered. Add a test that walks every seeded loan chain to Active — the absence of one is
why this shipped.

## 2 — R-003 + R-005b: stop the aggregate/ledger divergence that lets payroll re-collect

Scope `large` · **separate-recommended**

These are one defect in two files and must land together. `reconcileAggregates($asOf)`
(`LoanService.php:354-374`) writes `total_paid`/`balance` from a date-filtered ledger, so
recomputing an earlier period drops a later payment; `applyLoanDeductions`
(`PayrollCalculatorService.php:860-863`) then clamps against that drifted column instead of
the ledger. Measured composition: ₱250.00 collected against an already-settled ₱1,000 loan.

Direction: make the persisted aggregate always reflect the **whole** ledger (an as-of view is
a read concern, not a write concern — return it, do not store it), and make every overdraw
clamp read the ledger sum as `recordPayment` already does.

Why separate: it changes a deduction amount on a payslip. It also crosses into M021
(`payroll-period-processing`), which is not this module's to edit.

## 3 — R-005: make payroll reversal use the canonical rebuild

Scope `medium` · **separate-recommended** (owner: M021)

`reverseLoanDeductions` (`PayrollCalculatorService.php:920-957`) restores
`pay_periods_remaining` with `+1` per reversed row while `reconcileAggregates` derives it from
schedule coverage; with semi-monthly halves the two cannot agree (measured 3 on a 2-period
loan), and the field is a live collection filter. It also `delete()`s ledger rows, so the
"immutable ledger" the reconciliation depends on is not immutable.

Direction: delete-then-`reconcileAggregates()`, or better, write a reversing ledger entry and
rebuild — never hand-adjust denormalized fields. Depends on item 2.

## 4 — R-002: final-pay recovery

Scope `large` · **separate-recommended** (owner: M023 `separation-final-pay`)

Today `SeparationService.php:296-312` refuses finalize while a balance exists and
`FinalPayService.php:405-412` sums only `active|pending`, so the recovery journal line is
structurally unreachable and a borrower can never be separated. Needs the workflow decision
(Q-3) first, then one transaction that locks the clearance and the loan, writes an idempotent
`final_pay` `LoanPayment`, reconciles, persists the unrecovered residual, and posts the
balanced JE. Note `ClearanceLoanBlockTest.php:63-98` asserts the current behaviour and will
need rewriting — read it as a specification of the deadlock, not as coverage.

Also fix the type filters: `loanBalances()`/`openCashAdvance()` ignore `sss_loan`/`pagibig_loan`
while the finalize gate blocks on them.

## 5 — R-006: a permissioned payment/settlement API

Scope `large` · **separate-recommended**

`recordPayment()` is already correct and already measured clean; it simply has no caller,
route or UI, so `LoanPaymentType::Manual` and `::FinalPay` are unreachable. Needs a
permission (a new `loans.payment.record`, or `loans.write_off`), idempotency key, reversal
semantics, and a SPA surface. This is the tool item 4 needs and the only way finance can
correct a mis-deduction.

Why separate: it creates a path that moves money and needs its own permission design.

## 6 — R-012: give loan collection a per-cutoff key

Scope `medium` · **separate-recommended**

`loan_payments_payroll_deduction_unique` binds `(loan_id, payroll_id)`, which measurably does
not stop a `manual`-typed row on the same payroll, a second `payrolls` row covering the same
cutoff, or two `payroll_id IS NULL` rows. Payroll's own answer to this class of problem is a
**cycle key** (`payroll_cycle_claims` UNIQUE `(employee_id, cycle_key)`); loans should carry
the analogous `(loan_id, cycle_key)`. Depends on items 2–3, since the key must survive a
void-and-re-run.

## 7 — R-007: align the loan feature gate

Scope `medium` · **separate-recommended** (owner: HR module)

A loan was created with `modules.loans = false`, because the self-service routes sit behind
`feature:hr` (`api/app/Modules/HR/routes.php:25`) while the module's own routes use
`feature:loans`. Add `feature:loans` to the two self-service loan routes (`:258-259`), plus
the missing throttle, and tighten `amount` to `decimal:0,2` to match `StoreLoanRequest`.

Why separate despite being a two-line middleware change: the file is outside this module's
scope, and adding a gate can turn a working employee flow off in an environment where the
toggle is already false — that needs the owning module's judgement, not mine.

---

## Contained — safe to do in one session

These change no amount anyone is paid or deducted. Items 8–11 were executed this session;
see `fix-log.md`.

## 8 — R-009: stop echoing raw integer PKs from `bulk-approve`

Scope `small` · **same-session-ok** · **DONE this session**

Pure serialization change in `LoanService::bulkApprove` + `LoanController::bulkApprove`.
Contained because: the endpoint has **no SPA client** (R-010), so no consumer can break; it
touches no money field; and the existing assertion that locks the leak
(`LoanBulkApproveTest.php:47`) is inside this module.

## 9 — R-008: remove float money arithmetic from the loan detail progress bar

Scope `small` · **same-session-ok** · **DONE this session**

`detail.tsx:62-64` parses two decimal strings to double and adds them. Display-only — it
drives a progress bar, nothing persisted — so containment is total, but it is the exact
pattern `CLAUDE.md` forbids and it sits in the money module.

## 10 — R-013: align the SPA loan route guards with the backend permissions

Scope `small` · **same-session-ok** · **DONE this session**

`hrRoutes.tsx:162,166,170` gate on `loans.approve|loans.write_off`; the endpoints need
`loans.view` and `loans.create`. Contained because frontend guards are UX only — the backend
enforces independently — and because **no seeded role is currently view-only**, so the change
alters nothing for any role that exists today. It fixes the seeded `loans.outstanding`
widget's "Open →" being refused, and stops a `write_off`-only holder landing on a create form
whose type list silently 403s to empty.

## 11 — R-015: debounce and error-handle the admin amortization preview

Scope `small` · **same-session-ok** · **DONE this session**

`create.tsx:88-95` fires one POST per keystroke into a 10/min throttle with no `.catch` and
no ordering guard. The fix mirrors what `self-service/loans.tsx:222-223` already does, so
there is a working pattern in-repo to copy rather than invent.

## 12 — R-011: add the missing partial UNIQUE index behind the slot rule

Scope `small` · **same-session-ok in principle — deferred this session, deliberately**

`UNIQUE (employee_id, loan_type) WHERE status IN ('pending','active')`, following
`2026_08_13_212000_add_loan_payroll_payment_idempotency.php:20-35` (preflight that *refuses*
rather than silently skipping).

It qualifies as contained under the "closes a race without changing correct behaviour" test —
the application lock already holds under real concurrency, so the index can only fire on a
path that is already a bug. Deferred anyway for two honest reasons: (a) it converts a
would-be duplicate from a 422 into a 500 unless `request()` catches the violation, so it
wants a small behavioural change alongside it; and (b) `MEMORY.md` records that the running
Postgres drifts from the seeders, so the preflight must be run against real data before this
ships — which I cannot do from a test database.

**Migration naming, checked rather than assumed:** it would depend only on `employee_loans`
(created by `0029_`), so a `0NNN_` prefix is correct. Confirm the next free number at write
time — every `0NNN_` sorts before every `2026_*`, so this must not be timestamp-named.

## 13 — R-010: retire or wire the dead surfaces

Scope `small` per item · **same-session-ok**, deferred

- `POST /loans/bulk-approve` — no client. Either build the list-page selection UI or delete
  the route, the service method and its test. Deleting is a scope decision, not a bug fix.
- `/hr/loans` has no Sidebar entry, so it is also absent from the command palette and
  recent-pages (both derive from Sidebar's `SECTIONS`). Adding one is small but is an
  information-architecture choice (which section, what label, which permission) and
  `Sidebar.tsx` is a high-traffic shared file mid-batch — deferred to avoid a conflict with
  the other two sessions rather than because it is hard.
- `loans/index.tsx:87-94` should use `<ListEmptyState>` so the curated
  `emptyStateCopy.ts:81-89` entry renders.
- `loansApi.approve`'s unused `remarks` argument — either pass it from `detail.tsx:39` or drop it.

## 14 — R-016 / R-017: self-service error fidelity and pagination

Scope `small` / `medium` · **same-session-ok** (R-016) / **separate-recommended** (R-017, HR module)

R-016: surface the server's 422 message instead of a generic toast, add `periods` to
`defaultValues`, and stop presenting a truncated history count as the total. R-017: paginate
the employee picker and the self-service history — both live in the HR module and change an
API contract, so not this module's call.
