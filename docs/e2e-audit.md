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
| Auth/RBAC | done (auth.roles) | pending | — | — | — |
| HR | pending | pending | UNVERIFIED | — | — |
| Attendance | pending | pending | UNVERIFIED | — | — |
| Leave | pending | pending | UNVERIFIED | — | — |
| Payroll | pending | pending | UNVERIFIED | — | — |
| Loans | pending | pending | UNVERIFIED | — | — |
| Accounting | pending | pending | UNVERIFIED | — | — |
| Inventory | pending | pending | UNVERIFIED | — | — |
| Purchasing | pending | pending | UNVERIFIED | — | — |
| SupplyChain | pending | pending | UNVERIFIED | — | — |
| Production | pending | pending | UNVERIFIED | — | — |
| MRP | pending | pending | UNVERIFIED | — | — |
| CRM | pending | pending | UNVERIFIED | — | — |
| B2B portal | pending | pending | UNVERIFIED | — | — |
| Forecasting | pending | pending | UNVERIFIED | — | — |
| Returns | pending | pending | UNVERIFIED | — | — |
| Assets | pending | pending | UNVERIFIED | — | — |
| Quality | pending | pending | UNVERIFIED | — | — |
| Maintenance | pending | pending | UNVERIFIED | — | — |
| Dashboard | pending | pending | UNVERIFIED | — | — |
| Admin | pending | pending | UNVERIFIED | — | — |
| Landing | pending | pending | UNVERIFIED | — | — |

Chains: C1 UNVERIFIED · C2 UNVERIFIED · C3 UNVERIFIED.

## 3. Findings log (defect → severity → status → test → fix)

(none yet — entries added as confirmed by observed behavior)

## 4. Questions for owner (business rule ambiguous — behavior left unchanged)

(none yet)

## 5. Full-suite runs

- 2026-09-29: recon only, no suite run yet.
