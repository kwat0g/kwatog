# M035 — Customer Portal fix log

Session date: 2026-08-25  
Result: implementation completed for the actionable findings; released as `🔁 Needs Re-audit` because complaint order/product traceability still needs a product decision and runtime suites could not complete in this checkout.

## Implemented findings

### F-01 — Customer authentication contract

- Files: `api/config/auth.php:14-24`, `api/app/Modules/B2B/Services/B2bAuthService.php:42-166`, `api/app/Modules/B2B/Controllers/CustomerAuthController.php:40-110`, `spa/src/api/b2b/client.ts:3-18`, `spa/src/api/b2b/customer.ts:18-46`.
- Before: customer login issued a Sanctum bearer token and the SPA persisted it in `sessionStorage`.
- After: the customer guard is session-backed; login regenerates an HTTP-only session, logout/password change invalidate it, and the customer client sends credentialed cookie requests without a storage key or token setter. Supplier bearer behavior remains unchanged.

### F-02/F-03/F-12 — Response boundary, relation loading, and complaint attribution

- Files: `api/app/Modules/B2B/Resources/CustomerPortalUserResource.php:10-31`, `api/app/Modules/B2B/Controllers/CustomerPortalController.php:50-64`, `api/app/Modules/B2B/Services/CustomerPortalService.php:125-143,239-300`.
- Before: dashboard deliveries/complaints were raw model collections; order child eager loads omitted `sales_order_id`; complaint creation retained only the internal system-user attribution.
- After: dashboard data uses customer-safe resources, all order child selections include their relationship key, login/me share the customer user resource, and complaint submission writes an append-only `customer.complaint.submitted` audit event with portal actor and request metadata.

### F-04/F-05/F-10 — Password policy and auth concurrency

- Files: `api/app/Modules/B2B/Services/PortalPasswordHistoryService.php:10-77`, `api/app/Modules/B2B/Middleware/CheckPortalPasswordExpiry.php:10-43`, `api/app/Modules/B2B/Services/PortalPasswordService.php:15-48`, `api/app/Modules/B2B/Services/PortalPasswordResetService.php:15-101`, `api/app/Modules/B2B/Services/B2bAuthService.php:58-164`, `api/database/migrations/2026_08_25_120000_create_portal_password_history_table.php:9-24`.
- Before: portal password changes/resets ignored password history and expiry, login email matching was case-sensitive, and failed-attempt/token mutations were not serialized.
- After: configured portal password history is recorded/checked, customer routes gate expired passwords, email lookup is normalized, and login state plus supplier token replacement run under a user row lock/transaction.

### F-06 — Delivery schedule idempotency

- Files: `api/app/Modules/B2B/Services/CustomerPortalService.php:358-425`.
- Before: any existing customer/month row was returned even when a retry carried different lines, and a concurrent unique-index race could become a raw database error.
- After: retries compare a deterministic SHA-256 payload fingerprint; identical requests replay the existing schedule, changed requests return a business-rule 422, and the unique-index race is normalized to the same contract.

### F-07 — Exact outstanding-balance arithmetic

- Files: `api/app/Modules/B2B/Services/CustomerPortalService.php:66-102`.
- Before: the dashboard used a database sum followed by float-based formatting.
- After: balances are plucked as decimal strings, accumulated through `Money`, and returned as a two-decimal string.

### F-08 — Query validation and statement-date policy

- Files: `api/app/Modules/B2B/Controllers/CustomerPortalController.php:70-126,213-252,320-342`, `api/tests/Feature/B2B/PortalValidationTest.php:77-101`.
- Before: raw status, search, pagination, and `as_of` values reached services without consistent bounds or date validation.
- After: enum/status, search length, page/per-page, complaint date ordering, and ISO date validation occur at the controller boundary; future statement dates are rejected with validation 422 responses.

### F-09 — Proof streaming and filename safety

- Files: `api/app/Modules/B2B/Controllers/CustomerPortalController.php:165-204`, `api/tests/Feature/B2B/CustomerPortalServiceTest.php:306-319`.
- Before: proof downloads loaded the whole object into memory and interpolated the stored filename into a response header.
- After: proofs use a storage read stream, close the resource after `fpassthru`, and emit sanitized ASCII plus RFC-5987 filename parameters.

### F-13/F-14/F-15/F-16 — Pagination, dashboard completeness, date display, and keyboard access

- Files: `api/app/Modules/B2B/Services/CustomerPortalService.php:107-236,351-356`, `spa/src/api/b2b/customer.ts:82-189`, `spa/src/pages/portal/customer/orders/index.tsx:28-133`, `spa/src/pages/portal/customer/invoices/index.tsx:15-113`, `spa/src/pages/portal/customer/deliveries/index.tsx:15-106`, `spa/src/pages/portal/customer/complaints/index.tsx:26-250`, `spa/src/pages/portal/customer/delivery-schedules.tsx:27-215`, `spa/src/pages/portal/customer/dashboard.tsx:160-206`, `spa/src/pages/portal/PortalLoginPage.tsx:201-210`.
- Before: several portal lists were unbounded or discarded paginator metadata; dashboard recent panels were unused; undelivered rows showed no useful date; the password visibility button was removed from keyboard order.
- After: all growing customer lists use a shared paginated envelope and design-system footer, dashboard deliveries/complaints render safe linked panels, scheduled dates are shown until delivery, and the visibility control is keyboard reachable.

### F-18 — Public feature gating

- Files: `api/app/Modules/B2B/routes.php:67-79`, `api/tests/Feature/B2B/CustomerPortalAuthTest.php:231-252`.
- Before: customer login/logout/reset routes remained reachable when `modules.b2b_portals` was disabled.
- After: the public customer auth surface is feature-gated and the test asserts the stable `feature_disabled` response.

## Deferred findings

### F-11 — Complaint order/product traceability (human decision required)

The backend still accepts an optional customer-owned `order_id`, but the form has no order/product selectors. The current request contract explicitly prohibits `product_id` rather than silently accepting a field that CRM cannot persist. Adding selectors requires a decision about the allowed product/order relationship and the CRM/NCR persistence contract; it was not guessed in this session.

### F-17 — Browser execution

`spa/e2e/customer-portal.spec.ts:1-197` adds customer login/logout, first-login redirect, dashboard safe-link, paginated delivery/scheduled-date, and narrow-navigation coverage. Its standalone TypeScript compile passed, but Playwright execution is pending because the compose image has no installed Chromium executable. This is a verification follow-up, not a Plan Ready bounce-back.

## Verification record

- PHP lint passed for all changed/new customer-portal backend files; `git diff --check` passed.
- Compose SPA unit slice: `src/api/b2b/client.test.ts` — **4 passed**.
- Compose SPA typecheck: **blocked by 3 unrelated existing errors** in `src/pages/assets/detail.tsx` (`qrcode`/implicit-any) and `src/pages/return-management/detail.tsx` (duplicate JSX attribute); no customer-portal errors were reported.
- New Playwright spec standalone TypeScript compile: **passed**.
- Compose API feature slice was blocked before tests by unrelated migration `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`, which attempts to drop `holidays_date_name_unique` while PostgreSQL still owns it as a table constraint.
- Route-list verification was also blocked by the pre-existing missing `api/app/Modules/HR/Controllers/TrainingMatrixController.php`; no unrelated file was changed.
