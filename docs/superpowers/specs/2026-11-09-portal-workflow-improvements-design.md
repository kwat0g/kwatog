# Customer and Supplier Portal Workflow Improvements — Design

## Intent

Use the panel feedback to make customer and supplier portal workflows clearer, configurable, and linked to the ERP's existing customer, product, order, inventory, delivery, and quality records. The goal is to reduce manual re-entry and prevent orders, payments, shipments, or defect reports from losing the agreement and evidence that governs them.

## Users and outcomes

- **Sales and customer-record staff** can set up a customer contact, delivery location, agreement, allowed products, and product-specific warranty terms before inviting the customer.
- **Customer contacts** can understand their permitted catalog and terms, place an order against available stock, provide payment evidence, and leave order feedback.
- **Purchasing and supplier-record staff** can invite supplier contacts, manage supplier agreements, and review supplier listing and invoice evidence.
- **Suppliers** can see item images, sign agreements, and attach invoice evidence.
- **Dispatch, drivers, warehouse, and Quality staff** can make assignments against driver availability and vehicle capacity, understand warehouse locations and stock states, and document incoming defects with severity and photos.

Success means the portal reflects the customer's agreement and approved assortment, quantity and payment checks happen in the order flow, and supplier, shipment, and quality evidence remains attached to the relevant records.

## Current code evidence and design consequence

The app is a Laravel modular monolith with a React SPA. `Customer` lives in Accounting, finished-good `Product` and sales orders live in CRM, portal users and supplier-facing workflows live in B2B, deliveries and vehicles live in Supply Chain, and inspections live in Quality/Inventory. The implementation should extend those owners and preserve their boundaries.

The repository already contains customer agreement-document uploads, customer product access, a single customer/product warranty override, customer-level warranty defaults and payment terms, one product image field, portal first-login password change with a confirmation field, bank-payment submission workflows, supplier invoice document handling, and vehicle capacity data. The portal invitation form still asks for contact name and email after selecting a customer or vendor. The plan must verify these existing flows before changing them and fill gaps instead of duplicating existing features.

The portal is an **Operate** surface. Preserve the established Atelier system documented in `docs/DESIGN-SYSTEM.md`, the current portal layouts, existing shared components, and backend authorization conventions.

## Goals

1. Store a contact person's job title/role (for example, “Sales Officer”) and require a map pin for the customer's primary delivery location.
2. Select an existing customer/vendor record when granting portal access and prefill its contact name and email. Permit manual entry when the record lacks those values.
3. Keep first-login password change and confirmation required. Add skippable, replayable, role-specific tutorials for customer and supplier portal areas.
4. Support reusable contract templates and uploaded documents for both customers and suppliers. Allow a fillable PDF template (or a PDF whose fields are mapped once) to auto-fill customer/supplier details and agreement terms when a new agreement is created. Also allow existing PDFs to be attached without auto-fill. Only portal contacts explicitly designated as authorized signatories may sign. Retain signer, timestamp, document version/hash, and audit evidence. Keep customer and supplier records in their owning modules while reusing the workflow/UI where practical.
5. Let staff configure a customer's permitted products and multiple warranty-duration choices per product under the customer's agreement. Show only approved products in that customer's portal and snapshot the chosen warranty onto the order line.
6. Improve product setup with structured dimensions/material/finish details, a multi-image gallery, and custom-order blueprint attachments. Reuse the existing BOM/material model where it is the source of production material data. Keep cost and expected margin staff-only.
7. Apply customer payment terms to an order by default, with an order-level override. Cap stock orders at the ERP's authoritative eligible-to-promise quantity, preserving existing QC, allocation, reservation, and safety-stock rules. For a larger need, offer the existing customer price-request flow for the requested quantity rather than adding a second production-request subsystem. Allow Sales to set an optional fixed-amount or percentage down payment per order. The customer may submit the order, but production release is held until the deposit is verified or Finance records a waiver with a reason. Treat verified deposits as customer advances: post them to a liability account and apply them to the later sales invoice once, without recognizing the deposit as sales revenue or charging it twice.
8. Show supplier-listing images and allow listing-level image upload when needed. Allow invoice image/PDF evidence. Require bank/online payment proof and staff verification before marking the payment paid.
9. Capture driver license identifier/expiry, vehicle type and plate, vehicle capacity, and total shipment weight. Dispatch enters total shipment weight on the delivery because products do not currently have weight data. Block delivery assignment unless the driver is available and vehicle capacity covers the shipment. Add shipment date filters (7/30/90 days and custom range).
10. Use clear warehouse/zone/bin and stock-state labels, and record defect severity (Minor/Major/Critical), affected quantity, notes, and photos during quality checks.
11. Let customers submit an order-linked rating and optional comment after delivery; keep feedback staff-visible initially.
12. Add an “Other” plus text entry where a business option list needs an escape hatch.

## Non-goals

- Credit-limit enforcement or credit-based order blocking. The current optional field may remain, but it will not affect order eligibility until a business process is defined.
- Replacing the portal visual identity or introducing a new design system.
- A CAD/3D product configurator, automated geocoding, or a new external mapping provider.
- A new cross-module contract domain or external e-signature vendor integration. The initial signing flow records a portal signature/acceptance with auditable document identity and signer details.
- OCR or automatic field inference for arbitrary or scanned PDFs. Auto-fill requires a fillable PDF or a one-time field-to-record mapping; unmapped PDFs remain attachable as documents.
- Reworking warehouse schema; this request covers clear labels and the stated delivery/stock rules.
- Reusing the existing invoice-payment submission as if it supported prepayments. That workflow only accepts issued invoice balances, so down payments need a separate order-advance and invoice-application lifecycle.

## Approaches

1. **Extend existing modules in phased, testable slices — selected.** CRM/Accounting continue to own customer/product/order terms, B2B owns portal access and supplier interactions, Supply Chain owns delivery assignment, and Quality/Inventory own inspection/stock evidence. Use shared portal contract interaction patterns while keeping entity-specific records in their modules. This fits existing ownership and avoids duplicating capabilities already present.
2. **Create a shared partner-agreement subsystem.** This could unify customer and supplier agreements more deeply, but would introduce new data ownership and cross-module coupling before the existing document and agreement patterns have been fully reused.
3. **Implement every requested change as one combined portal release.** This could target a single demo date, but joins unrelated schema and state changes across several modules and increases regression and review risk.

## Selected sequence

1. **Customer setup and agreement foundation:** contact role and delivery pin; invitation prefill; contract templates/uploads/signature evidence; allowed-product and warranty configuration.
2. **Product and customer ordering:** structured product details/gallery/blueprints; agreement-aware product and warranty selection; order-level payment terms; available-stock cap and excess-quantity route; down payment and bank-payment evidence.
3. **Supplier portal:** supplier agreement workflow, listing images, and invoice attachments.
4. **Fulfillment, warehouse, and Quality:** driver credentials, capacity/availability gate, shipment date filters, warehouse labels, and defect evidence/severity.
5. **Portal guidance and feedback:** skippable/replayable tutorials across customer and supplier routes; post-delivery feedback.

Each phase should be independently reviewable and should preserve the portal's current authentication, permissions, hashed IDs, transactional state changes, private document handling, and audit requirements.

## Constraints and builder decisions

- Keep Sanctum HTTP-only cookie authentication; do not introduce bearer tokens or local-storage auth.
- Use the existing module routes, request/service/resource patterns, hashed IDs, and shared React components.
- Keep contract, blueprint, invoice, and defect evidence behind authorized download routes; do not expose private files through public storage URLs.
- Use additive migrations. New customer delivery pins are required; existing records must not receive guessed coordinates. Require their location to be completed before a workflow that depends on dispatching to it.
- Existing product costs remain internal. If margin is shown, calculate it from the current approved cost and selling-price source rather than storing a second editable profit value.
- Use the existing CRM/Inventory available-to-promise calculation for order limits; do not implement a competing on-hand-minus-reserved calculation.
- Route requested quantities above available stock through the existing customer price-request flow. Do not add a separate production-request subsystem in this scope.
- Record total shipment weight at dispatch; do not add product-weight master data in this scope.
- Designate authorized customer and supplier signatories through the existing portal-access management flows; a contact's job title alone does not confer signing authority.
- Reuse current order permissions for Sales to set an optional deposit and current Finance authority for payment verification or waiver, with a required waiver reason.
- Configure and validate a postable customer-advances liability account. Verified deposits debit the receiving cash/bank account and credit that liability; application to a sales invoice debits the liability and credits accounts receivable. Orders with an unapplied verified deposit cannot be cancelled or closed until Finance resolves the advance.
- Future implementation should use the repository's test-first workflow and module-specific feature tests, then run focused SPA/API verification; no code or tests are part of this design document.
- Before implementation, inspect current contract, image, payment-proof, invoice-attachment, stock-allocation, driver, and inspection evidence flows and map each requested item to existing behavior or a specific gap.
