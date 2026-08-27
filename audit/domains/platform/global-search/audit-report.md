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
