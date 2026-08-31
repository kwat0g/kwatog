# M044 — fix log

No application source fixes were applied during the original 2026-08-25 audit pass. The module initially remained 📋 Plan Ready because the dominant work requires separate decisions around actor ownership, document retention, proof immutability, landed-cost money rules, idempotency, and schema backstops.

Verification recorded in `audit-report.md`:

- Focused Supply Chain backend suite: 77 tests, 176 assertions passed.
- SPA TypeScript typecheck passed.

The findings and ordered remediation steps are in `audit-report.md` and `action-plan.md`.

## 2026-08-25 resumed plan

### M044-F001 — resolved

- Before: `api/app/Modules/SupplyChain/Requests/CreateDeliveryRequest.php:60` allowed `items.*.inspection_id` to be omitted, while `DeliveryService` rejected every missing inspection; the SPA carried the field in its type but rendered no selector.
- After: `api/app/Modules/SupplyChain/Requests/CreateDeliveryRequest.php:60-63` requires the inspection; `api/app/Modules/SupplyChain/Requests/DeliveryInspectionOptionsRequest.php:11-31`, `api/app/Modules/SupplyChain/Controllers/DeliveryController.php:41-47`, `api/app/Modules/SupplyChain/Services/DeliveryService.php:111-183`, and `api/app/Modules/SupplyChain/routes.php:94-103` expose only passed outgoing inspections linked to the selected sales order and subtract active delivery reservations from accepted capacity.
- After: `spa/src/api/supply-chain/index.ts:75-104` types and fetches the options; `spa/src/pages/supply-chain/deliveries/create.tsx:25-31,98-102,232-301` requires a selection, shows remaining capacity, and aligns quantity input/validation to two decimal places.
- Tests: `api/tests/Feature/SupplyChain/CreateDeliveryDriverGateTest.php:74-115` covers the required inspection and sales-order-scoped remaining-capacity contract. Focused result: 4 tests, 15 assertions passed.
- SPA verification: `npm run typecheck` has no delivery-page errors; it remains blocked by the pre-existing `qrcode` module/type errors in `spa/src/pages/assets/detail.tsx:6,67`.

### Deferred at human-decision gate

- M044-F002–F010 remain pending. M044-F002 must first define assignment ownership, driver handoff permissions, and the customer-versus-internal confirmation actor. Implementing the next ordered item without that decision would guess at the RBAC and workflow contract.

## 2026-09-01 re-audit

Baseline before any change (own database `ogami_test_dlv`):
`docker compose run --rm -e DB_DATABASE=ogami_test_dlv api php artisan test tests/Feature/SupplyChain --no-coverage`
→ **93 tests: 91 passed, 2 failed, 226 assertions, 138.23s**. Both failures were the
inherited `CocAutoAttachOnConfirmTest` cases left red by the quality session (M056).

### Inherited task — `CocAutoAttachOnConfirmTest` fixture: resolved (case (a))

Conclusion: **the fixture was unrealistic; the quality session's guard is correct.**
Evidence, measured not assumed:

- `InspectionStatus::Passed` is written in exactly ONE place in the whole
  application — `api/app/Modules/Quality/Services/InspectionService.php:567`
  (`grep -rn "InspectionStatus::Passed" app/` returns 12 hits; every other one is a
  read/comparison).
- That writer is `complete()`, which refuses the fixture's state twice before it can
  reach `passed`: `InspectionService.php:552-554` ("Cannot complete: inspection has no
  measurement rows.") and `:556-559` (any `is_pass IS NULL` row blocks completion).
- Scaffold rows are created one per (sample unit x spec item) at
  `InspectionService.php:375-399`, so `count(distinct sample_index)` always reaches
  `sample_size` for a spec-backed inspection.
- The old fixture mass-assigned `status = passed` with **zero** measurement rows —
  precisely the falsified state `CoCService::assertEvidenceSupportsCertificate()`
  (`api/app/Modules/Quality/Services/CoCService.php:211-254`) exists to refuse.

- Before: `api/tests/Feature/SupplyChain/CocAutoAttachOnConfirmTest.php:225-237`
  created the inspection with no measurements; 2 of 4 tests failed
  (`COC_NO_MEASUREMENT_EVIDENCE`).
- After: the same helper seeds one resolved critical-dimension reading per sampled
  unit via a new `seedResolvedMeasurements()`; a passed lot reads in-tolerance, a
  failed lot fails its first unit. `accept_count`/`reject_count`/`defect_count` are now
  self-consistent with the verdict.
- Result: `tests/Feature/SupplyChain/CocAutoAttachOnConfirmTest.php` → **4 passed
  (13 assertions), 24.84s**. The guard was not weakened and no Quality file was touched.

### M044-F011 — a cancelled sales order could still ship: resolved

- Before: `DeliveryService::create()` locked the sales order at `:238` and validated
  remaining quantity, but never read `sales_orders.status`. Cancelling an order does
  not release its ordered quantity, so the reservation ledger still reported capacity.
  Measured: `create()` returned a delivery against a `cancelled` order (probe recorded
  `[M044 cancelled-SO create] OK`).
- After: `api/app/Modules/SupplyChain/Services/DeliveryService.php:631-655` refuses
  `SalesOrderStatus::Cancelled`. The gate is in `assertDeliveryQuantitiesAvailable()`
  rather than `create()` because the outgoing-QC auto-draft listener
  (`Quality/Listeners/CreateDeliveryDraftOnQcPass.php:144`) builds its delivery row
  directly and calls that same public method — it is the one seam both creation paths
  share, and it lives in this module.
- Verified: `[M044 cancelled-SO create] BusinessRuleException: Sales order SO-P4-… is
  cancelled; no further deliveries can be scheduled against it.`
- `draft` is deliberately still accepted; refusing it would change which orders may
  ship, which is a human's call. Filed as a question.

### M044-F004a — the last-proof invariant raced: resolved

- Before: `DeliveryProofController::destroy()` took the "is this the last proof" count
  **before** opening its transaction and without any lock (old `:126-132`), so two
  concurrent deletes against two proofs each observed one remaining, each passed the
  guard, and both committed — a confirmed delivery with zero proof of delivery.
- After: `DeliveryProofController.php:118-160` moves the count inside the transaction
  behind `Delivery::lockForUpdate()` — the same row `DeliveryService::confirm()`
  serializes on (`DeliveryService.php:826`) — and also locks the target proof. The
  business refusal is converted to the same 422 body as before, so the API contract is
  unchanged.
- Not executed as a two-connection race: `RefreshDatabase` hides uncommitted rows from
  a second connection, so a naive probe reports "no lock" as an artifact, and a real
  two-PDO probe on this path risks the deadlock a prior session correctly abandoned.
  Closed by inspection, and said so in the report rather than claimed as measured.

### M044-F010 — a client filename could forge the download header: resolved

- Before: `DeliveryProofController::view()` interpolated `$proof->file_name` — the
  client's original upload name, stored verbatim — straight into
  `Content-Disposition`. Measured: a proof named `a".jpg` produced
  `inline; filename="a".jpg"`, where the quote closes the parameter early and forges
  the remainder of the header value.
- After: `DeliveryProofController.php:117-158` builds an RFC 6266 disposition via a
  new private `contentDisposition()` — sanitised ASCII `filename` plus
  percent-encoded UTF-8 `filename*`. Measured: `inline; filename="a.jpg";
  filename*=UTF-8''a.jpg`. This copies the shape
  `B2B/Controllers/CustomerPortalController.php:187-199` already uses, including its
  hard-won comment about why the character class needs hex escapes and a doubled
  backslash.
- `ShipmentController::downloadDocument()` still carries the raw pattern; left for the
  shipment tranche and recorded in the report.

### Verification of this session's work

- `docker compose run --rm -e DB_DATABASE=ogami_test_dlv api php artisan test tests/Feature/SupplyChain --no-coverage`
  → **109 passed, 304 assertions** (baseline 93 with 2 failed). No pre-existing test
  turned red.
- Cross-module callers of the changed seam: `--filter='DeliveryDraft|QcPass|SalesOrder|Coc'`
  → all green; `tests/Feature/Quality` → 142 passed, 1 failed
  (`ZzAuditProbeTest > p10 escalation all failed looks like idle`, another live
  session's NCR-escalation probe — confirmed not mine via `git diff --name-only HEAD`).
- `./vendor/bin/phpstan analyse app/Modules/SupplyChain --memory-limit=1G` → **no errors**.
- `./vendor/bin/pint --test` on the four changed files: the three pre-existing files
  fail with rule sets **byte-identical** to their pre-session versions, proved by
  extracting `git show 0268dbff:<path>` into a scratch directory and running Pint over
  base and current side by side at `COLUMNS=400`:
  - `DeliveryProofController.php` base = current = `class_attributes_separation,
    unary_operator_spaces, not_operator_with_successor_space,
    blank_line_before_statement, ordered_imports`
  - `DeliveryService.php` base = current = `lambda_not_used_import,
    spaces_inside_parentheses, unary_operator_spaces, braces_position,
    not_operator_with_successor_space, single_line_empty_body, phpdoc_align`
  - `CocAutoAttachOnConfirmTest.php` base = current = `concat_space,
    binary_operator_spaces`
  **NEW (mine only): []**. My own new probe file's 3 issues were fixed with Pint in
  write mode on that file alone.

### Deferred, with reasons

- **F014** (CoC failure swallowed) and **F013** (AR invoices an unconfirmed delivery)
  are the two P0s and both are gated: F014's fix decides whether a shipment may leave
  uncertified, F013's code is Accounting's.
- **F016** (CoC guard vs AQL `accept_count`) is Quality's guard; reported, not touched.
- **F003** restore binding is deliberately NOT fixed: adding `->withTrashed()` without
  the file-retention decision would resurrect metadata pointing at deleted bytes.
- **F002** RBAC, **F015** accepted quantity, **F012** stock decrement, **F004b**
  append-only evidence, **F006** landed cost, **F007** idempotency, **F009**
  containers, **F017** mass-assignment — all either change policy, cross a module
  boundary, or are large. See `action-plan.md`.
