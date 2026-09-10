# Audit: hr — 2026-09-06

## Summary

HR module is structurally strong: encrypted casts on all 5 sensitive fields, permission-masked
resources, server-side row scoping (DepartmentScope) on lists/show/documents/export, self-service
endpoints uniformly session-employee-scoped with no cross-employee access found, and unusually
good test coverage of final-pay JE races (P05 regressions) and SoD on profile updates.

The serious problems cluster on the separation → clearance → final-pay money path. The worst is
order-dependent double pay: final pay happily consumes a computed-but-undisbursed payroll row for
the last period, and nothing stops that same period from being disbursed to the same employee
afterwards (bank file has no clearance awareness). Proration is genuinely duplicated across HR and
Payroll with two different formulas (confirming the Tier-1 flag), and the two can disagree. The
5-step clearance gate is only enforced by one flat permission — the per-department sign check
described in comments is not implemented, and there is no way to cancel a mistaken separation.

## Findings

| ID | Category | Sev | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| HR-01 | Risk | Critical | M | Final pay + payroll disbursement can pay the last period twice | api/app/Modules/HR/Services/FinalPayService.php:287-303; api/app/Modules/Payroll/Services/BankFileService.php:50-52 | See detail |
| HR-02 | Bad practice | High | M | Final-pay proration re-derived with a different formula than payroll's | FinalPayService.php:305-324 vs api/app/Modules/Payroll/Services/PayrollCalculatorService.php:559-587 | See detail |
| HR-03 | Broken process | High | M | Clearance items signable by any `hr.clearance.sign` holder; per-department gate absent | SeparationService.php:298-326; SeparationController.php:60-75; RolePermissionSeeder.php:491,769 | See detail |
| HR-04 | Stuck process | High | M | No cancel/correct path for an initiated separation | SeparationService.php:120-152; Enums/ClearanceStatus.php:13 | See detail |
| HR-05 | Risk | High | M | Salary adjustment applied at approval time, not effective date | Services/SalaryAdjustmentService.php:98-154; PayrollCalculatorService.php:622-691 | See detail |
| HR-06 | Risk | Medium | S | Voided final period silently zeroes last-salary component | FinalPayService.php:277-289 | Period covering separation_date with status=voided is excluded by the query → `$period` null → returns 0.00 with no error; employee loses the last salary unless someone re-runs a replacement period before compute |
| HR-07 | Risk | Medium | M | Last-salary component paid gross — no gov/tax withholding booked in final-pay JE | FinalPayService.php:124-138,243-257 | JE DR Salaries Expense (gross_plus) / CR Cash; no SSS/PhilHealth/Pag-IBIG/WHT lines or liability credits for the last-salary portion, while the same money through payroll would carry withholding. Cross-module: payroll/accounting |
| HR-08 | Risk | Medium | S | Employee soft delete has no open-record guard | Services/EmployeeService.php:329-347 | `delete()` only deactivates the account + soft-deletes. An employee with an InProgress clearance can be deleted → `finalize()` then throws 'Clearance employee not found' and the separation is stuck; active loans / pending leave requests orphaned on a trashed employee |
| HR-09 | Gap | Medium | S | `is_final_pay_deduction` loan flag is dead on the money path | api/app/Modules/Loans/Models/EmployeeLoan.php:29,46; FinalPayService.php:405-421 | Flag exposed in API resource but never consulted: payroll amortizes ALL active loans (PayrollCalculatorService.php:846-856) and final pay deducts ALL active+pending company loans regardless of the flag. Cross-module: loans |
| HR-10 | Gap | Low | S | Unreturned-property deduction unreachable | FinalPayService.php:423-429; routes.php:113-125 | Employee-property routes HIDDEN 2026-08-08 (scope cut), so no API can create `status='lost'` rows; final-pay component is permanently 0.00 while still documented in the JE docblock |
| HR-11 | Risk | Low | S | 13th-month component trusts stale accrual snapshot | FinalPayService.php:379-403 | If `thirteenth_month_accruals` row exists it is used verbatim even when payroll rows were computed/recomputed after the accrual ran → understated pro-rated 13th for late separations |
| HR-12 | Other | Low | S | CLAUDE.md documents nonexistent `Employee::employedDayFraction()` | CLAUDE.md ("Use Employee::monthlyEquivalentSalary…"); Employee.php has no such method | Proration is a private method in PayrollCalculatorService.php:559. Docs drift that sent the Tier-1 audit hunting in the wrong file |

### HR-01 — Double pay of the last period (Critical)

`FinalPayService::lastSalaryProRated()` finds the non-voided payroll period covering
`clearances.separation_date`. Only a **Disbursed** period returns 0. If the period is merely
Computed/Approved/Finalized, final pay books `basic_pay + leave_pay − tardiness − undertime` from
that employee's payroll row and `finalize()` pays it out via JE. Nothing then prevents the normal
payroll flow from disbursing that same period: `BankFileService` selects every row in the period
with `net_pay > 0` (BankFileService.php:50-52) — no clearance/final-pay awareness anywhere in the
Payroll module (grep for `clearance` in api/app/Modules/Payroll returns only the proration read).

Repro: (1) initiate separation mid-period, (2) period computed (row prorated via
`employedDayFraction` — the payroll auditor's fix), (3) clearance completed → compute final pay →
finalize (JE credits Cash in Bank for net incl. the prorated basic), (4) period finalized and
disbursed at payday → employee's bank file line pays the same prorated basic again. Both payments
are the system behaving as designed; no test covers this ordering (FinalPayTest only covers
disbursed-first, which is safe). The reverse ordering works only because of the Disbursed→0 check,
i.e. correctness is order-dependent. Fix direction: compute must refuse (or finalize must block)
while the covering period is computed-but-undisbursed, or disbursement must skip employees whose
clearance already consumed that row. Cross-module: payroll unit.

### HR-02 — Duplicated, divergent proration (High; confirms Tier-1 flag)

`Employee::employedDayFraction()` does not exist (CLAUDE.md is wrong); payroll's proration is
`PayrollCalculatorService::employedDayFraction()` — calendar-day based: `halfBasic ×
coveredDays/totalDays`, flat, attendance-agnostic. FinalPayService agrees with it **only** when a
computed payroll row exists (it reads `basic_pay` verbatim). When no computed row exists it falls
back to its own formula: `Σ min(regular_hours/hours_per_day, 1) × (monthly ÷
work_days_per_month)` over period_start..separation_date — an attendance-day basis with a
different divisor (work days per month vs calendar days in the cutoff) that also ignores
tardiness/undertime and pays nothing for days without DTR rows, while payroll pays flat. Same
quantity, two formulas, different numbers (e.g. ₱22,000 monthly, Mar 1–15 cutoff, separation
Mar 10, 8 attended days: fallback ₱8,000 vs payroll ₱7,333.33). This fallback engages exactly
when the separation outruns payroll compute — a normal ordering. Fix: expose one shared proration
service and have FinalPayService delegate (or require the period be computed first, per HR-01).

### HR-03 — Clearance signing has no per-department gate (High)

`signItem()`'s own comment promises "Soft auth check — user must belong to that department, or
have hr_officer / system_admin role" — no such check exists in the code; the only gate is the flat
`hr.clearance.sign` permission (controller + route middleware). Seed grants: hr_officer (whole
hr_separation module), department_head, system_admin. Consequences: (a) any department_head can
sign **all 11** seeded items including Finance's "No outstanding company loan" and IT's "System
accounts disabled"; (b) finance_officer holds no clearance permission at all, so the documented
5-role workflow (dept head → warehouse → maintenance → finance → HR) cannot be expressed with
seeded roles; (c) hr_officer can solo the entire chain — initiate, sign every item, compute final
pay, finalize, and post the JE — with no segregation of duties anywhere in hire-to-retire's tail,
unlike the maker-checker gates the project enforces elsewhere (REC-02/03/04). The hard loan gate
in `finalize()` is the only real multi-party control left.

### HR-04 — No cancel/correct for an initiated separation (High)

`ClearanceStatus::Cancelled` exists and `isTerminal()` knows it, but no code path ever sets it:
no route, no service method. Once initiated (employee flipped to on_leave, checklist created), the
only way is forward through all 11 signatures and final pay. `initiate()` blocks re-initiation
while a Pending/InProgress/Completed clearance exists, and `EmployeeService::update()` rejects
status changes, so a mistyped separation date (a future-dated typo passes the hire-date guard) or
a rescinded resignation leaves a permanently stuck clearance — the code comments themselves admit
"no cancel/correct transition exists".

### HR-05 — Salary adjustment effective date not honored (High)

`SalaryAdjustmentService::apply()` runs at full approval and immediately updates the employee's
live `basic_monthly_salary`/`semi_monthly_rate`; the `effective_date` is only written to
`employee_salary_history`. Payroll honors that date **only** when it falls strictly inside the
period being computed (`salarySegments`). Two money-wrong cases: (1) an adjustment approved with a
future effective date — any period computed after approval but ending before the effective date
gets `salarySegments === null` (no change inside period) and the legacy path pays the already-updated
live salary → the raise is paid early; (2) an employee's **first** history row landing strictly
inside a period — `$startRow` is null, so the pre-effective days fall back to the live (new)
salary and are overpaid. Effective-dating must drive the live row (or payroll must read history
rows with future effective dates as the period's starting rate). Cross-module: payroll unit.

## Cross-module flags

- **payroll**: HR-01 (bank-file/disbursement must be clearance-aware, or final pay must require
  disbursement-first); HR-02 (extract `employedDayFraction` into a shared service; CLAUDE.md's
  `Employee::employedDayFraction()` reference is wrong); HR-05 (legacy basic-pay path reads the
  live salary and ignores future-dated history rows).
- **accounting**: HR-07 — final-pay JE books the last-salary component gross; withholding policy
  for separation pay is a finance decision the system neither applies nor flags.
- **loans**: HR-09 — `is_final_pay_deduction` flag is unenforced; decide semantics (skip in
  payroll amortization vs informational) or delete it from a money path.
- **leave**: verified clean — Leave has no own separation-conversion path, so no double encashment
  with FinalPayService's `unusedConvertibleLeaveValue`.

## What was NOT checked

- Tests were **not executed** (static review only; the suspected defect HR-01 is a missing-test
  scenario, so a green suite would not have disproved it).
- Training/skills-matrix, onboarding, and user-provisioning service internals beyond route
  wiring and the clearance-complete deactivation listener.
- EmployeeImporter CSV path and the generic export controller itself (EmployeeMasterExport's own
  column registry, row scope and per-column permission gates were checked and are clean).
- JournalEntryService internals (treated as dependency); ApprovalService chain mechanics.
- SPA pages were sampled (separations list/detail, self-service payslips/profile, careers), not
  exhaustively reviewed; all sampled pages handle loading/error/empty states and render money via
  formatPeso on string decimals.
