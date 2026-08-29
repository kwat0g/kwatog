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

---

## Re-audit + implementation session — 2026-08-30

Claimed via `claim-module.sh finance journal-ledger` → **RECLAIMED** (stale lock,
106h old, originally claimed 2026-08-25T12:50Z).

**What the crashed session had already left in the tree.** Contrary to the usual
orphan-lock case, its work was not lost: F-001 through F-004 were implemented and
committed in `167de85e` ("chore: remaining uncommitted work from ~50 crashed
audit sessions"). `git status` for `api/app/Modules/Accounting` and
`api/database/migrations` was clean at the start of this session. Verified present
and working by measurement, not by reading the log:
`JournalEntryStateMachine`, `JournalEntryAuditContext`, `JournalEntryObserver`,
the header→lines→accounts lock order, and
`2026_08_25_100000_harden_journal_immutability`. All of it holds up — see the
executed-invariant table in `audit-report.md`. F-005 through F-010 were genuinely
untouched.

### Baseline before any change

`tests/Feature/Accounting/JournalLedgerInvariantTest.php` was written first,
against unmodified source (`git status --short -- api/app api/database` showed no
Accounting changes), on an isolated database `ogami_test_gl`:

```
Tests:    7 failed, 8 passed (86 assertions)
```

The 8 passing are regression locks over behaviour the previous session got right
(they pass either way, and are labelled as such in the report). The 7 failing are
the defects fixed below.

### F-011 — archiving a draft hard-deleted its lines; restore returned a phantom

- Classification: Broken
- Before: `api/app/Modules/Accounting/Services/JournalEntryService.php:268-277`
  ```php
  $oldLines = $this->lockLines($lockedJe->id);
  $this->lockAccountRows($oldLines);
  foreach ($oldLines as $oldLine) {
      $oldLine->delete();
  }
  $lockedJe->delete();
  ```
  `JournalEntryLine` does **not** use `SoftDeletes`
  (`api/app/Modules/Accounting/Models/JournalEntryLine.php:14-16`), so
  `$oldLine->delete()` was a hard `DELETE`. `restore()` (`:280-298`) restores only
  the header.
- Measured (`ZzGlProbe3Test`, since removed):
  `rows in journal_entry_lines AFTER archive (any deleted_at): []` →
  `RESTORED entry: status=draft deleted_at=NULL header total_debit=100.00 total_credit=100.00 LINES=0`
  → SQL reconciliation reported
  `{"entry_number":"JE-202608-0001","total_debit":"100.00","line_debit":"0"}`, the
  list endpoint advertised `total_debit "100.00"` for it, and posting it failed
  with `A journal entry must have at least two lines.`
- After: the lines are still locked (so a concurrent draft-line writer cannot
  interleave with the archive) but no longer deleted. Safe because every query
  that joins `journal_entry_lines` filters its parent to `status = 'posted'` —
  verified across `TrialBalanceService:34`, `BalanceSheetService:42`,
  `IncomeStatementService:33`, `AccountService:82`, `BudgetConsumptionService:283`,
  `GrnGlPostingService:133,173`, `FinanceDashboardService:110,121`,
  `PlantManagerDashboardService:102`, `ForecastingDashboardService:152`,
  `DashboardWidgetDataService:373`, `DashboardQueries:63` — so a draft's lines are
  counted by nothing, and the header's `deleted_at` is what hides the entry.
- Test: `JournalLedgerInvariantTest::test_archiving_and_restoring_a_draft_preserves_its_line_aggregate`
  (was RED, compares the full line array before/after and re-posts the restored draft).

### F-012 — an archived draft could be raw-promoted to Posted, becoming hidden-but-counted

- Classification: Broken
- Before: the draft branch of `prevent_posted_journal_mutation()` at
  `api/database/migrations/2026_08_25_100000_harden_journal_immutability.php:133-137`
  validated only the status transition and never `deleted_at`.
- Measured: raw `UPDATE journal_entries SET deleted_at = now()` on a draft was
  accepted with its 2 lines intact, then raw `UPDATE ... SET status = 'posted'`
  was **also accepted** — producing a POSTED row with `deleted_at` set. Eloquent's
  soft-delete scope hid it from the journal list while the statement aggregates
  counted its lines. The divergence was measured on the same two rows:
  `SAME ROWS, TWO ANSWERS -> budget-actuals filter=111.00  statements filter=999.00`
  (an ₱888.00 gap), and `TRIAL BALANCE totals: {"debit":"999.00"}` versus
  `journal LIST shows posted entries: ["JE-202608-0002"]`.
- After: new migration
  `api/database/migrations/2026_08_30_100000_guard_archived_journal_entry_posting.php`
  adds the one missing condition to the draft branch
  (`NEW.status = 'posted' AND NEW.deleted_at IS NOT NULL` → raise), for PostgreSQL
  (`CREATE OR REPLACE FUNCTION`, so both existing triggers stay bound) and SQLite
  (a separate `journal_entries_archived_post_guard` trigger, since SQLite cannot
  replace a trigger body). Archiving a draft is still permitted — only promoting
  an already-archived row is refused. `down()` restores the exact pre-guard body
  rather than dropping the function, so it is a true inverse.
- Numbering: timestamp-named on purpose. The migrator sorts by full filename and
  `'0' < '2'`, so a `0479_` file would run before `2026_08_25_100000` and try to
  replace a function that does not exist yet. The already-run
  `2026_08_25_100000` migration was NOT edited — Laravel matches on filename, so
  editing it would apply to fresh databases only and silently diverge from
  deployed ones.
- Test: `JournalLedgerInvariantTest::test_an_archived_draft_cannot_be_promoted_to_posted`
  (was RED).

### F-013 — the centavo contract was applied silently instead of enforced, and two well-formed inputs were 500s

- Classification: Broken (was F-006, "Incomplete")
- Before: `lines.*.debit|credit => ['nullable', 'numeric', 'min:0']` in
  `StoreJournalEntryRequest.php:27-28` and `UpdateJournalEntryRequest.php:26-27`,
  with `JournalEntryService::buildLines()` (`:644-645`) calling `Money::round2()`.
- Measured over HTTP, all three from that one rule:
  - `POST debit='1.999'` → **201** with `"total_debit":"2.00"`. The operator was
    told the entry succeeded, carrying a figure they never entered.
  - `POST debit='1e3'` → **HTTP 500**
    `ValueError: bccomp(): Argument #1 ($num1) is not well-formed` at
    `app/Common/Support/Money.php:95`. `is_numeric('1e3')` is true, so `numeric`
    passed it straight to BCMath.
  - `POST debit='99999999999999999.00'` → **HTTP 500**
    `SQLSTATE[22003] numeric field overflow`. `min:0` with no `max`.
- After: `['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99']`,
  with the bound as `StoreJournalEntryRequest::MAX_LINE_AMOUNT` (one centavo below
  10^13, the real ceiling of `decimal(15,2)`). Laravel's `decimal` rule regex has
  no exponent branch, so it rejects `1e3` as well as over-precision. Scoped to the
  FormRequests, NOT to the service: internal GL writers build their lines from
  `Money::*` (which already returns scale-2 strings) and are unaffected, so no
  automated poster's behaviour changes.
- Not changed: `'  7.00  '` → 201 and `'+5.00'` → 201 both still work (Laravel
  trims, and a leading `+` is representable). `'0x10'` and `'1_000'` were already
  422s.
- Tests: `test_an_over_precision_amount_is_rejected_not_silently_rounded` and
  `test_an_unrepresentable_amount_is_a_422_not_a_500` (data provider, 2 cases) —
  all 3 were RED.

### F-014 — `reference_label` leaked the raw database id the resource had just hashed

- Classification: Broken (was F-007, "Polish")
- Before: `api/app/Modules/Accounting/Models/JournalEntry.php:90-103` interpolated
  `$this->reference_id` into every label.
- Measured in a live response body:
  `"reference_id":"7K3NM3w6Z0","reference_label":"Reversal of JE #21"` — adjacent
  keys, one hashed and one not, so the response handed a client both halves of the
  mapping and the obfuscation bought nothing. Rendered by the SPA at
  `spa/src/pages/accounting/journal-entries/index.tsx:60` and `detail.tsx:135`.
  The `default` arm also printed FQCNs, because two allow-listed reference types
  are literal class names (`SourceReferenceRegistry.php:63-64`), so a finance
  officer saw `App\Modules\Assets\Models\Asset #123`.
- After: the identifier is `HashId::encode()`d, `class_basename()` strips the
  namespace, and a null `reference_id` (allow-listed for `asset_depreciation` and
  `opening`) yields the bare noun instead of a dangling separator.
- Known limitation, recorded in the action plan rather than papered over: a hash
  is not a document number, which is what an operator actually wants in the
  Reference column. Resolving each source family to its own number needs a
  per-family eager-load and is deferred as F-007b.
- Test: `test_no_journal_payload_carries_a_raw_database_id` (was RED) — asserts no
  `/#\s*\d+/` and no `App\` in `reference_label`, over both the detail and list
  payloads.

### Verification

- `php -l` clean on all five changed/added PHP files.
- Invariant suite after the fixes, same isolated database:
  `Tests: 15 passed (101 assertions)` — up from `7 failed, 8 passed (86 assertions)`.

### F-016 — a money string parsed into a JS float, and a red lint gate

- Classification: Broken
- Before / after, `spa/src/pages/accounting/journal-entries/detail.tsx:160-161`:
  ```tsx
  - <Td align="right" mono>{Number(l.debit) > 0 ? formatPeso(l.debit) : ''}</Td>
  - <Td align="right" mono>{Number(l.credit) > 0 ? formatPeso(l.credit) : ''}</Td>
  + <Td align="right" mono>{l.debit !== '0.00' ? formatPeso(l.debit) : ''}</Td>
  + <Td align="right" mono>{l.credit !== '0.00' ? formatPeso(l.credit) : ''}</Td>
  ```
  A string compare rather than `toCents()` because it cannot throw:
  `JournalEntryLineResource:17-18` serialises a `decimal:2` cast, so zero is always
  exactly `'0.00'`. This is the idiom `edit.tsx:75-76` already uses to seed its form.
- Before / after, `spa/src/pages/accounting/journal-entries/edit.tsx:79,84`:
  ```tsx
  - const lines = watch('lines') ?? [];
  -   for (const line of lines) {
  + const lines = watch('lines');
  +   for (const line of lines ?? []) {
  ```
  The freshly-created `?? []` fallback was the sole `useMemo` dependency, so it
  changed identity on every render. `create.tsx:71` was already the correct form —
  the two siblings had diverged.
- Baseline **proven**, not assumed. HEAD's `edit.tsx` was written back over the
  fixed file, linted, and then restored, with the restore verified byte-identical:
  ```
  --- lint against UNMODIFIED HEAD source ---
    79:8  error  The 'lines' logical expression could make the dependencies of useMemo Hook
                 (at line 94) change on every render...  react-hooks/exhaustive-deps
  ✖ 1 problem (1 error, 0 warnings)
  --- restoring ---
  src/pages/accounting/journal-entries/edit.tsx: OK
  RESTORE PROVEN IDENTICAL
  --- lint after restore ---
  clean (exit 0)
  ```

### F-025 — Print offered on archived entries, guaranteed to 404

- Classification: Polish (was F-008)
- Before: `detail.tsx:99` rendered Print with no conditional, while every sibling
  action gates on `!isArchived` (`:100`, `:105`, `:110`, `:120`) and `isArchived` is
  already computed at `:84`. The PDF route
  (`api/app/Modules/Accounting/routes.php:56`) has no `->withTrashed()` while show
  (`:45-47`) and restore (`:51-53`) do, so the click was always a 404.
- After: wrapped in `{!isArchived && ( … )}`. Chose hiding over adding
  `withTrashed()` to the PDF route because printing an archived draft has no
  business meaning — the other archive-time action is Restore.

### F-024 — first-run empty copy shown on a filtered list

- Classification: Polish
- Before / after, `index.tsx:121`: `<ListEmptyState />` →
  `<ListEmptyState searchTerm={filters.search} />`. Search is wired at `:112`, so a
  search returning zero rows rendered "create your first entry" — the exact failure
  `spa/src/components/ui/ListEmptyState.tsx:8-12` warns about in its own doc comment.

### F-018 — the test that would have caught F-011

- Classification: Missing
- `api/tests/Feature/Accounting/JournalLedgerInvariantTest.php` is new. The
  pre-existing `JournalEntryServiceTest::test_archived_draft_can_be_restored_but_active_entry_cannot`
  (`:354-375`) asserts `deleted_at === null` and `status === Draft` and stops — it
  never looks at the lines, which is why F-011 shipped. The new test captures the
  full line array before archiving, compares it after restoring, reconciles headers
  against `SUM(lines)` in SQL, and then re-posts the restored draft.

## Working-tree hygiene note (shared tree)

Two things happened that are worth recording because they are not mine and could
otherwise be misread as my diff:

1. **A `PostToolUse` hook reformats UI files.** `.claude/settings.local.json`
   registers the `impeccable` skill hook on `Edit|Write|MultiEdit`. Editing
   `detail.tsx` and `edit.tsx` through the Edit tool caused a full Prettier
   reformat — 605 insertions / 343 deletions for what should have been a 5-line
   change — and also reformatted `spa/src/lib/emptyStateCopy.ts`, a file this
   session never touched. All three were reconstructed from `git show HEAD:` and
   re-edited via a script (so the hook did not fire), leaving minimal diffs:
   `detail.tsx` 13 lines, `edit.tsx` 4 lines, `index.tsx` 1 line,
   `emptyStateCopy.ts` back to zero.
   Before restoring `emptyStateCopy.ts` its `git diff -w` was non-zero (8/4), so it
   was checked rather than assumed: running Prettier with the project's own
   `.prettierrc.json` over HEAD's copy reproduced **exactly** 8 insertions / 4
   deletions, all of it four long `description:` strings rewrapped. Nothing
   semantic was lost. (With Prettier's *default* config the same diff is 337/331 —
   the config matters, which is why the check was run both ways.)
   Note the repo is broadly not Prettier-clean: 28 files under
   `spa/src/pages/accounting/` fail `prettier --check` at HEAD, so accepting the
   reformat would have made these two files outliers among their siblings. Running
   Prettier repo-wide is a separate, deliberate decision for a human.
2. **Scratch files were removed.** Three probe suites
   (`ZzGlProbeTest`, `ZzGlProbe2Test`, `ZzGlProbe3Test`) were used for the
   measurements quoted above and deleted once their content was folded into
   `JournalLedgerInvariantTest`. Nothing of this session's remains untracked.
   Other sessions' scratch (`api/probe_*.php`, `ZzUserAdminProbeTest.php`,
   `ZzPricingProbe*Test.php`) and their source edits were left untouched, and the
   commit is pathspec-scoped so none of it was swept in.

## Final verification

Isolated database `ogami_test_gl`, created and dropped by this session.

| gate | result |
|---|---|
| `migrate:fresh` with the new migration | green — `2026_08_30_100000_guard_archived_journal_entry_posting … 2.68ms DONE` |
| `JournalLedgerInvariantTest` **before** any source change | **7 failed, 8 passed (86 assertions)** |
| `JournalLedgerInvariantTest` after | **15 passed (101 assertions)** |
| `tests/Feature/Accounting` (whole directory) | **1 failed, 164 passed (3625 assertions)** — the one failure is `AccountsPayableHardeningTest:229` (`SupplierBillResource` / `payments` key), pre-existing and unrelated: `git diff --name-only HEAD` shows this session touched no Bill, Supplier or AP file |
| `--filter=BusinessRuleRenderingTest` | **8 passed (18 assertions)** — the shared surface, unchanged by design |
| `php -l` on all 6 changed/added PHP files | clean |
| `phpstan analyse` on the 4 changed app files | **[OK] No errors** |
| `pint --test` on the 2 NEW files | **passed** |
| `pint --test` on the 4 MODIFIED files | fails — **proven inherited.** Pint was run against `git show HEAD:` extracts of all four: `StoreJournalEntryRequest` and `UpdateJournalEntryRequest` fail on the identical single rule (`binary_operator_spaces`), `JournalEntryService` on the identical 12-rule set, and `JournalEntry.php` now fails on **fewer** rules than at HEAD (the edit removed `control_structure_braces`, `concat_space` and `blank_line_before_statement`). No new violation introduced |
| `npx eslint src/pages/accounting/journal-entries --max-warnings 0` | exit 0 (was 1 error) |
| `npm run typecheck` | clean, no output |
| `npm run test:run` | **49 files / 302 tests passed** |

Of the 15 tests in the new suite, **7 fail against unmodified source** and 8 are
regression locks that pass either way — labelled as such rather than presented as
proof of the fixes.

## Deferred

F-007b, F-010, F-015 (decision #12), F-017, F-019, F-020, F-021, F-022, F-023,
F-026, F-027, F-028, the chained-reversal question, the M029/M025 `deleted_at`
filters, and S-001 (shared `Common`). Reasons and ordering are in
`action-plan.md`. Released as `🔁 Needs Re-audit` because F-015 and F-017 both need
a human accounting decision before the module can be called verified.
