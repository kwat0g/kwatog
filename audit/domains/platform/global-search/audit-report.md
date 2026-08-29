# M009 — Global Search audit report

Audit date: 2026-08-27
Claim: `platform / global-search` (`M009`)
Claim result: `audit/scripts/claim-module.sh platform global-search` → `CLAIMED`
Fallback: not used
Registry tier: 4
Status: 📋 Plan Ready
Database used for verification: `ogami_test_m009_roll_c` only

## Verdict

The 2026-08-26 hardening commit closed the original row-scope, sensitive-field,
soft-delete, ranking, failure-state, mobile-entry, and regression-coverage findings.
The re-audit still finds two P1 authorization-boundary defects: global search ignores
per-module feature flags, and persisted recent records are not invalidated when the
same user's permissions or enabled modules change. The remaining findings are contract,
operational, accessibility, and portability gaps.

No production-code fix was applied. The open P1 work is medium scope and requires a
coordinated server/client contract plus negative tests; the plan is therefore not a
small majority-`same-session-ok` plan. Final status is `📋 Plan Ready`.

## Audit scope and evidence

- Read the generated registry without editing or regenerating it; read the inherited
  project/module docs, prior `status.md`, `audit-report.md`, `action-plan.md`, and
  `fix-log.md` before trusting the prior `✅ Verified` status.
- Read the current implementation, focused tests, relevant row-scope/feature/auth
  dependencies, current git diff, recent history, and mtimes. The module source had no
  uncommitted diff; its latest hardening is commit `1a5d2122`.
- Re-verified the route, PHP syntax, backend feature tests, SPA unit tests, typecheck,
  module ESLint, and git whitespace. Existing unrelated worktree changes were preserved.
- Ran an additional focused probe in `ogami_test_m009_roll_c`: a `system_admin` search
  still returned a CRM product after `modules.crm=false`; an employee matching only
  `middle_name` was not returned; and the request validator accepted `q=" a "` while
  the trimmed service returned no results.
- `php artisan route:list --path=search` shows the M009 route alongside the separate
  quality traceability route; no registry generation was run.

## Re-verified strengths and closed findings

- The endpoint is protected by Sanctum, the search feature flag, the global-search
  permission, and a throttle (`api/app/Modules/Admin/routes.php:145-147`).
- Search now starts from Eloquent models and applies the shared employee and purchase
  order row scopes (`api/app/Common/Services/GlobalSearchService.php:111-138`,
  `:173-200`).
- Sensitive customer/vendor TIN columns are absent from the result contract; the
  customer fallback is a business code (`api/app/Common/Services/GlobalSearchService.php:335-350`),
  and the recent store validates its persisted envelope and binds it to an owner
  (`spa/src/stores/recentItemsStore.ts:86-92`, `:127-151`).
- Eloquent SoftDeletes now excludes archived base records; deterministic relevance
  ordering is applied by `rank()` (`api/app/Common/Services/GlobalSearchService.php:402-440`).
- The palette distinguishes failures, drops stale rows on failure, disables automatic
  retries, restores focus to its opener, and has a narrow-screen trigger. These were
  covered in the existing fix log and re-exercised by the focused SPA suite.
- The prior F04 literal wildcard behavior is fixed for the production PostgreSQL path;
  the production-sized index/benchmark portion remains open as M009-F13 below.

## Findings

### M009-F09 — Broken: per-module feature flags are bypassed by global search

Priority: **P1**
Scope: **medium**
Session: **separate-recommended**

The search endpoint checks only `feature:search` (`api/app/Modules/Admin/routes.php:145-147`).
Each result group then checks a permission and table existence, but no corresponding
`modules.hr`, `modules.crm`, `modules.purchasing`, `modules.production`,
`modules.accounting`, `modules.inventory`, or `modules.quality` setting; for example,
the employee, purchase-order, product, and customer branches are gated only at
`api/app/Common/Services/GlobalSearchService.php:111-121`, `:173-180`, `:283-289`,
and `:335-340`.

The normal module routes independently enforce their feature flags, for example CRM
uses `feature:crm` (`api/app/Modules/CRM/routes.php:17-21`), and the middleware returns
`feature_disabled` when a module is off (`api/app/Common/Middleware/CheckFeature.php:21-33`).
The SPA also filters navigation by enabled feature (`spa/src/components/layout/Sidebar.tsx:781-796`),
but renders every API group without a feature check (`spa/src/components/ui/CommandPalette.tsx:316-332`).

Observed in the unique audit database: with `modules.crm=false`, a system-admin search
for a CRM-only fixture still returned the `product` group. This exposes records from a
disabled module in the search response and palette even though its normal routes are
disabled. The broad system-admin permission bypass makes the discrepancy especially
clear, but it also affects any user retaining a permission while an organization toggle
changes.

Action: map every search group to its owning feature and enforce that setting in the
server result contract, then filter defensively in the SPA. Add negative API tests for
each disabled feature and a client test proving disabled groups never render.

### M009-F10 — Broken: same-user recent records survive permission/module revocation

Priority: **P1**
Scope: **medium**
Session: **separate-recommended**

Permission and module updates refresh the auth store (`spa/src/hooks/usePermissionSync.tsx:25-42`),
but the recent-items store only compares the persisted `ownerId`; a same-user claim
retains every item (`spa/src/stores/recentItemsStore.ts:127-151`). The store explicitly
documents that localStorage has no permission check (`spa/src/stores/recentItemsStore.ts:16-17`).
The palette renders all recents without rechecking current permissions/features and
copies their labels, sublabels, and statuses into new entries (`spa/src/components/ui/CommandPalette.tsx:263-279`,
`:352-364`).

After a permission override or module toggle, a previously authorized employee/PO/
customer result can therefore remain visible in localStorage and clickable in the
palette. A later route 403 does not undo the local disclosure already rendered on the
shared terminal.

Action: invalidate or version recents when effective permissions/features change. Prefer
persisting only a safe route identity and revalidating before display, or implement a
server-backed visibility token. Add same-user permission-revocation, module-toggle, and
localStorage regression tests.

### M009-F11 — Incomplete: employee middle-name search disagrees with the employee list

Priority: **P2**
Scope: **small**
Session: **same-session-ok**

The HR employee list searches `employee_no`, `first_name`, `middle_name`, and `last_name`
(`api/app/Modules/HR/Services/EmployeeService.php:63-70`), while global search omits
`middle_name` (`api/app/Common/Services/GlobalSearchService.php:118-121`). The manual
promises literal substring matching for global search and says results use the same
allowed row set as module lists (`docs/USER-MANUAL.md:281-288`). The focused probe with a
middle-name-only fixture returned no result group.

Action: include `employees.middle_name` in the group predicate and relevance inputs,
then add a regression fixture that is searchable only through the middle name.

### M009-F12 — Incomplete: minimum-length validation is applied before trimming

Priority: **P2**
Scope: **small**
Session: **same-session-ok**

`SearchController` validates the raw query string with `min:2` and echoes that raw value
(`api/app/Modules/Admin/Controllers/SearchController.php:16-24`), while the service trims
before applying its two-character guard (`api/app/Common/Services/GlobalSearchService.php:100-105`).
Thus `q=" a "` passes HTTP validation but produces an empty 200 response rather than a
consistent validation error or normalized query. The UI trims before enabling the query,
so this is primarily an API contract inconsistency and a direct-client edge case.

Action: normalize/trim before validation, validate the normalized value, and return the
normalized query or a deliberate 422 response. Add whitespace-only and padded one-character
tests.

### M009-F13 — Incomplete: query-cost ceiling is documented but not enforced

Priority: **P2**
Scope: **large**
Session: **separate-recommended**

`MAX_SOURCE_QUERIES=11` is explicitly described as a documentation ceiling rather than
a runtime guard (`api/app/Common/Services/GlobalSearchService.php:92-97`). The service
still contains one sequential branch per group, each using a leading-wildcard search and
`limit()` (`api/app/Common/Services/GlobalSearchService.php:149-200`, `:283-343`,
`:354-399`). Employee and purchase-order non-admin paths also perform a department lookup
through `DepartmentScope::departmentIdFor` (`api/app/Common/Support/DepartmentScope.php:69-72`,
`:101-110`), so the class comment's “one SELECT plus one Schema probe” is not a complete
request budget.

The old fix log deliberately deferred trigram/full-text indexing and a production-sized
benchmark because the current database is small and index rights span other modules
(`audit/domains/platform/global-search/fix-log.md:161-165`). The current tests prove
matching and row ceilings, not latency or database load under worst-case production data.

Action: make the group registry and query budget executable, record query/latency limits,
and benchmark representative data. Choose prefix/exact semantics or PostgreSQL trigram
/full-text indexes from measured plans; coordinate any cross-module migrations with the
owning module cards.

### M009-F14 — Incomplete: archived related labels are not covered by the soft-delete policy

Priority: **P2**
Scope: **medium**
Session: **separate-recommended**

The searched base models now use Eloquent and exclude their own soft-deleted rows, but
joined labels are read through raw `leftJoin`s without a related-row tombstone predicate;
for example purchase orders join vendors (`api/app/Common/Services/GlobalSearchService.php:173-180`)
and customer/vendor results join no relation policy at all (`:335-363`). Vendors and
customers themselves use `SoftDeletes` (`api/app/Modules/Accounting/Models/Vendor.php:13-17`,
`api/app/Modules/Accounting/Models/Customer.php:13-17`). A live transaction can therefore display the name of an archived
related record, and the test suite does not define whether that label should be hidden,
null, or treated as an explicit archived result.

Action: decide the archived-related-record contract with the owning module, apply the
same relation/global-scope semantics to joined labels, and add live-parent/deleted-child
fixtures for every joined source. Do not add archived-search behavior implicitly.

### M009-F15 — Missing: the `aria-modal` palette has no focus trap

Priority: **P2**
Scope: **small**
Session: **same-session-ok**

The palette declares a modal dialog (`spa/src/components/ui/CommandPalette.tsx:402-407`)
and moves focus into it/restores the opener, but its key handler handles only Escape,
ArrowUp, ArrowDown, and Enter (`spa/src/components/ui/CommandPalette.tsx:367-388`). No
Tab/Shift+Tab trap or equivalent focus-scope primitive is present. Keyboard and screen
reader users can tab behind a dialog that claims the background is modal. The prior fix
log recorded this as intentionally deferred (`audit/domains/platform/global-search/fix-log.md:199-202`).

Action: use the project's focus-scope primitive or implement a tested Tab loop, and add
an accessibility/browser assertion that focus remains inside the dialog until close.

### M009-F16 — Polish: the advertised cross-driver literal wildcard contract is untested on SQLite

Priority: **P3**
Scope: **small**
Session: **separate-recommended**

`SearchOperator` presents itself as cross-driver and promises that `contains()` escapes
`%` and `_` (`api/app/Common/Support/SearchOperator.php:9-34`), but `escape()` deliberately
returns raw input on SQLite because SQLite has no default backslash escape
(`api/app/Common/Support/SearchOperator.php:43-60`). The application and current tests
run on PostgreSQL, so this is not a production-path failure today; it is a portability
contract gap that could silently reintroduce wildcard matches if a fallback driver is used.

Action: either add an explicit driver-safe `ESCAPE` strategy with SQLite coverage or
narrow the helper's documentation/availability to supported PostgreSQL deployments.

## Verification

| Check | Result | Notes |
|---|---|---|
| `php artisan test tests/Feature/Admin/GlobalSearchTest.php --no-coverage` | **PASS — 20 tests, 80 assertions** | `ogami_test_m009_roll_c`; no shared `ogami_test` use |
| Focused SPA Vitest: palette, recents, topbar | **PASS — 17 tests across 3 files** | Container run; existing React `act(...)` warnings only |
| `npm run typecheck` | **PASS** | SPA container |
| Module-scoped ESLint | **PASS** | Six M009 SPA source/test files |
| PHP lint | **PASS** | Service, operator, controller, and focused backend test |
| `php artisan route:list --path=search` | **PASS** | M009 route enumerated |
| `git diff --check` | **PASS** | No whitespace errors |
| Feature-flag/middle-name/whitespace probe | **PASS — defects reproduced** | Unique audit DB; evidence for M009-F09, F11, F12 |
| Authenticated browser/e2e audit | **Not run** | No dedicated palette e2e path/live authenticated browser evidence in this session |
| Production-sized query plan/latency benchmark | **Not run** | Deferred by M009-F13; dev data is not representative |

## Deferred work and blockers

- M009-F09 and M009-F10 require coordinated backend/client authorization contracts and
  negative regression coverage; this is the release gate blocker for M009.
- The purchase-order detail route remains broader than the list scope. Search no longer
  hands out out-of-scope PO rows, but the route-level issue belongs to the
  `procurement/purchase-orders` card and was not modified here.
- Production search indexes/benchmarks span other modules and require database extension
  rights; do not change those dependency-owned migrations from this card.
- The unrelated untracked `m036-browser-check.mjs` and unrelated leave-management edits
  were preserved and are not part of this module's commit.

## Next action

In a separate hardening session, implement the per-group feature gate and same-user recent
invalidation first, add negative tests, then resolve the archive/latency contract before
taking the small API and accessibility improvements. Re-audit M009 only after those tests
and a representative performance/browser check are available.

---

# Re-audit — 2026-08-30

Claim: `audit/scripts/claim-module.sh platform global-search` → `CLAIMED`
Registry tier: 4 · Prior status: `🔁 Needs Re-audit` (2026-08-27)
Database used for verification: `ogami_test_search` only (created, used, dropped).
`ogami_test` was never touched. `regenerate-registry.sh` was not run.

## What changed since the last audit

The working tree was clean for every module file. Four commits had landed and are
all real work, not phantom entries:

| commit | effect |
|---|---|
| `e0530b33 fix(global-search): include employee middle names` | closes **M009-F11** |
| `e72c3315 fix: M009-F12 normalize global search query` | closes **M009-F12** |
| `5cd6e7e5 fix(global-search): trap command palette focus` | closes **M009-F15** (jsdom) |
| `6e932ad9`, `44f6e1b3 test(global-search): … browser focus` | closes **M009-F15** (Chromium + Firefox) |

Baseline before any change this session:
`php artisan test tests/Feature/Admin/GlobalSearchTest.php` → **24 passed, 95 assertions**.

## The permission matrix, executed

The defining risk for this module is one searchable entity out of eleven losing its
authorization check. That was **measured**, not reasoned about: a probe created one
matching record per group, all inside the caller's row scope, then issued a real
`GET /api/v1/search` as **every one of the thirteen seeded roles** and compared the
returned group set against the permissions that role actually holds.

`search.global` is a single permission (`api/database/seeders/RolePermissionSeeder.php:413`)
held by seven roles. Holding it must not imply reading any table.

| role | `search.global` | groups granted | groups returned | mismatch |
|---|---|---|---|---|
| system_admin | Y (wildcard) | all 11 | all 11 | none |
| hr_officer | Y | employee | employee | none |
| finance_officer | Y | invoice, bill, customer, vendor | same | none |
| production_manager | Y | purchase_order, work_order, item, ncr | same | none |
| ppc_head | Y | work_order | work_order | none |
| department_head | Y | employee, purchase_order | same | none |
| maintenance_tech | Y | **(none)** | **(none)** | none |
| purchasing_officer | n | — | **HTTP 403** | none |
| qc_inspector | n | — | **HTTP 403** | none |
| warehouse_staff | n | — | **HTTP 403** | none |
| impex_officer | n | — | **HTTP 403** | none |
| employee | n | — | **HTTP 403** | none |
| driver | n | — | **HTTP 403** | none |

**Zero mismatches across 13 roles × 11 entities. No entity is uncovered.** Every one of
the eleven was exercised with a live fixture; none was skipped. The permission dimension
of this module is sound, and is now locked by
`test_no_seeded_role_sees_a_group_it_lacks_the_permission_for`.

Corollaries also measured:

- `search.global` alone returns `data: []` — it opens the endpoint and nothing else.
  `maintenance_tech` is the live instance of that (see F17 below).
- The `employee` role cannot reach the endpoint at all (403), so "an employee surfacing
  another employee's records" is not reachable. For the roles that *can* search,
  employee rows are department-scoped: out-of-scope fixtures in another department
  returned nothing for `department_head`.
- Row scoping is entirely server-side (`GlobalSearchService.php:130-138`, `:193-202`).
  No client input selects the scope.

## Snippet, id and injection surface, executed

Full `system_admin` payload for a fixture in every group was dumped and inspected:

```
employee => {"id":"q0zw8Gb7no","label":"Liliane Zenkoku","sublabel":"OGM-562356 · sit et · Recruiter", …}
customer => {"id":"29awRBwjWx","label":"Zenkoku Motors 1","sublabel":"Waino Armstrong","status":null, …}
```

- **No masked field leaks through a snippet.** No `tin`, no `sss_no`/`philhealth_no`/
  `pagibig_no`/`bank_account_no`, no ciphertext (`eyJpdiI6`), no salary field appears in
  any `label`, `sublabel`, `status` or `amount`. The employee sublabel is employee number
  · department · position. NCR emits `Severity: low`, never the searched
  `defect_description`.
- **No raw integer ids anywhere** — every `id` and every `url` is a HashID, in all eleven
  groups. Error bodies carry no ids either (403 `feature_disabled`, 422 validation).
- **No `DB::raw()` with user input.** The one raw fragment is
  `orderByRaw('CASE … END', $bindings)` (`GlobalSearchService.php:448`); the column names
  are code constants and the term is a **binding**. There is no `to_tsquery`/`plainto_tsquery`
  anywhere, so the unbalanced-syntax crash class does not exist here.
- **`LIKE` metacharacters are escaped** (`SearchOperator::escape()`,
  `api/app/Common/Support/SearchOperator.php:54-61`): `%%` and `a_` return `[]` while a
  literal `A_C` still matches. Backslash is escaped first, so `\` cannot break out.
- Length and rate bounds hold: `min:2` / `max:120` after trimming
  (`api/app/Modules/Admin/Controllers/SearchController.php:18-30`), `throttle:30,1`
  (`api/app/Modules/Admin/routes.php:146`). A 120-character term returned 200 in 27.9 ms.

## Search engine: pure PostgreSQL; the Meilisearch container is an orphan

- `ogami-meili` (`getmeili/meilisearch:v1.10`) exists in Docker, `Exited (143) 10 days ago`.
- It is **not a service in `docker-compose.yml`** (services: api, spa, nginx, db, redis,
  reverb, queue) and there is **no `meili`/`scout`/`Searchable` reference** anywhere in
  `api/app`, `api/config`, `composer.json`, `composer.lock` or `spa/src`. It was removed by
  `b9b9e627 chore: remove Meilisearch, which indexed nothing`.
- **The engine is therefore not required and cannot degrade — there is nothing to be
  absent.** Search is Postgres `ILIKE`, always. There is no silent-zero-results-when-the-
  engine-is-down failure mode, because there is no engine.
- The container is nevertheless still registered against this Compose project, so
  **every `docker compose` command in this repo prints
  `Found orphan containers ([ogami-meili])`** — a standing false signal. See F20.

## Findings

Prior findings **M009-F11, F12, F15 are CLOSED** and were re-verified green this session.
**M009-F09 is FIXED this session** (see `fix-log.md`). F10, F13, F14, F16 remain open,
with F10 re-rated on evidence. Four new findings.

### M009-F09 — Broken: per-module feature flags were bypassed — **FIXED this session**

Priority: **P1** · Scope: **small** (as executed) · Status: **fixed, red/green proven**

Reproduced by execution before fixing: with `modules.hr`, `modules.crm`,
`modules.purchasing`, `modules.production`, `modules.accounting`, `modules.inventory` and
`modules.quality` **all set to `false`**, a `system_admin` search still returned
**all eleven groups**:

```
===== ALL MODULE FLAGS OFF (modules.search still on) =====
  groups returned: employee,sales_order,purchase_order,work_order,invoice,bill,
                   product,item,customer,vendor,ncr
```

What makes this a defect rather than a design choice is an inconsistency *inside the same
dropdown*: `isNavItemVisible` drops any nav item whose `feature` is off, ahead of even the
system_admin bypass (`spa/src/components/layout/Sidebar.tsx:786`), and the palette builds
its "Pages" section from exactly that function (`spa/src/components/ui/CommandPalette.tsx:264-266`).
So ⌘K hid the **Employees page** while still listing **employee records**. Meanwhile every
module's own routes answer 403 `feature_disabled` (`api/app/Common/Middleware/CheckFeature.php:21-33`),
e.g. `feature:hr` at `api/app/Modules/HR/routes.php:25`.

Fixed by a `GROUP_FEATURES` map plus a per-group gate — see `fix-log.md` for the
before/after and the red/green evidence.

### M009-F10 — Incomplete: same-user recents survive permission/module revocation

Priority: **P2** (re-rated down from P1) · Scope: **medium** · Session: **separate-recommended**

Still reproduces by reading: `usePermissionSync` refreshes the auth store on
`permission.override.changed` and `ModuleToggled` (`spa/src/hooks/usePermissionSync.tsx:32-42`),
but `recentItemsStore.claim()` compares only `ownerId`, so a same-user claim keeps every
item (`spa/src/stores/recentItemsStore.ts:127-128`), and the palette renders and re-copies
those labels unchecked (`spa/src/components/ui/CommandPalette.tsx:281-289`, `:363-371`).

**Re-rated to P2, with the reasoning stated so it can be disagreed with.** The prior report
called this P1 alongside F09. The cross-*principal* case — the next person on a shared plant
terminal — is already closed by the `ownerId` + `claim()` work (`recentItemsStore.ts:127-128`,
`:145-152`). What remains is the *same* user still seeing identifiers **they were authorized
to see minutes earlier**. That is a stale-cache defect and a real one, but it is not a
disclosure to a new principal, which is what P1 is for here.

### M009-F13 — Incomplete: query budget is documented, not enforced; no production plan

Priority: **P2** · Scope: **large** · Session: **separate-recommended**

Still open, and the documented figure is now shown to understate reality. `MAX_SOURCE_QUERIES = 11`
is explicitly a comment, not a guard (`GlobalSearchService.php:119-124`), and the class
docblock claims "22 queries" for a system_admin. Measured end-to-end through HTTP on
`ogami_test_search`:

| caller | queries | of which catalog/`information_schema` probes | wall |
|---|---:|---:|---:|
| system_admin (11 groups) | **30** | **14** | 109.4 ms (cold) |
| department_head (2 groups) | **26** | **10** | 22.2 ms |
| system_admin, 120-char term | — | — | 27.9 ms |

The docblock counts only the service's own SELECTs; the request budget is larger because
`Schema::hasTable()` issues a catalog query per group on every request and `DepartmentScope`
adds an employee lookup. Note `department_head` pays 26 queries to search **two** groups —
the per-request floor is not proportional to what the caller can see. The trigram/full-text
index and a production-sized plan remain deferred for the reasons already recorded
(`CREATE EXTENSION` rights, eleven tables owned by other modules, dev data with single-digit
row counts).

### M009-F14 — Incomplete: archived related records are still an index term — **now measured**

Priority: **P2** · Scope: **medium** · Session: **separate-recommended**

The prior report inferred this from the `leftJoin`s. It is now measured, and it is two
distinct behaviours, not one:

```
PO-GHOST     live parent found=YES  sublabel="Ghostvendor Polymers"   (vendor soft-deleted)
SO-GHOST     live parent found=YES  sublabel="Ghostcust Motors"       (customer soft-deleted)
INV-GHOST    live parent found=YES  sublabel="Ghostcust Motors"
WO-GHOST     live parent found=YES  sublabel="Ghostpart bushing"      (product soft-deleted)
Ghostvendor  searching an ARCHIVED relation name reaches the live purchase_order: YES
Ghostcust    searching an ARCHIVED relation name reaches the live sales_order:    YES
Ghostpart    searching an ARCHIVED relation name reaches the live work_order:     YES
```

1. An archived counterparty's **name is still rendered** as a live transaction's sublabel.
   Arguably correct — a PO's historical vendor is part of the record.
2. An archived counterparty's name is **still a searchable term**: typing an archived
   vendor's name reaches live POs. That contradicts `docs/USER-MANUAL.md`'s "no archived
   records" claim in spirit, since the archived name is still indexed, and it is the part
   that needs a product decision (see the question at the end of this section).

Joins are at `GlobalSearchService.php:180`, `:157`, `:220-221`, `:245`, `:272`; `Vendor` and
`Customer` both use `SoftDeletes` (`api/app/Modules/Accounting/Models/Vendor.php:13-17`,
`Customer.php:13-17`).

### M009-F16 — Polish: `SearchOperator` advertises cross-driver escaping it does not do on SQLite

Priority: **P3** · Scope: **small** · Session: **separate-recommended** · unchanged

`SearchOperator` presents itself as cross-driver (`api/app/Common/Support/SearchOperator.php:9-15`)
but `escape()` returns raw input on SQLite (`:56-58`), so `%`/`_` would be live wildcards
there. Not a production-path defect — app and `phpunit` both run on PostgreSQL, verified this
session — but a portability contract that would silently reintroduce F04.

### M009-F17 — Incomplete (NEW): search is broader than the PO list for `production_manager`

Priority: **P1** · Scope: **medium** · Session: **separate-recommended — cross-module**

`GlobalSearchService.php:187-202` states it re-expresses "the purchase-order list's row
scope (`PurchaseOrderService::list`) … through the shared helper", and the prior fix log
records that equivalence as "Verified equivalent against the seeds". **It is no longer
equivalent.** Search resolves the department tier from the *permission* `purchasing.pr.approve`;
`PurchaseOrderService::list()` resolves it from the *role slug* `department_head`
(`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:96-104`).

`production_manager` gained `purchasing.pr.approve` in a later change — it is step 2 of the
seeded `purchase_request` chain (`RolePermissionSeeder`, the M036 comment) — but is not
slugged `department_head`. Measured on one fixture: a PO **in the caller's department,
authored by another user**, searched and listed as the same role:

```
role                 pr.approve po.approve | in SEARCH | in PO LIST
department_head      Y          n          | YES       | YES        (aligned)
production_manager   Y          n          | YES       | no         ← search is broader
purchasing_officer   Y          Y          | HTTP 403  | YES        (no search.global)
impex_officer        n          n          | HTTP 403  | no
```

So global search hands `production_manager` a PO number, vendor name, status and **amount**
for a record its own list page hides — the exact defect class M009-F01 was opened to close,
reintroduced by drift in the seeder rather than in this file.

**The fix does not belong here.** No permission distinguishes `department_head` from
`production_manager`, so narrowing search to match the list would require the role-slug
branch CLAUDE.md forbids. The convention-compliant repair is for `PurchaseOrderService::list()`
to adopt `DepartmentScope` — which *widens the list to match search* and removes the
role-slug coupling. **Handed off to `procurement/purchase-orders`.** Until then, treat
`production_manager`'s PO reach as department-wide and reconcile the list, not search.

### M009-F18 — Incomplete (NEW): the palette's keyboard list is invisible to assistive tech

Priority: **P2** · Scope: **small/medium** · Session: **separate-recommended**

The palette is `role="dialog" aria-modal="true" aria-label="Global search"` with an
`aria-label`led input (`spa/src/components/ui/CommandPalette.tsx:440-442`, `:460`) — and
that is the entire ARIA surface. Arrow keys move `activeIndex`, which changes a **visual**
highlight (`bg-elevated`, `:556`) on a row that never receives DOM focus; focus stays in the
input. Consequently:

- no `role="listbox"`/`role="option"` on the `<ul>`/rows (`:543-584`);
- no `aria-activedescendant` on the input, so a screen reader announces nothing as the
  user arrows through results;
- no `role="combobox"`/`aria-expanded`/`aria-controls` on the input;
- no `aria-live` region, so "N results", "No results for …" (`:514-524`) and the failure
  block (`:480-512`) are silent.

This is the module's primary surface and its keyboard affordance is advertised in the
footer ("↑↓ navigate"). WCAG 4.1.2 / 1.3.1. Deliberately **not** fixed in this session: it
changes focus and announcement semantics in the same handler that `5cd6e7e5`/`44f6e1b3`
just landed and browser-verified, so it deserves its own browser run rather than riding
along on a backend fix.

### M009-F19 — Polish (NEW): `search.global` is withheld from the roles most likely to need it

Priority: **P3** · Scope: **small** · Session: **separate-recommended — question, not a defect**

Measured: four operational roles get **403 on every search** because they hold no
`search.global` — `purchasing_officer`, `qc_inspector`, `warehouse_staff`, `impex_officer`.
`purchasing_officer` in particular holds `purchasing.*`, `accounting.vendors.view` and
`accounting.bills.view`, i.e. it is the role with the most identifiers to look up (PO
numbers, vendor names) and the one that cannot look any of them up.

Conversely `maintenance_tech` **does** hold `search.global` (`RolePermissionSeeder.php:709`)
but holds none of the eleven gates, so its record search is permanently `data: []` while
the ⌘K trigger, the "type at least 2 characters to search records (SO-, PO-, WO-, INV-,
NCR-, any name)" hint (`CommandPalette.tsx:470-477`) and the "No results for …" empty state
all promise otherwise. Neither is a leak; both are grant-table shape, which is
`platform/rbac`'s to decide. **Question for a human, recorded below.**

### M009-F20 — Polish (NEW): the orphan `ogami-meili` container emits a warning on every command

Priority: **P3** · Scope: **small** · Session: **same-session-ok, but not this module's file**

`ogami-meili` is still labelled as belonging to this Compose project, so **every**
`docker compose …` invocation in this repo prints
`Found orphan containers ([ogami-meili]) for this project`. It appeared on all ~15
invocations this session. A warning that is always present is a warning nobody reads, and
this one sits in front of every developer command in the repo. One-line remedy
(`docker rm ogami-meili`), but it is host state rather than a repo file, so it is recorded
rather than executed here.

Related, and cheap: the module toggle's own label reads **"Full-text search across all
modules"** (`api/database/seeders/SettingsSeeder.php:436` region, `'search' => ['Global Search', …]`).
There is no full-text search — it is a literal `ILIKE` substring match, deliberately so
(F04). The Settings screen therefore describes a capability the system does not have.

## Verification

| Check | Result | Notes |
|---|---|---|
| Baseline `GlobalSearchTest` before any change | **PASS — 24 tests, 95 assertions** | `ogami_test_search` |
| `GlobalSearchTest` after the F09 fix | **PASS — 29 tests, 142 assertions** | +5 tests, +47 assertions |
| Red proof: 3 new feature-flag tests vs `git show HEAD:` source | **3 failed, 2 passed** | the 3 flag tests fail without the fix; the 2 matrix tests pass either way (they are a lock, not a repro) |
| Source restored after the red run | **PASS** | `sha256sum -c` OK + `diff -q` byte-identical |
| `php -l` service + test | **PASS** | |
| `phpstan analyse` (level per `phpstan.neon`) on both changed files | **PASS — no errors** | |
| `pint --test` on both changed files | **FAIL — inherited** | proven: see `fix-log.md`, rule-list comparison against the `HEAD` extract |
| Role × entity matrix, 13 roles × 11 entities, real HTTP | **PASS — 0 mismatches** | table above |
| Feature-flag bypass probe | **DEFECT REPRODUCED** | 11/11 groups returned with 7 flags off |
| Archived-relation probe | **DEFECT REPRODUCED** | table under F14 |
| PO search-vs-list window probe | **DEFECT REPRODUCED** | table under F17 |
| Query/latency measurement | **recorded** | 30 queries / 14 catalog probes for a system_admin |
| Meilisearch dependency audit | **PASS — no dependency** | no service, no package, no code reference |
| `git diff --check` | **PASS** | |
| SPA typecheck / vitest / ESLint | **NOT RUN — no SPA file was changed** | the fix is server-side only; see the declined item in `fix-log.md` |
| Chromium browser walk of the palette | **NOT RUN** | no SPA file changed, so there is nothing new to see. The existing `spa/e2e/command-palette.spec.ts` was verified against real Chromium + Firefox at `44f6e1b3` and is untouched. **No new visual or focus claim is made in this session.** |
| Production-sized query plan | **NOT RUN** | F13; dev data is single-digit rows per transaction table |

## Questions needing a human decision

1. **F14** — should an archived customer/vendor/product's *name* remain a searchable term
   that reaches its live transactions? Three options: keep it (historical accuracy), drop
   the joined predicate (the name stops being an index term but stays as a label), or drop
   both label and predicate. This is the owning modules' call, not search's.
2. **F19a** — is withholding `search.global` from `purchasing_officer`, `qc_inspector`,
   `warehouse_staff` and `impex_officer` intended, or an omission?
3. **F19b** — should `maintenance_tech` keep `search.global` with no searchable entity, or
   should the palette suppress the record-search affordance for a caller that holds no
   entity gate?
4. **F17** — confirm the intended PO visibility for `production_manager`: department-wide
   (adopt `DepartmentScope` in `PurchaseOrderService::list()`) or author-only (then the
   *permission* model needs a distinct grant, since the role slug cannot be used).

## What could NOT be verified

- **No browser/visual verification was performed this session.** `nginx` and `spa` are
  stopped and two other audit sessions share this host; I changed no SPA file, so I ran
  none and make no claim about rendering, layout or focus visibility.
- **Latency and query plans at production scale.** Every number above comes from a
  single-digit-row dev dataset; they bound nothing about production.
- **The `2026_08_*` migration set was not exercised beyond what `RefreshDatabase` runs**
  for this test path; no migration was added or renamed.
