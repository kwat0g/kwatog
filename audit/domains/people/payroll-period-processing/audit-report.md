# M021 — Payroll period processing audit report

Audit date: 2026-08-24  
Status: 📋 Plan Ready  
Release recommendation: separate implementation work required

Production audit: 38/100, blocked, because payroll calculation, disbursement evidence, bank artifacts, and statutory-table publication still have financial-integrity gaps even though compute claims, GL handoff, and lifecycle locking are comparatively strong. The focused Docker suite passed 264 tests and 771 assertions, but the browser harness and the highest-risk concurrency/publication scenarios were not verified.

## Executive assessment

M021 has a strong operational skeleton. Period creation validates date and employee-scope overlap; compute uses a durable outbox, per-period overlap protection, claim tokens, and per-employee transactions; failures become visible payroll rows that block approval; approval/finalization/disbursement/void paths lock and reload authoritative rows; GL posting is idempotent, balanced, period-guarded, and audited; bank builders reconcile decimal totals; payslip email has durable state/recovery; statutory exports and self-service payslips have dedicated server-side boundaries.

The release boundary is weaker than that skeleton. A dependency failure in de minimis calculation is treated as zero tax-impacting excess and can continue through payroll. Approved adjustments are not atomically claimed before application. Disbursement can be closed with an arbitrary proof without reconciling the bank artifact or net-pay total. Proof archival is not lifecycle-safe and its advertised restore path cannot restore the deleted private file. Bank-file download is a generating side effect that creates a new random artifact on each request. Government contribution imports can deactivate a prior schedule after a partial or invalid upload. These are P1 financial or publication risks, so the module is plan-ready rather than release-ready.

## Blockers and high-value findings

### M021-F01 — P1, Broken: de minimis lookup failure silently becomes zero taxable excess

Evidence: PayrollCalculatorService.php:253-260 calls the de minimis service while building contributions. PayrollCalculatorService.php:777-794 returns 0.00 when the class is absent and catches any Throwable from the service, logging a warning and continuing with zero excess.

Impact: a missing table, settings failure, query error, or service regression can silently understate taxable de minimis excess and therefore under-withhold BIR tax while the payroll remains computable, approvable, and finalizable. The error is not represented in the payroll error rows that approval checks.

Action: make statutory/de minimis dependency failure fail closed for the affected employee or period, or persist a visible manual-review state that blocks approval/finalization. Distinguish a legitimate zero result from an unavailable calculation. Add missing-table, settings, service-exception, retry, and approval-block tests.

### M021-F02 — P1, Broken: an approved adjustment can be applied twice under concurrent computation

Evidence: PayrollCalculatorService.php:963-995 queries Approved adjustments with whereNull(applied_at) but does not lock the adjustment rows before creating deduction details and then setting applied_at. The sequential coverage in PayrollCalculatorServiceTest.php:576-646 proves recomputation does not double-apply after the first commit, but does not cover two workers reading the same unapplied row before either worker marks it.

Impact: concurrent computations or overlapping period work can create duplicate adjustment deductions, duplicate application links, and incorrect net pay. A sequential recomputation guard does not protect the read/insert/update interleaving.

Action: claim approved adjustments atomically under a stable lock order, or add a database-level application key that makes one payroll/adjustment application unique and safely handles the losing transaction. Re-read the authoritative state before applying and add a two-connection concurrency test with rollback/retry behavior.

### M021-F03 — P1, Incomplete: any disbursement proof can close a full period

Evidence: DisbursementProofController.php:45-90 accepts an optional disbursed_amount and date, uploads a private file, and creates a proof without requiring a matching bank artifact, total, or lifecycle status. PayrollPeriodService.php:995-1025 allows Finalized to become Disbursed when GL is Posted or NotRequired and at least one non-deleted proof exists; it does not require bank_file_status Generated, proof amount coverage, or proof total equal to period net pay. Migration 0151 permits partially_disbursed and nullable proof amounts, but the close service only implements the full Disbursed transition.

Impact: a period can be marked fully disbursed with a zero/omitted/mismatched proof or with no generated bank file. The status then becomes a financial assertion that the evidence does not support, and the partial-disbursement model is not represented in the close rules.

Action: define whether disbursement is bank-file based, manual-proof based, or supports partial settlement. Require proof metadata and amount validation, reconcile the sum of valid proofs against the payable net total, require bank-file generation when policy requires it, and enforce allowed proof upload statuses. Add mismatch, partial, duplicate, zero, missing-file, and replay tests.

### M021-F04 — P1, Broken: proof archive and restore do not preserve the evidence artifact

Evidence: PayrollPeriod.php:55 casts status to PayrollPeriodStatus. DisbursementProofController.php:143-166 compares that enum-cast value strictly with the string disbursed, so the post-disbursement archive guard never matches. The same method soft-deletes the proof and registers after-commit physical file deletion. DisbursementProofController.php:168-172 restores only the model. Routes at api/app/Modules/Payroll/routes.php:89-98 use standard implicit binding for restore, which excludes SoftDeletes rows by default, and the restore action has no file restoration path. PayrollPeriodService.php:104-109 explicitly uses withTrashed for the period detail, while the SPA at spa/src/pages/payroll/periods/detail.tsx:1304-1305 tells operators the proof can be restored later.

Impact: operators can archive evidence after disbursement despite the stated policy, the restore route generally cannot resolve an archived proof, and even a manually resolved restore would point to a file already deleted from private storage. Audit evidence can therefore be lost or appear restorable when it is not.

Action: use enum-aware lifecycle checks, make archived/disbursed evidence policy explicit, bind trashed proofs intentionally, and choose a recoverable retention strategy (for example, retain and hide the file, or restore from an immutable archive). Add archive-after-disbursement, deleted-record restore, missing-file, authorization, and physical-file retention tests.

### M021-F05 — P1, Incomplete: bank-file download is a non-idempotent generating side effect

Evidence: BankFileService.php:92-196 generates a file, creates a BankFileRecord with a random filename, and marks the period generated. BankFileService.php:231-247 stream() calls generate() before every read. Migration 0037_create_bank_file_records_table.php:13-30 has no unique active-artifact/current-record/revocation key. Event replay is guarded by GenerateBankFileOnPayrollFinalized.php, but that guard does not protect manual or repeated GET downloads.

Impact: repeated downloads, browser retries, or concurrent operators can create multiple private files and audit records for one disbursement, with no explicit current artifact or revocation semantics. The transaction also holds the period row lock while loading payrolls and writing the file, increasing contention for larger periods.

Action: make generation a command/explicit mutation with an idempotency key and a durable current-artifact record. Make download read-only against that record, authorize the artifact and period state, and define regeneration/revocation behavior. Add repeated GET, concurrent download, failed-write cleanup, and replay tests.

### M021-F06 — P1, Incomplete: statutory contribution imports can publish an incomplete schedule

Evidence: GovernmentContributionTableImportService.php:66-118 converts numeric inputs through float, catches row errors, increments skipped/errors, and continues within the import transaction. At :120-129, deactivatePrior can deactivate all prior active rows before the import has established that the uploaded set is complete, non-overlapping, and valid. Migration 0031_create_government_contribution_tables_table.php:26-40 provides indexes but no uniqueness constraint for duplicate effective brackets. GovernmentContributionTableService.php:92-127 exposes row-level update/deactivate/activate operations without a full schedule-set validation. ImportGovContributionTableTest.php:84-101 explicitly accepts a malformed row being skipped while valid rows import.

Impact: a bad or partial CSV can leave the company with an incomplete active bracket schedule after the previous schedule is deactivated. Payroll may fail part-way through, use an unintended bracket set, or require emergency manual correction. Float parsing also makes exact statutory thresholds less reliable.

Action: stage the entire upload, validate agency/effective-date completeness, exact decimal values, bracket ordering, gaps/overlaps, duplicates, and expected row count, then activate the new version only after all rows pass. Preserve the previous active version on any failure, add a unique database key, record actor/version/audit metadata, and repair the SoftDeletes restore route for table rows if restore remains supported.

### M021-F07 — P2, Incomplete: adjustment read/create/approve/reject permissions are collapsed

Evidence: api/app/Modules/Payroll/routes.php:108-114 uses payroll.adjustments.create for list, show, create, approve, and reject. RolePermissionSeeder.php:470-486 grants that permission to HR, while :494-504 grants the payroll module set to Finance. PayrollAdjustmentService.php:74-114 checks the creator identity and state but does not introduce a separate approval permission or role-specific checker boundary.

Impact: maker-checker prevents self-approval but does not establish a distinct authorization capability. A permitted HR maker can approve another HR adjustment, and read access cannot be separated from mutation/approval where the operating model requires it.

Action: define separate adjustment view, create, approve, reject, and possibly apply permissions. Preserve self-approval rejection, add role/action matrix tests, and update UI gates and seed data together.

### M021-F08 — P2, Incomplete: whole-population reads and bank-file I/O can amplify lock and memory pressure

Evidence: PayrollPeriodService.php:631-640 loads all available employees with get(). ProcessPayrollJob.php:104-116 also obtains the full employee collection for a computation. BankFileService.php:102-124 loads all payrolls, and :163-193 writes the artifact while the period transaction/lock remains active. These paths have no bounded chunk/stream contract in the inspected code.

Impact: large employee populations can cause worker memory growth, slow retries, and long periods during which lifecycle actions are blocked by the period lock. Bank generation and payroll computation can turn a large normal run into an operational incident.

Action: measure expected population sizes, introduce bounded chunking/streaming where safe, minimize lock duration around external/file I/O, and add load/recovery tests before changing transaction boundaries.

### M021-F09 — P2, Incomplete: decimal money crosses float boundaries in summaries and artifacts

Evidence: PayrollPeriodService.php:89-96 and :139-178 format summary and variance totals through float; BankFileService.php:289-291 uses float for preview totals and :336/:369 use float formatting in CSV builders. Core calculator and GL code otherwise uses decimal string/bc math.

Impact: display and generated-file values can diverge at large amounts or fractional-cent boundaries even when persisted totals are correct. This weakens the consistency of operator review and artifact reconciliation and makes future format changes risky.

Action: keep money as decimal strings through response DTOs and artifact builders, centralize scale/rounding policy, and add high-value and fractional-cent regression fixtures comparing persisted, preview, CSV, and bank totals.

## Strengths observed

- Compute claims use a token, durable outbox handoff, per-period overlap protection, stale-claim recovery, and a job that is intentionally not ShouldBeUnique so the claim fence remains authoritative.
- Per-employee transactions persist diagnostic failures and approval blocks error rows rather than silently treating failed employees as successful.
- Payroll-cycle claims use a unique database guard, protecting the employee/period cycle from sequential duplicate payroll rows.
- Approval, finalization, disbursement, retry, force-unlock, and void paths generally lock and reload authoritative state; maker-checker and audit events are present.
- GL posting builds a balanced journal from decimal strings, refuses closed posting periods, is idempotent on an existing journal, and records the actor/audit trail.
- Bank generation refuses missing bank accounts, uses private storage, and compares builder totals with database totals using decimal arithmetic.
- Payslip email tracks queued/sent/failed state and has reconciliation/recovery behavior. Statutory exports have a dedicated permission and finalized/disbursed status gate. Payroll and self-service controllers enforce server-side employee/department scope.
- The SPA follows the Atelier system with status-gated actions, explicit confirmation dialogs, progress fallback, loading/error/empty states, accessible labels/focus, and dense data-table patterns.

## Evidence checked

- Current branch/release surface: main at b269eafd, origin aligned. Recent changes include backup/health/export hardening. Pre-existing dirty changes were preserved and no M021 production files were modified.
- Payroll routes, requests, controllers, services, jobs, listeners, models, enums, migrations, permission seeders, console schedules, frontend pages/components/API/types, focused tests, load fixture, design-system guidance, deployment, and restore documentation were inspected.
- Clean Compose test run: 264 passed, 771 assertions, 03:36.390. The host-side run was invalidated before assertions by DB_HOST=db DNS resolution outside Compose and was not treated as a product failure.
- SPA typecheck completed without diagnostics.

## Evidence missing / follow-up validation

- Browser/e2e verification was not completed because Vite could not write its cache under root-owned spa/node_modules/.vite-temp; no ownership change or cleanup was performed.
- No two-connection test covers approved-adjustment application, de minimis dependency failure, proof amount reconciliation, or statutory import activation safety.
- No load run covers large payroll populations, bank-file generation, scheduler throughput, or worker recovery under contention.
- No deployed queue/SMTP/realtime/scheduler/storage verification or restore-drill evidence was available.

## Release decision

No production-code fixes were applied. The majority of findings require migrations, transaction/locking changes, financial policy decisions, permission changes, or new concurrency/publication tests. Applying only the smaller float, UI, or query improvements would leave the primary financial-integrity risks unresolved. M021 is released as 📋 Plan Ready with the ordered action plan below.
