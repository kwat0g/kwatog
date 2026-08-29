# M047 — supplier-portal audit report

Audit date: 2026-08-27 (replacement-agent re-audit)
Status: 📋 Plan Ready
Claim: supply-chain / supplier-portal  
Scope: supplier authentication and tenancy, purchase-order visibility and acknowledgement, shipment updates and shipping documents, supplier invoicing and statement of account, delivery schedules, supplier PPAP reads, internal portal invitations and access lifecycle, SPA/API parity, migrations, routes, permissions, and focused production-readiness checks.

This report retains the 2026-08-25 baseline and historical findings below. The current re-audit disposition and findings in the final sections supersede that historical assessment where source changes have since landed.

## Historical baseline (2026-08-25)

Production audit: 42/100, risky. The portal has explicit supplier tenancy middleware, a separate portal guard, transaction-protected acknowledgement and invoice flows, useful focused tests, and all relevant migrations applied. It is not ready for broader production use because unapproved purchase orders and internal accounting fields cross the supplier boundary, portal lockout/reset and invitation lifecycle controls have security gaps, and money calculations use floating point in supplier-facing finance responses.

Blockers:

- Restrict supplier-visible purchase orders to the approved/portal-available policy and replace internal PurchaseOrderResource and BillResource responses with supplier-specific allowlists.
- Serialize portal login lockout and invalidate stale password-reset tokens; preserve the portal principal in security and mutation audit records.
- Prevent cross-vendor account reassignment and add an operational list, deactivate/revoke, and resend access lifecycle with explicit RBAC.
- Replace float-based dashboard and statement calculations with the BCMath Money convention and add precision tests.
- Define structured shipment and schedule contracts, content-address shipping documents, and complete the SPA's pagination/state/error behavior.

## Decision

No application source fixes were applied in this session. The dominant findings cross B2B, Purchasing, Accounting, Auth, file handling, RBAC, and SPA contracts; most are P1 security or financial issues. They are not safe as a small same-session patch. The module is released as 📋 Plan Ready with the ordered remediation work in action-plan.md.

## Findings

### M047-F001 — Supplier list exposes purchase orders outside the portal-available state policy

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- SupplierPortalService::purchaseOrders() scopes only vendor_id and applies an optional raw status filter at api/app/Modules/B2B/Services/SupplierPortalService.php:87-110; it does not apply PurchaseOrder::scopeOpen() or an explicit supplier status allowlist.
- PurchaseOrderStatus includes draft, pending_approval, approved, sent, partially_received, received, closed, and cancelled at api/app/Modules/Purchasing/Enums/PurchaseOrderStatus.php:7-16. The internal open scope limits work to approved, sent, and partially_received at api/app/Modules/Purchasing/Models/PurchaseOrder.php:105-112.
- SupplierPortalServiceTest creates factory-default draft POs and expects all three own-vendor rows to be visible at api/tests/Feature/B2B/SupplierPortalServiceTest.php:117-133; PurchaseOrderFactory defaults status to draft at api/database/factories/PurchaseOrderFactory.php:41-47.
- The documented dispatch flow says approved POs become portal available and sent follows external transmission/confirmation at docs/PROCESS-FLOWS.md:571-580.

Impact: a supplier can enumerate internal draft, approval-pending, cancelled, or otherwise non-dispatched orders by calling the API directly, even though the UI's delivery-schedule query adds a client-side sent filter.

### M047-F002 — Internal purchase-order and accounts-payable resources cross the supplier data boundary

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- Supplier dashboard, PO list/detail/PDF, invoice list/detail/PDF, and statement endpoints return internal PurchaseOrderResource or BillResource instances at api/app/Modules/B2B/Controllers/SupplierPortalController.php:61-123,226-234,289-309 and api/app/Modules/B2B/Services/SupplierPortalService.php:54-82,112-132,446-469,488-526.
- PurchaseOrderResource exposes approval steps/timestamps, budget warning data, internal remarks, PR number, vendor contact data, GRNs, bills, and approval relations when loaded at api/app/Modules/Purchasing/Resources/PurchaseOrderResource.php:15-124.
- BillResource exposes three-way variance, override and review metadata, an internal three-way-match URL, journal-entry lines, payments, source GRNs, and internal remarks at api/app/Modules/Accounting/Resources/BillResource.php:15-71.
- SupplierDeliveryResource demonstrates the intended narrow allowlist and its test asserts sensitive fields are omitted at api/app/Modules/B2B/Resources/SupplierDeliveryResource.php:19-29 and api/tests/Feature/B2B/SupplierPortalServiceTest.php:486-505.

Impact: even an otherwise valid supplier can receive internal approval, budget, AP review, variance, accounting, and workflow metadata. A vendor-specific DTO/resource contract is required; hiding fields in the current SPA is not a boundary.

### M047-F003 — Portal lockout accounting is not serialized and expired locks do not reset the strike counter

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- B2bAuthService reads the portal user, checks lock state, increments failed attempts, and clears the lock on successful login without a transaction or row lock at api/app/Modules/B2B/Services/B2bAuthService.php:55-103.
- The internal AuthService uses a transaction and lockForUpdate for the same class of login state at api/app/Modules/Auth/Services/AuthService.php:36-109; the portal implementation does not.
- Portal login retains failed_login_attempts after locked_until expires, so the next failed attempt can immediately re-lock the user; the lockout policy is documented as five strikes and fifteen minutes at api/database/migrations/0177_add_lockout_columns_to_portal_users.php:10-15.
- Existing authentication coverage proves five-strike locking and success reset, but has no expiry or concurrent-attempt test at api/tests/Feature/B2B/SupplierPortalAuthTest.php:96-121,143-165.

Impact: concurrent attempts can lose increments or bypass the intended threshold, and a legitimate user who waits out a lock can be re-locked on the first subsequent failure. Login availability and brute-force controls are not deterministic.

### M047-F004 — Successful password reset does not invalidate other unexpired reset tokens

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- PortalPasswordResetService marks only the selected token used after updating the password at api/app/Modules/B2B/Services/PortalPasswordResetService.php:51-94.
- Requesting another reset creates a separate live hashed token with its own expiry at api/app/Modules/B2B/Services/PortalPasswordResetService.php:18-48; the service does not revoke earlier unexpired tokens on request or successful reset.
- The existing reset test covers one token only at api/tests/Feature/B2B/PortalPasswordResetTest.php:70-86,107-134.

Impact: an older reset link obtained before a successful password change remains usable until expiry. Reset should be single-use per account or versioned so all prior tokens are invalidated when the password changes.

### M047-F005 — Supplier invitation can silently reassign a globally unique email to another vendor

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- PortalInvitationService uses withTrashed()->firstOrNew(['email' => lowercased email]), then force-fills vendor_id and restores/saves the record at api/app/Modules/B2B/Services/PortalInvitationService.php:51-79.
- The supplier portal user schema makes email globally unique while storing one vendor_id at api/database/migrations/0161_create_supplier_portal_users_table.php:13-27; there is no membership table or conflict check.
- The only supplier-access management route is the internal invite endpoint at api/app/Modules/B2B/routes.php:56-64; the response includes a temporary password at api/app/Modules/B2B/Controllers/PortalAccessController.php:36-52.

Impact: inviting an email already associated with vendor A can move the same credential to vendor B, changing the tenant boundary and potentially exposing vendor A's historical access or confusing both suppliers. Invitation conflict, membership, and credential-delivery policy must be explicit.

### M047-F006 — Supplier-account revocation and operational access management are missing

Classification: Missing  
Priority: P1  
Session: separate-recommended

Evidence:

- PortalAccessController exposes only POST customer and supplier invitation actions; there is no list, inspect, deactivate, revoke-token, resend, or reactivate operation at api/app/Modules/B2B/Controllers/PortalAccessController.php:22-52 and api/app/Modules/B2B/routes.php:56-64.
- SupplierPortalUser has is_active, must_change_password, and lockout fields at api/app/Modules/B2B/Models/SupplierPortalUser.php:20-60, but no M047 SPA page or API contract manages those fields.
- The supplier portal route tree and navigation contain only supplier operational pages, with no internal access-management surface at spa/src/routes/portalRoutes.tsx:11-23,37-57 and spa/src/layouts/PortalLayout.tsx:42-49; repository search found no SPA invite/revoke workflow.

Impact: operators cannot reliably see who has access, revoke a compromised credential, or resend an invitation through the product. Deactivation currently depends on an out-of-band database or implementation path rather than an auditable RBAC workflow.

### M047-F007 — Supplier-facing finance totals use floating point instead of the Money convention

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The shared Money helper uses BCMath string arithmetic and explicitly avoids float money operations at api/app/Common/Support/Money.php:7-12.
- Supplier dashboard totals use a raw aggregate, cast to float, and number_format at api/app/Modules/B2B/Services/SupplierPortalService.php:62-79.
- Statement of account buckets and total cast each balance to float and format it with number_format at api/app/Modules/B2B/Services/SupplierPortalService.php:488-526.
- Accounting Bill values are decimal:2 and BillService uses Money for totals and aging at api/app/Modules/Accounting/Models/Bill.php:36-50 and api/app/Modules/Accounting/Services/BillService.php:125-128,615-638.

Impact: decimal values can round differently across aggregation and presentation, undermining supplier statements and finance reconciliation. The portal is explicitly assigned a finance_officer role, so this is a correctness blocker rather than cosmetic formatting.

### M047-F008 — Shipping-document dedupe treats different files with the same name and size as identical

Classification: Broken  
Priority: P2  
Session: separate-recommended

Evidence:

- uploadShippingDocument deduplicates on PO, document_type, original_filename, and file_size at api/app/Modules/B2B/Services/SupplierPortalService.php:207-265.
- The database uniqueness constraint encodes the same four fields at api/database/migrations/0465_add_shipping_document_dedup_unique.php:9-35.
- The file is stored before the transaction and removed on error, but no content hash is recorded or compared at api/app/Modules/B2B/Services/SupplierPortalService.php:216-264.

Impact: a supplier can submit a revised document with the same filename and byte size and receive the prior document record instead of storing the new content. Idempotency should use a content digest, with filename/size retained as descriptive metadata.

### M047-F009 — Shipment updates are append-only free text rather than a structured current shipment state

Classification: Incomplete  
Priority: P2  
Session: separate-recommended

Evidence:

- updateShipment locks and vendor-checks the PO, but appends shipped date, carrier, tracking, ETA, and notes into remarks at api/app/Modules/B2B/Services/SupplierPortalService.php:158-203.
- The service has no structured shipment fields, update identity, current-value replacement, or idempotency key; repeated calls append duplicate or unbounded text.
- The SPA shipment mutation sends only tracking and estimated arrival despite the request/service supporting shipped date, carrier, and notes at spa/src/pages/portal/supplier/purchase-orders/detail.tsx:88-96 and api/app/Modules/B2B/Requests/Supplier/ShipmentUpdateRequest.php:16-24.

Impact: downstream receiving, logistics, and audit consumers cannot query the current carrier, date, or tracking value reliably, and retries can create contradictory history in a remarks field.

### M047-F010 — Delivery schedules accept arbitrary lines and any PO lifecycle state

Classification: Incomplete  
Priority: P2  
Session: separate-recommended

Evidence:

- storeDeliverySchedule verifies only that the decoded PO belongs to the vendor, then creates a submitted schedule without a PO-state check at api/app/Modules/B2B/Services/SupplierPortalService.php:540-576.
- StoreDeliveryScheduleRequest validates month and generic product_name/quantity/notes shapes, but does not reconcile lines to PO items, quantities, or the allowed dispatch state at api/app/Modules/B2B/Requests/Supplier/StoreDeliveryScheduleRequest.php:26-44.
- The SPA filters its PO query to sent at spa/src/pages/portal/supplier/delivery-schedules.tsx:51-55, but a direct API caller bypasses that client-only policy; duplicate vendor/PO/month requests return the original schedule unchanged at api/app/Modules/B2B/Services/SupplierPortalService.php:554-573.

Impact: a supplier can schedule a draft or unrelated PO and submit quantities that do not correspond to ordered products. The server must own the lifecycle and reconciliation rules, including whether revisions are allowed.

### M047-F011 — Supplier SPA does not consume pagination and has inconsistent state/error controls

Classification: Incomplete  
Priority: P2  
Session: separate-recommended

Evidence:

- The API paginates supplier POs and invoices, but spa/src/api/b2b/supplier.ts:80-105 returns only arrays and discards paginator metadata; the list pages render the first response without pagination, search, or filters at spa/src/pages/portal/supplier/purchase-orders/index.tsx:15-109 and spa/src/pages/portal/supplier/invoices/index.tsx:15-113.
- Deliveries and delivery schedules are unpaginated service queries at api/app/Modules/B2B/Services/SupplierPortalService.php:473-484,531-538, and the corresponding pages have no paging or detail workflow at spa/src/pages/portal/supplier/deliveries/index.tsx:13-69 and spa/src/pages/portal/supplier/delivery-schedules.tsx:24-278.
- PO detail shows acknowledgement whenever sent_to_supplier_at is absent and always offers shipment, upload, and invoice actions at spa/src/pages/portal/supplier/purchase-orders/detail.tsx:150-177, rather than deriving controls from the server's allowed state.
- The design system calls for dense, sortable, paginated tables and a pagination footer at docs/DESIGN-SYSTEM.md:435-444,495-500.

Impact: large supplier datasets are silently truncated, and the UI can offer actions that the server will reject or that are inappropriate for the PO state. The SPA needs a typed server contract, pagination footer, error states, and state-derived action gating.

### M047-F012 — Portal mutations are attributed to a system user instead of the supplier principal

Classification: Incomplete  
Priority: P2  
Session: separate-recommended

Evidence:

- Acknowledge and shipment update impersonate a system user for audit-compatible writes at api/app/Modules/B2B/Services/SupplierPortalService.php:134-203.
- PurchaseOrderService broadcasts chain activity using auth()->user() under that impersonation at api/app/Modules/Purchasing/Services/PurchaseOrderService.php:584-589; the portal supplier identity is not passed as the mutation actor.
- HasAuditLog supports actor_type and actor identity, while B2bAuthService separately records portal login identity at api/app/Common/Traits/HasAuditLog.php:26-50,85-99 and api/app/Modules/B2B/Services/B2bAuthService.php:100-103.

Impact: an audit reviewer can see a system-user write but cannot directly identify which supplier account acknowledged a PO or changed shipment data. Preserve the portal user/vendor identity and correlation alongside the internal system actor used for foreign-key compatibility.

### M047-F013 — Shipping-document resources return raw database identifiers

Classification: Incomplete  
Priority: P2  
Session: separate-recommended

Evidence:

- PortalShippingDocumentResource returns purchase_order_id and uploaded_by as raw integer fields at api/app/Modules/B2B/Resources/PortalShippingDocumentResource.php:15-35.
- The SPA declares those fields as numbers at spa/src/types/b2b.ts:221-234, while the download URL uses a hash ID and the surrounding portal contracts generally use hashed identities.
- The migration leaves uploaded_by without a foreign-key relation at api/database/migrations/0163_create_portal_shipping_documents_table.php:18-45.

Impact: raw identifiers make tenant-boundary mistakes and enumeration easier, and uploaded_by lacks referential integrity. Normalize the resource contract and record an explicit portal uploader relation or immutable audit identity.

## Verified strengths

- Authenticated supplier data routes use auth:supplier_portal, portal:supplier_portal, feature:b2b_portals, and B2BTenancyScopeMiddleware at api/app/Modules/B2B/routes.php:28-53. EnsurePortalGuard rejects non-portal principals and deactivated users at api/app/Common/Middleware/EnsurePortalGuard.php:35-61.
- Supplier tenancy applies vendor filters/global scopes to purchase orders, bills, delivery schedules, GRNs, PPAP submissions, and shipping documents at api/app/Modules/B2B/Middleware/B2BTenancyScopeMiddleware.php:42-60.
- Acknowledgement, invoice submission, document upload, and schedule creation use vendor checks and transaction/lock or idempotency paths at api/app/Modules/B2B/Services/SupplierPortalService.php:134-156,207-265,299-423,540-576; invoice creation also uses the canonical BillService.
- Portal token deactivation is tested, supplier PPAP visibility is tenant-scoped, and SupplierDeliveryResource provides a proven narrow resource pattern in api/tests/Feature/B2B/PortalTokenCrossGuardTest.php, api/tests/Feature/B2B/SupplierPpapViewTest.php, and api/tests/Feature/B2B/SupplierPortalServiceTest.php:486-505.
- The 25 supplier routes were enumerated, relevant portal migrations reported Ran, and the focused suite passed 46 tests with 169 assertions. SPA TypeScript typecheck and token-discipline audits passed.

## Verification

Passed:

- docker compose run --rm api php artisan test tests/Feature/B2B/SupplierPortalAuthTest.php tests/Feature/B2B/SupplierPortalServiceTest.php tests/Feature/B2B/PortalValidationTest.php tests/Feature/B2B/PortalTokenCrossGuardTest.php tests/Feature/B2B/PortalPasswordResetTest.php tests/Feature/B2B/SupplierPpapViewTest.php — 46 tests, 169 assertions.
- docker compose run --rm spa npm run typecheck — passed.
- docker compose run --rm spa npm run audit:tokens — passed; 769 files checked.
- docker compose run --rm api php artisan route:list --path=b2b/supplier --no-ansi — 25 supplier routes enumerated.
- Relevant supplier portal, lockout, token, schedule, document, and lifecycle migrations reported Ran.

Evidence limits:

- No focused test covers draft/pending PO exclusion, supplier resource field allowlists, lock-expiry/concurrent login, multiple reset tokens, invitation conflicts/revocation, Money precision, same-name/same-size different-file uploads, schedule line reconciliation, pagination metadata, or portal actor attribution.
- npm run audit:dynamic-routes failed with ERR_CONNECTION_REFUSED because no SPA/API HTTP server was running.
- npm run audit:api-routes failed because the audit script could not read routes from a running API container; API, queue, Reverb, SPA, and Nginx were stopped, while only the database and Redis containers were running.
- No browser-driven authenticated supplier journey, worker/scheduler execution, external invitation delivery, or production data/tenant drill was available in this session.

## Next action

Start with the separate implementation tranche in action-plan.md: establish the supplier-visible PO/resource contract and portal auth/access lifecycle first, then repair financial arithmetic and the document/shipment/schedule contracts before completing SPA pagination and state-gated workflows. Keep the current tenancy, token cross-guard, invoice idempotency, and narrow delivery-resource tests while adding negative cross-vendor and security-regression coverage.

---

## Current re-audit disposition (2026-08-27)

Status: 📋 Plan Ready
Decision: no production-code fixes in this session. The prior implementation tranche is present and the focused supplier checks are green, but the findings below remain open. Five of seven ordered actions are separate-recommended; the total scope is not small and includes P1 security/data-integrity work. The current action order is in `action-plan.md`.

### Discovery pass

- The registry card is M047 / `supply-chain` / `supplier-portal`; the claim was acquired atomically and no fallback module was used.
- Immediately before this recovery claim, the only worktree edits were the pre-existing M047 audit artifacts (`audit-report.md`, `action-plan.md`, and `status.md`); no M047 source or test file was dirty. The stale `unknown-session` lock was reclaimed only through `audit/scripts/claim-module.sh supply-chain supplier-portal 0`, and no other-module file was staged or modified by this recovery.
- Target implementation, tests, inherited docs, dependencies, routes, migrations, current diff, and mtimes were read. `audit/00-MODULE-REGISTRY.md` was not edited.
- Prior findings that are now covered by source commits/tests—supplier PO status filtering, supplier allowlist resources, lifecycle conflict/revocation, exact Money totals, document hashing/cleanup, schedule reconciliation, and portal actor audit—were treated as historical and re-checked rather than reopened.

### Hardening pass findings

#### M047-R001 — PO detail serializes non-supplier-visible AP bills

Classification: Broken
Priority: P1
Plan: medium / separate-recommended

Evidence:

- `api/app/Modules/B2B/Services/SupplierPortalService.php:173-180` eager-loads the PO's `bills` relation with no supplier-visible status predicate.
- `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:62-71` maps every loaded bill into the supplier response.
- The separate invoice list does apply the allowlist at `api/app/Modules/B2B/Services/SupplierPortalService.php:556-560`; its test only covers `/invoices` at `api/tests/Feature/B2B/SupplierPortalServiceTest.php:500-515`, not bills nested in PO detail.

Impact: a visible PO can disclose draft/cancelled/internal AP rows through PO detail even when the invoice endpoint hides them. Direct API access must obey the same bill boundary as the list/detail contract.

#### M047-R002 — Supplier password expiry is not enforced

Classification: Missing
Priority: P1
Plan: medium / separate-recommended

Evidence:

- Supplier operational routes omit `CheckPortalPasswordExpiry` at `api/app/Modules/B2B/routes.php:28-31`; the customer group includes it at `api/app/Modules/B2B/routes.php:90-94`.
- `api/app/Modules/B2B/Middleware/CheckPortalPasswordExpiry.php:17-21` only reads `customer_portal` and `CustomerPortalUser`.
- Supplier accounts retain `password_changed_at` at `api/app/Modules/B2B/Models/SupplierPortalUser.php:40-49`, and the shared setting is 90 days at `api/database/migrations/0292_seed_security_policy_settings.php:11-18`; the inherited policy also requires password expiry at `CLAUDE.md:154-165`.

Impact: a supplier can continue using an aged password after the shared expiry threshold. First-login `must_change_password` does not replace timed expiry, and no supplier expiry regression covers this route path.

#### M047-R003 — Post-commit invoice failures can delete a committed attachment

Classification: Broken
Priority: P1
Plan: medium / separate-recommended

Evidence:

- `api/app/Modules/B2B/Services/SupplierPortalService.php:407-419` wraps the transaction in an outer cleanup `try`.
- The bill and `PortalShippingDocument` row are created inside the transaction at `:474-512`; event dispatch and portal audit happen after commit at `:521-524`.
- The catch deletes `$storedPath` for any throwable at `:527-531`, including a post-commit event or audit failure.

Impact: the database can retain a submitted bill and document row while the committed invoice file is removed. Cleanup must distinguish rollback failures from post-commit notification/audit failures.

#### M047-R004 — Supplier authentication remains an undocumented-in-practice bearer exception to the canonical cookie contract

Classification: Incomplete
Priority: P1
Plan: large / separate-recommended; owner decision required

Evidence:

- The inherited security rule requires HTTP-only cookie auth and forbids bearer tokens/browser auth storage at `CLAUDE.md:90-101`; bootstrap repeats “NEVER bearer tokens” at `api/bootstrap/app.php:40-43`.
- Supplier login returns a token at `api/app/Modules/B2B/Controllers/SupplierAuthController.php:39-64`.
- The shared portal client sets `Authorization: Bearer` and persists the token in `sessionStorage` at `spa/src/api/b2b/client.ts:14-24`; the supplier client enables that persistence at `spa/src/api/b2b/supplier.ts:18`.
- `api/config/auth.php:14-20` documents that supplier remains token-based until migration, while portal runbooks still describe bearer auth. The repository therefore contains a deliberate exception without one authoritative current contract.

Impact: the supplier portal does not meet the canonical XSS-resistant cookie posture, and mixed documentation can cause an unsafe partial migration. Security/Auth must decide whether to migrate or formally retain the exception before implementation.

#### M047-R005 — Supplier public auth routes bypass the B2B feature gate

Classification: Missing
Priority: P2
Plan: small / same-session-ok

Evidence:

- Supplier login/logout/forgot/reset use only `throttle:auth` at `api/app/Modules/B2B/routes.php:19-25`.
- Customer public auth applies `feature:b2b_portals` at `api/app/Modules/B2B/routes.php:80-88`, and supplier operational routes apply it at `:28-31`.

Impact: disabling the B2B portal feature can still leave supplier authentication and reset entry points reachable. The gate should cover the complete supplier portal surface.

#### M047-R006 — Supplier PPAP reads use a generic resource with internal fields and a raw storage path

Classification: Incomplete
Priority: P2
Plan: medium / separate-recommended

Evidence:

- `api/app/Modules/B2B/Controllers/SupplierPortalController.php:327-341` returns `Quality\Resources\PpapSubmissionResource` directly.
- The service eager-loads PPAP elements at `api/app/Modules/B2B/Services/SupplierPortalService.php:720-734`.
- The generic resource includes rejection/review/approval metadata at `api/app/Modules/Quality/Resources/PpapSubmissionResource.php:19-27,40-46`; its element resource exposes `document_path` at `api/app/Modules/Quality/Resources/PpapElementResource.php:14-21`.
- Existing tests assert vendor filtering and status counts only at `api/tests/Feature/B2B/SupplierPpapViewTest.php:29-91`.

Impact: the endpoint lacks a supplier-specific allowlist and can disclose internal review fields or private storage-path metadata. Quality resources are dependencies and were not modified.

### Polish pass finding

#### M047-R007 — Invoice filters offer statuses that the API deliberately hides

Classification: Polish
Priority: P3
Plan: small / same-session-ok

Evidence: the SPA offers Draft and Cancelled at `spa/src/pages/portal/supplier/invoices/index.tsx:35-46`, while the service restricts results to supplier-visible statuses at `api/app/Modules/B2B/Services/SupplierPortalService.php:556-569`. Selecting either option produces an empty result by design.

Impact: the filter contract is confusing but not a data-boundary failure. Align the options after the API status policy is confirmed.

### Verification

- `docker compose exec -T -e DB_DATABASE=ogami_test_m047_roll_d api php -d memory_limit=768M artisan test tests/Feature/B2B/SupplierPortalAuthTest.php tests/Feature/B2B/SupplierPortalServiceTest.php tests/Feature/B2B/SupplierPortalAccessLifecycleTest.php tests/Feature/B2B/PortalPasswordResetTest.php tests/Feature/B2B/SupplierPpapViewTest.php tests/Feature/B2B/PortalTokenCrossGuardTest.php tests/Feature/B2B/PortalValidationTest.php` — 78 passed, 329 assertions.
- `docker compose exec -T -e DB_DATABASE=ogami_test_m047_roll_d api php -d memory_limit=768M artisan test tests/Feature/B2B/LoginThresholdTwoConnectionHarnessTest.php` — 4 passed, 24 assertions.
- PHP syntax checks passed for the reviewed supplier service, routes, middleware, auth/controller, and supplier PO resource files.
- `docker compose exec -T -e DB_DATABASE=ogami_test_m047_roll_d api php artisan route:list --path=b2b/supplier --no-ansi` — 25 routes enumerated.
- `docker compose exec -T spa npm run typecheck` — passed.
- All test commands used `DB_DATABASE=ogami_test_m047_roll_d`; `ogami_test` was not used.
- No source, dependency, shared config, registry, or other-module file was changed by this audit. No temporary audit file was created.

### Current handoff

The exact deferred blocker is the Security/Auth owner decision on the supplier bearer-token exception versus the inherited cookie-only policy; the PPAP field contract and supplier-visible AP bill status contract also need their owning-module decisions. These do not block audit completion, artifact commit, or claim release. Next action is a separate implementation session beginning with R001–R004, followed by the PPAP contract and the two small UI/route alignments.

---

## Re-audit + fix session (2026-08-30)

Status released: `🔁 Needs Re-audit`
Claim: `audit/scripts/claim-module.sh supply-chain supplier-portal` → **CLAIMED**
(a fresh lock, not an orphan reclaim). Working tree clean on entry.
Baseline before any change: focused supplier suite **83 passed / 353 assertions**.

Decision: fixed three contained defects (one of them new and more serious than
anything in the prior plan); deferred M047-R002 and M047-R004 as
`separate-recommended`. Reasoning for each reclassification is in `action-plan.md`.

### What the two intervening commits actually did

Both were verified against source rather than trusted:

- `2e260491` — real. `feature:b2b_portals` is now on all four supplier public
  auth routes (`api/app/Modules/B2B/routes.php:22-25`). **M047-R005 closed.**
- `7e47f752` — real. The `draft`/`cancelled` options are gone from the supplier
  invoice filter. **M047-R007 closed.**
- `f971118f` / M047-R003 — real. The cleanup `catch` in `submitInvoice` wraps only
  `DB::transaction` (`api/app/Modules/B2B/Services/SupplierPortalService.php:520-528`);
  event dispatch and portal audit sit outside it at `:530-533`. **Closed.**

### Cross-tenant isolation — measured by real HTTP requests

New drill: `api/tests/Feature/B2B/SupplierPortalCrossTenantTest.php`, 19 tests.
Two suppliers each get a vendor, portal user, `sent` PO with a line, bill,
accepted GRN, shipping document with real stored bytes, delivery schedule and a
PPAP submission with an element. Supplier A is then pointed at every one of
Supplier B's identifiers.

**Finding: no cross-tenant leak on any route probed.** Both layers hold —
`B2BTenancyScopeMiddleware`'s vendor global scope refuses the route binding (404,
`api/app/Modules/B2B/Middleware/B2BTenancyScopeMiddleware.php:42-59`), and every
service method re-checks `vendor_id` after binding (403). `HasHashId::resolveRouteBinding`
uses `$this->newQuery()` (`api/app/Common/Traits/HasHashId.php:42`), which applies
global scopes — that is *why* the binding refuses. Refusal bodies were also
asserted not to echo the victim's `po_number`/`bill_number` or a raw `"id":<pk>`,
so a refusal is not an existence oracle.

Routes probed for tenancy (25 supplier routes total; `route:list --path=b2b/supplier`):

| route | probe | result |
|---|---|---|
| `GET dashboard` | counts + recent PO/invoice fragments | scoped |
| `GET purchase-orders` | exact top-level id set | scoped |
| `GET purchase-orders/{po}` | B's hash | refused |
| `GET purchase-orders/{po}/pdf` | B's hash | refused |
| `POST purchase-orders/{po}/acknowledge` | B's hash | refused, B's row unchanged |
| `POST purchase-orders/{po}/shipment-update` | B's hash | refused, no `supplier_shipments` row |
| `POST purchase-orders/{po}/shipping-documents` | B's hash + file | refused, no row, no stored file |
| `GET purchase-orders/{po}/shipping-documents` | own + B's | scoped |
| `POST purchase-orders/{po}/submit-invoice` | B's hash | refused, no extra `Bill` |
| `GET shipping-documents/{id}/download` | B's document hash | refused, bytes not disclosed |
| `GET invoices` | exact top-level id set | scoped |
| `GET invoices/{invoice}` | B's hash | refused |
| `GET invoices/{invoice}/pdf` | B's hash | refused |
| `GET deliveries` | exact top-level id set | scoped |
| `GET statement-of-account` | vendor name, open bills, exact total | scoped |
| `GET delivery-schedules` | exact top-level id set | scoped |
| `POST delivery-schedules` | B's PO in body; own PO + B's line | both 422, nothing persisted |
| `GET ppap-submissions` | exact top-level id set | scoped |
| `POST login` / `POST forgot-password` | enumeration + throttle | see below |
| `GET portal-access/suppliers`, `POST …/{vendor}/invite` | supplier token → internal admin | 401 |

**Routes NOT covered for tenancy, and why:** `GET me`, `POST change-password`,
`POST logout` (single-principal, no cross-tenant identifier in the request);
`GET purchase-orders/shipping-documents/options` (static enum list, no tenant
data). `POST reset-password` was not driven end-to-end with a live token in this
drill — token invalidation is already covered by
`api/tests/Feature/B2B/PortalPasswordResetTest.php`.

### Files, downloads, auth surface — measured

- Uploads get randomised names under `portal/shipping-docs/<po>/` on the `local`
  disk (outside the web root) and are served only through the controller.
- A `../../evil.pdf` client filename never becomes a storage path: Symfony's
  `UploadedFile::getName()` strips separators before `getClientOriginalName()`.
- Traversal / garbage in `shipping-documents/{id}/download` is refused.
- **Server-side MIME validation is real** (`mimes:pdf,jpg,jpeg,png` sniffs content
  via finfo). A first version of this assertion used `UploadedFile::fake()` and
  reported a bypass that does not exist — `Illuminate\Http\Testing\File::getMimeType()`
  returns `MimeType::from($name)`, derived from the *filename*. Rewritten against
  a real `Illuminate\Http\UploadedFile`, the PHP payload named `.pdf` is correctly
  422'd. Recorded because the naive version is a convincing false positive.
- **The CLAUDE.md audit-guard hazard does not reproduce.** A portal
  acknowledgement writes `PurchaseOrder` (a `HasAuditLog` model) under
  `auth:supplier_portal` and both the `supplier_po.ack` portal row
  (`user_id = null`, `actor_type = supplier_portal`) and a `HasAuditLog` row whose
  `user_id` resolves to a real `users` record are written. No FK violation. The
  working remedy is `App\Common\Services\SystemUserResolver::impersonate()`
  (`api/app/Common/Services/SystemUserResolver.php:68-79`) — **not** the
  `EdgeSystemUserResolver` / `auth:edge_device` CLAUDE.md prescribes, neither of
  which exists. Note `storeDeliverySchedule` does *not* impersonate and does not
  need to: no model it writes uses `HasAuditLog`.
- Login enumeration: identical status and identical `errors.email` for a known vs
  unknown address; `forgot-password` returns one fixed message either way.
  Throttling fires within 8 attempts (`throttle:auth`). Response *timing* was not
  measured — see open questions.

### Findings

#### M047-R008 — PO detail dropped every GRN and bill (NEW)

Classification: **Broken** · Priority P1 · **Fixed this session**

Three defects were stacked in `purchaseOrderDetail()`, each concealing the next.
Found because the M047-R001 regression fixture returned **0** bills, not the 3 the
leak predicted. Direct probe of the eager load:

```
DB rows                                     -> bills=1 grns=1
load([bills, goodsReceiptNotes])            -> bills=1 grns=1
load([...:id,<cols> without the FK])        -> bills=0 grns=0
load([...:id,purchase_order_id,<cols>])     -> bills=1 grns=1
HTTP GET po detail                          -> bills=0 goods_receipt_notes=0
```

1. `api/app/Modules/B2B/Services/SupplierPortalService.php:176-177` (before) read
   `'goodsReceiptNotes:id,grn_number,received_date,status'` and
   `'bills:id,bill_number,…'`. `HasMany::match()` keys children by the foreign
   key, so omitting `purchase_order_id` from the select made every row
   unmatchable. The relation was still *loaded*, so `whenLoaded` emitted
   `bills: []` and `goods_receipt_notes: []` unconditionally. The SPA renders both
   panels only when non-empty
   (`spa/src/pages/portal/supplier/purchase-orders/detail.tsx:417,441`), so two
   sections of the supplier PO detail page had **never once displayed**.
2. `:200-205` (before) then ran `BillStatus::tryFrom((string) $bill->status)` — a
   fatal `Error: Object of class …BillStatus could not be converted to string`,
   since `Bill::$casts` maps `status` to the enum
   (`api/app/Modules/Accounting/Models/Bill.php:45`). Unreachable only because (1)
   guaranteed an empty collection; repairing (1) alone made PO detail a **500 for
   every PO that has a bill**, which is what the first fix run produced.
3. M047-R001's missing predicate (below) would then have started leaking.

#### M047-R001 — PO detail serialized non-supplier-visible AP bills

Classification: **Broken** · Priority P1 · **Fixed this session**

Real but **latent**, which the prior report could not have known: the leak could
not fire while M047-R008(1) held. The prior evidence (no status predicate on the
`bills` eager load; `SupplierPurchaseOrderResource:62-71` mapping every loaded
bill) was correct as source reading. Fixed together with R008 — fixing either
alone is wrong.

#### M047-R006 — supplier PPAP read used the internal Quality resource

Classification: **Incomplete** · Priority P2 · **Storage-path leak fixed; field
allowlist still an owner question**

Measured before the fix: `ppap/private/vault/control-plan-secret.pdf` was present
in the supplier response, from `api/app/Modules/Quality/Resources/PpapElementResource.php:20`.
Fixed with B2B-owned allowlists (`SupplierPpapSubmissionResource`,
`SupplierPpapElementResource`); Quality's resources unchanged.

#### M047-R002 — supplier password expiry is not enforced

Classification: **Missing** · Priority P1 · **Deferred, `separate-recommended`**

Confirmed by measurement, not inference:

```
security.password_expiry_days = 90
SUPPLIER  dashboard, password 150 days old => HTTP 200
SUPPLIER  purchase-orders, same           => HTTP 200
CUSTOMER  dashboard, password 150 days old => HTTP 403  code=password_expired
```

One policy, two guards, one enforcing it.
`api/app/Modules/B2B/Middleware/CheckPortalPasswordExpiry.php:19-20` reads only
`customer_portal`/`CustomerPortalUser`; the supplier group omits the middleware
(`api/app/Modules/B2B/routes.php:28`).

#### M047-R004 — supplier bearer-token exception to the cookie-only contract

Classification: **Incomplete** · Priority P1 · **Deferred, owner decision**

Unchanged and still an explicit, documented divergence. Note the contrast has
sharpened: `api/config/auth.php:22-25` now shows `customer_portal` on the
`session` driver while `supplier_portal:14-20` remains `sanctum`. So the customer
half of the migration is done and the supplier half is not.

#### M047-R009 — `can_submit_invoice` over-promises against the server rule (NEW)

Classification: **Incomplete** · Priority P2 · Not fixed

`SupplierPurchaseOrderResource:37` derives `can_submit_invoice` from PO status
alone, but `submitInvoice` additionally requires an accepted GRN
(`SupplierPortalService.php:462-470`). So the SPA shows the invoice action and the
server answers 422 "Supplier invoices for stock items require an accepted goods
receipt." Aggravated by R008: until this session the supplier could not even see
whether a GRN existed, because `goods_receipt_notes` was always empty. The prior
fix-log §11 claimed "accepted-GRN gating for invoice submission" was implemented
in the capability — it is not in the resource.

#### M047-R010 — the supplier PPAP route has no SPA client at all (NEW)

Classification: **Missing** · Priority P3 · Not fixed

`GET /b2b/supplier/ppap-submissions` is a live, tested, tenant-scoped endpoint
with **no** consumer: no `ppap` type in `spa/src/types/b2b.ts`, nothing in
`spa/src/api/b2b/supplier.ts`, no page under `spa/src/pages/portal/supplier/`,
and no nav entry. Same "fixed route with no caller" shape seen in
`procurement/purchase-requests`. Either build the page or drop the route; a
maintained endpoint nobody calls is a standing cost.

#### M047-R011 — `HashIdFilter` accepts raw integers in every environment (NEW)

Classification: **Incomplete** · Priority P3 · **Out of module — report only**

`api/app/Common/Support/HashIdFilter.php:19-21` returns `(int) $str` for any
digit string, unconditionally. `HasHashId::resolveRouteBinding` gates the same
shortcut behind `app()->environment('testing')`
(`api/app/Common/Traits/HasHashId.php:33`) precisely so "staging pentests surface
the same enumeration surface as prod" — `HashIdFilter` does not, so
`shipping-documents/2/download` and a raw `purchase_order_id: 2` are accepted in
production. **No leak results here**: every supplier consumer of `HashIdFilter` is
tenant-scoped, and that is asserted in the drill. But ID obfuscation stops being a
layer. Shared `App\Common\Support` — not this module's to change.

Related, same class: HashIDs are salted **globally**, not per model
(`api/config/hashids.php:8`), so `encode(2)` is the identical string for a
`PurchaseOrder` and a `DeliverySchedule` with pk 2 (verified: `GqkbAVwxd1`). A
hash is therefore a type-free identifier. This also broke an early version of the
drill, where `assertJsonMissing(['id' => …])` matched a *nested* `purchase_order.id`
and reported a leak that was not there — the drill now compares exact top-level id
sets. Worth knowing before writing any ID-based assertion in this codebase.

### Verification

All commands used `DB_DATABASE=ogami_test_sup`; `ogami_test` was never touched.
Containers: only `db` and `redis` were already up and neither was restarted.

- Baseline, before changes — focused supplier suite (`SupplierPortalServiceTest`,
  `SupplierPortalAuthTest`, `SupplierPortalAccessLifecycleTest`,
  `PortalPasswordResetTest`, `SupplierPpapViewTest`, `PortalTokenCrossGuardTest`,
  `PortalValidationTest`): **83 passed / 353 assertions**.
- After changes — whole `tests/Feature/B2B` folder: **145 passed / 655 assertions,
  0 failed**. (The two `CustomerPortalServiceTest` failures a prior session
  itemised as pre-existing are now green; not my change.)
- New regressions confirmed failing against **unmodified** source:
  `test_purchase_order_detail_returns_its_grn_and_bill_relations` failed first as
  `actual size 0 matches expected size 1`, then — after fixing the FK omission
  only — as `Expected response status code [200] but received 500`;
  `test_supplier_ppap_response_never_exposes_the_private_document_path` failed as
  `Failed asserting that an array does not have the key 'document_path'`.
- Dependency regression, `--filter='PurchaseOrder|AccountsPayableHardening|Ppap'`:
  **41 passed, 1 failed** — `AccountsPayableHardeningTest > supplier bill
  resource…`, which is **pre-existing and outside this module**. Confirmed not
  mine: `git diff --name-only HEAD -- api/app/Modules/Accounting api/tests/Feature/Accounting`
  is empty. It is the same failure the prior fix-log §14 raised for the AP owner.
- `php -l`: clean on all 7 changed/added PHP files.
- `phpstan analyse` on all 4 changed/added app files: **[OK] No errors**.
- `pint --test`: the 3 new files **PASS**. The 3 pre-existing files still fail, and
  this was **proved inherited** by running Pint on the `git show HEAD:` extracts
  and diffing rule lists — `SupplierPortalService.php` and
  `SupplierPortalServiceTest.php` report byte-identical rule sets to HEAD, and
  `SupplierPortalController.php` reports one rule *fewer* than HEAD
  (`fully_qualified_strict_types` cleared by replacing an inline FQN with an
  import). No pre-existing style was reformatted into this diff.
- `route:list --path=b2b/supplier`: 25 routes, unchanged by this session.

### Not verified — stated plainly

- No browser-driven authenticated supplier journey; the SPA claims about the two
  dead PO-detail panels are read from
  `spa/src/pages/portal/supplier/purchase-orders/detail.tsx:417,441` and from the
  API response shape, not from a rendered page.
- Login **timing** was not measured, only status codes and message bodies, so
  timing-based user enumeration is untested either way.
- Sanctum **abilities are not enforced anywhere on this portal**: tokens are minted
  with no ability list (`B2bAuthService.php:131` → `createToken($tokenName)`, which
  defaults to `['*']`) and no supplier route uses the `ability` middleware. This is
  "no abilities model" rather than "abilities attached but unenforced"; the drill
  asserts scope-based isolation instead, which is what actually gates access here.
- The frontend polish pass against `docs/DESIGN-SYSTEM.md` was **not** repeated;
  the prior session's SPA pagination/state work was taken as given and only the
  two contract mismatches above (R009, R010) were examined.
- Concurrency was not re-driven; `LoginThresholdTwoConnectionHarnessTest` is the
  existing two-connection coverage and passes in the folder run.

### Open questions for a human

1. **PPAP field allowlist (Quality owner).** Is
   `SupplierPpapSubmissionResource` the intended supplier contract? Keeping
   `rejection_reason` / `reviewed_at` / `approved_at` / `expires_at` / `revision`
   and dropping `submitter` / `approver` / `document_path` is my judgement, not a
   recorded decision. Should suppliers get a PPAP document download route, which
   is the only thing that would make a path-like field legitimate?
2. **M047-R004 (Security/Auth owner).** Migrate the supplier guard to the cookie
   contract now that `customer_portal` already is, or formally amend the policy and
   record compensating controls? Unblocking this is a prerequisite for closing the
   module.
3. **Supplier payment visibility (AP owner).** Still open from the prior session's
   §14, and still failing: may a supplier see the payment records applied to its
   own invoice? `SupplierPortalService::invoiceDetail()` eager-loads `payments`,
   which is what `AccountsPayableHardeningTest:229` asserts against.
4. **M047-R011 (shared-support owner).** Should `HashIdFilter::decode` gate its
   raw-integer shortcut behind the testing environment, as `HasHashId` already
   does? Harmless in this module; the question is repo-wide.
