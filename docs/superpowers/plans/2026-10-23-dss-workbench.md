# DSS Workbench Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a role-scoped Decision Center overview, server-filtered recommendation table, and URL-backed responsive detail panel using existing DSS actions and R1 presentation.

**Architecture:** Keep one authorized active-row query in `RecommendationService` and use it for the list, summary counts, and filter options. Apply business filters, search, and allow-listed sorting in SQL before pagination. Keep SPA orchestration in the existing page, reuse shared `FilterBar`, `DataTable`, `Panel`, and render both panel and direct detail through one recommendation view using R1's `decisionView` and `DecisionEvidence` exports.

**Tech Stack:** Laravel, PostgreSQL JSONB, React, TypeScript, React Query, React Router, Atelier UI, PHPUnit, Vitest, Testing Library, and Playwright/browser review.

---

## Scope and constraints

Read `../specs/2026-10-23-dss-system-design.md`, R2 in `2026-10-23-dss-system-roadmap.md`, and R1's `2026-10-23-dss-readable-decisions.md`. R2 follows R1. Re-read the current diff at execution; the R1 page may have changed. Preserve R1's decision wording, evidence bounds, current action behavior, coverage-health messaging, retry states, and API contract.

R2 only lists the current active scope: open rows and snoozed rows whose snooze time has elapsed, after expiry and latest-run freshness rules. Do not add in-progress, resolution, or history states before R3. Summary reports recommendation counts only: total and counts by severity. Do not total impact estimates, count commitments, or infer verified exposure. Search only explicit human-facing scalar facts and bounded document references listed below. Do not search raw JSON text, detector identifiers, internal class names, or unrelated payload fields.

Current authorization is `dss.view`, active advisory detector, detector owner permission, and recommendation owner role/permission fields. It does not authorize joined source records. R2 must not add source joins or source-derived counts. Derive domains and owner-role dropdown values from the same unfiltered authorized active recommendation scope. Reject submitted domain or owner-role filters absent from those scoped options. Do not read technical `DssCoverage` detector IDs for business filter options. Never display raw domain or role slugs as labels; if a role no longer has a display name, label it `Responsible team`.

No migration or dependency is needed. Keep R3 freshness semantics unchanged; current query uses `updated_at` to hide rows after detector runs. `SearchOperator::contains()` escapes `%`, `_`, and backslash on PostgreSQL/MySQL. PostgreSQL JSONB search must guard malformed array and element shapes before expanding `sample_documents`.

## File map

| File | Responsibility |
|---|---|
| `api/app/Modules/DSS/Services/RecommendationService.php` | One reusable authorized active query; filtered list, summary, and options derived from it |
| `api/app/Modules/DSS/Requests/ListRecommendationsRequest.php` | Validate supported filters, ordering, and page size; reject out-of-scope domain/owner options |
| `api/app/Modules/DSS/Controllers/RecommendationController.php` | List, summary, options response handlers |
| `api/app/Modules/DSS/Resources/RecommendationResource.php` | Add optional `owner_role_slugs` data for matching rows to authorized display options |
| `api/app/Modules/DSS/routes.php` | Static summary/options routes before `{recommendation}` |
| `api/tests/Feature/DSS/RecommendationScopingTest.php` | Extend existing role, permission, detector-mode, freshness, and hidden-row coverage |
| `api/tests/Feature/DSS/RecommendationQueryTest.php` | Query/search/sort/pagination and malformed JSONB cases |
| `spa/src/types/dss.ts` | Filter, summary, option, and recommendation response types; reuse existing paginator shape |
| `spa/src/api/dss.ts` | Typed `summary` and `options` clients |
| `spa/src/pages/decision-center/index.tsx` | URL-backed filters, table, summary, selection/panel orchestration; preserve R1 page behaviors |
| `spa/src/pages/decision-center/RecommendationDetails.tsx` | Shared recommendation content/actions for side panel and direct-detail route |
| `spa/src/pages/decision-center/detail.tsx` | Fetch direct ID and render shared detail content with current retry/back behavior |
| `spa/src/pages/decision-center/index.test.tsx` | URL controls, filters, sorting, panel, errors, empty state, and retained action coverage |
| `spa/src/pages/decision-center/detail.test.tsx` | Direct route renders same shared R1 evidence and current lifecycle actions |
| `spa/src/pages/decision-center/RecommendationDetails.test.tsx` | Presentation and action parity in the shared detail surface |

Use current R1 exports from `spa/src/pages/decision-center/presentation.ts` (`decisionView`, `DecisionView`) and `DecisionEvidence.tsx`. Do not copy the family titles, labels, evidence parsing, or status labels into R2. Use the existing `recommendationFixture` in `test-fixtures.ts` and existing `index.test.tsx` QueryClient/MemoryRouter setup.

## Task 1: Add validated server filters and shared query scope

**Files:**

- Modify `api/app/Modules/DSS/Services/RecommendationService.php`
- Modify `api/app/Modules/DSS/Requests/ListRecommendationsRequest.php`
- Extend `api/tests/Feature/DSS/RecommendationScopingTest.php`
- Create `api/tests/Feature/DSS/RecommendationQueryTest.php`

- [ ] **Step 1: Add failing scope and validation tests.**

Extend `RecommendationScopingTest` with these cases using its existing `userWithPermissions()` and `makeRecommendation()` helpers:

1. A viewer with `dss.view` sees only rows whose detector and stored owner permission/role allow the viewer. Assert `meta.total` and returned IDs, as existing tests do.
2. A hidden restricted row with `priority_score = 999` and `rationale.customer = "Hidden Customer"` does not change filtered list results or totals.
3. Invalid `status`, `sort`, `direction`, `page`, and `per_page` return 422. `page=0`, `per_page=0`, and `per_page=101` fail validation.

Assert list resources include optional `owner_role_slugs` only for roles stored on the visible recommendation. Treat malformed/non-list JSON values and non-string array items as an empty list; do not expose `owner_permissions`, detector IDs, or internal role metadata as display values. These slugs are matching data only; never render one directly.

Create `RecommendationQueryTest` with 41 active recommendations assigned across at least two domains, three severities, two owner roles, and explicit searchable facts. Use a `system_admin` viewer for the cross-role dataset so stored owner roles do not correctly hide rows from a non-admin viewer. Assert `per_page=20` returns metadata totals of 41 and page 3 contains one row. A literal `%_` query must match those characters, not act as wildcards. Add search fixtures for each accepted scalar key and one matching `sample_documents.document`; include malformed scalar values (`customer` as object/array/number) and malformed `sample_documents` values `null`, object, string, mixed array elements, and object elements without `document`. Each malformed case must return safely without a PostgreSQL JSONB error and must not match nested object text.

- [ ] **Step 2: Run focused backend tests and confirm failures.**

Run from `api/`:

~~~bash
php artisan test --compact tests/Feature/DSS/RecommendationScopingTest.php tests/Feature/DSS/RecommendationQueryTest.php
~~~

Expected: new query behavior is missing; unrelated existing DSS scoping tests continue to pass.

- [ ] **Step 3: Extract the current query construction into one service method.**

In `RecommendationService`, move the detector visibility, active status, expiry, freshness, owner-permission, and owner-role clauses currently at the top of `list()` into `private function activeQuery(User $user): Builder`. Preserve those SQL conditions and the `system_admin` exception exactly. Add `private function filteredQuery(User $user, array $filters): Builder`; start with `activeQuery($user)`, then apply the validated `severity`, `domain`, `status`, `owner_role`, and `search` filters. `list()`, `summary()`, and `options()` must all derive from these methods; options must use `activeQuery()` directly, not `filteredQuery()`.

Implement `list()` ordering with this allowlist and stable tie-breaker:

~~~php
$sortColumn = [
    'priority_score' => 'priority_score',
    'created_at' => 'created_at',
][$filters['sort'] ?? 'priority_score'];
$direction = $filters['direction'] ?? 'desc';
$query->orderBy($sortColumn, $direction)->orderBy('id');
~~~

Keep `page` and `per_page` out of summary filtering. Use validated `page` and `per_page`, defaulting to page 1 and 20; pass the validated page explicitly to `paginate($perPage, ['*'], 'page', $page)` instead of letting Laravel read the raw request value.

Use only the selected search keys `customer`, `work_order`, `ncr_number`, `training`, `rule`, plus sample document values. Bind all user values and use the existing pattern helper:

~~~php
private function applyBusinessSearch(Builder $query, string $search): void
{
    $pattern = SearchOperator::contains($search);
    $operator = SearchOperator::like();

    $query->where(function (Builder $matches) use ($pattern, $operator): void {
        foreach (['customer', 'work_order', 'ncr_number', 'training', 'rule'] as $key) {
            $matches->orWhereRaw(
                "jsonb_typeof(rationale->?) = 'string' AND rationale->>? {$operator} ?",
                [$key, $key, $pattern],
            );
        }

        $matches->orWhereRaw(
            "EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(rationale->'sample_documents') = 'array' THEN rationale->'sample_documents' ELSE '[]'::jsonb END) AS sample(value) WHERE jsonb_typeof(sample.value) = 'object' AND jsonb_typeof(sample.value->'document') = 'string' AND sample.value->>'document' {$operator} ?)",
            [$pattern],
        );
    });
}
~~~

Import `App\Common\Support\SearchOperator`. The scalar search checks that each selected JSON value is a string; nested objects and arrays are never serialized into a searchable string. `jsonb_array_elements` receives `[]` for any non-array value; non-object entries and non-string documents are ignored. Keep the explicit human-fact allowlist bounded to those five scalar keys and document references.

- [ ] **Step 4: Validate request filters and reject inaccessible options.**

In `ListRecommendationsRequest`, validate:

~~~php
'search' => ['nullable', 'string', 'max:120'],
'severity' => ['nullable', Rule::in(['critical', 'warning', 'info'])],
'status' => ['nullable', Rule::in(['open', 'snoozed'])],
'domain' => ['nullable', 'string', 'max:50'],
'owner_role' => ['nullable', 'string', 'max:80'],
'sort' => ['nullable', Rule::in(['priority_score', 'created_at'])],
'direction' => ['nullable', Rule::in(['asc', 'desc'])],
'page' => ['nullable', 'integer', 'min:1'],
'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
~~~

Preserve `detector_id` only if existing callers rely on it; do not show it in the business filter bar. If retained, validate it against the same active authorized detector set. In Task 2, add this `FormRequest::after()` validator so inaccessible choices produce ordinary Laravel field errors:

~~~php
public function after(): array
{
    return [function (Validator $validator): void {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $options = app(RecommendationService::class)->options($this->user());
        foreach ([
            'domain' => array_column($options['domains'], 'value'),
            'owner_role' => array_column($options['owner_roles'], 'value'),
        ] as $field => $allowed) {
            $value = $this->input($field);
            if ($value !== null && ! in_array($value, $allowed, true)) {
                $validator->errors()->add($field, 'The selected value is unavailable.');
            }
        }
    }];
}
~~~

Add optional `owner_role_slugs` in `RecommendationResource::toArray()`. Use `array_is_list()` and filter values by `is_string()`; malformed/object-shaped JSON returns `null`, not coerced display strings. Add an API resource assertion to `RecommendationQueryTest`.

- [ ] **Step 5: Run backend query/scoping tests.**

Run:

~~~bash
php artisan test --compact tests/Feature/DSS/RecommendationScopingTest.php tests/Feature/DSS/RecommendationQueryTest.php
~~~

Expected: all tests pass; hidden records do not affect page metadata, filtered rows, counts, or options.

## Task 2: Add scoped summary and filter options endpoints

**Files:**

- Modify `api/app/Modules/DSS/Services/RecommendationService.php`
- Modify `api/app/Modules/DSS/Controllers/RecommendationController.php`
- Modify `api/app/Modules/DSS/routes.php`
- Extend `api/tests/Feature/DSS/RecommendationScopingTest.php`
- Extend `api/tests/Feature/DSS/RecommendationQueryTest.php`

- [ ] **Step 1: Specify endpoint response tests.**

`GET /api/v1/dss/recommendations/summary` returns a JSON data envelope:

~~~json
{
  "data": {
    "total": 41,
    "by_severity": { "critical": 10, "warning": 20, "info": 11 }
  }
}
~~~

Apply the selected severity, status, domain, owner role, and search filters, but ignore pagination. Assert total and each severity count match the complete filtered set, including when results span multiple pages. Assert a hidden record contributes no count.

For a `system_admin` viewer, assert options include values and human labels from the visible active rows, even when one detector/role is hidden to a non-admin. For a non-admin viewer, assert restricted, shadow, disabled, expired, stale, and not-yet-due snoozed records contribute no domain or owner-role options. Assert options do not change when the request is filtered by another domain. A submitted hidden or stale domain/owner role returns 422 with a generic validation error; the response must not disclose the hidden value.

`GET /api/v1/dss/recommendations/options` returns:

~~~json
{
  "data": {
    "domains": [{ "value": "production", "label": "Production" }],
    "owner_roles": [{ "value": "production_manager", "label": "Production manager" }],
    "statuses": [
      { "value": "open", "label": "New" },
      { "value": "snoozed", "label": "Follow-up due" }
    ],
    "severities": [
      { "value": "critical", "label": "Critical" },
      { "value": "warning", "label": "Warning" },
      { "value": "info", "label": "Information" }
    ]
  }
}
~~~

Derive `domains` and `owner_roles` from the unfiltered authorized active query; options must not shrink when another filter is active. Label domains with this explicit map in `RecommendationService`: `cross_module` → `Connected workflows`, `approvals` → `Approvals`, `production` → `Production`, `accounting` → `Finance`, `quality` → `Quality`, `maintenance` → `Maintenance`, `payroll` → `Payroll`, `human_resources` → `People and training`, `platform` → `Master data`; use `Business operations` for an unknown value. Resolve role labels from the `roles` table; if a stored slug has no display name, use `Owner not recorded`, never the slug. The SPA renders API labels as-is. Never expose roles/domains that only occur on hidden, stale, disabled, expired, or shadow rows. Status/severity choices are static supported enums, not data counts. The `snoozed` option means only a snooze whose deadline has elapsed, because future snoozes remain outside the current list query; label it `Follow-up due`.

- [ ] **Step 2: Run the new tests and confirm endpoint failures.**

Run the focused API tests from Task 1. Expected: summary/options routes return 404 until implemented.

- [ ] **Step 3: Add summary and options service methods.**

Add `summary(User $user, array $filters): array` from `filteredQuery($user, $filters)`. Return integer `total`, `critical`, `warning`, and `info`; group the latter counts in `by_severity`. Use SQL aggregates so summary does not load result rows. Never aggregate `impact_estimate`.

Add `options(User $user): array` from `activeQuery($user)`, with no user filters applied. Select distinct visible `domain` and `owner_role_slugs` values only. Validate each role-slug JSON value as an array of strings before building the unique list; resolve display names in one role query. Return only the shape in Step 1.

- [ ] **Step 4: Add controller handlers and order routes before the dynamic ID route.**

Controller signatures:

~~~php
public function summary(ListRecommendationsRequest $request): JsonResponse
public function options(Request $request): JsonResponse
~~~

Use the same list request for `summary`, so its filters and inaccessible-choice rejection match `index`. `options` only requires `permission:dss.view`, returns the authorized unfiltered options shape, and does not accept filters. Add routes in this exact order inside the existing authenticated `dss` group:

~~~php
Route::get('/recommendations/summary', [RecommendationController::class, 'summary'])
    ->middleware('permission:dss.view');
Route::get('/recommendations/options', [RecommendationController::class, 'options'])
    ->middleware('permission:dss.view');
Route::get('/recommendations', [RecommendationController::class, 'index'])
    ->middleware('permission:dss.view');
Route::get('/recommendations/{recommendation}', [RecommendationController::class, 'show'])
    ->middleware('permission:dss.view');
~~~

Static routes must precede `/{recommendation}` so `summary` and `options` are not treated as model IDs.

- [ ] **Step 5: Run focused backend tests.**

Run:

~~~bash
php artisan test --compact tests/Feature/DSS/RecommendationScopingTest.php tests/Feature/DSS/RecommendationQueryTest.php
~~~

Expected: list, summary, options, and detail keep matching authorization; page-1 totals and full-result summary agree.

## Task 3: Type and connect the scoped API in the SPA

**Files:**

- Modify `spa/src/types/dss.ts`
- Modify `spa/src/api/dss.ts`
- Extend `spa/src/pages/decision-center/index.test.tsx`

- [ ] **Step 1: Add API client/type tests.**

Test the page using mocks for `dssApi.list`, `dssApi.summary`, `dssApi.options`, and `dssApi.coverage`. Assert list and summary receive the same filters except `page`/`per_page`; options receives no filters. Keep the current coverage failure/retry assertions.

- [ ] **Step 2: Add exact response and query types.**

In `types/dss.ts`, extend `RecommendationListParams` with:

~~~ts
search?: string;
status?: 'open' | 'snoozed';
owner_role?: string;
sort?: 'priority_score' | 'created_at';
direction?: 'asc' | 'desc';
~~~

Keep `severity`, `domain`, `page`, and `per_page`. Use shared `PaginationMeta` from `@/types` in `PaginatedRecommendations` instead of maintaining a second incompatible copy; verify it retains `from` and `to` as nullable numbers.

Add:

~~~ts
export interface DssOption {
  value: string;
  label: string;
}

export interface DssRecommendationOptions {
  domains: DssOption[];
  owner_roles: DssOption[];
  statuses: DssOption[];
  severities: DssOption[];
}

export interface DssRecommendationSummary {
  total: number;
  by_severity: { critical: number; warning: number; info: number };
}
~~~

Extend `Recommendation` with optional `owner_role_slugs?: string[]`; keep it optional for old cached/API fixtures. Match values through the authorized `options.owner_roles` value-to-label map, with no per-row role queries. A null/empty owner scope or missing label renders `Owner not recorded`; never render a raw slug. R2 does not assign owners; existing Action Center owns task assignment.

Implement `dssApi.summary(params)` and `dssApi.options()` using `client.get<{ data: DssRecommendationSummary }>` and `client.get<{ data: DssRecommendationOptions }>` at `/dss/recommendations/summary` and `/dss/recommendations/options`.

- [ ] **Step 3: Run focused SPA typecheck and test.**

From `spa/`, run:

~~~bash
npm run test -- --run src/pages/decision-center/index.test.tsx
npm run typecheck
~~~

Expected: client types compile and existing coverage-health/error assertions remain intact.

## Task 4: Replace the current card composition with URL-backed table and panel

**Files:**

- Create `spa/src/pages/decision-center/RecommendationDetails.tsx`
- Modify `spa/src/pages/decision-center/index.tsx`
- Modify `spa/src/pages/decision-center/detail.tsx`
- Extend `spa/src/pages/decision-center/index.test.tsx`
- Modify/create `spa/src/pages/decision-center/detail.test.tsx`
- Create `spa/src/pages/decision-center/RecommendationDetails.test.tsx`

- [ ] **Step 1: Add failing URL and shared-details tests.**

In `index.test.tsx`, use `MemoryRouter` with `initialEntries` and a location probe. Verify initial `search`, `severity`, `domain`, `owner_role`, `status`, `sort`, `direction`, `page`, `per_page`, and selected `id` are restored from the URL and are sent to list/summary. Verify changing a filter, search value, or sort resets page to 1. Verify changing page size also resets page. Verify row selection writes `id`; closing the panel removes only `id`, preserving all list query parameters; browser back restores the previous selected ID and filters. Use debounced `FilterBar` behavior with fake timers or `userEvent` and wait for the 300 ms debounce.

Add assertions that a row's business title, area, summary, severity, evidence label, facts, and bounded tables come from `decisionView(rec)` and `<DecisionEvidence>`; no raw rationale serialization or detector ID appears. Assert the panel and `/decision-center/:id` use the same title/evidence and expose the same existing workflow and recommendation actions. Keep existing failed-list retry, coverage-health retry, empty/no-results, and action mutation tests; update assertions to R1 business copy rather than removing these behaviors.

When options load successfully, check any URL `domain` and `owner_role` against returned option values before enabling list/summary queries. If a value disappeared from the current authorized active scope, remove only that filter, reset page to 1, and show a status message that the unavailable filter was cleared. If options fail, show retry and a clear-filters action; do not issue a list request with stale scoped filters or leave a stale selection silently applied.

- [ ] **Step 2: Implement shared details using the R1 exports.**

Create `RecommendationDetails.tsx`. Its props take `recommendation`, optional panel close callback, and existing navigation callback or router context. Compute `const view = decisionView(recommendation)` for title and area, and render `<DecisionEvidence recommendation={recommendation} />` for summary, evidence label, facts, and tables; R1's component computes the same `decisionView` internally. Keep source actions from `recommendation.action` and current lifecycle action semantics, mutation error reporting, toast messages, and outcome form. Move existing direct-detail action handlers into this component so panel and direct route share one implementation. Do not reintroduce `rationaleEntries`, `JSON.stringify`, confidence percentages, or internal detector metadata.

- [ ] **Step 3: Replace index state with typed URL search params.**

Use `useSearchParams()` from React Router as the source of truth for the supported `RecommendationListParams` plus selected `id`. Parse integer values with safe defaults (`page=1`, `per_page=20`) and accept only API enum values. Omit empty/default query entries when serializing. Preserve unknown URL query keys only if the existing route owns them; otherwise remove invalid DSS keys from rendered requests. Keep `id` separate from list filters when serializing so filter changes never erase the selection accidentally. Use history entries for filter/page/sort and selection changes; use replacement for repeated search debounce updates when the same text input is still active, so browser Back restores useful view states instead of each typed character.

Use `FilterBar` for search, status, severity, domain, and owner-role dropdowns from `DssRecommendationOptions`. Use `DataTable` with columns `Priority`, `Decision`, `Business impact`, `Responsible team`, `Business deadline or age`, `Evidence freshness`, and `Next step` where data is actually available from R1's `decisionView` and existing recommendation fields. For owner, map resource `owner_role_slugs` through `options.owner_roles`; for business impact, use the existing `formatImpact` helper only when the R1 patch retains it; for age, use a matching R1 business fact such as `Reported age (hours)` or `Days overdue`. Do not invent deadline, owner, or freshness fields: render `Not available` or omit a column whose data contract is absent. Domain/role filters must show server option labels, not raw internal domain/role slugs. Do not add assignment actions. `DataTable` already supports sortable headers, highlighted row ID, keyboard row activation, pagination metadata, and table preferences.

Fetch the list with all URL filters. Fetch summary with the same filters after removing `page` and `per_page`; render its authorized count as “Current view” and show total/critical/warning/info only. Fetch options independently without current filters. Keep `PageHeader`, coverage-health unavailable/retry messaging, loading/error/empty states, and refresh action.

On wide viewports, show the selected recommendation in a `Panel` beside the table. Selection is a URL `id`; closing/back removes selection without resetting list state. On narrow viewports, selecting a recommendation navigates to `/decision-center/:id` carrying the list query string; the direct route back link returns to `/decision-center` with that query string. Preserve an explicit focusable detail link in each row, even when row keyboard activation also opens the panel. Restore focus to the activating row/link when closing the panel.

- [ ] **Step 4: Make direct detail use the shared detail component.**

Keep its route and permission guard in `spa/src/routes/adminRoutes.tsx` unchanged. Keep `dssApi.show(id)`, React Query key, loading skeleton, failed-detail retry, mutation invalidation, and back navigation semantics. Pass the loaded recommendation to `RecommendationDetails`. Set its back destination to the list URL from `location.search`; never drop active search/filter/page/sort values.

- [ ] **Step 5: Run focused SPA checks.**

From `spa/`, run:

~~~bash
npm run test -- --run src/pages/decision-center/index.test.tsx src/pages/decision-center/detail.test.tsx src/pages/decision-center/RecommendationDetails.test.tsx
npm run typecheck
~~~

Expected: URL round trips, filters, sorting, selection, shared presentation, detail retry, and current recommendation actions pass.

## Task 5: Verify full-result scoping, responsive behavior, and accessibility

**Files:**

- No new production files; adjust only the files above if a failing acceptance check identifies a defect.
- Record review evidence in `docs/dss/PROGRESS.md` only if the R2 implementation task also explicitly owns progress tracking.

- [ ] **Step 1: Run the required backend and SPA checks.**

From `api/`:

~~~bash
php artisan test --compact tests/Feature/DSS/RecommendationScopingTest.php tests/Feature/DSS/RecommendationQueryTest.php
~~~

From `spa/`:

~~~bash
npm run test -- --run src/pages/decision-center
npm run typecheck
~~~

Expected: all focused checks pass. Check PostgreSQL JSONB malformed-shape test against PostgreSQL, not only SQLite.

- [ ] **Step 2: Review desktop and narrow layouts.**

At 1440px, verify table and side panel both fit, selecting a row keeps the list state, and filters/sort/page apply across the full result set. At 390px, verify the selected row opens the full detail route and the evidence tables remain reachable through horizontal scrolling where needed. Test dark theme, loading, list failure/retry, coverage failure/retry, no matches, and stale/empty result states.

- [ ] **Step 3: Review keyboard and browser navigation.**

Use only keyboard to search, select each dropdown, sort a column, activate a row, open its explicit detail link, close/back, and reach all detail actions. Confirm visible focus, `aria-sort`, focus restoration on panel close, and browser back/forward restoring filters, pagination, and selection.

- [ ] **Step 4: Confirm release boundary.**

Verify no in-progress, resolved, or history state is presented. Verify no estimated impacts are totaled, no hidden recommendation affects overview/options, no source record is joined without a source authorization check, and unknown R1 evidence stays in R1's safe unavailable presentation.

## Acceptance

- List, summary, and options share the same authorized active recommendation scope.
- Search is SQL-side, allow-listed, literal for LIKE metacharacters, and safe on malformed PostgreSQL JSONB shapes.
- Summary ignores pagination but applies all active filters; it reports recommendation counts only.
- Domain and owner-role options derive from unfiltered authorized active rows; inaccessible submitted values return 422.
- Sorting is SQL-side, allow-listed to priority score or created time, and stable by ID.
- Page and per-page are validated, and totals are correct beyond page one.
- URL owns filters, search, sort, direction, page size, page, and selected recommendation; browser navigation restores state.
- Desktop table/panel and mobile detail route share R1 titles and evidence; existing action, retry, coverage-health, and empty states remain useful.
- No new dependency, migration, source join, unsupported metric, or lifecycle state.

The R2 implementation is complete when the focused API and SPA checks pass and desktop/mobile/keyboard review confirms these acceptance points. This plan does not implement R3 history, follow-up, source verification, or lifecycle changes.
