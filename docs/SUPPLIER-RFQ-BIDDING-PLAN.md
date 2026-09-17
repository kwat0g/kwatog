# Supplier RFQ Bidding Plan

> Status: implemented and hardened; remaining changes are incremental operational improvements
>
> Scope owner: Purchasing / Procure to Pay
>
> Primary thesis scenario: competitive sourcing of production resin

## 1. Purpose

Add an invite-only, sealed Request for Quotation (RFQ) process to the
Purchasing module. Ogami purchasing officers will be able to source production
resin from multiple suppliers, compare commercial and quality evidence, award
individual RFQ lines, and generate traceable draft Purchase Orders.

The RFQ is a sourcing event before a PO. It is not a public marketplace, a
live reverse auction, a customer sales tender, or a replacement for the
existing supplier item-listing and PO-response features.

This plan is a deliberate scope expansion. The current documentation records
RFQ and CRM quote workflows as excluded scope. Once implementation begins, the
scope decision must be reflected in the process, schema, permissions, seed,
demo, and defense documentation.

## 2. Thesis Scenario

MRP detects that Ogami needs additional molding resin for a production plan.
An approved Purchase Request is placed into sourcing-pending state. The
purchasing officer starts an RFQ from the approved PR, selects qualified
suppliers suggested by the existing vendor-sourcing logic, and publishes the
requirements.

Suppliers receive the invitation in the supplier portal and by email when an
email address is configured. Each supplier submits a private quotation with
price, quantity, VAT treatment, freight, lead time, payment terms, and the
required resin documents. Suppliers may save drafts, quote only the lines they
can supply, offer a partial quantity, revise, or withdraw before the deadline.

The RFQ closes automatically. Supplier prices remain hidden until closure. The
buyer compares total delivered cost, delivery, supplier performance, and
quality evidence. The buyer records a reason and awards each acceptable line.
Draft POs are grouped by supplier and continue through the existing Finance /
VP approval, supplier dispatch, GRN, Incoming QC, Inventory, Bill, Payment,
and GL chain.

## 3. Goals

1. Provide a real manufacturing procurement bidding workflow.
2. Complete a visible segment of Chain 2: Procure to Pay.
3. Preserve traceability from PR to RFQ to quote version to PO line.
4. Keep supplier prices confidential until the RFQ deadline.
5. Include quality evidence in supplier selection without making every bid a
   separate mandatory QC approval.
6. Reuse the existing Purchasing, vendor, supplier portal, notification,
   approval, budget, document, and audit infrastructure.
7. Demonstrate race-safe, auditable business state transitions suitable for a
   production ERP thesis.

## 4. Non-Goals

The MVP will not include:

- Customer tender or sales quotation bidding.
- Public supplier registration or an open marketplace.
- Live reverse auctions or real-time price ranking.
- Multi-PR consolidation into one RFQ.
- Automatic award of a supplier.
- Configurable weighted scoring formulas.
- Substitute resin-grade acceptance.
- A separate RFQ award approval workflow.
- Multi-currency quotations.
- Mandatory QC approval for every quotation.
- Per-shot tooling procurement or full technical tender packages.

These may be considered later, but they must not enter the MVP by implication.

## 5. Product Decisions

| Area | Decision |
|---|---|
| Module | Purchasing RFQs |
| Employee route family | `/purchasing/rfqs` |
| Supplier route family | Existing supplier portal under `/b2b/supplier/rfqs` |
| RFQ source | One approved PR per RFQ |
| RFQ entry point | Start RFQ action on approved PR detail |
| PR conversion | Manual PRs use Direct PO; Sales Order/MRP auto PRs may use Direct PO or Start RFQ |
| RFQ preparation | Four-step draft wizard |
| Supplier selection | System suggests; buyer explicitly selects |
| Supplier eligibility | Qualified suppliers by default; exception with reason |
| Invitation minimum | No fixed minimum |
| Submission channels | Supplier portal plus structured manual fallback |
| Manual fallback | Structured fields plus original PDF or scan |
| Bid confidentiality | Prices hidden until deadline closes |
| Quote revisions | Allowed until deadline; immutable versions |
| Quote withdrawal | Allowed until deadline; audited |
| Deadline | Automatic close; audited extension while open |
| Clarifications | Shared addendum to all invited suppliers |
| Line response | No-quote and partial quantity allowed |
| Award | Buyer decision per line; partial RFQ awards allowed |
| Recommendation | Highlight lowest compliant delivered cost; never auto-award |
| Comparison basis | Total delivered cost with separate VAT and charges |
| Quality | Commercial fields plus basic quality documents |
| QC review | Risk-based review and blocking exception flags |
| Substitute material | Not allowed in MVP |
| PO result | Draft POs grouped by supplier |
| Financial gate | Existing Finance / VP PO approval |
| Budget variance | Warn at award; enforce before PO submission and approval |
| Outcome notices | Notify all suppliers after final resolution |
| Buyer separation | RFQ creator may award; PO approval remains independent |

## 6. Actors and Permissions

### 6.1 Internal roles

The implementation should use existing roles where possible. New RFQ-specific
permissions are more appropriate than a new organization role.

| Actor | Required capability |
|---|---|
| Purchasing officer | Create, edit, publish, extend, evaluate, award, and cancel RFQs |
| Finance officer | View authorized commercial evidence and approve generated POs |
| Vice president | View authorized commercial evidence and approve applicable POs |
| QC inspector | View quality evidence and record compliance or blocking exceptions |
| PR requester | View RFQ status and final sourcing result only |
| System administrator | Technical administration, not ordinary business approval |

Proposed permissions:

- `purchasing.rfq.view`
- `purchasing.rfq.create`
- `purchasing.rfq.publish`
- `purchasing.rfq.evaluate`
- `purchasing.rfq.award`
- `purchasing.rfq.manage`
- `purchasing.rfq.quality_review`

The final permission names must be checked against the current
`RolePermissionSeeder` and role-permission matrix before implementation. The
RFQ feature must not grant business approval authority to `system_admin`.

### 6.2 Supplier portal scope

All active portal users belonging to an invited vendor may collaborate on that
vendor's RFQs. They may view and edit only their vendor's drafts and submitted
quote versions. They may never access another vendor's invitation, price,
document, ranking, or response status.

## 7. State Machines

### 7.1 Purchase Request sourcing state

The current approved-PR auto-conversion path must be changed so an approved PR
does not immediately become a PO before the buyer chooses the sourcing path.

Recommended behavior:

```text
approved -> sourcing_pending
              +-- direct conversion -> existing PO conversion flow
              +-- Start RFQ -> RFQ draft
```

The implementation may represent `sourcing_pending` as a new conversion enum
value or as a separate sourcing status. It must not overload
`manual_required` to mean RFQ-required because that would misrepresent the
operator recovery state.

### 7.2 RFQ state

```text
draft -> open -> closed -> under_evaluation -> awarded
                                      +-- partially_awarded
                                      +-- no_award

open -> cancelled
closed -> cancelled only when no award exists
```

An awarded RFQ is immutable apart from audit-safe display metadata. It cannot
be reopened. A changed requirement requires a new RFQ.

### 7.3 Supplier quote state

```text
draft -> submitted -> superseded
                  +-- withdrawn
                  +-- awarded
                  +-- not_awarded
                  +-- disqualified
```

Only the latest valid submitted version is evaluated. Every version remains
available to authorized reviewers and auditors.

## 8. Functional Requirements

### 8.1 PR handoff

- Add a Start RFQ action to an approved PR detail page.
- Prevent duplicate active RFQs for the same PR unless the previous RFQ is
  `no_award` or `cancelled`.
- Lock the PR sourcing decision transactionally against concurrent direct
  conversion and RFQ creation.
- Preserve the existing direct-conversion path for buyers who choose it.
- Prevent edits to PR lines while an active RFQ is open.

### 8.2 RFQ creation

- Create a draft RFQ from one approved PR.
- Snapshot PR item, description, quantity, unit, and required date into RFQ
  lines.
- Permit RFQ-specific instructions, deadline, documents, and supplier
  selection.
- Do not permit silent item, quantity, budget, or specification changes.
- Use document sequence `RFQ-YYYYMM-NNNN`.
- Publish only after the four-step review is complete.

### 8.3 Supplier invitations

- Suggest candidate suppliers using `VendorSourcingService` or its successor.
- Prefer qualified Approved Supplier entries and existing performance data.
- Require an exception reason for non-qualified invitations.
- Record invitation timestamp, inviter, delivery channel, viewed timestamp,
  response state, and notification outcome.
- Allow any buyer-selected number of invited suppliers.

### 8.4 Supplier quotation

- Display only the invited supplier's RFQ requirements.
- Permit save-as-draft without exposing the draft internally.
- Require structured commercial values and a formal quotation attachment at
  submission.
- Allow no-quote per line.
- Allow offered quantity less than requested quantity.
- Reject offered quantity less than or equal to zero.
- Reject substitute items or grades in the MVP.
- Allow revision and withdrawal only while the RFQ is open.
- Reject submission after the server-side closing timestamp.

### 8.5 Closing and clarifications

- Automatically close open RFQs at the deadline using server-side time.
- Permit an authorized buyer to extend an open RFQ with a mandatory reason.
- Notify all invited suppliers about an extension.
- Publish clarifications as shared addenda visible to every invitee.
- Extend the deadline when a published addendum materially changes the
  requirements.
- Record all addenda, deadline changes, and notifications in the audit trail.

### 8.6 Evaluation and award

- Hide supplier prices until the RFQ is closed.
- After closure, allow authorized evaluators to compare quote versions.
- Calculate total delivered cost from item price, VAT, freight, and other
  disclosed charges using exact decimal arithmetic.
- Highlight the lowest compliant option without selecting it automatically.
- Show supplier lead time, quality history, delivery history, validity, and
  exceptions.
- Permit buyer selection per line.
- Require an award reason per line.
- Require an exception reason for a single-response award.
- Allow unresolved lines and partial RFQ awards.
- Prevent total awarded quantity from exceeding the PR quantity.
- Prevent award of a quote with a blocking QC exception.
- Warn when the award exceeds the PR estimate or budget.
- Require budget acknowledgement or correction before PO submission.

### 8.7 PO generation

- Generate one draft PO per awarded supplier.
- Reuse the existing PO creation service and money calculation conventions.
- Copy the exact awarded price and commercial terms into PO lines.
- Link the PO header to the source PR and RFQ.
- Link every PO line to the RFQ award and exact supplier quote version.
- Make retries idempotent and return existing draft POs instead of duplicating
  them.
- Leave PO approval, dispatch, GRN, Incoming QC, and AP behavior unchanged.

### 8.8 Outcomes and expiry

- Notify the winning supplier of awarded lines and next steps.
- Notify unsuccessful suppliers with a neutral not-awarded result.
- Do not disclose competing prices or rankings.
- Require supplier reconfirmation if the winning quote expires before PO
  approval.
- Close RFQs with no valid responses as `no_award`.
- Permit a new RFQ after `no_award` or cancellation.

## 9. Data Model

The tables should be implemented inside the Purchasing module and use normal
foreign keys, HashID models, audit traits, enum-backed statuses, and decimal
money columns.

### 9.1 `request_for_quotes`

Proposed fields:

- `id`
- `rfq_number`, unique
- `purchase_request_id`, required
- `created_by`, required
- `status`
- `title`
- `instructions`, nullable
- `currency`, default `PHP`
- `issued_at`, nullable
- `closes_at`
- `closed_at`, nullable
- `evaluation_started_at`, nullable
- `resolved_at`, nullable
- `cancellation_reason`, nullable
- `no_award_reason`, nullable
- `budget_warning_level`, nullable
- `budget_warning_message`, nullable
- `budget_acknowledged_by`, nullable
- `budget_acknowledged_at`, nullable
- timestamps

### 9.2 `request_for_quote_items`

Snapshot fields:

- `id`
- `request_for_quote_id`
- `purchase_request_item_id`
- `item_id`
- `description`
- `specification`
- `quantity`
- `unit`
- `required_delivery_date`, nullable
- `allow_partial_quantity`
- `allow_substitute`, fixed false for MVP
- timestamps

### 9.3 `request_for_quote_invitations`

- `id`
- `request_for_quote_id`
- `vendor_id`
- `invited_by`
- `invited_at`
- `viewed_at`, nullable
- `status`
- `exception_reason`, nullable
- `portal_notified_at`, nullable
- `email_notified_at`, nullable
- `last_notification_error`, nullable
- timestamps
- unique `(request_for_quote_id, vendor_id)`

### 9.4 `supplier_quotes`

- `id`
- `request_for_quote_id`
- `vendor_id`
- `invitation_id`
- `portal_user_id`, nullable for manual capture
- `captured_by`, nullable for portal submissions
- `version`
- `status`
- `submitted_at`, nullable
- `withdrawn_at`, nullable
- `withdrawal_reason`, nullable
- `is_current`
- `vat_inclusive`
- `vat_amount`
- `freight_amount`
- `other_charges`
- `total_delivered_cost`
- `quote_valid_until`
- `payment_terms`
- `notes`, nullable
- timestamps
- unique `(request_for_quote_id, vendor_id, version)`

Use a database constraint or partial unique index to ensure one current
submitted version per supplier and RFQ.

### 9.5 `supplier_quote_items`

- `id`
- `supplier_quote_id`
- `request_for_quote_item_id`
- `response_status` such as quoted or no_quote
- `offered_quantity`, nullable for no-quote
- `unit_price`, nullable for no-quote
- `line_vat_amount`
- `line_freight_amount`
- `line_other_charges`
- `line_total_delivered_cost`
- `lead_time_days`, nullable
- `proposed_delivery_date`, nullable
- `minimum_order_quantity`, nullable
- `order_quantity_multiple`, nullable
- `compliance_status`
- `compliance_notes`, nullable
- timestamps

### 9.6 `rfq_awards`

- `id`
- `request_for_quote_id`
- `request_for_quote_item_id`
- `supplier_quote_id`
- `supplier_quote_item_id`
- `vendor_id`
- `awarded_quantity`
- `awarded_unit_price`
- `awarded_total_delivered_cost`
- `award_reason`
- `single_response_justification`, nullable
- `status`
- `awarded_by`
- `awarded_at`
- timestamps

Add constraints preventing an award from exceeding the source RFQ line
quantity when all award rows for that line are considered.

### 9.7 Documents and addenda

Use private file storage and permission-checked controller downloads for:

- RFQ requirement documents
- Supplier quotation PDFs
- Resin datasheets
- Certificates of Analysis
- Safety or compliance documents
- Shared addenda

Do not store uploaded files under the public web root. Validate MIME type on
the server and generate random storage names.

## 10. API Plan

The following endpoint groups are the intended contract. Exact controller and
resource names should follow `docs/PATTERNS.md` during implementation.

### 10.1 Internal Purchasing API

```text
GET    /api/v1/purchasing/rfqs
POST   /api/v1/purchasing/purchase-requests/{purchaseRequest}/rfqs
GET    /api/v1/purchasing/rfqs/{rfq}
PUT    /api/v1/purchasing/rfqs/{rfq}
POST   /api/v1/purchasing/rfqs/{rfq}/publish
POST   /api/v1/purchasing/rfqs/{rfq}/extend
POST   /api/v1/purchasing/rfqs/{rfq}/addenda
GET    /api/v1/purchasing/rfqs/{rfq}/comparison
POST   /api/v1/purchasing/rfqs/{rfq}/award
POST   /api/v1/purchasing/rfqs/{rfq}/cancel
GET    /api/v1/purchasing/rfqs/{rfq}/purchase-orders
```

The Start RFQ endpoint must lock the PR and be idempotent. The award endpoint
must lock the RFQ and all affected lines before validating quantities and
creating awards or draft POs.

### 10.2 Supplier portal API

```text
GET    /api/v1/b2b/supplier/rfqs
GET    /api/v1/b2b/supplier/rfqs/{rfq}
POST   /api/v1/b2b/supplier/rfqs/{rfq}/quotes
PUT    /api/v1/b2b/supplier/rfqs/{rfq}/quotes/{quote}
POST   /api/v1/b2b/supplier/rfqs/{rfq}/quotes/{quote}/submit
POST   /api/v1/b2b/supplier/rfqs/{rfq}/quotes/{quote}/withdraw
GET    /api/v1/b2b/supplier/rfqs/{rfq}/quotes/{quote}/versions
POST   /api/v1/b2b/supplier/rfqs/{rfq}/documents
```

Every supplier endpoint must resolve the vendor through the supplier portal
guard and tenancy middleware. A HashID supplied by a supplier must still be
checked against that vendor's invitation and quote ownership.

## 11. User Interface Plan

### 11.1 Internal pages

```text
/purchasing/rfqs
/purchasing/rfqs/:id
/purchasing/rfqs/:id/compare
/purchasing/rfqs/:id/award
```

The RFQ creation flow is a four-step wizard:

1. Requirements
2. Suppliers
3. Terms and Documents
4. Review and Publish

The RFQ list must handle loading, error, empty, data, and stale states. The
detail page should contain requirements, invitations, quotations, comparison,
awards, generated POs, and audit history.

The comparison page must remain usable on mobile. Desktop may use a wide
comparison table; mobile should use horizontal scrolling or supplier cards,
not compressed unreadable columns.

### 11.2 Supplier portal pages

```text
/portal/supplier/rfqs
/portal/supplier/rfqs/:id
/portal/supplier/rfqs/:id/quote
```

The supplier inbox displays invitation status, deadline, draft state,
submission state, and final outcome. It does not display competitor data or
Ogami's internal PR estimate.

The quote form must support:

- Save draft
- Structured line response
- No-quote selection
- Partial quantity
- Separate tax and charges
- Quality document upload
- Formal quotation PDF upload
- Submit
- Revision
- Withdrawal

## 12. Integration Plan

### Purchasing

- Extend PR sourcing/conversion state.
- Reuse `VendorSourcingService` for candidate suggestions.
- Reuse vendor, Approved Supplier, and performance data.
- Reuse PO creation and PDF patterns.
- Preserve existing direct PR-to-PO conversion.

### Supplier portal

- Reuse supplier guard, portal password middleware, and tenancy scope.
- Reuse portal notification and private document patterns.
- Do not expose employee Purchasing endpoints to portal users.

### Approval and budgeting

- Do not add a new RFQ approval workflow in the MVP.
- Route the resulting PO through the existing approval service.
- Use existing budget warning, acknowledgement, and enforcement behavior.
- Add no RFQ model to the approval registry unless a separate award approval is
  approved later.

### Quality

- Reuse existing quality permissions and document conventions.
- Risk-based RFQ review may create a QC review record or a dedicated RFQ
  quality-review child record, depending on the existing inspection/document
  model.
- Incoming QC remains the authoritative acceptance gate after delivery.

### Notifications and events

Use the existing notification service and explicit event listener registration.
Recommended domain events:

- `RfqPublished`
- `RfqSupplierInvited`
- `SupplierQuoteSubmitted`
- `SupplierQuoteWithdrawn`
- `RfqClosed`
- `RfqAddendumPublished`
- `RfqAwarded`
- `RfqNoAward`
- `RfqCancelled`
- `RfqQuoteReconfirmationRequired`

Notification failures must be durable and observable. They must not change a
successful bid or award into a false failure, and a failure recorder must not
swallow its own database errors.

## 13. Security and Audit Requirements

- Employee routes use Sanctum session authentication and feature/module/
  permission middleware.
- Supplier routes use the supplier portal guard and tenancy middleware.
- Never expose integer IDs in URLs or resources.
- Enforce row visibility server-side.
- Hide submitted prices before RFQ closure at the service and query level,
  not only in the React UI.
- Lock RFQ state during close, submit, withdraw, extend, and award operations.
- Use exact decimal arithmetic; never convert quote money to floating point.
- Validate files by MIME type and store them privately with random names.
- Record actor, timestamp, IP, and user agent for publish, view, submit,
  revise, withdraw, close, extend, disqualify, addendum, award, cancellation,
  budget acknowledgement, and PO generation actions.
- Preserve old quote versions and original documents.
- Do not reveal supplier response count if it could expose competitive
  information, unless the final product decision explicitly allows it.

## 14. Concurrency and Invariants

The following must be enforced in service transactions and, where possible,
with database constraints:

1. One active sourcing decision exists for a PR.
2. Direct conversion and RFQ creation cannot both win the PR handoff.
3. An RFQ can be published only from draft.
4. A quote can be submitted only while the RFQ is open.
5. A quote received at or after the closing timestamp is rejected.
6. Only one current quote version exists per supplier and RFQ.
7. A supplier may submit only against its own invitation.
8. A withdrawn or superseded version cannot be awarded.
9. Total awarded quantity cannot exceed RFQ quantity.
10. An RFQ cannot be awarded twice after a successful retry.
11. Generated POs are idempotent under queue retry or repeated UI submission.
12. A quote that is no longer valid cannot release a PO without reconfirmation.
13. An awarded RFQ cannot be reopened or edited.
14. A blocking QC exception prevents award.
15. A budget exception prevents PO submission until acknowledged or corrected.

## 15. Implementation Work Breakdown

### Phase 0: Foundation and documentation

- Confirm the current migration maximum and dependency ordering.
- Confirm existing PR conversion and queue idempotency behavior.
- Confirm vendor sourcing and supplier portal row scopes.
- Add RFQ scope to `docs/SCHEMA.md`.
- Add the RFQ process to `docs/PROCESS-FLOWS.md`.
- Add permissions and roles to `docs/SEEDS.md` if applicable.
- Update `docs/USER-MANUAL.md`, `docs/DEMO-SCRIPT.md`, and
  `docs/DEFENSE-TRACEABILITY.md` after implementation.

### Phase 1: Persistence and domain types

- Create migrations for RFQ, line, invitation, quote, quote-line, award,
  document, and addendum tables.
- Add enums for RFQ, invitation, quote, quote-line, award, and QC review
  states.
- Add HashID, audit, casts, relations, scopes, factories, and policies.
- Add PR sourcing state or equivalent conversion guard.
- Add PO header and line traceability fields.
- Add document sequence registration for `RFQ-YYYYMM-NNNN`.

### Phase 2: Services and backend workflow

- `RequestForQuoteService` for draft, publish, extend, close, cancel, and
  addendum behavior.
- `SupplierQuoteService` for draft, submit, revise, withdraw, and manual
  capture.
- `RfqEvaluationService` for comparison, delivered-cost calculation, QC
  exceptions, and recommendation.
- `RfqAwardService` for locked line awards and partial quantities.
- `RfqPurchaseOrderService` for idempotent draft PO generation.
- Integrate PR conversion guard and existing PO approval.
- Add scheduled automatic closing command or scheduler entry.

### Phase 3: Requests, resources, controllers, and routes

- Implement Form Requests with authorization and validation.
- Implement Resources with HashIDs and permission-sensitive fields.
- Add internal Purchasing routes and controller actions.
- Add supplier portal routes and controller actions.
- Add private document download endpoints.
- Register events and listeners explicitly in `AppServiceProvider`.

### Phase 4: Permissions, notifications, and seed data

- Add RFQ permissions to the permission seeder.
- Assign permissions to purchasing, finance, vice president, and QC roles.
- Add supplier portal fixtures and at least three demo vendors.
- Add resin, approved-supplier, and quality-document demo data.
- Add notification templates and durable dispatch records.
- Add a dashboard or task link only after the core feature is stable.

### Phase 5: Internal SPA

- Add TypeScript types with string HashIDs and decimal strings.
- Add API client methods and query keys.
- Add lazy-loaded RFQ list, detail, wizard, comparison, and award pages.
- Add all required page states.
- Add permission and module guards.
- Add toast success/error handling and query invalidation for mutations.
- Follow Atelier tokens and existing Purchasing visual patterns.

### Phase 6: Supplier portal SPA

- Add supplier RFQ inbox.
- Add RFQ detail and requirement documents.
- Add draft quote form.
- Add quote document and quality document upload.
- Add submission, revision, and withdrawal states.
- Add outcome views with vendor-only data.
- Test desktop and narrow mobile layouts.

### Phase 7: Verification and defense evidence

- Run focused backend tests during implementation.
- Run the relevant SPA unit and browser tests with Chromium for layout checks.
- Run supplier cross-tenant and employee cross-guard checks.
- Add a complete thesis demo fixture and walkthrough.
- Update process, schema, manual, defense, and QA documentation.
- Run the full relevant test suite at the end using a dedicated test database.

## 16. Testing Plan

### Backend feature tests

- Approved PR can enter sourcing-pending state.
- Direct conversion remains available.
- RFQ creation snapshots PR lines.
- RFQ cannot start from draft, rejected, cancelled, or converted PRs.
- Supplier suggestions are qualified and correctly scoped.
- Non-qualified invitation requires a reason.
- Draft RFQ remains invisible until published.
- Supplier can see only its own invitation.
- Supplier can save, submit, revise, and withdraw before close.
- Supplier cannot submit after close.
- Internal users cannot see prices before close.
- Automatic close and manual extension behave correctly.
- Shared addendum reaches all invitees.
- Single-response award requires justification.
- Partial quantities and awards cannot exceed PR quantity.
- Blocking QC exception prevents award.
- Budget variance is warning/enforcement correct.
- Quote expiry requires reconfirmation.
- No-award can be reissued without duplicate active state.
- Cancellation rules are enforced.
- Award retry is idempotent.
- PO generation groups lines by vendor.
- PO line links retain exact quote version.

### Security tests

- Supplier A cannot access Supplier B's RFQ or quote.
- Supplier portal cannot access employee RFQ endpoints.
- Requester cannot access confidential quote prices.
- Unauthorized employee cannot evaluate or award.
- HashID and route binding cannot resolve another tenant's record.
- Private documents cannot be downloaded without permission.
- A system administrator does not receive ordinary business award authority
  solely by role name.

### Concurrency tests

- Simultaneous final submission and automatic close.
- Simultaneous direct conversion and Start RFQ.
- Simultaneous quote revision and withdrawal.
- Simultaneous award requests.
- Queue retry during draft PO generation.
- Duplicate notification or event delivery.

### SPA tests

- RFQ list has loading, error, empty, data, and stale states.
- Wizard validation prevents incomplete publication.
- Comparison table preserves decimal strings and does not auto-award.
- Award review requires reasons.
- Supplier draft survives navigation and reload.
- Deadline and closed states disable submission actions.
- Supplier views never render competitor data.
- Desktop and mobile layouts are usable in Chromium.

## 17. Thesis Demonstration Script

1. Open an approved resin Purchase Request generated from an MRP shortage.
2. Show that the buyer can choose Start RFQ instead of direct conversion.
3. Create the draft RFQ through the four-step wizard.
4. Select three demo suppliers from the sourcing suggestions.
5. Publish the RFQ and show the invitation notifications.
6. Log in as Supplier A and save a draft.
7. Log in as Supplier B and submit a quote with resin documents.
8. Log in as Supplier C and submit a partial quantity.
9. Revise one quote before the deadline.
10. Show that internal staff cannot see prices before closure.
11. Close the RFQ and open the comparison page.
12. Show delivered-cost calculation and supplier quality evidence.
13. Select different suppliers for different lines if applicable.
14. Record an award reason.
15. Generate draft POs grouped by supplier.
16. Show RFQ-to-quote-version-to-PO-line traceability.
17. Approve the PO through Finance / VP workflow.
18. Continue to GRN and Incoming QC.
19. Show supplier outcome notifications.
20. Demonstrate a single-response or partial-award exception.

## 18. Release Checklist

- [ ] Product decisions in this plan remain unchanged or are formally revised.
- [ ] Migration dependency ordering has been verified.
- [ ] PR auto-conversion race is covered by a transaction test.
- [ ] All models use HashID and audit conventions.
- [ ] All money is decimal and remains a string in TypeScript.
- [ ] Quote prices are server-side confidential until closure.
- [ ] Supplier tenancy tests pass.
- [ ] File uploads are private, MIME-validated, and permission checked.
- [ ] RFQ state transitions are transactionally locked.
- [ ] Award and PO generation are idempotent.
- [ ] Existing direct PR-to-PO conversion tests still pass.
- [ ] Existing PO approval and supplier dispatch tests still pass.
- [ ] Notifications and failed dispatches are observable.
- [ ] Dashboard or global-search additions use the owning module's row scope.
- [ ] Permission drift tests cover every new protected route.
- [ ] Internal and supplier pages are lazy-loaded and guarded.
- [ ] All page states and mutation toasts are implemented.
- [ ] Schema, process flow, manual, seed, demo, and defense docs are updated.
- [ ] Thesis demo passes from MRP shortage through Incoming QC.

## 19. Phase 2 Candidates

Only after the MVP is stable:

- Configurable weighted evaluation criteria.
- Multi-PR consolidation.
- Supplier substitution proposal with formal QC review.
- Separate high-value RFQ award approval.
- Reverse auction mode.
- Supplier performance feedback from awarded RFQs.
- RFQ analytics and price-trend reporting.
- Formal supplier clarification inbox with controlled threads.
- Customer-side tender bidding as a separate CRM capability.

## 20. Definition of Done

The feature is complete when a buyer can take one approved resin PR through a
sealed RFQ, receive and compare supplier quotations, make an evidence-backed
line-level award, generate traceable draft POs, and continue through the
existing PO approval and Incoming QC chain without bypassing security,
budgeting, audit, or supplier tenancy controls.
