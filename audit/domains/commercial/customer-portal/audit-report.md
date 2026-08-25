# M035 — Customer Portal audit report

Session date: 2026-08-24  
Domain: `commercial`  
Module: `customer-portal`  
Roles: customer portal, system admin, finance officer  
Dependencies read for context: auth/session, sales orders, accounts receivable, deliveries/proof, customer complaints/8D  
Recommended release status: `📋 Plan Ready`

## Executive result

The portal has a good ownership baseline: customer routes use a dedicated guard, tenancy middleware scopes the main customer-owned models, HashID resources exist for deliveries and complaints, and the focused API security/service suite passes. It is not ready for production sign-off yet. The main blockers are the bearer-token/sessionStorage design contradicting the repository authentication contract, raw model serialization on the dashboard, incomplete portal password policy enforcement, financial float conversion, and missing browser-level role/flow coverage.

No production code was changed in this session. Findings are recorded below and sequenced in [action-plan.md](action-plan.md).

## Discovery

### Surface and controls

- Backend routes are in `api/app/Modules/B2B/routes.php:66-98`. Authenticated customer routes use `auth:customer_portal`, `portal:customer_portal`, `feature:b2b_portals`, and `B2BTenancyScopeMiddleware`; the public login/reset routes use throttling but do not use the feature gate (`:67-76`).
- Customer ownership is checked again in `api/app/Modules/B2B/Services/CustomerPortalService.php:115-124`, `:156-162`, `:182-196`, and `:241-249`. The tenancy middleware provides a useful defense-in-depth scope in `api/app/Modules/B2B/Middleware/B2BTenancyScopeMiddleware.php:23-41`.
- The portal covers dashboard, orders/detail/chain, invoices/PDF, deliveries/proof, complaints/8D, statement of account, and delivery schedules. The SPA route and navigation surface is in `spa/src/routes/portalRoutes.tsx:60-71` and `spa/src/layouts/PortalLayout.tsx:51-59`.
- The module has backend feature tests in `api/tests/Feature/B2B/CustomerPortalAuthTest.php`, `CustomerPortalServiceTest.php`, `PortalPasswordResetTest.php`, `PortalTokenCrossGuardTest.php`, and `PortalValidationTest.php`. Only the API client storage behavior is covered on the SPA side by `spa/src/api/b2b/client.test.ts`; no customer-portal browser flow spec was found.

### Verification performed

- `docker compose run --rm api php artisan test --filter='CustomerPortal|PortalValidation|PortalToken|PortalPasswordReset'`: **32 passed, 125 assertions**.
- `docker compose run --rm spa npm run test:run -- src/api/b2b/client.test.ts`: **3 passed**.
- `npm run typecheck` in `spa`: **passed**.
- Host-shell variants were environment-limited rather than application results: the API shell could not resolve the compose-only `db` hostname, and host Vitest could not write the root-owned `spa/node_modules/.vite-temp` cache. The compose reruns above are the authoritative focused results.

## Hardening findings

### F-01 — Broken — portal auth violates the project cookie-auth contract (P0)

Evidence: `CLAUDE.md:92-101` and `:580` require HTTP-only cookies and explicitly prohibit bearer tokens and browser storage; `api/bootstrap/app.php:40-42` describes stateful SPA cookie auth; `spa/src/api/b2b/client.ts:13-37` sends `Authorization: Bearer` and persists the token in `sessionStorage`; `spa/src/api/b2b/customer.ts:20-33` wires the customer portal to `ogami_customer_portal_token`.

Impact: any script executing in the SPA origin can read the customer portal token and replay it. The implementation and its tests currently encode the opposite of the repository security contract. This is a large authentication migration, not a cosmetic inconsistency.

### F-02 — Broken — dashboard returns raw delivery and complaint models (P0)

Evidence: `api/app/Modules/B2B/Controllers/CustomerPortalController.php:53-57` wraps only orders and invoices; `api/app/Modules/B2B/Services/CustomerPortalService.php:75-91` returns raw `Delivery` and `CustomerComplaint` collections. The models expose broad attributes, including internal fields, at `api/app/Modules/SupplyChain/Models/Delivery.php:22-45` and `api/app/Modules/CRM/Models/CustomerComplaint.php:23-50`. Safe customer-facing resources already exist at `api/app/Modules/B2B/Resources/CustomerDeliveryResource.php:11-50` and `api/app/Modules/CRM/Resources/CustomerComplaintResource.php:11-76`.

Impact: dashboard response shape can expose integer primary/foreign keys and internal operational/audit fields, and it is inconsistent with the HashID/no-internal-data contract. Serialize both fields through explicitly customer-safe resources or DTOs and add response-key assertions.

### F-03 — Broken — order detail eager loads omit relationship foreign keys (P1)

Evidence: `api/app/Modules/B2B/Services/CustomerPortalService.php:119-124` selects child columns for `deliveries`, `invoices`, and `workOrders` without `sales_order_id`. These are `HasMany` relationships, so Eloquent cannot reliably match the loaded children back to the parent when the foreign key is absent. `spa/src/pages/portal/customer/orders/detail.tsx:56-127` renders work orders and related data, so a portal user can receive a successful detail response with missing sections.

Impact: customer-visible order traceability is silently incomplete. Include each relationship foreign key in the selected columns and add a fixture assertion that the detail response contains a related delivery, invoice, and work order when present.

### F-04 — Incomplete — portal password history and expiry policies are not enforced (P0)

Evidence: `api/app/Modules/B2B/Services/PortalPasswordService.php:17-43` and `api/app/Modules/B2B/Services/PortalPasswordResetService.php:51-94` update the portal password but do not consult or write `password_history`. `api/app/Common/Middleware/CheckPasswordExpiry.php:25-60` only applies age checks after confirming the principal is the internal `User` (`:36-39`), while the portal routes only use `portal.password.changed` at `api/app/Modules/B2B/routes.php:76-80`. The configured security policy is seeded at `api/database/migrations/0292_seed_security_policy_settings.php:13-17`.

Impact: portal users can reuse recent passwords and their passwords do not age out under the configured policy. Define whether the portal is in or out of the global policy; if in, share the history/expiry implementation with the portal models and add reset/change/expiry tests for both portal types.

### F-05 — Incomplete — login lockout and token replacement are race-prone (P1)

Evidence: `api/app/Modules/B2B/Services/B2bAuthService.php:74-99` increments and saves failed attempts without a row lock/atomic update, then deletes and creates tokens without serializing concurrent successful logins.

Impact: concurrent invalid attempts can overwrite one another and fail to count toward the threshold; concurrent valid logins can interleave token revocation/creation. Add a transaction with `lockForUpdate()` or atomic guarded updates, define the intended one-session semantics, and add concurrency-oriented tests.

### F-06 — Incomplete — delivery-schedule idempotency is not deterministic under retries or concurrency (P1)

Evidence: `api/app/Modules/B2B/Services/CustomerPortalService.php:289-315` returns any existing customer/month row, even when the submitted lines differ, and otherwise relies on the partial unique index at `api/database/migrations/0464_make_delivery_schedule_customer_id_nullable.php:51-55` to reject a race.

Impact: a changed request can receive a `201`-style success while its payload is silently ignored, and a simultaneous first submission can surface a raw unique-constraint failure. Choose an explicit same-payload/idempotency-key or conflict/update contract, catch/retry the unique race, and test both cases.

### F-07 — Incomplete — dashboard outstanding balance converts financial data through float (P1)

Evidence: `api/app/Modules/B2B/Services/CustomerPortalService.php:62-67` aggregates invoice balances and `:87` formats `(float) $totalOutstanding`. Invoice monetary fields are decimal strings (`api/app/Modules/Accounting/Models/Invoice.php:36-47`), while the canonical SOA path uses exact `Money` operations (`api/app/Modules/Accounting/Services/StatementOfAccountService.php:39-71`).

Impact: binary floating-point conversion can produce rounding discrepancies for large or fractional totals. Keep the sum as an exact decimal string and use the shared `Money` helper before formatting the API value; add boundary/rounding assertions.

### F-08 — Incomplete — query parameters are not validated at the module boundary (P1)

Evidence: `api/app/Modules/B2B/Controllers/CustomerPortalController.php:67-71`, `:105-107`, and `:234-245` pass raw `status`, `search`, `per_page`, and `as_of` values through. `CustomerPortalService.php:110-112` and `:151-153` cap `per_page` but do not enforce a positive lower bound. `api/app/Modules/Accounting/Services/StatementOfAccountService.php:37-41` calls `Carbon::parse($asOf)` directly.

Impact: malformed `as_of` can become a 500, invalid page sizes can reach pagination, and future/ambiguous statement dates are accepted without a documented policy. Add customer query FormRequests or typed query objects with enum/date/length/range rules and a deliberate future-date policy.

### F-09 — Incomplete — proof streaming reads the entire file into memory and trusts the filename header (P2)

Evidence: `api/app/Modules/B2B/Controllers/CustomerPortalController.php:160-178` authorizes the proof, then uses `$disk->get()` inside a stream callback and interpolates `$proof->file_name` into `Content-Disposition`.

Impact: large proofs can create avoidable memory spikes, and an untrusted filename needs header-safe encoding. Use a storage read stream/response helper, sanitize or RFC-5987 encode the filename, and add a large-file/content-disposition test.

### F-10 — Incomplete — email matching is inconsistent across portal auth flows (P2)

Evidence: `api/app/Modules/B2B/Services/B2bAuthService.php:54-60` performs an exact email lookup, while reset lookup normalizes with `LOWER(email)` in `api/app/Modules/B2B/Services/PortalPasswordResetService.php:68-73` and invitation/reset entry points normalize input. On PostgreSQL, a case variation can therefore reset successfully but fail to log in.

Impact: valid users can receive “invalid credentials” after entering a differently cased address. Normalize at the boundary and enforce a consistent unique/index strategy.

## Product and frontend findings

### F-11 — Missing — complaint form does not provide order/product traceability (P1)

Evidence: the request accepts an optional validated HashID order at `api/app/Modules/B2B/Requests/Customer/CreateComplaintRequest.php:25-40`; the service resolves and verifies it at `api/app/Modules/B2B/Services/CustomerPortalService.php:210-223`. The SPA type advertises `order_id` and `product_id` at `spa/src/api/b2b/customer.ts:145-151`, but the form submits only severity, description, and quantity at `spa/src/pages/portal/customer/complaints/index.tsx:42-47` and renders no selectors at `:92-121`.

Impact: most portal complaints enter CRM without an order or product link, weakening quality/NCR traceability and customer follow-up. Confirm whether unlinked complaints are intentional; otherwise provide customer-owned order/product selectors, validate both IDs, and test the cross-module write. This is cross-module work and should not be patched as a quick UI-only change.

### F-12 — Incomplete — complaint audit attribution collapses to a system user

Evidence: `api/app/Modules/B2B/Services/CustomerPortalService.php:225-237` intentionally calls `SystemUserResolver->impersonate()` and supplies the internal system user to `ComplaintService`. The complaint model’s `created_by` is an internal `User` relationship (`api/app/Modules/CRM/Models/CustomerComplaint.php:27-35`, `:77-80`).

Impact: the durable complaint/NCR record cannot directly identify which portal account submitted it; only surrounding auth logs can provide that association. Add a first-class external actor/source field or an audit payload that records portal type, portal user HashID, email, and request ID while preserving the valid internal audit foreign key.

### F-13 — Missing — list endpoints and pages do not implement the product’s pagination contract (P1)

Evidence: orders and invoices paginate in `api/app/Modules/B2B/Services/CustomerPortalService.php:97-112` and `:145-153`, but the SPA API types return arrays and the pages call them without page parameters (`spa/src/api/b2b/customer.ts:83-101`, `spa/src/pages/portal/customer/orders/index.tsx:15-25`, `spa/src/pages/portal/customer/invoices/index.tsx:15-25`). Deliveries, complaints, and schedules are unbounded `get()` collections at `CustomerPortalService.php:167-180`, `:201-205`, and `:282-287`. The design system requires dense paginated lists with a footer at `docs/DESIGN-SYSTEM.md:494-500`.

Impact: large customer accounts can receive oversized responses and the UI silently drops paginator metadata. Define a shared portal list envelope, validate page size, add pagination to every growing list, and implement loading/empty/error/page controls in the SPA.

### F-14 — Incomplete — dashboard API and UI disagree about recent panels (P2)

Evidence: the backend returns `recent_deliveries` and `recent_complaints` at `api/app/Modules/B2B/Services/CustomerPortalService.php:75-91`, and the TypeScript contract includes them at `spa/src/types/b2b.ts:375-383`; the dashboard renders only recent orders and invoices at `spa/src/pages/portal/customer/dashboard.tsx:78-158`.

Impact: either the API performs unused work or the customer is missing useful delivery/quality information. Decide the intended dashboard surface, then remove unused fields or add safe, linked panels with loading/empty states.

### F-15 — Incomplete — delivery list shows the wrong date for undelivered records (P2)

Evidence: `api/app/Modules/B2B/Resources/CustomerDeliveryResource.php:22-24` supplies both `scheduled_date` and `delivered_at`, but `spa/src/pages/portal/customer/deliveries/index.tsx:51-71` labels the column “Delivery Date” and displays only `delivered_at`.

Impact: scheduled/loading/in-transit deliveries display `—` instead of the date customers need. Display scheduled date until delivery completes, then show delivered/confirmed timing explicitly.

### F-16 — Polish — password visibility control is removed from keyboard order (P2)

Evidence: `spa/src/pages/portal/PortalLoginPage.tsx:201-210` sets `tabIndex={-1}` on the interactive show/hide button. The design system requires all interactive elements to be keyboard reachable at `docs/DESIGN-SYSTEM.md:523-531`.

Impact: keyboard and assistive-technology users cannot operate an available password control. Remove the negative tab index and retain the accessible name/focus styling.

### F-17 — Missing — customer portal browser coverage is absent (P1)

Evidence: the only customer-portal SPA test found is `spa/src/api/b2b/client.test.ts:1-38`, which tests token/header storage behavior rather than rendered routes. The documented customer role matrix requires own orders, deliveries, invoices, SOA, complaints, and separation from supplier/employee data (`docs/AUTO-BROWSER-TESTS.md:114-130`), and the defense traceability list expects portal screen/test proof (`docs/DEFENSE-TRACEABILITY.md:88-92`).

Impact: the highest-risk role boundary, responsive layout, redirects, password-change gate, pagination, PDF/proof flows, and complaint submission are not protected by a browser-level regression. Add Playwright coverage for a customer fixture, a supplier-token/internal-token cross-guard, mobile/desktop layouts, and the documented happy/error paths.

### F-18 — Incomplete — public portal routes bypass the feature flag (P2)

Evidence: `api/app/Modules/B2B/routes.php:67-73` places login, logout, forgot-password, and reset-password outside `feature:b2b_portals`; only the authenticated group at `:76` is feature-gated.

Impact: disabling the portal still exposes its authentication and reset surface. Confirm the intended operational behavior; if disabling means fully off, apply the feature gate to the public routes as well and test the disabled response without revealing account state.

## Positive controls observed

- Customer ownership is checked in the service layer as well as through `B2BTenancyScopeMiddleware`.
- Login throttling, inactive/locked checks, token cross-guard tests, password-change gating, and generic reset responses are present.
- Delivery/proof and complaint resources demonstrate the intended customer-safe HashID boundary; the raw dashboard path is the exception that should be brought into the same pattern.
- Focused backend tests pass in the compose environment, including cross-customer denial and portal-token isolation.

## Release decision

`📋 Plan Ready`. The module is released for planning only. The majority of fixes are security, financial, concurrency, or cross-module changes and are recommended for separate sessions. The small UI polish items can be bundled after the security/data contracts are settled.
