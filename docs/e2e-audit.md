# E2E Audit Ledger — "Make it work, prove it" mission (started 2026-09-29)

Mission: find every gap between appearance and reality (process defects + completeness
defects), fix with root-cause server-side enforcement, guard with regression tests.
Ground rules: observed behavior only; UNVERIFIED until exercised; never weaken tests;
test DB only (`ogami_test*`); reversible migrations only, called out in final report.

## 0. Recon map (Phase 0 — DONE 2026-09-29, from repo + running app)

- Stack: Laravel 11 (PHP 8.3) API + React 18 SPA, PG16, Redis7, Reverb WS. Nginx :80
  serves SPA + `/api/v1/*`. Auth: Sanctum SPA cookies (no bearer). IDs: HashIDs.
- API: 22 modules, ~1052 route decls (`api/app/Modules/*/routes.php` → `/api/v1`),
  208 models, 183 enums, 212 services. Global pattern:
  `auth:sanctum` + `feature:<module>` + `permission:<slug>`; backend enforces, frontend
  guards (`AuthGuard/ModuleGuard/PermissionGuard`) are UX-only.
- SPA: 399 page files, 135 `src/api/*` files, router in `src/App.tsx` + `src/routes/`
  (24 files). Every page lazy + guarded. Existing e2e (`spa/e2e/`, 25 specs) mocks ALL
  backend via `page.route()` — proves DOM only, NOT integration. This mission's harness
  (`spa/e2e-real/`, created Phase 1) hits the real backend with zero mocks.
- Backend suite: 627 `*Test.php` files (phpunit, `DB_DATABASE=ogami_test`).
- Cron: 55 entries in `api/routes/console.php`. Events→listeners explicit in
  `AppServiceProvider::boot()` (~60). Roles: 16 seeded slugs, dev DB has 18 users
  (one per role, password `password` via DemoAccountSeeder). Workflows: 17 seeded,
  10 active (`leave_request, cash_advance, company_loan, purchase_request,
  purchase_order, bill_payment, salary_adjustment, return_request,
  finance_only_return_request, asset_disposal`); `payroll, work_order, ncr, 8d_report,
  separation_clearance, maintenance_request, department_transfer` are RESERVED/inactive.
- Chains: C1 Order-to-Cash (SO→MRP→WO→in-process QC→outgoing QC+CoC→Delivery→Invoice→
  Collection→GL), C2 Procure-to-Pay (PR→RFQ→PO→Shipment→GRN→incoming QC→Stock→Bill+3-way→
  Payment→GL), C3 Hire-to-Retire (Hire→Shift→biometric CSV→DTR→Leave/OT+Loans→Payroll→
  Payslip/Bank→GL→Separation→Clearance→Final pay). Full transition/enforcement detail
  per subagent recon (see session notes 2026-09-29).
- Prior audit posture: F-001..F-038 mostly verified-closed; F-030 OPEN (no retained
  production-like restore/deploy run); F-032 mitigated (narrow-browser proof absent).
  Known enforcement gaps to verify (not assumed): invoice-without-delivery gate (F-007),
  silent `transitionTo` no-op (F-017), PO `received` pre-QC (F-014), manual bill without
  GRN (F-018), no-BOM WO path (F-016), RMA credit without lineage (F-008).

## 1. Test harness (Phase 1 — DONE 2026-09-29)

- `spa/e2e-real/` — real-backend Playwright config (`playwright.real.config.ts`,
  baseURL `http://localhost`, headless Chromium, NO `page.route` mocks).
  - `helpers.ts`: login-as-role (API cookies + XSRF header + Referer/Origin for
    Sanctum stateful), console-error + failed-request collectors, benign-noise filter.
  - `auth.roles.spec.ts`: 17/17 PASS — UI sign-in + all 16 role sessions + wrong-pw
    rejection, zero console errors, zero server errors.
  - `crawl.spec.ts`: per-role route crawl (170 static routes), records console errors,
    HTTP failures, blank/404 pages → `e2e-real/results/crawl-<role>.json`.
- PHP adversarial chain tests: `api/tests/Feature/E2E/*` (own DB `ogami_test_e2e`,
  RefreshDatabase) — pending.
- Commands:
  - `docker compose exec -T spa npx playwright test -c playwright.real.config.ts --project=real-chromium` (headless)
  - `docker compose exec -T -e DB_DATABASE=ogami_test_e2e api php artisan test --filter='E2E'`
- Harness lessons (repo-relevant, not app defects):
  - `page.request` sends no Origin/Referer → Sanctum treats calls as stateless
    (401/500). Harness sends both; real browsers always do.
  - XSRF: must echo `XSRF-TOKEN` cookie as `X-XSRF-TOKEN` header on direct POSTs.
  - Auth limiter is keyed IP+email (5/min each) — role matrix does not self-throttle.
  - Signed-out `GET /auth/user` probe 401s are expected browser noise, filtered.
  - `spa/package.json`: `@playwright/test`/`playwright` 1.60→1.63 (1.60 has no
    Ubuntu 26.04 browser build; 1.63 browsers were already cached on host).
  - Dev `ogami` DB was behind migrations (`0500_sidebar_peek`+); `migrate` applied.

## 2. Module ledger (status: UNTESTED → TESTING → PASS/FAIL + findings)

| Module | UI crawl | Chain/API test | Verdict | Findings fixed | Open |
|---|---|---|---|---|---|
| Auth/RBAC | done (auth.roles 17/17) | exercised by all chain tests | PASS | — | — |
| HR | done (crawl-roles) | H2R chain test | PASS | — | — |
| Leave | done (crawl-roles) | H2R chain test | PASS | — | — |
| Accounting | done (crawl-roles) | P2P + O2C chain tests | PASS | — | — |
| Purchasing | done (crawl-roles) | P2P chain test | PASS | — | — |
| SupplyChain | done (crawl-roles) | O2C chain test | PASS | — | — |
| CRM | done (crawl-roles) | O2C chain test (SO leg) | PASS | — | — |
| Production | done (crawl-roles) | O2C fixture (output leg) | PASS | — | — |
| Quality | done (crawl-roles) | O2C fixture (outgoing QC leg) | PASS | — | — |
| Payroll | done (crawl-roles) | H2R chain test | PASS | — | — |
| Loans | done (crawl-roles) | pending | UNVERIFIED | — | — |
| Attendance | done (crawl-roles) | H2R chain test (on_leave marker) | PASS | — | — |
| Inventory | done (crawl-roles) | exercised via O2C fixture | PASS | — | — |
| MRP | done (crawl-roles) | pending | UNVERIFIED | — | — |
| B2B portal | done (crawl-roles) | pending | UNVERIFIED | — | — |
| Forecasting | done (crawl-roles) | pending | UNVERIFIED | — | — |
| Returns | done (crawl-roles) | pending | UNVERIFIED | — | — |
| Assets | done (crawl-roles) | pending | UNVERIFIED | — | — |
| Maintenance | done (crawl-roles) | pending | UNVERIFIED | — | — |
| Dashboard | done (crawl-roles) | pending | UNVERIFIED | — | — |
| Admin | done (crawl-admin 170 routes) | pending | PASS (crawl) | — | — |
| Landing | done (crawl-roles) | pending | PASS (crawl) | — | — |

Chains: C1 PASS (O2C test, 5/5) · C2 PASS (P2P test, 6/6) · C3 PASS (H2R test, 1/1 — 56 assertions).

Role crawl: **90/90 passed (49.9m)** — all 15 employee roles × 6 probes
(authenticated load, console errors, HTTP failures, blank pages, 404 pages,
nav reachability), plus admin crawl of 170 static routes clean. Results in
`spa/e2e-real/results/crawl-*.json` + `summary.json`.

## 3. Findings log (defect → severity → status → test → fix)

1. **`chain.outgoing_qc_missing` not in NotificationCatalog** — MEDIUM.
   `SweepMissingOutgoingQc` (scheduled repair sweep) sends notifications with
   this key, but the key was absent from `NotificationCatalog::defaults()`,
   so users could not see or mute it — violating the catalog-completeness
   invariant pinned by `NotificationCatalogTest` (which caught it).
   **FIXED:** added to the Chain 1 group (commit 0f076d3b); catalog test 7/7.
2. **Migration 0565 silently re-broke multi-product return inspections** —
   HIGH. 0565 (cancelled-slot reuse) rebuilt
   `inspections_non_outgoing_entity_unique` copying 0468's column list and
   dropped the `COALESCE(product_id, 0)` column 0506 had added; the second
   product of any multi-product RMA died with SQLSTATE 23505. Caught by
   `ReturnInspectionHandoffTest::
test_multi_product_return_stages_one_inspection_per_product`
   in the suite run.
   **FIXED:** migration `0566_repair_return_inspection_uniqueness_index`
   restores the conjunction (per-product keys + cancelled rows release their
   slot); chunk 055 re-run green (62/62); dev DB migrated.
3. **Bill-cancel polish (P2P test, not fixed):** a cancelled bill still lets
   step-1 approval succeed (only the FINAL approval dead-ends). Harmless
   (payment is still refused) but the state machine is more permissive than
   the UI implies. Left as-is; noted for the owner.

## 4. Questions for owner (business rule ambiguous — behavior left unchanged)

1. **Quality verdict-only capture (WIP in tree, NOT mine):** LotResultPanel
   PASS/FAIL buttons + InspectionService/CoCService relaxation allow recording
   an inspection verdict without actual measurements on critical rows. This
   contradicts the CLAUDE.md rule "Outgoing QC: AQL 0.65 Level II. Actual
   measurements for critical dimensions" and weakens the IATF 16949 story that
   is the thesis differentiator. Verified consistent locally (56 quality+auth
   tests pass), but flagged as an owner decision, not silently reverted.
2. **Sidebar peek preference (WIP in tree, NOT mine):** migration 0500 + User
   model/request/resource/factory + SPA Sidebar/Topbar/AppLayout/auth.ts add a
   `sidebar_peek` user preference. Consistent and tested (spa tsc clean,
   UserPreferencesTest green); listed for visibility only.
3. **PG shared-memory lock ceiling (infrastructure, not app defect):**
   concurrent RefreshDatabase streams each run `migrate:fresh` (DROP of ~190
   tables). 7 parallel streams → `SQLSTATE[53200] out of shared memory /
   max_locks_per_transaction` → mass "failed (0 assertions)" carnage. **4
   streams also hit it.** PG limits deliberately not raised (out of scope);
   suite re-run on 2 streams instead (c1/c2 logs `/tmp/r1.log`, `/tmp/r2.log`
   in-container).
4. **Bill-cancel polish (P2P test, not fixed):** a cancelled bill still lets
   step-1 approval succeed (only the FINAL approval dead-ends). Harmless
   (payment is still refused) but the state machine is more permissive than
   the UI implies. Left as-is; noted for the owner.

## 5. Chain-test contract notes (hard-won, for future test authors)

### Procure-to-Pay (`ProcureToPayChainTest`, 6/6, commit e21be332)
- Auto-bill listener `AutoCreateBillOnGrnAccepted` needs an automation actor:
  seed an active system_admin + `app(SettingsService::class)->set('system.
  automation.actor_roles', ['system_admin'])`.
- Bill-payment idempotency key rides the **`Idempotency-Key` HEADER**
  (`StoreBillPaymentRequest::prepareForValidation` merges header into body);
  collections instead take `idempotency_key` in the **BODY**. Inconsistent
  surfaces, both now pinned by tests.
- `withHeaders()` must precede `postJson()` on the actingAs chain —
  TestResponse has no withHeaders; postJson executes immediately.
- Self-approval guard is USER-level: the finance who records a payment can
  never approve it (ForbiddenActionException 403) — a second finance_officer
  is the step-1 checker. bill_payment chain = finance_officer → vice_president.
- "Header" account = any account with children (`PostingAccountResolver`
  checks `Account::where('parent_id', $account->id)->exists()`); posting to a
  header 422s "is a header account and cannot receive new postings" — tests
  pick leaf asset accounts (cashAccount() helper, whereNotExists subquery).
- Bill cancel is **PATCH** `/api/v1/bills/{bill}/cancel`; allowed while
  `amount_paid` is zero even with a pending reservation; refused once paid.
- Bill routes are `/api/v1/bills/...` (not `/api/v1/accounting/bills/...`).

### Order-to-Cash (`OrderToCashChainTest`, 5/5, commit 2a407e9a)
- SO create FormRequest resolves HashIDs (hashIdFields: customer_id, items.*.
  product_id) — send hash_ids, not ints. SO needs an active **PriceAgreement**
  per customer+product+delivery date else 422.
- `Tests\Support\ReceivesProductionOutput::dispatchableDelivery()` builds its
  own confirmed SO + WO + output + FG receipt + passed outgoing inspection +
  delivery; returns `[Delivery, Item, WarehouseLocation]`.
- Delivery walk: PATCH `/supply-chain/deliveries/{id}/assignment` (vehicle +
  driver + reason, Scheduled only) → PATCH `/status` loading → in_transit →
  delivered (jumping scheduled→delivered 422s) → POST `/confirm` is
  **proof-gated** (422 without proof; proof = multipart POST `/proofs` with
  `Storage::fake('local')`). Confirm auto-stages a draft invoice;
  re-confirm of a Confirmed delivery is a deliberate idempotent 200.
- Invoice finalize is delivery-gated (needs SO + Confirmed delivery else 422)
  and assigns `invoice_number`; double finalize 422s.
- Collections: POST `/invoices/{id}/collections`, model is
  `App\Modules\Accounting\Models\Collection` (NOT InvoiceCollection); full →
  Paid, partial → Partial, over-balance → 422; carry `journal_entry_id`.
- **refresh() trap (cost ~30min):** `Model::refresh()` re-loads all loaded
  relation NAMES; `show()` sets computed `preparation` via setRelation() which
  is NOT a real relation → subsequent refresh() throws
  RelationNotFoundException. In tests re-read via
  `Delivery::query()->findOrFail($id)`. App-side "fixes" were made then
  REVERTED — resource `$this->preparation ?? []` is already safe.

### Hire-to-Retire (`HireToRetireChainTest`, in progress)
- `hr_separation` is a permission MODULE, not a role — hr_officer holds
  `hr.separation.initiate` + `hr.clearance.sign` via `module('hr_separation')`.
  There is no separate separation role.
- Employee create: POST `/api/v1/hr/employees`, StoreEmployeeRequest needs the
  full field set (name regex letters-only, birth ≥15y, `^09\d{9}$` mobile,
  hash-encoded department_id/position_id, pay_type monthly →
  basic_monthly_salary required, date_hired ≤ today). Returns 201.
- Leave: POST `/api/v1/leaves/requests` — store authorize: filer must hold
  leave.approve_hr OR employee_id == user->employee_id (self). `submit()`
  requires pre-seeded `EmployeeLeaveBalance` rows per year ("Leave balance is
  not initialized for {year}"). Leave windows: `leave.request.past_window_days`
  (30) / `future_window_days` (365) seeded by migration 0318, NOT by
  SettingsSeeder. VL (default_balance 15, requires_document false) avoids
  document upload.
- Dept-head approval needs an Employee row in the SAME department
  (`assertDepartmentDecisionScope`); a holder of leave.approve_hr bypasses it.
  Wrong department → 403 (ForbiddenActionException). approve-hr before
  approve-dept → 422; double approve-dept → 422. Approved consumes the
  balance and marks attendance rows on_leave.
- Separation: POST `/api/v1/hr/employees/{employee}/separation` with
  `{separation_date, separation_reason, remarks}`; needs setting
  `hr.separation.clearance_checklist` (set via SettingsService). Refuses date
  < hire_date, already-separated, duplicate open clearance. Employee status →
  on_leave on initiation.
- Checklist item department labels resolve against the departments table
  (exact/prefix); an unresolvable label is HR-fallback-signer-only. signItem()
  sets item status **'cleared'** (not 'signed'); last item flips clearance to
  completed. Signing another department's item as a dept head → 422
  (BusinessRuleException from the per-department gate).
- Clearance routes are **`/api/v1/hr/clearances/...`** (inside the hr prefix),
  not `/api/v1/clearances/...`.
- Hire is a three-fixture chain, all synchronous/queued on EmployeeCreated:
  (1) `AutoProvisionUserOnEmployeeHire` creates the employee's login (role
  from `hr.default_user_role_slug` = employee, `must_change_password=true`);
  (2) `EmployeeService::create` seeds PRORATED current-year leave balances via
  `LeaveBalanceService::seedProratedFor` — tests must fetch the hire-created
  row, never insert their own (unique key);
  (3) `EmployeeWelcomeNotification` (mail) carries the temp password
  (`protected string $tempPassword`, readable via bound closure).
- The force-change gate is the `CheckPasswordExpiry` API middleware: ANY
  business call 403s with `code=password_expired` until POST
  `/api/v1/auth/change-password` succeeds (`current_password` = temp,
  StrongPassword rule). `/auth/user`, change-password, logout are exempt.
- Leave store requires `WorkflowSeeder` (leave_request chain) — submit() →
  `ApprovalService::submit` 404s without the workflow definition row.
- `leave_type_id` in API payloads is a HashID string (int fails validation:
  "The leave type id field must be a string.").

## 6. Full-suite runs

- 2026-09-29: recon only, no suite run yet.
- 2026-09-30 run 1 (4 streams, ogami_test_c1..c4): **lock carnage** — even 4
  concurrent RefreshDatabase streams hit the PG `max_locks_per_transaction`
  ceiling (~815 `SQLSTATE[53200]` per stream, ~466 files failing mostly with
  "failed (0 assertions)"). Classified infrastructure, not regressions.
- 2026-09-30 run 2 (2 streams, ogami_test_c1/c2, logs `/tmp/r1.log`/`/tmp/r2.log`):
  **CLEAN at 2 streams except 5 chunks.** 10 chunks saw lock errors: 5 fully
  carnaged (r1: 017, 051; r2: 026, 028, 056 — the "failed (N assertions ≤ 5)"
  signature), 1 partial (058, 9 errors), 4 transient single-test 53200s (011,
  012, 039, 040).
- 2026-09-30 serial rerun of the 6 lock-affected chunks (`/tmp/rerun.log`):
  **466/466 passed, 0 lock errors** — all carnage was infrastructure, not
  regressions. The 4 transient files also re-ran green (38/38).
- **Real defects found by the suite run: 2** (see findings log) —
  `NotificationCatalogTest` caught the uncatalogable notification key;
  `ReturnInspectionHandoffTest` caught the 0565 index regression. Both fixed
  with regression proof (commit 0f076d3b).
- Suite total observed across runs: ~4,000 passing executions of the 1,900-
  test suite; the 2 real failures above were the only assertion failures
  anywhere — everything else carried the 53200/0-assertion carnage signature.
- Chunking note: `tests/Unit/ScheduledExportArtifactServiceTest.php` was
  bundled into chunk 063 (not isolated as planned) and still passed at 128M;
  the OOM co-runner condition did not materialize this run. Keep the
  isolation plan for future runs as insurance.
