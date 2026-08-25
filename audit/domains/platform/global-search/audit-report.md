# M009 — Global Search audit report

Audit date: 2026-08-24  
Claim: platform / global-search  
Registry tier: 4  
Status: 📋 Plan Ready  
Session recommendation: separate-recommended

## Verdict

Production-readiness score: **38/100 — not ready for an unqualified release**.

The module has a sound outer boundary: the endpoint requires Sanctum authentication, the search feature flag, the global-search permission, and a route throttle; the controller bounds the query; the SPA debounces requests and has a regression test for stale responses. The inner boundary is not production-safe. Per-group queries enforce only broad module permissions and bypass the row-level scopes used by the employee and purchase-order list/detail surfaces. View-only customer and vendor users can also receive raw TINs, which the SPA persists in browser localStorage as a recent-result sublabel. Direct table queries do not consistently exclude soft-deleted rows, and the UI has no error state or mobile search entry point.

The highest risks are authorization and sensitive-data disclosure. The majority of the remediation requires a shared visibility contract and negative security tests, so no production-code fix was applied in this audit session.

## Evidence checked

- Refreshed the module registry, atomically claimed M009, reviewed the existing dirty worktree, and confirmed the module scaffold was the only M009 audit surface.
- GlobalSearchService, SearchController, SearchOperator, Admin routes, feature middleware, session/password middleware, role/permission seed data, row-scoped EmployeeService and PurchaseOrderService paths, resources, models, migrations, hash-ID binding, SPA command palette, Topbar trigger, recent-items persistence, and existing CommandPalette tests.
- php artisan route:list --path=search — the global-search route was enumerated alongside the separate quality traceability search route.
- PHP syntax check passed for the matched M009 backend files.
- npm run typecheck in spa — passed.
- npm run lint in spa — passed.
- npx vitest run src/components/ui/CommandPalette.test.tsx — could not start because Vitest/Vite attempted to write under the root-owned spa/node_modules/.vite-temp directory and received EACCES.
- No M009-specific backend test file was found; the existing SPA suite covers debounce, minimum query length, and stale-response behavior only.
- No live SPA/API server was available for an authenticated browser audit. No production query plan or representative large-dataset benchmark was available.

## Strengths

- The API route requires auth:sanctum, feature:search, permission:search.global, and throttle:30,1 (api/app/Modules/Admin/routes.php:139-141). Session timeout, password-expiry, API throttling, and slow-query middleware are also appended globally to the API group (api/bootstrap/app.php:44-78).
- SearchController validates q as a required string from 2 through 120 characters before calling the service (api/app/Modules/Admin/Controllers/SearchController.php:16-24).
- Search results return hash IDs and hash-based URLs rather than raw numeric identifiers (api/app/Common/Services/GlobalSearchService.php:35,50-56,68-75,87-94,109-115,127-134,146-153,164-170,181-187,198-204,215-221,232-238).
- The SPA cancels/isolates superseded React Query requests through the query key and AbortSignal, debounces by 200ms, and does not query until two characters are present (spa/src/components/ui/CommandPalette.tsx:153-171). The existing test covers the stale-response regression plus minimum length and debounce behavior (spa/src/components/ui/CommandPalette.test.tsx:32-96).
- The recent-items store validates persisted data and caps the list at eight entries (spa/src/stores/recentItemsStore.ts:32-79).
- SQL values are parameter-bound through the query builder, so the wildcard issue below is not a direct SQL-injection finding. The problem is wildcard semantics and the resulting search breadth/performance.

## Findings

### M009-F01 — Broken/critical: global search bypasses row-level authorization

Priority: **P0**  
Scope: **medium**  
Recommendation: **separate-recommended**

GlobalSearchService checks only broad permissions before querying entire tables. The employee branch checks hr.employees.view and then uses an unscoped DB::table query (api/app/Common/Services/GlobalSearchService.php:38-56). The purchase-order branch does the same for purchasing.view (api/app/Common/Services/GlobalSearchService.php:78-94). The service accepts the acting User but never applies the visibility policy associated with that user.

The normal employee list applies DepartmentScope with hr.employees.view_sensitive for all-row access and hr.employees.view for department-plus-self access (api/app/Modules/HR/Services/EmployeeService.php:40-58); EmployeeController passes the request user into that service (api/app/Modules/HR/Controllers/EmployeeController.php:55-58). The normal purchase-order list applies a system-admin/approver/all, department-head/department, or creator-only scope and receives the request user (api/app/Modules/Purchasing/Services/PurchaseOrderService.php:61-119; api/app/Modules/Purchasing/Controllers/PurchaseOrderController.php:30-33). The seeded department_head role has hr.employees.view, purchasing.view, and search.global but not the employee view-all grant (api/database/seeders/RolePermissionSeeder.php:670-688).

Therefore a department head can search and receive employee names, employee numbers, departments, positions, statuses, and purchase orders belonging to other departments or users. Purchase-order detail routes require only purchasing.view and the controller does not apply the list scope to show (api/app/Modules/Purchasing/routes.php:56-71; api/app/Modules/Purchasing/Controllers/PurchaseOrderController.php:46-49), so a result can lead to a broader record disclosure than the search row itself. This is a server-side authorization bypass, not only a UI filtering defect.

Action: define one visibility contract per searchable resource and reuse the same user-aware scope for search, list, show, and linked routes. Do not duplicate role checks in GlobalSearchService. Add negative API tests with a department-head fixture proving cross-department employees and unrelated purchase orders are absent from both search and detail responses.

### M009-F02 — Broken/high: view-only users can receive raw customer and vendor TINs

Priority: **P1**  
Scope: **small-to-medium**  
Recommendation: **separate-recommended**

The customer and vendor search branches select tin and use it as the fallback sublabel when contact_person is empty (api/app/Common/Services/GlobalSearchService.php:190-221). The normal resources deliberately expose an unmasked TIN only to accounting.customers.manage or accounting.vendors.manage; view-only users receive a masked value (api/app/Modules/Accounting/Resources/CustomerResource.php:14-18,30-59; api/app/Modules/Accounting/Resources/VendorResource.php:12-16,21-45). The permission catalog distinguishes view from manage (api/database/seeders/RolePermissionSeeder.php:164-175).

This makes the global-search response a confidentiality bypass for any view-only user who searches a customer or vendor with no contact person. CommandPalette.pick() then stores the result sublabel in the persisted recent-items store (spa/src/components/ui/CommandPalette.tsx:282-294; spa/src/stores/recentItemsStore.ts:11-19,65-79), extending the exposure into browser localStorage.

Action: remove TIN from the global-search result contract or apply the same permission-aware masking policy used by the resources. Treat recent-result sublabels as sensitive-data-bearing and either redact them at the source or prohibit sensitive fields from persistence. Add view-only negative tests for both customer and vendor search and inspect localStorage in the browser test.

### M009-F03 — Broken/high: direct table queries can return archived records

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

GlobalSearchService uses DB::table for every record group and contains no deleted_at predicate or explicit archived-record policy (api/app/Common/Services/GlobalSearchService.php:38-239). Several searched models use SoftDeletes, including Employee, SalesOrder, PurchaseOrder, and WorkOrder (api/app/Modules/HR/Models/Employee.php:20-24; api/app/Modules/CRM/Models/SalesOrder.php:20-24; api/app/Modules/Purchasing/Models/PurchaseOrder.php:21-25; api/app/Modules/Production/Models/WorkOrder.php:22-26). Customers, vendors, products, and inventory items also declare soft deletes in their table migrations (api/database/migrations/0043_create_vendors_table.php:23-29; api/database/migrations/0047_create_customers_table.php:23-29; api/database/migrations/0052_create_items_table.php:28-36; api/database/migrations/0444_add_soft_deletes_to_all_tables.php:29-39,65-71). The ordinary list services explicitly use TrashedFilter, whose default path leaves archived rows excluded by Eloquent and provides deliberate only/with modes (api/app/Common/Support/TrashedFilter.php:15-26; api/app/Modules/Accounting/Services/CustomerService.php:22-40; api/app/Modules/Accounting/Services/VendorService.php:21-39; api/app/Modules/CRM/Services/ProductService.php:16-46; api/app/Modules/Inventory/Services/ItemService.php:19-49; api/app/Modules/Production/Services/WorkOrderService.php:90-123).

The search can consequently surface records that normal lists hide. Hash-ID route binding also has an explicit with-trashed path for routes that opt in (api/app/Common/Traits/HasHashId.php:45-68), so the result behavior is inconsistent rather than safely guaranteed to be a dead link.

Action: use model queries or add explicit deleted_at is null predicates to every searchable source, then define an administrator-only archived-search policy if required. Add fixtures for soft-deleted employees, orders, customers, vendors, products, and items and assert the default search excludes them.

### M009-F04 — Operational risk: wildcard input and fan-out are unbounded for the search contract

Priority: **P2**  
Scope: **medium**  
Recommendation: **separate-recommended**

The service wraps the trimmed user input directly in %...% and passes it to ILIKE/LIKE (api/app/Common/Services/GlobalSearchService.php:30-34; api/app/Common/Support/SearchOperator.php:25-30). SanitizeInput trims and strips HTML but does not escape SQL wildcard characters (api/app/Common/Middleware/SanitizeInput.php:31-53), so % and _ supplied by a user retain wildcard meaning. The leading wildcard also prevents ordinary B-tree prefix use. Each term can execute up to eleven sequential source queries, each with joins or text predicates and no documented query budget (api/app/Common/Services/GlobalSearchService.php:38-239). The migrations show indexes for status, foreign keys, dates, names, and unique identifiers but no full-text, trigram, or equivalent search index contract for these broad predicates (api/database/migrations/0016_create_employees_table.php:64-72; api/database/migrations/0043_create_vendors_table.php:23-29; api/database/migrations/0047_create_customers_table.php:23-29; api/database/migrations/0060_create_purchase_orders_table.php:32-39; api/database/migrations/0071_create_sales_orders_table.php:40-46).

The route throttle limits requests but does not establish a latency or database-load budget, and the React Query configuration does not explicitly disable retries for this endpoint (spa/src/components/ui/CommandPalette.tsx:156-166).

Action: choose and document the search syntax, escape wildcard characters when literal matching is intended, add exact/prefix ranking or a supported full-text/trigram strategy, and measure the worst-case fan-out with representative data. Add a per-request query/latency budget and explicit retry behavior for rate-limit or server errors.

### M009-F05 — Incomplete: result selection is nondeterministic and not relevance-ranked

Priority: **P2**  
Scope: **small-to-medium**  
Recommendation: **same-session only after authorization fixes**

Every source query applies limit(perGroup) without an orderBy (api/app/Common/Services/GlobalSearchService.php:40-49,61-67,80-86,99-108,120-126,139-145,158-163,175-180,192-197,209-214,226-231). The first five rows can therefore vary by query plan and may omit an exact identifier match when more rows qualify. The corresponding module list services deliberately order their results, for example customers/vendors/products by name and sales/purchase/work orders by business date or priority (api/app/Modules/Accounting/Services/CustomerService.php:39-40; api/app/Modules/CRM/Services/ProductService.php:45-46; api/app/Modules/CRM/Services/SalesOrderService.php:144-146; api/app/Modules/Purchasing/Services/PurchaseOrderService.php:118-119; api/app/Modules/Production/Services/WorkOrderService.php:121-123).

Action: add deterministic per-group ordering and relevance tiers: exact identifier, prefix identifier, exact name, prefix name, then contains match. Test that an exact record is stable and preferred.

### M009-F06 — Incomplete: search failures render as an empty or stale result state

Priority: **P2**  
Scope: **small**  
Recommendation: **same-session only after security fixes**

CommandPalette reads only data and isFetching from useQuery; it does not inspect isError or error (spa/src/components/ui/CommandPalette.tsx:156-171). The empty state is shown only when searching, not loading, and sections are empty (spa/src/components/ui/CommandPalette.tsx:327-383). A 403, 429, or 5xx can therefore look like “No results,” while placeholderData can retain the previous term's rows during a failed transition. There is no retry or rate-limit guidance in the palette.

Action: model loading, error, empty, and stale-result states separately; clear or label stale rows after a failed term; provide a retry action and distinguish permission-disabled, throttled, and server-error responses. Add tests for 403, 429, and 500 responses.

### M009-F07 — Missing: mobile has no visible search entry point

Priority: **P2**  
Scope: **small**  
Recommendation: **same-session only after security fixes**

The only visible Topbar search trigger is hidden below the sm breakpoint (spa/src/components/layout/Topbar.tsx:84-93). The other entry point is a Cmd/Ctrl+K document listener (spa/src/components/layout/Topbar.tsx:48-58), which is not a practical mobile interaction. No mobile menu search action or other palette opener was found. The module is therefore unavailable to touch users on narrow screens despite the manual describing global search as a general feature (docs/USER-MANUAL.md:281-282).

Action: add a mobile-visible search button or a sidebar/menu action, preserve focus restoration to the opener, and add a narrow-viewport browser test.

### M009-F08 — Missing: negative security and failure-path coverage does not match the module risk

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The only module-specific tests found are three CommandPalette tests for stale responses, minimum length, and debounce (spa/src/components/ui/CommandPalette.test.tsx:32-96). No backend M009 tests cover route denial, feature-off behavior, role-specific row scope, soft-deleted records, TIN masking, wildcard input, deterministic ordering, or per-group result contracts. The failed Vitest startup also means the existing UI tests were not executable in this environment.

Action: add authenticated backend tests for every searchable group, with special negative cases for department_head employee/PO scope and view-only TIN masking. Add SPA tests for permission/feature states, error handling, mobile opening, localStorage redaction, and deterministic row rendering. Run them against a writable dependency cache and a live API/SPA smoke path.

## Production-audit assessment

### Blockers / high-value risks

1. M009-F01 allows department-scoped users to enumerate and, for purchase orders, open records outside their authorized row set.
2. M009-F02 exposes raw customer/vendor TINs through a view-level search permission and persists them in browser storage.
3. M009-F03 can make archived records searchable despite normal module lists excluding them.
4. M009-F08 leaves the highest-risk authorization and confidentiality paths without negative regression coverage.

### Evidence still missing

- A live API test with department-head fixtures proving cross-department employee and purchase-order records are absent from search and show.
- Soft-deleted fixtures for every searchable SoftDeletes model and an explicit archived-search policy.
- View-only customer/vendor fixtures with empty contact_person values proving TIN redaction in API responses and recent-items localStorage.
- Query-plan and latency measurements for worst-case wildcard terms on representative production-sized data.
- Authenticated browser coverage for mobile opening, error/retry behavior, feature-off/permission-denied states, and result navigation.

### Next action

Do not promote M009 as complete. In a separate hardening session, first centralize the user-aware visibility contract and remove the TIN exposure, then add soft-delete filtering and negative security tests. Only after those controls are proven should the team optimize relevance/query cost and complete mobile/error-state polish.
