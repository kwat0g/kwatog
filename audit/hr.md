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

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Re-audit verdict

The current commit materially hardens several previously reported seams. Payroll and final-pay
now share `EmployedDayFraction`; clearance signing resolves the checklist department; separation
cancellation exists in the API; salary adjustments have a maker-checker path and effective-date
handling in Payroll; and final-pay JE posting plus bank-file generation contain double-payment
guards. Those controls are not all closed end-to-end: the final-pay compute state still accepts an
undisbursed covering period, employee archive can still strand an open separation, and the HR edit
endpoint bypasses the bank-account dual-approval workflow.

### Prior finding status

| ID | Current status | Evidence |
|---|---|---|
| HR-01 | **Mitigated at the money boundary, unresolved as a process state** | `FinalPayService` rechecks the covering period before posting (`api/app/Modules/HR/Services/FinalPayService.php:220-225,282-288,465-487`), and `BankFileService` rejects posted final-pay consumption (`api/app/Modules/Payroll/Services/BankFileService.php:102-148`). `compute()` still does not call that guard; see below. |
| HR-02 | **Resolved statically** | Both paths delegate to `EmployedDayFraction` (`api/app/Modules/Payroll/Support/EmployedDayFraction.php:38-70`; `api/app/Modules/HR/Services/FinalPayService.php:415-439`). Parity coverage exists in `api/tests/Feature/HR/FinalPayProrationParityTest.php:129-206`. |
| HR-03 | **Resolved in the service path** | `assertMaySignItem()` enforces department ownership with HR/admin fallback (`api/app/Modules/HR/Services/SeparationService.php:282-385`), with route/service regression coverage in `api/tests/Feature/HR/ClearanceDepartmentGateTest.php:92-193`. |
| HR-04 | **Backend resolved; SPA still incomplete** | `cancel()` is implemented and guarded for pre-money states (`api/app/Modules/HR/Services/SeparationService.php:525-603`), but the scoped SPA API/page has no cancel action (`spa/src/api/separations.ts:11-25`; `spa/src/pages/hr/separations/detail.tsx:44-100`). |
| HR-05 | **Appears resolved statically** | Future-dated and first-history-row cases are handled by `salarySegments()` (`api/app/Modules/Payroll/Services/PayrollCalculatorService.php:594-699`), with focused tests in `api/tests/Feature/Payroll/SalaryEffectiveDateProrationTest.php:109-184`. |
| HR-06 | **Unresolved** | A voided covering period is excluded and then treated as no period, so last salary becomes zero (`api/app/Modules/HR/Services/FinalPayService.php:391-399,446-455`). |
| HR-07 | **Unresolved** | Final-pay JE still books the combined gross debit without statutory withholding/liability lines (`api/app/Modules/HR/Services/FinalPayService.php:300-324`). |
| HR-08 | **Unresolved** | Employee deletion still only deactivates the account and soft-deletes the employee (`api/app/Modules/HR/Services/EmployeeService.php:317-334`); no open-clearance guard was added. |
| HR-09 | **Partially mitigated; policy remains unresolved** | Payroll now skips loans marked `is_final_pay_deduction` false (`api/app/Modules/Payroll/Services/PayrollCalculatorService.php:941-948`), but final-pay balance collection still includes every active/pending loan (`api/app/Modules/HR/Services/FinalPayService.php:564-579`). |
| HR-10 | **Still scope-cut/unreachable through the supported property routes** | Property routes remain hidden (`api/app/Modules/HR/routes.php:118-130`), although legacy rows are still read by final pay. |
| HR-11 | **Unresolved** | Existing accrual rows are trusted without reconciling current payroll (`api/app/Modules/HR/Services/FinalPayService.php:538-548`). |
| HR-12 | **Resolved** | The documentation now names the shared Payroll helper (`CLAUDE.md:335-343`), and the implementation uses it. |

### Findings

#### HR-01 — Final-pay compute still creates an invalid undisbursed state

**Broken process | High | Effort: S**

**Location:** `api/app/Modules/HR/Services/FinalPayService.php:52-76,391-439`; guard only at
`api/app/Modules/HR/Services/FinalPayService.php:465-487`.

**Evidence/reproduction:** `compute()` calls `lastSalaryProRated()` and persists
`final_pay_computed=true` without calling `assertCoveringPeriodAllowsFinalPay()`. With a covering
period in `draft`, `computed`, `approved`, or `finalized`, the service therefore stores a
non-zero last-salary component and returns a clearance that the SPA presents as ready to
finalize (`spa/src/pages/hr/separations/detail.tsx:90-99`). Finalization later fails only when
`postJournalEntry()` reaches the guard. Because `cancel()` refuses any clearance with
`final_pay_computed` (`api/app/Modules/HR/Services/SeparationService.php:543-555`), a mistaken
early compute cannot be cancelled and must be repaired by disbursing/voiding and recomputing.
The code-reading evidence is also internally contradicted by the tests: the new guard test
expects compute to refuse (`api/tests/Feature/HR/FinalPayDoublePayGuardTest.php:148-166`), while
the older final-pay tests still expect compute on an open period to succeed
(`api/tests/Feature/HR/FinalPayTest.php:167-188`). The posting and bank-file defenses reduce the
actual double-payment risk, but the operator-visible state remains wrong and order-dependent.

#### HR-06 — Voiding the covering payroll period silently removes earned final salary

**Risk | Medium | Effort: M**

**Location:** `api/app/Modules/HR/Services/FinalPayService.php:391-399,446-455`.

**Evidence/reproduction:** `coveringPayrollPeriod()` filters out `voided` periods. When the
employee's separation date is inside that voided cutoff, `lastSalaryProRated()` sees `null` and
returns `0.00` rather than using the shared fallback or refusing with an actionable replacement-
period message. `compute()` then persists a valid-looking final-pay breakdown missing the last
salary. No error or `manual_required` state identifies the omitted earnings.

#### HR-07 — Final-pay statutory withholding remains absent from the JE

**Risk | Medium | Effort: M**

**Location:** `api/app/Modules/HR/Services/FinalPayService.php:155-168,286-324`.

**Evidence/reproduction:** The final-pay posting constructs one debit for `gross_plus`, then
credits loan/advance/property recoveries and cash. There are no SSS, PhilHealth, Pag-IBIG, or BIR
withholding calculations, liability credits, or a stored policy flag for the last-salary portion.
The same earnings paid through Payroll carry statutory deductions, so a leaver can receive a
different statutory treatment depending on whether the covering cutoff was disbursed first.
This remains a Finance/policy boundary, but the current path neither applies nor explicitly
blocks for that decision.

#### HR-08 — Employee archive can strand an open separation

**Stuck process | High | Effort: S**

**Location:** `api/app/Modules/HR/Services/EmployeeService.php:317-334`;
`api/app/Modules/HR/Services/SeparationService.php:463-469`.

**Evidence/reproduction:** The delete path locks the employee, deactivates the linked account,
and soft-deletes the row without checking for `pending`/`in_progress`/`completed` clearances,
final-pay computation, or other open H2R records. Later `finalize()` uses a normal
`Employee::query()->find()`, which excludes the trashed employee and throws `Clearance employee
not found`. The clearance remains non-terminal and the employee cannot complete final pay through
the supported workflow. The re-audit found no regression test covering archive during an open
separation.

#### HR-09 — Final-pay loan semantics still disagree with the reservation flag

**Gap | Medium | Effort: M**

**Location:** `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:941-948`;
`api/app/Modules/HR/Services/FinalPayService.php:348-355,564-579`.

**Evidence/reproduction:** Payroll treats `is_final_pay_deduction=false` as an explicit reason
to continue ordinary amortization, while final pay reads all active and pending loans without
that predicate and only settles active loans. The current HR tests deliberately use a false flag
and expect final pay to deduct the loan (`api/tests/Feature/HR/FinalPayTest.php:343-375`), so the
implementation is internally consistent with that fixture but not with the flag's documented
"reserve for final pay" meaning. A loan can be skipped by payroll yet included by final pay, or
included by both if the flag is changed at the wrong point. The owner must choose whether the
flag is authoritative, informational, or should be removed from the money path.

#### HR-11 — Stale 13th-month accrual can understate final pay

**Risk | Medium | Effort: M**

**Location:** `api/app/Modules/HR/Services/FinalPayService.php:538-561`.

**Evidence/reproduction:** If any accrual row exists for the separation year, the service returns
`accrued_amount` immediately. It does not compare `total_basic_earned` or the accrued amount with
computed, non-voided payroll through the separation date. A payroll recompute or late cutoff
posted after accrual generation therefore leaves final pay using the older snapshot. The fallback
rebuild is used only when no accrual row exists, so the presence of stale data suppresses the
authoritative rebuild.

#### HR-13 — Direct HR employee edits bypass bank-account dual approval

**Broken process | High | Effort: M**

**Location:** `api/app/Modules/HR/Requests/UpdateEmployeeRequest.php:52-54,98-99`;
`api/app/Modules/HR/Services/EmployeeService.php:251-275`;
`api/app/Modules/HR/routes.php:81-83`.

**Evidence/reproduction:** A user with `hr.employees.view_sensitive` may submit
`bank_name`/`bank_account_no` to the normal employee `PUT` route. The request allows those fields,
and `EmployeeService::update()` writes them directly. The dedicated self-service path says bank
changes require HR plus Finance approval (`api/app/Modules/HR/Services/ProfileUpdateRequestService.php:18-20,45-49`),
but the generic HR edit path never creates a `ProfileUpdateRequest` and does not require
`hr.profile_updates.finance_review`. The HR SPA exposes the direct fields in
`spa/src/components/hr/EmployeeForm.tsx:426-431`, making this a supported bypass rather than a
direct-service-only edge case.

#### HR-14 — Separation cancellation is API-only, so the SPA still strands corrections

**Missing | Medium | Effort: S**

**Location:** `api/app/Modules/HR/routes.php:300-301` and
`api/app/Modules/HR/Services/SeparationService.php:525-601` versus
`spa/src/api/separations.ts:11-25` and `spa/src/pages/hr/separations/detail.tsx:44-100`.

**Evidence/reproduction:** The backend exposes a permission-gated cancel endpoint and the
service restores the pre-initiation status, but the scoped SPA API has no `cancel()` method and
the separation detail page has no cancel button, mutation, reason field, or invalidation path.
An HR operator following the supported UI can initiate a separation and then has no visible way
to correct or rescind it; using the API directly is required. This is the remaining frontend
half of prior HR-04.

#### HR-15 — Hidden employee-property data is emitted on employee detail

**Gap | Medium | Effort: S**

**Location:** `api/app/Modules/HR/Services/EmployeeService.php:168-172`;
`api/app/Modules/HR/Resources/EmployeeResource.php:87-92`;
`api/app/Modules/HR/Resources/EmployeePropertyResource.php:15-26`.

**Evidence/reproduction:** The employee detail service eagerly loads `property`, and the
resource emits it whenever loaded without a permission check. A department-scoped employee
viewer can therefore open a same-department employee detail and receive item names, quantities,
replacement costs, and lost/issued status even though the property routes are explicitly hidden
as a scope cut (`api/app/Modules/HR/routes.php:118-130`). The existing employee scope test checks
documents and salary masking but not property omission.

#### HR-16 — HR multipart API clients manually set the forbidden Content-Type

**Bad practice | Medium | Effort: S**

**Location:** `spa/src/api/hr/employees.ts:106-113` and
`spa/src/api/hr/employee-documents.ts:10-13`.

**Evidence/reproduction:** Both upload methods set `headers: { 'Content-Type': 'multipart/form-data' }`
on browser `FormData` requests. The repository contract explicitly says not to set this header
because the browser/axios must add the multipart boundary (`CLAUDE.md:467-468`). Photo and
employee-document uploads can consequently arrive without a usable boundary and fail server
MIME/file validation even when the selected file is valid. Training uploads correctly omit the
manual header, so the inconsistency is localized to these two HR clients.

#### HR-17 — Onboarding cron reports success when every delivery attempt fails

**Risk | Medium | Effort: S**

**Location:** `api/app/Modules/HR/Services/OnboardingService.php:185-248`;
`api/app/Console/Commands/SendOnboardingReminders.php:19-23`.

**Evidence/reproduction:** Each reminder delivery catches `Throwable`, logs a warning, leaves the
row retryable, and continues. The command prints `Sent 0 onboarding reminders.` and returns
`SUCCESS` even when all stale rows threw notification/database errors. Scheduler monitoring cannot
distinguish a healthy zero-row run from a run in which every candidate failed. The tests verify
retryability (`api/tests/Feature/HR/OnboardingTest.php:265-280`) but not command exit semantics.

#### HR-18 — Concurrent password resets can email an invalid temporary password

**Risk | Medium | Effort: M**

**Location:** `api/app/Modules/HR/Services/UserProvisioningService.php:110-145`.

**Evidence/reproduction:** `resetPasswordForUser()` does not lock or atomically claim the user
row before reading the old password, writing a new hash, and scheduling the notification. Two
authorized reset requests can both commit: the last hash wins, while both temporary passwords
are delivered. The first notification then contains a password that no longer works, and the
audit/history sequence does not identify the winning reset. Existing coverage is serial only
(`api/tests/Feature/HR/UserProvisioningTest.php:131-147`).

#### HR-19 — Bulk account provisioning returns raw exception text to the API caller

**Risk | Medium | Effort: S**

**Location:** `api/app/Modules/HR/Services/UserProvisioningService.php:219-225`.

**Evidence/reproduction:** The bulk loop catches every `Throwable` and includes
`$e->getMessage()` in the returned `failed` result. A database/constraint/storage exception can
therefore expose SQL, table/index names, filesystem paths, or provider details to the HR client,
contrary to the generic-error rule and the UI pattern forbidding technical details
(`docs/PATTERNS.md:1624-1629`). The error is reported server-side, but the response should carry
an operator-safe message and a correlation/reference for logs.

### Clean areas and retained controls

- Employee and clearance resources use HashIDs, encrypted casts, and sensitive-field masking;
  the clearance checklist also converts raw signer IDs before emitting JSON
  (`api/app/Modules/HR/Resources/EmployeeResource.php:64-77`; `api/app/Modules/HR/Resources/ClearanceResource.php:72-107`).
- Employee list/detail and self-service reads use server-side row ownership/departments rather
  than trusting URL HashIDs (`api/app/Modules/HR/Services/EmployeeService.php:43-61,152-172`;
  `api/app/Modules/HR/Controllers/SelfServiceController.php:58-70,119-127,184-213`).
- Self-service payroll, DTR, leave, and loan reads are session-employee scoped, and the read
  tests cover department-head/approval-permission attempts to cross that boundary
  (`api/tests/Feature/HR/SelfServiceOwnerScopeTest.php:25-65`).
- Clearance item updates, final-pay JE posting, loan settlement, and salary-adjustment approval
  use transaction/lock patterns with focused regression coverage; the cent-precision and
  loan-residue paths were read as materially improved.
- Recruitment posting/application transitions re-read locked rows, clean up a failed resume
  upload, enforce the passed-interview gate, and record application decision history
  (`api/app/Modules/HR/Services/RecruitmentService.php:122-183,224-337`).
- Training and skill evidence uses the private local disk and employee competence scope for
  downloads (`api/app/Modules/HR/Services/TrainingEvidenceService.php:12-50`;
  `api/app/Modules/HR/Controllers/EmployeeSkillController.php:110-120`).
- The sampled HR, careers, and self-service pages are lazy-loaded and generally implement
  loading/error/empty states; the exceptions and process gaps above are separate from the
  broad page-state posture.

### Verification limits

- This was a read-only static/code-reading re-audit at `56e0d431`; no tests, Docker, Artisan,
  browser, build, database, queue, scheduler, or two-connection concurrency harness was run.
- Tests were read for intended contracts and regression coverage only. In particular, the
  conflicting open-period expectations around HR-01 were not resolved by execution.
- JournalEntryService, ApprovalService, shared middleware, payroll bank/disbursement internals,
  and database migrations were inspected only where directly needed for HR evidence; findings
  at those boundaries remain cross-module flags rather than claims of a complete subsystem audit.
- Runtime provider delivery, scheduler monitoring, browser multipart parsing, and deployed
  storage behavior remain unverified.

### Explicit no-code-change statement

No application code, migration, test, registry, roadmap, or other file was modified by this
re-audit. Only this dated section was appended to `audit/hr.md`; all prior audit content was
preserved verbatim.
