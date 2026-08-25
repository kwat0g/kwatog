# M026 — Journal ledger audit report

- Audit date: 2026-08-25
- Domain/module: finance / journal-ledger
- Tier: 2
- Surface: M
- Dependencies: chart-of-accounts-periods, audit-activity
- Roles: system admin, finance officer
- Status: Plan Ready
- Source changes: the 2026-08-24 report was invalidated by current uncommitted changes in the journal service, models, requests, routes, migration, tests, and SPA. This report audits the current worktree; this session changed audit artifacts only.

## Scope and summary

The discovery pass covered the manual and automated journal-entry service paths,
period gating, posting-account resolution, maker/checker permissions, source
references, journal models and migrations, audit observers, API resources/routes,
and the journal list/create/edit/detail SPA pages for system administrators and
finance officers. The hardening pass traced every journal write through
`DB::transaction()`, terminal status changes, period/account locks, line
validation, raw-query database guards, and audit attribution. The polish pass
checked the role-gated workflows and the relevant rules in
`docs/DESIGN-SYSTEM.md`.

Several findings from the prior report are now addressed: reversal dates use the
period gate, update/delete re-read and lock the header, manual requests prohibit
source references, journal lines have audit observers, list resources eager-load
the reversal relation and hash reference IDs, and the SPA has archive/restore,
draft editing, reversal reasons, and exact cent totals. The remaining risks are
still concentrated at terminal aggregate boundaries and in acceptance coverage.

## Findings

### F-001 — Terminal journal entries can still be hidden by soft delete

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: `JournalEntry` uses `SoftDeletes` at
  `api/app/Modules/Accounting/Models/JournalEntry.php:18-20`. Migration 0444
  adds `deleted_at` to `journal_entries` at
  `api/database/migrations/0444_add_soft_deletes_to_all_tables.php:46-71`.
  The new PostgreSQL terminal-row trigger compares the business columns at
  `api/database/migrations/2026_08_25_100000_harden_journal_immutability.php:84-117`,
  but never compares `deleted_at`; its separate delete guard only covers a hard
  `DELETE` at `:128-130`. The SQLite equivalents have the same omission at
  `:153-197`. The service guard at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:224-244` only
  protects the normal delete endpoint.
- Impact: A model or raw update that sets `deleted_at` on a Posted or Reversed
  entry bypasses the terminal guard. Eloquent's default scope then removes the
  entry from journal lists and other `JournalEntry` queries, while
  `restore()` rejects non-draft rows at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:247-259`.
  A posted financial fact can therefore be hidden without a compensating entry
  or recovery path.
- Recommendation: Treat `deleted_at` as immutable for Posted/Reversed rows in
  both database implementations, add a service-level terminal archive guard,
  and test Eloquent soft-delete plus raw `deleted_at` updates on PostgreSQL and
  SQLite. Keep archived Draft recovery separate from terminal-row protection.

### F-002 — The journal lifecycle has no canonical transition map and the database permits an unlinked reversal

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: `JournalEntryStatus` defines values but no `TRANSITIONS` or state
  machine at `api/app/Modules/Accounting/Enums/JournalEntryStatus.php:7-25`.
  The service writes `Draft → Posted` directly in both manual and system paths
  at `api/app/Modules/Accounting/Services/JournalEntryService.php:308-314` and
  `:348-354`, and writes the reversal aggregate directly at `:459-472`.
  The Posted branch of the database trigger allows `NEW.status` to become
  `reversed` without requiring `NEW.reversed_by_entry_id` at
  `api/database/migrations/2026_08_25_100000_harden_journal_immutability.php:84-100`.
  The current test deliberately demonstrates that an entry can be forced into
  this inconsistent state with a direct status update at
  `api/tests/Feature/Accounting/JournalEntryServiceTest.php:140-157`.
- Impact: A direct model/query writer can mark a Posted entry Reversed with no
  compensating journal row, or with an unrelated linked row. The application
  then treats the source as terminal and will not reverse it, so the ledger can
  report a reversal that never posted. A value check is not a transition guard.
- Recommendation: Add a journal state machine with explicit transitions and
  route every terminal write through it. Enforce the required reversal link and
  target status/relationship at the database boundary where practical. Add
  negative tests for Posted→Reversed with null, unrelated, Draft, and valid
  reversal links.

### F-003 — Terminal posting paths do not lock or fully validate the line aggregate

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: Draft update/delete lock the header and then the line rows at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:162-217` and
  `:224-243`. By contrast, `post()` loads lines without
  `lockForUpdate()` and only recomputes debit/credit totals at `:285-294`;
  `postSystem()` follows the same pattern at `:335-345`, and `reverse()` reads
  the original lines without a line lock at `:423-431`. The minimum-two-line
  check exists only in create/update at `:111-118` and `:190-195`. The line
  trigger rejects mutations once the parent is already terminal at
  `api/database/migrations/2026_08_25_100000_harden_journal_immutability.php:43-68`,
  but it does not serialize draft-line mutation with the draft-to-post read.
- Impact: A concurrent raw or legacy draft-line mutation can be missed between
  the posting read and the terminal status update. Because `0.00 == 0.00`, an
  emptied draft can pass the current balance comparison and become an empty
  Posted entry; the reversal path likewise trusts the persisted line set. The
  existing stale update/delete test is sequential and does not cover this
  interleaving.
- Recommendation: Use one documented header→lines lock order for post,
  postSystem, reverse, update, and delete; revalidate line count, non-zero
  amounts, XOR debit/credit, account eligibility, and totals while holding the
  aggregate lock. Add PostgreSQL interleaving tests for draft-line mutation vs
  post/system-post/reverse.

### F-004 — Explicit automated posting actors are not propagated into audit-log attribution

- Classification: Incomplete
- Tags: [large] [separate-recommended]
- Evidence: `postSystem()` accepts an optional actor ID and stores it in
  `posted_by` at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:320-354`.
  `HasAuditLog` instead derives `user_id` and `actor_type` exclusively from
  `Auth::id()` at
  `api/app/Common/Traits/HasAuditLog.php:26-50,85-99`. Reversal-created headers
  and lines are written through ordinary Eloquent events at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:434-467`, with
  no explicit audit actor context.
- Impact: A command/listener can post on behalf of an actor while the journal
  header says `posted_by = actor` and the audit rows say system or the ambient
  authenticated user. The same mismatch applies to line-level audit events.
  The ledger has an audit row, but its actor evidence is not authoritative.
- Recommendation: Introduce a scoped audit actor/reason context used by the
  canonical journal service and model observer, or write explicit journal audit
  events with the supplied actor. Test authenticated, system, and
  actor-on-behalf-of-system posting and reversal paths.

### F-005 — Reversal-date policy is implicit rather than an explicit financial rule

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: The request accepts an optional `reverse_date` at
  `api/app/Modules/Accounting/Requests/ReverseJournalEntryRequest.php:16-21`.
  The service uses that date, or today's date, and only checks whether the
  target period is open at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:414-415`.
  There is no comparison with the source entry date and no test for an earlier,
  later, or default-date reversal; the current period test covers only a closed
  explicit target at
  `api/tests/Feature/Accounting/JournalEntryServiceTest.php:179-196`.
- Impact: The system currently permits a caller to choose any open date without
  making clear whether policy is “same/current open period”, “not earlier than
  the source”, or another accounting rule. A reversal can therefore be
  back-dated into a period different from the source under an undefined policy.
- Question requiring product/accounting direction: Should a prior-period Posted
  entry be reversed only in the current open period, only on/after the source
  date, or under an approved exception? Encode the chosen rule in the service,
  request/UI copy, audit reason, and tests before treating F-001 as complete.

### F-006 — The backend silently rounds over-precision amounts instead of enforcing the cent contract

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: Both journal requests accept any `numeric|min:0` debit/credit value
  at `api/app/Modules/Accounting/Requests/StoreJournalEntryRequest.php:25-29`
  and `api/app/Modules/Accounting/Requests/UpdateJournalEntryRequest.php:24-28`.
  `buildLines()` then silently rounds every value to two decimals at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:495-500`, while
  the persisted columns are `decimal(15,2)` at
  `api/database/migrations/0039_create_journal_entries_table.php:26-27`.
  The new SPA regex rejects more than two decimals at
  `spa/src/pages/accounting/journal-entries/create.tsx:26-38` and
  `edit.tsx:26-38`, so the direct API and UI contracts differ.
- Impact: An API caller sending `1.999` receives a journal line rounded to
  `2.00` rather than a field-level error; sufficiently large numeric input can
  instead reach a database precision failure. This is inconsistent with the
  repository's centavo/Money convention and makes client/server behavior depend
  on which entry point was used.
- Recommendation: Validate a bounded, non-negative two-decimal decimal string
  at the request/service boundary, reject rather than silently round user input,
  and add exact-cent, over-precision, and maximum-precision tests for manual and
  automated writers.

### F-007 — Source-reference display still exposes implementation names and raw IDs in human labels

- Classification: Polish
- Tags: [medium] [same-session-ok]
- Evidence: The resource hashes `reference_id` but returns the raw
  `reference_type` at
  `api/app/Modules/Accounting/Resources/JournalEntryResource.php:20-24`.
  `JournalEntry::referenceLabel()` interpolates the database ID into labels at
  `api/app/Modules/Accounting/Models/JournalEntry.php:80-95`. The allow-list
  includes fully-qualified model class names as public source types at
  `api/app/Modules/Accounting/Services/SourceReferenceRegistry.php:62-65`.
- Impact: A finance officer can see labels such as
  `App\Modules\Assets\Models\Asset #123` rather than a friendly document
  number, while a raw integer still appears inside `reference_label` even
  though the API field itself is hashed. The API also leaks internal class-name
  vocabulary that the SPA does not need for display.
- Recommendation: Resolve source references to a stable friendly type and
  document number/label, keep raw implementation type/IDs internal or hashed,
  and add resource snapshots for each allow-listed source family.

### F-008 — Archived detail pages expose a Print action whose route excludes trashed entries

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: The journal show and restore routes opt into trashed model binding at
  `api/app/Modules/Accounting/routes.php:45-53`, but the PDF route does not at
  `:54-56`. The detail page loads archived entries and identifies them at
  `spa/src/pages/accounting/journal-entries/detail.tsx:78-85`, yet renders Print
  unconditionally at `:97-100`.
- Impact: A finance officer can open an archived Draft from the new Archived
  filter, see Print, and receive a model-binding 404 because the PDF endpoint
  excludes the same trashed row.
- Recommendation: Either add an explicit `withTrashed()` PDF binding with the
  same permission and archive policy, or hide/disable Print for archived drafts
  and cover the chosen behavior in a route/UI test.

### F-009 — The new draft-edit page fails the repository lint gate

- Classification: Broken
- Tags: [small] [same-session-ok]
- Evidence: `spa/src/pages/accounting/journal-entries/edit.tsx:77-94` assigns
  `watch('lines') ?? []` and uses that freshly-created fallback in the
  `useMemo` dependency list. Targeted ESLint reports:
  `react-hooks/exhaustive-deps: The 'lines' logical expression could make the
  dependencies of useMemo change on every render` at `edit.tsx:79`.
- Impact: The module's focused lint command fails, so the new edit workflow
  cannot pass the configured zero-warning/zero-error quality gate. The unstable
  fallback also adds unnecessary recalculation while the form is initializing.
- Recommendation: Use a module-level empty-lines constant or compute the
  fallback inside the memo callback, then run the focused lint and edit-page
  component regression.

### F-010 — Acceptance coverage does not exercise the remaining terminal, role, and UI contracts

- Classification: Missing
- Tags: [large] [separate-recommended]
- Evidence: `JournalEntryServiceTest` covers sequential create/reverse,
  sequential stale update/delete, one raw posted-line update, one closed target
  period, archive/restore, null clearing, and manual source rejection at
  `api/tests/Feature/Accounting/JournalEntryServiceTest.php:46-301`.
  `JournalEntryPostRaceTest` covers only stale posting after a manually forced
  status at `api/tests/Feature/Accounting/JournalEntryPostRaceTest.php:52-76`.
  There are no journal-specific SPA tests, and no current test covers terminal
  soft-delete, invalid reversal links, draft-line/post interleavings,
  postSystem actor/audit propagation, the reversal-date policy, PDF behavior for
  archived rows, or the system_admin/finance_officer route/action matrix.
- Impact: The current tests prove important happy paths and some guards, but the
  defects most likely to hide or misstate financial facts can regress without a
  failing acceptance test. The repository's canonical-writer contract test only
  checks call shape, not these state and audit outcomes.
- Recommendation: Add a focused M026 acceptance suite covering both roles,
  route permissions, every status transition, terminal soft-delete and raw
  guards, line races, exact money input, audit actor/reason evidence, source
  serialization, archive/PDF behavior, and the edit/detail UI.

## Strengths and verified behavior

- Journal routes are behind `auth:sanctum` and `feature:accounting`, with
  distinct view/create/post/reverse permissions at
  `api/app/Modules/Accounting/routes.php:20-56`; the role seed defines the
  journal permissions and explicit self-post override at
  `api/database/seeders/RolePermissionSeeder.php:153-166`, and the SoD conflict
  is recorded at `api/database/seeders/SodConflictRuleSeeder.php:45-51`.
- Manual store/update requests prohibit source fields at
  `api/app/Modules/Accounting/Requests/StoreJournalEntryRequest.php:21-24` and
  `UpdateJournalEntryRequest.php:21-23`; automated writers are checked by the
  allow-list and canonical-writer contract.
- Create, update, delete, restore, post, system-post, and reverse use database
  transactions. Period gating now runs for create/update/post/system-post and
  reversal, and the period service serializes PostgreSQL period lifecycle work
  at `api/app/Modules/Accounting/Services/AccountingPeriodService.php:154-175,241-251`.
- `PostingAccountResolver` rejects missing/inactive accounts and rechecks them
  under locks at `api/app/Modules/Accounting/Services/PostingAccountResolver.php:22-33,79-123`.
- Journal headers and lines use `HasAuditLog`, and the line model now participates
  in audit events at
  `api/app/Modules/Accounting/Models/JournalEntryLine.php:13-28`; the database
  also has posted-line mutation triggers.
- The SPA gates create/edit/post/reverse/restore actions by permission, uses
  archive scopes, labels icon-only restore controls, uses design-system table
  primitives, and calculates form totals with `bigint` cents at
  `spa/src/pages/accounting/journal-entries/create.tsx:73-85` and
  `edit.tsx:81-94`.
- PHP syntax checks passed for the scoped changed PHP files, and
  `php artisan route:list --path=journal-entries` resolves 10 journal routes.

## Verification constraints

- The focused backend command for
  `JournalEntryServiceTest`, `JournalEntryMakerCheckerTest`, and
  `JournalEntryPostRaceTest` could not reach its configured PostgreSQL host
  `db` (`Temporary failure in name resolution`), so it completed with 15
  environment failures and 0 assertions. This is not treated as a product
  result.
- Targeted SPA ESLint ran and failed only on F-009. Full SPA typecheck also
  ran but currently fails in unrelated existing files:
  `src/components/hr/CreateAccountModal.tsx:33`,
  `src/pages/accounting/periods.tsx:180`, and
  `src/pages/assets/detail.tsx:6,67`. No typecheck error was reported from the
  journal files before those repository-wide errors.

## Session disposition

This is a fresh audit because the prior report's source snapshot changed. The
majority of the refreshed plan is financial-control, state-machine, database,
audit, or cross-path work. No production source fixes were applied in this
session; release M026 as `📋 Plan Ready` for a dedicated implementation pass.
