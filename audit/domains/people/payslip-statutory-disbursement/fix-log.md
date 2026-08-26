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

---

## Verification session — 2026-08-27 (separate-session run)

Claimed `people/payslip-statutory-disbursement` (RECLAIMED — 35h stale lock from a
crashed session). This session's mandate was to EXECUTE what the 2026-08-25
session wrote, plus two named failing tests. No same-session-ok /
separate-recommended re-check was performed; this IS that separate session.

### Fix 1 — repo-wide `diffIn*` sign-convention lint (coordinator-authorised, out of module)

`Tests\Unit\CarbonDiffSignConventionTest::test_no_diff_call_leaves_its_sign_convention_implicit`
named exactly one violating call site, and it is **outside M022** — in the Quality
module (whose own audit slice, `quality/ncr-capa`, is separately locked). Only that
single call was touched; nothing else in the file or module was changed.

- File: `api/app/Modules/Quality/Services/EffectivenessService.php:220`
- Before:
  `$overdueDays = max(0, $action->next_effectiveness_check_at->startOfDay()->diffInDays($today));`
- After (`, true` + a comment recording why):
  `$overdueDays = max(0, $action->next_effectiveness_check_at->startOfDay()->diffInDays($today, true));`
- Why `true` and not `false`: the query feeding this loop
  (`api/app/Modules/Quality/Services/EffectivenessService.php:192`) filters
  `whereDate('next_effectiveness_check_at', '<=', $today)`, so the receiver is
  provably the earlier-or-equal instant. Under Carbon 3 the signed result is
  therefore already non-negative and `true`/`false` agree numerically here — but
  the caller wants a **magnitude** (days overdue, rendered into an operator
  notification and compared against `overdue_escalation_days`), and the existing
  `max(0, …)` clamp already folds any sign. `, true` is the honest annotation:
  "this is a magnitude, and the receiver is checked to be earlier."
- Verified: `CarbonDiffSignConventionTest` — **5 passed, 7 assertions** (was
  1 failed / 4 passed). Behaviour regression check on the file I touched:
  `NcrCapaEffectivenessTest` (see verification section below).

### Finding 2 — P02-01 payroll JE actor: PRODUCTION DEFECT, and NOT fixable inside M022

`Tests\Feature\Payroll\PayrollMoneyFindingsRegressionTest::test_p02_01_payroll_je_has_actor_and_audit_row`
fails on `journal_entries.created_by IS NULL`. Actual output: `1 failed, 3 passed
(8 assertions)`; the other three P01-01 / P01-02 / P02-02 regressions pass.

**Not a test artifact.** The fixture does not rely on an authenticated user at all
— it stamps `finalized_by` on the period
(`api/tests/Feature/Payroll/PayrollMoneyFindingsRegressionTest.php:201`), which is
exactly what production sets on finalize, and the production path deliberately
derives its actor from that column rather than from `Auth::id()`:

- `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:315-324`
  `$actorId = $period->finalized_by; … $this->journals->create([…], $actor); $this->journals->postSystem($je, $actorId);`

So an actor **is** available and **is** passed in, on every path including a queued
one. The null is introduced downstream, unconditionally:

- `api/app/Modules/Accounting/Services/JournalEntryService.php:139`
  `'created_by' => empty($data['reference_type']) ? $user?->id : null,`

Any JE carrying a `reference_type` — i.e. every machine-generated entry in the
system — has its supplied maker discarded. `posted_by` still lands
(`JournalEntryService.php:370`) and the `audit_logs` row still lands
(`PayrollGlPostingService.php:327-343`), so the gap is specifically the
**created_by attribution**, not the whole audit trail.

**Regression, introduced un-executed.** `git log -S` dates line 139 to
`167de85e "chore: remaining uncommitted work from ~50 crashed audit sessions"`,
whereas both the test (`14a323a0`) and the payroll actor derivation (`482d8c7e`)
predate it. It is one of the source-only fixes that was never run.

**Why it cannot simply be reverted — the nulling is load-bearing.**
`create()`'s sibling guard `assertNotSelfPosting()`
(`JournalEntryService.php:398-416`) keys segregation-of-duties on
`created_by === $by->id`, and five source-linked writers pass the **same** `$by`
to `create()` and then to `post()`:

| writer | create actor | post path |
|---|---|---|
| `Accounting/Services/InvoiceService.php:280-287`, `:422-432` | `$by` | `post($je, $by)` |
| `Accounting/Services/BillService.php:613-623`, `:997-1004` | `$by` | `post($je, $by)` |
| `Accounting/Services/CreditNoteService.php:185-192` | `$by` | `post($je, $by)` |
| `Assets/Services/DepreciationService.php:103-110` | `$by` | `post($je, $by)` |
| `HR/Services/FinalPayService.php:259-266` | `$by` | `post($je, $by)` |

With the default `accounting.je_self_post_limit = 0`, restoring `created_by` for
source-linked entries without touching the guard would `abort(403)` every one of
those flows for any non-admin finance officer. That is almost certainly why line
139 was written.

**The two purposes are conflated in one column.** `created_by` is being read as
both (a) audit attribution "who caused this entry" and (b) the maker identity for
maker-checker. They only collide for the `post()` writers. Payroll does **not**
use that path — `PayrollGlPostingService` uses `postSystem()`
(`JournalEntryService.php:356-386`), which never consults `created_by` — so
recording payroll's maker is provably incapable of tripping the guard. Same for
the other two `postSystem()` writers, `Inventory/Services/GrnGlPostingService.php:225`
and `Inventory/Services/MovementGlPostingService.php:201`.

Also relevant to any fix: `created_by` can only be written at draft time.
`api/database/migrations/2026_08_25_100000_harden_journal_immutability.php`
installs `prevent_posted_journal_mutation()` / `prevent_posted_journal_line_mutation()`
PostgreSQL triggers, so a post-hoc patch of the column on a posted entry raises
`Posted journal entries are immutable.` The fix has to happen inside
`JournalEntryService::create()`, at the journal boundary.

**NOT FIXED — deliberately, twice over.** (a) It is a decision about *who is
recorded as an actor* on a financial posting and about the scope of a
segregation-of-duties gate, which is the policy owner's call, not an auditor's;
and (b) the only file that can carry the fix is
`api/app/Modules/Accounting/Services/JournalEntryService.php`, owned by
`finance/journal-ledger` (M026), which is **locked by a live parallel session**.
Writing around another module's explicitly-commented invariant from the payroll
side would be the wrong shape of fix even if the lock were free.

Options for the policy owner, with the evidence above:

- **Option A — narrow the guard, keep the attribution.** In `create()` always
  record `$user?->id`; change `assertNotSelfPosting()` to return early when
  `$je->reference_type !== null`. Restores attribution on all eight source-linked
  writers, keeps all five `post()` flows working, and leaves maker-checker fully
  enforced on manual entries — note `createManual()` already refuses a
  `reference_type` outright (`JournalEntryService.php:166-173`), so "manual" and
  "no reference_type" are already synonymous. Smallest change consistent with
  both invariants. Touches a SoD gate, so it needs the finance owner's sign-off.
- **Option B — split the columns.** Add a `source_actor_id` (or similar) for
  machine-generated attribution and leave `created_by` meaning strictly "manual
  maker". Most explicit, no SoD semantics change; costs a migration, a resource
  change, and an update to every source-linked writer plus this test's assertion.
- **Option C — accept a null maker on source-linked entries as intended, and
  re-point the test.** Cheapest, but it concedes that a financial posting carries
  no recorded creator, which reads against CLAUDE.md's "all auth + financial
  events logged with IP + user agent". Only defensible if `posted_by` + the
  `audit_logs` row are agreed to be sufficient attribution — an explicit
  decision someone must make and record, not a silent one.

An explicit **system actor** is *not* the answer here, unlike the queued-path case
it superficially resembles: the actor is a real, known human (`finalized_by`) and
is already being passed in. Substituting a synthetic system user would lose
information that the code already has.

Test left failing on purpose. It is a true statement about production.

### Verification (this session, isolated DB `ogami_w2_pay`)

Contrary to the repo-wide expectation that the ~50 crashed sessions left
source-only fixes that had never been executed, **M022's own 2026-08-25 work
does execute and is green.** Every test class the previous session cited as
regression coverage was re-run here from a clean `migrate:fresh`:

| class | result |
|---|---|
| `Feature/Documents/DocumentControllerTest` (F01) | 14 passed, 30 assertions |
| `Feature/Payroll/PayslipPublicationTest` (F02) | 3 passed, 7 assertions |
| `Feature/Payroll/StatutoryExportsTest` (F06) | 13 passed, 46 assertions |
| `Feature/Payroll/BirAlphalistTest` (F06) | 7 passed, 26 assertions |
| `Feature/Payroll/EmailPayslipOnFinalizeTest` (F07) | 4 passed, 8 assertions |
| `Feature/Payroll/SendPayslipEmailJobTest` (F07) | 3 passed, 15 assertions |
| `Feature/Payroll/PayslipEmailRecoveryTest` (F07) | 2 passed, 7 assertions |
| `Feature/Payroll/PayslipNotificationDedupeTest` (F07) | 1 passed, 1 assertion |
| `Feature/Payroll/PayrollGlPostingTest` | 5 passed, 16 assertions |

**52 passed, 156 assertions, 0 failures** across the M022 surface.

So F01 and F02 (both P1 Broken) are **done and now verified**. F06, F07 and F09
remain **partial by design** — their implemented halves are verified above; the
deferred halves (export-run ledger/checksum/retention, provider message-id
idempotency, statutory preflight, F09's browser/e2e leg) are untouched policy or
UI-verification work, not unexecuted code.

Other runs:

- `Tests\Unit\CarbonDiffSignConventionTest` — **5 passed, 7 assertions**. Green
  after Fix 1.
- `Tests\Feature\Quality\NcrCapaEffectivenessTest` — **5 passed, 10 assertions**.
  Behaviour unchanged by the `EffectivenessService` annotation, as expected.
- `Tests\Feature\Payroll\PayrollMoneyFindingsRegressionTest` — **1 failed,
  3 passed (8 assertions)**. The single failure is P02-01 above, left failing
  deliberately because it is a true statement about production and its fix is a
  policy decision in a file owned by a locked module.

Worth recording: `PayrollGlPostingTest` passes and asserts the JE's balance,
idempotency, and status gate but **never asserts an actor**, which is why the
`created_by` regression reached this session invisibly. Whatever option is chosen
for P02-01, that class is the natural place for a permanent actor assertion.

Not run (out of this session's memory budget — 4 parallel agents on 3.7 GiB, and
the Playwright suite needs real Chromium): the F09 frontend leg
(`spa/src/pages/self-service/payslips.tsx`, `spa/src/pages/payroll/statutory/index.tsx`),
SPA typecheck/lint, and `npm run audit:tokens`. These were reported clean by the
2026-08-25 session and no SPA file was modified here.
