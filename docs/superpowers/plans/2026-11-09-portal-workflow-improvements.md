# Customer and Supplier Portal Workflow Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the approved customer/supplier portal workflow improvements as independently verifiable slices while preserving Ogami ERP's existing accounting, inventory, delivery, quality, security, and visual-system contracts.

**Architecture:** Extend the existing Accounting, CRM, B2B, Purchasing, Supply Chain, Inventory, and Quality modules. Keep customer/vendor records and workflow state in their owning modules, share only bounded PDF/template and UI helpers, and use new order-advance accounting records for down payments because existing invoice payment submissions explicitly do not support prepayments. Preserve the current React SPA and Atelier Operate visual system.

**Tech Stack:** Laravel 12, PostgreSQL, PHP 8.3, React 18, TypeScript, Vite, React Hook Form, Zod, TanStack Query, Leaflet, existing Dompdf, PDFtk form filling for uploaded AcroForm templates, PHPUnit, Vitest, and Playwright.

**Spec:** `docs/superpowers/specs/2026-11-09-portal-workflow-improvements-design.md`

## Global Constraints

- Extend the existing domain owners: `Customer` is Accounting; finished-good `Product` and sales orders are CRM; portal identities and supplier-facing workflows are B2B; deliveries/vehicles are Supply Chain; incoming inspection and warehouse stock are Quality/Inventory.
- Preserve Sanctum HTTP-only cookie authentication, HashIDs in API responses, module permissions, row/tenant scopes, audit logging, and database transactions for state changes.
- Keep contract, blueprint, invoice, payment, and defect files on private storage and serve them only through authorized download routes.
- Keep money as decimal strings/BCMath. Never use JS floating point to determine an order balance, deposit, or available quantity.
- Reuse the existing eligible-to-promise inventory calculation, including QC eligibility, allocation, reservation, and safety-stock rules. Do not add a competing stock calculation.
- Customer payment terms default from the customer to the whole order. Sales may override them per order; customer portal views are read-only.
- Credit limit remains optional and does not gate ordering.
- Only portal accounts explicitly designated as authorized signatories may sign; a contact title alone does not grant signature permission.
- Auto-fill PDF contracts only when the uploaded PDF has fillable AcroForm fields and a one-time mapping exists. Ordinary PDFs remain attachable; do not infer fields with OCR.
- Verified deposits are customer advances: debit the receiving bank/cash account, credit the configured customer-advances liability, then apply to sales-order invoices once by debiting the liability and crediting AR. Never book the advance to revenue or apply it twice.
- New customer delivery locations require a map pin. Existing records receive no guessed coordinates and must be completed before a delivery depends on their address.
- Use the current Atelier visual system and shared React components. Do not replace portal layout or global design tokens.
- Follow test-first implementation for every behavior. Run a focused API and SPA test for each task, then the full relevant suites and `make test` before completion.
- Preserve unrelated working-tree content, including the existing untracked `docs/e2e/` directory and B2B process-flow documents. Stage only files belonging to the current task.

## Review Focus

- **Concurrent order placement** can change stock between catalog display and submission; the server must re-read and lock authoritative eligible quantity before accepting the order. Pin in Task 4.
- **Stale or unmapped contract fields** can produce a signed document with missing/wrong customer data; reject unmapped required fields and pin in Task 2.
- **Concurrent advance verification/application** can duplicate a liability posting or consume an advance twice; lock the order advance and target invoice in consistent order and pin in Task 5.
- **Expired/unavailable drivers or an overweight load** can still pass a stale client-side assignment form; enforce every condition on the server and pin in Task 7.
- **Unauthorized portal users or cross-tenant IDs** can read/sign another party's documents, submit evidence, or feedback; verify the parent entity and signer ownership on every mutation/download and pin in Tasks 1, 2, 5, 6, and 8.

---

### Task 1: Customer contact profile, delivery pin, and portal invitation

**Files:**
- Create: `api/database/migrations/2026_11_09_100000_add_customer_contact_role_and_delivery_coordinates.php`
- Create: `api/database/migrations/2026_11_09_101000_add_authorized_signatory_to_portal_users.php`
- Modify: `api/app/Modules/Accounting/Models/Customer.php`
- Modify: `api/app/Modules/Accounting/Requests/StoreCustomerRequest.php`
- Modify: `api/app/Modules/Accounting/Requests/UpdateCustomerRequest.php`
- Modify: `api/app/Modules/Accounting/Resources/CustomerResource.php`
- Modify: `api/app/Modules/B2B/Models/CustomerPortalUser.php`
- Modify: `api/app/Modules/B2B/Models/SupplierPortalUser.php`
- Modify: `api/app/Modules/B2B/Controllers/PortalAccessController.php`
- Modify: `api/app/Modules/B2B/Services/PortalInvitationService.php`
- Modify: `spa/src/pages/crm/customers/form.tsx`
- Modify: `spa/src/pages/crm/customers/create.tsx`
- Modify: `spa/src/pages/crm/customers/edit.tsx`
- Modify: `spa/src/pages/accounting/customers/form.tsx`
- Modify: `spa/src/pages/accounting/portal-access.tsx`
- Create: `spa/src/components/accounting/CustomerDeliveryLocationPicker.tsx`
- Test: `api/tests/Feature/Accounting/CustomerContactLocationTest.php`
- Test: `api/tests/Feature/B2B/PortalInvitationPrefillTest.php`
- Test: `spa/src/pages/crm/customers/location-form.test.tsx`
- Test: `spa/src/pages/accounting/portal-access.test.tsx`

**Interfaces:**
- Produces customer fields `contact_person_role: string|null`, `delivery_latitude: decimal|null`, and `delivery_longitude: decimal|null` in Accounting resources and form payloads.
- Produces `is_authorized_signatory: boolean` on customer and supplier portal users. Only the existing portal-access management permission may set it.
- Portal invitation selection preloads the selected customer's/vendor's contact name and email. If either is absent, keep that field manually editable; the parent customer/vendor association remains server-bound by the route.

- [ ] Write API tests for required customer delivery coordinates on create, numeric coordinate bounds, optional contact role, nullable legacy records, and update rejection when only one coordinate is supplied.
- [ ] Run `cd api && php artisan test --filter=CustomerContactLocationTest`; confirm expected failures before implementation.
- [ ] Write SPA tests that select/map a pin, preserve coordinates while editing, display validation, prefill an invitation from the selected customer/vendor, and allow fallback entry when contact details are missing.
- [ ] Run `cd spa && npm run test:run -- src/pages/crm/customers/location-form.test.tsx src/pages/accounting/portal-access.test.tsx`; confirm expected failures.
- [ ] Add the additive migrations. Keep coordinate columns nullable for existing rows; validate coordinates on new/changed primary delivery addresses and at dispatch when an old row has no pin.
- [ ] Add the contact-role and coordinate fields to both customer form surfaces and the API resource/request contracts. Build the map picker with the installed React Leaflet stack and current OpenStreetMap tile convention; do not add geocoding.
- [ ] Change portal-access forms to select a customer/vendor record and prefill name/email from it. Keep manual fallback only for missing contact fields.
- [ ] Add the signatory flag and require it only when signing; do not infer permission from job title.
- [ ] Run both focused API tests and SPA tests; run `cd spa && npm run build`.
- [ ] Commit only Task 1 files with `feat: add customer location and portal contact setup`.

### Task 2: Reusable PDF contract templates and authorized signing

**Files:**
- Create: `api/database/migrations/2026_11_09_110000_create_portal_contract_templates.php`
- Create: `api/database/migrations/2026_11_09_111000_add_signature_state_to_customer_agreement_documents.php`
- Create: `api/database/migrations/2026_11_09_112000_create_supplier_agreement_documents.php`
- Create: `api/app/Modules/B2B/Models/PortalContractTemplate.php`
- Create: `api/app/Modules/B2B/Services/PortalContractPdfService.php`
- Create: `api/app/Modules/B2B/Services/PortalContractSignatureService.php`
- Create: `api/app/Modules/B2B/Models/SupplierAgreementDocument.php`
- Create: `api/app/Modules/B2B/Controllers/PortalContractTemplateController.php`
- Create: `api/app/Modules/B2B/Controllers/SupplierAgreementDocumentController.php`
- Modify: `api/app/Modules/CRM/Models/CustomerAgreementDocument.php`
- Modify: `api/app/Modules/CRM/Controllers/CustomerAgreementDocumentController.php`
- Modify: `api/app/Modules/CRM/routes.php`
- Modify: `api/app/Modules/B2B/routes.php`
- Modify: `docker/php/Dockerfile`
- Modify: `docker/php/Dockerfile.prod`
- Modify: `api/composer.json`
- Modify: `spa/src/pages/crm/customers/detail.tsx`
- Modify: `spa/src/pages/accounting/portal-access.tsx`
- Create: `spa/src/components/contracts/ContractTemplateForm.tsx`
- Create: `spa/src/components/contracts/PortalContractSignatureDialog.tsx`
- Create: `spa/src/components/contracts/contractTemplateFields.ts`
- Create: `api/tests/Feature/CRM/CustomerAgreementTemplateTest.php`
- Create: `api/tests/Feature/B2B/SupplierAgreementSignatureTest.php`
- Create: `api/tests/Feature/B2B/PortalContractPdfServiceTest.php`
- Create: `spa/src/components/contracts/ContractTemplateForm.test.tsx`
- Create: `spa/src/components/contracts/PortalContractSignatureDialog.test.tsx`

**Interfaces:**
- `PortalContractPdfService::fill(PortalContractTemplate $template, array $recordValues): string` returns a private temporary/output path after filling mapped AcroForm fields.
- `PortalContractSignatureService::signCustomerDocument(CustomerAgreementDocument $document, CustomerPortalUser $signer, string $typedName): CustomerAgreementDocument` and the supplier counterpart enforce designated signatory, active portal identity, parent ownership, immutable PDF hash, and audit timestamp.
- The template maps only approved keys (customer/vendor name, contact, address, payment terms, effective dates, allowed-product/warranty summary). Unknown keys are rejected.

- [ ] Write service/API tests for PDF field discovery/mapping, missing required fields, unknown fields, entity-specific auto-fill, private storage, parent ownership, authorized signer success, unauthorized signer denial, and repeat-sign rejection.
- [ ] Run `cd api && php artisan test --filter='CustomerAgreementTemplate|SupplierAgreementSignature|PortalContractPdfService'`; confirm expected failures.
- [ ] Write component tests for template upload, field mapping, previewed auto-filled values, attachment-only PDFs, and signer confirmation state; run the named Vitest files and confirm expected failures.
- [ ] Add a pinned PHP wrapper dependency and the required PDFtk Java executable to both development and production PHP images. Invoke the binary through Symfony Process argument arrays with no shell interpolation; fail closed when unavailable.
- [ ] Implement private PDF template storage, per-template realm, allowed field mapping, version hash, and output fill. Arbitrary PDFs without AcroForm fields remain attachable but are not auto-filled.
- [ ] Extend customer agreement documents with signature status/audit data and add a supplier-owned agreement-document record. Reuse the same PDF/signature services while keeping customer and vendor authorization/records separate.
- [ ] Add staff template management and customer/supplier upload/sign/review UI using existing dialogs and form patterns. Store signer portal-user ID, typed name, UTC timestamp, and the exact document SHA-256.
- [ ] Run the focused PHP and SPA tests, `cd spa && npm run build`, and a PDF fill smoke test inside the production PHP image.
- [ ] Commit only Task 2 files with `feat: add reusable portal contract templates and signing`.

### Task 3: Product specifications, image gallery, and custom blueprints

**Files:**
- Create: `api/database/migrations/2026_11_09_120000_add_product_specifications.php`
- Create: `api/database/migrations/2026_11_09_121000_create_product_images_and_blueprints.php`
- Create: `api/app/Modules/CRM/Models/ProductImage.php`
- Create: `api/app/Modules/CRM/Models/ProductBlueprint.php`
- Create: `api/app/Modules/CRM/Controllers/ProductImageController.php` (replace single-image implementation without breaking its primary-image route)
- Create: `api/app/Modules/CRM/Controllers/ProductBlueprintController.php`
- Modify: `api/app/Modules/CRM/Models/Product.php`
- Modify: `api/app/Modules/CRM/Requests/StoreProductRequest.php`
- Modify: `api/app/Modules/CRM/Requests/UpdateProductRequest.php`
- Modify: `api/app/Modules/CRM/Resources/ProductResource.php`
- Modify: `api/app/Modules/CRM/Resources/ProductOptionResource.php`
- Modify: `api/app/Modules/CRM/routes.php`
- Modify: `spa/src/pages/crm/products/form.tsx`
- Modify: `spa/src/pages/crm/products/schema.ts`
- Modify: `spa/src/pages/crm/products/detail.tsx`
- Modify: `spa/src/api/crm/products.ts`
- Modify: `spa/src/types/crm.ts`
- Test: `api/tests/Feature/CRM/ProductSpecificationGalleryTest.php`
- Test: `api/tests/Feature/CRM/ProductBlueprintTest.php`
- Test: `spa/src/pages/crm/products/form.test.ts`
- Test: `spa/src/pages/crm/products/detail.test.tsx`

**Interfaces:**
- Product resources expose `specifications` (dimensions/unit/material/finish), `images[]` (hash ID, private URL, position, alt text), and `primary_image_url`.
- `ProductImageController` supports ordered multi-image upload, primary selection, authorized private download, and delete; legacy `image_path` is migrated as the initial primary image.
- A product marked customizable requires at least one approved blueprint before it can be released to the customer catalog. BOM remains the source of actual production material quantities/cost.

- [ ] Write API tests for required specification fields on catalog-visible products, multiple image storage/order, legacy primary-image backfill, private download tenancy/permission, and blueprint-required publish behavior.
- [ ] Run `cd api && php artisan test --filter='ProductSpecificationGallery|ProductBlueprint'`; confirm expected failures.
- [ ] Write form/detail component tests for specification sections, image gallery upload/order/main image, validation errors, and blueprint upload; run the named Vitest files and confirm expected failures.
- [ ] Add additive schema and model relationships. Backfill current `products.image_path` rows into the image-gallery table with a stable primary position; keep the old image resource field as a compatibility alias during migration.
- [ ] Implement uploads with strict MIME/extension/size validation, generated storage names, private disk, cleanup on transaction failure, and authorized download routes.
- [ ] Extend the ProductForm using existing UI tokens. Show standard cost and calculated expected margin only to permitted internal roles; calculate from the approved cost and current applicable selling price.
- [ ] Run focused backend and SPA tests plus `cd spa && npm run build`.
- [ ] Commit only Task 3 files with `feat: add product specifications and image gallery`.

### Task 4: Customer agreement catalog, warranty choices, and order limits

**Files:**
- Create: `api/database/migrations/2026_11_09_130000_create_customer_product_warranty_options.php`
- Create: `api/app/Modules/CRM/Models/CustomerProductWarrantyOption.php`
- Create: `api/app/Modules/CRM/Services/CustomerPortalOrderAvailabilityService.php`
- Modify: `api/app/Modules/CRM/Controllers/CustomerWarrantyTermsController.php`
- Modify: `api/app/Modules/CRM/Services/SalesOrderService.php`
- Modify: `api/app/Modules/CRM/Models/SalesOrderItem.php`
- Modify: `api/app/Modules/CRM/Requests/StoreSalesOrderRequest.php`
- Modify: `api/app/Modules/B2B/Requests/Customer/StorePortalOrderRequest.php`
- Modify: `api/app/Modules/B2B/Services/CustomerPortalService.php`
- Modify: `api/app/Modules/B2B/Resources/CustomerPortalOrderResource.php`
- Modify: `api/app/Modules/CRM/Resources/SalesOrderResource.php`
- Modify: `api/app/Modules/CRM/Requests/UpdateProductCustomerAccessRequest.php`
- Modify: `spa/src/pages/crm/customers/detail.tsx`
- Modify: `spa/src/pages/portal/customer/orders/new.tsx`
- Modify: `spa/src/pages/portal/customer/orders/detail.tsx`
- Modify: `spa/src/pages/crm/sales-orders/create.tsx`
- Modify: `spa/src/api/b2b/customer.ts`
- Modify: `spa/src/types/b2b.ts`
- Test: `api/tests/Feature/CRM/CustomerWarrantyOptionsTest.php`
- Test: `api/tests/Feature/B2B/CustomerPortalOrderAvailabilityTest.php`
- Test: `api/tests/Feature/B2B/CustomerPortalOrderSnapshotTest.php`
- Test: `spa/src/pages/portal/customer/orders/new.test.tsx`

**Interfaces:**
- `CustomerPortalOrderAvailabilityService::forCustomer(Customer $customer, CarbonInterface $asOf): Collection` returns only the customer's approved, priced products plus authoritative eligible quantity.
- Warranty options are keyed to customer+product and contain the full terms snapshot; a customer order line must select one offered option, and CRM snapshots those exact terms.
- Payment terms are copied from customer defaults onto the sales order; authorized Sales may override per order. Customer portal sees the saved terms and cannot change them.

- [ ] Write API tests for strict customer-product access, multiple per-product warranty durations under one agreement, invalid warranty option rejection, order-line warranty snapshot, payment-term default/override, and no credit-limit gate.
- [ ] Write race-aware order tests for quantity equal to available, one unit over, zero eligible stock, reserved stock, QC-held stock, safety stock, and availability changing between catalog read and submission.
- [ ] Run `cd api && php artisan test --filter='CustomerWarrantyOptions|CustomerPortalOrderAvailability|CustomerPortalOrderSnapshot|CustomerPortalOrderTest'`; confirm expected failures.
- [ ] Write portal-order UI tests for image gallery, specs, allowed catalog, stock indicator, max quantity, warranty selector, price-request route for excess, readonly payment terms, and preserved form data after submission errors.
- [ ] Replace the single customer/product warranty override with additive multiple-option records; migrate each existing override to one equivalent option and preserve old order snapshots.
- [ ] Implement the server availability service by reusing CRM/Inventory eligible stock, lot QC, existing sales-order allocations/reservations, and safety-stock policy; lock/re-read availability inside the order transaction.
- [ ] Restrict the catalog to customer-authorized products. Add a request-price action for quantity above stock using the existing Price Request flow; do not create a new production-request record.
- [ ] Run API tests, portal Vitest, and `cd spa && npm run build`.
- [ ] Commit only Task 4 files with `feat: enforce customer agreement order options`.

### Task 5: Order deposits, customer advances, and production release gate

**Files:**
- Create: `api/database/migrations/2026_11_09_140000_create_sales_order_advances.php`
- Create: `api/database/migrations/2026_11_09_141000_create_sales_order_advance_applications.php`
- Create: `api/app/Modules/CRM/Models/SalesOrderAdvance.php`
- Create: `api/app/Modules/CRM/Models/SalesOrderAdvanceApplication.php`
- Create: `api/app/Modules/CRM/Services/SalesOrderAdvanceService.php`
- Create: `api/app/Modules/CRM/Controllers/SalesOrderAdvanceController.php`
- Create: `api/app/Modules/CRM/Requests/SubmitSalesOrderAdvanceRequest.php`
- Create: `api/app/Modules/CRM/Requests/DecideSalesOrderAdvanceRequest.php`
- Modify: `api/app/Modules/CRM/Models/SalesOrder.php`
- Modify: `api/app/Modules/CRM/Services/SalesOrderService.php`
- Modify: `api/app/Modules/Accounting/Services/AccountingAccountPolicyService.php`
- Modify: `api/app/Modules/Accounting/Services/InvoiceService.php`
- Modify: `api/app/Modules/Accounting/Services/InvoicePaymentSubmissionService.php`
- Modify: `api/app/Modules/Accounting/Models/Invoice.php`
- Modify: `api/app/Modules/Accounting/Models/InvoiceCollection.php`
- Modify: `api/app/Modules/Accounting/Resources/InvoiceResource.php`
- Modify: `api/app/Modules/CRM/routes.php`
- Modify: `api/app/Modules/B2B/routes.php`
- Modify: `api/database/seeders/SettingsSeeder.php`
- Modify: `api/database/seeders/RolePermissionSeeder.php`
- Modify: `spa/src/pages/crm/sales-orders/create.tsx`
- Modify: `spa/src/pages/crm/sales-orders/detail.tsx`
- Create: `spa/src/pages/portal/customer/orders/SubmitDepositDialog.tsx`
- Modify: `spa/src/pages/portal/customer/orders/detail.tsx`
- Modify: `spa/src/pages/accounting/invoices/detail.tsx`
- Test: `api/tests/Feature/CRM/SalesOrderAdvanceLifecycleTest.php`
- Test: `api/tests/Feature/CRM/SalesOrderAdvanceAccountingTest.php`
- Test: `api/tests/Feature/CRM/SalesOrderAdvanceConcurrencyTest.php`
- Test: `spa/src/pages/portal/customer/orders/SubmitDepositDialog.test.tsx`
- Test: `spa/src/pages/crm/sales-orders/detail.test.tsx`

**Interfaces:**
- `SalesOrderAdvanceService::configure(SalesOrder $order, ?string $fixedAmount, ?string $percentage, User $salesUser): SalesOrderAdvance` snapshots the required amount and order total.
- `SalesOrderAdvanceService::submitEvidence(SalesOrder $order, array $payment, UploadedFile $proof, CustomerPortalUser $portalUser): SalesOrderAdvance` creates one immutable pending attempt with private proof and fingerprint.
- `SalesOrderAdvanceService::verify(SalesOrderAdvance $advance, string $bankAccountHash, User $financeUser): SalesOrderAdvance` posts one debit to an approved bank/cash account and one credit to the configured customer-advances liability.
- `SalesOrderAdvanceService::waive(SalesOrderAdvance $advance, string $reason, User $financeUser): SalesOrderAdvance` records an audited waiver without posting cash.
- `SalesOrderAdvanceService::applyToInvoice(SalesOrder $order, Invoice $invoice, User $systemActor): list<SalesOrderAdvanceApplication>` applies only the unapplied verified amount, with a balanced liability-to-AR journal and invoice balance update.

- [ ] Write failing tests for fixed and percentage deposits, amount cap, proof validation, tenant ownership, repeat submission, Finance verify/reject/waive, correct liability posting, exact invoice application, cancellation/refund guard, and zero duplicate posting under concurrent verification/application.
- [ ] Run `cd api && php artisan test --filter='SalesOrderAdvanceLifecycle|SalesOrderAdvanceAccounting|SalesOrderAdvanceConcurrency'`; confirm expected failures.
- [ ] Write UI tests for optional fixed/percentage deposit setup, customer proof upload, pending/verified/waived states, Finance review, and production hold messaging; confirm failures with the named Vitest tests.
- [ ] Add and validate a postable liability account setting. Reuse existing private proof upload, MIME checks, idempotency, cash/bank account policy, `JournalEntryService`, and notifications where possible; do not reuse invoice balance collection as if the deposit were an invoice payment.
- [ ] Gate MRP/production release after Sales confirmation until the required advance is verified or Finance waives it. Releasing after payment must be idempotent and must not duplicate `SalesOrderConfirmed` events/work orders.
- [ ] When standard sales invoices are finalized, apply verified advances in order, lock both advance and invoice in a consistent order, post liability-to-AR journals, and update balance/amount-paid without recognizing revenue twice. Preserve a full audit trail and show applied amounts in statements/resources.
- [ ] Prevent order cancellation/closure when a verified advance remains unapplied; expose an audited Finance refund/resolution action that reverses the liability posting and retains proof/reference.
- [ ] Run focused CRM/Accounting/B2B tests, existing `InvoicePaymentSubmission*` tests, order-chain tests, and SPA tests; run the full API/SPA suite before this task is committed.
- [ ] Commit only Task 5 files with `feat: add customer advance verification and application`.

### Task 6: Supplier agreement access, listing images, and invoice evidence

**Files:**
- Create: `api/database/migrations/2026_11_09_150000_add_supplier_listing_image_metadata.php`
- Modify: `api/app/Modules/Purchasing/Models/SupplierItemListing.php`
- Modify: `api/app/Modules/Purchasing/Resources/SupplierItemListingResource.php`
- Modify: `api/app/Modules/B2B/Requests/Supplier/StoreSupplierListingRequest.php`
- Modify: `api/app/Modules/B2B/Requests/Supplier/UpdateSupplierListingRequest.php`
- Modify: `api/app/Modules/B2B/Controllers/SupplierListingPortalController.php`
- Modify: `api/app/Modules/B2B/Services/SupplierInvoiceService.php`
- Modify: `api/app/Modules/B2B/Requests/Supplier/SubmitInvoiceRequest.php`
- Modify: `api/app/Modules/B2B/Resources/SupplierInvoiceResource.php`
- Modify: `spa/src/pages/portal/supplier/item-listings.tsx`
- Modify: `spa/src/pages/portal/supplier/invoices/index.tsx`
- Modify: `spa/src/pages/portal/supplier/invoices/detail.tsx`
- Modify: `spa/src/api/b2b/supplier.ts`
- Test: `api/tests/Feature/B2B/SupplierListingImageTest.php`
- Test: `api/tests/Feature/B2B/SupplierInvoiceEvidenceTest.php`
- Test: `spa/src/pages/portal/supplier/item-listings.test.tsx`
- Test: `spa/src/pages/portal/supplier/invoices/index.test.tsx`

**Interfaces:**
- Listing image belongs to a supplier listing, not the shared inventory item; the resource exposes a private image route and stable `image_url`.
- Invoice attachment accepts PDF/JPEG/PNG under the current 10 MiB policy and remains optional; existing bill review and three-way matching behavior is unchanged.
- Supplier agreements use Task 2's template and signature services with supplier-specific ownership and signatory flags.

- [ ] Write backend tests for supplier tenant isolation, image MIME/size enforcement, private download, invoice attachment persistence, and existing supplier invoice lifecycle unchanged.
- [ ] Run `cd api && php artisan test --filter='SupplierListingImage|SupplierInvoiceEvidence|SupplierInvoiceSubmission'`; confirm expected failures.
- [ ] Write supplier listing/invoice UI tests for fallback catalog image, supplier image upload, invoice file selection, and retained error state; run focused Vitest and confirm failures.
- [ ] Implement listing-level image storage and authenticated view/delete routes; preserve existing listing approval transitions.
- [ ] Connect optional invoice image/PDF upload to the current `SupplierInvoiceService`; do not weaken required invoice number, amount, date, and PO/GRN validation.
- [ ] Add the supplier contract panel, mapped-template auto-fill, and authorized signature status from Task 2.
- [ ] Run focused API and SPA tests, `cd spa && npm run build`, and existing supplier cross-tenant/invoice tests.
- [ ] Commit only Task 6 files with `feat: add supplier listing and invoice evidence`.

### Task 7: Driver eligibility, shipment filters, warehouse labels, and defect evidence

**Files:**
- Create: `api/database/migrations/2026_11_09_160000_create_driver_credentials_and_inspection_defect_evidence.php`
- Modify: `api/database/migrations/2026_11_09_161000_add_delivery_shipment_weight.php`
- Create: `api/app/Modules/SupplyChain/Models/DriverCredential.php`
- Create: `api/app/Modules/Quality/Models/InspectionDefectEvidence.php`
- Modify: `api/app/Modules/SupplyChain/Services/DeliveryService.php`
- Modify: `api/app/Modules/SupplyChain/Models/Delivery.php`
- Modify: `api/app/Modules/SupplyChain/Models/Vehicle.php`
- Modify: `api/app/Modules/SupplyChain/Controllers/DeliveryController.php`
- Modify: `api/app/Modules/SupplyChain/Controllers/VehicleController.php`
- Modify: `api/app/Modules/SupplyChain/Requests/AssignDeliveryRequest.php`
- Modify: `api/app/Modules/SupplyChain/Requests/CreateDeliveryRequest.php`
- Modify: `api/app/Modules/SupplyChain/Services/ShipmentService.php`
- Modify: `api/app/Modules/SupplyChain/Controllers/ShipmentController.php`
- Modify: `api/app/Modules/Quality/Services/InspectionService.php`
- Modify: `api/app/Modules/Quality/Controllers/InspectionController.php`
- Modify: `api/app/Modules/Quality/routes.php`
- Modify: `spa/src/pages/supply-chain/shipments/index.tsx`
- Modify: `spa/src/pages/supply-chain/deliveries/create.tsx`
- Modify: `spa/src/pages/supply-chain/deliveries/detail.tsx`
- Modify: `spa/src/pages/quality/inspections/detail.tsx`
- Modify: `spa/src/pages/inventory/grn/detail.tsx`
- Modify: `spa/src/pages/warehouse/index.tsx`
- Test: `api/tests/Feature/SupplyChain/DriverAvailabilityCapacityTest.php`
- Test: `api/tests/Feature/SupplyChain/ShipmentDateFilterTest.php`
- Test: `api/tests/Feature/Quality/InspectionDefectPhotoSeverityTest.php`
- Test: `spa/src/pages/quality/inspections/detail.test.tsx`
- Test: `spa/src/pages/supply-chain/shipments/index.test.tsx`

**Interfaces:**
- Driver credential resource exposes only license identifier, expiry, active/expired state, and authorized document links. Never expose private credential file paths.
- Assignment eligibility is `active driver + active vehicle + no overlapping live delivery on scheduled date + shipment_weight_kg <= vehicle.capacity_kg`.
- Dispatch enters total shipment weight on the delivery because product master data has no weight field.
- Inspection defect evidence records category/Other text, Minor/Major/Critical severity, affected quantity, notes, and private photo references, linked to the inspection and received item.

- [ ] Write API tests for expired/inactive driver rejection, overlapping assignment, inactive vehicle, under/over capacity, missing ship-to pin, custom date filters, defect severity/evidence ownership, and file MIME/size limits.
- [ ] Run `cd api && php artisan test --filter='DriverAvailabilityCapacity|ShipmentDateFilter|InspectionDefectPhotoSeverity'`; confirm expected failures.
- [ ] Write UI tests for driver credential status, shipment weight/capacity error, date presets/custom range, warehouse names/status descriptions, and defect image/severity display/upload.
- [ ] Add driver credential and delivery weight persistence. Store only the credential data required by the approved scope; keep the user credential record separate from unrelated HR fields.
- [ ] Enforce availability and capacity in `DeliveryService::assign` under the delivery/driver/vehicle lock, not only in the form options. Reuse existing vehicle status and delivery state definitions.
- [ ] Add shipment filters for 7/30/90-day presets and an inclusive custom range with server-side date validation.
- [ ] Add defect evidence rows and authorized private upload/download endpoints. Link them to existing inspection/NCR records without changing IATF release or acceptance decisions.
- [ ] Clarify warehouse copy with current schema terms (warehouse, zone, location/bin; available, reserved, held/QC, damaged) without changing schema/status semantics.
- [ ] Run focused Supply Chain, Quality, Inventory, and SPA tests, then `cd spa && npm run build`.
- [ ] Commit only Task 7 files with `feat: enforce delivery readiness and capture defect evidence`.

### Task 8: Portal walkthroughs and order feedback

**Files:**
- Create: `api/database/migrations/2026_11_09_170000_create_customer_order_feedback.php`
- Create: `api/app/Modules/B2B/Models/CustomerOrderFeedback.php`
- Create: `api/app/Modules/B2B/Requests/StoreCustomerOrderFeedbackRequest.php`
- Create: `api/app/Modules/B2B/Controllers/CustomerOrderFeedbackController.php`
- Modify: `api/app/Modules/B2B/Services/CustomerPortalService.php`
- Modify: `api/app/Modules/B2B/routes.php`
- Modify: `api/app/Modules/B2B/Resources/CustomerPortalOrderResource.php`
- Modify: `spa/src/layouts/PortalLayout.tsx`
- Create: `spa/src/components/portal/PortalWalkthrough.tsx`
- Create: `spa/src/components/portal/portalWalkthroughs.ts`
- Modify: `spa/src/pages/portal/customer/orders/detail.tsx`
- Create: `spa/src/pages/portal/customer/orders/feedback.test.tsx`
- Create: `spa/src/components/portal/PortalWalkthrough.test.tsx`
- Test: `api/tests/Feature/B2B/CustomerOrderFeedbackTest.php`

**Interfaces:**
- Walkthroughs are realm-specific, skippable, replayable from a persistent Help control, and keyed by portal-user hash plus guide version. Do not store authentication material in local storage.
- `CustomerOrderFeedback` is one rating (1–5) plus optional comment per delivered order, scoped to its owning customer and visible only to staff initially.

- [ ] Write API tests for customer ownership, delivered-order eligibility, one feedback per order, rating bounds, comment length, and private staff visibility.
- [ ] Write component tests for first-run, skip, completed, replay, route changes, and mobile layout for both portal realms.
- [ ] Implement realm-specific guides for every customer/supplier navigation area and the primary actions on each page. Keep tours skippable and expose replay in the shared portal help control.
- [ ] Add order-linked rating/comment UI after delivery and an internal staff view; do not show feedback to suppliers or other customers.
- [ ] Run focused B2B tests, portal Vitest, keyboard/a11y checks, and `cd spa && npm run build`.
- [ ] Commit only Task 8 files with `feat: add portal onboarding and order feedback`.

### Task 9: Integrated regression and documentation

**Files:**
- Create: `api/tests/Feature/E2E/PortalOrderAgreementDeliveryFlowTest.php`
- Create: `spa/e2e/portal-customer-order-flow.spec.ts`
- Modify: `docs/PROCESS-FLOWS.md`
- Modify: `docs/USER-MANUAL.md`
- Modify: `docs/README.md`
- Modify: this implementation plan to check completed steps and record evidence.

- [ ] Add one end-to-end backend flow: configure customer and authorized signer; create/fill/sign the PDF agreement; restrict catalog; select warranty; submit an order with terms, deposit, and blueprint; verify deposit; prove MRP release exactly once; fulfill, deliver, apply the advance to invoice, verify bank proof, and collect feedback.
- [ ] Add a browser flow for customer order/inventory/warranty/deposit/feedback and supplier sign/listing image/invoice evidence. Use isolated test accounts and no live payment submission.
- [ ] Run focused feature groups serially against an isolated PostgreSQL test database; never share `ogami_test` across simultaneous `RefreshDatabase` suites.
- [ ] Run `cd spa && npm run test:run`, `cd spa && npm run build`, and `make test`. Run browser tests in Chromium for geometry/screenshots and Lightpanda only for DOM-only audits.
- [ ] Run migration up/down/up on a disposable database containing legacy customer locations, single-image products, one-row warranty overrides, old invoices/collections, supplier listings, and inspections. Preserve all prior values and verify no guessed location data or duplicate ledger posting.
- [ ] Review file privacy, cross-tenant access, role/permission matrices, audit rows, transaction boundaries, race handling, and all user-visible loading/error/empty states. Fix failures before completion.
- [ ] Update `docs/README.md`, the user manual, and process flow with the shipped behavior and known operational setup (PDFtk binary, liability account configuration, map pin completion).
- [ ] Commit only Task 9 files with `docs: document portal workflow improvements`.
