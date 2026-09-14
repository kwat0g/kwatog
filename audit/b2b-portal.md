# Audit: b2b-portal — 2026-09-06

## Summary

Audited all ~57 backend files under `api/app/Modules/B2B/**`, the 26 SPA portal pages + `spa/src/api/b2b/**` + `spa/src/types/b2b.ts`, and the 12 test files in `api/tests/Feature/B2B/**`. **Tenant isolation is in good shape**: every detail/action/download endpoint carries an explicit `vendor_id`/`customer_id` ownership check in the service layer on top of the per-request `B2BTenancyScopeMiddleware` global scopes, and this is proven by an HTTP-level cross-tenant drill (`SupplierPortalCrossTenantTest`) plus 151 passing B2B tests (690 assertions, re-run during this audit). Auth hardening is largely solid: lockout, hashed single-use reset tokens, case-insensitive email-unique invitation boundary (cross-tenant account-takeover case explicitly closed), guard-bleed protection (`EnsurePortalGuard`), rate limiting on all public endpoints, CSRF via Sanctum stateful `ValidateCsrfToken` for the session-based customer portal. The audit-log FK gotcha is handled (existence-checked `Auth::id()`, explicit portal-principal audit rows). No Critical findings. Main issues: the supplier portal still uses **bearer tokens persisted in sessionStorage** (baseline violation, migration left half-done), **ERP-side Vendor/Customer deactivation does not close portal access**, and the customer portal **reuses the internal `SalesOrderResource`**, leaking internal workflow fields.

## Findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Repro |
|----|----------|----------|--------|-------|----------|----------------|
| BP-01 | Bad practice | High | M | Supplier portal auth = bearer token stored in sessionStorage | spa/src/api/b2b/client.ts:23-24; spa/src/api/b2b/supplier.ts:21,33; api/config/auth.php:18-21 | Login response returns `token`; client persists it in `window.sessionStorage` under `ogami_supplier_portal_token`. Violates project baseline ("NEVER use Bearer tokens. NEVER store auth in localStorage/sessionStorage"); XSS on an externally-facing portal steals the token (14-day Sanctum expiry, config/sanctum.php:20). Customer portal already uses HTTP-only sessions; config comment admits supplier "remains a token client until its own portal module is migrated" |
| BP-02 | Broken process | Medium | M | ERP-side Vendor/Customer deactivation does not close portal access | api/app/Modules/B2B/Services/B2bAuthService.php:77; api/app/Modules/B2B/Middleware/B2BTenancyScopeMiddleware.php:27,44; no listener in api/app/Modules/Accounting | Only the portal user's own `is_active` is checked (login + every request via EnsurePortalGuard). No code links `vendors.is_active`/soft-delete or `customers.is_active` to the portal account: deactivate or soft-delete a vendor in the ERP and its portal user keeps viewing POs, submitting invoices, uploading documents, scheduling deliveries. Identity seam from audit brief (party deactivated while session lives) is open |
| BP-03 | Bad practice | Medium | S | Customer portal serves internal SalesOrderResource, leaking ERP-internal fields | api/app/Modules/B2B/Controllers/CustomerPortalController.php:59,82,93; api/app/Modules/CRM/Resources/SalesOrderResource.php:31-50,70-90 | `salesOrderShow` loads `workOrders` then serializes with the internal resource: customer receives `next_statuses`, `is_editable`, `is_cancellable`, `work_orders` (quantity_target/quantity_produced/planned_start — internal production progress), `creator`, `deleted_at`; `mrp_plan` block would render if the relation were loaded. Supplier portal deliberately built a minimal DTO (SupplierPurchaseOrderResource header comment) — customer side skipped that step |
| BP-04 | Bad practice | Medium | S | Item-listing writes under portal guard mis-attribute audit rows | api/app/Modules/Purchasing/Services/SupplierListingService.php:75-100 (submit), 105-118 (update); api/app/Common/Traits/HasAuditLog.php:96-106 | `submit()`/`update()` run under `auth:supplier_portal` with no `SystemUserResolver::impersonate`. `Authenticate` switches the default driver, so `Auth::id()` returns the SupplierPortalUser PK; `auditActor()` then attributes the row to whichever internal User happens to share that PK (`actor_type='user'`, wrong person) or to `'system'`. Every other portal write records an explicit portal-principal row (`recordPortalAudit`); listings lose the supplier actor entirely. IATF traceability gap |
| BP-05 | Risk | Low | S | No password-expiry enforcement on supplier portal; no idle timeout on customer session | api/app/Modules/B2B/routes.php:30 vs 109; api/app/Common/Middleware/SessionTimeout.php:29-31 | Customer group mounts `CheckPortalPasswordExpiry` (90-day baseline); supplier group does not. SessionTimeout deliberately skips portal routes, so customer sessions live for full SESSION_LIFETIME with no idle cutoff; supplier tokens live 14 days |
| BP-06 | Risk | Low | S | Lockout response is an account-enumeration oracle | api/app/Modules/B2B/Services/B2bAuthService.php:152-157 | Wrong password/unknown email both return 422 "Invalid credentials" (tested), but a locked existing account returns 423 "Account locked. Try again in N minutes." — distinguishable, revealing both existence and lock state. Not covered by the enumeration test |
| BP-07 | Missing | Low | M | No HTTP-level cross-tenant test suite for the customer portal | api/tests/Feature/B2B/ (SupplierPortalCrossTenantTest has no customer counterpart) | Customer isolation is only proven at service level (CustomerPortalServiceTest). Route-binding enumeration (delivery proofs, invoice PDF, 8D report, SO chain) is untested via real HTTP, exactly the class the supplier drill exists to catch |
| BP-08 | Risk | Low | S | Password reset does not invalidate live customer sessions | api/app/Modules/B2B/Services/PortalPasswordResetService.php:134 | `tokens()->delete()` is a no-op for the session-backed customer model; an existing session cookie survives a reset (until SESSION_LIFETIME). The change-password path does invalidate; reset does not |

### Detail — High

**BP-01.** The security baseline in CLAUDE.md is absolute: Sanctum cookie auth, never bearer tokens, never auth in web storage — because HTTP-only cookies make the portals immune to XSS token theft. The customer portal was rebuilt to exactly that contract (`createPortalClient()` with no storage key; `customer.ts:21-24` comment), but the supplier portal still: (a) returns a plaintext token in the login JSON body (`SupplierAuthController::login`), (b) persists it in `sessionStorage` and re-attaches it as `Authorization: Bearer` on every call, (c) the unit test even codifies the persistence ("restores a portal token after a hard navigation"). Any XSS on the supplier portal exfiltrates a 14-day credential. Remediation is the same migration already performed for the customer portal: switch the `supplier_portal` guard to the session driver, drop the token from the login response and the storage key from the client.

### Detail — Medium

**BP-02.** Portal identity is `portal user → vendor/customer`, but the link is only enforced at invitation time. There is no event/listener on Vendor or Customer save/delete that touches portal users (grep of Accounting module finds no reference to the portal models). `B2bAuthService::login` and `EnsurePortalGuard` check only the portal user row. So the ERP's natural "cut off this supplier" action (vendor deactivate/soft-delete) silently leaves an external party with full read+write portal access — invoices can still be submitted against their old POs. Suggested fix: on Vendor/Customer deactivate/soft-delete, deactivate linked portal users (mirror the existing `PortalAccessService::deactivate` path), and re-check party liveness in `B2BTenancyScopeMiddleware` or at login.

**BP-03.** Not a cross-tenant leak (rows are scoped), but the customer portal exposes internal ERP state: which transitions the internal lifecycle allows (`next_statuses`), whether the order is internally editable/cancellable, and per-work-order production progress (`quantity_target`, `quantity_produced`, `planned_start`) for the customer's own orders. The supplier side shows the intended pattern — a portal-specific resource whose header comment explicitly says internal approval/budget/dispatch evidence must never be reused for an external principal. Create a `CustomerPortalSalesOrderResource` with the same discipline.

**BP-04.** The CLAUDE.md "HasAuditLog + custom guards" gotcha is neutralized for FK violations (existence check in `auditActor()`), but the listing flow demonstrates the *attribution* variant: under `auth:supplier_portal` the default driver is switched, `Auth::id()` yields the portal user's PK, and if an internal user shares that PK the audit row is written against the wrong person. Two concurrent suppliers submitting listings produce audit rows attributed to arbitrary internal users. Fix is the established pattern: wrap the writes in `SystemUserResolver::impersonate` and add a `recordPortalAudit`-style actor row, or give `SupplierItemListing` an `auditContextOverride`. (Related, same service: `notifyReviewers()` is called inside the submit transaction — a rollback after queueing would emit a phantom "listing awaiting review" notification.)

## Cross-module flags

- **purchasing**: BP-04 lives in `Purchasing\Services\SupplierListingService` (audit attribution under portal guard; in-transaction notification; `update()` does not re-lock the row, so a supplier edit can interleave with review approval — status itself is safe since `fill()` never writes `status`). Also: no partial unique index backs the "one pending listing per vendor+item" guard in `submit()` — two concurrent first submissions could both insert (check migration; test only covers sequential duplicates).
- **crm**: BP-03 — `SalesOrderResource` is consumed by an external portal principal; needs a portal-safe contract or a guard against external reuse.
- **accounting**: BP-02 — Vendor/Customer lifecycle has no hook into B2B portal accounts.

## What was NOT checked

- Blade views (`supplier-purchase-order.blade.php`, `supplier-bill.blade.php`) and `SupplierInvoiceStatusMail` content line-by-line.
- SPA pages only spot-checked (grep for state handling + existing vitest specs); no browser/E2E walk of portal flows.
- `StatementOfAccountService` internals (Accounting) beyond verifying it receives the scoped customer.
- Deployment config: actual `SESSION_SAME_SITE`, `SANCTUM_STATEFUL_DOMAINS`, nginx headers for portal origins — CSRF protection for the customer portal relies on Sanctum stateful middleware + SameSite=Lax defaults.
- Purchasing-side internal review UI routes for listings (outside unit).
- Only `--filter='B2B'` was run (151 pass / 690 assertions); full suite untouched.

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Re-audit verdict

The supplier bearer-token finding is resolved in the current source: both portal guards are session-backed, the supplier login response no longer returns a token, and the SPA portal client has no browser storage or `Authorization` hook. Supplier cross-tenant read/write defenses remain strong in source and in the existing supplier HTTP drill. The customer portal still crosses its external contract boundary by serializing the internal sales-order resource, and the ERP party lifecycle is still not connected to portal access. No Critical findings were identified from code reading.

### Prior finding status

| ID | Current status | Recheck |
|----|----------------|---------|
| BP-01 | **Resolved in source** | `f3fa4f61` migrated supplier auth to sessions; current guard/client/login evidence is below. |
| BP-02 | **Unresolved** | Vendor/customer `is_active` and soft-delete state still do not disable linked portal users or active sessions. |
| BP-03 | **Unresolved, expanded** | Internal `SalesOrderResource` is still used by four customer portal responses; detail also loads work orders. |
| BP-04 | **Unresolved** | Supplier item-listing Eloquent audit events still run under the portal guard without an external actor context. |
| BP-05 | **Unresolved** | Customer password expiry exists, but supplier expiry and idle timeout for both portals remain absent. |
| BP-06 | **Unresolved** | Locked accounts still return a distinguishable `423` response with remaining minutes. |
| BP-07 | **Partially addressed, still open** | Customer HTTP tests cover selected order/invoice/delivery cases, but no complete customer route-level cross-tenant matrix exists. |
| BP-08 | **Unresolved** | Password reset still deletes Sanctum tokens only; the live session-backed portal cookie is not invalidated. |

### Findings

**BP-02 — Broken process — Medium — M**

- **Location:** `api/app/Modules/B2B/Services/B2bAuthService.php:76-78`; `api/app/Common/Middleware/EnsurePortalGuard.php:48-55`; `api/app/Modules/B2B/Middleware/B2BTenancyScopeMiddleware.php:27-64`; `api/app/Modules/B2B/Services/SupplierPortalService.php:122-172`; `api/app/Modules/B2B/Services/CustomerPortalService.php:72-111,294-322,470-527`.
- **Evidence/reproduction:** Login and request authorization check only the portal-user `is_active` flag. The tenancy middleware scopes by the portal user's stored `vendor_id`/`customer_id`, not by the linked party's current liveness. Customer catalog filtering checks an active customer at `CustomerPortalService.php:142-143`, but the customer dashboard, orders, invoices, deliveries, complaints, schedules, and returns do not apply the same party-liveness gate; supplier portal queries have no equivalent vendor check. Deactivate or soft-delete a `Vendor`/`Customer` in ERP while its portal user remains active, then reuse the session against `/b2b/supplier/dashboard` or `/b2b/customer/orders`: the portal principal remains usable and the party's data/actions continue to resolve.
- **Impact:** ERP operators cannot rely on the natural vendor/customer deactivation action to cut off an external organization. A stale session can continue reading data and performing portal writes, including supplier invoice submission and customer order/complaint/return actions.
- **Cross-module flag:** `Accounting` owns `Vendor`/`Customer`; B2B must receive a lifecycle hook or re-check their authoritative state on login and every portal request.

**BP-03 — Bad practice — Medium — S**

- **Location:** `api/app/Modules/B2B/Controllers/CustomerPortalController.php:27,66,103,123,129-134`; `api/app/Modules/B2B/Services/CustomerPortalService.php:97-103,294-322`; `api/app/Modules/CRM/Resources/SalesOrderResource.php:31-52,58-61,65-93,127-130`; `spa/src/types/b2b.ts:158-174`; `spa/src/pages/portal/customer/orders/detail.tsx:116-144`.
- **Evidence/reproduction:** Customer dashboard, order creation response, order list, and order detail all instantiate `CRM\Resources\SalesOrderResource`. That resource always emits internal lifecycle/capability fields such as `next_statuses`, `is_editable`, `is_cancellable`, `submission_source`, `customer_confirmation_requested_at`, `deleted_at`, and response-resolution fields; when relations are loaded it emits internal creator, work orders, production quantities, planned start, and chain context. `salesOrderDetail()` explicitly loads `workOrders`, and the SPA renders the resulting production progress. `CustomerPortalServiceTest.php:176-202` asserts `data.work_orders`, so the exposure is currently codified by a passing-shape expectation rather than accidentally unreachable.
- **Impact:** This is not a cross-customer row leak, but it exposes ERP workflow and production information to an external customer and couples the public contract to an internal resource. The list/dashboard paths also lack a customer-visible status allowlist, so internal lifecycle rows can be returned through the same resource.
- **Cross-module flag:** `CRM` owns the resource. B2B needs a customer-specific allowlist resource and an explicit customer-visible status policy; the internal resource must not be made portal-safe by weakening its ERP contract.

**BP-04 — Bad practice — Medium — S**

- **Location:** `api/app/Modules/Purchasing/Models/SupplierItemListing.php:7,18-20`; `api/app/Modules/Purchasing/Services/SupplierListingService.php:75-101,107-119`; `api/app/Common/Traits/HasAuditLog.php:91-103`; `api/app/Modules/B2B/Controllers/SupplierListingPortalController.php:91-143`.
- **Evidence/reproduction:** `SupplierItemListing` uses `HasAuditLog`, while portal `submit()` and `update()` execute under `auth:supplier_portal` without `SystemUserResolver::impersonate()` and without a `recordPortalAudit` event. `HasAuditLog::auditActor()` sees the supplier portal user's numeric primary key, treats it as an internal user only if that unrelated `users.id` happens to exist, otherwise records `actor_type=system`; neither result carries the supplier portal user ID. Submit or update a listing as a supplier and inspect its audit row: the external actor is absent, or a numeric-ID collision can attribute the change to the wrong internal user.
- **Additional evidence:** `update()` checks `Pending` before its transaction and does not re-read/lock the listing at `SupplierListingService.php:107-117`; a concurrent Purchasing approval can therefore interleave with a supplier update. The status is not mass-assigned, but approved commercial fields can still be changed after review.
- **Impact:** IATF/accountability evidence cannot identify which external contact submitted or changed an offer, and the review boundary is not fully serialized.
- **Cross-module flag:** This is in `Purchasing\Services\SupplierListingService`; B2B owns the portal call path, while the owning module must provide the state/audit-safe write seam.

**BP-05 — Risk — Low — S**

- **Location:** `api/app/Modules/B2B/routes.php:33-40,126-133`; `api/app/Modules/B2B/Middleware/CheckPortalPasswordExpiry.php:17-43`; `api/app/Common/Middleware/SessionTimeout.php:24-31,33-49`.
- **Evidence/reproduction:** The customer route group mounts `CheckPortalPasswordExpiry`, but the supplier group does not. Neither portal group mounts `session.timeout`; `SessionTimeout` deliberately returns for routes that do not resolve as internal Sanctum routes. The comment at `SessionTimeout.php:33-37` still describes portal clients as bearer-token clients even though both guards now use sessions. A supplier with a password older than the configured expiry can continue signing in, and an authenticated supplier or customer session remains usable after the configured internal idle period because no portal idle check runs.
- **Impact:** Portal credentials have inconsistent lifecycle policy, and a stolen or unattended portal cookie has no application-level idle cutoff.

**BP-06 — Risk — Low — S**

- **Location:** `api/app/Modules/B2B/Services/B2bAuthService.php:64-67,139-152`; `api/tests/Feature/B2B/SupplierPortalAuthTest.php:143-168`; `api/tests/Feature/B2B/SupplierPortalCrossTenantTest.php:549-565`.
- **Evidence/reproduction:** Unknown email and wrong password return the generic `422` validation response, but a known locked account returns `423` with `Account locked. Try again in N minutes.` and the remaining lock duration. Send the same login request to an unknown address and to a known address after five failures: the status/body distinguish account existence and lock state. Existing tests prove the lock response and separately compare known-invalid versus unknown credentials, but do not compare locked versus unknown accounts.
- **Impact:** The public login endpoint remains an account and lock-state oracle, enabling targeted enumeration even though the ordinary invalid-credential path is generic.

**BP-07 — Missing — Low — M**

- **Location:** `api/app/Modules/B2B/routes.php:138-152,160-163`; `api/tests/Feature/B2B/CustomerPortalServiceTest.php:148-160,227-239,291-333`; `api/tests/Feature/B2B/CustomerPortalDeliveryConfirmTest.php:127-145`.
- **Evidence/reproduction:** Current customer tests cover a foreign sales-order detail, foreign invoice detail, and foreign delivery confirmation, plus some own-record paths. There is no customer equivalent of `SupplierPortalCrossTenantTest` that drives every customer identifier-bearing endpoint through HTTP. Uncovered or not matrix-proven include order chain/respond, invoice PDF, delivery detail, every delivery-proof combination, complaint 8D report, and return-request detail/source combinations. A future route-binding or relation-loading regression in any of those paths can therefore ship without the supplier drill's systematic negative assertion.
- **Impact:** Customer isolation is partly tested but not proven across the complete public route surface. This is a verification gap, not evidence that a current customer row leak was found.

**BP-08 — Risk — Low — S**

- **Location:** `api/app/Modules/B2B/Services/PortalPasswordResetService.php:118-136`; `api/config/auth.php:16-23`; `api/tests/Feature/B2B/PortalPasswordResetTest.php:141-168`.
- **Evidence/reproduction:** Reset updates the password and clears lock state, then calls `$user->tokens()->delete()` at line 134. Both portal guards are now `session` guards, so that deletes `personal_access_tokens` rows but does not invalidate an existing database-backed session. Reset a customer password from one browser while another browser remains logged into `/b2b/customer/me` or a portal data route: no source path changes the session record or otherwise revokes the existing cookie session. The test only asserts that old bearer tokens are deleted, which is no longer the credential used by the portal.
- **Impact:** Password reset does not reliably terminate sessions created with the old password after compromise or account recovery.

**BP-09 — Missing — Medium — S**

- **Location:** `api/app/Modules/B2B/Services/CustomerPortalService.php:719-755`; `api/app/Modules/B2B/Models/DeliverySchedule.php:18-20`; `api/app/Modules/B2B/Services/SupplierPortalService.php:784-797`; `api/tests/Feature/B2B/CustomerPortalServiceTest.php:597-614`.
- **Evidence/reproduction:** Customer delivery-schedule submission inserts a `DeliverySchedule` row at lines 749-754 and returns it, but neither the model has `HasAuditLog` nor the service calls `recordCustomerAudit`. The supplier counterpart records `supplier_sched.sub` at `SupplierPortalService.php:792`. Submit a customer schedule and inspect `audit_logs`: there is no external actor event for the write; the existing test asserts only the row/status. This is asymmetric with the other customer writes, which record `customer_portal` audit rows.
- **Impact:** An externally visible planning commitment has no durable portal-user, IP, user-agent, correlation, or request provenance, contrary to the roadmap auditability standard.

**BP-10 — Bad practice — Low — S**

- **Location:** `api/app/Common/Support/HashIdFilter.php:15-20`; B2B callers `api/app/Modules/B2B/Services/CustomerPortalService.php:197,607`; `api/app/Modules/B2B/Services/SupplierPortalService.php:753,850`; response callers `api/app/Modules/CRM/Services/SalesOrderResponseService.php:180-181` and `api/app/Modules/Purchasing/Services/SupplierResponseService.php:211-212`.
- **Evidence/reproduction:** The shared decoder accepts any digit-only value as a raw integer primary key before attempting HashID decoding. Consequently an external request such as customer order `items[0].product_id=1`, customer complaint `order_id=1`, supplier schedule `purchase_order_id=1`, or a numeric proposed line ID can be accepted if the resulting row passes the later ownership/business checks. The production path has no environment guard; the helper comment says raw integers are for tests, but the implementation permits them in live requests.
- **Impact:** This weakens the HashID boundary and allows raw primary-key probing/acceptance on portal payloads. Current response resources and URL bindings are predominantly HashID-safe, so this is a contract-hardening issue rather than evidence of a current cross-tenant write.
- **Cross-module flag:** The decoder is shared infrastructure and the response services are CRM/Purchasing dependencies; fix at the common request-decoding contract rather than adding ad hoc checks to one portal endpoint.

### Clean areas confirmed by source reading

- **Supplier auth migration:** `api/config/auth.php:16-23`, `api/app/Modules/B2B/Controllers/SupplierAuthController.php:43-69`, `spa/src/api/b2b/client.ts:3-14`, and `spa/src/api/b2b/supplier.ts:23-38` establish HTTP-only cookie sessions; no supplier token is returned or stored. `spa/src/api/b2b/client.test.ts:7-23` also guards against an `Authorization` default and browser storage.
- **Separate portal guards:** `api/app/Common/Middleware/EnsurePortalGuard.php:15-55` rejects guard/model mismatches and inactive portal accounts; `api/config/auth.php:16-38` uses distinct providers for supplier and customer users.
- **HashID response discipline:** Portal auth responses, portal resources, route-bound models, document downloads, and portal URLs use HashIDs in the inspected paths. The supplier cross-tenant drill covers collections, bound details, mutations, files, schedules, and PPAP rows in `api/tests/Feature/B2B/SupplierPortalCrossTenantTest.php:200-448`.
- **Supplier allowlists and file boundaries:** `SupplierPurchaseOrderResource`, `SupplierBillResource`, `CustomerPortalInvoiceResource`, `CustomerPortalComplaintResource`, and `CustomerReturnRequestResource` use portal-specific allowlists; supplier documents and customer delivery proofs are served from the `local` disk through authenticated controller paths.
- **Credential lifecycle controls:** Login lockout, throttling, case-normalized invitation uniqueness, hashed single-use reset tokens, password history, first-login gating, and operator deactivation/reactivation are present in the inspected service/tests. These controls do not close BP-02 or BP-08 because party lifecycle and session invalidation are separate boundaries.

### Verification limits

- This was a read-only code-reading re-audit at commit `56e0d431`; no tests, Docker, Artisan, browser, build, or runtime HTTP commands were run.
- The report does not verify deployed cookie attributes, `SESSION_LIFETIME`, `SANCTUM_STATEFUL_DOMAINS`, CSRF behavior across production origins, session-store behavior, queue/mail delivery, or PHP worker lifecycle behavior.
- Customer isolation evidence was inspected from the existing tests only; the missing full customer HTTP matrix was not executed or added.
- Shared-module findings are flagged for the owning module; no independent full audit of CRM, Purchasing, Accounting, Return Management, or shared auth infrastructure was performed in this unit.

### No code change

No application code, migrations, tests, registry, roadmap, or existing audit content was modified. This append is the only requested file change.
