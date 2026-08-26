# Return Management (RMA) — Audit Report

- Module: `supply-chain / returns-rma` (M046)
- Audit date: 2026-08-25
- Audit mode: fresh discovery → hardening → polish audit
- Status recommendation: `📋 Plan Ready`
- Scope: Return Management API, its RMA migrations/models/services/listeners, the RMA SPA pages/API types, and the module's feature/permission entry points. Dependency modules were read for contracts only.

> **2026-08-27 resolution pass.** Findings below are the original text, kept as the record.
> Current state per finding — details and file:line in `fix-log.md`:
>
> | finding | state |
> |---|---|
> | RMA-001, RMA-003 | fixed 2026-08-25, **runtime-verified 2026-08-27** (were never executed) |
> | RMA-005, RMA-006, RMA-007, RMA-008, RMA-010, RMA-011, RMA-012, RMA-013 | fixed and verified 2026-08-27 |
> | RMA-002, RMA-004, RMA-009 | **deferred — need a product/finance decision**, see "Deferred" in `fix-log.md` |
>
> Two defects NOT in the original findings were found by finally running the suite, and are
> the reason it mattered that no session had reached a database:
>
> - **`settledQuantity()` ignored a recorded receipt count** — it gated on the
>   `receipt_recorded` flag alone, contradicting the invariant its own migration
>   (`2026_08_25_190000`) declares and backfills. It over-moved stock
>   (`InsufficientStockException` on every customer restock) and **over-credited the
>   customer** by the difference between requested and returned quantity.
> - **Supplier-credit test fixtures built an unposted `Bill`**, so 7 failures were a fixture
>   gap against a correct Accounting invariant, not a module defect.
>
> A third, self-inflicted and worth remembering: a new migration named `0479_` per the
> "highest + 1" convention silently skipped its own guards, because every `04xx_` file sorts
> before every `2026_` file and the table it constrains is created by a timestamp migration.


The previous report was not reused as a source-of-truth because the module files had substantial uncommitted changes after that report and the change was not recorded in `fix-log.md`. No production source file was changed during this audit session.

## Discovery

### What exists

- The API has request, approval, receipt, Quality handoff, disposition, completion, reject, cancel, resource, and source-option surfaces in `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php:41-456` and `api/app/Modules/ReturnManagement/routes.php:13-35`.
- The service now owns draft updates, source-line resolution, source allocations, lifecycle transitions, quarantine/stock movements, supplier credits/replacement POs, customer credit-note creation, and Quality retry handling in `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:65-1765`.
- The lifecycle table is explicit in `api/app/Modules/ReturnManagement/Support/ReturnRequestStateMachine.php:15-59`; the service generally locks the RMA and wraps writes in `DB::transaction()` before invoking it, for example submit/approval at `ReturnRequestService.php:469-498,512-544` and receipt at `:556-614`.
- Source reservations were added as a separate ledger in `api/app/Modules/ReturnManagement/Models/ReturnRequestSourceAllocation.php:12-42` and `api/database/migrations/2026_08_25_190000_harden_return_source_allocations.php:36-50`.
- The SPA has list, draft create/edit, detail, receipt, Quality retry, and disposition screens at `spa/src/pages/return-management/list.tsx`, `create.tsx`, `detail.tsx`, and `dispose.tsx`.
- Eight feature test files cover HTTP boundaries, receipt, disposition, restock, supplier return, completion, and Quality handoff under `api/tests/Feature/ReturnManagement/`.

### What is incomplete or missing from the discovered surface

- The `resolution` option set advertises Replace, Refund, Credit Note, Scrap, and Return to Vendor at `api/database/migrations/0334_seed_return_option_settings.php:18-21`, but only customer/supplier credit-note paths and supplier replacement-PO creation are implemented. There is no customer replacement work-order path or refund settlement path.
- The module has a configured `return_management` feature flag at `api/database/seeders/SettingsSeeder.php:452`, but the RMA API and browser routes do not consume it.
- The source-allocation ledger has no resource/API representation of reserved or remaining source quantity. The create UI sees only the raw source quantities returned by `sourceOptions()`.

## Hardening findings

### RMA-001 — Broken — Customer source resolution destroys the source kind

`resolveSource()` builds an associative map of `invoice_item`, `sales_order_item`, and `delivery_item`, then immediately applies `array_values()` at `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:238-243`. At `:249`, `array_key_first($sources)` therefore returns the numeric key `0`, not the source kind. The string comparisons at `:252` and `:275` fail, the code falls through to the delivery branch at `:295`, and the returned `kind` remains numeric at `:306-311`. The later `sourceLimit()` match only accepts the four string kinds and throws `Unsupported return source line` at `:393-401`.

This is the normal non-finance customer path: the request validator requires an inventory item and exactly one source line at `api/app/Modules/ReturnManagement/Requests/StoreReturnRequestRequest.php:147-172`, and the SPA sends the selected source IDs at `spa/src/pages/return-management/create.tsx:213-225`. The source-backed customer RMA cannot reliably be created or submitted until this is fixed.

### RMA-002 — Broken — Order/delivery-backed customer returns can dispose without a customer credit

The service supports customer source provenance from sales-order and delivery lines at `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:275-311`, and the source-options endpoint exposes both at `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php:129-166`. However, `dispose()` calls `createCreditNote()` only when the RMA root has an invoice ID at `ReturnRequestService.php:908-919`. A customer RMA sourced from an SO or delivery without `invoice_id` therefore reaches disposition successfully but produces no credit note, even though `createCreditNote()` accepts the source-line variants and passes the root invoice as an optional field at `:1148-1185`.

This is a contract decision that needs to be made explicit: either invoice-less SO/delivery returns must be rejected before approval, or the credit-note path must support the documented source types. The current API/UI support the latter source choices without enforcing the former.

### RMA-003 — Broken — The SPA drops the product identity needed for Quality handoff

Customer source lines include `product_id` in the API response at `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php:120-165`, but `selectSourceLine()` only sets root document IDs at `spa/src/pages/return-management/create.tsx:183-194`. The payload deliberately emits `product_id` only for finance-only lines at `create.tsx:213-224`; a normal source-backed line sends an `item_id` but no product ID.

The backend groups Quality work exclusively by `ReturnRequestItem::product_id` at `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:759-783`. When that ID is absent, the inspect path transitions the RMA to `Inspected` with `NotRequired` at `:662-674`. Thus a product-backed customer return can become item-only in the browser and bypass the product Quality handoff. If item-only returns are intentionally exempt, the UI still needs to distinguish and validate that policy; the current source selector supplies the product identity but discards it.

### RMA-004 — Missing — Replace and Refund resolutions have no downstream effect

The draft form accepts the configured resolution values at `spa/src/pages/return-management/create.tsx:259-262`, and the API persists the free-form value at `api/app/Modules/ReturnManagement/Requests/StoreReturnRequestRequest.php:74-81` and `ReturnRequestService.php:100-103,139-147`. Disposition ignores it: customer handling only stages a credit note at `ReturnRequestService.php:908-919`, while supplier handling can create a replacement PO at `:1093-1101`.

`replacement_wo_id` exists in the RMA model/migration (`api/app/Modules/ReturnManagement/Models/ReturnRequest.php:52-56`, `api/database/migrations/0158_create_return_requests_table.php:57-64`) but has no relation or writer. No refund service, payment/refund record, or refund mutation is present in the module. Operators can select Replace or Refund and reach a terminal RMA without the selected outcome being executed or marked as pending human work.

### RMA-005 — Incomplete — Existing source reservations are not revalidated against a changed source limit

`reserveSource()` correctly locks and checks the available source quantity when it creates an allocation at `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:360-391`. On submit, however, `refreshAuthoritativeLineContract()` refreshes price and total and only calls `reserveSource()` when no active allocation exists at `:438-459`. If the source document quantity is reduced after draft creation, an existing active allocation can therefore remain larger than the current authoritative source limit and still pass submission.

The new allocation table also has no database check for non-negative quantity/unit price and no check restricting `source_kind` to the supported values at `api/database/migrations/2026_08_25_190000_harden_return_source_allocations.php:36-50`. Application locking is valuable, but the reservation ledger has no persistence-level backstop for malformed or stale rows.

### RMA-006 — Incomplete — The application state machine has no database status guard

The application transition table is explicit and rejects unsupported transitions at `api/app/Modules/ReturnManagement/Support/ReturnRequestStateMachine.php:15-59`. The base RMA table still defines `status` as an unconstrained string at `api/database/migrations/0158_create_return_requests_table.php:33-36`. The lifecycle-guard migration adds checks for `inspection_handoff_status` and `quarantine_status`, but not `return_requests.status`, at `api/database/migrations/2026_08_13_221000_add_enum_lifecycle_status_guards.php:44-49`.

A direct model/query writer can persist a value outside `ReturnRequestStatus`; later enum hydration can fail before the state machine gets a chance to reject it. The database should backstop the same eight values used by the service.

### RMA-007 — Missing — The `return_management` feature flag is bypassable

The feature is seeded as its own module at `api/database/seeders/SettingsSeeder.php:452`, and the shared server middleware returns a fail-closed `feature_disabled` response at `api/app/Common/Middleware/CheckFeature.php:12-33`. The RMA API group uses only `auth:sanctum` at `api/app/Modules/ReturnManagement/routes.php:13`, unlike the other feature-gated module route groups. The browser RMA routes likewise sit directly in `advancedRoutes` with permission guards and no `ModuleGuard` at `spa/src/routes/advancedRoutes.tsx:44-49`, while the sidebar advertises the feature at `spa/src/components/layout/Sidebar.tsx:205-210`.

Disabling the setting can hide the navigation item but still leave direct RMA URLs and API calls available to users who retain the permission. The API and SPA need the same feature slug and disabled-module behavior.

### RMA-008 — Incomplete — Service-level disposition does not enforce the complete line set

The HTTP `DisposeReturnRequest` validator correctly rejects foreign, duplicate, and missing lines at `api/app/Modules/ReturnManagement/Requests/DisposeReturnRequest.php:68-96`. The public service method does not repeat the completeness check: it loops only over matching rows at `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:880-889` and marks the RMA disposed at `:931-939`. A direct service caller, queued workflow, or future controller can therefore terminalize an RMA with undecided lines even though the current HTTP request happens to be guarded.

The service should require a one-to-one disposition map before any NCR, credit, supplier, or stock side effect.

### RMA-009 — Incomplete — The documented incomplete supplier-draft path is not usable from the SPA

The service comments state that supplier drafts may exist before PO/GRN selectors are filled at `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:154-168`. The actual path returns `null` only when all supplier source IDs are absent at `:314-321`, then still requires a unit price at `:194-198`. The SPA's schema requires a source line for every non-finance supplier line at `spa/src/pages/return-management/create.tsx:91-96`, and source selection fills the PO/item/lot but not the line price at `:183-188`; the payload leaves price blank at `:217-226`.

As a result, a user cannot save the incomplete draft described by the service contract through the supplied UI, and an API caller can only do so by inventing a temporary unit price. Either drafts must be explicitly allowed with nullable/clearly provisional price and no source reservation, or the comments/UI should require complete supplier provenance at creation.

### RMA-010 — Incomplete — Source availability is optimistic and reservation lineage is hidden

`sourceOptions()` returns raw invoice/SO/delivery/GRN quantities and caps documents at 100, but does not subtract active RMA allocations at `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php:109-166,180-239`. The SPA labels those values as available/delivered/accepted at `spa/src/pages/return-management/create.tsx:175-181`. The authoritative rejection only occurs later in `reserveSource()` at `ReturnRequestService.php:367-381`.

Two operators can see the same remaining quantity, one receives a late validation error, and neither can see the reservation that caused it. Exposing remaining quantity and the RMA allocation reference would make the source contract auditable and reduce avoidable failed submissions.

### RMA-011 — Incomplete — Decimal quantities are converted through float/integer paths

RMA quantities are stored to three decimal places at `api/database/migrations/0158_create_return_requests_table.php:89-94`. Quality batch calculation converts returned/requested quantities through `float` and `ceil` before casting to `int` at `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:779-783`. Supplier PO receipt status uses database sums cast to `float` at `:1105-1112`.

These are not money calculations, but they can change sample size or classify a fractional PO as fully/partially received at the boundary. The module should retain decimal-safe BCMath/string comparisons for all quantity decisions.

## Polish findings

### RMA-012 — Broken — The RMA detail page fails the SPA typecheck

`ReasonDialog` has two `minLength` attributes at `spa/src/pages/return-management/detail.tsx:892-905`. `npm run typecheck` reports `TS17001` at line 902, so the SPA build/type gate is red for this module. The same run also reports unrelated QR-code errors in `spa/src/pages/assets/detail.tsx`; those are outside M046 and are not included as M046 findings.

### RMA-013 — Polish — Manual-handoff retry visibility is inconsistent by role

The page-header retry button is available to `canInspect` at `spa/src/pages/return-management/detail.tsx:431-445`, but the equivalent retry button inside the warning banner is gated by `canManage` at `:571-591`. A QC inspector can therefore see a retry action in one placement but not the other. Both controls should use the same `return_management.inspect` policy and share one action component/state.

## Verification

- PHP syntax check passed for every file under `api/app/Modules/ReturnManagement`.
- `docker compose run --rm spa npm run typecheck` failed with the M046 duplicate-JSX-attribute error above, plus the unrelated asset QR-code errors.
- The first targeted API suite run completed with 28 passing and 19 failing tests (125 assertions). Several failures are fixture/contract drift in the current dirty tree: older tests still submit dispositions now rejected by the finance-only matrix, and Quality replay fixtures create a spec without the immutable revision now required by `InspectionService`. The shared test database was then torn down/partially migrated by parallel activity; a follow-up isolated test could not read the `migrations` table. The suite is therefore not a clean release signal, but it confirms that current test coverage does not protect the ordinary non-finance source path, feature-disabled behavior, source allocation revalidation, or resolution effects.
- The source tree has not been modified by this session; findings above are based on the current working tree and the existing tests/artifacts.

## Strengths verified

- Money-bearing totals and credit amounts use the module's `Money`/BCMath path at `ReturnRequestService.php:205-212,1047-1050,1157-1174`; no new float-based money calculation was found.
- RMA writes lock the root row and run inside transactions before state or cross-module side effects, including submit, receipt, inspect, dispose, and complete.
- Hash IDs are decoded at request boundaries and re-encoded in resources; line and source identifiers are not intentionally exposed as raw API IDs (`StoreReturnRequestRequest.php:44-61`, `ReturnRequestItemResource.php:15-44`).
- The HTTP disposition boundary has explicit full-set, duplicate, foreign-line, and location validation, and stock movement code has active warehouse/zone checks (`DisposeReturnRequest.php:68-118`, `ReturnRequestService.php:417-435,1437-1524`).
- Action-specific permissions are present for approve, receive, inspect/retry, dispose, complete, and manage routes at `api/app/Modules/ReturnManagement/routes.php:18-35`; the corresponding SPA action checks are present at `spa/src/pages/return-management/detail.tsx:364-385`.
- Quality handoff failure is durable and recoverable: the service retains the RMA in `Received` with `ManualRequired` and records an outbox recovery request at `ReturnRequestService.php:643-659,818-827`; retry is available through a dedicated permissioned route.

## Questions for product/operations

- Should an invoice-less customer return sourced from an SO or delivery produce a credit note, or should invoice provenance be mandatory before approval? The current API exposes both policies simultaneously.
- Are external customer/supplier self-service RMA views required? The notification mail directs parties to contact operations, and the B2B route surface has no RMA endpoint; this is not classified as a defect without that product decision.
- Should supplier drafts be intentionally incomplete, or must every RMA be source-complete at creation? The service comment and SPA validation currently disagree.
