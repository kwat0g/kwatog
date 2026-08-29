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

---

# Action plan — revision 2026-08-30

Status: `🔁 Needs Re-audit`
Prior plan items 6 (M047-R005) and 7 (M047-R007) are **closed** by commits
`2e260491` and `7e47f752`; item 3 (M047-R003) is closed by `f971118f`. All three
were verified against source, not taken on trust.

Items 1 (M047-R001) and 5 (M047-R006, storage-path half) are **done this session**.
What remains is ordered below.

## Done this session

### ✅ M047-R008 + M047-R001 — PO detail relations and the bill allowlist

- Broken, P1. Scope: small. **Reclassified `same-session-ok`** from the prior
  plan's `medium / separate-recommended`, and the reclassification is the point:
  R001 turned out to be *latent* behind a worse defect (the eager loads omitted
  the `purchase_order_id` foreign key, so both relations always resolved empty),
  and behind that a third — a fatal `(string) $enum` in dead code. Judged on
  containment: one `load()` array and one deleted loop in one method, no state
  machine, no Money arithmetic, no permission or guard change. Fixing R001 alone
  would have been *wrong* — it had to land with R008 or PO detail would have
  started 500ing. Details and before/after in `fix-log.md` §16.

### ✅ M047-R006 (partial) — supplier PPAP storage-path leak

- Incomplete, P2. Scope: small. **Reclassified `same-session-ok`** for the
  security half only: two new B2B-owned resource classes plus one controller line,
  with Quality's resources untouched. Judged on containment — it adds an allowlist
  in this module rather than editing a dependency. The *wider field allowlist*
  remains an owner question (below). `fix-log.md` §17.

## Ordered remaining actions

### 1. M047-R002 — enforce password expiry for supplier portal accounts

- Classification/severity: Missing, P1
- Size: medium
- Session: **`separate-recommended`**
- Evidence: measured, not inferred — supplier HTTP 200 with a 150-day-old password
  against a 90-day policy, customer HTTP 403 `password_expired` for the identical
  age (`fix-log.md` §18). `CheckPortalPasswordExpiry.php:19-20` reads only
  `customer_portal`; `routes.php:28` omits the middleware from the supplier group.
- Why not this session: it changes an **authentication gate on an externally
  facing portal**, which the session criteria call out directly. Concretely, the
  supplier SPA has no handler for the `password_expired` code, so enabling the
  gate without the client work would hard-brick an expired supplier with no route
  to change their password. The API and SPA halves must land together.
- Action: generalise the middleware to resolve either portal guard (or add a
  supplier sibling), add it to the supplier authenticated group, keep `me` and
  `change-password` reachable exactly as the customer path does, and add a
  `password_expired` handler to the supplier SPA client. Tests: expired, current,
  and first-login supplier accounts, plus the escape-hatch routes.
- Acceptance: an expired supplier password cannot reach operational routes,
  changing it restores access, and `must_change_password` stays a distinct signal
  from timed expiry.

### 2. M047-R004 — resolve the supplier authentication contract

- Classification/severity: Incomplete, P1
- Size: large
- Session: **`separate-recommended`; owner decision required first**
- Evidence: `config/auth.php:14-20` keeps `supplier_portal` on `sanctum` while
  `:22-25` now has `customer_portal` on `session`, so the customer half of the
  migration is complete and the supplier half is not. Supplier login returns a
  token (`SupplierAuthController.php:39-64`); the SPA sets `Authorization: Bearer`
  and persists it in `sessionStorage` (`spa/src/api/b2b/client.ts:14-24`). CLAUDE.md
  forbids both.
- Action: Security/Auth decides migrate vs. formally retain. If migrating, the
  customer portal is now the worked example to follow.
- Acceptance: one authoritative contract; source, docs, guards and tests agree.

### 3. M047-R009 — make `can_submit_invoice` agree with the server rule

- Classification/severity: Incomplete, P2
- Size: small
- Session: `same-session-ok`
- Evidence: `SupplierPurchaseOrderResource:37` gates on PO status only;
  `SupplierPortalService.php:462-470` also requires an accepted GRN, so the SPA
  offers an action the server refuses with 422. The prior fix-log §11 claimed this
  gating existed; it does not.
- Action: publish accepted-GRN presence in the capability on **both** the list and
  detail paths — the list currently has only `withCount('goodsReceiptNotes')`
  (unfiltered by status), so it needs a status-constrained count. Add a test that
  the capability is false when no accepted GRN exists and that the button-visible
  case actually succeeds.
- Acceptance: every action the capability advertises succeeds; no 422 reachable
  from an enabled control.

### 4. M047-R006 (remainder) — ratify the supplier PPAP field contract

- Classification/severity: Incomplete, P2
- Size: small once decided
- Session: `separate-recommended` (needs the Quality owner)
- Action: confirm or amend the allowlist now shipping in
  `SupplierPpapSubmissionResource`, and decide whether a supplier PPAP document
  download route should exist. If yes, add it with an ownership check and replace
  `has_document` with a download URL.
- Acceptance: the contract is a recorded decision rather than an auditor's
  judgement.

### 5. M047-R010 — resolve the PPAP endpoint with no client

- Classification/severity: Missing, P3
- Size: medium (build) or small (remove)
- Session: `same-session-ok` either way
- Evidence: no PPAP type, API function, page or nav entry anywhere in `spa/`.
- Action: build the supplier PPAP list page against the new resource, or delete the
  route and its service method. Do not leave it as-is.

### 6. M047-R011 — `HashIdFilter` raw-integer shortcut (NOT this module)

- Classification/severity: Incomplete, P3 · **report only, do not fix here**
- Evidence: `api/app/Common/Support/HashIdFilter.php:19-21` accepts any digit
  string in every environment, while `HasHashId::resolveRouteBinding` gates the
  same shortcut behind `environment('testing')`. Shared `App\Common\Support`.
- Action: hand to the shared-support / `platform/auth-session` owner. No supplier
  leak results — every consumer here is tenant-scoped and the drill asserts it.

## Session decision

Fixed the three contained defects (R008, R001, R006-security) and deferred the two
that touch an auth guard or the auth contract. That split follows the stated
criteria: R008/R001/R006 are containment-scoped additions of a missing boundary
inside this module; R002 and R004 change authentication behaviour for an external
principal and need coordinated SPA/owner work. Released `🔁 Needs Re-audit`
because items 1–5 remain open.
