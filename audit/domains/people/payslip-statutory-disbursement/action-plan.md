# M022 — Payslip / statutory / disbursement action plan

Date: 2026-08-24  
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session

The focused Docker suite passed 39 tests and 119 assertions; SPA typecheck, lint, and token discipline also passed. The primary risks require authorization, publication-state, statutory-policy, export-artifact, and queue semantics work. They should be implemented in reviewable sessions with negative tests and release evidence.

## Ordered implementation plan

### 1. M022-F01 — Repair document-vault ownership and permission mapping

- Classification/severity: Broken, P1
- Scope: medium/large
- Session recommendation: separate-recommended
- Resolve a payslip document to its Payroll owner and compare the payroll employee with the caller. Do not treat payroll.view as cross-employee access. Keep payroll.payslip.view_all, HR-sensitive access, department policy, and system-admin access explicit; reject forged document entity pairs.
- Tests: owner view/download, cross-owner denial, department-head boundary, view-all success, admin success, malformed entity pair, and regression through both direct payslip and generic document routes.

### 2. M022-F02 — Enforce one finalized publication predicate

- Classification/severity: Broken, P1
- Scope: medium/large
- Session recommendation: separate-recommended
- Define the allowed employee-publication statuses and enforce them in payroll list/show/PDF, self-service certificates, document-vault access, and email send. Keep draft preview as a separately authorized route if required, and make voided/replaced rows non-publishable.
- Tests: draft, processing, computed, approved, finalized, disbursed, voided, and error rows across direct API, UI-triggered URL, generic document route, certificate endpoints, and email jobs.

### 3. M022-F03 — Resolve the official statutory artifact contract

- Classification/severity: Missing, P1
- Scope: large
- Session recommendation: separate-recommended
- Obtain the authoritative filing specification and decide whether the current alphalist CSV is an internal staging extract or whether official DAT/XML/control records are required. Rename/gate staging output or implement the approved format with versioned fixtures and operator handoff.
- Tests: official sample file, schema/field-order validation, control totals, rejected invalid file, correct filename/content type, and manual-filing handoff evidence.

### 4. M022-F04 — Centralize and reconcile taxable-base calculations

- Classification/severity: Broken, P1
- Scope: large
- Session recommendation: separate-recommended
- Create one decimal tax-base policy/service used by alphalist, 1601-C, 1604-CF, BIR 2316, payroll summaries, and GL reconciliation. Record treatment for statutory contributions, withholding, loans, adjustments, de minimis, 13th-month correction, and effective dates.
- Tests: each deduction category individually and together, annual/monthly identity, 13th-month correction, fractional-cent totals, cross-export equality, and reconciliation after void/replacement.

### 5. M022-F05 — Add statutory completeness preflight

- Classification/severity: Incomplete, P1
- Scope: medium/large
- Session recommendation: separate-recommended
- Validate required TIN/SSS/PhilHealth/Pag-IBIG identifiers and agency-specific components before generating a file. Return a bounded exception report, block export by default, and support only an explicit audited override. Model SSS EC or remove the column until the authoritative policy is implemented; never silently emit zero.
- Tests: missing/invalid identifiers, missing components, soft-deleted employee, override authorization, row counts, contribution totals, and no-download-on-failed-preflight.

### 6. M022-F06 — Govern confidential statutory artifacts

- Classification/severity: Incomplete, P1
- Scope: medium/large
- Session recommendation: separate-recommended
- Add no-store/nosniff response headers to direct exports and introduce a confidential export run ledger or vault artifact containing actor, source period ids, table/policy versions, checksum, generated time, and revision/revocation state. Ensure later payroll corrections cannot silently rewrite an already-reviewed run.
- Tests: response headers, actor/permission audit, exact source snapshot, checksum, repeat download, revoke/retention, and cache-proxy contract.

### 7. M022-F07 — Re-check publication state at email send

- Classification/severity: Incomplete, P1
- Scope: medium
- Session recommendation: separate-recommended
- Lock/re-read the parent period before rendering/sending and refuse voided/non-published periods. Define replacement behavior for queued jobs. Add provider/message idempotency or document the accepted at-least-once delivery policy with support recovery.
- Tests: queued-then-void, queued-then-replaced, event replay, provider accepted/worker crash, retry, missing email, and failure notification.

### 8. M022-F08 — Make payslip artifacts versioned and downloads read-only

- Classification/severity: Incomplete, P2
- Scope: medium
- Session recommendation: separate-recommended
- Separate generation from download, persist one current version per published Payroll/document type, and add a unique version key, retention policy, cleanup, and bounded rendering. Preserve private storage and checksum behavior.
- Tests: repeat/concurrent download, generation retry, changed source row, missing blob, retention cleanup, and version selection.

### 9. M022-F09 — Align UI/API publication and filing controls

- Classification/severity: Polish, P2
- Scope: small/medium
- Session recommendation: same-session-ok
- Return period start/end and authoritative status, display those instead of computed_at/Computed, validate year/month ranges, and add a statutory preflight/review panel showing included periods, exceptions, totals, and whether output is staging or filing-ready. Keep the Atelier loading/error/empty/accessibility patterns.
- Tests: UI/API contract, invalid period input, no eligible periods, preflight failure, download success, keyboard interaction, and mobile layout.

## Cross-module decisions to record

- Whether the generic document vault owns payslip authorization or each payroll output has its own resource/policy.
- Which payroll statuses constitute an employee-publishable payslip and whether voided runs can ever be reissued under the same identity.
- Whether statutory output is a filing artifact, a staging extract, or both with separate names, permissions, and audit state.
- Authoritative taxable-base and statutory identifier/EC policy, including effective table versions and correction/void behavior.
- Whether direct generated personal certificates intentionally bypass the vault and what audit/retention requirements then apply.

## Session decision

No production-code implementation is authorized for this audit session. Eight findings are separate-recommended because they touch sensitive-data authorization, financial/statutory calculations, publication state, document retention, or queue semantics. Only the UI contract item is a small same-session candidate; applying it alone would make the product look clearer while leaving the primary exposure and filing risks open.

## Definition of done for the next implementation session

- Cross-employee document access is denied under ordinary payroll.view and tested through generic and direct routes.
- Payslip/certificate/email publication requires an authoritative finalized/disbursed state and excludes void/error rows.
- The official statutory artifact contract is documented and implemented or the staging CSV is explicitly renamed/gated.
- All statutory outputs share one reconciled tax-base policy and exact decimal totals.
- Missing identifiers/components block or create an audited override exception; no silent blank/zero statutory fields remain.
- Confidential export headers, run ledger/snapshot, checksum, retention, and revision behavior are verified.
- Queue/send races, large-population behavior, migration order, rollback, worker restart, SMTP, and staging filing evidence are documented before release.
