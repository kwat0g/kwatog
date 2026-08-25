# M047 — supplier-portal fix log

Implementation session: 2026-08-25  
Claim: supply-chain / supplier-portal  
Source scope: the supplier portal module and the explicitly required B2B/Auth/Accounting/RBAC/SPA contracts it consumes. Existing unrelated working-tree changes were preserved.

## 1. M047-F001/F002 — supplier-visible PO and finance contracts

- `api/app/Modules/B2B/Services/SupplierPortalService.php:50-188,551-586`: Before, PO and bill reads were vendor-scoped but accepted arbitrary lifecycle/status rows and returned internal relation graphs. After, PO reads use an explicit approved/sent/receiving/history allowlist, invalid filters yield no rows, hidden detail rows return 404, bill reads use an explicit supplier-visible status allowlist, and pagination bounds are enforced.
- `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:17-96`: Before, supplier endpoints used the internal PO resource. After, a supplier-only DTO exposes hashed IDs, operational PO/line/GRN/bill fields, and server-owned capabilities while omitting approval, budget, internal remarks, and workflow evidence.
- `api/app/Modules/Accounting/Resources/SupplierBillResource.php:14-65` and `SupplierBillItemResource.php:14-29`: Before, supplier invoice responses used the internal AP resource. After, supplier-safe bill/item/payment fields are allowlisted and internal GL, variance, review, journal, and provenance fields are excluded.
- `api/app/Modules/B2B/Services/SupplierPortalPdfService.php:21-132` and `api/app/Modules/B2B/Views/supplier-*.blade.php:1-55`: Before, supplier PDF endpoints reused internal Accounting/Purchasing PDF contracts. After, dedicated supplier views and exact string money formatting are rendered through the private document vault.
- `api/app/Modules/B2B/Controllers/SupplierPortalController.php:65-104,226-234,288-309`: Before, dashboard, PO, invoice, and SOA endpoints wrapped internal resources. After, each uses the supplier DTO contract; PDF ownership is checked before rendering.

## 2. M047-F003/F004 — lockout and reset lifecycle

- `api/app/Modules/B2B/Services/B2bAuthService.php:42-157`: Before, portal login state was read/updated without a row lock and expired locks retained the strike count. After, login state is serialized in `DB::transaction()` with `lockForUpdate()`, expired locks clear both lock and strike state, successful logins reset state, and supplier token replacement is serialized.
- `api/app/Modules/B2B/Services/PortalPasswordResetService.php:25-139`: Before, reset requests accumulated usable tokens and successful reset consumed only the selected token. After, requests invalidate outstanding tokens under the locked portal-user row, reset consumes all remaining tokens for that account, resets lock state, and revokes portal sessions.
- `api/app/Modules/Auth/Services/AuthAuditLogger.php:37-99` and `api/app/Modules/B2B/Services/B2bAuthService.php:189-216`: Before, rich portal event names could overflow the 20-character audit action column. After, audit actions use bounded compact values while the original event and portal principal remain in audit/log metadata.

## 3. M047-F005/F006 — supplier account conflict and operations

- `api/app/Modules/B2B/Services/PortalInvitationService.php:54-105`: Before, an email already belonging to another vendor could be reassigned by `firstOrNew`/`forceFill`. After, the normalized email is checked under a row lock and cross-vendor or inactive-account reassignment is rejected; same-vendor active invitation rotation revokes old bearer tokens.
- `api/app/Modules/B2B/Services/PortalAccessService.php:24-188`: Before, there was no operational supplier-access service. After, list/search/status/vendor filtering, invite/resend, deactivate/reactivate, session revocation, transaction locks, token deletion, and internal actor audit records are implemented. Resend cannot implicitly reactivate a deactivated account.
- `api/app/Modules/B2B/Controllers/PortalAccessController.php:42-112` and `api/app/Modules/B2B/routes.php:58-77`: Before, only invite routes existed. After, supplier access routes expose explicit view/manage RBAC for list, invite, resend, deactivate, reactivate, and token revocation; lifecycle bindings include soft-deleted accounts.
- `api/database/seeders/RolePermissionSeeder.php:207-209,516-533`: Before, the route permissions were not in the catalog/finance role. After, `b2b.portal_access.view/manage` are catalogued and granted to the finance officer module set.
- `spa/src/api/b2b/portal-access.ts:1-27`, `spa/src/pages/accounting/portal-access.tsx:1-214`, `spa/src/routes/accountingRoutes.tsx:39,72-73`, `spa/src/components/layout/Sidebar.tsx:519-523`: Before, no operator UI/API contract existed. After, the paginated access table, filters, invite/resend/lifecycle/session actions, loading/error/empty states, confirmations, and permission-gated route/navigation are present; temporary passwords are not returned to the SPA.

## 4. M047-F007 — exact supplier finance arithmetic

- `api/app/Modules/B2B/Services/SupplierPortalService.php:109-126,611-645`: Before, dashboard and SOA totals used aggregate/float/`number_format` arithmetic. After, all supplier totals and aging buckets use `Money` string operations and return canonical two-decimal strings.
- `spa/src/types/b2b.ts:50-73,294-315` and supplier bill/statement pages: Before, the UI contract assumed mixed internal/float-shaped finance fields. After, supplier bill/PO values are represented as strings and rendered through the supplier contract.

## 5. M047-F008/F013 — document identity and uploader integrity

- `api/app/Modules/B2B/Services/SupplierPortalService.php:288-369,477-511`: Before, document idempotency used filename plus byte size and invoice uploads had no content fingerprint. After, SHA-256 content hashes drive dedupe, stored files are cleaned on failure, supplier document uploads are state-gated, and invoice uploads persist the same fingerprint.
- `api/database/migrations/2026_08_25_235600_harden_portal_shipping_document_identity.php:11-50` and `api/app/Modules/B2B/Models/PortalShippingDocument.php:17-48`: Before, the legacy uniqueness rule could collide different content and `uploaded_by` had no referential constraint. After, the old index is replaced with `(purchase_order_id, document_type, content_sha256)`, orphan uploader references are nulled before the FK is added, and the uploader relation includes soft-deleted users.
- `api/app/Modules/B2B/Resources/PortalShippingDocumentResource.php:10-42` and `spa/src/types/b2b.ts:243-263`: Before, raw PO/uploader integers were returned. After, PO and uploader identities use hash IDs and the uploader is a bounded `{id,name}` object.
- `api/app/Modules/B2B/Services/SupplierPortalService.php:768-790`: Before, portal mutations were not attributable to the external principal. After, supplier actor/vendor IDs, request metadata, and correlation IDs are recorded in append-only portal audit rows for acknowledgement, shipment, document, invoice, and schedule mutations.

## 6. M047-F009/F010 — structured shipment and schedule contracts

- `api/database/migrations/2026_08_25_235500_create_supplier_shipment_state_tables.php:13-42`, `api/app/Modules/B2B/Models/SupplierShipment.php:16-48`, and `SupplierShipmentUpdate.php:13-42`: Before, shipment data was appended to PO remarks. After, current carrier/date/tracking/ETA/notes are stored in a one-to-one structured state row with immutable update snapshots and portal-user references.
- `api/app/Modules/B2B/Services/SupplierPortalService.php:220-281`: Before, shipment updates appended unbounded free text and lacked current-value/idempotent state. After, the PO is locked, allowed shipment states are enforced, current state is replaced atomically, and each update is snapshotted/audited.
- `api/app/Modules/B2B/Requests/Supplier/StoreDeliveryScheduleRequest.php:26-44` and `api/app/Modules/B2B/Services/SupplierPortalService.php:661-772`: Before, any vendor PO state and arbitrary product lines were accepted. After, the server requires a valid `YYYY-MM` date, locks a sent/partially-received PO, decodes each hashed PO-item ID, rejects duplicates and over-remaining quantities using `Money`, stores canonical item hashes, and makes same-payload retries idempotent while rejecting conflicting revisions.
- `api/app/Modules/B2B/Resources/DeliveryScheduleResource.php:10-57` and `spa/src/types/b2b.ts:334-350`: Before, schedule lines could echo legacy numeric item IDs and the SPA type was numeric-only. After, schedule responses normalize quantities to strings and suppress/convert legacy numeric IDs to opaque hashes while retaining the customer schedule shape.
- `spa/src/api/b2b/supplier.ts:126-139` and `spa/src/pages/portal/supplier/delivery-schedules.tsx:22-152`: Before, schedule creation used free-text product lines and an unpaginated list. After, the form selects supplier-owned PO lines by hash ID, sends structured quantities/notes, consumes paginator metadata, and exposes loading/error/empty/retry states.

## 7. M047-F011/F012 — SPA parity and server-derived action gating

- `api/app/Modules/B2B/Services/SupplierPortalService.php:137-160,551-606,649-733` and `api/app/Modules/B2B/Controllers/SupplierPortalController.php:78-89,240-247,288-324`: Before, PO/invoice/delivery/schedule responses were inconsistently unbounded or manually wrapped. After, all supplier list reads use bounded paginator contracts and the controller returns resources matching the SPA client.
- `spa/src/api/b2b/supplier.ts:75-139`, `spa/src/pages/portal/supplier/purchase-orders/index.tsx:15-140`, `invoices/index.tsx:17-148`, and `deliveries/index.tsx:14-80`: Before, metadata was discarded and tables offered only the first page. After, typed paginator responses, URL filters, status filters, retry/error/empty states, and design-system pagination controls are wired through.
- `spa/src/pages/portal/supplier/purchase-orders/detail.tsx:88-164`: Before, action visibility was inferred from stale client state and the shipment form omitted supported fields. After, capabilities come from the API and the form sends/loads shipped date, carrier, tracking number, ETA, and notes.
- `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:33-41` and `spa/src/types/b2b.ts:38-73`: Before, the client had no stable action contract. After, server-published capabilities govern acknowledge, shipment, document, and invoice actions, including accepted-GRN gating for invoice submission.

## Verification

Passed:

- `php -l` passed for all changed PHP files; the final schedule/access files also passed after the last patch.
- Targeted ESLint passed for all changed supplier/access SPA files.
- `git diff --check` passed for the final changed files.
- `docker compose exec -T api php artisan route:list --path=b2b/portal-access --no-ansi` passed and listed the seven access-management routes.

Limited:

- `spa/npm run typecheck` reaches the compiler but exits on three pre-existing unrelated errors: missing `qrcode` in `src/pages/assets/detail.tsx`, its implicit `dataUrl` type, and duplicate JSX attributes in `src/pages/return-management/detail.tsx`. No M047 file appears in the errors.
- The focused backend test run was attempted against an isolated `ogami_m047_test` database. It could not reach assertions because baseline migrations outside this module fail first: duplicate `scheduler_tick_runs` creation and the existing `holidays_date_name_unique` constraint/index drop conflict. The shared test database was not modified further.

## Release status

All planned M047 implementation items were applied. Focused backend execution/re-audit remains pending because the repository baseline migration defects block test bootstrapping outside module scope; no source item was deferred for a business-rule decision. Release as `🔁 Needs Re-audit`, with the next session starting by fixing or isolating those baseline migrations and then running the supplier security/regression suite (draft PO exclusion, resource allowlists, lock expiry/concurrency, multiple reset tokens, invitation conflict/revocation, Money precision, content-digest dedupe, schedule reconciliation, and portal actor attribution).
