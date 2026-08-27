# M047 — supplier-portal action plan

Date: 2026-08-27
Status: 📋 Plan Ready  
Recommendation: separate implementation work; no production-code fixes in this audit session.

The re-audit verified the prior tenancy, lifecycle, exact-money, document, schedule, audit, and SPA work with focused tests. The remaining findings below are ordered by supplier-boundary and security risk. Most require policy or ownership decisions across B2B, Accounting, Auth, Quality, and the supplier SPA.

## Ordered actions

### 1. M047-R001 — Filter purchase-order detail bills to the supplier-visible status policy

- Classification/severity: Broken, P1
- Size: medium
- Session: separate-recommended
- Evidence: `api/app/Modules/B2B/Services/SupplierPortalService.php:173-180` loads every bill on an otherwise visible PO, while `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:62-71` serializes every loaded bill. The invoice list applies the visible-bill allowlist at `api/app/Modules/B2B/Services/SupplierPortalService.php:556-560`; the existing test covers only `/invoices` at `api/tests/Feature/B2B/SupplierPortalServiceTest.php:500-515`.
- Action: apply the same approved bill-status boundary to PO detail relations and add a regression fixture for draft and cancelled AP rows attached to a visible PO. Confirm whether historical paid rows remain supplier-visible with Accounting.
- Acceptance: direct PO detail calls never return draft/cancelled/internal AP rows; list, detail, dashboard, PDF, and SPA contracts agree.

### 2. M047-R002 — Enforce password expiry for supplier portal accounts

- Classification/severity: Missing, P1
- Size: medium
- Session: separate-recommended
- Evidence: supplier authenticated routes omit `CheckPortalPasswordExpiry` at `api/app/Modules/B2B/routes.php:28-31`, while customer routes include it at `api/app/Modules/B2B/routes.php:90-94`. The middleware only reads `customer_portal` at `api/app/Modules/B2B/Middleware/CheckPortalPasswordExpiry.php:17-21`; supplier users already store `password_changed_at` at `api/app/Modules/B2B/Models/SupplierPortalUser.php:40-49`, and the configured policy is 90 days at `api/database/migrations/0292_seed_security_policy_settings.php:11-18`.
- Action: extend the portal expiry policy to the supplier guard, preserve the me/change-password escape hatch, and add expired, current, and first-login supplier tests. Coordinate response semantics with the SPA.
- Acceptance: an expired supplier password cannot access operational routes, changing it restores access, and lockout/reset/first-login behavior remains distinct and auditable.

### 3. M047-R003 — Make supplier-invoice attachment cleanup transaction-aware

- Classification/severity: Broken, P1
- Size: medium
- Session: separate-recommended
- Evidence: `api/app/Modules/B2B/Services/SupplierPortalService.php:407-419` starts an outer cleanup `try` around the database transaction; the bill and attachment row are committed in `:474-512`; event dispatch and portal audit occur after commit at `:521-524`; the catch still deletes the stored path at `:527-531`.
- Action: separate transaction rollback cleanup from post-commit notification/audit failures. Preserve a committed invoice file and document row, and add a failure-injection test for event/audit exceptions plus an orphan-file check.
- Acceptance: any exception before commit removes provisional storage; any exception after commit does not delete a committed supplier invoice attachment or leave a misleading document row.

### 4. M047-R004 — Resolve the supplier authentication contract and complete the cookie-only migration

- Classification/severity: Incomplete, P1
- Size: large
- Session: separate-recommended
- Evidence: the inherited security contract says HTTP-only cookie auth and “NEVER use Bearer tokens” at `CLAUDE.md:90-101`, and bootstrap repeats the cookie-only rule at `api/bootstrap/app.php:40-43`. Supplier auth still returns a token at `api/app/Modules/B2B/Controllers/SupplierAuthController.php:39-64`, the SPA sets `Authorization: Bearer` and writes `sessionStorage` at `spa/src/api/b2b/client.ts:14-24`, and the supplier client opts into persistence at `spa/src/api/b2b/supplier.ts:18`. The exception is documented in `api/config/auth.php:14-20`, so this is an unfinished migration/contract conflict rather than an untested cross-guard issue.
- Action: Security/Auth owners must choose whether the supplier bearer exception remains supported. If migrating, move the supplier guard and SPA to the HTTP-only cookie/session contract, update CSRF/session handling, remove browser token persistence, and revise the portal runbooks/tests. If retaining the exception, explicitly amend the inherited policy and document compensating controls.
- Acceptance: one authoritative auth contract exists; source, docs, browser tests, and guards agree; no accidental mixed-mode behavior remains.

### 5. M047-R006 — Add a supplier-safe PPAP resource contract

- Classification/severity: Incomplete, P2
- Size: medium
- Session: separate-recommended
- Evidence: `api/app/Modules/B2B/Controllers/SupplierPortalController.php:327-341` returns the generic Quality resource, and `api/app/Modules/B2B/Services/SupplierPortalService.php:720-734` eager-loads PPAP elements. That resource includes review/rejection/approval metadata at `api/app/Modules/Quality/Resources/PpapSubmissionResource.php:19-27,40-46`, while its element resource emits the raw private storage path at `api/app/Modules/Quality/Resources/PpapElementResource.php:14-21`. Existing coverage checks vendor filtering/status only at `api/tests/Feature/B2B/SupplierPpapViewTest.php:29-91`.
- Action: keep Quality resources unchanged and add a B2B supplier-specific allowlist/resource. Define which PPAP status/review fields suppliers may see and replace `document_path` with an authorized download contract if documents are intended to be available. Add response-shape and private-path regression tests.
- Acceptance: the supplier endpoint exposes only the approved PPAP contract, never raw storage paths or unapproved internal review fields, while vendor scoping remains intact.

### 6. M047-R005 — Apply the B2B feature gate to supplier public auth routes

- Classification/severity: Missing, P2
- Size: small
- Session: same-session-ok
- Evidence: supplier login/logout/forgot/reset routes use only `throttle:auth` at `api/app/Modules/B2B/routes.php:19-25`, whereas customer public auth includes `feature:b2b_portals` at `api/app/Modules/B2B/routes.php:80-88` and supplier operational routes apply it at `:28-31`.
- Action: add the feature middleware consistently to supplier public routes and cover disabled-feature behavior for login, logout, forgot, and reset.
- Acceptance: disabling `b2b_portals` disables every supplier portal entry point, including unauthenticated auth endpoints.

### 7. M047-R007 — Remove invoice status filters that the API deliberately hides

- Classification/severity: Polish, P3
- Size: small
- Session: same-session-ok
- Evidence: the SPA presents Draft and Cancelled filters at `spa/src/pages/portal/supplier/invoices/index.tsx:35-46`, but the server first restricts invoices to the visible statuses at `api/app/Modules/B2B/Services/SupplierPortalService.php:556-569`; selecting either option therefore returns an empty result by design.
- Action: align the filter options with the supplier-visible status contract, or explicitly label internal statuses as unavailable. Add a small UI contract check if the filter list is maintained separately.
- Acceptance: every selectable invoice filter can produce a meaningful supplier-visible result or is clearly unavailable.

## Session decision

No production-code implementation is authorized in this session. Five actions are separate-recommended and include P1 supplier-data, authentication, and committed-file integrity risks; the two same-session items do not make the total scope small. The module remains 📋 Plan Ready.

## Verified in this session

- Supplier portal focused suite: 78 tests, 329 assertions, pass.
- Two-connection login lockout harness: 4 tests, 24 assertions, pass.
- Database used for all test commands: `ogami_test_m047_roll_d` only.
- No dependency, shared config, registry, or other-module file was changed.
