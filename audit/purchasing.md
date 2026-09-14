# Audit: purchasing — 2026-09-06

## Summary

The purchasing core is unusually hardened: every PR/PO lifecycle transition (submit/update/approve/reject/cancel/convert/close/send/delete) uses lock-then-guard with `lockForUpdate()`, PR→PO conversion is idempotent under a source-PR row lock, the ₱50k VP gate is genuinely enforced via the workflow step `threshold` in `ApprovalService::submit()`, self-approval and vendor-creator SoD are blocked, and the whole unit's test filter passes (160 tests / 0 fail, ~140s). The serious gap is row-level authorization on the **PO detail surface**: `PurchaseOrderController::show`/`pdf` (and every PO mutation) never consult `PurchaseOrderAccessPolicy` — the policy its own docblock calls "the single source of truth" is applied to the list, global search and approval board only, so any `purchasing.view` holder can read any PO by hash ID, including bills. The PR side applies its policy correctly on every endpoint; the asymmetry looks like an oversight. Other findings: the dept-head auto-approval of small PRs is dead code (reads an attribute that exists nowhere), the supplier scorecard's "price variance" metric actually measures delivery shortfall, a quantity scale mismatch at the GRN→PO-line boundary, and several races in the newer supplier-listing/preferred-supplier code. The MRP rerun vs PR-submit race (MRP-03 in the MRP audit) was verified from this side: purchasing is lock-safe; the unsafe writes are MRP's.

## Findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| PU-01 | Risk | Critical | S | PO `show`/`pdf` bypass PurchaseOrderAccessPolicy — any `purchasing.view` holder reads any PO | api/app/Modules/Purchasing/Controllers/PurchaseOrderController.php:52-55,141-144 | `index` applies `visibleTo()` (Service:100-102); `show`/`pdf` return the resource with no check. PR controller guards both with `canView` (PurchaseRequestController.php:43,73). No test covers show-row-scope |
| PU-02 | Risk | High | M | PO mutation endpoints have no row/action ownership — any permission holder acts on anyone's PO | api/app/Modules/Purchasing/Controllers/PurchaseOrderController.php:67-139; Services/PurchaseOrderService.php:290-683 | update/destroy/restore/send/cancel/close/acknowledgeBudget check only status + route permission; PR side has `canManageDraft`/`canCancel` equivalents, PO side has none |
| PU-03 | Broken process | High | M | Dept-head auto-approval of PRs < ₱5,000 never fires (dead attribute) and would self-approval-trap if revived | api/app/Modules/Purchasing/Services/PurchaseRequestService.php:313-339 | `$requester->employee->is_department_head` exists on no migration/model (repo-wide grep: 1 hit — this line). Latent: loop calls `approvals->approve($fresh, $requester)` → ApprovalService self-submission guard throws ForbiddenActionException → submit() rolls back → 403 |
| PU-04 | Broken process | Medium | M | `price_variance_pct` snapshot metric computes delivery shortfall, not price variance | api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:29-30,407-429 | Implementation: `(ordered_qty − received_qty)/ordered_qty`; never reads unit prices. Feeds 15% of `overall_score` and the ranking/tier a supplier is graded by |
| PU-05 | Risk | Medium | S | Quantity scale mismatch: PO line `quantity_received` decimal(12,2) vs GRN decimal(15,3) | api/database/migrations/0061_create_purchase_order_items_table.php:23; api/app/Modules/Inventory/Services/GrnService.php:181-245 | GRN accepts 3dp quantities (StoreGrnRequest `decimal:0,3`), bcadd at scale 3, writes into a 2dp column → PO-line running total drifts from GRN sum; feeds over-receipt `remaining` and three-way qty gate |
| PU-06 | Gap | Medium | S | PR create accepts any department_id — budget charged and step-1 approval routed to a department the requester doesn't belong to | api/app/Modules/Purchasing/Services/PurchaseRequestService.php:100-116; Requests/StorePurchaseRequestRequest.php:39 | `canAssignDepartment` enforced in `update()` only; `create()` takes `$data['department_id']` verbatim, `submit()` assesses budget against it |
| PU-07 | Risk | Medium | M | Supplier listing review flow races: duplicate pending submissions; unguarded double-approve | api/app/Modules/Purchasing/Services/SupplierListingService.php:80-88,126-149; api/database/migrations/0480_create_supplier_item_listings_table.php | `lockForUpdate()->exists()` locks no row when none exists and there is no unique index → concurrent submits both pass; approve/reject check status on the route-bound model, no re-check under lock; concurrent approve races `firstOrNew` into the `(item_id,vendor_id)` unique index → 500 |
| PU-08 | Risk | Medium | S | No invariant "one preferred supplier per item" — concurrent `setPreferred` can leave two | api/app/Modules/Purchasing/Services/ApprovedSupplierService.php:77-85; migration 0062 | Clear-then-set without lock; no partial unique index. Two preferred rows → AutoPurchaseOrderService aborts (`count() !== 1`) and PR prefill picks arbitrarily (`->first()`) |
| PU-09 | Bad practice | Medium | S | Raw integer PO/item IDs in three-way-match API response and bill snapshots | api/app/Modules/Purchasing/Support/ThreeWayMatchResult.php:11,30; Services/ThreeWayMatchService.php:126,161; Controllers/ThreeWayMatchController.php:15-21 | `po_id`/`item_id` returned as raw ints; violates the HashID rule; snapshot persisted into `bills.three_way_match_snapshot` (Accounting) carries the same raw IDs |
| PU-10 | Bad practice | Low | S | Auto-PO hardcodes `requires_vp_approval=true` regardless of amount; `created_by=null` | api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:111-112 | Flag is display/filter-only (real gate is the workflow threshold) but mislabels sub-₱50k auto-POs; null creator means the self-approval guard cannot fire for auto-POs (documented bypass, worth restating) |
| PU-11 | Risk | Low | S | PO approve gates evaluated on stale route-bound model outside the row lock | api/app/Modules/Purchasing/Services/PurchaseOrderService.php:385-424 | SoD/PPAP/`assertAcknowledged`/budget `enforce()` run before the transaction; only status is re-checked under lock. Vendor immutability makes this mostly inert |
| PU-12 | Bad practice | Low | S | Dead SPA surfaces: pr-templates pages + API client, backend routes commented out 2026-08-08 | spa/src/pages/purchasing/pr-templates/**; spa/src/api/purchasing/purchase-requests.ts:44-56; api/app/Modules/Purchasing/routes.php:41-55 | Routes unmounted in purchasingRoutes.tsx; pages and client unreachable but maintained (incl. a test) |
| PU-13 | Gap | Low | S | PO detail shows Approve/Reject to every `purchasing.po.approve` holder regardless of current step | spa/src/pages/purchasing/purchase-orders/detail.tsx:128-133 | Backend refuses non-current-step roles with 403; PR detail uses server-computed `actions.can_approve` — PO resource has no `actions` block |
| PU-14 | Risk | Low | M | Zero unit_price allowed on PO lines narrows the three-way price gate | api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php (unit_price `min:0`); Services/ThreeWayMatchService.php:89-92 | `poPrice=0`/`grnCost=0` make both variance terms 0 by guard, so any bill price passes the price check on that line (qty gates still apply) |

### PU-01 — PO detail/PDF leak past the row scope (Critical, Risk)

`PurchaseOrderAccessPolicy` exists precisely because three surfaces drifted; its docblock names list, global search and approval board as the consumers — and those three do call it (`PurchaseOrderService::list`, `GlobalSearchService`, `ApprovalSourceScope`). But `PurchaseOrderController::show()` and `pdf()` return the full resource with no check at all, and the resource eagerly exposes vendor contact details, line prices, totals, GRNs, **bills with balances**, budget warnings and the approval chain. Any authenticated user holding the baseline `purchasing.view` permission (granted broadly so employees can see their own POs) can fetch `/api/v1/purchasing/purchase-orders/{hashId}` for arbitrary POs. HashIDs are guess-resistant but not secret — IDs leak through notifications (`link_to`), chain pages, global-search metadata and URLs. The PR controller proves the intended pattern: `show`/`printPdf` both `abort_unless($this->access->canView(...))`. Fix is one line per endpoint (add a `canView`-equivalent to the PO policy and call it in `show`/`pdf`); the same call should also gate the mutation endpoints (PU-02). No test covers this, which is how it survived the policy extraction.

### PU-02 — PO mutations are permission-gated but not row-gated (High, Risk)

The PR side scopes every action (`canManageDraft` requires requester-or-global; `canCancel` likewise). The PO side has no analogue: `update`/`delete` check only `status === Draft`, `send` only `status === Approved`, `cancel`/`close` only status, and none of them check who the actor is relative to `created_by`. Consequences under normal use: any user with `purchasing.po.create` can edit, delete or cancel **another buyer's** draft PO, submit it, or close someone else's received PO; any `purchasing.po.send` holder can mark any approved PO sent (which stages the GRN trigger and supplier dispatch). Approval steps themselves are protected (step-role + self-submission in ApprovalService), so this is not a money-signing hole — it is an integrity/availability hole on the documents themselves, plus the restore endpoint (`purchasing.po.manage`) which can resurrect any trashed draft. Fix: mirror the PR policy with a PO action layer (`canManageDraft` = creator-or-`po.approve`-holder; `canCancel`/`canSend` similarly), enforced inside the locked transaction like the PR service does.

### PU-03 — Dept-head auto-approval: dead today, a 403 trap tomorrow (High, Broken process)

`submit()` promises: "Auto-approve small PRs (< ₱5,000) when requestor is a dept head or above." Two independent defects make this inert:
1. **Dead read.** The gate is `$requester->employee->is_department_head`. There is no `is_department_head` column, cast, accessor or migration anywhere in the repo (single grep hit is this line). With `preventAccessingMissingAttributes()` off, the read returns null → `$isDeptHead` is always falsy → every small dept-head PR silently enters the full 4-step chain. The feature has never worked; the seeded setting `approval.pr.dept_head_auto_approve_threshold` (5000) and the admin-editable validation for it advertise a behaviour the system does not have.
2. **Latent trap.** If someone "fixes" (1) by adding the attribute, the while-loop calls `$this->approvals->approve($fresh, $requester, ...)` where `$requester` is the PR's own submitter. `ApprovalService::approve()` resolves the submitter via `requested_by` and throws `ForbiddenActionException` ("You cannot act on a record you submitted.") — which is **not** a `BusinessRuleException`, so the controller's catch doesn't map it, the whole `submit()` transaction rolls back, and the dept head gets a 403 and no PR at all. The correct shape is direct record updates (`action=approved`, system remarks) or a dedicated `approveAsSystem` path, plus a test.

## Cross-module flags

- **mrp** — MRP-03 confirmed from the purchasing side. Purchasing is race-safe: `submit/update/delete/cancel` all `lockForUpdate()` + re-check status, and `convertFromPr` locks the source PR. The unsafe writes are MRP's reuse path: `MrpEngineService.php:327-353` reads draft auto-PRs **without** locks, then unconditionally `forceFill(status=draft)->save()` and `$pr->items()->delete()` on the reused row — a PR purchasing concurrently submitted flips back to draft with its lines replaced while its approval records stay `is_current`, so the chain keeps approving an amount the lines no longer produce. (The bulk-cancel arm at :374-383 is safe — its `WHERE status='draft'` re-evaluates per row.) Fix belongs in MRP: lock the row and make the rewrite conditional on still-draft.
- **inventory** — PU-05: `GrnService` writes 3dp received quantities into `purchase_order_items.quantity_received` decimal(12,2) (migration 0061). Either widen the PO column to (12,3) to match `quantity_accepted` (already 12,3) and GRN items, or round at the boundary. Purchasing owns the table, Inventory owns the write — joint fix.
- **accounting** — (a) PU-09 persistence side: `BillService` stores `ThreeWayMatchResult::toArray()` — raw `po_id`/`item_id` — into `bills.three_way_match_snapshot`; sanitize at the source when fixing PU-09. (b) `ThreeWayMatchController::show` is gated only by `accounting.bills.view` with no bill row scope — whether bills are row-scoped at all is Accounting's call; if they are, this endpoint must inherit it. (c) `BudgetEnforcementService::enforce()` for PO approval runs outside the approval transaction (TOCTOU window is small but noted). Whether PO cancellation releases committed budget was not verified here (BudgetConsumptionService is accounting-owned).
- **b2b** — Supplier-listing submit/update input validation for portal calls lives in the B2B controller; only the purchasing-side service was reviewed (see PU-07 for the service-side races).

## What was NOT checked

- PDF services (`PurchaseOrderPdfService`, `PurchaseRequestPdfService`) internals; `PurchaseRequestTemplateService` (routes hidden 2026-08-08).
- `SupplierDispatchService` recover/retry internals, `SupplierOrderDispatch` model, dispatch gateway implementation (B2B provider side).
- Outbox/`ChainBroadcaster` plumbing; listener queue semantics beyond reading the classes.
- Accounting `BillService` internals (three-way call sites only), `BudgetConsumptionService` commitment accounting.
- B2B supplier portal surfaces consuming POs/listings.
- SPA behaviour beyond static inspection (5-state presence, HashID/string-decimal types, route guards verified; no browser run).
- RolePermissionSeeder grant matrix for purchasing permissions.

**Verification:** `docker compose exec -T api php artisan test --filter='Purchasing'` → 160 passed (563 assertions), 0 fail, ~140s. None of the Critical/High findings above is covered by an existing test.

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Status recheck

The prior findings were re-read against the current commit. PU-01 is resolved: `PurchaseOrderController::show()` and `pdf()` now call `PurchaseOrderAccessPolicy::canView()` (`api/app/Modules/Purchasing/Controllers/PurchaseOrderController.php:54-58,147-151`), and `PurchaseOrderShowScopeTest` covers both endpoints. PU-03 is resolved: the dead department-head auto-approval and urgent-step branches were removed (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:289-301`). PU-06 is resolved: create now validates a non-central creator's department (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:100-125`). PU-08 is materially resolved at the database-invariant level by the partial unique preferred-supplier index (`api/database/migrations/2026_09_11_000002_qualify_approved_suppliers.php:43-60`), although the application still needs graceful handling of a concurrent loser. PU-10's threshold label is fixed (`api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:103-122`). PU-13 is resolved on the normal detail response path by server-computed `actions` (`api/app/Modules/Purchasing/Resources/PurchaseOrderResource.php:126-131` and `api/app/Modules/Purchasing/Policies/PurchaseOrderAccessPolicy.php:205-237`). PU-14 is resolved for PO create/update input by `min:0.01` (`api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php:60-64`, `UpdatePurchaseOrderRequest.php:47-49`).

The following findings remain unresolved or have changed materially.

### PU-02R — Broken process — High — PO submit still bypasses row ownership

- **Effort:** S
- **Location:** `api/app/Modules/Purchasing/Controllers/PurchaseOrderController.php:97-102`; `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:499-515`
- **Evidence/reproduction:** `submit()` accepts only the route-bound PO and never receives the authenticated user. It locks and submits any draft after the route's broad `purchasing.po.create` permission check; it never calls `PurchaseOrderAccessPolicy::canManageDraft()`. Buyer A creates a draft, then buyer B with `purchasing.po.create` can `PATCH /purchase-orders/{A}/submit`, creating approval records for A's document. `PurchaseOrderMutationOwnershipTest.php:88-173` covers update/delete/cancel/send/close ownership, but has no foreign-draft submit case.
- **Risk:** The PO ownership fix is incomplete: a buyer can force another buyer's draft into an approval workflow and alter its operational state even though the detail action map says `can_submit` only for the owner (`api/app/Modules/Purchasing/Policies/PurchaseOrderAccessPolicy.php:205-230`).

### PU-04 — Broken process — Medium — Supplier price variance is still receipt shortfall

- **Effort:** M
- **Location:** `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:407-429`
- **Evidence/reproduction:** The method documented and exposed as `price_variance_pct` aggregates `SUM(poi.quantity)` and `SUM(poi.quantity_received)`, then computes `(ordered - received) / ordered`. It never reads `unit_price`, receipt `unit_cost`, or bill price. The result feeds the price component of the overall score at `SupplierPerformanceService.php:500-517` and is displayed as “Price variance” at `spa/src/pages/purchasing/suppliers/performance.tsx:182-185`. A supplier with exactly the PO price but an incomplete receipt is penalized as a price offender; a supplier with a large price change and complete receipt scores 0% price variance. `SupplierScorecardInvariantsTest.php:116-136` locks the shortfall behavior rather than the stated price metric.
- **Risk:** Supplier ranking and A/B/C/D tier decisions remain materially misclassified, affecting sourcing decisions and the purchasing dashboard.

### PU-05 — Risk — Medium — GRN three-decimal receipts still collapse into two-decimal PO totals

- **Effort:** S
- **Location:** `api/database/migrations/0061_create_purchase_order_items_table.php:19-23`; `api/app/Modules/Inventory/Services/GrnService.php:190-238`; `api/app/Modules/Purchasing/Models/PurchaseOrderItem.php:23-28`
- **Evidence/reproduction:** GRN validation and `bcadd(..., 3)` accept and accumulate three-decimal base quantities, but `purchase_order_items.quantity_received` remains `decimal(12,2)` and the model casts it as `decimal:2`. Receiving `1.999` writes a two-decimal running total while the remaining-quantity guard was evaluated at scale 3. The PO physical total, over-receipt gate, and downstream quantity comparisons can therefore disagree with the GRN line sum.
- **Risk:** Partial receipts and three-way quantity gates can drift by up to a centesimal unit per receipt; repeated receipts can prematurely exhaust or leave a PO line apparently open.

### PU-07 — Risk — Medium — Supplier-listing review and submission races remain unclosed

- **Effort:** M
- **Location:** `api/app/Modules/Purchasing/Services/SupplierListingService.php:75-101,107-119,127-166`; `api/database/migrations/0480_create_supplier_item_listings_table.php:31-34`
- **Evidence/reproduction:** The pending duplicate check uses `lockForUpdate()->exists()` but the query locks no row when none exists, and migration 0480 has only non-unique status indexes. Two concurrent submissions for one vendor/item can both pass and insert pending rows. Approval and rejection check the route-bound status before their transactions and do not re-read the listing under lock; concurrent approvals can race `ApprovedSupplier::firstOrNew()` against the `(item_id,vendor_id)` unique index and return a raw 500. The sequential tests at `api/tests/Feature/Purchasing/SupplierItemListingTest.php:134-146,240-262` do not exercise these interleavings.
- **Risk:** Duplicate offers, lost review outcomes, or a unique-violation 500 can leave the supplier portal and approved-supplier record out of sync. PU-08's new preferred index prevents two preferred rows, but does not make this listing workflow atomic.

### PU-09 — Bad practice — Medium — Three-way-match API and persisted snapshot still expose raw IDs

- **Effort:** S
- **Location:** `api/app/Modules/Purchasing/Support/ThreeWayMatchResult.php:18-37`; `api/app/Modules/Purchasing/Services/ThreeWayMatchService.php:125-127,160-162`; `spa/src/types/purchasing.ts:401-406`
- **Evidence/reproduction:** `ThreeWayMatchResult::toArray()` returns integer `po_id`, each line returns integer `item_id`, and the SPA types declare both as `number`. `BillService` persists this result into `bills.three_way_match_snapshot` as noted in the prior audit. Calling the three-way endpoint or reading the bill snapshot therefore violates the repository-wide HashID contract even though normal PO and item resources use strings.
- **Risk:** Internal integer primary keys can enter API consumers, browser state, and persisted audit data, making later resource hardening incomplete and creating an ID-enumeration inconsistency.

### PU-11 — Risk — Low — PO approval business gates are still evaluated before the authoritative lock

- **Effort:** S
- **Location:** `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:527-545,566-575`
- **Evidence/reproduction:** `assertAcknowledged()`, vendor SoD, department resolution, budget enforcement, and the PPAP checks run against the route-bound `$po` before the transaction. The transaction then locks a fresh row but rechecks only `status` before invoking `ApprovalService`. A concurrent change to the budget acknowledgment or another approval-relevant field between these phases can make the pre-lock decision differ from the row that is committed. The existing race test at `api/tests/Feature/Purchasing/PurchaseOrderTwoConnectionRaceTest.php:130-175` proves stale status handling, not these pre-lock gates.
- **Risk:** The row lock is not a complete lock-then-guard boundary for approval. Current vendor/line immutability makes the window narrower, but budget and future approval gates can be evaluated against stale state.

### PU-15 — Broken process — High — Partial supplier counter-offers understate PO totals

- **Effort:** M
- **Location:** `api/app/Modules/Purchasing/Services/SupplierResponseService.php:232-269`; `api/app/Modules/B2B/Requests/Supplier/RespondToPurchaseOrderRequest.php:25-30`
- **Evidence/reproduction:** `applyProposal()` locks all PO lines, but initializes `$subtotal` to zero and adds only `$response->items` (`SupplierResponseService.php:234-258`). The supplier request allows a proposal to name one line while leaving the other PO lines out, and allows both proposed quantity and proposed price to be null. With two PO lines, a proposal for only line A changes line A and then sets the header subtotal/VAT/total to line A alone; line B remains in the database but disappears from the header amount. The existing two-line test (`api/tests/Feature/Purchasing/SupplierResponseTest.php:236-296`) proposes both lines and cannot detect this.
- **Risk:** Accepting a legitimate partial counter-offer can understate the PO and downstream bill/commitment amount while retaining the omitted lines for receiving. A no-change proposal line can produce the same class of header corruption.

### PU-16 — Broken process — High — Accepted counter-offer does not re-enter the PO approval chain

- **Effort:** M
- **Location:** `api/app/Modules/Purchasing/Services/SupplierResponseService.php:159-190,261-269`; `api/app/Modules/Purchasing/Enums/PurchaseOrderStatus.php:52-65`
- **Evidence/reproduction:** Accepting a proposed response mutates prices/quantities, sets `requires_vp_approval`, and changes the PO directly to `Acknowledged`; it never calls `ApprovalService::submit()` or creates a new current approval attempt. `Acknowledged` is in `PurchaseOrderStatus::receivable()`, so the changed PO can proceed to receiving. The existing test at `api/tests/Feature/Purchasing/SupplierResponseTest.php:298-325` asserts only that the threshold flag becomes true, not that Finance/VP approval records are recreated or receiving is blocked.
- **Risk:** A supplier counter-offer that crosses the PHP 50,000 VP threshold, or changes any financial terms after the original approval, can become receivable without the required maker-checker approval. The comment at `SupplierResponseService.php:266-268` says the order “re-enters the approval gate,” but the implementation only refreshes a display flag.

### PU-17 — Risk — High — Procurement-chain overview ignores purchasing row scope and shares global cached counts

- **Effort:** M
- **Location:** `api/app/Modules/Purchasing/Controllers/ProcurementChainController.php:31-78`; `api/app/Modules/Purchasing/routes.php:15-19`
- **Evidence/reproduction:** The controller asserts that a user exists but never applies `PurchaseRequestAccessPolicy` or `PurchaseOrderAccessPolicy`. Every PR, PO, GRN, and bill count is company-wide, and the cache keys (`procurement_chain_pr`, `procurement_chain_po`, etc.) are not user- or scope-specific. A department head or production user with the seeded `purchasing.view` permission (`api/database/seeders/RolePermissionSeeder.php:691-700,873-892`) can GET `/api/v1/purchasing/chain` and receive other departments' counts plus the plant-wide unpaid/overdue bill amount.
- **Risk:** The aggregator bypasses the row-scope controls fixed for list/detail/search surfaces and leaks operational and AP volume across departments. This is also a cross-module flag for the shared aggregator/permission-scoping audit unit.

### PU-18 — Risk — High — Supplier listing prices are readable with broad purchasing.view permission

- **Effort:** S
- **Location:** `api/app/Modules/Purchasing/routes.php:94-100`; `api/app/Modules/Purchasing/Controllers/SupplierListingController.php:26-38`; `spa/src/routes/purchasingRoutes.tsx:83-90`
- **Evidence/reproduction:** The review queue GET endpoint and SPA route require only `purchasing.view`; only approve/reject actions require `purchasing.supplier_listings.review`. The resource returns supplier, part number, price, order unit, quantity conversion, and validity (`api/app/Modules/Purchasing/Resources/SupplierItemListingResource.php:14-40`), and the page fetches and renders that data even when `canReview` is false (`spa/src/pages/purchasing/supplier-listings/index.tsx:37-50,152-177`). `purchasing.view` is granted to department heads and read-only production/ImpEx roles, while the review permission comes from the full purchasing module catalogue.
- **Risk:** Non-purchasing roles can read supplier quotes and commercial terms merely by opening the review page. The write gate is correct but does not protect the read side of the review queue.

### PU-19 — Broken process — Medium — “Due this month” chain amount is filtered by bill date, not due date

- **Effort:** S
- **Location:** `api/app/Modules/Purchasing/Controllers/ProcurementChainController.php:62-70`; `spa/src/pages/purchasing/chain/index.tsx:98-102,197-201`
- **Evidence/reproduction:** The API labels the result `bills_this_month`, while the SPA labels it “due this month,” but the query filters `whereMonth('date', now()->month)` and sums unpaid, partial, and paid residual balances. A bill issued in September with an October due date is counted as September due; a bill issued in August with a September due date is omitted. Paid rows are also included in the source set, even though their balance is zero.
- **Risk:** The procurement dashboard gives Finance and Purchasing a misleading due-this-month workload and cash amount.

### PU-20 — Gap — Medium — Supplier quality breakdown promises stages the query cannot reach

- **Effort:** M
- **Location:** `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:315-358`; `api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php:42-58`; `spa/src/pages/purchasing/suppliers/performance.tsx:211-235`
- **Evidence/reproduction:** `qualityMetrics()` joins inspections only through the vendor's GRNs (`goods_receipt_notes -> inspections`) and then groups the result by `stage`. In-process and outgoing inspections are attached to work orders/deliveries, not GRNs, so those two buckets cannot be populated by this query. The API and UI nevertheless expose “In-process QC” and “Outgoing QC” cards, which remain null/“—” for valid non-incoming inspection data.
- **Risk:** The scorecard presents an incomplete quality picture while suggesting that all three quality stages were measured. Either the contract must be incoming-only or the query needs source-specific vendor linkage.

### PU-21 — Gap — Medium — Supplier counter-offer numeric bounds do not match PO storage bounds

- **Effort:** S
- **Location:** `api/app/Modules/B2B/Requests/Supplier/RespondToPurchaseOrderRequest.php:27-30`; `api/app/Modules/Purchasing/Services/SupplierResponseService.php:243-258`; `api/database/migrations/2026_09_11_000004_create_purchase_order_responses.php:46-52`
- **Evidence/reproduction:** Supplier proposed quantities/prices use only `numeric` and `min:0.01`; there is no decimal-scale or maximum rule. The response-item columns are `decimal(12,2)` and `decimal(15,2)`, and accepted values are written into the PO line columns through `applyProposal()`. A supplier can submit an oversized or high-precision numeric value that passes request validation and reaches PostgreSQL as a 500/rounding event instead of a bounded 4xx. The normal PO create/update requests already carry explicit maxima (`StorePurchaseOrderRequest.php:58-64`, `UpdatePurchaseOrderRequest.php:47-49`).
- **Risk:** A newly exposed external financial input path is not constrained to the same storage and money contract as internal PO inputs. This is a cross-module B2B boundary issue; the purchasing write path is the affected consumer.

### Clean areas and verification limits

- Clean/reverified: PO show/PDF row scope, PR department attribution, PR chain redesign, preferred-supplier database uniqueness, auto-PO VP flag, server action-map wiring, and positive PO price validation.
- Clean/reverified by source reading: HashIDs are used by current purchasing resources and supplier-response resources except for the documented three-way-match DTO/snapshot exception; supplier dispatch and PO conversion retain transaction/lock boundaries on their main write paths.
- No tests, Docker, Artisan, browser, queue, scheduler, migration, or live database commands were run for this re-audit, per scope. Evidence is static reading at commit `56e0d431` plus inspection of directly relevant tests; test files were read but not executed.
- Not re-proven here: external supplier-provider delivery, queue/outbox replay, accounting bill row scope, B2B portal deployment behavior, and live responsive rendering. Those remain dependency/verification limits rather than additional purchasing findings.

No application code, migration, test, registry, or roadmap was changed. Only this section was appended to `audit/purchasing.md`.
