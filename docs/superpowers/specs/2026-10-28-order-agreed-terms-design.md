# Order agreed terms

## Purpose

Record the terms shared with a customer for one sales order, retain what was acknowledged, and make that record available when reviewing a delivery problem. This supports Order to Cash without assuming confidential company policies.

## Approved scope

The user approved a limited enhancement: customer-visible terms separate from internal notes, optional supporting attachment, acknowledgment, preserved history, and access during return review. The user subsequently requested that the controller write the plan, then delegate implementation and testing to GPT-6-Luna while retaining quality oversight.

## User experience

An “Agreed terms” panel appears on the existing internal and customer order detail pages. Authorized Sales staff publishes a plain-text revision before order confirmation. The text can describe payment arrangements, shipping responsibility, defect reporting, warranty coverage, and product-specific exceptions using product names or codes. Existing payment days, delivery terms, shipping terms, and order lines are captured alongside the text. There are no invented default terms.

Staff may attach one PDF, JPEG, PNG, or WebP file, up to 10 MiB. The form states that both terms and attachment will be visible to this order's customer. Internal notes are neither copied into terms nor included in the commercial snapshot. Existing note behavior is outside this change.

Customers explicitly acknowledge the current revision through their existing portal. This acknowledgment is separate from accepting the sales order or proposing a delivery date. Staff can instead record an external acknowledgment with the customer's representative, acknowledgment date/time, and a mandatory evidence/reference description. The interface labels this “External acknowledgment recorded by staff”; it never calls it portal acceptance or a verified signature.

Publishing and acknowledgment are allowed only while `SalesOrderStatus::isBeforeConfirmation()` is true. Each publication creates a new revision. Old content, snapshot, attachment, and acknowledgment are retained. Acknowledgment can be written only once. There is no update/delete endpoint for published revisions.

If an order has published terms, order confirmation requires the newest revision to be acknowledged and its captured commercial values to match the current order. Changes to products, quantities, prices, payment days, delivery terms, shipping terms, or scheduled delivery dates require a new publication and acknowledgment. Old acknowledgments do not authorize revised commercial values. Orders without published terms retain their existing confirmation behavior.

Confirmed orders are read-only for this feature. Post-confirmation amendments are deliberately outside this first version. Return case detail shows the originating delivery's order terms read-only, including acknowledged revision and history. Supplier cases and customer cases without an originating order show no invented agreement. Terms never determine blame, expiry, replacement, refund, credit, transport charges, or stock movements automatically.

## Data and ownership

CRM owns `sales_order_agreements`, one row per published revision. A row stores order, revision number, text, canonical commercial snapshot, publisher, publication time, optional private file metadata, and optional acknowledgment metadata. The order row lock serializes publication, acknowledgment, order edits, and order confirmation. A unique `(sales_order_id, revision)` constraint protects numbering.

The commercial snapshot stores customer and product HashIDs, decimal money/quantity strings, dates, payment days, delivery terms, and Incoterm. It excludes internal notes, internal integer identifiers, fulfillment progress, and operational statuses. It is derived server-side and canonically ordered so an unchanged order does not become stale merely because items are reloaded or replaced with equivalent rows.

Once any revision exists, the sales order cannot be reassigned to another customer. This prevents a new customer from inheriting access to the previous customer's terms and files; orders with no agreement retain existing customer-edit behavior.

Acknowledgment metadata distinguishes `portal` and `external`, with customer representative, effective acknowledgment time, server recording time, portal user or internal recording user, and external reference where applicable. Public resources expose only needed names, timestamps, content, public snapshot, file name, and revision HashID. Storage paths, internal IDs, audit metadata, and account details are omitted.

## Permissions and confidentiality

Publication requires `crm.sales_orders.update`. Recording external acknowledgment requires `crm.sales_orders.confirm`. Ordinary reads require `crm.sales_orders.view`. Return reviewers with `return_management.view` may read/download terms only for an order linked to a customer return case they may access. Portal reads, downloads, and acknowledgment require the authenticated customer's ownership of the order, the correct order/revision pair, and existing portal feature/authentication guards. Supplier and employee sessions never qualify as customer acknowledgment actors.

Files use private `local` storage, randomized paths, server-side MIME/size validation, ownership-checked downloads, and cleanup if persistence fails. Client-supplied status, snapshot, revision, author, acceptance time for portal actions, or file path cannot be mass assigned.

## Compatibility and non-goals

Use an additive migration; do not backfill terms or acknowledgments. Do not reinterpret legacy order responses. Existing prices, totals, invoices, collections, delivery negotiations, returns, approvals, and customer account lifetimes continue to be owned by existing workflows. Shipping amounts mentioned in text do not become invoice charges. No template engine, global contract module, automatic warranty calculator, electronic signature provider, new dependency, policy settings, or seeded company agreement is introduced.

## Acceptance

1. Authorized staff publishes terms and optional private evidence; customer sees exactly that revision.
2. Portal acknowledgment records its actual customer actor; external acknowledgment records the staff actor and mandatory evidence reference.
3. Cross-customer reads, acknowledgments, and downloads fail; mismatched order/revision pairs fail.
4. New publications retain earlier records and require fresh acknowledgment; published content cannot be changed.
5. Commercial changes make an old revision stale; acknowledging stale terms or confirming with stale/unacknowledged terms fails.
6. Repeated acknowledgment of the same current revision is idempotent and preserves its original actor/time.
7. No-terms and existing order response/delivery negotiation paths remain compatible.
8. Internal and customer problem detail show original-order terms without modifying case outcomes.
9. Forms expose loading/error/success states, accessible labels, and explicit customer visibility; historical orders show an honest empty state.
10. Focused backend and frontend tests, TypeScript, targeted lint, and production frontend build pass. Broader regressions are run when failures or changed shared behavior justify them.
