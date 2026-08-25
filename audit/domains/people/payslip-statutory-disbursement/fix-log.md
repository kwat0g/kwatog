# M022 — Payslip / statutory / disbursement fix log

Audit date: 2026-08-24  
Module status: 🔁 Needs Re-audit  
Implementation status: Partial plan implementation completed; policy-dependent items remain deferred

## Audit actions

- Refreshed the registry and confirmed M022 was the first unlocked Not Started Tier 2 candidate with all declared dependencies at 📋 Plan Ready.
- Claimed people/payslip-statutory-disbursement atomically before inspecting or authoring its audit artifacts.
- Read the Payroll/HR/Admin routes, controllers, services, jobs, listeners, models, enums, document vault, migrations, permission seeder, scheduler, frontend pages/API/types, CI workflows, design-system guidance, deployment/restore notes, and focused tests.
- Ran the clean Docker-backed focused suite: 39 tests passed with 119 assertions.
- Ran SPA typecheck and lint successfully; token discipline reported 769 files clean.
- Attempted Vitest and found the same pre-existing EACCES failure writing Vite config output beneath root-owned spa/node_modules/.vite-temp. No ownership change, cache deletion, or unrelated worktree cleanup was performed.
- Preserved all unrelated user changes. At the initial audit, no M022 production
  source files were modified.

## Implementation session — 2026-08-25

M022 was resumed from its existing 📋 Plan Ready report. The report remained
valid for the M022 source surface at claim time; unrelated concurrent changes
in dependency/shared files were preserved. The plan was not rewritten.

### Implemented and verified

1. **M022-F01 — document-vault ownership boundary (Broken/P1).**

   - Before: `DocumentController` compared a payslip document's `entity_id`
     directly with `user.employee_id`, even though the vault row stores a
     `Payroll` id; `payroll.view` consequently authorized arbitrary payslip
     documents.
   - After: `PayrollPublicationPolicy` defines the publishable payroll
     boundary at `api/app/Modules/Payroll/Services/PayrollPublicationPolicy.php:20-76`.
     `api/app/Common/Controllers/DocumentController.php:112-171` resolves the
     owning Payroll, rejects malformed polymorphic pairs and unpublished/error
     rows, then applies owner, department-head, view-all, HR-sensitive, and
     system-admin access explicitly.
   - Regression coverage: owner/cross-owner, department boundary, and forged
     entity-pair tests at
     `api/tests/Feature/Documents/DocumentControllerTest.php:85-137`.

2. **M022-F02 — finalized publication predicate (Broken/P1).**

   - Before: payroll list/show/PDF and self-service certificate queries could
     expose computed, approved, voided, or error rows; the BIR catalogue only
     checked whether any historical row existed.
   - After: `api/app/Modules/Payroll/Services/PayrollPublicationPolicy.php:41-66`
     is applied to payroll list/show/PDF at
     `api/app/Modules/Payroll/Controllers/PayrollController.php:28-101`, PDF
     generation at `api/app/Modules/Payroll/Services/PayslipPdfService.php:31-34`,
     self-service catalogue/certificate reads at
     `api/app/Modules/HR/Controllers/SelfServiceController.php:462-468` and
     `api/app/Modules/HR/Services/SelfServiceDocumentService.php:95-203`.
     Only finalized/disbursed rows without an error are publishable; direct
     requests for other states return a business-rule 422 or 404 when no
     published source exists.
   - Regression coverage: all lifecycle states, error rows, direct URLs, and
     draft certificates at `api/tests/Feature/Payroll/PayslipPublicationTest.php:20-72`.

3. **M022-F06 — direct statutory response confidentiality (partial fix).**

   - Before: direct CSV/XLSX statutory responses lacked the vault's explicit
     no-store/nosniff response contract.
   - After: CSV and XLSX responses set private no-store, no-cache, and
     nosniff headers at `api/app/Modules/Payroll/Controllers/StatutoryExportController.php:20-28,96-110`
     and `api/app/Modules/Payroll/Controllers/BirAlphalistController.php:31-37`.
     Year/month request ranges are validated at
     `api/app/Modules/Payroll/Controllers/StatutoryExportController.php:31-49`.
   - Still pending: immutable export-run ledger, actor/scope/source snapshot,
     policy/table versions, checksum, revision/revocation, and retention.

4. **M022-F07 — queued email publication re-check (partial fix).**

   - Before: the email job could render/send after its parent period had been
     voided; the listener could claim rows from a stale finalization event.
   - After: the listener checks the current period and claim boundary at
     `api/app/Modules/Payroll/Listeners/EmailPayslipPdfOnPayrollFinalized.php:33-40,90-104`.
     The job locks the Payroll and parent period through the publication check,
     render, send, and sent-marker write at
     `api/app/Modules/Payroll/Jobs/SendPayslipEmailJob.php:45-107`; a voided or
     error row is marked terminal-for-this-run instead of mailed.
   - Regression coverage: voided listener/job cases at
     `api/tests/Feature/Payroll/EmailPayslipOnFinalizeTest.php:99-115` and
     `api/tests/Feature/Payroll/SendPayslipEmailJobTest.php:70-98`.
   - Still pending: provider/message-id idempotency or an explicitly approved
     at-least-once duplicate-delivery policy and recovery test.

5. **M022-F09 — publication/status UI and request validation (partial fix).**

   - Before: the payslip table labeled `computed_at` as the pay period and
     every successful row as Computed; statutory inputs accepted arbitrary
     year/month values and the alphalist was presented as a filing export.
   - After: the API returns authoritative period dates/status at
     `api/app/Modules/Payroll/Resources/PayrollResource.php:33-39`; the payslip
     UI uses them at `spa/src/pages/self-service/payslips.tsx:23-72`; statutory
     controls validate bounds and identify the alphalist as internal staging
     CSV at `spa/src/pages/payroll/statutory/index.tsx:50-143`.
   - Still pending: backend statutory preflight/review data for included
     periods, identifier exceptions, reconciliations, and filing readiness,
     plus browser/e2e verification.

### Deferred plan items

- **M022-F03 — official statutory artifact contract:** requires the statutory
  owner's decision between a clearly gated internal staging extract and an
  authoritative DAT/XML/control-total implementation. The UI now avoids
  calling the CSV an official artifact, but the implementation decision is
  not guessed.
- **M022-F04 — taxable-base policy:** requires authoritative treatment of
  statutory, withholding, loan, adjustment, de minimis, and 13th-month amounts
  before changing financial calculations. No tax formula was changed.
- **M022-F05 — statutory completeness preflight:** required identifier rules,
  override authority, and the SSS EC model are not specified. Existing blank
  and zero output behavior remains pending that decision.
- **M022-F06 — export governance remainder:** run ledger/snapshot/checksum and
  retention/revocation remain pending as noted above.
- **M022-F07 — delivery idempotency remainder:** provider acceptance/message
  identity and duplicate policy remain pending as noted above.
- **M022-F08 — versioned payslip artifacts:** current artifact identity,
  migration/retention, concurrency, and cleanup policy remain pending; the
  per-download render/store behavior was not changed partially.

### Verification

- Isolated Docker/PostgreSQL M022 suite: **45 passed, 132 assertions** in
  02:40.129, including the new publication, vault, void-race, header, and
  input-validation tests. The shared `ogami_test` run was not usable because
  other parallel sessions were refreshing the same schema and caused
  PostgreSQL deadlocks; no other session was interrupted.
- PHP syntax checks passed for all modified PHP files.
- M022 frontend ESLint passed for the changed payslip/statutory files.
- `npm run audit:tokens` passed (771 files checked).
- Full SPA typecheck remains blocked by the pre-existing missing `qrcode`
  module/implicit-any errors in `spa/src/pages/assets/detail.tsx`; full lint
  remains blocked by unrelated errors in `useChainProgress.tsx` and
  `accounting/journal-entries/edit.tsx`.

M022 is released as **🔁 Needs Re-audit**. The remaining items are policy/
artifact-governance decisions and incomplete P1 statutory controls, not a
fresh discovery pass.

## Code changes

M022 implementation changes were applied as described above. The following
audit artifacts were already present and were preserved:

- inventory.md
- audit-report.md
- action-plan.md
- fix-log.md

## Why fixes were deferred

The findings are dominated by a cross-employee confidential-document authorization flaw, publication-state enforcement, statutory/tax policy, missing filing-format decisions, export audit/retention, and queue send semantics. These require separate design, migration/permission review, policy-owner decisions, concurrency tests, and release evidence. Applying only the UI label or download-performance improvements would leave the P1 data-boundary and statutory risks unresolved.

## Release note

M022 is ready to be released through the audit workflow as 🔁 Needs Re-audit.
The release step must remove the claim lock, regenerate the registry, and
identify the next eligible module.
