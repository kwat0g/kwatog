# Ogami ERP — Full-System Audit and Defense Readiness

**Audit dates:** 2026-07-27 to 2026-07-28  
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

## Verification evidence

| Check | Result |
|---|---:|
| Focused auth/session/GRN/QC regressions | **PASS — 37 tests, 152 assertions** |
| SPA strict lint (`--max-warnings=0`) | **PASS — 0 errors, 0 warnings** |
| SPA TypeScript | **PASS** |
| SPA unit tests | **PASS — 13 files, 60 tests** |
| SPA production build | **PASS** |
| Playwright browser matrix | **PASS — 171 cases (82 desktop Chromium, 82 desktop Firefox, 7 mobile Chromium)** |
| PHP syntax lint | **PASS — application, config, migrations, routes, and tests** |
| PHPStan level 0 | **PASS — 0 errors** |
| Composer validation and security audit | **PASS — valid metadata; 0 advisories at the last successful registry check** |
| Production Compose rendering | **PASS** |
| Production PHP image | **PASS — build and production-mode boot/cache smoke test** |
| Migrations 0275–0276 | **PASS — applied** |
| Golden-path seeder | **PASS — two idempotent runs** |
| Strict live browser gate | **PASS — 17 internal screens + exact trace + 2 portals** |
| Forced-stale CSRF login | **PASS — 419 → 200, dashboard reached, no toast** |
| Authenticated PR PDF | **PASS — HTTP 200, `application/pdf`** |
| Full backend suite | **PASS — 1,130 tests, 3,591 assertions** |

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
