# M058 — traceability-ppap fix log

## 2026-08-25 fresh audit

No application source fixes were applied. The prior report was re-audited because the shared worktree contained uncommitted changes in the report’s cited source files. The fresh findings and ordered remediation work are in `audit-report.md` and `action-plan.md`.

There are no before/after fix entries for this session.

Verification recorded:

- Quality route inventory succeeded and includes the PPAP, traceability, recall, and shipment-lot endpoints.
- `SupplierPpapViewTest`: 3 tests passed.
- `BatchLotSequenceTest`: 3 tests passed.
- `LotTraceabilityTest`: 4 tests failed before assertions because `GoodsReceiptNote::qcInspection` is missing at `api/app/Modules/Inventory/Services/GrnService.php:84,272`; this dependency issue was not changed.
- SPA typecheck failed on unrelated asset QR-code and return-management files; no traceability-file error was reported.

The module remains `📋 Plan Ready`; it must not be marked Verified until the plan is implemented and the focused verification gate is green.

## 2026-08-25 resumed plan session

### M058-F009 — visible traceability search label

- `spa/src/pages/quality/traceability.tsx:51-70` — before: the search input had only a placeholder and `aria-label`, with no visible field label; after: added the visible `Batch, shipment lot, or material lot` label linked to `traceability-term` and aligned the submit control with the labelled field.
- Verification: `git diff --check -- spa/src/pages/quality/traceability.tsx` passed; `npm run typecheck` in `spa/` passed.

### Deferred plan items

- M058-F001, M058-F002, and M058-F007 remain pending because the authoritative lot/allocation rule and cross-module ownership are unresolved; implementing them would require changing dependency modules or guessing quantity semantics.
- M058-F003, M058-F004, M058-F006, and M058-F008 remain pending because the PPAP element matrix, PSW count, evidence policy, maker-checker/revision policy, and gate fail-open/fail-closed rule require human decisions that are explicitly open in `audit-report.md`.
- M058-F005 remains pending because the internal and supplier workflows depend on those unresolved backend contracts.

This was a genuine mid-plan deferral, not a fresh Plan Ready handoff. The module should be re-audited after the policy and ownership decisions are recorded and the remaining plan items are implemented.

## 2026-08-25 interrupted-session recovery

The worker context ended after the fresh audit artifacts were written but before the lock was released. No application source fixes were pending; the Plan Ready status and action plan are preserved for the dedicated implementation session.

## 2026-09-01 re-audit + partial fix session

Claim: **RECLAIMED** (orphan lock, 153h stale).

**Baseline before any change** (real run, non-zero assertions):
`artisan test tests/Feature/Quality tests/Unit/BatchLotSequenceTest.php tests/Feature/B2B/SupplierPpapViewTest.php`
on `ogami_test_ppap` → **135 tests / 388 assertions / exit 0**.

**Coverage split measured first:** `grep -rn -E "quality/ppap|quality/traceability" api/tests/`
returns nothing — **0 of 12 endpoints had ever been exercised over HTTP.** Every finding
below came from closing that gap.

### Fixed — 5 changes, all inside `api/app/Modules/Quality/`

1. **R1 `TraceabilityService.php:59,216` — the forward trace had never worked.**
   Before: `whereJsonContains('material_lot_references', ['material_lot_number' => $lot])`
   — an associative array encodes to a JSON *object*, the column is a JSON *array of
   objects*, and PG containment refuses that (`'[{...}]'::jsonb @> '{...}'::jsonb` = false,
   verified directly against PG16).
   After: both call sites go through one documented private helper
   `workOrdersConsumingLot()` using `[['material_lot_number' => $lot]]`.
   Verified: `recall-simulation` on an intact chain went from
   `found:false / 0 customers / 0 deliveries / qty 0` → `found:true / 1 customer
   ("Toyota Motor Philippines") / 1 delivery / qty 500`.

2. **R10 `PpapService::updateElement()` — approved evidence was swappable.**
   Before: no parent-state guard; the approved PSW `document_path` could be replaced and
   its status flipped to `rejected` while the parent still read `approved`, with zero audit
   rows (`PpapElement` has no `HasAuditLog`).
   After: refuses when the parent is `Approved` (`BusinessRuleException` → 422 via the
   controller, which now catches it). `Rejected` rework is deliberately untouched pending
   open question 5.
   Verified: PATCH after approval 200 → **422**, `document_path` still
   `ppap/original-psw.pdf`, status still `accepted`; and still editable pre-approval.

3. **R3 `TraceabilityController.php:20,27` — array query params 500'd both routes.**
   Before: `(string) $request->query('term')`. After: `validate(['term' => ['nullable','string','max:100']])`.
   Verified: `?term[a]=b` and `?lot[a]=b` 500 → **422**; scalars unchanged at 200.

4. **R14 `PpapController::update()` — accepted a raw PK, refused the HashID.**
   Before: `'product_id' => ['sometimes','nullable','integer']`, `'ppap_level' => ['sometimes','string']`.
   After: `product_id` is a HashID decoded via `Product::tryDecodeHash` with an existence
   check; `ppap_level` uses `Rule::enum(PpapLevel::class)`.
   Verified: raw PK 200 → **422**; HashID 422 → **200**; `banana`/`7`/`1e0`/`1.0` 500 → **422**.

5. **R14 `StorePpapRequest`** — `Rule::in` → `Rule::enum` (loose comparison let `'1e0'`
   through to `varchar(1)` as a 500 and coerced float `1.0` to Level 1); unresolvable
   optional `product_id`/`purchase_order_id` are now refused rather than silently nulled.
   Verified: `'1e0'` 500 → **422**; garbage hash 201-with-null → **422**.

### Test evidence and honesty about it

`tests/Feature/Quality/TraceabilityPpapAuditRegressionTest.php` — 14 tests, 88 assertions.
**8 confirmed RED against unmodified source** (files reverted with `git show HEAD:…`, run,
then restored and restoration **proven** with `sha256sum -c` → 5/5 OK).

Of the 6 that pass either way: 1 is a "must keep working" lock on the backward trace, 1
locks pre-approval editability, 2 are the auth/permission gates, and **2 are labelled
PASS-EITHER-WAY LOCKS ON KNOWN DEFECTS** (`…archiving_a_delivery_silently_drops_it…`,
`…a_partial_trace_looks_complete…`). Those two assert today's *wrong* behaviour to pin the
numbers for the follow-up session — **green there does not mean healthy.**

### Verification

- `artisan test tests/Feature/Quality tests/Unit/BatchLotSequenceTest.php tests/Feature/B2B/SupplierPpapViewTest.php tests/Feature/B2B/SupplierPortalCrossTenantTest.php tests/Feature/Inventory/LotTraceabilityTest.php tests/Feature/Purchasing/PurchaseOrderReopenPrTest.php`
  → **201 passed / 721 assertions / exit 0.** No pre-existing test turned red.
- `phpstan analyse app/Modules/Quality --memory-limit=1G` → **No errors** (94 files).
- Pint: **NEW (mine only) = []** on all four changed app files. Proven programmatically by
  extracting `git show HEAD:…` into `/tmp`, running `pint --test` on both, and diffing the
  fixer sets. `PpapController` reports two extra fixer *names*, but `pint` in write mode on
  a copy produces **zero diff in my added lines** — all 4 hunks are the file's pre-existing
  aligned-`=>` style, 2 of them on untouched HEAD lines. The new test file is Pint-clean.
- `php -l` clean on all 6 files.

### Deferred, with reasons

- **R7 shipment-lot creation 500s unconditionally** (`ShipmentLotService.php:41` calls the
  nonexistent `WorkOrder::decodeHashId()`). **`api/app/Modules/SupplyChain/` is LIVE under
  another agent — read only.** The one-line correction is `WorkOrder::tryDecodeHash()`, but
  it belongs with the allocation-contract work and is not mine to land.
- **R8 trace erasure across 9 links** — needs migrations and `SoftDeletes` decisions in
  Inventory, SupplyChain and CRM, plus the IATF policy call on whether archival is
  recoverable. Large, cross-module, auditable.
- **R9 PPAP element matrix + a create-element route + self-approval** — blocked on open
  questions 1, 2 and 4. Changing approval authority is explicitly out of scope for a
  containment session.
- **R10 reject-after-approve** — refusing it changes whether an approval may be reversed,
  which is open question 5 (revision vs. reject). Guard not added.
- **R12 scheduled expiry command** — `expireOverdue()` has 0 callers, but adding it touches
  `routes/console.php`, a shared file with other sessions live. Medium scope, deferred.
- **R15 missing SPA surfaces** — large, and blocked on the backend contracts above.
- **R19 seeder produces zero trace inputs** — `database/seeders/` is not my module.

### Could not verify

- F001 authoritative material-issue lineage (Production/Inventory owned; Inventory live).
- `unsignedInteger` overflow on shipment-lot quantity — masked by R7's 500.
- Attachment invariants — **no upload or download surface exists** for PPAP evidence.
- Two-connection race on approval — `RefreshDatabase` would make the result an artifact.

### Environment note

`ogami-db` and `ogami-redis` exited (255) mid-session when a backgrounded
`docker compose run` was killed with the parent process. Recovered with
`docker compose start db redis` + **72s** of `FATAL: the database system is starting up`
before the first successful query. No `docker compose down` was used. All measurements
above were taken either before that crash or after the verified recovery.
