# M009 — Global Search fix log

Audit date: 2026-08-24 (report + plan)
Fix session: 2026-08-26 — the dedicated hardening session the plan deferred to
Module status on release: ✅ Verified

## Claim

`bash audit/scripts/claim-module.sh platform global-search 0` → `STALE lock found (2h old…) - RECLAIMED`.
The orphaned lock from the crashed prior session was reclaimed through the script's own
documented stale path. No lock was removed by hand.

## Findings re-verified before fixing

`git log` over the module's source paths shows the last functional change was
`b9b9e627 chore: remove Meilisearch, which indexed nothing`. None of the module's own
files (`GlobalSearchService`, `SearchOperator`, `SearchController`, `CommandPalette`,
`Topbar`, `recentItemsStore`) was dirty in the shared worktree, so the 2026-08-24
findings still described the code as it stood. **No re-audit was needed.**

Two corrections to the report, both found while implementing:

1. **M009-F02 was mis-severed.** The report says view-only users "receive raw TINs".
   `customers.tin` / `vendors.tin` are `encrypted` casts stored as TEXT
   (`api/database/migrations/0043_create_vendors_table.php:21`), and the old code read the
   column through `DB::table()`, which bypasses the cast. What actually reached the client
   was **ciphertext**, not a readable TIN — a garbled sublabel, not a plaintext disclosure.
   The fix is unchanged (a government identifier, encrypted or not, has no business in a
   search sublabel that gets persisted to localStorage), but the confidentiality-bypass
   framing was wrong.
2. **One new defect found in the same area, not in the report.** See F02-b below —
   `recentItemsStore`'s persistence guard validated the wrong JSON shape and was silently
   deleting the key it was meant to protect on every page load, and the store was never
   cleared between users on a shared terminal.

## Fixes applied

### M009-F01 (P0) — row-level authorization bypass — FIXED

`api/app/Common/Services/GlobalSearchService.php` — rewritten from `DB::table()` to Eloquent
model queries and given the module lists' own row scopes.

| | before | after |
|---|---|---|
| employees | `DB::table('employees as e')` behind `can('hr.employees.view')`, unscoped | `Employee::query()` + `DepartmentScope::apply(viewAllPermission: 'hr.employees.view_sensitive', departmentPermission: 'hr.employees.view', deptColumn: 'employees.department_id', selfColumn: 'employees.id', selfId: $user->employee_id)` — byte-for-byte the call `EmployeeService::baseQuery()` makes (`api/app/Modules/HR/Services/EmployeeService.php:52-61`) |
| purchase orders | `DB::table('purchase_orders as po')` behind `can('purchasing.view')`, unscoped | `PurchaseOrder::query()` + `DepartmentScope::apply(viewAllPermission: 'purchasing.po.approve', departmentPermission: 'purchasing.pr.approve', selfColumn: 'purchase_orders.created_by', selfId: $user->id, deptRelation: 'purchaseRequest')` |

- `GlobalSearchService.php:100-129` — employee group + scope.
- `GlobalSearchService.php:168-200` — purchase-order group + scope.
- Columns are **table-qualified** throughout because of the joins. `purchase_orders.created_by`
  in particular is load-bearing: `vendors` also has a `created_by` column
  (`api/database/migrations/0222_add_created_by_to_vendors.php`), so the unqualified name
  used by `PurchaseOrderService::list()` would be ambiguous once `vendors` is joined.
- The PO rule is expressed **through the shared `DepartmentScope`**, not copied. That drops
  the `$roleSlug === 'department_head'` branch `PurchaseOrderService::list()` still carries
  and replaces it with the grant that role actually holds — per CLAUDE.md, "never add a
  role-name branch". Verified equivalent against the seeds: `purchasing.pr.approve` is held
  by `department_head` and `purchasing_officer`, and `purchasing_officer` also holds
  `purchasing.po.approve`, so it lands on tier 1 and never reaches the department tier.
- `$user->can()` → `$user->hasPermission()` throughout, for consistency with
  `DepartmentScope`. Equivalent: `AuthServiceProvider::boot()` routes permission-shaped
  abilities straight to `hasPermission()`.

Measured before/after on the dev database (`depthead@ogami.test`, dept 4):

```
employees matching "an":  company-wide 69  →  in-scope 32   (search now returns 32)
purchase orders "PO-":    all 4            →  in-scope 0    (search now returns 0; admin still 4)
```

**Deliberate narrowing to flag:** `impex_officer` holds `purchasing.view` but neither approve
grant, so its PO search now returns only POs it created. That is exactly what its PO *list*
already returned — search previously disagreed with the list, and the list was right.

### M009-F02 (P1) — sensitive identifier in the result contract — FIXED

- `GlobalSearchService.php:305-323` (customers) — `tin` removed from the select; the sublabel
  fallback is now `code`, the non-sensitive business identifier `CustomerResource` already
  returns unmasked.
- `GlobalSearchService.php:328-347` (vendors) — `tin` removed; vendors have no code column, so
  an empty `contact_person` now yields **no** sublabel rather than an identifier.
- `spa/src/stores/recentItemsStore.ts:1-18` — the header comment now states that the "no PII"
  guarantee is enforced by the *server*, not by that file, and names the field to keep clean.

Verified on the dev DB: customer/vendor payloads carry `sublabel: "Procurement Officer"` /
`"Account Manager"` and no `tin` key, no ciphertext.

### M009-F02-b (new, not in the report) — recent-items persistence — FIXED

Two defects, both in `spa/src/stores/recentItemsStore.ts`:

1. **The persistence guard validated the wrong shape and destroyed the data.**
   `persistedSchema` was `z.object({ items: […] })` — the *state* shape. `createJSONStorage`
   writes the *envelope* `{ state: { items }, version }`, so `items` was never a top-level key:
   the parse failed on every page load, the guard logged "Invalid persisted state" and deleted
   the key. Persistence silently did not work at all. Fixed at
   `recentItemsStore.ts:73-90` (schema now validates the envelope).
   Proven by temporarily reverting only that schema and re-running the new test:
   `AssertionError: expected [] to have a length of 1` → restored, `diff -q` byte-identical.
2. **The list was never cleared between users on a shared terminal.**
   `authStore.logout()` clears the query cache and `ogami:formdraft:*` keys for exactly this
   reason but never touched this store, so the next person to press ⌘K saw the previous user's
   employee numbers, PO numbers, customer names and department/position strings, with working
   links to records their own role forbids. (The 403 on click is not the leak — the identifiers
   already rendered.) Fixed by an `ownerId` on the persisted state plus a `claim()` action
   (`recentItemsStore.ts:38-64`, `:118-127`) driven by an `useAuthStore.subscribe` at
   `recentItemsStore.ts:131-146`.
   The asymmetry is deliberate and commented: a present user *claims* (so a page reload
   re-bootstrapping the same id keeps its own list), and only a transition away from a
   signed-in user *clears*. Claiming on sign-in is what also closes the 401 path, where the
   axios interceptor hard-navigates to `/login` without ever calling `logout()`.

   Scope note: the more central place is one line in `authStore.logout()`, which is the
   `platform/auth-session` module. Not touched — out of scope. The subscription is
   functionally equivalent and lives in the file it protects.

### M009-F03 (P1) — archived records searchable — FIXED

Moving every group from `DB::table()` to its Eloquent model puts the `SoftDeletes` global
scope back in the query — the same mechanism the module lists rely on via `TrashedFilter`'s
default arm. Eight of the eleven searched tables are soft-deletable (`employees`,
`sales_orders`, `purchase_orders`, `work_orders`, `products`, `items`, `customers`,
`vendors`); `invoices`, `bills` and `non_conformance_reports` have no `deleted_at` column at
all (confirmed against the live schema), so there is nothing to exclude there.

Deliberate decision: **no** archived-search mode was added. The plan's acceptance says "any
archived search is explicit, authorized, and tested" — there is now no archived search at
all, which satisfies the requirement without inventing a feature nobody asked for.

### M009-F04 (P2) — wildcard input, fan-out, query contract — FIXED, one part deferred

- `api/app/Common/Support/SearchOperator.php:44-75` — added `escape()`, `contains()`,
  `startsWith()`, `exact()`. `%` and `_` in user input are now escaped, so there is no
  user-facing wildcard vocabulary. `like()` is unchanged, so no other caller is affected.
  The escape is skipped on SQLite (documented in the docblock): Postgres and MySQL both treat
  backslash as the default `LIKE` escape, SQLite has none without an explicit `ESCAPE` clause,
  so escaping there would inject literal backslashes. Both the app and `phpunit` run on
  Postgres.
- `GlobalSearchService.php:92` — the term is built with `SearchOperator::contains()`.
- Search syntax and the query budget are now documented in the class docblock
  (`GlobalSearchService.php:60-84`) with measured numbers, not adjectives.
- `spa/src/components/ui/CommandPalette.tsx:216-221` — `retry: false` on the palette query.
  The client-wide default is `retry: 1`, which on a 30/min throttled endpoint spends a second
  token against the limit that just rejected the first.

Measured (dev dataset: 200 employees, 4 POs, 1 SO, 13 items, 8 products; system_admin, all
eleven groups active):

```
term  groups rows queries  ms
%%    0      0    22       49.7   ← previously would have matched every row in every table
a_    0      0    22       22.5
an    7      24   22       30.5
OGM   1      5    22       24.9
```

`EXPLAIN (ANALYZE, BUFFERS)` on the worst-case employee query: `Seq Scan on employees`,
200 rows scanned, 131 removed by filter, **2.188 ms** execution. A leading-wildcard `ILIKE`
cannot use a B-tree index, which is expected and fine at this scale.

**Deferred, with reason:** the trigram (`pg_trgm` GIN) / full-text index. It needs
`CREATE EXTENSION` rights, spans eleven tables owned by other modules, and the index choice
should follow a plan measured on production-sized data — this environment has single-digit
row counts on every transaction table, so any index added now would be a guess. Recorded in
the class docblock so the next reader does not have to rediscover it.

### M009-F05 (P2) — nondeterministic result selection — FIXED

`GlobalSearchService.php:353-390` — a `rank()` helper applied to every group. Relevance tiers:
0 exact identifier, 1 identifier prefix, 2 exact name, 3 name prefix, 4 substring; ties break
on the identifier then the qualified primary key. Column names are code constants; the term is
a **binding**, so this is not `DB::raw()` with user input.

### M009-F06 (P2) — failures rendered as "No results" — FIXED

`spa/src/components/ui/CommandPalette.tsx`:

- `:116-152` — `describeFailure()` maps 403 / 429 / 5xx / network to distinct copy and a
  `retryable` flag. A 403 shows no Retry button, because retrying cannot help.
- `:200-234` — `isError` / `error` / `refetch` are read, and `placeholderData`'s stale rows are
  dropped on error (`const groups = isError ? NO_GROUPS : fetchedGroups`). Without this the
  previous term's records sat under the new term with nothing marking them stale.
- `:379` — `showEmptyState` now excludes the failure case.
- `:441-472` — the inline failure block. Deliberately **not** `<QueryErrorState>`: that
  component's own docblock says it "distinguishes nothing about the cause", which is the right
  call for a page and the wrong one here, and it is a full-height `EmptyState` that does not
  fit a 420px dropdown. The reason is recorded in the JSX comment.

### M009-F07 (P2) — no mobile entry point, no focus restoration — FIXED

- `spa/src/components/layout/Topbar.tsx:95-110` — an icon-only `sm:hidden` search button beside
  the existing `hidden sm:flex` trigger. Below 640px the only way in was a ⌘K/Ctrl+K document
  listener, which is not a gesture a phone or floor tablet has.
- `spa/src/components/ui/CommandPalette.tsx:161-176` — the palette records
  `document.activeElement` on open and focuses it again on close. `aria-modal` moves focus into
  the dialog; dropping it afterwards strands keyboard and screen-reader users at the top of the
  document.

Not done, and flagged rather than faked: a **focus trap**. `aria-modal="true"` still promises
the page behind is inert while Tab can walk out of it. It is not in the plan's acceptance
criteria, and the only browser suite that could verify it (`spa/e2e/`, Chromium) needs a live
server that is not running. Left as an observation.

### M009-F08 (P1) — no negative security or failure-path coverage — FIXED

**Backend — `api/tests/Feature/Admin/GlobalSearchTest.php` (new, 20 tests / 80 assertions).**
There was no backend test for this endpoint at all. Coverage: 401 unauthenticated; 403 without
`search.global`; 403 + `code: feature_disabled` with `modules.search` off; 422 for `q` under 2
and over 120; a module permission the caller lacks contributing no group; department-head
employee scope (same surname in two departments, exactly one returned); the sensitive-grant
holder still seeing both; department-head PO scope; PO-approver seeing all; authorship-only
fallback; soft-deleted rows excluded across all eight soft-deletable models (asserting the
term matched *before* deletion, so a typo cannot make the test pass); no TIN or ciphertext for
a view-only caller; the `code` sublabel fallback; `%%`/`a_` matched literally; a literal `A_C`
still matching; exact identifier ranking first against five decoys; ordering stable across
identical requests; hash IDs and hash URLs, never integers; `status`/`amount` as plain strings.

**SPA — 17 tests across 3 files.**
- `spa/src/components/ui/CommandPalette.test.tsx` — 3 pre-existing + 6 new: 403/429/5xx render
  distinct recoverable states rather than "No results"; no automatic retry; stale rows dropped
  on failure; Retry refetches; focus returns to the opener.
- `spa/src/stores/recentItemsStore.test.ts` (new, 6) — rehydration (the F02-b regression),
  malformed blobs still rejected, cross-user `claim()` clearing, same-user retention, clearing
  on sign-out and on account takeover.
- `spa/src/components/layout/Topbar.test.tsx` (new, 2) — both breakpoints offer a trigger, and
  the narrow one opens the palette.

The Topbar tests assert **presence and the responsive class**, not geometry. jsdom computes no
layout, so a real narrow-viewport check belongs in the Chromium e2e suite (see below); the test
file says so explicitly rather than asserting a fabricated width.

## Verification performed

| Check | Result | Notes |
|---|---|---|
| `php -l` on both changed PHP files | PASS | |
| `api php artisan test --filter=GlobalSearchTest` | **PASS — 20 passed (80 assertions), 27.9s** | On `ogami_test_r4`, my own database, never `ogami_test` |
| `vitest run` × 3 module test files | **PASS — 17 passed (3 files)** | Run inside the `spa` container, which has its own `node_modules` volume |
| F02-b regression proven | PASS | Old schema restored temporarily → `expected [] to have a length of 1`; file restored, `diff -q` byte-identical |
| `npx tsc --noEmit` | 3 errors, **none mine** | `pages/assets/detail.tsx` (missing `qrcode` dep) and `pages/return-management/detail.tsx` (duplicate JSX attribute) — both files are `M` in the shared worktree, i.e. another agent's in-flight work |
| `npx eslint` on all 6 changed/added SPA files | PASS | |
| `node scripts/check-token-discipline.mjs` | PASS | 784 files, no hardcoded colours |
| `EXPLAIN (ANALYZE, BUFFERS)` worst-case employee query | recorded | Seq Scan, 2.188 ms / 200 rows |
| Row-scope behaviour measured on dev DB | recorded | 69→32 employees, 4→0 POs for a department head |
| Authenticated Chromium browser walk | **NOT RUN** | `nginx` and `spa` containers are stopped; five concurrent audit sessions. No e2e spec references the palette, so nothing regressed — but no narrow-viewport browser evidence was produced either |
| Production-sized query-plan benchmark | **NOT RUN** | Dev dataset has single-digit rows on every transaction table |

### Environment obstacles worked around, not papered over

1. **`spa/node_modules` is root-owned**, so vitest cannot write `.vite-temp` on the host
   (`EACCES`) — the reason the 2026-08-24 session recorded the SPA suite as BLOCKED. Worked
   around by running vitest inside the `spa` service container, which mounts its own
   `spa_node_modules` named volume. The host permission problem still needs root and was not
   touched.
2. **`api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php`** —
   a foreign, untracked in-flight migration whose line 42 `DROP INDEX IF EXISTS
   holidays_date_name_unique` fails on this database because that name is a UNIQUE
   *constraint*: `SQLSTATE[2BP01] … cannot drop index … because constraint … requires it`.
   It killed `RefreshDatabase`. **The file flapped in and out of the tree during this session**
   (my first green run at 00:54 happened while it was absent; a re-run at 01:07 hit it). Not
   edited, not deleted: moved aside for the duration of one run by a script with a `trap … EXIT
   INT TERM` restore, then verified byte-identical with `diff -q`. It is back in place.
3. **A stale bind-mount view in the long-running `api` container** made `require` fail on a
   file that `ls` and `file_exists` both reported as present. Worked around by running tests in
   a fresh `docker compose run --rm --no-deps api` container instead of `exec`.

## Items NOT fixed — out of module scope, handed off

1. **Purchase-order detail is still broader than the list.** The report's F01 notes it and it
   remains true: `api/app/Modules/Purchasing/routes.php` gates `show` on `purchasing.view`
   only, and `PurchaseOrderController::show()` does not apply the list scope
   (`api/app/Modules/Purchasing/Controllers/PurchaseOrderController.php:46-49`). Search no
   longer *hands out* out-of-scope PO hash IDs, so the practical route in is closed — but a
   guessed or shared hash ID still opens the record. **This belongs to
   `procurement/purchase-orders`, not to M009.**
2. **`PurchaseOrderService::list()` still hand-rolls its own scope** with a
   `$roleSlug === 'department_head'` branch. Search now expresses the identical rule through
   the shared `DepartmentScope`; adopting it there too would remove the duplication and the
   role-slug coupling. Same module as (1).
3. **The trigram / full-text index** — see F04 above. Needs `CREATE EXTENSION` rights, spans
   eleven other modules' tables, and needs production-sized data to choose correctly.
4. **A focus trap for the palette dialog** — see F07 above.

## Housekeeping

- Test database `ogami_test_r4` was created and left in place (`ogami_test` was never touched).
- No `git add`, `commit`, `stash`, `checkout` or branch operation was run. All edits are in the
  shared working tree.
- `regenerate-registry.sh` was not run; release only, per the session brief.

## Files changed by this session

| File | Change |
|---|---|
| `api/app/Common/Services/GlobalSearchService.php` | M — rewritten: Eloquent + row scopes + escaped term + ranking + no TIN + documented contract |
| `api/app/Common/Support/SearchOperator.php` | M — added `escape()`, `contains()`, `startsWith()`, `exact()`; `like()` untouched |
| `api/tests/Feature/Admin/GlobalSearchTest.php` | A — 20 tests |
| `spa/src/components/ui/CommandPalette.tsx` | M — failure states, `retry: false`, stale-row drop, focus restoration |
| `spa/src/components/ui/CommandPalette.test.tsx` | M — +6 tests |
| `spa/src/components/layout/Topbar.tsx` | M — narrow-screen search trigger |
| `spa/src/components/layout/Topbar.test.tsx` | A — 2 tests |
| `spa/src/stores/recentItemsStore.ts` | M — envelope schema fix, `ownerId` + `claim()`, auth subscription, threat-model comment |
| `spa/src/stores/recentItemsStore.test.ts` | A — 6 tests |
| `docs/USER-MANUAL.md` | M — global-search bullet: mobile trigger, literal matching, row scope, no archived records |

## Fix session: 2026-08-27 — M009-F11

Claimed `platform / global-search` atomically with `audit/scripts/claim-module.sh`.

### M009-F11 — employee middle-name search

- `api/app/Common/Services/GlobalSearchService.php` now selects and matches the qualified `employees.middle_name` column with the existing case-insensitive `SearchOperator::contains()` term, leaving the employee row scope and result mapping unchanged.
- The relevance helper now accepts the existing name input plus `employees.middle_name`; its identifier/name ranking tiers, binding-based literal matching, and per-group limit remain intact.
- `api/tests/Feature/Admin/GlobalSearchTest.php` adds a middle-name-only fixture with five substring decoys. The lower-case query must return the exact middle-name fixture first, within the existing five-row window, with the existing hash ID, label, URL, and no new `middle_name` response field.
- Red/green evidence: the regression first returned zero items before the source change, then passed after the fix.

### Focused verification

- `DB_DATABASE=ogami_test_m009_impl` — `php artisan test tests/Feature/Admin/GlobalSearchTest.php --no-coverage`: **PASS — 21 tests, 86 assertions**.
- PHP lint for the changed service and focused test: **PASS**.
- `git diff --check`: **PASS**.
- No SPA files changed; no SPA checks were run.

F09, F10, F12, F13, F14, F15, and F16 remain open and out of scope. Release status is
`🔁 Needs Re-audit`.

## Fix session: 2026-08-27 — M009-F12

Claimed `platform / global-search` atomically with `audit/scripts/claim-module.sh`.

### M009-F12 — normalized query validation

`SearchController` now trims string query input before Laravel applies the `required`,
`min:2`, and `max:120` rules, passes the validated normalized query to
`GlobalSearchService`, and echoes that normalized value in the response. The service
keeps its defensive trim for direct callers, while literal wildcard escaping, existing
row scopes, and F11 middle-name matching remain unchanged. The palette already used the
same trim-before-gate/request behavior; its focused test now covers padded one-character
rejection and normalized valid padded requests.

`api/tests/Feature/Admin/GlobalSearchTest.php` adds focused regressions for padded
one-character and whitespace-only queries returning 422, plus a valid padded query that
returns its matching employee and `query: "PaddedQuery"`.

### Focused verification

- `docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_m009_f12 api php artisan test tests/Feature/Admin/GlobalSearchTest.php --no-coverage` — **PASS: 24 tests, 95 assertions**.
- `docker compose run --rm --no-deps spa npm run test:run -- src/components/ui/CommandPalette.test.tsx` — **PASS: 10 tests**; existing React `act(...)` warnings only.
- PHP lint for controller, service, and focused backend test — **PASS**.
- Targeted SPA ESLint and `npm run typecheck` — **PASS**.
- `git diff --check` — **PASS**.

F09, F10, F13, F14, F15, and F16 remain open and out of scope; F11 remains complete.
Release status: `🔁 Needs Re-audit`.

## Fix session: 2026-08-27 — M009-F15

Claimed `platform / global-search` atomically with `audit/scripts/claim-module.sh`.

### M009-F15 — CommandPalette focus trap

`CommandPalette` now scopes keyboard focus to its `aria-modal` dialog. Tab and
Shift+Tab wrap from the last and first focusable controls respectively, and an
unexpected focus escape is redirected to the appropriate edge. The dialog has a
programmatic focus fallback while Escape handling and opener focus restoration
remain unchanged.

Focused regressions cover forward wrapping, reverse wrapping, Escape close with
opener restoration, and the existing open/close focus restoration behavior.

### Focused verification

- `audit/scripts/claim-module.sh platform global-search` — **PASS: CLAIMED**.
- `docker compose run --rm --no-deps spa npm run test:run -- src/components/ui/CommandPalette.test.tsx` — **PASS: 13 tests**; existing React `act(...)` warnings only.
- `docker compose run --rm --no-deps spa npx eslint src/components/ui/CommandPalette.tsx src/components/ui/CommandPalette.test.tsx --max-warnings 0` — **PASS**.
- `docker compose run --rm --no-deps spa npm run typecheck` — **PASS**.
- `git diff --check` — **PASS**.

F09, F10, F13, F14, and F16 remain open and out of scope; F11 and F12 remain complete.
Release status: `🔁 Needs Re-audit`.

## Fix session: 2026-08-28 — M009-F15 browser follow-up

The prior M009-F15 implementation added the document-level Tab/Shift+Tab loop
and jsdom coverage. The audit acceptance also requires a browser/accessibility
assertion, so `spa/e2e/command-palette.spec.ts` now opens the palette through
the real desktop Search button and exercises native Playwright keyboard
traversal.

The browser spec asserts:

- the real dialog is visible with `aria-modal="true"`;
- native `page.keyboard.press('Tab')` traversal reaches the last control and
  wraps to the first without leaving the dialog;
- native `page.keyboard.press('Shift+Tab')` wraps from the first control back to
  the last; and
- native Escape removes the dialog and restores focus to the opener.

### Focused verification

- `npx playwright test e2e/command-palette.spec.ts --project=desktop-chromium --reporter=line --output=/tmp/ogami-m009-f15-playwright` — **PASS: 1 test (11.5s)** against the real Chromium browser. This pass occurred before the final Firefox-compatibility guard below.
- `docker compose run --rm --no-deps spa npm run test:run -- src/components/ui/CommandPalette.test.tsx` — **PASS: 13 tests**; existing React `act(...)` warnings only.
- `npx eslint e2e/command-palette.spec.ts --max-warnings 0` — **PASS**.
- Firefox browser run — **BLOCKED by a genuine compatibility failure in the pre-guard implementation**: native Tab focused the dialog's `div[tabindex="-1"]` container instead of a focusable control. The minimal guard in `CommandPalette.tsx` now redirects that state to the correct boundary, but the post-patch browser rerun was intentionally stopped at the user's request before it could be verified.
- `git diff --check` — **PASS** after the final source guard.

Release remains `🔁 Needs Re-audit` because the final production guard still needs a focused browser rerun.
