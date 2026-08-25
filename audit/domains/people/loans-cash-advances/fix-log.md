# M020 — Loans & Cash Advances fix log

Audit date: 2026-08-24  
Session outcome: **Plan Ready; no production-code fixes applied**

## Audit actions

- Regenerated the module registry before selection and again before release.
- Claimed `people/loans-cash-advances` atomically after excluding the finance modules locked by other sessions.
- Read the module source, migrations, routes, approval/RBAC configuration, payroll/final-pay integrations, frontend consumers, tests, and relevant product documentation.
- Kept the audit scope to M020 artifacts; existing user changes in application and infrastructure files were not modified.

## Fixes

None. The decision gate was not met: the majority of findings require separate financial-integrity, RBAC, state-machine, or cross-module implementation sessions. The contained frontend/documentation candidates remain ordered in `action-plan.md` but were intentionally deferred so the module can be handed off with a coherent plan.

## Verification log

- Registry regeneration: passed.
- Loan route listing: passed; 13 routes registered.
- PHP syntax checks for audited backend files: passed.
- SPA typecheck: passed.
- Targeted SPA ESLint: passed.
- SPA token discipline: passed; 769 files checked.
- Deterministic amortization tests: 7 passed.
- Database-backed loan tests: 7 could not start because the configured PostgreSQL host `db` was unavailable; no database service was running (`docker compose ps` was empty).

## Session 2026-08-25 — resumed Plan Ready

The module was claimed after the higher-priority finance Plan Ready modules were
locked by other sessions. The existing report and plan were read in full; this
session continued the existing plan without re-auditing it.

### Fixes applied

- F-001/F-006: api/app/Modules/Loans/Support/LoanRate.php:20 now normalizes
  fractional annual rates with six-decimal precision and exposes a separate
  percent conversion at line 35. EmployeeLoan persists the matching cast at
  api/app/Modules/Loans/Models/EmployeeLoan.php:35, migration
  api/database/migrations/0477_harden_loan_precision_and_payment_integrity.php:120
  widens PostgreSQL storage to numeric(8,6), and LoanService lines 106 and 130
  keep salary caps and principals in BCMath-backed decimal strings.
- F-002: api/app/Modules/Loans/Policies/LoanAccessPolicy.php:24 centralizes
  global, self, and department-head row scope. The service applies it at
  api/app/Modules/Loans/Services/LoanService.php:90, :211, and :267;
  controller show/limits enforce it at
  api/app/Modules/Loans/Controllers/LoanController.php:73 and :118.
- F-003: api/app/Modules/Loans/Support/LoanStateMachine.php:21 defines
  explicit transitions and excludes active cancellation. Service cancellation
  now requires write-off permission and only permits pending loans at
  api/app/Modules/Loans/Services/LoanService.php:281; the detail page exposes
  the action only for pending loans with loans.write_off at
  spa/src/pages/loans/detail.tsx:87.
- F-005: the preview endpoint validates loan_type and returns the canonical
  schedule at api/app/Modules/Loans/Controllers/LoanController.php:134.
  Self-service sends the type and consumes the array contract at
  spa/src/api/self-service.ts:106 and spa/src/pages/self-service/loans.tsx:225.
- F-007/F-008 (partial): payment reconciliation filters asOf, derives
  remaining periods from schedule coverage, and serializes current ledger
  balance at api/app/Modules/Loans/Services/LoanService.php:301 and :348.
  Payment audit coverage is enabled at
  api/app/Modules/Loans/Models/LoanPayment.php:14; migration 0477 adds
  positive-amount/type checks and the payroll FK at lines 78, 139, and 26.
- F-009: final approval/rejection events are no longer emitted for every
  intermediate approval; final-only behavior is at
  api/app/Modules/Loans/Services/LoanService.php:216 and regression-covered
  by api/tests/Feature/Notifications/LoanNotificationTest.php:81.
- F-011/F-012: detail progress/rate semantics use total due and the fractional
  formatter at spa/src/pages/loans/detail.tsx:62 and :122; self-service
  status chips use the design-system mapping at
  spa/src/pages/self-service/loans.tsx:75; admin employee selection is
  searchable at spa/src/pages/loans/create.tsx:41.
- F-013 (partial): amortization preview is throttled at
  api/app/Modules/Loans/routes.php:15.

### Deferred

- F-003: approved write-off/settlement workflow, reason/reference capture,
  segregation of duties, and journal handling still require a business
  decision; active cancellation is blocked until that path exists.
- F-004: final-pay settlement/recovery and the unrecovered-residual rule remain
  in the HR/final-pay dependency module.
- F-007/F-008: payroll recompute still directly deletes payment rows and adjusts
  aggregates; a permissioned manual-payment API/UI and reversal-entry design
  remain pending.
- F-010: SSS/Pag-IBIG remain defined but inactive; activation or de-scoping is
  intentionally not guessed.
- F-012/F-013: self-service history pagination and the HR-versus-Loans feature
  gate alignment require dependency-module changes.
- F-014: documentation remains pending the government-loan and write-off
  decisions.

### Verification

- Docker-backed focused suite: 18 passed, 93 assertions.
- Migration verification in ogami_test: employee_loans.interest_rate is
  numeric(8,6) and all three payment constraints/FK are present.
- Deterministic precision/state tests: included in the 18 passing tests.
- Changed SPA files: targeted ESLint passed.
- Route listing passed; preview throttle and write-off middleware are present.
- Full SPA typecheck remains blocked by the pre-existing invalid character and
  unterminated template in spa/src/pages/crm/sales-orders/create.tsx:169,
  outside M020.

Release status for this session: `🔁 Needs Re-audit`. The next session should resolve the deferred write-off, final-pay, payroll-reversal/manual-payment, government-loan, feature-gate, pagination, and documentation decisions.
