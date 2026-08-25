# M026 — journal-ledger fix log

Audit date: 2026-08-25  
Final disposition: Plan Ready

## Session ownership and scope

- M026 `finance / journal-ledger` was claimed after the first unlocked
  `Needs Re-audit` candidate (`people / employee-master`) was locked by another
  session.
- The existing 2026-08-24 report, action plan, and fix log were read in full.
- Current uncommitted journal source changes made that report stale, so this
  session reran discovery, hardening, and polish from the current worktree.
- Only this module's audit artifacts were edited by this session. Existing
  production changes in the shared worktree were preserved.

## Before / after

- Before: M026 was `🔁 Needs Re-audit` with a prior Plan Ready handoff and
  uncommitted journal service, migration, test, and SPA changes present.
- After: current code was re-audited. No production source fixes were applied;
  the module is being released as `📋 Plan Ready` with F-001 through F-010
  pending implementation and re-audit.

## Verification

- Scoped PHP syntax checks passed for the changed journal service, controller,
  models, requests, resource, and immutability migration.
- `php artisan route:list --path=journal-entries` resolved 10 routes.
- Focused backend tests could not connect to PostgreSQL host `db`; 15 tests
  failed before assertions with DNS resolution errors. No test result is being
  claimed from that run.
- Targeted SPA ESLint found F-009 in `edit.tsx:79`. Full SPA typecheck also
  reports unrelated errors in HR CreateAccountModal, accounting periods, and
  assets detail; no journal type error was reported.

## Deferred findings

All findings remain pending implementation and re-audit: F-001 through F-010.
The next implementation session should start with F-001/F-002 and F-003, then
resolve the reversal-date policy before making the acceptance suite authoritative.

## Implementation session — 2026-08-25

M026 was claimed from `📋 Plan Ready` and the existing plan was executed in
order. The following items were implemented and re-checked:

- F-001: terminal archive protection now covers model deletes at
  `api/app/Modules/Accounting/Observers/JournalEntryObserver.php:15-27`, the
  service locks trashed rows before its Draft guard at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:256-276`, and
  PostgreSQL/SQLite guards reject terminal `deleted_at` changes at
  `api/database/migrations/2026_08_25_100000_harden_journal_immutability.php:93-127,183-201`.
- F-002: `JournalEntryStateMachine::TRANSITIONS` and canonical transition
  writes live at
  `api/app/Modules/Accounting/Support/JournalEntryStateMachine.php:18-59`;
  database guards reject Draft→Reversed and require a posted, linked,
  non-archived reversal at
  `api/database/migrations/2026_08_25_100000_harden_journal_immutability.php:103-137,187-201,232-238`.
- F-003: post, system-post, reverse, update, and delete use the documented
  header→lines→accounts lock order and terminal validation helpers at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:175-239,256-276,300-377,442-624`.
  Empty, negative, dual-sided, and unbalanced line aggregates are rejected
  while the locks are held.
- F-004: explicit journal actors and reversal reasons flow through the scoped
  context at
  `api/app/Modules/Accounting/Support/JournalEntryAuditContext.php:18-61`,
  the journal models at
  `api/app/Modules/Accounting/Models/JournalEntry.php:82-87` and
  `JournalEntryLine.php:40-45`, and the shared audit hook at
  `api/app/Common/Traits/HasAuditLog.php:26-55`.

Verification:

- `php -l` passed for every changed PHP implementation, migration, and test
  file.
- In an isolated PostgreSQL database, `docker exec ... php artisan test
  --filter=JournalEntry` passed 21 tests and 2,922 assertions, including the
  new terminal, transition, aggregate, and actor/reason tests.
- The shared `ogami_test` run was not used as product evidence because other
  sessions were concurrently refreshing that database; the isolated run was
  clean.

## Deferred findings

- F-005 remains pending a human accounting decision: should a reversal of a
  prior-period Posted entry be allowed only in the current open period, only on
  or after the source date, or through an approved exception? No date rule was
  guessed. The choice must be encoded in the service, request/UI copy, audit
  reason, and tests before continuing to F-006.
- F-006 through F-010 were not started because the ordered plan reached this
  unresolved product rule. M026 requires a fresh audit after the remaining
  implementation work.
