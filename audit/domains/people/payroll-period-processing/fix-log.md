# M021 — Payroll period processing fix log

Audit date: 2026-08-24  
Module status: 🔁 Needs Re-audit  
Implementation status: fixes applied; see the 2026-08-25 implementation section

## Audit actions

- Refreshed the registry, confirmed M021 was the next unlocked eligible module, and claimed it atomically with the audit script.
- Read the Payroll routes, requests, controllers, services, jobs, listeners, models, enums, migrations, permission seeders, scheduled commands, frontend pages/components/API/types, focused tests, load fixture, design-system guidance, deployment notes, and restore drill.
- Ran the clean Docker-backed payroll and authorization suite: 264 tests passed with 771 assertions.
- Ran SPA typecheck successfully. The payroll Playwright run was attempted but Vite could not write its cache because pre-existing spa/node_modules and spa/node_modules/.vite-temp are root-owned; no ownership change or cleanup was performed.
- Preserved all unrelated pre-existing worktree changes. During the audit portion, no M021 production source files were changed before the plan was handed off.

## Code changes

During the audit portion, only the M021 audit artifacts were authored:

- inventory.md
- audit-report.md
- action-plan.md
- fix-log.md

## Why fixes were initially deferred

The initial audit session deferred implementation because the findings are dominated by financial-state integrity, concurrent adjustment application, disbursement evidence policy, private artifact lifecycle, statutory-table activation, authorization separation, and population-scale transaction behavior. The resumed implementation session below handled the in-scope fixes and records the remaining policy/performance decisions.

## Release note

The resumed implementation session is ready to release M021 as 🔁 Needs Re-audit. The release step must remove the claim lock, regenerate the registry, and identify the next eligible module.

## 2026-08-25 implementation session

The existing Plan Ready work was resumed after an atomic claim. The audit report and action plan were not rewritten. Changes below are limited to the payroll-period-processing module, its payroll-specific migrations/permission catalog, and the payroll SPA surfaces.

### M021-F01 — de minimis dependency failure

- Before: api/app/Modules/Payroll/Services/PayrollCalculatorService.php:778-796 treated a missing/failed de minimis lookup as 0.00.
- After: the calculator injects DeMinimisService at :62-70, propagates a BusinessRuleException at :778-795, and lets ProcessPayrollJob::handle() persist the visible error row at api/app/Modules/Payroll/Jobs/ProcessPayrollJob.php:112-170; existing approval guards reject errored rows.
- Verification: Payroll feature suite exercised the calculator and de minimis paths. A dedicated dependency-failure injection test remains recommended.

### M021-F02 — approved adjustment application

- Before: api/app/Modules/Payroll/Services/PayrollCalculatorService.php:971-976 read all approved, unapplied adjustments without a row lock.
- After: :971-980 applies deterministic id ordering plus lockForUpdate() inside the per-employee transaction, so concurrent workers cannot both claim the same row.
- Verification: the broader Payroll suite passed all adjustment/recompute coverage; two-connection race coverage remains pending.

### M021-F03 — disbursement evidence reconciliation

- Before: api/app/Modules/Payroll/Services/PayrollPeriodService.php:1010-1017 closed a finalized period when any proof row existed.
- After: api/app/Modules/Payroll/Services/DisbursementEvidenceService.php:25-132 computes payable net with decimal strings, rejects payroll errors/zero or excessive amounts, requires every active proof file, and requires exact total equality before markDisbursed(); upload status records partially_disbursed while evidence is incomplete. api/app/Modules/Payroll/Controllers/DisbursementProofController.php:53-117 validates and reconciles required positive amounts transactionally.
- Verification: PayrollPeriodEventsTest.php now covers exact evidence and mismatch rejection; the focused event suite passed 9/9. Finance policy on whether generated bank artifacts are mandatory remains a decision for re-audit.

### M021-F04 — proof archive/restore

- Before: api/app/Modules/Payroll/Controllers/DisbursementProofController.php:164-211 used an enum-unsafe terminal check, deleted the private file on archive, and could not bind soft-deleted proofs for restore.
- After: :164-212 locks the period, blocks archive/restore after Disbursed or Voided, retains the private object, checks it exists before restore, reconciles restored amounts, and the route opts into withTrashed() at api/app/Modules/Payroll/routes.php:101-105. The SPA only offers upload on finalized periods at spa/src/pages/payroll/periods/detail.tsx:400-402.
- Verification: proof lifecycle paths are covered by the Payroll suite; physical-file retention and restore authorization remain policy-sensitive and should receive dedicated controller tests.

### M021-F05 — bank artifact publication

- Before: api/app/Modules/Payroll/Services/BankFileService.php generated a random path and a new audit row on every download.
- After: api/app/Modules/Payroll/Services/BankFileService.php:97-240,276-323 uses a period/format artifact key, deterministic private path, temp-write/publish cleanup, idempotent current-record lookup, and read-only streaming. api/database/migrations/2026_08_25_140000_add_artifact_key_to_bank_file_records.php:12-44 backfills the newest historical artifact and adds uniqueness; api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:288-329 and api/app/Modules/Payroll/routes.php:73-77 expose explicit POST generation before GET download. The SPA calls that mutation at spa/src/api/payroll/periods.ts:82-90 and spa/src/pages/payroll/periods/detail.tsx:196-208,613-626.
- Verification: bank integrity and replay coverage passed 17/17 plus auto-generation coverage in the focused suite. Generation still holds the period lock during builder/storage work; that performance/operability part is deferred to F08.

### M021-F06 — statutory table import integrity

- Before: api/app/Modules/Payroll/Services/GovernmentContributionTableImportService.php cast CSV values through floats, skipped invalid rows, and deactivated prior schedules after a partial transaction.
- After: :25-176 stages the complete file, :178-305 parses exact decimal/date strings and rejects reversed, duplicate, or overlapping brackets before one transaction upserts and deactivates; cache invalidation occurs after the transaction. api/app/Modules/Payroll/Services/GovernmentContributionTableService.php:98-171 applies the same active-row overlap guard to CRUD activation/update. api/database/migrations/2026_08_25_141000_add_unique_government_schedule_key.php:11-24 adds database uniqueness.
- Verification: import coverage passed 4/4, including no partial writes on a bad row. Gap/expected-coverage validation and actor/version publication metadata are deferred because the statutory schedule shape and version ownership need an explicit business decision.

### M021-F07 — adjustment permissions

- Before: api/app/Modules/Payroll/routes.php:112-117 and the SPA gated read, create, approve, and reject on .create.
- After: api/database/seeders/RolePermissionSeeder.php:133-136,489-517 defines separate view/create/approve/reject capabilities, routes use the matching middleware, RejectPayrollAdjustmentRequest checks .reject, and the list/sidebar/frontend action gates use .view, .approve, and .reject (spa/src/routes/payrollRoutes.tsx:37-44, spa/src/pages/payroll/adjustments/index.tsx:158-185, spa/src/components/layout/Sidebar.tsx:613-617).
- Verification: payroll authorization coverage passed 5/5 in the isolated focused run.

### M021-F08 — population and lock pressure

- Before: api/app/Modules/Payroll/Jobs/ProcessPayrollJob.php materialized every eligible employee before processing.
- After: api/app/Modules/Payroll/Services/PayrollPeriodService.php:633-657 exposes a query and ProcessPayrollJob.php:103-126 counts then consumes it with lazyById(100), preserving claim fencing and final reconciliation.
- Deferred: BankFileService.php:114-218 still builds the payroll population and performs storage I/O under the period lock. A realistic large-population/memory/lock test and a safe artifact-claim boundary are required before changing that transaction.

### M021-F09 — decimal output boundaries

- Before: payroll summaries, variance money deltas, bank previews/builders, and de minimis aggregates crossed through (float)/number_format.
- After: api/app/Modules/Payroll/Services/PayrollPeriodService.php:91-166,1070-1078, BankFileService.php:73-76,176-191,343-365,418-527, and DeMinimisService.php:105-132,170-235 keep money as decimal strings and use Money/BCMath; bank reconciliation compares the builder total to persisted payroll totals.
- Verification: all bank formats and preview/integrity coverage passed; the broad Payroll suite passed 272/273 tests.

### Verification notes

- PHP syntax checks passed for all changed PHP source, migration, seeder, and test files.
- Isolated Docker database focused runs passed: bank integrity 17 tests/34 assertions; statutory import plus authorization 9/28; bank auto-generation plus disbursement events 12/29.
- The broader isolated Payroll plus payroll-authorization run completed 273 tests with one unrelated existing failure in PayrollMoneyFindingsRegressionTest::test_p02_01_payroll_je_has_actor_and_audit_row (the Accounting journal entry still has null created_by/posted_by; that service was not changed in this M021 pass).
- SPA typecheck remains blocked by the pre-existing JSX parse error in spa/src/pages/inventory/grn/create.tsx:237; no unrelated UI file was changed to mask it.

### Session disposition

M021-F01 through F07 and the decimal portion of F09 were implemented and rechecked. M021-F06 still needs statutory-owner decisions, M021-F08 still needs bank population/lock work, and F03/F04 need policy-specific publication/retention coverage. Release as Needs Re-audit with those pending items called out in the module status.
