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

## 9. M047-R003 — transaction-aware supplier-invoice attachment cleanup

Implementation session: 2026-08-27

- Before: `SupplierPortalService::submitInvoice()` wrapped the database transaction and its post-commit event/audit calls in one cleanup catch, so an event or portal-audit failure could delete the already-committed attachment path while leaving the invoice/document rows committed.
- `api/app/Modules/B2B/Services/SupplierPortalService.php:407-536`: After, the cleanup catch wraps only `DB::transaction`; event dispatch and portal audit run after the commit boundary, so their failures rethrow without deleting the committed attachment path or document row.
- `api/tests/Feature/B2B/SupplierPortalServiceTest.php:410-547`: Added event and post-commit audit failure injection tests that assert the committed file/document survive, plus a duplicate-digest transaction failure test that asserts the bill rolls back and no provisional invoice file is orphaned.
- Verification: `docker compose exec -T -e DB_DATABASE=ogami_test_m047_impl api php -d memory_limit=768M artisan test tests/Feature/B2B/SupplierPortalServiceTest.php` — **39 passed / 167 assertions**; the three new tests — **3 passed / 10 assertions**. Both touched PHP files pass `php -l`; `git diff --check` passes. All test commands used `DB_DATABASE=ogami_test_m047_impl`.
- Release: `🔁 Needs Re-audit` because other M047 findings remain open.

---

# Re-audit session: 2026-08-27

The 2026-08-25 session applied all seven plan items to the source but never
executed one line of them — `migrate:fresh` was broken repo-wide, so no fix ever
reached a database. `migrate:fresh` works now. This session's job was execution.

Baseline on entry, `SupplierPortalServiceTest`: **4 failed / 19 passed**, exactly
the four failures the coordinator listed and for exactly the reasons the code
implied.

## 8. The four failures were all one thing: fixtures asserting the retired contract

Verdict on the scoping question: **the scope is correct; the fixtures were stale.**
Not over-filtering. Evidence:

- `api/app/Modules/B2B/Services/SupplierPortalService.php:49-55` declares
  `SUPPLIER_VISIBLE_PO_STATUSES` = approved / sent / partially_received /
  received / closed. `docs/PROCESS-FLOWS.md:571` states the policy the constant
  encodes: "`portal_available` — the **approved** PO is visible to an active
  supplier portal user". Draft and pending_approval are internal.
- `api/database/factories/PurchaseOrderFactory.php:44` defaults `status` to
  `draft`, so every fixture that let the factory choose was building a row the
  policy is *designed* to hide.
- The sibling test that already passed proves the intended convention is
  fixture-side: `test_dashboard_returns_own_data` has always written
  `->forceFill(['status' => 'approved'])->save()` explicitly.

Widening the scope would have been the wrong direction — this is a supplier-facing
external portal, and admitting draft/pending_approval rows would hand vendors
internal pre-approval orders. No production source was changed for these four.

### 8.1 `test_purchase_orders_scoped_to_own_vendor` — size 0 vs 3

- `api/tests/Feature/B2B/SupplierPortalServiceTest.php:117-133` → `:145-172`.
  Before: `PurchaseOrder::factory()->count(3)->create(['vendor_id' => …])` —
  three `draft` rows — then asserted all three were visible. After: three rows in
  explicitly portal-visible states (approved / sent / partially_received), plus a
  fourth own-vendor draft that is asserted **absent** from the response, so the
  test now pins the exclusion instead of merely surviving it.

### 8.2 `test_purchase_order_detail_succeeds_for_own_vendor` — 200 vs 404

Same root cause, as hypothesised. `SupplierPortalService::purchaseOrderDetail()`
at `api/app/Modules/B2B/Services/SupplierPortalService.php:168-170` checks vendor
ownership (403) and then portal-availability (404) — a `draft` PO belonging to
the caller's own vendor is legitimately a 404.

- `api/tests/Feature/B2B/SupplierPortalServiceTest.php:150-161` → `:191-202`.
  Before: factory-default draft PO, `assertOk()`. After: `sent` PO.

### 8.3 `test_shipment_update_succeeds` — 200 vs 422

Two stale assumptions, not an over-strict rule:

- The draft PO tripped `SUPPLIER_SHIPMENT_STATUSES` (sent / partially_received) at
  `api/app/Modules/B2B/Services/SupplierPortalService.php:58-61,234-236`. A
  supplier cannot ship against an order that was never transmitted to them.
- The test then asserted `Shipped: …`, `Maersk`, `MAEU1234567` were appended to
  `purchase_orders.remarks` — the append-only free-text behaviour that plan item 6
  (M047-F009) deliberately **replaced** with the structured `supplier_shipments`
  row. The assertions were checking for the absence of the fix.

- `api/tests/Feature/B2B/SupplierPortalServiceTest.php:330-353` → `:406-435`.
  Before: draft PO + four `assertStringContainsString` on `remarks`. After: `sent`
  PO; asserts the `SupplierShipment` current-state row (shipped_date, carrier,
  tracking_number, estimated_arrival, notes, `portal_user_id`) and one immutable
  update snapshot. `expected_delivery_date` assertion kept — still written.

### 8.4 `test_store_delivery_schedule_is_idempotent_for_same_po_and_month` — 201 vs 422

Fixture, again — the payload was genuinely invalid under the new contract:

- Sent `lines: [['product_name' => 'Relay Cover', 'quantity' => 500]]`.
  `StoreDeliveryScheduleRequest` now requires
  `lines.*.purchase_order_item_id` (`api/app/Modules/B2B/Requests/Supplier/StoreDeliveryScheduleRequest.php:41`)
  so `normalizeScheduleLines()` can reconcile each line to a real PO line and its
  remaining quantity (`api/app/Modules/B2B/Services/SupplierPortalService.php:744-771`).
  The verbatim 422 was `The lines.0.purchase_order_item_id field is required.`
  The SPA already sends the hashed item ID
  (`spa/src/pages/portal/supplier/delivery-schedules.tsx`), so the request rule
  and the only real client agree; the test was the last holder of the old shape.
- The fixture PO also had no line items at all and was `draft`.

- `api/tests/Feature/B2B/SupplierPortalServiceTest.php:567-590` → `:665-690`.
  After: `sent` PO with a real `PurchaseOrderItem`, line addressed by
  `$poItem->hash_id`.

### 8.5 New helpers + regression coverage for the previously untested P1/P2 policies

The 2026-08-25 report listed "no focused test covers draft/pending PO exclusion,
… schedule line reconciliation" as an explicit evidence gap. Closed the cheap part
of it while the fixtures were open:

- `api/tests/Feature/B2B/SupplierPortalServiceTest.php:59-95` — added
  `makePo(Vendor, string $status = 'sent')` and `makePoItem(PurchaseOrder, $qty)`.
  `makePo` exists so no future fixture silently inherits `draft` again; its
  docblock states why.
- Added six tests: non-portal `?status=draft` filter returns 0 rather than falling
  back to unfiltered (`:174-188`); own-vendor pre-approval detail is 404 not 403
  (`:204-216`); shipment update replaces current state and keeps both snapshots
  (`:437-457`); schedule rejects quantity beyond remaining (`:692-707`), a line
  belonging to a different PO of the *same* vendor (`:709-726`), and an
  `approved`-but-not-yet-`sent` PO (`:728-746`).
- `test_store_delivery_schedule_rejects_other_vendors_purchase_order` (`:640-663`)
  was passing for the wrong reason — its old payload was missing
  `purchase_order_item_id`, so it would 422 whether or not the cross-vendor check
  existed. Now sends an otherwise-complete payload and asserts
  `assertJsonValidationErrors(['purchase_order_id'])`, so it actually proves
  ownership rejection.

Result: `SupplierPortalServiceTest` **29 passed / 103 assertions**, from
19 passed / 4 failed.

## 9. Plan item 2 (M047-F003/F004) — verified, coverage added

Source was already correct on inspection; it had simply never run. Nothing to
change. The 2026-08-25 report's evidence gap ("no expiry or concurrent-attempt
test") was half-closed already and I closed the rest:

- Concurrency was **already covered** and passes:
  `api/tests/Feature/B2B/LoginThresholdTwoConnectionHarnessTest.php` drives two
  real DB connections through `B2bAuthService::login()` for both the supplier and
  customer audiences — increments cannot be lost, and a failure observes a
  successful counter reset. 4/4 green.
- Lock **expiry** had no test. `B2bAuthService::login()` clears both
  `failed_login_attempts` and `locked_until` while still holding the row lock
  (`api/app/Modules/B2B/Services/B2bAuthService.php:82-88`). Added
  `api/tests/Feature/B2B/SupplierPortalAuthTest.php:163-182` (waiting out a lock
  restores login) and `:184-205` (the first typo afterwards is strike 1, not an
  instant re-lock — the accidental-permanent-lockout case F003 described).
- Reset-token invalidation had no test.
  `PortalPasswordResetService::requestReset()` invalidates outstanding tokens
  under the row lock before inserting the new one
  (`api/app/Modules/B2B/Services/PortalPasswordResetService.php:47-54`) and
  `reset()` consumes every remaining token for the address plus deletes bearer
  tokens (`:127-133`). Added
  `api/tests/Feature/B2B/PortalPasswordResetTest.php:80-108` (the first of two
  issued links is dead, the second works) and `:110-137` (reset revokes live
  portal tokens and clears lock state). Both are supplier-typed so the
  customer-portal module is untouched; added a small `makeSupplier()` helper at
  `:23-33`.

## 10. Plan item 3 (M047-F005/F006) — verified, was entirely untested

`PortalAccessService` (188 lines) and the hardened `inviteSupplier()` had **zero**
tests. This is the tenant boundary — `supplier_portal_users.email` is globally
unique while the row holds one `vendor_id`, so a silent reassignment re-points one
supplier's credential at another supplier's purchase orders. Source verified
correct; added `api/tests/Feature/B2B/SupplierPortalAccessLifecycleTest.php`
(new file, 14 tests):

- Cross-vendor invitation refused, and the account stays with the original vendor
  (`PortalInvitationService::inviteSupplier()` at
  `api/app/Modules/B2B/Services/PortalInvitationService.php:65-67`); also proven
  case-insensitively, since emails normalise to lowercase on write and a
  case-sensitive conflict check would be bypassable by capitalisation.
- Refused at the service level too, not only over HTTP, so future callers inherit it.
- An email held by a deactivated account is refused — re-inviting is not a back
  door around the audited reactivation decision (`:69-71`).
- Same-vendor re-invitation rotates the credential AND deletes old bearer tokens (`:88-89`).
- Deactivate revokes tokens and `EnsurePortalGuard` then 401s the principal.
- Resend cannot quietly reactivate; reactivate clears lock state and forces a new password.
- Token revocation does not deactivate the account (two distinct decisions).
- List reports all four lifecycle states and each `status` filter returns only its own.
- No response leaks `password`/`temporary_password` — the temp credential travels
  by mail, not through an API response that gets logged and cached client-side.
- RBAC: `warehouse_staff` gets 403 on all five routes; a supplier portal principal
  gets 401 on the administration routes.

14 passed / 53 assertions.

## 11. Plan items 1, 4, 5, 6, 7 — verified, coverage added

All source was already applied and, on execution, all of it works. The gap was
purely that nothing proved it. Added to
`api/tests/Feature/B2B/SupplierPortalServiceTest.php`:

- **Item 1 (M047-F001/F002), resource allowlist** — `:497-531`
  asserts the supplier PO detail response carries none of
  `current_approval_step`, `approval_steps`, `approvals`, `approved_by`,
  `approved_at`, `budget_warning`, `budget_acknowledged_by/_at`, `remarks`,
  `purchase_request`, `pr_number`, `created_by`, `creator`, `dispatch*`, `vendor`
  — the fields the internal `PurchaseOrderResource` does expose — and that the
  server-published `capabilities` block is what the SPA reads. `:479-495` pins
  that draft and cancelled AP rows are not supplier invoices.
- **Item 4 (M047-F007), exact money** — `:533-568`. The regression this actually
  catches is the retired `number_format()` presentation step: it inserts a
  thousands separator, so `1234567.89` reached the client as `"1,234,567.89"` and
  any client parsing it as a decimal broke. Asserts the dashboard total and SOA
  total are the exact string `1234568.20`, contain no comma, that the five aging
  buckets re-sum to the total exactly via `Money::add`, and that each bucket
  matches `/^-?\d+\.\d{2}$/`.
- **Item 5 (M047-F008/F013), document identity** — `:783-815` uploads two files
  with the **same filename and same byte size but different content** (the exact
  case the old `(po, type, filename, size)` dedupe key silently swallowed,
  returning the superseded document) and asserts two distinct rows with two
  distinct `content_sha256`. `:817-836` asserts the resource returns the PO hash
  ID and a bounded `{id,name}` uploader rather than raw integers. `:838-853`
  asserts a pre-dispatch upload is refused **and leaves no stored file behind**.
- **Item 6 (M047-F009/F010)** — covered by §8.3/§8.4 above plus the three new
  schedule-reconciliation tests.
- **Item 7 (M047-F012), portal actor attribution** — `:1000-1026` asserts the
  `supplier_ship.update` audit row carries `actor_type = 'supplier_portal'`,
  a null `user_id`, and the real `portal_user_id` + `vendor_id` in `new_values`,
  so a reviewer can identify which supplier account made the write despite the
  system-user impersonation the FK requires.

`SupplierPortalServiceTest`: **36 passed / 157 assertions**.

## 12. New source fix — the upload cleanup window extended past COMMIT

Found while reading the upload path; not in the original plan.

`api/app/Modules/B2B/Services/SupplierPortalService.php:310-357`. The `try`
wrapped the transaction **and** the two statements after it:

```php
$document = DB::transaction(...);          // commits
$document->load([...]);                    // may throw
$this->recordPortalAudit(...);             // may throw
} catch (\Throwable $e) {
    Storage::disk('local')->delete($path); // deletes a COMMITTED row's file
```

A throw from `load()` or `recordPortalAudit()` happens after commit, so the
cleanup deleted the file belonging to a persisted `portal_shipping_documents`
row — a readable document record pointing at nothing, which is worse than the
orphan file the guard exists to prevent. Now only the transaction is wrapped;
the two post-commit statements sit outside it, with a comment stating that the
cleanup window ends at commit. Covered by the new `:838-853` (a rolled-back
upload still leaves no file).

## 13. Stale documentation corrected

`docs/PROCESS-FLOWS.md:582-590`. The paragraph still described supplier shipment
updates as "appended to the PO's shipment remarks" — the behaviour plan item 6
deliberately replaced. Left as-is it would invite a future session to "restore"
the append. Now describes the `supplier_shipments` current-state row plus
`supplier_shipment_updates` snapshots, and names the two accepted PO states. This
is the one file I touched outside the module tree; it documents this module's
behaviour and nothing else changed in it.

## 14. Flagged, NOT changed — a cross-module disagreement about supplier payments

`Tests\Feature\Accounting\AccountsPayableHardeningTest > supplier bill resource…`
fails, and it is **not** caused by this session (my only source change is §12,
in the upload path). It is pre-existing: both the test and the resource arrived
together in the sweep commit `167de85e`.

```
FAILED  Tests\Feature\Accounting\AccountsPayableHardeningTest > supplier…
Failed asserting that an array does not have the key 'payments'.
at tests/Feature/Accounting/AccountsPayableHardeningTest.php:229
```

Two separate things are tangled here:

1. **A test-method artifact.** `api/app/Modules/Accounting/Resources/SupplierBillResource.php:40`
   uses `whenLoaded('payments', …)`. Verified directly in the container: on an
   unloaded bill, `toArray()` returns the key holding an
   `Illuminate\Http\Resources\MissingValue`, while `resolve()` strips it —
   `purchase_order`, `vendor` and `items` behave identically. The assertion at
   line 229 uses `toArray()`; lines 232-233 of the same test already use
   `resolve()` for the items check. So the one-line correction is to assert
   against `resolve()`.
2. **A real boundary question underneath it**, which is why I did not just make
   the assertion pass. `SupplierPortalService::invoiceDetail()` at
   `api/app/Modules/B2B/Services/SupplierPortalService.php:586` **does**
   eager-load `payments`, so on the live supplier invoice-detail response the key
   is populated. My reading is that this is correct — the supplier already sees
   `amount_paid` and `balance` uncontested, and the allowlisted payment fields are
   date / amount / method / reference / status with no journal entry, GL account,
   or internal approver — i.e. a statement of account, which this module ships
   deliberately. But it is a supplier-visibility decision recorded in the
   accounts-payable module's test, on a file outside this module's scope, so it is
   the AP owner's call, not mine.

Both files (`api/tests/Feature/Accounting/AccountsPayableHardeningTest.php`,
`api/app/Modules/Accounting/Resources/SupplierBillResource.php`) are outside this
module and were left untouched.

## Verification (this session)

All runs on an isolated `ogami_test_sp` database, `memory_limit = 512M`. Full
suite deliberately NOT run — host has ~1.1 GiB free and was OOM-killed earlier.

Passed:

- `SupplierPortalServiceTest` — **36 passed / 157 assertions** (was 4 failed / 19 passed).
- `SupplierPortalAccessLifecycleTest` — **14 passed / 53 assertions** (new file).
- Whole-module run: `SupplierPortalServiceTest|SupplierPortalAuthTest|SupplierPortalAccessLifecycleTest|PortalPasswordResetTest|PortalTokenCrossGuardTest|SupplierPpapViewTest|PortalValidationTest|LoginThresholdTwoConnectionHarnessTest`
  — **83 passed / 358 assertions / 0 failed**.
- `tests/Feature/B2B` (whole folder) — **111 passed, 2 failed**; both failures are
  `CustomerPortalServiceTest` (delivery-proof view 500, 8D report 404), which belong
  to the separate `commercial/customer-portal` module and were not touched.
- `--filter=PurchaseOrder` — 30 passed / 97 assertions (no regression in the
  dependency module whose lifecycle policy this module now enforces).
- SPA `tsc --noEmit` — **2 errors, both in `src/pages/assets/detail.tsx`**
  (missing `qrcode` module, implicit `dataUrl`), zero in any portal/B2B file. The
  third error the previous session reported (duplicate JSX attributes in
  `return-management/detail.tsx`) has since been fixed by another session.
- SPA `eslint` on `src/pages/portal/supplier`, `src/api/b2b/supplier.ts`,
  `src/api/b2b/portal-access.ts`, `src/pages/accounting/portal-access.tsx`,
  `src/types/b2b.ts` — clean.
- `node scripts/check-token-discipline.mjs` — clean, 785 files.

Known-failing, out of scope, itemised:

- `AccountsPayableHardeningTest > supplier bill resource…` — see §14. Pre-existing,
  another module's file, needs an AP owner decision on supplier payment visibility.
- `CustomerPortalServiceTest` ×2 — the separate `commercial/customer-portal` module.
- `PortalInvitationService::inviteCustomer()` at
  `api/app/Modules/B2B/Services/PortalInvitationService.php:26-40` still uses the
  old `withTrashed()->firstOrNew()` + `forceFill('customer_id')` pattern, so a
  customer-portal email **can** still be silently reassigned between customers —
  the exact defect M047-F005 fixed on the supplier side (`:65-71`). Same class,
  same file, different tenant. Left alone because `customer-portal` is a separate
  claimed module; raised here so its owner inherits the finding rather than
  rediscovering it.

## Historical release status (2026-08-27)

All seven plan items are now applied **and executed**: the four known failures are
fixed at the fixture layer (the scope was right; widening it would have leaked
pre-approval purchase orders to suppliers), one new post-commit cleanup defect was
fixed in source, stale documentation was corrected, and the previously untested
P1 boundaries — cross-vendor invitation, credential lifecycle, resource allowlist,
lock expiry, reset-token invalidation, exact money, content-addressed documents,
schedule reconciliation, portal actor attribution — now have 33 new tests.

The prior execution session released as `🔁 Needs Re-audit` for one reason only:
§14 needed an accounts-payable owner decision on whether a supplier may see the
payment records applied to their own invoice. Nothing in the supplier implementation
was deferred at that historical point, and no supplier-portal test failed.

---

## Recovery handoff (2026-08-27)

This section supersedes the historical release status above. The replacement agent
reclaimed the stale M047 lock through the required claim script, confirmed the
current report/action-plan findings, and made no production-code changes. The
current status is `📋 Plan Ready`, matching `audit-report.md`, `action-plan.md`, and
`status.md`; current classifications, sizes, session recommendations, and
file:line evidence remain in those artifacts.

Recovery verification passed:

- Focused supplier portal suite: 78 tests / 329 assertions on the unique
  `ogami_test_m047_recovery_20260827` database.
- Two-connection lockout harness: 4 tests / 24 assertions on the same database.
- Reviewed supplier B2B PHP syntax, 25 supplier routes, SPA typecheck, and token
  discipline (786 files) all passed.
- No M047 source/test, dependency, shared-config, registry, or other-module file
  was changed. The next work is the separate implementation tranche recorded in
  `action-plan.md`.

## M047-R005 — supplier public auth feature gate

Implementation session: 2026-08-27

- Claimed `supply-chain / supplier-portal` through `audit/scripts/claim-module.sh`.
- `api/app/Modules/B2B/routes.php`: applied `feature:b2b_portals` alongside the
  existing `throttle:auth` middleware on supplier login, logout, forgot-password,
  and reset-password routes. No supplier auth contract or throttle behavior was
  changed.
- `api/tests/Feature/B2B/SupplierPortalAuthTest.php`: added focused disabled and
  enabled-feature coverage for all four supplier public entry points. Existing
  login authentication and throttle tests remain in the same suite.
- Verification so far: the focused supplier auth plus password-reset suites pass
  (**20 tests / 103 assertions**); both touched PHP files pass `php -l`, and
  `git diff --check` passes. Pint still reports unrelated pre-existing style
  differences elsewhere in these two files.

## M047-R007 — supplier invoice status filter aligned

Implementation session: 2026-08-27

- Claimed `supply-chain / supplier-portal` through `audit/scripts/claim-module.sh`.
- `spa/src/pages/portal/supplier/invoices/index.tsx`: removed the internal AP
  `draft` and `cancelled` filter options while preserving the empty `All`
  option and the supplier-visible API statuses `unpaid`, `partial`, and `paid`.
- `spa/src/pages/portal/supplier/invoices/index.test.tsx`: added a focused UI
  contract assertion that checks the rendered Status combobox values/labels and
  confirms Draft and Cancelled are unavailable.
- Verification: focused Vitest passed (**1 test**); targeted ESLint passed for the
  changed page and regression test. The filter now exposes only the supplier-visible
  statuses and keeps the `All` option.

---

# Re-audit + fix session: 2026-08-30

Claimed `supply-chain / supplier-portal` via `audit/scripts/claim-module.sh`
(result: CLAIMED — the lock this session took was not an orphan reclaim).
Baseline on entry, working tree clean, focused supplier suite
**83 passed / 353 assertions**.

Verified first that the two commits since the last audit landed what they
claimed: `2e260491` did add `feature:b2b_portals` to all four supplier public
auth routes (`api/app/Modules/B2B/routes.php:22-25`), closing M047-R005, and
`7e47f752` did remove the `draft`/`cancelled` options from the supplier invoice
filter, closing M047-R007. M047-R003 is also genuinely fixed — the cleanup
`catch` in `submitInvoice` now wraps only `DB::transaction`
(`api/app/Modules/B2B/Services/SupplierPortalService.php:520-528`) with the
event dispatch and portal audit outside it at `:530-533`.

## 15. Cross-tenant isolation drill — measured, not reasoned about

New file `api/tests/Feature/B2B/SupplierPortalCrossTenantTest.php` (19 tests).
Two suppliers are each given a vendor, portal user, sent PO with a line, bill,
accepted GRN, shipping document with real stored bytes, delivery schedule, and a
PPAP submission with an element; Supplier A is then pointed at every one of
Supplier B's identifiers on every route that takes one.

**Result: no cross-tenant leak found on any probed route.** Both defences hold —
`B2BTenancyScopeMiddleware`'s vendor global scope refuses the route binding
(404), and each service method re-checks `vendor_id` after binding (403). The
drill additionally asserts that a refusal body never echoes the victim's
`po_number`/`bill_number` nor a raw integer `"id":<pk>`, so the refusal is not an
existence oracle.

Also measured and passing: uploads get randomised names under
`portal/shipping-docs/<po>/` on the `local` disk (outside the web root), a
`../../evil.pdf` client filename never becomes a storage path, and traversal or
garbage values in `shipping-documents/{id}/download` are refused.

Two notes on making these honest rather than merely green:

- The MIME assertion deliberately does **not** use `UploadedFile::fake()`.
  `Illuminate\Http\Testing\File::getMimeType()` returns
  `MimeType::from($name)` — derived from the *filename* — so a fake named
  `payload.pdf` reports `application/pdf` whatever its bytes are. Written that
  way the test reported a MIME-validation bypass that does not exist. Rewritten
  against a real `Illuminate\Http\UploadedFile`, which inherits Symfony's
  finfo-sniffing `getMimeType()` (the production path), the PHP payload is
  correctly rejected 422. **Server-side MIME validation is real.**
- `assertStringNotContainsString('a/b', $response->getContent())` is unsound:
  `json_encode` escapes `/` as `\/`, so the check passes even when the path is
  present. The PPAP path assertion compares against
  `json_encode($response->json(), JSON_UNESCAPED_SLASHES)` instead.

`api/tests/Feature/B2B/SupplierPortalCrossTenantTest.php` also settles the
CLAUDE.md audit-guard hazard empirically: a portal acknowledgement (which writes
`PurchaseOrder`, a `HasAuditLog` model, under `auth:supplier_portal`) both
succeeds and writes its rows — the `supplier_po.ack` portal row with
`user_id = null` / `actor_type = supplier_portal`, and a `HasAuditLog` row whose
`user_id` resolves to a real `users` record. No FK violation. The remedy is
`App\Common\Services\SystemUserResolver::impersonate()`, **not** the
`EdgeSystemUserResolver` CLAUDE.md names, which does not exist.

## 16. NEW — PO detail dropped every GRN and bill (three defects, each hiding the next)

Found while building the R001 regression fixture: the test failed with **0**
bills rather than the 3 the leak predicted. Probing the eager load directly
(temporary probe, since deleted) produced the decisive evidence:

```
[PROBE] DB rows                                     -> bills=1 grns=1
[PROBE] load([bills, goodsReceiptNotes])            -> bills=1 grns=1
[PROBE] load([...:id,<cols> without the FK])        -> bills=0 grns=0
[PROBE] load([...:id,purchase_order_id,<cols>])     -> bills=1 grns=1
[PROBE] HTTP GET po detail                          -> bills=0 goods_receipt_notes=0
```

Three defects were stacked in `purchaseOrderDetail()`, and each one concealed
the one below it:

1. **Broken, P1 — the relations always resolved empty.**
   `api/app/Modules/B2B/Services/SupplierPortalService.php:176-177` (before) read
   `'goodsReceiptNotes:id,grn_number,received_date,status'` and
   `'bills:id,bill_number,total_amount,amount_paid,balance,status,due_date'`.
   `HasMany::match()` keys children by the foreign key, so omitting
   `purchase_order_id` from the select made every row unmatchable and discarded.
   `SupplierPurchaseOrderResource` uses `whenLoaded`, so the relation *was*
   loaded and the response shipped `bills: []` / `goods_receipt_notes: []`
   unconditionally. The SPA renders both panels only when the array is non-empty
   (`spa/src/pages/portal/supplier/purchase-orders/detail.tsx:417,441`), so two
   sections of the supplier PO detail page had **never once displayed**.
   After: both are constrained closures that select the FK and `orderBy('id')`.

2. **Broken, P1 — a fatal `Error` behind the empty collection.**
   `:200-205` (before) ran `$purchaseOrder->bills->each(...)` doing
   `BillStatus::tryFrom((string) $bill->status)`. `Bill::$casts` maps `status` to
   `BillStatus` (`api/app/Modules/Accounting/Models/Bill.php:45`), so casting the
   enum to string is `Error: Object of class ...BillStatus could not be converted
   to string`. Unreachable only because defect 1 guaranteed an empty collection —
   repairing 1 alone turned PO detail into a **500 for every PO that has a bill**,
   which is exactly what the first fix run produced. Verbatim, at
   `SupplierPortalService.php:203`. Deleted rather than repaired: the loop's
   `status_label` never reached the response anyway, because
   `SupplierPurchaseOrderResource:69` builds an explicit array and derives
   `status_label` from the enum itself.

3. **M047-R001, Broken, P1 — the missing supplier-visible bill predicate.**
   Real but latent: the leak the prior audit reported could not actually fire
   while defect 1 held. Repairing 1 without it would have activated it. Now the
   `bills` closure applies `whereIn('status', $this->supplierVisibleBillStatusValues())`,
   so PO detail and `/invoices` read the one allowlist.

Regression: `api/tests/Feature/B2B/SupplierPortalServiceTest.php` —
`test_purchase_order_detail_returns_its_grn_and_bill_relations` pins all three
(GRN present, 200 not 500, `status_label` correct, draft/cancelled absent).
**Confirmed failing against unmodified source**: first as
`Failed asserting that actual size 0 matches expected size 1` (defect 1), then
after fixing 1 as `Expected response status code [200] but received 500`
(defect 2).

## 17. M047-R006 — supplier-safe PPAP contract (partial: the storage-path leak)

`GET /b2b/supplier/ppap-submissions` returned `Quality\Resources\PpapSubmissionResource`
directly, whose element resource emits `document_path` — the raw private storage
path of the uploaded PPAP evidence (`api/app/Modules/Quality/Resources/PpapElementResource.php:20`).
Measured before the fix: the path `ppap/private/vault/control-plan-secret.pdf`
was present in the supplier response.

- New `api/app/Modules/B2B/Resources/SupplierPpapSubmissionResource.php` and
  `SupplierPpapElementResource.php`: B2B-owned allowlists over the same models.
  `document_path` is replaced with a boolean `has_document` (there is no supplier
  PPAP download route, so the path had no client use); the internal `submitter`
  and `approver` identities are dropped. `rejection_reason`, `reviewed_at`,
  `approved_at`, `expires_at` and `revision` are kept — the supplier is the party
  that must act on a rejection of its own submission.
- `api/app/Modules/B2B/Controllers/SupplierPortalController.php:340` now returns
  the B2B resource. **Quality's resources were not modified** — they are a
  dependency of this module.
- Regression: `api/tests/Feature/B2B/SupplierPpapViewTest.php` —
  `test_supplier_ppap_response_never_exposes_the_private_document_path`.
  **Confirmed failing against unmodified source**:
  `Failed asserting that an array does not have the key 'document_path'`.
- Still open for the Quality owner: whether the wider field allowlist above is
  the intended supplier PPAP contract, and whether suppliers should get a
  download route at all. Recorded as a question, not guessed at.

## 18. Deferred, with measured evidence — M047-R002 supplier password expiry

Not fixed. Measured with a temporary probe (since deleted), which settles it:

```
[PROBE] security.password_expiry_days = 90
[PROBE] SUPPLIER dashboard, password 150 days old  => HTTP 200
[PROBE] SUPPLIER purchase-orders, same            => HTTP 200
[PROBE] CUSTOMER dashboard, password 150 days old => HTTP 403  code=password_expired
```

One policy, two guards, only one enforcing it. `CheckPortalPasswordExpiry` reads
`customer_portal`/`CustomerPortalUser` only
(`api/app/Modules/B2B/Middleware/CheckPortalPasswordExpiry.php:19-20`) and the
supplier route group omits it (`api/app/Modules/B2B/routes.php:28`).

Deferred deliberately: it changes an authentication gate on an externally facing
portal, and the supplier SPA has no handler for the `password_expired` code, so
turning the gate on without the client work would hard-brick an expired supplier
with no route to change their password. `separate-recommended`.

---

### RESOLVED 2026-09-04 — supplier payment visibility

Decision made by the project owner: **payments stay supplier-visible as a
statement of account.** `SupplierBillResource` keeps its allowlisted `payments`
mapping (date / amount / method / reference / status — no journal, GL, or
internal approver fields). The `AccountsPayableHardeningTest` assertion was
rewritten to assert on the real serialized payload (`resolve()`, relations
loaded — `toArray()` on an unloaded `whenLoaded()` relation yields a
MissingValue key, a serializer artifact) and to pin the allowlist: internal
fields absent at the top level and inside each payment, statement fields
present. Internal AP controls (`exception_evidence`,
`three_way_override_reason`, `expense_account`) remain hidden.

---

## 2026-09-11 — acknowledgment semantics + capability gates

Source: `audit/domains/procurement/purchase-orders/lifecycle-audit-2026-09-11.md`.

- `SupplierPortalService.php:50-88,162-191,237-263,300-320`: Before, a supplier
  saw `approved` POs and "acknowledging" one called `markAsSent`, recording the
  supplier's acceptance as OGAMI's transmission; the supplier could also accept
  a PO that had never been sent, its ETA overwrote `expected_delivery_date`
  (the scorecard's target), and its note replaced internal PO remarks. After,
  the supplier-visible set starts at `sent`; `acknowledgePo()` delegates to
  `PurchaseOrderService::acknowledgeBySupplier()` (`Sent → Acknowledged`),
  writes `confirmed_delivery_date`, and appends the note; `updateShipment()`
  writes `confirmed_delivery_date`, leaving `expected_delivery_date` untouched;
  the portal dashboard counts reuse one pending-delivery status set.
- `SupplierPurchaseOrderResource.php:33-52`: Before, `can_submit_invoice` was
  true for `sent|partially_received|received` even though `submitInvoice()`
  requires an accepted GRN — the supplier saw an enabled button and got a 422.
  After, the capability requires an accepted receipt and a new
  `can_schedule_delivery` flag is exposed; `can_acknowledge` requires `sent`.
- `api/tests/Feature/B2B/SupplierPortalServiceTest.php`,
  `SupplierPortalCrossTenantTest.php`: updated for the `sent`/`acknowledged`
  model; added tests pinning the unsent-PO refusal, the `acknowledged` result
  with `confirmed_delivery_date`, remark preservation, and the accepted-GRN
  invoice gate.

Verification: B2B `--filter=SupplierPortal` `89 passed (433 assertions)`;
integrated Purchasing+B2B `372 passed`.

