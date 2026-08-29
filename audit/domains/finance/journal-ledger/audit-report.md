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

---

# M026 — re-audit, 2026-08-30

- Audit date: 2026-08-30
- Claim: **RECLAIMED** (stale lock, 106h, originally 2026-08-25T12:50Z)
- Status released: `🔁 Needs Re-audit`
- Method: every finding below is measured against real PostgreSQL rows on an
  isolated database (`ogami_test_gl`). Nothing here is concluded from reading.

## What the crashed session had actually left behind

The orphan lock did **not** mean nothing happened. F-001 through F-004 were
implemented and are committed in `167de85e`; `git status` for
`api/app/Modules/Accounting` and `api/database/migrations` was clean at the start
of this session. `JournalEntryStateMachine`, `JournalEntryAuditContext`,
`JournalEntryObserver`, the header→lines→accounts lock order and
`2026_08_25_100000_harden_journal_immutability` are all present and — verified by
probe, not by reading the fix log — all working. F-005 through F-010 were
untouched. The dev-DB survey below and the executed-invariant table are the
evidence.

## Ledger invariants executed

Every row was run; none is inferred. Probes were removed after extraction and
their content folded into
`api/tests/Feature/Accounting/JournalLedgerInvariantTest.php`.

| # | Invariant | Measured result | Probe |
|---|---|---|---|
| 1 | Unbalanced entry cannot be created | **HOLDS** | `create()` with 10.00 debit / 9.00 credit → 422 `debits=10.00 credits=9.00 (difference=1.00)`; no row persisted |
| 1b | Unbalanced **via per-line rounding** | **HOLDS** | debits `1.005`+`1.005` (→2.02) vs credit `2.01` → refused; `0.005`×3 (→0.03) vs `0.02` → refused. `buildLines()` rounds each line *then* sums, so the comparison sees post-rounding totals |
| 1c | Money is BCMath strings end to end | **HOLDS in the service**; one float in the SPA (fixed) | no `(float)`/`round()` in `JournalEntryService`; `Money` is `bcadd/bcsub/bccomp`. `detail.tsx` used `Number(l.debit)` — fixed, see F-016 |
| 2 | Posted entry: update refused | **HOLDS** | Eloquent `update(['description'])` and raw `UPDATE total_debit` → `P0001 Posted journal entries are immutable.` |
| 2b | Posted entry: **date change** refused | **HOLDS** | Eloquent and raw `UPDATE date` both refused — this is the verb that would re-date a figure into another period |
| 2c | Posted entry: soft-delete refused | **HOLDS** | `delete()` → `BusinessRuleException` from `JournalEntryObserver::deleting`; raw `UPDATE deleted_at` → trigger |
| 2d | Posted entry: force-delete refused | **HOLDS** | `forceDelete()` → observer; raw `DELETE` → `journal_entries_delete_guard` |
| 2e | Posted entry: add/remove/alter a line refused | **HOLDS** | Eloquent `JournalEntryLine::create`, raw INSERT, raw DELETE and raw `UPDATE debit` all → `Posted journal entry lines are immutable.` |
| 2f | Out-of-band status flip refused | **HOLDS** | raw `posted→draft` and raw `posted→reversed` with no linked reversal both refused |
| 3 | Closed period refused on **every** write path | **HOLDS on all five** | create / update / post / postSystem / reverse each → `ClosedPeriodException` |
| 3b | Closed-period refusal is a 422, not a 500 | **HOLDS** | `POST /journal-entries/{id}/reverse` into a closed month → **422** with "Accounting period 2030-03 is closed … Reopen the period first". Notable: `JournalEntryController::reverse` does **not** catch `ClosedPeriodException`; the 422 comes from that exception's own `render()` arm |
| 4 | Maker cannot post their own entry (default) | **HOLDS for manual entries** | finance_officer self-post → 403 "segregation of duties"; draft stays `draft` |
| 4b | Override works for whoever holds it | **HOLDS** | only `system_admin` holds `accounting.journal.self_post_override` (measured from `role_permissions`); it self-posts successfully via the `hasPermission` wildcard |
| 4c | Maker-checker on **source-linked** entries | **INERT** — see F-015 | `created_by` is null, so `assertNotSelfPosting()` early-returns |
| 5 | Reversal is balanced and a true mirror | **HOLDS** | ₱250.00/₱250.00; every line's debit↔credit swapped, same `account_id` |
| 5b | Reversal linked both ways | **HOLDS** | `source.reversed_by_entry_id = reversal.id` and `reversal.reference_id = source.id`, `reference_type='journal_entry_reversal'` |
| 5c | Cannot reverse the same entry twice | **HOLDS** | second `reverse()` → "Only posted entries can be reversed."; exactly 1 reversal row |
| 5d | Reversal **dated legally** | **FAILS** — F-017 | a 2029-11-15 posted entry was reversed with date **2024-01-01**, accepted |
| 6 | Sequence uniqueness under concurrency (via the module) | **HOLDS** | 6 concurrent `create()` on one month → `JE-202608-0001…0006`, distinct=6, `last_number=6`, no gap |
| 6b | Sequence uniqueness in the shared service, unshielded | **FAILS** — S-001 | 8 barrier-aligned direct `generate()` calls with no pre-existing row → **4 OK / 4 `UniqueConstraintViolationException` (23505)**. No duplicate and no gap; the race becomes a 500 for half the callers |
| 6c | Two connections posting the same draft | **HOLDS** | one `OK posted_by=2`, one `Only draft entries can be posted.`; 2 lines intact |
| 6d | Two connections reversing the same entry | **HOLDS** | one OK, one refused; exactly 1 reversal row |
| 6e | A failed create burns no sequence number | **HOLDS** | after two 500-producing attempts, `last_number=2` and exactly 2 entries exist — `generate()` is a savepoint inside the create transaction |
| 7 | Header totals equal `SUM(lines)` | **FAILED via archive/restore** — F-011; now HOLDS | SQL reconciliation found `total_debit 100.00` vs `line_debit 0` after an archive/restore round trip |
| 7b | Soft-deleted entries excluded consistently across aggregates | **FAILS** — F-012 | same two rows, two answers: budget-actuals filter **₱111.00**, statements filter **₱999.00** |
| 8 | No raw integer id in payloads or URLs | **FAILED** — F-014; now HOLDS | `"reference_id":"7K3NM3w6Z0","reference_label":"Reversal of JE #21"` — adjacent keys, one hashed, one not |
| 8b | 404 body is not an existence oracle | **HOLDS** | `/journal-entries/9999999`, `/zzzznotahash` and `/1` all → bare 404, no `id`, no `reason` |

**Could not test:** nothing was skipped for lack of a harness. Two limits worth
naming: (a) SQLite branches of both immutability migrations were reviewed but not
executed, because the suite runs on PostgreSQL and the CI database is PostgreSQL —
the SQLite triggers are the untested half; (b) the dev database holds only 15
journal entries, so the aggregate reconciliation there is a weak sample and the
divergence in F-012 was demonstrated on constructed rows rather than found in
production data.

## Dev-database survey (real rows, `ogami`, read-only)

```
total_entries | soft_deleted | soft_deleted_posted | reversed | null_created_by | source_linked | srclinked_null_maker
           15 |            0 |                   0 |        0 |               9 |            12 |                    6
```
No header/lines mismatch and no entry with fewer than two lines exists today, so
F-011 and F-012 had not yet damaged live data. By reference type:
`stock_movement` 6 posted, **6/6 with null `created_by` and null `posted_by`**;
`(manual)` 3 posted, 3/3 null both (seeded opening entries); `invoice`,
`collection`, `credit_note` 6 posted, **0/6 null** — those rows predate
`167de85e`, which is when the `created_by = null` line landed. The static
call-site inventory below is therefore authoritative over this table.

## Findings

### Broken

- **F-011 — archiving a draft hard-deleted its lines; restore returned a phantom header.** FIXED.
  `JournalEntryService::delete()` called `$oldLine->delete()` on a model with no
  `SoftDeletes` trait (`api/app/Modules/Accounting/Models/JournalEntryLine.php:14-16`),
  so it was a hard `DELETE`; `restore()` (`:280-298`) restores only the header.
  Measured: `LINES=0` after restore, header still `total_debit=100.00`, SQL
  reconciliation mismatch, the list endpoint advertising `"total_debit":"100.00"`,
  and posting refused with "A journal entry must have at least two lines." The
  entry was recoverable only by re-entering every line by hand — the per-line
  accounts, amounts and memos were gone. Archive is presented to a finance officer
  as reversible (there is a Restore action and an Archived filter); it was not.
  The existing `test_archived_draft_can_be_restored_but_active_entry_cannot`
  (`api/tests/Feature/Accounting/JournalEntryServiceTest.php:354-375`) asserts
  `deleted_at === null` and `status === Draft` and stops — that is the blind spot
  that let this ship.

- **F-012 — an archived draft could be raw-promoted to Posted, producing a row hidden from the journal but counted by the statements.** FIXED (module half).
  The draft branch of `prevent_posted_journal_mutation()`
  (`api/database/migrations/2026_08_25_100000_harden_journal_immutability.php:133-137`)
  validated the status transition and not `deleted_at`. Measured: raw
  `UPDATE … SET deleted_at` on a draft accepted with lines intact, then raw
  `UPDATE … SET status='posted'` **also accepted**. Consequence, measured on the
  same two rows: `budget-actuals filter=111.00  statements filter=999.00` — an
  ₱888.00 divergence, and `TRIAL BALANCE totals {"debit":"999.00"}` against
  `journal LIST shows ["JE-202608-0002"]`.
  The second half is **not fixed and not mine**: `BudgetConsumptionService:283-284`
  filters `whereNull('je.deleted_at')`, while `TrialBalanceService:34`,
  `BalanceSheetService:42`, `IncomeStatementService:33`, `AccountService:82`,
  `FinanceDashboardService:110,121`, `PlantManagerDashboardService:102`,
  `ForecastingDashboardService:152`, `DashboardWidgetDataService:373`,
  `DashboardQueries:63` and `GrnGlPostingService:133,173` do not. The codebase
  answers "does a soft-deleted posted entry count?" both ways. With the trigger
  guard in place the state is now unreachable through any path found, so those
  missing filters are defence-in-depth rather than an active defect — but they are
  a divergence in a money aggregate and belong to M029/M025.

- **F-013 — the centavo contract was applied silently instead of enforced, and two well-formed inputs were 500s.** FIXED. (Was F-006, classified "Incomplete"; the 500s reclassify it.)
  From the one rule `['nullable','numeric','min:0']`:
  `POST debit='1.999'` → **201** with `"total_debit":"2.00"` (the operator was told
  it succeeded, carrying a figure they never entered);
  `POST debit='1e3'` → **HTTP 500** `ValueError: bccomp(): Argument #1 ($num1) is not well-formed`
  at `api/app/Common/Support/Money.php:95` — `is_numeric('1e3')` is true, so
  `numeric` handed it straight to BCMath;
  `POST debit='99999999999999999.00'` → **HTTP 500** `SQLSTATE[22003]` numeric
  field overflow, because `min:0` had no upper bound.

- **F-014 — `reference_label` leaked the raw database id the resource had just hashed.** FIXED. (Was F-007, "Polish".)
  `api/app/Modules/Accounting/Models/JournalEntry.php:90-103` interpolated
  `reference_id`. Measured in a live body:
  `"reference_id":"7K3NM3w6Z0","reference_label":"Reversal of JE #21"` — adjacent
  keys giving a client both halves of the mapping, so the HashID bought nothing
  for that record. The `default` arm also printed FQCNs, because two allow-listed
  types are literal class names (`SourceReferenceRegistry.php:63-64`), so a finance
  officer saw `App\Modules\Assets\Models\Asset #123`. Rendered at
  `spa/src/pages/accounting/journal-entries/index.tsx:60` and `detail.tsx:135`.

- **F-016 — the SPA parsed a money string into a JS float, and the repo lint gate was failing.** FIXED.
  `detail.tsx:160-161` used `Number(l.debit) > 0` on a `decimal(15,2)` string —
  the exact thing `CLAUDE.md` forbids. And `edit.tsx:79` assigned
  `watch('lines') ?? []` into a `useMemo` dependency list; verbatim, against
  unmodified HEAD:
  `79:8  error  The 'lines' logical expression could make the dependencies of useMemo Hook (at line 94) change on every render  react-hooks/exhaustive-deps`
  → `✖ 1 problem (1 error, 0 warnings)`. The module's own lint gate was red.
  (Was F-009.) `create.tsx:71` is the already-correct sibling — the two files had
  diverged.

- **F-017 — a reversal can be dated years BEFORE the entry it reverses.** DEFERRED (policy).
  Measured: a posted entry dated `2029-11-15` was reversed with
  `reverse_date = 2024-01-01` and **accepted**, because
  `AccountingPeriodService::assertPostingAllowed()` (`:189-211`) only refuses when
  an `accounting_periods` row exists *and* is closed — any month with no row is
  allowed. This is materially worse than F-005 described: not "a different open
  period" but any date in any year, including before the source existed. A 2024
  trial balance then contains a credit with no matching debit while the 2029 one
  contains a debit whose reversal is five years earlier.
  **Why it is still deferred:** the exact rule (same period / current open period /
  not-before-source / approved exception) is an accounting decision, and I will not
  pick one. But note that "the reversal predates its source" is not a candidate
  under *any* of them, so the intersection guard `reverse_date >= source.date` is
  available as a minimal step that prejudges nothing. See the action plan.

### Missing

- **F-018 — no test asserted that archive is reversible.** FIXED by
  `JournalLedgerInvariantTest::test_archiving_and_restoring_a_draft_preserves_its_line_aggregate`,
  which compares the full line array before and after and then re-posts the
  restored draft.
- **F-010 (unchanged) — no acceptance matrix.** The new invariant suite covers 8
  invariant families across 15 tests / 101 assertions, but not the full
  system_admin × finance_officer route matrix, the automated-writer idempotency
  paths, or any SPA test: `spa/src/pages/accounting/journal-entries/` contains
  **zero** test files.
- **F-019 — the reverse dialog has no Zod schema.** `detail.tsx:34,228` holds
  `reverseReason` in bare `useState` and enforces it only with
  `disabled={!reverseReason.trim()}`, against a backend rule of
  `required|string|min:1|max:500` (`ReverseJournalEntryRequest.php:20`). There is
  no `applyServerValidationErrors`, so a server rejection lands in a generic toast
  (`detail.tsx:57`). `reverse_date` is typed in the client
  (`spa/src/api/accounting/journal-entries.ts:30`) with no UI field at all.

### Incomplete

- **F-015 — `created_by = null` on every source-linked entry also disables maker-checker.** DEFERRED — shared decision #12, see the dedicated section below.
- **F-020 — `restore()` does not apply the period gate that `create()` does.**
  Measured: an archived draft dated in a now-closed period restored successfully.
  Low impact (a draft has no GL effect and `post()` re-checks), but the module's
  own policy is "a draft may not be dated into a closed period" and `restore()` is
  the one path that does not say so. **Deliberately not fixed:** gating it would
  make such a draft permanently unrestorable, where today an operator can restore
  and re-date it. That is a UX/policy trade-off, not a bug with one right answer.
- **F-021 — `journal_entry_lines.deleted_at` exists but nothing ever writes it.**
  The column arrived with `0444_add_soft_deletes_to_all_tables` and
  `JournalEntryLine` has no `SoftDeletes` trait, so
  `BudgetConsumptionService:284`'s `whereNull('jel.deleted_at')` filters a column
  that is always null — a decorative guard. Either the model should soft-delete
  (which would then require a `jel.deleted_at` filter in ten aggregates that lack
  one) or the column and the filter should go. F-011 was fixed without needing
  either, so this is now a tidiness question, not a correctness one.
- **F-022 — `reversal_reason` is null on the reversed source row.**
  Measured: `source after reversal: {"status":"reversed","reversed_by_entry_id":13,"reversal_reason":null}`.
  The reason lives only on the reversal. Note this is not an oversight that can be
  patched in the service: the posted branch of the trigger lists
  `NEW.reversal_reason IS DISTINCT FROM OLD.reversal_reason` as immutable, so the
  posted→reversed transition *cannot* write it. The field on a source row is dead
  by design. The fix is serialization — expose `reversedBy.reversal_reason` — not a
  new write.
- **F-023 — the list page retains stale data with no indicator.** `index.tsx:43`
  sets `placeholderData: (prev) => prev`, but `isPlaceholderData`/`isFetching` are
  destructured nowhere, so state 5 of the mandated five is data-retention without
  the visual cue.

### Polish

- **F-024 — `ListEmptyState` was called with no `searchTerm`.** FIXED
  (`index.tsx:121`). A search returning zero rows rendered the first-run
  "create your first entry" copy — the exact failure that component's own doc
  comment warns about (`spa/src/components/ui/ListEmptyState.tsx:8-12`).
- **F-025 — Print was offered on archived entries and 404s.** FIXED. The PDF route
  (`api/app/Modules/Accounting/routes.php:56`) has no `->withTrashed()` while show
  (`:45-47`) and restore (`:51-53`) do, and `detail.tsx:99` rendered Print with no
  conditional while every sibling action gates on `!isArchived`. (Was F-008.)
- **F-026 — `toCents` is asymmetric and can throw.**
  `spa/src/pages/accounting/journal-entries/money.ts:8` applies the sign to the
  whole part only (`toCents('-4.50')` → `-350n`, not `-450n`) while `fromCents`
  handles sign correctly; and `BigInt(whole)` throws on non-numeric input, which
  a paste can reach (`spa/src/lib/numberInput.ts:41` passes Ctrl/Cmd combos
  through) — inside a `useMemo` render body, so an uncaught render crash rather
  than a validation message. Both are currently masked by the `^\d+(\.\d{1,2})?$`
  Zod regex and `negative: false`. Latent, not live.
- **F-027 — the line-memo input has no `maxLength`.** `create.tsx:143`,
  `edit.tsx:155` against a backend `max:200`, where the header Textarea does set
  `maxLength={500}`.
- **F-028 — `SourceReferenceRegistry::assertValid()` interpolates a raw id into its
  message** (`:90`, `"Source reference '{$type}#{$id}' cannot be resolved."`). Not
  reachable from the manual API (both fields are `prohibited`), so it can only
  surface to an internal writer that already knows the id. Cosmetic.

### Question — reversing a reversal

A reversal is `posted` with a null `reversed_by_entry_id`, so `reverse()` accepts
it. Measured: reversing a reversal succeeded, and reversing it a second time was
refused. The GL arithmetic is right either way (the re-reversal restores the
original amounts, and whether statements sum `posted` only or `posted+reversed`,
the net is the original). What is wrong is the **label**: the original entry still
reads `status = reversed`, i.e. "cancelled", after it has been effectively
reinstated, and nothing on the screen says so. Is chained reversal intended as the
reinstatement mechanism, and if so should the original's status or a badge reflect
it? Not guessed at.

## The suite's deliberate 500s — concluded, not changed

`api/tests/Feature/Common/BusinessRuleRenderingTest.php:231-257` asserts that an
`UnbalancedJournalEntryException` and a `LedgerImbalanceException` are **still
500s** "where nothing names it". **I conclude this is a considered choice, and I
did not change it.** The evidence is behavioural, not stylistic:

1. The reachable operator-facing paths are already 422. Measured:
   `POST /journal-entries` with 10.00 vs 9.00 → **422** with the message keyed to
   `errors.lines`. `JournalEntryController` names the exception in `store`,
   `update` and `post` — that is the whole HTTP surface a human can reach.
2. What the 500 covers is the *other* caller. `UnbalancedJournalEntryException`'s
   own docblock (`:14-31`) lists nine internal posters that build lines from our
   own account mapping; an imbalance there is a programmer bug, and the honest
   answer is a stack trace.
3. Reparenting it to `BusinessRuleException` would hand it to the ~20
   `catch (BusinessRuleException)` arms that degrade a failed GL handoff to
   `manual_required`. A poster computing unbalanced lines would then be marked
   "needs manual attention" forever instead of failing on the first attempt. That
   is a strictly worse outcome for a money defect.
4. The contrast with `ClosedPeriodException` is instructive and deliberate: that
   one *did* get a `render()` arm (`:63-77`), because a closed period is an
   operator condition whose own message names the period to reopen, and 21
   controllers already answered 422. An imbalance is not an operator condition.

So: **considered choice, not an unclosed gap.** The one thing I would flag for the
shared surface is that the test's name ("where nothing names it") carries this
reasoning only by implication; the two docblocks carry it properly.

## Shared-service finding (reported, deliberately not fixed in `Common`)

- **S-001 — `DocumentSequenceService::generate()` insert-then-reselect races into a hard failure.**
  `api/app/Common/Services/DocumentSequenceService.php:64-85`: when no sequence row
  exists for `(type, year, month)`, `->lockForUpdate()->first()` locks nothing, so
  every concurrent caller proceeds to `INSERT`.
  Measured with 8 barrier-aligned connections and the row deleted first:
  **4 OK / 4 `UniqueConstraintViolationException` (23505)** on
  `document_sequences_document_type_year_month_unique`, `last_number=4`,
  `distinct=4`. So the unique index *prevents* corruption — no duplicate number and
  no gap — but converts the race into a 500 for half the callers.
  **It cannot bite journal entries.** `create()` calls
  `assertPostingAllowed()` first, which takes
  `pg_advisory_xact_lock(hashtext('accounting-period:YYYY-MM'))`
  (`AccountingPeriodService.php:197`), serialising every JE create for a month
  before the sequence is touched — 6 concurrent `create()` calls gave 6 distinct
  sequential numbers. The defect is therefore latent for M026 and live for any
  document type whose writer takes no month lock, i.e. the first document of each
  month for those types. `journal_entries.entry_number` is additionally `unique`
  (`0039_create_journal_entries_table.php:21`), so a duplicate could never persist
  here even if the guard were removed. Owner: shared `Common`, not this module.

## Shared Accounting decision #12 — `created_by` on source-linked entries

`api/app/Modules/Accounting/Services/JournalEntryService.php:139`:
`'created_by' => empty($data['reference_type']) ? $user?->id : null`.
I own this file, so here is the precise characterisation. **No option is
implemented and no attribution semantics were changed.**

**Which reference types are affected — 11:** `payroll_period`, `stock_movement`,
`goods_receipt_note`, `asset_depreciation`, `App\Modules\Assets\Models\Asset`
(disposal), `invoice`, `collection`, `bill_payment`, `bill`, `credit_note`,
`App\Modules\HR\Models\Clearance` (final pay). `journal_entry_reversal` is the only
JE-bearing type that keeps its maker, because `reverse()` bypasses `create()` and
writes `'created_by' => $by->id` directly (`:484`).

**9 of the 11 call sites pass a real `$user` and have it discarded.**
`DepreciationService:103-110`, `AssetService:253-260`, `InvoiceService:280-287` and
`:422-432`, `BillService:613-623` and `:997-1004`, `CreditNoteService:185-192`,
`FinalPayService:259-266`, `PayrollGlPostingService:317-324`. Two never had an
actor to pass at all: `MovementGlPostingService:179-185` (`stock_movement` — the
model *has* `created_by`, it is simply never read) and
`GrnGlPostingService:209-221` (`goods_receipt_note` — `GrnService` holds `$by` but
`GrnGlPostingService::post()` takes no actor).

**What `posted_by` records versus `created_by`.** Rows 4-11 call `post($je, $by)`
with the *same* `$by`, so `posted_by` is populated and correct. The two
actor-less writers call `postSystem($je)` with no id, so `posted_by` is null too —
those entries have **no actor anywhere in the row**. `payroll_period` passes
`postSystem($je, $actorId)` from `finalized_by`, so it is attributed when that is
set.

**A second consequence the earlier reports did not state:** because `created_by`
is null, `assertNotSelfPosting()` returns at `:400-402` and the maker-checker
control is **silently inert on every source-linked entry**. Rows 4-11 are literal
self-posts by one user, of exactly the kind the guard was written to block.

**Can the actor be reconstructed?** For 9 of 11, **yes**. `create()` wraps the
whole transaction in `JournalEntryAuditContext::run((int) $user->id, 'user', …)`
(`:151-156`), and `HasAuditLog:29-34` prefers that context over `Auth::id()`, so
the `audit_logs` row for the JE `created` event carries the real `user_id` even
though the persisted snapshot shows `created_by => null`. Those rows are immutable
(`2026_06_09_100001_add_audit_log_immutability_trigger.php:12-27`) and pruning
archives rather than deletes (`PruneAuditLogs.php:23-28`). For `stock_movement` and
`goods_receipt_note`, **no** — attribution falls back to `Auth::id()`, which is
`[null,'system']` on the queued `PostStockMovementToGlOnRequested` path. Two
caveats on durability: `audit_logs.user_id` is `nullOnDelete()`
(`0008_create_audit_logs_table.php:15`), so a hard user delete severs the last
link; and `actor_type` was added later and is nullable, so older rows have no
actor class.

**Measured blast radius.** `RunMonthlyDepreciation --backfill` runs one JE per
month (`DepreciationService:149,172-176`), so a 36-month backfill produced **0 of
36** makers — already recorded at
`audit/domains/finance/fixed-assets-depreciation/fix-log.md:383-397`. Visible
consequences: a blank "Prepared by" line on every source-linked JE PDF
(`api/resources/views/pdf/journal-entry.blade.php:52`) and an em-dash in the
detail Audit panel (`detail.tsx:178`). No report, export or list column reads
`created_by`, so nothing else degrades. `PayrollMoneyFindingsRegressionTest:208`
(`assertNotNull($entry->created_by)`) is red today and flagged as intentionally so.

**A backfill is blocked at the database.** The immutability trigger lists
`NEW.created_by IS DISTINCT FROM OLD.created_by` for posted and reversed rows
(`2026_08_25_100000:95-96,128-129,185-186`), so historical rows cannot be repaired
without first relaxing the guard. Any fix is therefore forward-only.

### Options, with costs

- **Option A — keep the semantics, document them.** `created_by` means "a human
  filled in the JE form". Cost: zero code. Leaves maker-checker inert on
  source-linked entries and `PayrollMoneyFindingsRegressionTest` red; the audit
  trail still answers "who" for 9 of 11 via `audit_logs`, so IATF traceability is
  satisfiable but only by joining a second table.
- **Option B — key on the posting path, not on `reference_type`.** Record the real
  `$user` in `created_by` whenever one was supplied, and add an explicit
  `posted_via_system` (or reuse `postSystem` as the signal) so the maker-checker
  guard can distinguish "unattended" from "attributed". Cost: one line in
  `create()` plus a guard change; **turns maker-checker on for 8 writers that
  currently self-post**, which will start refusing postings that succeed today
  unless the automated paths are exempted deliberately. That behaviour change,
  not the attribution, is the real cost — it needs a decision about which
  automated posters are allowed to self-post.
- **Option C — add a separate `initiated_by` column** and leave `created_by`
  meaning exactly what it means now. Cost: migration + resource + PDF + SPA;
  no behaviour change anywhere, maker-checker stays inert, the PDF signature line
  gets filled. The safest option and the least useful as a control.
- **Option D — fix only the two actor-less writers** (thread `$by` from
  `GrnService` into `GrnGlPostingService::post()`, and read
  `StockMovement::created_by` in `MovementGlPostingService`). Cost: two small
  cross-module changes, no semantic change to `created_by`. Closes the only case
  where the actor is unrecoverable *from anywhere*, and is orthogonal to A/B/C.

**Recommendation shape, not a decision:** D is separable and strictly additive, so
it can proceed regardless. The A-versus-B-versus-C choice hinges on one question a
human must answer: **should an unattended automated posting be subject to
maker-checker at all?** If yes, B; if no, C (or A). Whoever decides should also
decide whether the historical rows matter enough to justify relaxing the
immutability trigger for a one-off backfill.
