# M054 — fix log

Resumed plan execution: 2026-08-25. The module is released as 🔁 Needs Re-audit because the contained fixes below are complete, while F002/F003/F004/F009 require policy or cross-module decisions.

## Fixed and verified

- F001 — `api/app/Modules/Inventory/Services/QuarantineService.php:191-201,320-342,412-449`: added active-location, typed-zone, active-warehouse, same-warehouse, and good-zone validation for hold/release paths; auto-resolved locations also require an active warehouse. `spa/src/pages/inventory/mrb/index.tsx:246-267` and `spa/src/pages/inventory/mrb/detail.tsx:240-257` now filter the same valid choices. Before: explicit IDs bypassed resolver rules. After: forged inactive, wrong-zone, and cross-warehouse payloads fail before movement.
- F005 — `api/app/Modules/Inventory/Services/QuarantineService.php:111-164,472-531` and `api/app/Modules/Inventory/Requests/MrbQualityOptionsRequest.php:11-34`: added item-scoped failed-inspection/open-NCR lookup and item/status/quantity/event consistency checks. `api/app/Modules/Inventory/Resources/MaterialReviewRecordResource.php:37-61` and `spa/src/pages/inventory/mrb/detail.tsx:129-142` expose the quality state. Before: IDs were existence-only and the UI listed the first 100 NCRs. After: unrelated or non-actionable links are rejected and the selected inspection/NCR state is visible.
- F006 — `api/database/migrations/2026_08_25_190000_add_mrb_hold_idempotency.php:11-24`, `api/app/Modules/Inventory/Services/QuarantineService.php:180-223,450-470`, and `spa/src/api/inventory/mrb.ts:37-40`: added actor-scoped durable key/fingerprint replay handling and SPA retry headers. Before: every retry created a new MRB and movement. After: the same command returns its original row; changed-payload reuse returns a business-rule error.
- F007 — `api/app/Modules/Inventory/Requests/MrbIndexRequest.php:11-27` and `api/app/Modules/Inventory/Services/QuarantineService.php:50-89`; `spa/src/pages/inventory/mrb/index.tsx:144-145`: search is validated, forwarded, and matched against MRB/item/NCR/location fields while URL state remains synchronized.
- F008 — `spa/src/pages/inventory/mrb/index.tsx:38-47,229-278,321-416`: aligned quantity entry to three decimals, added searchable item and quality lookups, active-location filtering, scoped result limits, and retry/error states.

## Deferred with reason

- F002 remains pending: generic transfer/adjustment ownership crosses shared Inventory and Return Management paths, and the existing RMA quarantine flow is a valid exception. No authoritative exception/reconciliation policy was available; no dependency module was changed.
- F003 remains pending: no authoritative MRB rework/reinspection evidence or use-as-is concession approval model exists. A human decision is required before adding a release gate.
- F004 remains pending: supplier/PO/GRN/bill provenance and Purchasing/Accounting handoff ownership are unspecified, so no cross-module terminal-state or notification behavior was invented.
- F009 remains pending: segregation-of-duties thresholds and approval ownership are unspecified; changing shared RBAC/seeder policy is outside this module scope.

## Verification

- Passed: `docker compose run --rm api php artisan test tests/Feature/Inventory/QuarantineMrbTest.php tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php` — 16 tests, 49 assertions.
- Passed: targeted ESLint for `spa/src/pages/inventory/mrb/index.tsx` and `detail.tsx`.
- Repository-wide SPA typecheck is blocked by pre-existing errors in `src/pages/assets/detail.tsx` (`qrcode`) and `src/pages/return-management/detail.tsx`; no M054 file appears in that error set.
- Repository-wide SPA lint remains blocked by three pre-existing hook dependency errors outside M054.

---

# RE-AUDIT 2026-09-01 — no code changed, and why

**No source file was modified this session.** M054's entire runtime surface is in
`api/app/Modules/Inventory/` (including `StockMovementService`, which owns the
most severe finding), and that module is LIVE under another agent
(`warehouse-stock-control`) with read-only access for this session.
`api/app/Modules/SupplyChain/` is live too. Files changed: only the three
documents in `audit/domains/quality/material-review-board/`.

Independently, 6 of 9 findings are IATF-auditable design decisions that the
session brief excludes from same-session fixing. See `action-plan.md` for the
full justification and the ordered hand-off.

A scratch probe (`api/tests/Feature/Quality/M054MrbSeamProbeTest.php`) was written
to measure the invariants below and **deleted before release**; the test database
`ogami_test_mrb` was dropped. All evidence is recorded in `audit-report.md`.

## Prior session's log: accurate

The 2026-08-25 log claimed `16 tests, 49 assertions` for its resumed-plan
verification. Re-run this session on a private database: **16 passed, 49
assertions, exit 0** — an exact match. 5 of its 9 findings are genuinely fixed
and were verified by probe here (F001, F005, F006, F007, F008); the 4 it deferred
as needing cross-module policy all reproduce, which was the correct call.

## Invariants executed

Measured on real PostgreSQL rows in `ogami_test_mrb` unless marked otherwise.

| # | invariant | probe | measured result |
|---|---|---|---|
| 1 | a disposition produces a stock movement | I1: `ncr.disposition='scrap'` with no MRB; then MRB hold+scrap release | **SPLIT.** NCR-side: `stock_movements` **0 → 0** (no material effect — reproduces `ncr-capa` N-004). MRB-side: **0 → 1 → 2** (hold + release both post). MRB is the only mechanism that moves stock. |
| 2 | decision recordable with no material effect | I1a + code read of `NcrService::close()` `:304-370` | **YES for the NCR** — closes with WO/notification only, never stock. **NO for the MRB** — `release()` always posts a movement. |
| 3 | quantity dispositioned reconciled against quantity quarantined | I3: 3 holds of 40 against one NCR with `affected_quantity=40` | **NOT RECONCILED.** 3 MRB rows, `sum(quantity)=120.000` vs 40 affected. `assertQualityLinks():506-508` checks each hold in isolation. |
| 4 | same material dispositioned twice | I4: re-release a `scrapped` MRB | **Per-record: BLOCKED** (`MRB … is not held (status: scrapped)`). **Per-NCR: NOT blocked** — see #3/#5. |
| 5 | scrap **and** return-to-supplier both | I5: two MRBs on one NCR, released `scrap` and `return_to_supplier` | **BOTH SUCCEED.** `a=scrap/scrapped`, `b=return_to_supplier/returned`; 80 units disposed against a 40-unit nonconformance. |
| 6 | quarantined stock excluded from issue / sale / MRP | I8 (issue, delivery) executed; MRP/WO/reserve guards read | **EXCLUDED.** `MaterialIssue` → blocked, `Delivery` → blocked (`BusinessRuleException`). Guards also at `MaterialIssueService:96-97`, `PickingListService:132-133`, `StockMovementService::reserve():386-397`, `MrpEngineService:867-868`, `WorkOrderService:955-956,1063-1064`. Two of these executed; four read only. |
| 7 | quarantined stock not invisible | I7: stock_levels after a 30-of-100 hold | **VISIBLE and not double-counted.** Real row at quarantine location `quantity=30.000 reserved=0.000`; total on hand still `100.000`; non-quarantine/scrap `70.000`. |
| 8 | moving it out requires a decision | I8: all 6 movement types out of quarantine, null reference, no MRB decision | **FAILS — 4 of 6 escape.** `Transfer`, `AdjustmentOut`, `Scrap`, `ReturnToVendor` all **ESCAPED**, quarantine → 0.000, MRB left `held` claiming 20.000. And **the MRB can then never terminate** — `release()` throws `InsufficientStockException` on all four. `MaterialIssue`/`Delivery` blocked. |
| 9 | board requires more than one party | I9: single-actor chain | **NO BOARD.** One permission (`inventory.mrb.manage`) gates both hold and release (`routes.php:157-158`); `warehouse_staff` and `qc_inspector` both hold it. No approver column. |
| 10 | the causer/detector cannot disposition | I10: one `qc_inspector` inspects, raises NCR, holds, releases | **CAN.** `inspector_id=7 ncr.created_by=7 held_by=7 released_by=7`, released `use_as_is` into good stock with no sign-off. |
| 11 | closed MRB record immutable | I11 (a–e) against a `scrapped` MRB | **FULLY MUTABLE.** Eloquent update → **ALLOWED** (`quantity` → `999.000`); terminal→`held` status downgrade → **ALLOWED**; raw SQL `mrb_number='HACKED'` → **ALLOWED**; `delete()` → **ALLOWED, row gone**; non-internal triggers → **NONE**; CHECK constraints → 1 (`material_review_records_status_lifecycle_check`, enum values only). Mitigations: no update/destroy route exists; `HasAuditLog` logs the Eloquent writes but not the raw SQL or the delete. |
| 12 | full transition matrix | I12: **20 cells** (4 states × 5 dispositions incl. one invalid) | **SOUND — 20/20 correct.** `held`+{rework,use_as_is,scrap,return_to_supplier} → released/released/scrapped/returned; `held`+invalid → refused; **all 15 terminal-state cells refused**. No `resume()`-style back-door; `release()` is the single mutation path and re-reads under `lockForUpdate` `:313-316`. |
| 13 | scheduled MRB commands: exit code + counters distinguish idle from failed | enumerated the full Artisan command list in-container, filtered `/mrb|quarantine/i`; `grep` on `api/routes/console.php` | **N/A — NONE EXIST.** Zero MRB commands and zero console entries. Reported as a *missing* subsystem (M054-R6: nothing ages a hold), **not** as "passed". |
| 14 | aggregates with real rows, no `42803` | code sweep of every `MaterialReviewRecord` reference | **N/A — no MRB aggregate exists.** `list()` paginates, `qualityOptions()` uses `whereHas`; the only aggregate anywhere is `BadgeService:433-435`, a plain `count()`. Nothing selects over `NonConformanceReport::actions()`, so the default-`orderBy` GROUP BY trap has no surface. |
| 15 | tests reach the row-mapping branch | I13/HTTP probes with real rows | **YES for `qualityOptions()`** — `GET /mrb/quality-options?item_id=<hash>` returned a populated `inspections`/`ncrs` array (not the empty case). The row-mapping closures `:141-162` executed. |
| 16 | empty period returns null not 0 | searched for any MRB rate/average/percentage | **N/A — none exists.** No division anywhere in the MRB surface; a `count()` of 0 is honest. |
| 17 | soft-deleted item / NCR / GRN / MRB across every aggregate and export | I13, I13b | **Item:** soft-deletable; `GET /mrb` and `GET /mrb/{id}` both **200** with `"item": null` — *no 500* (this **refutes** a hypothesis I formed from `MaterialReviewRecordResource:30-35` having no null guard; `JsonResource::whenLoaded()` short-circuits a null relation). The lot becomes unidentifiable, and the badge still counts it. **NCR/Inspection/MRB: no `SoftDeletes` at all** (probed via `class_uses_recursive`), so hard delete is the only delete — and `ncr_id` is `nullOnDelete`, so an MRB silently loses its quality trace (`ncr_id`→NULL, show still 200). **GRN:** MRB has no GRN FK, so N/A. **Export:** none exists, so N/A. |
| 18 | money/quantity FormRequest vs `1.999`/`1e3`/`1e17`/`10.00005` incl. map payloads | 20 values over HTTP against `POST /api/v1/inventory/mrb` | **FULLY HARDENED — 0 failures.** `1.999`→201 stored exactly `1.999`; `1.9999`,`10.00005`,`1e3`,`1E3`,`1e17`,`1e20`,`0.0001`,`abc`,`1,5`,`0x10`,`NaN`,`Infinity`→**422**; `0`,`-5`→422 `min`; `''`→422 required; `'  7  '`→201 `7.000`; `'+3'`→201 `3.000`. **Zero 500s, no `ValueError`, no `22003`, no silent rounding.** Rule is `['required','decimal:0,3','min:0.001']`, not `numeric`. `999999999999999` (would overflow `decimal(15,3)`) is masked by the stock check at `:236-244`. **No array/map payload exists** on either MRB request. |
| 19 | `Rule::exists()` non-closure form present | programmatic scan of all four MRB FormRequests | **ABSENT.** No `Rule::exists` in any form; plain `'exists:table,id'` strings only. The `false`-cast landmine has no site here. |
| 20 | permission gate per endpoint including list/options | HTTP, `maintenance_tech` vs `warehouse_staff` vs `qc_inspector` | **HOLDS on all six.** 403 for `maintenance_tech` on `/mrb/options`, `/mrb`, `/mrb/quality-options`, `/mrb/{id}`, `POST /mrb/{id}/release`. |
| 21 | all three registry roles complete their part | HTTP as `warehouse_staff` and `qc_inspector`; `RolePermissionSeeder:669-670,694-695` | **YES — and that is the defect.** Both roles hold `inventory.mrb.view` **and** `inventory.mrb.manage`, so each completes hold *and* release alone (#9/#10). `system_admin` holds everything. No role is stranded mid-workflow. |
| 22 | raw-id-free error bodies | `GET /mrb/999999`; programmatic `"id":<pk>` scan of the 200 body | **CLEAN.** Payload `id='1kPNxQbXyR'`, `hold_movement_id='40awqV4pKG'`; pk=34 **not present** in the body. `GET /mrb/999999` → 404. (404 body carries `exception`/`file` only because `APP_DEBUG=true` under `phpunit.xml` — env artifact, not an MRB defect.) |
| 23 | restore route binds `withTrashed()` | route table read | **N/A.** `MaterialReviewRecord` has no `SoftDeletes` and there is no restore or destroy route (`routes.php:153-158` = options/index/quality-options/show/store/release only). The five-module defect cannot exist here. |
| 24 | sequence row exists in `document_sequences` | queried the table after a real hold | **PRESENT.** `{document_type: 'mrb', prefix: 'MRB', year: 2026, month: 9, last_number: 1}` (seeded `0360_seed_document_sequence_config.php:16`) producing `MRB-202609-0001` — correct monthly-reset format. Unlike `work_order` (correction #5), MRB's row exists. |
| 25 | unvalidated `orderBy($direction)` (PATTERNS.md:262-268) | programmatic scan of service + requests | **ABSENT.** `list():88` hardcodes `orderByDesc('held_at')->orderByDesc('id')`; no `direction`/`sort` param on any MRB request. |
| 26 | dead surfaces (route↔client↔page↔docs) | both directions | **NONE in code.** All 6 routes have SPA client methods (`spa/src/api/inventory/mrb.ts`); both pages routed (`inventoryRoutes.tsx:109-112`) and sidebar-linked (`Sidebar.tsx:337-342`). **One doc gap:** `docs/USER-MANUAL.md` has **zero** mentions of MRB / material review / quarantine. |

### Could not test, and why

- **A two-connection race on `hold()` idempotency.** `RefreshDatabase` wraps the
  suite in a transaction, so a second connection cannot see uncommitted rows —
  the probe would report "no lock" as an artifact, and an uncommitted unique
  insert can deadlock. Abandoned deliberately rather than reported as a result.
  The UNIQUE `(held_by, idempotency_key)` constraint is the real guard and is
  visible in the schema; `MrbDoubleReleaseRaceTest` already covers the release
  race and passes.
- **A live browser journey.** Not run; no Chromium session was started for this
  module, so the SPA findings are code-read plus the API contract, not rendered
  behaviour.
- **The four escaping movement types over HTTP** (#8). Measured through
  `StockMovementService` directly, because the HTTP paths that reach them
  (transfer orders, stock adjustments) belong to the live `warehouse-stock-control`
  session's surface. The service-level result is unambiguous, but the exact HTTP
  reachability of each was not confirmed.
- **`GrnStatus::PartialAccepted` end-to-end.** The GRN findings in
  `audit-report.md` §3 are from a read-only code trace of `GrnService`, not
  executed — GRN belongs to another module.

### Tests written

One scratch probe, `api/tests/Feature/Quality/M054MrbSeamProbeTest.php`, 15 test
methods. **Deleted before release** — it was an instrument, not a regression
suite, and most methods deliberately assert nothing (they print measurements).
It was **not** confirmed red against unmodified source, because it was never
intended to gate anything: 9 methods passed and 6 were reported `risky` (zero
assertions) by PHPUnit, which is the expected shape for a measurement harness.
**Every method is a pass-either-way probe and is labelled as such.** The durable
tests that *should* exist are item 10 of `action-plan.md`.
