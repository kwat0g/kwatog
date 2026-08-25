# M021 — Payroll period processing inventory

Audit date: 2026-08-24  
Release target: 📋 Plan Ready  
Audit mode: discovery, hardening, and polish review with local evidence only

## Purpose and boundary

M021 covers the payroll-period lifecycle from draft creation through computation, approval, finalization, disbursement, statutory processing, bank-file generation, GL handoff, payslip delivery, and void/recovery paths:

- period and employee-scope creation;
- attendance, leave, loans, adjustments, de minimis, statutory contributions, and 13th-month computation;
- compute claims, durable outbox events, recovery, anomaly review, maker-checker approval, and finalization;
- GL posting, bank-file generation, disbursement proofs, and disbursement close;
- payslip PDF/email delivery, statutory exports, government-table import, and frontend workflow state.

The audit does not modify employee master, attendance, leave, loans, journal-ledger, notification, storage, or deployment modules. Those areas were inspected read-only where their contracts affect payroll correctness or release safety.

## Dependency context

Declared dependencies are employee-master, attendance-dtr, leave-management, loans-cash-advances, and journal-ledger. The registry showed each dependency at 📋 Plan Ready with no active lock when M021 was claimed. Dependency behavior was read-only context; no dependency module was changed.

## Surface inventory

| Surface | Primary evidence | Audit coverage |
|---|---|---|
| Period routes and authorization | api/app/Modules/Payroll/routes.php:49-151; RolePermissionSeeder.php:108-140, 470-504 | feature gate, period permissions, adjustment permissions, statutory permissions |
| Period lifecycle service | api/app/Modules/Payroll/Services/PayrollPeriodService.php:43-1411 | creation, scope validation, claims, compute handoff, approval, finalization, disbursement, void, retry, summary |
| Payroll computation | api/app/Modules/Payroll/Services/PayrollCalculatorService.php:35-999; ProcessPayrollJob.php:24-252 | money math, attendance, statutory/de minimis, loans, adjustments, cycle claims, per-employee transactions |
| Async orchestration | RunPayrollComputationOnRequested.php:20-45; listeners and jobs under api/app/Modules/Payroll | durable outbox replay, overlap protection, claim fencing, finalization side effects, recovery |
| GL handoff | api/app/Modules/Payroll/Services/PayrollGlPostingService.php:43-350 | lock/reload, balanced journal construction, idempotency, closed-period guard, audit |
| Bank files and disbursement proofs | BankFileService.php:21-377; DisbursementProofController.php:45-172; migrations 0037 and 0151 | bankability, artifact generation, private storage, proof lifecycle, disbursement evidence |
| Statutory tables and exports | GovernmentContributionTableImportService.php:17-129; GovernmentContributionTableService.php:92-127; statutory services/controllers | table import publication, effective dating, export authorization and data boundaries |
| Payroll UI and self-service | spa/src/pages/payroll/periods; spa/src/pages/self-service/payslips.tsx; payroll API/types | state gates, confirmation dialogs, progress/realtime fallback, loading/error/empty states, self-service scope |
| Design and release surface | docs/DESIGN-SYSTEM.md; docs/DEPLOY.md; docs/RESTORE-DRILL.md; api/routes/console.php:68-102 | Atelier conventions, migration/worker order, restore evidence, scheduler operations |
| Verification | api/tests/Feature/Payroll; api/tests/Feature/Security/PayrollAuthorizationTest.php; SPA typecheck and payroll E2E | focused backend behavior, authorization, frontend compile, browser harness readiness |

## Observed workflow

1. A permitted operator creates a Draft period. The service derives the half/cycle key from dates, validates the payroll date and cutoff, normalizes employee scope, rejects overlapping employee coverage, and persists the period transactionally.
2. Compute claims the period with a token, clears progress, and emits a durable PayrollComputationRequested event. The listener uses per-period overlap protection and the job processes employees in individual transactions, recording diagnostic zero rows for failures so approval cannot proceed with hidden errors.
3. The period is approved only from Computed, with nonzero payroll rows, no error rows, and maker-checker enforcement. Finalization is locked/reloaded, rejects unresolved anomalies, marks bank/GL work pending, records audit/outbox events, and applies the 13th-month paid transition.
4. GL posting builds a balanced journal from decimal money strings and links the journal idempotently. Bank generation validates bankability and reconciles builder totals against the period total. A finance operator uploads a disbursement proof and then closes the period as Disbursed.
5. Finalization listeners generate bank files, enqueue payslip delivery, and notify employees. Scheduled commands reconcile auto periods, stale compute claims, and payslip email state. Statutory exports are gated by a dedicated export permission and finalized/disbursed status.

## Verification completed

The clean Docker-backed payroll suite passed on 2026-08-24:

    docker compose run --rm -T api sh -lc 'pwd; test -x vendor/bin/phpunit && ./vendor/bin/phpunit tests/Feature/Payroll tests/Feature/Security/PayrollAuthorizationTest.php'

Result: 264 passed, 771 assertions, 03:36.390, PHPUnit 11.5.56 on PHP 8.3.30.

The suite covers lifecycle, scope, concurrency/claim fencing, recomputation, money findings, GL handoff/posting, bank integrity, statutory exports, government-table import, 13th-month, email recovery, authorization, and related payroll behavior.

Host-side PHPUnit was attempted but could not resolve DB_HOST=db outside the Compose network; this was a test-harness failure before assertions. SPA npm run typecheck completed without diagnostics. The payroll Playwright run could not start Vite because spa/node_modules and spa/node_modules/.vite-temp are root-owned mode 755, producing EACCES while writing Vite's timestamp file. No ownership or cache cleanup was performed.

## Discovery, hardening, and polish observations

- Discovery: the module has broad lifecycle coverage and a substantial hardening layer around compute claims, outbox replay, per-employee transactions, GL posting, and payroll-cycle uniqueness.
- Hardening: de minimis lookup failures are converted to zero taxable excess; approved adjustments are read without a row lock before being marked Applied; disbursement close accepts any proof row; proof archive/restore behavior is inconsistent with SoftDeletes and private-file deletion; bank download regenerates artifacts; statutory imports can partially publish.
- Authorization: adjustment read/create/approve/reject routes share payroll.adjustments.create, so maker-checker is not a distinct permission boundary.
- Polish/operability: summary, preview, variance, and CSV output still cross float formatting boundaries; bank generation and available-employee queries load whole populations, with bank file I/O inside the period transaction.
- The payroll SPA follows the Atelier guidance with dense data tables, semantic status chips, serif page titles, form labels/focus states, confirmation dialogs, progress/error/empty states, and permission/state gating. The browser journey could not be verified because of the local node_modules ownership issue.

## Evidence gaps

- No browser/e2e run of the payroll lifecycle, bank repeated-download behavior, disbursement proof archive/restore, statutory import failure path, or adjustment concurrency.
- No load/k6 run for large employee populations, bank-file generation, or scheduler throughput.
- No staging/production verification of queue workers, SMTP, realtime progress, scheduler execution, storage permissions, migration order, or restore drill.
- No adversarial fixture yet proves the adjustment double-application race, de minimis dependency failure behavior, proof-total mismatch, or partial statutory-table activation.
- The worktree contains pre-existing user changes in backup, notification, landing, Docker, database scripts, SPA notifications, and audit/docs areas. No M021 production source files were changed.
