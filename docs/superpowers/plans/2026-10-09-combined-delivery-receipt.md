# Combined Delivery Receipt Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan inline, task by task. Steps use checkbox syntax. Do not delegate or change live business records without user authorization.

**Goal:** Let a customer review an original shipment and its approved replacement in one modal, enter receiver details once, and confirm their separate receipts with one submission.

**Architecture:** Keep both delivery records and reuse the existing customer receipt validation, `DeliveryService::confirm()`, and replacement settlement checks. Add a customer-scoped confirmation-group query and an atomic submission endpoint. Extend the existing receipt modal; do not introduce a new shipment model, dependencies, or a database migration.

**Tech Stack:** Laravel, PostgreSQL, BCMath, React, TypeScript, TanStack Query, existing Modal and Button components, PHPUnit, Vitest, Testing Library.

**Spec:** The accepted design is recorded in the Design section of this document: combine the confirmation experience while preserving the original and replacement deliveries.

## Global Constraints

- This planning task changes documentation only. Execution must not confirm real deliveries as a test.
- Preserve existing single-delivery confirmation and customer, driver, internal, and supplier permissions.
- Preserve delivered quantities, original quality inspection failures, return-case evidence, stock movements, and invoice history.
- Scope a group through the exact return-case and replacement-order relationships; matching customer or product alone is insufficient.
- Each delivery still needs its own valid delivery proof. The driver remains the proof source; do not add customer or ImpEx proof-upload steps.
- Quantity inputs are decimal strings. Use BCMath on the server and the existing BigInt-based quantity validation in the modal. Pieces require whole numbers.
- Original and replacement quantities must not be added together for original-order fulfillment or billing.
- One HTTP submission must commit all new receipt confirmations or none. Existing invoice and certificate handoff recovery remains best-effort and visible.
- No manual case-closing, receipt-linking, or duplicate invoice buttons are added.
- Preserve unrelated changes in the shared workspace. Do not stage or commit the existing broad dirty diff.

## Design

### Customer experience

On either eligible delivery detail page, replace the primary **Confirm Receipt** action with **Confirm received shipments**. The modal shows:

1. **Original shipment — DR-202610-0001:** 500 pieces received, comprising its existing 2-piece and 498-piece shipment lines. Show the original order number and delivery date.
2. **Replacement shipment — DR-202610-0002:** 2 replacement pieces received, clearly labeled **No charge**. Show its separate replacement order and delivery date.
3. Receiver name, optional position, and optional remarks, entered once.
4. A single **Confirm received shipments** submit action.

Copy: “This records receipt of both shipments. Replacement pieces are free. Your original order quantity stays unchanged.” Explain that receipt records physical arrival, including previously reported damaged goods; it does not certify every original piece as defect-free. Show the associated case number and reported replacement quantity without exposing internal resolution notes.

Do not display a combined 502-piece fulfillment total. The customer physically received 500 original pieces and 2 additional replacement pieces; the original order still covers 500 pieces. Do not merge shipment lines even when product codes match.

Already-confirmed shipments remain visible with **Receipt already confirmed** and read-only quantities. Preserve their original receiver, timestamp, and audit records. Shared receiver details apply only to newly confirmed shipments.

If the group is blocked, retain the valid single-delivery action where available and show the specific reason for the combined action being unavailable. Do not disable replacement-only receipt merely because the original has another unresolved case. If no eligible relationship exists, keep the current single-delivery experience.

### Eligibility and scope

Resolve the anchor delivery's owning customer before returning any related record. For a replacement anchor, use `SalesOrder.return_case_id` and require the reciprocal `ReturnCase.replacement_sales_order_id`. For an original anchor, select its customer redelivery case only when that relationship is unambiguous. Multiple candidate cases do not become one arbitrary group.

One group contains the case's original delivery and the delivered or confirmed shipments of its approved zero-price replacement order. This supports a replacement order split across multiple deliveries without combining unrelated orders. Scheduled, in-transit, returned, and cancelled shipments are not silently treated as received.

Combined confirmation is available only when:

- The case is a customer redelivery case in `in_progress` or `resolved`, with valid reciprocal lineage and zero-price order lines and totals.
- The original and every included replacement belong to the authenticated customer, are delivered or confirmed, and have acceptable proof types.
- Recorded received quantities of the included and previously confirmed replacement shipments cover every verified affected product quantity.
- Any linked physical return is complete and covers the required defective quantity under the existing settlement rules.
- No other unresolved case blocks any participating delivery. A case blocking the original may be the same case this submission will settle; no other billing hold is waived.
- There is at least one unconfirmed delivery. Fully confirmed groups are returned as read-only context and support safe submission retries.

Group discovery is read-only. It must not call `synchronizeReplacement()` or create invoices, audit events, stock movements, or case events.

### Server contract

Add routes inside the existing customer-portal guard and feature group:

```text
GET  /api/v1/b2b/customer/deliveries/{delivery}/receipt-group
POST /api/v1/b2b/customer/deliveries/{delivery}/confirm-group
```

The POST uses the existing `throttle:sensitive` middleware. GET returns `{ "data": null }` for an owned delivery with no unambiguous group. Foreign anchors return the existing ownership error and reveal no group details.

Use these frontend contracts in `spa/src/types/b2b.ts`:

```ts
export interface PortalReceiptGroup {
  case: { id: string; case_number: string; status: string };
  version: string;
  can_confirm: boolean;
  blocked_reason: string | null;
  shipments: Array<{
    kind: 'original' | 'replacement';
    already_confirmed: boolean;
    delivery: PortalDeliveryDetail;
  }>;
}

export interface PortalReceiptGroupSubmission {
  version: string;
  receiver_name: string;
  receiver_position?: string;
  delivery_remarks?: string;
  shipments: Array<{
    delivery_id: string;
    lines: Array<{
      delivery_item_id: string;
      received_quantity: string;
    }>;
  }>;
}
```

GET returns `PortalReceiptGroup | null`. POST returns `{ data: PortalReceiptGroup, message: string }`, including the refreshed state of both deliveries and the case. Send all displayed shipments, including read-only confirmed context, so the server can check the exact reviewed group.

The server computes `version` with a SHA-256 hash of a canonical, sorted snapshot: case identity and state, replacement-order lineage and prices, verified line quantities, linked return completion and coverage, shipment membership, statuses, item identities, recorded received quantities, and valid proof identities. Recompute under locks before writing. A changed group returns a validation error: “Shipment details changed. Review the refreshed quantities before confirming.” Frontend refetches without silently replacing entered values or automatically resubmitting.

Server request rules allow only the documented keys, require a 64-character lowercase hexadecimal version, reject duplicate shipment IDs and duplicate line IDs, and cap the shipment array at 100 entries. Quantities match `/^\d{1,12}(?:\.\d{1,2})?$/D`; piece quantities must be integral. All shipment IDs must exactly match the server-derived group, and every shipment must contain its exact item-ID set. Each received quantity must equal the immutable recorded customer-received quantity, falling back to dispatched quantity as the existing endpoint does. A shortage or excess requires the existing problem-report workflow, not a receipt override.

Errors use nested field paths such as `shipments.1.lines.0.received_quantity`, plus `version` or `shipments` for group-level changes. Receiver name is trimmed, required, and limited to 100 characters; position is limited to 100 and remarks to 500.

### Atomic completion, audit, and retries

Lock all participating sales orders in ascending ID order, then deliveries and their items in ascending ID order, then the case. Re-read lineage, ownership, status, proofs, quantities, obligations, and group membership under those locks. Use a bounded transaction retry count of 3. Existing case actions can acquire a case lock before replacement-order locks; the execution task must align the relevant customer-redelivery action path before calling this safe under concurrent use.

Validate the entire payload before invoking any confirmation. For a valid new submission:

1. Confirm all unconfirmed replacement deliveries through `CustomerPortalService::confirmDelivery()` using shared receiver details and each delivery's exact lines.
2. Call `ReturnCaseService::synchronizeReplacement($case->id)` inside the outer transaction. Re-read the case and require its real settlement to be complete. Do not depend on the after-commit DeliveryConfirmed listener to clear the original hold during this request.
3. Confirm the unconfirmed original through the same existing customer service. Its normal billing-hold guard must pass without a bypass.
4. Commit, then let the existing outbox events and notifications run normally. Return refreshed individual delivery and case states.

If settlement or original confirmation fails, the outer transaction rolls back new replacement confirmations, case resolution, receipt audits, invoice rows, and outbox records. Preserve existing delivery-service certificate and invoice handoff recovery semantics; filesystem certificate work must retain its current idempotent naming and recovery behavior rather than be advertised as a transactional file write.

Existing customer receipt audits remain one per newly confirmed delivery, sharing the request correlation ID. Do not create a synthetic merged delivery or a third receipt. Do not move dispatched stock again.

For retries, validate ownership, lineage, exact shipment and line membership, and recorded quantities even when deliveries are already confirmed. If all reviewed shipments are confirmed, return their current state with no duplicate effects, ignoring an older version that only reflects the completed confirmation. Do not overwrite confirmed receiver details. If only part of the reviewed group changed concurrently, reject the stale version and require review of the refreshed group.

Original confirmation uses normal invoice creation and recovery. The zero-price replacement keeps `invoice_handoff_status = not_required` and creates no customer invoice. A successful receipt response does not claim an invoice is finalized or paid.

## Files and responsibilities

| File | Change |
| --- | --- |
| `api/app/Modules/B2B/routes.php` | Add the guarded GET and throttled POST routes. |
| `api/app/Modules/B2B/Controllers/CustomerPortalController.php` | Resolve the portal user, call the group service, serialize each nested delivery with CustomerDeliveryResource. |
| `api/app/Modules/B2B/Requests/Customer/ConfirmPortalReceiptGroupRequest.php` | New strict receiver, version, shipment, and line request rules. |
| `api/app/Modules/B2B/Services/CustomerPortalService.php` | Resolve a precise group, produce its eligibility snapshot, validate and confirm atomically; reuse single-receipt effects. |
| `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php` | Align locks for customer-redelivery actions that touch the participating original or replacement documents. Reuse settlement and automatic closure. |
| `spa/src/types/b2b.ts` | Add PortalReceiptGroup and PortalReceiptGroupSubmission. |
| `spa/src/api/b2b/customer.ts` | Add typed getReceiptGroup and confirmReceiptGroup methods. |
| `spa/src/pages/portal/customer/deliveries/ConfirmReceiptDialog.tsx` | Add optional group mode; keep existing validation, receiver fields, pending guard, and ModalFooter. |
| `spa/src/pages/portal/customer/deliveries/detail.tsx` | Query group context for delivered/confirmed details, choose one primary receipt action, show blocked reasons, invalidate related portal data. |
| `api/tests/Feature/B2B/CustomerPortalCombinedReceiptTest.php` | New group discovery, hostile payload, atomic rollback, retry, and billing tests. |
| `api/tests/Feature/B2B/CustomerPortalDeliveryConfirmTest.php` | Preserve existing single confirmation regressions. |
| `api/tests/Feature/ReturnManagement/ReturnCaseCustomerReplacementTest.php` | Verify the real replacement lifecycle still closes automatically and creates no replacement invoice. |
| `api/tests/Feature/ReturnManagement/ReturnCaseWorkflowTest.php` | Preserve partial replacement, physical return, and case-action behavior. |
| `spa/src/pages/portal/customer/deliveries/detail.test.tsx` | Add group-mode UI and payload tests alongside single-mode tests. |

Do not change OpenApi annotations, dependencies, exports, or configuration unrelated to these routes. No existing delivery receipt annotation was found in `api/app/OpenApi.php`; document the new contract here and in the typed API client.

## Task 1: Read-only group discovery

**Interfaces:** Add `CustomerPortalService::receiptGroup(int $customerId, Delivery $anchor): ?array`. Its non-null result contains `case: ReturnCase`, `version: string`, `can_confirm: bool`, `blocked_reason: ?string`, and `shipments: array` entries containing `kind`, `already_confirmed`, and `delivery: Delivery`. The controller serializes case hash IDs and each CustomerDeliveryResource. Add `customerPortalApi.getReceiptGroup(id: string): Promise<PortalReceiptGroup | null>`.

- [x] Add `CustomerPortalCombinedReceiptTest` fixtures using the existing portal, sales-order, delivery, and signed-proof patterns from CustomerPortalDeliveryConfirmTest. Use one PC-001 product, original order quantity `500.00` at `35.00`, original delivery lines `2.000` and `498.000`, a case with `2.000` verified defective pieces on the 498-piece line, and a replacement order with `return_case_id`, quantity `2.00`, and zero prices/totals. The original and replacement start delivered with customer-received quantities matching their shipment lines. Create their own signed receipt proofs.
- [x] Write discovery tests asserting original and replacement anchors return identical group membership, original line IDs remain distinct, replacement is labeled no-charge through `kind`, no unrelated order appears, and GET creates no receipt audit, invoice, outbox entry, or resolved case.

```php
$before = AuditLog::count();
$response = $this->actingAs($portal, 'customer_portal')
    ->getJson("/api/v1/b2b/customer/deliveries/{$original->hash_id}/receipt-group")
    ->assertOk();
$response->assertJsonPath('data.shipments.0.kind', 'original');
$response->assertJsonPath('data.shipments.1.kind', 'replacement');
$response->assertJsonPath('data.shipments.1.delivery.items.0.quantity_delivered', '2.000');
$this->assertSame($before, AuditLog::count());
$this->assertSame('in_progress', $case->fresh()->status->value);
```

- [x] Run the new tests in the isolated test container and verify failure because the route is absent.
- [x] Implement server-derived lineage and read-only eligibility. Missing proof, incomplete replacement coverage, incomplete physical return, another blocking case, and ambiguous original lineage produce specific blocked reasons. An unrelated owned delivery returns null. Add the GET route and serialization, then the typed API client.
- [x] Pass discovery tests for both anchors, unrelated/foreign anchors, mixed-product coverage, split replacement shipments, confirmed context, and every blocked condition. Only database fixture IDs are used; no live delivery lookup is necessary.

## Task 2: Atomic grouped receipt endpoint

**Interfaces:** Add `CustomerPortalService::confirmReceiptGroup(int $customerId, Delivery $anchor, array $data, CustomerPortalUser $portalUser): array`, returning the same group structure. Add `customerPortalApi.confirmReceiptGroup(id: string, form: PortalReceiptGroupSubmission): Promise<{ data: PortalReceiptGroup; message: string }>`. Keep `confirmDelivery()` callable with its existing signature.

- [x] Add strict FormRequest rules and failing tests for the public POST contract. Group fixture request bodies use the version from GET and every displayed shipment/item ID; do not send case IDs, customer IDs, prices, statuses, or billing override flags.

```php
return [
    'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
    'receiver_name' => ['required', 'string', 'max:100'],
    'receiver_position' => ['nullable', 'string', 'max:100'],
    'delivery_remarks' => ['nullable', 'string', 'max:500'],
    'shipments' => ['required', 'array', 'min:1', 'max:100'],
    'shipments.*' => ['required', 'array:delivery_id,lines'],
    'shipments.*.delivery_id' => ['required', 'string', 'distinct'],
    'shipments.*.lines' => ['required', 'array', 'min:1'],
    'shipments.*.lines.*' => ['required', 'array:delivery_item_id,received_quantity'],
    'shipments.*.lines.*.delivery_item_id' => ['required', 'string', 'distinct'],
    'shipments.*.lines.*.received_quantity' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
];
```

Trim the receiver name in `prepareForValidation()` as the existing request does. Reject unknown top-level keys in an after-validation check using `array_diff(array_keys($this->all()), ['version', 'receiver_name', 'receiver_position', 'delivery_remarks', 'shipments'])`; add a `shipments` validation error if any exist. Numeric IDs remain server-decoded hash IDs, never raw client database IDs.

```php
$payload = [
    'version' => $group['version'],
    'receiver_name' => 'Toyota receiver',
    'shipments' => array_map(static fn (array $shipment): array => [
        'delivery_id' => $shipment['delivery']['id'],
        'lines' => array_map(static fn (array $item): array => [
            'delivery_item_id' => $item['id'],
            'received_quantity' => bcadd((string) $item['quantity_delivered'], '0', 2),
        ], $shipment['delivery']['items']),
    ], $group['shipments']),
];
$response = $this->actingAs($portal, 'customer_portal')->postJson(
    "/api/v1/b2b/customer/deliveries/{$replacement->hash_id}/confirm-group", $payload
)->assertOk();
$this->assertSame('confirmed', $original->fresh()->status->value);
$this->assertSame('confirmed', $replacement->fresh()->status->value);
$this->assertSame('resolved', $case->fresh()->status->value);
$this->assertDatabaseMissing('invoices', ['delivery_id' => $replacement->id]);
```

- [x] Run and confirm the new POST tests fail before implementing mutations.
- [x] Reuse the current single-receipt line checks at one responsible layer. If extracted into a private `validateReceiptLines(Delivery $delivery, array $lines): void`, invoke it for every grouped shipment, including confirmed context, before any early return. Preserve exact IDs, decimal strings, whole pieces, and immutable recorded quantities.
- [x] Complete the lock-order alignment and concurrency checks described at the start of Task 3 before introducing the grouped mutation into the shared application. Those checks are a backend prerequisite, not a UI dependency.
- [x] Add strict shape limits, ownership checks, exact group membership, snapshot validation, and nested errors. Test empty, negative, exponent, fractional pieces, overlong decimals, excess, shortage, duplicate IDs, swapped line IDs, an unrelated same-customer delivery, and a foreign customer. Assert all participating statuses and effect counts remain unchanged after rejected requests.
- [x] Implement the outer transaction and prescribed replacement-first ordering. Call existing `synchronizeReplacement()` synchronously before confirming the original; require the refreshed case to be resolved. Do not clear a hold directly or write a fake settlement.

Inside the validated, locked transaction, use the existing domain calls in this order. Here `$replacementDeliveries` contains the server-derived replacement models and `$shipmentPayloads` is keyed by their decoded delivery IDs after exact membership validation:

```php
$receiver = array_intersect_key($data, array_flip([
    'receiver_name', 'receiver_position', 'delivery_remarks',
]));
foreach ($replacementDeliveries as $replacementDelivery) {
    $this->confirmDelivery($customerId, $replacementDelivery, $receiver + [
        'lines' => $shipmentPayloads[$replacementDelivery->id]['lines'],
    ], $portalUser);
}
app(ReturnCaseService::class)->synchronizeReplacement((int) $case->id);
if ($case->fresh()->status->value !== 'resolved') {
    throw new BusinessRuleException('Replacement work is not complete. Review the problem report before confirming both shipments.');
}
$this->confirmDelivery($customerId, $original, $receiver + [
    'lines' => $shipmentPayloads[$original->id]['lines'],
], $portalUser);
```
- [x] Add failure injection after replacement confirmation and before original confirmation; assert rollback of receipt audits, delivery statuses, case resolution, invoices, and outbox entries. Repeat with a linked physical return incomplete and a second original billing hold.
- [x] Test full retries and a concurrent single confirmation. Already confirmed deliveries remain unchanged, customer audits and invoices do not duplicate, mixed stale state requires refresh, and invalid quantities do not become accepted through the confirmed no-op branch.
- [x] Assert original SalesOrderItem delivery coverage remains `500.00`, replacement coverage remains `2.00`, existing dispatch stock is unchanged, and only original invoice quantities/prices apply. Preserve visible invoice recovery when accounting handoff fails.

## Task 3: Concurrency and modal integration

**Interfaces:** Extend `ConfirmReceiptDialog` props with `group?: PortalReceiptGroup` while preserving `{ delivery: PortalDeliveryDetail; onClose: () => void }`. Use the group endpoints only in group mode; keep the old endpoint for single mode.

- [x] Before adding UI, align customer-redelivery case-action locks with the grouped endpoint. For an existing customer redelivery case, resolve source and replacement order IDs, lock those orders sorted, then participating deliveries sorted, then re-read and lock the case before actions that create or link replacement documents, create a physical return, or resolve the case. Do not change supplier locking or unrelated review actions. Revalidate lineage after locking; handle a newly assigned replacement by restarting the bounded transaction rather than acquiring earlier-order locks out of order.
- [x] Add an isolated PostgreSQL concurrency check using separate database connections for grouped receipt versus single receipt and grouped receipt versus customer-redelivery case action. Both callers must finish within the test timeout, with at most one receipt audit per delivery and no duplicate invoice. Verify all callbacks emit notifications/outbox effects only after commit. Do not run this check against the production database.
- [x] Extend detail tests with a typed group fixture containing the current original two shipment lines plus a separate 2-piece replacement. Test the primary action replaces, rather than duplicates, the single receipt action. Mock `getReceiptGroup` and `confirmReceiptGroup` in the existing API mock.
- [x] Query group context only after an owned detail is loaded and its status is delivered or confirmed. Use query key `['portal', 'customer', 'receipt-group', id]` and the existing visible-page refresh cadence. A failed group query shows Retry for group discovery while preserving a valid single receipt action.
- [x] Render original and replacement sections inside the current modal, with shipment number, order, date, no-charge label, per-line expected quantities, and distinct input labels. Scope quantity state with delivery ID plus item ID; never collapse identical product codes. Confirmed context is read-only and retains original receiver details.

```tsx
{group?.shipments.map(({ kind, already_confirmed, delivery: shipment }) => (
  <section key={shipment.id} aria-label={shipment.delivery_number}>
    <h3>{kind === 'replacement' ? 'Replacement shipment · No charge' : 'Original shipment'}</h3>
    <p>{shipment.delivery_number} · {shipment.sales_order?.so_number}</p>
    {already_confirmed && <p>Receipt already confirmed</p>}
  </section>
))}
```

- [x] Reuse existing live quantity validation and server-error presentation. Errors remain red with `aria-invalid` and associated error IDs. A mismatch links to Report a problem for that shipment. Do not hide a mismatch by clamping, rounding, or changing the other shipment's quantity.
- [x] Submit one grouped POST, guard double submits, and preserve entered values on errors. On stale snapshot failure, refetch group context and require a fresh customer review; do not submit automatically. On success, set each returned delivery cache and invalidate portal delivery lists, order details/chains, invoices, and case data under the existing portal query prefix.

```ts
expect(customerPortalApi.confirmReceiptGroup).toHaveBeenCalledTimes(1);
expect(customerPortalApi.confirmDelivery).not.toHaveBeenCalled();
expect(group.shipments.map(({ delivery }) => delivery.id)).toEqual(['dr-1', 'dr-2']);
expect(screen.queryByText('502 pcs')).not.toBeInTheDocument();
```

- [x] Preserve Cancel/Escape/focus behavior, lock closing while submitting, and keep footer visible with a scrollable modal body. Check receiver length limits, whitespace-only name, invalid quantities, read-only confirmed context, blocked group fallback, duplicate submit, server failure, stale response, and successful cache refresh in Vitest.

## Task 4: Focused verification and handoff

- [x] Copy only changed API sources and test files into the existing isolated API mount at `/tmp/kwatog-finish-first-20261008/api`. Preserve `phpunit.case-validation.xml` and its isolated `ogami_test_finish_first_core_20261008` database. Do not replace test configuration with production configuration. Run database-refresh tests sequentially.

```bash
docker exec -e WO_FINISH_FIRST_QC_ENABLED=false -e WO_OUTGOING_CONFIRMED_CHECKS_ENABLED=false ogami-finish-first-20261008 php -d memory_limit=512M vendor/bin/phpunit -c phpunit.case-validation.xml --filter 'CustomerPortalCombinedReceiptTest|CustomerPortalDeliveryConfirmTest|CustomerPortalDeliveryConfirmableTest|ReturnCaseCustomerReplacementTest|ReturnCaseWorkflowTest'
```

- [x] Run frontend regression tests and the production build from `spa`:

```bash
npx vitest run src/pages/portal/customer/deliveries/detail.test.tsx
npm run build
```

- [x] Inspect the real component in a mocked browser at desktop width 1440 and mobile width 390. Browser checks cover three shipment lines, red excess errors, a ready form, Cancel, reachable footer, and no horizontal overflow or console errors. Vitest covers confirmed original context, missing-proof wait, stale-group recovery, and pending submit. No browser action calls a live confirmation endpoint.
- [x] Recheck the 500-piece original plus 2-piece replacement acceptance example: one receiver entry, one POST, two distinct confirmed delivery records, resolved case only after real settlement, original-order coverage 500, replacement invoice not required, and no repeated stock dispatch.
- [x] Review only the task files against this plan and report verified behavior, test results, invoice/certificate recovery limits, and any blocked concurrency verification. Stop when these acceptance checks pass; do not redesign unrelated return, invoicing, or delivery screens.

## Planning self-review

- Grouped modal, exact lineage, per-line validation, one receiver, and one submission are covered by Tasks 1–3.
- Driver proof, physical-return obligations, another open case, original billing, free replacements, retry behavior, and rollback are covered by Tasks 1–2.
- Known lock-order inversion is addressed and explicitly tested in Task 3; this must pass before claiming concurrency safety.
- Single receipt compatibility, responsive behavior, cache refresh, and focused regression evidence are covered by Tasks 3–4.
- All newly proposed methods and TypeScript contracts are defined above. No record merge, schema change, live receipt confirmation, or extra approval workflow is required.

## Execution notes

Implemented the grouped receipt endpoints and optional mode in the existing customer modal. Added one shared exact-line validator and reused physical-return coverage validation. No schema or dependency changes were needed.

Customer case actions now lock participating orders before deliveries and the case. A replacement relationship changed during lock acquisition returns a reload error rather than acquiring locks in an unsafe order. Separate PostgreSQL connection checks cover group confirmation against both single receipt confirmation and an internal case update.

The shared checkout contains ongoing user changes; this implementation stayed on its existing feature branch and was tested in the isolated API container. No broad staging, commit, merge, or live receipt confirmation was performed.

The live read-only check found the 2-piece replacement already confirmed and the original 500-piece delivery still pending. The modal preserves that replacement confirmation and its receiver while allowing confirmation of the original receipt.

Validation completed: 20 frontend tests, production TypeScript/Vite build, and mocked desktop/mobile browser checks passed. Backend verification passed with 48 tests and 492 assertions, including both independent PostgreSQL concurrency checks. Existing Vite mixed-import warning for NotFound.tsx remains unrelated to this change.
