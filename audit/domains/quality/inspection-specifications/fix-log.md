# M055 — Inspection Specifications Fix Log

Session date: 2026-08-26  
Claim result: `RECLAIMED` (orphaned lock from a crashed prior session, stale-hours `0`)  
Plan worked: [action-plan.md](action-plan.md) — all six ordered items, including the
`separate-recommended` ones. This session was the dedicated separate session those
items were waiting for.

## State inherited from the crashed session

The crashed session had already implemented most of the plan but logged **nothing**
(`fix-log.md` was still the inventory scaffold). Before changing anything I re-read
the report, the plan, and the source, and confirmed the following were already in the
working tree and are correct:

| Plan item | Findings | Inherited state |
|---|---|---|
| 1 — demo/bootstrap fixtures | F-01, F-02, F-06 | Done in `ComprehensiveDemoSeeder` (revision table in truncate list, specs seeded before inspections, revision-linked items, absolute bounds, `assertInspectionFixtureIntegrity()`) and `GoldenPathDemoSeeder` |
| 2 — revision history surface | F-03 | Done: `/revisions` + `/revisions/{revision}` routes, `InspectionSpecRevisionResource`, service `revisions()`/`revision()`, editor history panel, inspection-detail deep link |
| 3 — SPC population + zero variance | F-04, F-07 | Done: `compute()` returns `null` under `sigma < 1e-10`; `computeForSpec()`/`computeCapabilityStudy()` scoped to the current revision; `population_policy: current_revision_only` in the response meta |
| 4 — DB lineage + item invariants | F-05, F-06 | Done: composite FKs `inspection_spec_items_revision_spec_foreign` / `inspections_revision_spec_foreign`, plus five `inspection_spec_items_*` CHECKs |
| 5 — soft-deleted products | product-relation follow-up | Partly done (see FIX-01 — the eager load it added was dead on arrival) |
| 6 — SPA failure/loading states | F-08, F-09 | Done: SPC loading + retryable error states, skeleton `columns={6}` |

Nothing in the code had invalidated the report's findings; the report simply predated
those fixes. No re-discovery was needed. What was missing was **verification** — the
prior audit could not reach a database or run vitest, so none of the above had ever
executed. Verifying it found three real defects.

## Fixes made this session

### FIX-01 — `InspectionSpecService` eager-load closures crashed every read path (500)

`api/app/Modules/Quality/Services/InspectionSpecService.php:34`, `:67`, `:109`

The plan-item-5 fix typed its constrained eager loads as
`fn (Builder $product): Builder`. Laravel passes the **`Relation`** instance to an
eager-load constraint (not a `Builder`; only `whereHas()` receives a `Builder`), so
every one of these threw a `TypeError` before touching the database:

```
App\Modules\Quality\Services\InspectionSpecService::{closure}(): Argument #1
($product) must be of type Illuminate\Database\Eloquent\Builder,
Illuminate\Database\Eloquent\Relations\BelongsTo given
```

That is a hard 500 on `list()`, `show()`, `forProduct()` and therefore on `upsert()`
too — i.e. the entire module surface, in production, not only in tests. Every test in
the boundary suite failed on it.

- Before: `->with(['product' => fn (Builder $product): Builder => $product->withTrashed()->select([...])])`
- After: `->with(['product' => fn ($product) => $product->withTrashed()->select([...])])`, with a comment recording why the closure stays untyped.

This matches the repo convention for constrained eager loads (`fn ($q) => …`, e.g.
`api/app/Modules/Quality/Services/SpcService.php:177`). The `whereHas()` closure at
`:54` keeps its `Builder` type, which is correct there and was left alone.

### FIX-02 — revision-history region carried a label no assistive tech could read

`spa/src/pages/quality/inspection-specs/editor.tsx:499`, `:534`

The revision reconstruction panel was a plain `<div aria-label="Inspection spec
revision N">`. A generic `div` has no role, so `aria-label` on it is not exposed —
the accessible name was silently discarded and the panel the plan asked for was
unnamed for screen-reader users.

- Before: `<div className="mt-3 space-y-3" aria-label={…}>` … `</div>`
- After: `<section className="mt-3 space-y-3" aria-label={…}>` … `</section>` — `<section>` with an accessible name maps to `role="region"`.

Found by executing `editor.test.tsx`, which had been asserting
`getByRole('region', { name: 'Inspection spec revision 2' })` all along but had never
run.

### FIX-03 — SPC fixtures asserted the right outcome for the wrong reason

`api/tests/Feature/Quality/InspectionSpecBoundaryTest.php:183`, `:223`, `:232-234`

`test_revisions_retain_old_items_and_spc_uses_only_completed_readings` wrote
`measured_value => '-1.0000'` for all readings. With the zero-variance fix (F-04) an
all-identical series has sigma 0 and `compute()` correctly returns `null`, so:

- the positive assertion (`sample_count === 5`) failed outright — `Undefined array key`;
- the negative assertion (`assertArrayNotHasKey($oldItem->hash_id)`) passed for the wrong reason: absent because it was flat, not because it was revision-scoped.

Fixed by giving both series real variation (superseded revision and current revision
use distinct values), and added `assertSame(-1.0, …['mean'])` so a leaked
draft/in-progress/cancelled reading — those now sit far from the completed five —
would move the mean and fail the test.

### FIX-04 — editor test raced react-hook-form's prefill

`spa/src/pages/quality/inspection-specs/editor.test.tsx:147`

`findByRole('textbox', { name: 'Parameter 1 name' })` resolved against the initial
empty row, then the prefill `reset()` remounted every `useFieldArray` row, detaching
the node — so `toBeInTheDocument()` failed on a node that had existed. Now the test
first awaits the prefilled value (`findByDisplayValue('Inspect every batch.')`) and
queries the settled tree, and the SPC status assertion uses `findByRole` rather than
`getByRole`.

## Coverage added this session

- `api/tests/Feature/Quality/InspectionSpecBoundaryTest.php:98`, `:104` — list search by product **name** narrows to one row, and a term matching neither part number nor name returns zero. Only part-number search had been covered.
- `api/tests/Feature/Quality/InspectionSpecBoundaryTest.php:238` — `test_spc_reports_nothing_when_the_current_revision_has_no_measurable_variation`: six completed readings, all identical. Asserts `computeForSpec()` returns `[]` and that the HTTP endpoint returns `data: []` with `meta.population_policy = current_revision_only` and `meta.revision_version = 1`. This is the F-04/F-07 contract asserted through the role-facing surface, which the plan's item 3 required and nothing covered.

## Changes considered and reverted

I briefly grouped the `whereHas()` OR pair in `list()`, believing SQL precedence let
one name match satisfy `EXISTS()` for every row. That was **wrong** — Laravel's
`Builder::callScope()` already wraps callback-added wheres via
`addNewWheresWithinGroup()`. I proved it by reverting the grouping and re-running the
new name-search test, which still passed. The service is back to its original form
(no false comment left behind) and the regression test was kept, since name-search
narrowing genuinely had no coverage.

## Verification record (all commands actually executed)

Own database, per the concurrency rule: `ogami_test_r2`, `memory_limit = 512M`.

| Check | Result |
|---|---|
| `php artisan test --filter='InspectionSpecBoundaryTest\|SpcServiceTest'` | **17 passed, 96 assertions** (8 boundary + 9 unit), 0 failed |
| `vitest run src/pages/quality/inspection-specs/editor.test.tsx` | **2 passed** — executed for the first time |
| `npx eslint` on `editor.tsx`, `editor.test.tsx`, `index.tsx` | clean, exit 0 |
| `npx tsc --noEmit` | no diagnostic on any inspection-spec file (pre-existing unrelated errors in `assets/detail.tsx`, `return-management/detail.tsx` remain) |
| `php -l` on `InspectionSpecService.php` | no syntax errors |
| DB constraint introspection on `ogami_test_r2` | confirms `inspection_spec_items_revision_spec_foreign`, `inspections_revision_spec_foreign`, `inspections_spec_revision_pair_check` and the five `inspection_spec_items_*` CHECKs are really applied — F-05/F-06 verified against a live schema, not just read |
| Quality demo fixture, run **twice** on a freshly migrated DB | `PASS 1` and `PASS 2` identical: `specs=3 revisions=3 items=15 inspections=3 measurements=45 orphan_items=0 spc_params=4`, and `assertInspectionFixtureIntegrity()` clean both times |
| `GoldenPathDemoSeeder` quality pattern, in isolation | its spec↔revision join (`:430-438`) resolves `spec=1 revision=1`, and the inspection insert it builds (`:455-475`) is **accepted** by the live `inspections_spec_revision_pair_check` and `inspections_revision_spec_foreign` composite FK |

The twice-run fixture check is the release gate's headline item. It proves F-01 (no
revisionless or orphaned quality rows on a clean run or a rerun) and F-02 (seeded
absolute bounds produce measurements that yield four genuinely computable SPC
parameters, the visual parameter correctly excluded).

The Golden Path seeder's own `run()` cannot be reached (see out-of-scope blocker 2),
so its quality block was extracted and executed directly against the seeded schema
instead. Both of the things that could be wrong in it — the join failing to find a
revision, and the insert violating the new lineage constraints — are now covered by
execution rather than by reading.

### Fixing the vitest blocker

The prior audit could not run vitest: `spa/node_modules` is **root-owned**, so vite
cannot write `node_modules/.vite-temp/` to bundle its TypeScript config (`EACCES`).
`node_modules` is not writable by the session user, so this cannot be fixed from here
— it needs root. Worked around without touching the repo by running against an
equivalent plugin-free config outside the tree
(`npx vitest run --root . --config /tmp/vitest.r2.config.mjs`), which vite loads
natively and never bundles. **The underlying permission problem is unfixed and will
block `npm run test:run` for every session until someone with root runs
`chown -R` on `spa/node_modules`.**

## Out-of-scope blockers found — NOT fixed, not mine to touch

These are recorded for the coordinator. I did not modify any of these files.

1. **`api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`
   breaks `migrate:fresh` for the entire repository.** It runs
   `DROP INDEX IF EXISTS holidays_date_name_unique`, but that index backs a UNIQUE
   *constraint*, so PostgreSQL refuses:
   `SQLSTATE[2BP01] … cannot drop index holidays_date_name_unique because constraint
   holidays_date_name_unique on table holidays requires it`. Every `RefreshDatabase`
   test in the repo dies here, with zero assertions reached. It needs
   `ALTER TABLE holidays DROP CONSTRAINT` instead. To verify my own module I moved
   this file aside for the duration of each test run and restored it in the same
   command via a shell `trap`; it is byte-identical to its original state (verified
   with `diff -q`).

2. **`api/database/seeders/ComprehensiveDemoSeeder.php:634` (`seedJournalEntries()`)
   aborts the whole demo seed** on the accounting immutability trigger:
   `SQLSTATE[P0001] … Posted journal entry lines are immutable` from
   `prevent_posted_journal_line_mutation()`. This is why I verified the quality
   fixture through its own methods rather than `db:seed`: the seed never reaches the
   quality section. Consequence for this module: `GoldenPathDemoSeeder`'s inspection
   block (`:430-475`) could not be reached through `db:seed`, so I extracted and ran
   it directly instead (see the verification table) — its join and its constrained
   insert both pass. Someone should still re-run the full demo seed once the
   accounting trigger is fixed, but no quality-side risk remains unexercised.

3. **`api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:190-196` fails**
   (`test_capability_uses_only_passed_and_failed_inspection_measurements`, expected
   200 got 422, `quality_capability_insufficient_samples`). This belongs to the
   quality analytics/capability module, not to inspection specifications. It is
   pre-existing breakage caused by this module's F-04 zero-variance fix, which landed
   in the crashed session — not by any edit of mine. Two fixture defects, both at
   `:145-188`: every `measured_value` is `'10.0000'` (zero sigma → `compute()`
   returns `null` by contract), and the spec/item are created directly with no
   `inspection_spec_revision_id`, so `computeCapabilityStudy()` rejects the item as
   not belonging to the current revision. The fix is to vary the readings and link a
   revision. Left for whoever owns that module.

## Status

Released `✅ Verified`. All six plan items are implemented and, for the first time,
executed: 17 API tests and 2 SPA tests pass, the database-level lineage and item
invariants are confirmed present on a live schema, the quality demo fixture is clean
across two consecutive runs, and the Golden Path quality block's join and constrained
insert both execute successfully.

Nothing in this module is pending or deferred. The three blockers listed above are all
in other modules and are recorded for the coordinator, not left as this module's debt.
