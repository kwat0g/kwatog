# M047 — supplier-portal action plan

Date: 2026-08-25  
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session.

The focused supplier-auth, tenancy, invoice, document, PPAP, migration, route, SPA typecheck, and token-discipline evidence is green. The remaining work changes a supplier data boundary, authentication state, credential lifecycle, finance arithmetic, and several API/UI contracts. Keep the work independently reviewable and preserve hash IDs, explicit tenant scopes, private storage, transaction/lock, Money, and RBAC conventions.

## Ordered fixes

### 1. M047-F001/F002 — Establish the supplier-visible purchase-order and finance resource contract

- Classification/severity: Broken, P1
- Scope: large; Purchasing state policy, B2B supplier DTOs/resources, Accounting supplier invoice/statement fields, relation loading, PDF contracts, tenant/role tests, and legacy response compatibility
- Recommendation: separate session with Purchasing, Accounting, B2B, and Security owners
- Define the portal-available PO states and whether received/closed history remains visible. Enforce the allowlist in the service, not only in SPA filters. Replace PurchaseOrderResource and BillResource with supplier-specific allowlists that exclude approval, budget, AP review/override, internal match URL, journal, and other internal workflow data. Keep supplier financial fields limited to the agreed business contract.
- Acceptance: draft, approval-pending, cancelled, and other non-portal states are denied or excluded by direct API calls; approved/sent/receiving behavior is explicit; no internal approval, budget, variance, override, review, journal, or raw workflow fields appear in supplier responses; PO, invoice, PDF, dashboard, SOA, and SPA types agree.

### 2. M047-F003/F004 — Harden portal lockout and password reset lifecycle

- Classification/severity: Broken, P1
- Scope: medium; B2bAuthService transaction/row locking, expired-lock reset semantics, reset-token invalidation, mail/retry behavior, audit events, rate-limit interaction, and concurrent feature tests
- Recommendation: separate session with Auth and Security owners
- Mirror the internal login state contract with a transaction and lockForUpdate. Reset the strike window after an expired lock according to policy, preserve five-strike behavior, and invalidate all prior reset tokens when a reset is requested or completed. Add concurrent bad-login, lock expiry, multiple-token, replay, and deactivated-user tests.
- Acceptance: concurrent attempts cannot lose increments or bypass lockout; waiting out a lock restores normal login behavior; only the current reset flow can change the password; every old token is rejected after reset; token revocation and audit behavior remain deterministic.

### 3. M047-F005/F006 — Add safe supplier account membership and revocation operations

- Classification/severity: Broken/Missing, P1
- Scope: large; portal-user membership/conflict model, invitation policy, temporary-password delivery, internal RBAC/API, SPA operator page, deactivation and token revocation, resend/reactivation, audit events, and cross-vendor tests
- Recommendation: separate session with B2B, IAM, and Security owners
- Do not silently move a globally unique email between vendors. Choose either a vendor-membership model or an explicit conflict/one-vendor policy. Add list/detail, invite/resend, deactivate/reactivate, revoke all tokens, and audit history operations behind dedicated permissions. Do not return reusable temporary passwords in a normal API response; use the controlled invitation/reset delivery path.
- Acceptance: an existing account cannot be reassigned without an explicit authorized workflow; operators can see active, pending-change, locked, and deactivated accounts; deactivation revokes access immediately; resend and reactivation are auditable; cross-vendor attempts are denied; SPA and API roles are tested.

### 4. M047-F007 — Replace supplier finance float operations with Money arithmetic

- Classification/severity: Broken, P1
- Scope: medium; dashboard aggregates, statement-of-account buckets, decimal serialization, invoice balance display, precision regression tests, and reconciliation examples
- Recommendation: separate session with Accounting/Finance owners
- Use Money string operations for every supplier-facing total and bucket. Keep database decimal values as canonical amounts and make rounding/scale explicit at the response boundary. Test values such as 0.10 + 0.20, many fractional lines, credits, partial payments, and large totals against BillService.
- Acceptance: dashboard, SOA, invoice detail, and PDF totals reconcile exactly with accounting values; no float casts or number_format-based arithmetic remain in the portal service; precision tests pass for positive, partial, credit, and zero balances.

### 5. M047-F008/F013 — Make shipping-document identity and access integrity explicit

- Classification/severity: Broken/Incomplete, P2
- Scope: medium; content digest schema/index, dedupe/idempotency, storage transaction cleanup, supplier resource hash-ID contract, uploader relation/audit, download authorization, and migration/backfill
- Recommendation: separate session with B2B, storage, and Security owners
- Record a cryptographic content digest and use it with PO, document type, and intended idempotency semantics; filename and size remain metadata. Decide how revised documents are versioned. Replace raw PO/uploader integers in the portal resource and add referential or immutable actor integrity for uploaded_by. Preserve private download authorization and cleanup on all failure paths.
- Acceptance: different content with the same name and size is stored as a distinct revision; exact retries are idempotent; cross-vendor downloads remain denied; supplier responses use the agreed opaque identifiers; orphan files and orphan uploader references are detectable.

### 6. M047-F009/F010 — Define structured shipment and delivery-schedule contracts

- Classification/severity: Incomplete, P2
- Scope: large; shipment state schema, event/history or current-value policy, idempotency, receiving/logistics consumers, PO lifecycle validation, schedule line reconciliation, revisions, locking, and API/SPA tests
- Recommendation: separate session with SupplyChain, Purchasing, Receiving, and B2B owners
- Replace append-only shipment remarks with structured current state plus an auditable update history or event model. Validate allowed PO states and reconcile schedule lines to vendor-owned PO items and remaining quantities on the server. Decide whether duplicate month submissions are immutable, replaceable, or versioned. Keep transaction and lock boundaries around the authoritative rows.
- Acceptance: current shipment values are queryable and retries do not duplicate state; invalid/cancelled/draft PO schedules are rejected; product and quantity mismatches are rejected; valid partial schedules reconcile; revision behavior and audit actor are explicit.

### 7. M047-F011/F012 — Complete SPA parity and portal actor auditability

- Classification/severity: Incomplete, P2
- Scope: medium; typed paginator response, sortable/filterable tables, pagination footer, loading/error/empty states, server-derived action gating, shipment form fields, accessibility review, and portal-principal audit correlation
- Recommendation: separate session after the API contracts in items 1 and 6
- Preserve paginator metadata in the SPA client and implement the design-system table/pagination pattern for POs and invoices. Add bounded delivery/schedule loading or pagination. Derive acknowledgement, shipment, upload, and invoice actions from server-provided state/capabilities. Send all supported shipment fields. Store the supplier portal user/vendor identity and correlation ID alongside any system-user impersonation used for internal audit foreign keys.
- Acceptance: large result sets are navigable and sortable; failures are visible and recoverable; the UI cannot offer an action outside the server contract; form fields match the API; audit history identifies the actual supplier principal for each mutation.

## Session decision

No production-code implementation is authorized in this audit session. Four P1 boundary/security/finance findings and three cross-module contract groups require policy and ownership decisions. Do not combine them into a small validation patch or mark the module Verified on the strength of the current focused tests.

## Definition of done for the next implementation tranche

- Supplier API responses expose only the approved portal contract and only portal-available purchase orders; direct API calls cannot bypass the policy.
- Lockout, password reset, invitation, deactivation, and token revocation behavior is serialized, replay-safe, auditable, and covered by concurrent/security tests.
- Supplier finance totals use exact Money arithmetic and reconcile with Accounting.
- Shipping documents are content-addressed/idempotent, privately downloadable, and returned with opaque, referentially sound identifiers.
- Shipment and schedule states are structured, server-validated, idempotent, and useful to receiving/logistics consumers.
- SPA lists are typed, paginated, state-gated, accessible, and error-aware; supplier mutation audit records preserve the portal actor.
- Focused backend, SPA, browser, migration, worker, and live-container checks are documented and green before promotion beyond 📋 Plan Ready.
