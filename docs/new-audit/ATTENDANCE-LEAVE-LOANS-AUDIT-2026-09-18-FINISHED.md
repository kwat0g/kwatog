# Attendance + Leave + Loans Audit

Date: 2026-09-18
Scope: `api/app/Modules/{Attendance,Leave,Loans}`. DTR computation/import, OT, leave requests,
and loan amortization were partly covered by the H2R payroll trace; this adds full routes and new
findings. Claims are **[confirmed]** (file:line/grep) or **[assumption/unverified]**.

---

## 1. Attendance

1. **Raw-punch import was unexposed.** `DTRImportService::importRawPunches()` and
   `PunchSessionizer` were only called by tests; the attendance module now exposes a dedicated raw
   import route, and the `direction` column validates and selects directional in/out punches.
   **[fixed in this pass]**
2. **`overtime_request` workflow was dead config.** OT is decided by
   `OvertimeDecisionPolicy`, not `ApprovalService`; the unused seeded workflow and drift-map entry
   are now removed. **[fixed in this pass]**
3. **Voided payroll period permanently locks attendance.** `AttendanceDateMutabilityGuard` treated
   `Voided` as locked while its own message said "Void or correct the payroll period" — the
   recommended recovery was impossible without deleting the period. **[fixed in this pass]**
4. **Rejected auto-detected OT blocked re-detection.** The existing-row check matched any status, so
   a rejected/cancelled auto-OT was never re-created even if punches changed. **[fixed in this pass]**
5. **OT settings serve different audiences.** Admin/HR and self-service limits remain separate and
   are labelled by audience; the unused `shiftAnchor($isNight)` parameter and mass-assignable OT
   status have been removed. Leave-range holiday loading now uses one range query rather than one
   model lookup per day. **[fixed in this pass]**

## 2. Leave

6. **Year-end has a primary run plus recovery window.** The primary
   `leave:process-year-end` schedule runs Dec 31 at 23:00; Jan 1–7 retains the
   `--previous-year` recovery schedule and rollover retry. **[confirmed, corrected]**
7. **Cancelled pending leave retires its approval card.** `cancel()` changes current pending or
   skipped `approval_records` to `superseded` and `is_current=false`; the approval board therefore
   no longer presents the card. A general `ApprovalService` withdraw method and a cancellation event
   remain absent, but they are not required for this path. **[confirmed fixed before this pass]**
8. **`LeaveBalanceService::seedFor()` was dead and is removed.** `seedProratedFor()` remains the
   sole balance-initialization path. **[fixed in this pass]**
9. **Leave days exclude Sundays and configured public holidays.** Regular and special non-working
   holidays now do not consume leave credits. **[fixed in this pass]**
10. `LeaveHalfDayPeriod::FullDay = 'none'` was never produced by validation and is now removed;
    full-day remains represented by `null`. `ProcessedYearEndLeaveType` now records aggregate
    `days_carried` per leave type. **[fixed in this pass]**

## 3. Loans

11. **`is_final_pay_deduction` is active.** Separation reserves active and pending loans, payroll
    excludes reserved loans, and final pay settles the outstanding active balance. Cancellation of a
    separation releases the reservation. **[confirmed fixed before this pass]**
12. **Cancelled pending loan retires its approval card.** `cancel()` supersedes current pending or
    skipped approval records, so the approval board no longer presents the cancelled loan.
    **[confirmed fixed before this pass]**
13. **Government loans are kept in the enum but unsupported.** `sss_loan`/`pagibig_loan` remain in the enum
    and settings for future statutory work, but no active workflow is seeded. They are now rejected
    by request validation, amortization preview, and service boundaries rather than being accepted
    and then failing after interest calculation. **[fixed in this pass]**
14. **`government_reference_no` was dead.** It is removed from the model contract and dropped by a
    migration; the rollback can restore the nullable legacy column. **[fixed in this pass]**
15. **Borrower withdrawal is owner-only and pending-only.** Self-service now exposes a borrower-scoped
    withdrawal action; active loans still require the separate administrative settlement/write-off
    workflow. **[fixed in this pass]**
16. `pay_periods_*` still stores monthly repayment schedule rows while payroll deducts at half per
    semi-monthly period, but API aliases and UI labels now call them repayment months. **[fixed in
    this pass]**

## 4. Cross-module / dead config

- Approved OT recomputes attendance; rejected/cancelled OT do not (and never contributed) — correct.
- Approved leave writes `on_leave` with a reversible snapshot and goes through the mutability guard
  (fail-closed on a payroll-locked day) — correct.
- Retained future scope: `sss_loan`/`pagibig_loan` enum values and settings remain hidden and
  fail-closed until statutory workflows are designed.

## 5. Assumptions

- A1. Original audit used source/grep only; focused remediation tests were added and run against an
  isolated local PostgreSQL cluster. A2. Government-loan interest intent from CLAUDE.md. A3. The
  leave-day rule is now resolved to exclude Sundays and configured Philippine public holidays.

## 6. Remediation status

- Fixed in this pass: voided-period attendance recovery, rejected/cancelled auto-OT re-detection,
  fail-closed government-loan boundaries, borrower withdrawal of pending loans, public-holiday
  exclusion from leave-day calculations, raw-punch route/direction handling, dead workflow/config
  cleanup, year-end carry summary, government-reference retirement, and repayment-month labels.
- Regression coverage added for: voided CSV correction, auto-OT reopening, cancelled leave approval
  retirement, cancelled loan approval retirement, government-loan API rejection, public-holiday leave
  handling, raw-punch routing/direction, and year-end carry summaries.
- Existing source fixes covered by the re-audit: Dec 31 year-end scheduling, final-pay loan
  reservation, and approval-card retirement for leave and loans.
- Focused PHPUnit execution passed against an isolated local PostgreSQL cluster without Docker.
