# Ogami ERP — Full-System Audit and Defense Readiness

**Audit dates:** 2026-07-27 to 2026-07-30
**Scope:** backend and SPA code, database migrations, dependency security,
authentication and authorization, file lifecycle, exports, seeded demo state,
automated tests, container packaging, production orchestration, and a live Chrome
walkthrough against the Docker stack

## Verdict

The audited system has no known broken core workflow and is suitable for a
rehearsed adviser demo after the remaining operational items below are accepted.
The audit did not merely confirm existing tests: it found and repaired defects
in dependency wiring, transactional file handling, exports, scheduled jobs,
production packaging, and seeded/live integrations.

## Defects repaired

1. **Realtime authorization:** Echo used cookie credentials without sending the
   CSRF header, producing 419 responses. After fixing that, live testing exposed
   a second defect: the private user channel expected an integer while the SPA
   correctly used a HashID, producing 500 responses. Authorization and emitted
   channel names now consistently use HashIDs and have regression coverage.
2. **Demo-walk throttling:** all API traffic shared a 60/minute IP bucket, so a
   fast multi-screen walkthrough produced 429s. Guest traffic remains at 60/min;
   authenticated traffic uses a configurable 300/min per-user bucket.
3. **Stock-count demo seed:** the stock-count service requested an unregistered
   `stock_count` document sequence. The sequence is now registered and tested.
4. **Partial demo seeding:** GoldenPathDemoSeeder used to log section failures
   and continue, allowing a misleading partially seeded “success.” It now fails
   loudly and reruns idempotently.
5. **Missing deterministic demo records:** stable B2B accounts, zone-freeze
   stock count, PR conversion/budget-warning rows, supplier-return lineage, and
   a forecast-to-MRP opt-in are now seeded.
6. **Supplier return gap:** direct tests now prove the complete supplier path:
   exact GRN/PO lineage validation, received-quantity reversal, PO status
   recalculation, supplier credit/bill application, optional replacement PO,
   rollback on invalid lineage, and repeat-disposition rejection.
7. **ADV11 literal requirement:** products now have an **Include forecast in
   MRP** control. Only opted-in active products feed the advisory forecast-MRP
   projection; this remains planning-only and does not create PRs.
8. **Feature visibility:** newer modules were omitted from the authenticated
   user's feature payload. This made Forecasting display “Module disabled” even
   when its persisted toggle was enabled. Forecasting, budgeting, B2B portals,
   search, and notifications are now represented and tested.
9. **Quality gates:** seven strict lint errors and fifteen warnings were cleared,
   including unsafe `any` casts and stale E2E imports. PHPUnit data providers now
   use attributes rather than deprecated doc-comment metadata.
10. **Defense runner:** the old screenshot script reported `OK` despite console
    errors and always exited zero. `npm run test:defense` now fails on console or
    page errors, HTTP 4xx/5xx API responses, missing fixture content, blank/404/
    disabled pages, failed internal login, or failed supplier/customer login.
11. **Demo discoverability:** Forecasting, the procurement chain, and the four
    ADV8 WMS screens were registered routes but absent from the shared sidebar
    and command palette. They are now permission- and feature-gated navigation
    items; budgeting links now also honor the budgeting feature toggle.
12. **Stale-session login recovery:** a 419 response claimed the session had
    been refreshed but never fetched a new CSRF cookie or replayed the request.
    Mutations now refresh CSRF and retry exactly once. A live forced-stale login
    produced the expected 419 → 200 sequence and reached the admin dashboard
    without a hard refresh or user-facing error toast.
13. **Duplicate and unexplained toasts:** the Axios interceptor and individual
    screens both announced the same failures, while background queries could
    raise context-free global errors. Screens now own error presentation by
    default; a request must explicitly opt into an interceptor toast.
14. **Authenticated files:** protected PDFs, CSV/XLSX exports, payslips,
    statutory files, bank files, shipment documents, delivery proofs, document
    vault files, and audit exports no longer navigate directly to API URLs.
    They use one authenticated blob downloader, preserve server filenames,
    handle expired sessions inside the SPA, and surface one actionable error.
15. **Session-policy enforcement:** 706 internal Sanctum routes could omit idle
    timeout and password-expiry middleware. Both policies now apply at the API
    group boundary while explicitly excluding public, portal, and edge guards.
    The password age uses the admin-configured value (including zero to disable
    timed expiry), and idle logout now targets the web guard instead of calling
    the nonexistent `logout()` method on Sanctum's RequestGuard.
16. **GRN/accounting atomicity:** receipt acceptance previously committed stock
    even when required GL posting failed, and consolidated receive-with-QC did
    not post a journal entry. Stock, receipt state, and GL posting are now one
    transaction; an enabled but misconfigured ledger rolls everything back.
17. **Incoming-QC item linkage:** the automatic GRN listener wrote an inventory
    item ID into a CRM-product foreign key, swallowed the constraint exception,
    and left the receipt without its required inspection. Migration 0276 adds
    the correct nullable item relationship, product inspections remain intact,
    and both the API and quality screens display the appropriate identity.
18. **Authentication bypass in PR templates:** the department selector was the
    last raw internal `fetch()` call and therefore bypassed common 401/session
    recovery. It now uses the shared authenticated departments client.
19. **Dependency security and framework currency:** Laravel was upgraded from
    11 to 12.64, vulnerable transitive PHP packages were updated, and the
    unmaintained Excel wrapper that pinned an unsafe PHPSpreadsheet release was
    replaced with a small direct export adapter on PHPSpreadsheet 5.9. Composer
    now reports zero advisories.
20. **Spreadsheet formula injection:** CSV exports could turn attacker-controlled
    values beginning with `=`, `+`, `-`, or `@` into formulas when opened in a
    spreadsheet. CSV values are neutralized and XLSX values are explicitly
    written as strings; regression coverage includes leading whitespace and
    unsafe sheet names.
21. **Silently bypassed accounting controls:** `BillService` declared budget and
    three-way-match services as optional constructor arguments. Laravel used the
    `null` defaults, so bill creation skipped both controls. These services are
    now required dependencies; exhausted budgets and GRN shortfalls block as
    designed, while audited overrides remain supported.
22. **Transactional file lifecycle:** resume, quote drawing, controlled-document,
    shipment, supplier invoice, delivery proof, disbursement proof, vault, and
    receipt uploads now check storage failures and compensate failed database
    writes. Destructive blob deletion is deferred until the database commit, and
    deleting a delivery cleans all associated proof files after commit.
23. **Production build-context data exposure:** the production image previously
    copied the entire API tree, including local `.env` files, logs, resumes,
    documents, bank files, and database backups when present. A root
    `.dockerignore` now excludes secrets, runtime data, dependencies, tests, and
    documentation from the build context.
24. **Runtime artifacts committed to Git:** 71 generated/private artifacts (68
    PDFs, two resumes, and one SQL backup) were removed from the Git index while
    preserving the local files. Runtime application storage is now ignored as a
    class rather than by a short allowlist.
25. **Disposable and isolated production uploads:** API, queue, scheduler, and
    Reverb containers previously had separate ephemeral storage layers. They now
    share a named `appstorage` volume, preserving uploads across replacement and
    making them visible to workers.
26. **Unsafe production cache timing:** Laravel caches were generated during the
    image build without deployed secrets, previously hidden behind `|| true`.
    Platform requirements remain a hard build gate; an entrypoint now builds
    configuration, route, and view caches after production environment injection
    and aborts startup for invalid configuration.
27. **Static-analysis runtime defects:** level-0 PHPStan exposed a wrong Product
    namespace, missing facade/model imports (including a migration's implicit DB
    alias), a console command's runtime-bound output call, and scanner actions
    referencing an undefined item from the work-order resolver. Imports and
    console output are explicit, the actions now live in the item resolver, and
    context-specific regression coverage passes. Level 0 is clean.
28. **Import error information exposure:** a broad `RuntimeException` catch ran
    before `QueryException`, allowing database details to escape in import API
    errors. Database exceptions now return a generic message and preserve
    rollback semantics.
29. **Scheduled budget synchronization:** a syntax error made the scheduled
    budget-actual job unloadable. The job is valid again and a database-backed
    test verifies only posted, in-fiscal-year entries contribute to actuals.
30. **Hard-coded external credential:** a tracked Claude launcher contained an
    API credential. The launcher now requires `ANTHROPIC_API_KEY` from the
    caller's environment or secret manager, and the current tree no longer
    matches the audited private-key/cloud-token patterns. Revocation remains a
    mandatory external action because removing a value from the current tree
    cannot invalidate it or erase prior Git objects.
31. **Payroll information overexposure:** `payroll.view` is intentionally held
    by every employee so they can retrieve their own scoped payslips, but it was
    also used to authorize company-wide payroll-period metadata, progress
    broadcasts, disbursement proofs, and unscoped de-minimis records. A new
    `payroll.periods.view` permission now protects period data end to end; only
    payroll operators receive it. De-minimis reads now require the existing
    payroll-adjustment privilege, and SPA guards match the backend gates for
    periods, adjustments, and statutory exports. Direct negative authorization
    tests prove an own-payslip user receives 403 for both exposed surfaces.
32. **Nondeterministic browser coverage and stale UI contracts:** isolated E2E
    tests allowed layout/background requests to reach the real API, converting
    missing mocks into misleading session redirects. Authenticated background
    endpoints are now deterministic, permission-guard tests isolate data APIs,
    and stale HR, payroll, order-to-cash, forecast, and mobile self-service
    fixtures/selectors match current routes and response schemas. The upgraded
    Playwright Firefox runtime is installed and the automated matrix now covers
    desktop Chromium, desktop Firefox, and mobile Chromium.
33. **Forecast KPI double formatting:** quality forecast values included a `%`
    in the backend value while the shared panel also appended its percent unit,
    rendering values such as `2.3%%`. The API now returns an unformatted numeric
    string and the shared formatter produces one correct percentage.
34. **Strict-lint drift in shared UI primitives:** the upgraded React refresh
    lint rule began rejecting the intentional helper exports in the dashboard
    shell and table-cell modules. Those colocated layout/style exports are now
    explicitly documented at file scope, restoring the zero-warning lint gate
    without changing runtime behavior.
35. **Stale forecast-dashboard failure assertions:** finance, HR, and quality
    forecast browser specs still expected their old role-specific error copy
    after the dashboards adopted the shared accessible error shell. The specs
    now assert the shared error heading by role, and all forecast trend, empty,
    loading, and API-failure cases pass again.
36. **Duplicate mobile leave action:** an empty self-service leave page rendered
    the same “New request” action in both the page header and empty state. The
    header action is now reserved for populated/loading states, leaving one
    unambiguous mobile action while preserving the normal shortcut once rows
    exist.
37. **Polling-page E2E readiness:** shared browser helpers waited for network
    idle even though dashboards and self-service pages legitimately poll, and
    repeated role checks could skip a second auth bootstrap because the session
    was already hydrated. Readiness now follows the mounted application shell;
    multi-navigation RBAC cases have an honest Firefox time budget, isolated
    self-service home tests mock both current APIs, and HashID/payroll checks
    assert stable accessible UI instead of timing-dependent or ambiguous text.
38. **SPA/API route contracts were not mechanically enforced:** frontend request
    paths could drift from Laravel routes while mocked browser tests remained
    green. A TypeScript-AST audit now resolves static strings, template IDs,
    shared path constants, public recruitment prefixes, and both B2B portal
    clients against the live Laravel route table. All 702 declared SPA requests
    currently match an endpoint and HTTP method.
39. **Forecasting still looked like a standalone global module:** demand,
    stock-out, and accuracy pages remained a separate sidebar section even after
    role dashboards gained partial forecast widgets. The duplicate global
    section is removed; PPC and Plant Manager dashboards now show demand,
    stock-out, and accuracy together, including honest empty/error states and
    drill-down links. Purchasing and Warehouse retain the operational forecast
    slices relevant to their responsibilities, while full pages remain available
    as task/detail drill-downs rather than top-level destinations.
40. **Zero-demand forecast variance:** the compact dashboard calculated a
    percentage against a zero forecast quantity, which could render an infinite
    variance. Zero baselines are now excluded from the percentage aggregate.
41. **Mocked tests did not prove live page contracts:** a role-aware browser
    walker now discovers every literal SPA route and exercises public, internal,
    employee self-service, driver, maintenance-mobile, supplier, and customer
    contexts against the live seeded API. It performs one hard/deep-link load
    per session and then real client-side navigation, monitoring API failures,
    browser exceptions, blank/error pages, and unauthorized redirects. All 230
    static route surfaces currently pass.
42. **Self-service overtime SQL 500:** the shift lookup referenced four obsolete
    columns (`effective_from`, `effective_until`, `time_in`, and `time_out`). It
    now queries the migrated assignment and shift schema and returns the stable
    `time_in`/`time_out` response aliases. A feature regression proves an
    effective shift returns successfully.
43. **Warehouse HashID detail failures:** warehouse-map bin and MIS picking
    endpoints accepted integer scalars while the SPA sent public HashIDs,
    causing a controller type error on bin selection. Both routes now use
    implicit HasHashId model binding, and bin detail no longer leaks raw IDs.
44. **Duplicate dashboard records and React keys:** the warehouse low-stock
    query produced one row per approved supplier rather than per item, while the
    finance budget table keyed repeated categories by label alone. The warehouse
    query now selects one preferred supplier without multiplying items and the
    finance table uses stable row-position disambiguation.
45. **Maintenance-mobile machine selector forbidden:** technicians could open
    condition readings but their role lacked the read permission required by
    its machine selector. The role now includes `mrp.machines.view`; the live
    technician route loads without a 403.
46. **Portal sessions failed on refresh/deep links:** supplier and customer
    bearer tokens existed only in module memory, so every hard navigation lost
    authentication. Each portal now persists its isolated token in tab-scoped
    session storage, restores it when its API client boots, and clears it on
    logout. Unit coverage proves reload restoration and cross-portal isolation.
47. **Customer statement response-shape crash:** the portal page expected an
    obsolete open-invoice DTO while the shared accounting service returns a
    customer ledger with `aging` and `transactions`. Types and rendering now
    match the authoritative API contract, including four aging buckets and the
    running-balance ledger.
48. **Parent-pack demo configuration gap:** translated statements defaulted to
    JPY but a fresh/demo database contained no FX rate, immediately producing a
    422 empty state. The additive golden-path seeder now idempotently creates JPY
    and USD reporting rates, and the live parent-pack page renders successfully.
49. **Parameterized pages were outside the static route gate:** a second live
    browser audit now discovers route templates, extracts real HashID links from
    rendered pages, derives sibling edit/action URLs, and sources remaining
    fixtures from authenticated list APIs. It evaluates record pages for failed
    APIs, browser exceptions, error states, blank screens, and auth redirects.
50. **Demo delivery proofs referenced nonexistent files:** the golden-path
    seeder created proof database rows without their backing assets, so delivery
    detail generated automatic 404 image requests. The idempotent seeder now
    creates or repairs a valid local proof image for every demo proof row.
51. **Forecast and warehouse dashboard links used business codes as route IDs:**
    stock-out, warehouse low-stock, demand-forecast, and top-consumption widgets
    constructed detail URLs from item codes, product part numbers, or raw SQL
    IDs although the target routes require public HashIDs. The APIs now emit
    linkable HashIDs and each widget targets the correct inventory or CRM entity.
52. **Customer invoice detail 500:** the portal service eagerly loaded a
    nonexistent `payments` relationship. It now loads the authoritative
    `collections` relationship, and the portal type/table use collection dates
    and the actual invoice-item `total` field.
53. **Customer delivery detail used raw IDs and internal proof URLs:** raw
    Eloquent serialization produced `/portal/customer/deliveries/1`, which
    cannot bind to a HasHashId model, and exposed proof URLs inaccessible to the
    customer guard. A portal-specific resource now emits scoped HashIDs, stable
    item fields, and customer-authenticated proof URLs backed by an ownership
    checked streaming endpoint.
54. **Supplier invoice UI used the customer invoice contract:** supplier portal
    records are AP bills, but the dashboard/list/detail pages expected AR fields
    such as `invoice_number`, `total_price`, and `paid_at`. Supplier bill types
    are now separate and match `BillResource` (`bill_number`, `total`, and
    `payment_date`).
55. **Audit-log detail HashIDs rejected by route constraint:** the index emitted
    HashIDs and the controller decoded them, but `whereNumber('id')` rejected the
    request before controller dispatch. The contradictory constraint is removed
    and a feature regression opens the exact HashID emitted by the index.
56. **Public-career and driver detail routes lacked demo fixtures:** the
    golden-path seeder now provides an open job posting and assigns a scheduled
    delivery to the demo driver, making the public detail/application and driver
    detail/photo flows reproducible in manual and automated testing.
57. **PR-template pages mixed raw IDs and HashIDs:** list results exposed raw
    template IDs, the edit page converted route and department HashIDs with
    `Number(...)`, and “Use template” silently discarded `template_id` during
    purchase-request validation. A dedicated resource now emits HashIDs, template
    CRUD decodes department HashIDs safely, PR creation resolves template HashIDs,
    and the SPA keeps all identifiers as strings. An HTTP regression covers list,
    show, update, and PR creation from the emitted template ID.
58. **Eleven parameterized routes had no reproducible record:** the golden-path
    seeder now idempotently supplies complaint, performance-review, recruitment,
    separation, succession, MRB, routing, PR-template, controlled-document,
    NCR-template, and SPC records. The dynamic browser audit consequently covers
    every discovered route template instead of reporting unseeded exclusions.
59. **COPQ trend arithmetic overflowed at month end:** subtracting months from
    dates such as August 31 can roll into March and omit February from the trend
    window. The widget now anchors calculations at the first day of the month,
    reuses one request timestamp, and its tests use overflow-safe month fixtures.
    A July 31 full-suite run exposed and verified the regression.
60. **Role dashboards crashed while rendering their KPI strip:** permission
    filtering preserved Laravel collection indexes, so PPC and six other roles
    received an object such as `{ "3": {...} }` where the React widget expected
    an array. The server now reindexes filtered scorecards, the SPA normalizes
    legacy or cached object-shaped responses, and focused API/component tests
    cover both formats. The reported Firefox `dispatcher is null` / `useRef`
    message was a secondary React render symptom; the first application error
    was `data.find is not a function` in `KpiStrip`.
61. **Default KPI recomputation used the wrong period at year boundaries:** the
    controller independently selected the current year and previous month, so a
    January request targeted December of the current year. It now derives both
    values from one overflow-safe previous-calendar-month date, with a January
    regression proving the `2025-12` result from `2026-01-31`.
62. **Self-service grants exposed back-office navigation:** operational pages
    reused `attendance.view`, `leave.view`, and `payroll.view`, even though those
    permissions are intentionally universal and row-scoped for personal DTR,
    leave, and payslips. PPC and other non-HR roles consequently saw Attendance,
    Leave, Payroll, and Statutory links that either failed with 403 or exposed a
    broader page than their responsibility. Sidebar and SPA route guards now use
    the precise operational permissions, while self-service remains available.
63. **Role pages depended on permissions their visible route did not require:**
    Production routings and machine health needed machine-master reads; Stock
    Count was exposed by generic inventory access; Department Head employee and
    attendance pages fetched the HR department tree unconditionally. Production
    now has the machine read needed for its work, Stock Count requires its own
    permission end to end, and department filters only load when authorized.
64. **Department employee detail bypassed list scoping:** the employee list
    correctly limited a Department Head to their department, but a guessed
    employee HashID reached an unscoped detail service. Detail retrieval now
    applies the same permission-driven department scope and returns 404 for an
    out-of-scope identifier; feature coverage proves both same-department access
    and cross-department denial.
65. **Generic export authorization was too broad for sensitive future modules:**
    payroll register, statutory, and accounting-aging exports could inherit a
    generic module-view gate when registered. The export map now requires
    all-payslip, statutory-export, or statement-export privileges respectively,
    with negative PPC regressions for payroll and government export metadata.
66. **Department-scoped employee view still returned sensitive HR fields:** a
    manager allowed to see their team also received salary values, salary-change
    history, document metadata, and the onboarding workflow. Employee resources
    now redact pay and salary-history values without the sensitive-data grant,
    omit document metadata without its dedicated grant, and reserve onboarding
    for HR editors. HR and self-view retain the information they require.

## Verification evidence

| Check | Result |
|---|---:|
| Focused auth/session/GRN/QC regressions | **PASS — 37 tests, 152 assertions** |
| SPA strict lint (`--max-warnings=0`) | **PASS — 0 errors, 0 warnings** |
| SPA TypeScript | **PASS** |
| SPA unit tests | **PASS — 16 files, 67 tests** |
| SPA production build | **PASS** |
| Playwright browser matrix | **PASS — 171 cases (82 desktop Chromium, 82 desktop Firefox, 7 mobile Chromium)** |
| PHP syntax lint | **PASS — application, config, migrations, routes, and tests** |
| PHPStan level 0 | **PASS — 0 errors** |
| Structural route audit | **PASS — 861 routes, 0 missing/shadowed routes or unresolved middleware** |
| SPA → Laravel API contract audit | **PASS — 702 static requests match 1,253 API method/routes** |
| Live static SPA route audit | **PASS — 230 public/authenticated/role/portal route surfaces, 0 failures** |
| Live dynamic SPA route audit | **PASS — all 77 seeded parameterized URLs/templates, 0 failures or uncovered templates** |
| Authenticated GET endpoint sweep | **PASS — 0 internal HTTP 500 responses** |
| Model/schema/binding/permission audit | **PASS — 195 models, 0 binding errors, 221 referenced permissions all seeded** |
| PostgreSQL relational integrity | **PASS — 461 foreign keys and 201 sequences; 0 orphans, invalid indexes, unvalidated constraints, or lagging sequences** |
| Composer validation and security audit | **PASS — valid metadata; 0 advisories at the last successful registry check** |
| Production Compose rendering | **PASS** |
| Production PHP image | **PASS — build and production-mode boot/cache smoke test** |
| Migrations through 0280 | **PASS — applied** |
| Golden-path seeder | **PASS — two idempotent runs** |
| Strict live browser gate | **PASS — login + 19 internal screens + 2 portals** |
| Forced-stale CSRF login | **PASS — 419 → 200, dashboard reached, no toast** |
| Authenticated PR PDF | **PASS — HTTP 200, `application/pdf`** |
| Full backend suite | **PASS — 1,138 tests, 3,629 assertions** |
| Forecast role-dashboard browser regressions | **PASS — 18 desktop-Chromium cases** |
| Actual role-dashboard browser matrix | **PASS — all 18 role/browser combinations (9 roles × Chromium/Firefox), 0 failures** |
| KPI scorecard regressions | **PASS — 2 backend tests/7 assertions and 3 SPA tests** |
| Role-responsibility and department-scope regressions | **PASS — 20 backend tests, 127 assertions** |
| All-role live permission/navigation matrix | **PASS — 443 checks across all 13 internal roles, 0 failures** |
| Department Head employee-detail click-through | **PASS — overview plus Attendance, Leaves, and Loans tabs, 0 failed APIs/browser errors** |
| RBAC static catalog audit | **PASS — 243 seeded permissions, 235 referenced, 0 missing references** |
| Current SPA verification | **PASS — 17 files/71 tests, strict lint, TypeScript, and production build** |

The live gate specifically verified `BATCH-20260709-0001`, the forecast MRP
control, `Defense Demo — Zone Freeze`, `PR-DEMO-CONVERT`/`PR-DEMO-BUDGET`,
`RMA-DEMO-SUP-READY`, and both portal accounts without any failed API response
or browser console error. A separate forced-header probe deliberately generated
the expected browser network-level 419, then verified the SPA recovered with a
single successful replay and no application exception or user-facing toast.

## Known residual risks

- Registry DNS was reachable again on 2026-07-28 and the Composer audit was
  re-run: **`No security vulnerability advisories found`** — zero advisories
  confirmed live, not just from the earlier cached run. The npm audit was also
  re-run and still reports only the non-applicable React Router issue below.
- npm reports one high-severity React Router advisory through two package
  entries. The advisory is limited to React Server Components/server-action
  mode; this application is a client-only Vite SPA and does not expose that
  execution path. Re-checked 2026-07-28: the installed release is
  `react-router-dom@7.18.1` (latest 7.x); the only advisory-clear release is
  `react-router@8.3.0`, a breaking major migration. Because the vulnerable path
  is unreachable here, the 7.x line is intentionally pinned rather than migrated;
  revisit if/when a patched 7.x release is published.
- The private runtime files and removed hard-coded API credential still exist in
  earlier Git history. Revoke/rotate the credential immediately. A coordinated
  history purge and data-exposure review is also recommended, but history was
  not rewritten automatically because it would invalidate every existing clone.
- The operations-health report currently contains 27 overdue action items. They
  are operational follow-ups rather than failing code tests, but should be
  triaged before calling the deployment fully production-ready.
- Repository-wide Pint and PHPStan level 5 are not yet clean because of broad
  legacy formatting and Eloquent/magic-property annotation debt. All files
  touched by this hardening pass are formatted, PHP syntax is clean, and PHPStan
  level 0 is suitable as the immediate CI floor.
- `docs/QA-MATRIX.md` still has intentionally unchecked Safari, Edge, real
  phone/tablet hardware, dark-mode, reduced-motion, and accessibility checks.
  Automated desktop Firefox and mobile-Chromium coverage now pass, but they are
  not a substitute for the remaining real-device and assistive-technology work.
- The repository worktree contains many pre-existing and audit changes. Freeze
  the demonstrated state in a reviewed commit before rehearsal so the demo
  machine cannot drift.
- The strict browser gate validates render, fixture presence, API/realtime
  health, and portal access. It deliberately does not execute destructive demo
  actions such as disposing the seeded RMA or converting the seeded PR; those
  transitions are covered by backend tests and should be rehearsed on a database
  that can be reseeded.

## Demo-day commands

```bash
docker compose up -d
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --class=GoldenPathDemoSeeder
cd spa
npm run test:defense
```

Credentials and the 15-minute narrative are in `docs/DEMO-SCRIPT.md`.
