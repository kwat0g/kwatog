# M047 — supplier-portal audit report

Audit date: 2026-08-25  
Status: 📋 Plan Ready  
Claim: supply-chain / supplier-portal  
Scope: supplier authentication and tenancy, purchase-order visibility and acknowledgement, shipment updates and shipping documents, supplier invoicing and statement of account, delivery schedules, supplier PPAP reads, internal portal invitations and access lifecycle, SPA/API parity, migrations, routes, permissions, and focused production-readiness checks.

## Production readiness

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
