# M038 — Supplier Performance Audit

Status at audit time: `🔍 Auditing In Progress`  
Audit date: 2026-08-25  
Scope: `procurement / supplier-performance` only

## Executive summary

Supplier performance is a working medium-surface feature: monthly snapshots are
computed in a database transaction, the snapshot has a uniqueness constraint,
the outbox event is registered, deterioration is queued, the API routes are
permission-gated, and the vendor detail page is present. Static checks passed.

The score cannot be treated as decision-ready yet. The price metric is currently
quantity shortfall rather than price variance, the advertised in-process and
outgoing quality metrics cannot be populated from the quality module's normal
inspection entity types, and delivery metrics operate at GRN grain while their
contract describes PO-level measurements. The ranking contract and alert link
also have concrete defects, while a cross-vendor ranking UI is missing.

No source implementation was changed in this session. The high-risk findings
touch financial calculations, quality semantics, queued side effects, RBAC, or
a new frontend surface; they are therefore deferred to a separate fix session.

## Discovery

### Implemented surface

- `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:40-94`
  computes monthly metrics, persists one snapshot per vendor/period, and records
  `SupplierPerformanceComputed` in the same transaction.
- `api/routes/console.php:129-133` schedules the previous calendar month's
  recomputation on the first day of each month. The command entry point is
  `api/app/Console/Commands/RecomputeSupplierPerformance.php:23-37`.
- `api/app/Modules/Purchasing/routes.php:83-94` exposes the detail, ranking, and
  recompute routes with separate permissions.
- `api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php:27-77`
  exposes a vendor detail/trend response; `:99-147` exposes ranking.
- `spa/src/pages/purchasing/suppliers/performance.tsx:56-267` provides a
  permission-gated vendor detail page with KPI cards, QC breakdown, and a trend
  chart. `spa/src/api/purchasing/supplier-performance.ts:7-20` has detail and
  recompute clients only; there is no ranking client or page.
- Focused regression suites exist for ranking, tiers, quality metrics, and
  deterioration alerts under `api/tests/Feature/Purchasing/`.

### Roles and permissions

The API uses `purchasing.suppliers.performance.view` for detail/ranking and
`purchasing.suppliers.performance.recompute` for recompute
(`api/app/Modules/Purchasing/routes.php:86-94`). Both permissions are catalogued
in `api/database/seeders/RolePermissionSeeder.php:222-224`, and the purchasing
officer receives the purchasing module permission set at `:586-601`. The detail
page checks the recompute permission before rendering its button
(`spa/src/pages/purchasing/suppliers/performance.tsx:62-72`). A policy/documentation
mismatch remains: the controller calls recompute “Admin-only”
(`api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php:79-82`)
while the seeded purchasing officer can invoke it.

### Process and state

The snapshot lifecycle is derived measurement, not a workflow state machine:
`overall_score` is calculated and `tier` is derived as A/B/C/D or null
(`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:63-64,99-115`).
There is no `StateMachine::TRANSITIONS` requirement to audit for this module.
The snapshot write is transaction-wrapped and protected by a unique
`(vendor_id, period_year, period_month)` index
(`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:42-44,66-87`;
`api/database/migrations/0130_create_supplier_performance_snapshots_table.php:21-45`).

The repository schema convention is bigint auto-increment primary keys exposed
as HashIDs (`docs/SCHEMA.md:4`); this module follows that convention with
`$table->id()` and `HasHashId`, so no separate ULID finding is raised. Any future
price calculation must use the repository's exact decimal/Money path rather than
floating-point arithmetic; PO unit price and GRN unit cost are decimal fields.

### Verification performed

- PHP syntax check passed for the service, controller, listener, and command.
- `php artisan route:list` confirmed all three supplier performance routes.
- `npm run typecheck` passed.
- ESLint passed for the supplier performance API, types, page, and routes.
- The focused Laravel command selected 25 tests, but every test failed before
  assertions because PostgreSQL host `db` could not be resolved
  (`SQLSTATE[08006]`, `could not translate host name "db"`). This is an
  environment-blocked verification result, not evidence that the tests pass or
  fail on application behavior.

## Findings

### F-001 — Price variance is implemented as receipt shortfall

Classification: **Broken**  
Risk: high; financial scoring  
Evidence: The service documents price variance as the difference between actual
unit cost and PO unit price (`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:28-29`),
but `priceVariancePct()` sums ordered and received quantities and divides the
shortfall by ordered quantity (`:321-337`). The source fields needed for the
documented calculation exist in `api/app/Modules/Purchasing/Models/PurchaseOrderItem.php:17-27`,
`api/database/migrations/0064_create_grn_items_table.php:19-22`, and are populated
as authoritative receipt costs by `api/app/Modules/Inventory/Services/GrnService.php:194-206`.

Impact: a supplier with correct prices but outstanding quantity is penalized as
price variance, while a supplier with an incorrect receipt unit cost is not. The
result feeds the composite score and tier at
`SupplierPerformanceService.php:51,63-82`.

Disposition: replace the metric only after confirming the business denominator,
receipt allocation, currency, and rounding policy. This is a financial change;
use decimal-string/`Money` or BCMath arithmetic and add cent-level fixtures.

### F-002 — In-process and outgoing quality rates cannot be populated

Classification: **Broken**  
Risk: high; cross-module quality semantics  
Evidence: `qualityMetrics()` joins inspections only through GRNs and explicitly
requires `i.entity_type = 'grn'`
(`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:238-247`).
The quality entity contract assigns `grn` to incoming inspection, `work_order`
to in-process inspection, and `delivery` to outgoing inspection
(`api/app/Modules/Quality/Enums/InspectionEntityType.php:10-20`). Outgoing
creation also enforces a `work_order` target
(`api/app/Modules/Quality/Services/InspectionService.php:282-306`). The SPA
nevertheless presents “In-process QC” and “Outgoing QC” cards and describes them
as work-order/pre-shipment inspections
(`spa/src/pages/purchasing/suppliers/performance.tsx:203-227`).

Impact: the stage breakdown is effectively incoming-only; the two visible
metrics remain null or cannot reflect the normal quality process.

Disposition: define how supplier attribution should cross from GRN/vendor to
work-order and delivery inspections, then change the query and fixtures together.
This is a cross-module change and is deferred.

### F-003 — Delivery metrics use GRN grain despite PO-level documentation

Classification: **Broken**  
Risk: medium; score distortion on partial receipts  
Evidence: the service contract describes on-time delivery as POs received in the
month (`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:22-23`),
but `onTimeDeliveryRate()` counts every GRN row with a received date
(`:204-225`). `leadTimeVarianceDays()` likewise averages one value per GRN
(`:340-368`). Multiple/partial GRNs for one PO are supported by the receiving
service (`api/app/Modules/Inventory/Services/GrnService.php:177-223`).

Impact: one PO split across several receipts has multiple votes in both metrics;
the denominator and lead-time average can change based on receipt splitting
rather than supplier-level delivery performance.

Disposition: choose PO-level, receipt-level, or quantity-weighted semantics and
cover partial/multiple receipt fixtures before changing the score.

### F-004 — Quality fallback has no mixed-coverage or partial-acceptance policy

Classification: **Incomplete**  
Risk: medium; score denominator ambiguity  
Evidence: when any inspection row exists, the service returns terminal inspection
results immediately (`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:249-272`);
the GRN fallback is used only when the entire inspection query is empty
(`:275-290`). The fallback counts only status `accepted`, while the GRN schema
also defines `partial_accepted` (`api/database/migrations/0063_create_goods_receipt_notes_table.php:16-21`).

Impact: a period with some inspected and some uninspected receipts silently
omits the uninspected population, and a partially accepted receipt is treated as
not accepted. Whether that is intended is not encoded or tested.

Disposition: document the denominator and partial-acceptance rule, then add
mixed-coverage and partial-acceptance tests. Treat this as part of the separate
quality semantics work.

### F-005 — Ranking puts null scores first and ties by internal vendor ID

Classification: **Broken**  
Risk: medium; API ordering  
Evidence: ranking orders only by descending score and then `vendor_id`
(`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:189-201`).
The authoritative ranking contract requires `overall_score DESC NULLS LAST`,
then `vendor.name ASC`
(`git show 8fdebf71:docs/superpowers/plans/2026-06-15-t3.3-supplier-scorecard-ranking.md:359-363`).

Impact: vendors with no score can appear ahead of scored suppliers on PostgreSQL,
and equal-score ordering changes with database ID rather than the user-visible
vendor name.

Disposition: add null-score and tie fixtures, then implement the explicit order.

### F-006 — Ranking input and response contract are permissive/incomplete

Classification: **Incomplete**  
Risk: medium; API consumer reliability  
Evidence: the controller casts raw `period_year`, `period_month`, and `limit`
values without request validation, and silently drops an invalid tier filter
(`api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php:105-123`).
The response rows omit `period_year`/`period_month`, and `meta` omits `count`
(`:125-145`), although those fields are part of the planned contract cited in
F-005. Existing tests cover a valid period, valid tier, and an oversize limit but
not invalid values or the complete shape
(`api/tests/Feature/Purchasing/SupplierRankingTest.php:47-106`).

Impact: malformed requests can silently query period zero or remove a caller's
tier constraint, and consumers cannot rely on the documented row/meta shape.

Disposition: use explicit validation for year/month/tier/limit and pin the full
response shape with negative and boundary tests.

### F-007 — Invalid periods can be persisted by command/service entry points

Classification: **Incomplete**  
Risk: medium; data integrity  
Evidence: the CLI accepts arbitrary `--year`/`--month` values and casts them
without range checks (`api/app/Console/Commands/RecomputeSupplierPerformance.php:23-37`).
`compute()` normalizes the dates through Carbon but persists the raw year/month
used in the unique key (`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:42-44,66-70`).
The snapshot migration has no database check constraint for month range
(`api/database/migrations/0130_create_supplier_performance_snapshots_table.php:26-28,43-45`).

Impact: a month such as 13 can produce a date window for a different calendar
period while storing an invalid period value, which then does not compose with
trend/ranking queries.

Disposition: validate 1–12 and an agreed year range at every entry point and add
database protection where compatible with existing data.

### F-008 — Deterioration notification link points to a non-existent SPA route

Classification: **Broken**  
Risk: medium; user-facing workflow  
Evidence: the listener emits `/purchasing/vendors/{hash}/performance`
(`api/app/Modules/Purchasing/Listeners/AlertOnSupplierDeterioration.php:59-74`),
while the registered SPA route is
`/purchasing/suppliers/:id/performance`
(`spa/src/routes/purchasingRoutes.tsx:50-52`).

Impact: recipients following a valid deterioration alert land on a 404 or
unmatched route instead of the supplier scorecard.

Disposition: correct the route and add a notification payload/link regression
test.

### F-009 — Alert delivery has no explicit notification idempotency boundary

Classification: **Incomplete**  
Risk: high; queued cross-module side effect  
Evidence: `compute()` records the event after updating the snapshot
(`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:66-88`).
The default outbox dedupe key is derived from the encoded event type/payload
(`api/app/Common/Services/OutboxService.php:29-37`), but notification insertion
has no uniqueness or dedupe check around type/recipient/entity
(`api/app/Common/Services/NotificationService.php:104-145`). The queued listener
rethrows after logging (`api/app/Modules/Purchasing/Listeners/AlertOnSupplierDeterioration.php:75-80`).

Impact: a retry after a partial notification side effect can insert duplicate
inbox alerts. The outbox's event-row dedupe is not a substitute for an explicit
notification idempotency key.

Disposition: define a stable alert identity for vendor/period/threshold event,
make notification delivery retry-safe, and test both duplicate dispatch and
failure-after-insert behavior.

### F-010 — Recompute authorization conflicts with the controller's policy label

Classification: **Incomplete**  
Risk: medium; RBAC policy ambiguity  
Evidence: the controller documents the recompute action as “Admin-only”
(`api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php:79-82`),
but the route gates only on the recompute permission
(`api/app/Modules/Purchasing/routes.php:92-94`) and the purchasing officer gets
that module permission set (`api/database/seeders/RolePermissionSeeder.php:586-601`).

Impact: the effective policy is unclear to operators and reviewers. If the
action is admin-only, purchasing officers can force writes that the documentation
does not allow; if it is buyer self-service, the documentation and role naming
are stale.

Disposition: obtain the product/RBAC decision before changing either the seeder
or the controller/UI wording.

### F-011 — Supplier policy settings are only partially bounded

Classification: **Incomplete**  
Risk: medium; configurable score integrity  
Evidence: `setting()` accepts any numeric non-negative value
(`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:412-419`),
while only weight-sum validation and descending tier-threshold validation are
performed (`:102-114,395-409`). The policy includes neutral score, penalty
factors, targets, and thresholds (`api/database/migrations/0295_seed_supplier_hr_crm_and_quality_sla_settings.php:11-23`).

Impact: an operator can configure a neutral value above 100, thresholds outside
the score domain, or penalty factors that produce unexpected composite values.

Disposition: define allowed domains for each setting and validate them centrally;
add boundary tests before exposing more policy editing.

### F-012 — Cross-vendor ranking has no frontend surface

Classification: **Missing**  
Risk: medium; role usability  
Evidence: the backend route exists and is view-permission-gated
(`api/app/Modules/Purchasing/routes.php:83-91`), but the SPA API client exposes
only `show` and `recompute` (`spa/src/api/purchasing/supplier-performance.ts:7-20`),
the types contain only detail/trend shapes (`spa/src/types/supplierPerformance.ts:5-47`),
and the route table registers only the per-vendor page
(`spa/src/routes/purchasingRoutes.tsx:50-52`).

Impact: purchasing users cannot use the ranking capability through the product
UI; they must open individual vendor pages or call the API directly.

Disposition: add a permission-gated ranking page with period/tier/limit controls,
empty/error/loading states, accessible table semantics, and links to detail.
This is a separate frontend feature, not a same-session patch.

### F-013 — Trend window label is hardcoded and the chart has a weak text fallback

Classification: **Polish**  
Risk: low/medium; configuration and accessibility  
Evidence: the API response includes the configured trend window
(`api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php:58-64`),
but the page always renders “6-month trend”
(`spa/src/pages/purchasing/suppliers/performance.tsx:232-236`). The chart bars are
plain `div` elements with an `aria-label` but no explicit chart/table role or
text summary (`:243-260`). The design system requires information-dense,
accessible UI and keyboard/focus/status discipline
(`docs/DESIGN-SYSTEM.md:19-21,525-532`).

Impact: changing the configured window makes the heading inaccurate, and users
of assistive technology may not receive a reliable textual representation of the
trend.

Disposition: use the response policy/window in the heading and provide a compact
accessible data table or equivalent text alternative.

### F-014 — Existing tests do not protect the high-risk paths

Classification: **Incomplete**  
Risk: high; regression detection  
Evidence: tier tests mostly invoke the private tier helper through reflection and
do not create non-null computed metric inputs to prove `compute()` persists the
derived tier (`api/tests/Feature/Purchasing/SupplierTierTest.php:34-58,86-109`).
Quality tests exercise incoming GRN-linked inspections only
(`api/tests/Feature/Purchasing/SupplierQualityMetricsTest.php:159-175,191-312`).
Ranking tests do not cover null ordering, ties, invalid parameters, or the full
response contract (`api/tests/Feature/Purchasing/SupplierRankingTest.php:54-106`).
Deterioration tests cover one successful send and rethrow behavior but not
duplicate/retry idempotency or the emitted link
(`api/tests/Feature/Purchasing/SupplierDeteriorationTest.php:54-162`).

Impact: the current suite would not catch the price, quality-stage, ranking-sort,
route-link, or duplicate-alert defects identified here.

Disposition: expand regression coverage alongside each separate fix. Re-run
once the test database is available.

### F-015 — Concurrent first computation can race the unique snapshot key

Classification: **Incomplete**  
Risk: medium; operational reliability  
Evidence: `compute()` uses `updateOrCreate()` inside a transaction
(`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:42,66-87`),
while the schema enforces a unique vendor/period key
(`api/database/migrations/0130_create_supplier_performance_snapshots_table.php:43`).
The scheduled command is protected from duplicate schedules, but the HTTP
recompute route and a manual command can still target the same vendor/period.

Impact: two first-time computations can both observe no row and one can surface
a unique-constraint failure instead of converging on the snapshot.

Disposition: add a concurrency regression test and choose an upsert/lock/retry
strategy consistent with the repository's write conventions.

## Passing controls and follow-up boundaries

- The snapshot write is transaction-wrapped and has a database uniqueness guard.
- The event listener is registered and implements `ShouldQueue`
  (`api/app/Providers/AppServiceProvider.php:342-343`;
  `api/app/Modules/Purchasing/Listeners/AlertOnSupplierDeterioration.php:15-25`).
- API routes are ordered so the literal `ranking` route precedes the vendor
  binding route (`api/app/Modules/Purchasing/routes.php:83-94`).
- HashIDs are used at the API vendor boundary; the ranking test explicitly checks
  that raw integer IDs are not returned (`api/tests/Feature/Purchasing/SupplierRankingTest.php:126-138`).
- The existing vendor detail page follows the design system's `PageHeader` and
  `StatCard` number conventions (`spa/src/components/layout/PageHeader.tsx:123-139`;
  `spa/src/components/ui/StatCard.tsx:55-60`).

The dashboard is a downstream consumer and was read only for context; no
dashboard files were audited or modified in this module session.

---

# Re-audit — 2026-09-01

Status entering: `🔁 Needs Re-audit` (M038, Tier 3)
Lock: **RECLAIMED** — orphan lock from `2026-08-25T12:09:11Z`, 118h old.

## Environment verification (done FIRST, before any probe)

Four prior sessions across two modules wrote verification claims from runs that
never executed; the root cause was a stopped compose project. Verified before
probing:

```
$ docker compose ps
ogami-db      postgres:16-alpine   Up 41 minutes (healthy)   5432/tcp
ogami-redis   redis:7-alpine       Up 41 minutes             6379/tcp

$ docker compose exec -T db psql -U ogami -d postgres -c "select 1;"
 ?column?
----------
        1
```

`db` and `redis` were both **running** for the entire session. No containers were
started, stopped, or restarted.

## Real numeric baseline (before any change)

Own database, never the shared `ogami_test`:

```
$ docker compose exec -T db psql -U ogami -d postgres \
    -c "CREATE DATABASE ogami_test_supperf OWNER ogami;"
CREATE DATABASE

$ docker compose run --rm -e DB_DATABASE=ogami_test_supperf api php artisan test \
    tests/Feature/Purchasing/SupplierRankingTest.php \
    tests/Feature/Purchasing/SupplierTierTest.php \
    tests/Feature/Purchasing/SupplierQualityMetricsTest.php \
    tests/Feature/Purchasing/SupplierDeteriorationTest.php --no-coverage

  Tests:    29 passed (69 assertions)
  Duration: 35.47s
```

**Baseline = 29 passed / 0 failed / 69 assertions.** Assertions are non-zero, so
this is a real run, not a poisoned one.

Harness note worth recording: `php artisan test api/tests/...` (repo-relative
path) prints `Test file "…" not found` **and exits 0**. Paths must be
container-relative (`tests/Feature/...`). A session that used the repo-relative
form would see a green exit with zero tests run — the same phantom-verification
shape the pipeline has already been bitten by four times.

## Prior-work assessment

This is the *good* case, with one important qualification.

- The 2026-08-25 session's code **is committed** — swept into
  `167de85e` ("chore: remaining uncommitted work from ~50 crashed audit
  sessions"). `git status --porcelain` over all module paths is **clean**; every
  file it claims to have written exists.
- Its `fix-log.md` was **honest**, not fabricated. It explicitly recorded that
  its focused Laravel run "selected 24 module tests but every case stopped in
  `RefreshDatabase` because PostgreSQL host `db` could not be resolved
  (`SQLSTATE[08006]`)" and that "database-backed fixes are therefore not
  runtime-verified in this environment." This is the opposite of the
  `separation-final-pay` failure mode.
- **Its unverified claims now verify.** The 29/0 baseline above is the first
  actual execution of that work. Every fix it claimed is confirmed below.

### Prior findings — reproduction status

Verified by probe, not by reading the log.

| Finding | Prior disposition | Re-audit result |
|---|---|---|
| F-001 price variance is quantity shortfall | deferred | **STILL REPRODUCES** — `SupplierPerformanceService.php:375-392` still sums `poi.quantity`/`poi.quantity_received`; docblock at `:29` still claims unit-cost variance |
| F-002 in-process/outgoing quality unpopulatable | deferred | **STILL REPRODUCES** — `:293-301` still requires `i.entity_type = 'grn'` |
| F-003 delivery metrics at GRN grain | deferred | **STILL REPRODUCES** — `:258-280`, `:394-423` still one vote per GRN |
| F-004 no mixed-coverage / partial-acceptance policy | deferred | **STILL REPRODUCES** — `:340` counts only `'accepted'`; `GrnStatus::PartialAccepted` exists |
| F-005 ranking null-first / id tie-break | fixed | **CLOSED** — `:231-235` orders `CASE WHEN score IS NULL` → score DESC → `vendors.name` → `vendor_id`; test green |
| F-006 ranking contract permissive | fixed | **CLOSED** — `SupplierRankingRequest.php` validates year/month/tier/limit; controller consumes `validated()` only; `meta.count` present at `:139` |
| F-007 invalid periods persistable | fixed | **CLOSED** — `validatePeriod()` at `:243-256` on all three entry points; CLI at `RecomputeSupplierPerformance.php:39-62`; **DB CHECK constraint confirmed present** (see below) |
| F-008 deterioration link 404s | fixed | **CLOSED** — `AlertOnSupplierDeterioration.php:66-68` emits `/purchasing/suppliers/{hash}/performance`, matching `purchasingRoutes.tsx:109` |
| F-009 no notification idempotency boundary | deferred | **STILL REPRODUCES** (not re-probed at runtime this session) |
| F-010 recompute RBAC vs "Admin-only" label | deferred | **STILL REPRODUCES** — controller docblock `:82` says Admin-only; `purchasing_officer` holds the slug (measured, below) |
| F-011 policy settings only partially bounded | deferred | **STILL REPRODUCES** — `setting()` at `:466-473` accepts any non-negative numeric |
| F-012 no ranking frontend | fixed | **CLOSED** — client `ranking()` in `spa/src/api/purchasing/supplier-performance.ts`; page `spa/src/pages/purchasing/suppliers/ranking.tsx`; route `purchasingRoutes.tsx:101` → `SupplierRankingPage` |
| F-013 hardcoded trend window / weak fallback | fixed | **CLOSED** — table alternative present at `performance.tsx:240-311` |
| F-014 tests do not protect high-risk paths | partial | **PARTLY REPRODUCES** — ranking/tier/period/link now covered and green, but see NEW-02: the quality test's terminal-empty case never asserts the composite |
| F-015 first-computation race | fixed (code) | **CODE CLOSED** — `:98-108` is a real DB `upsert()` on the unique key; `repeated compute keeps one snapshot for a vendor period` green. Two-connection race NOT probed (see Not verified) |

**8 of 15 prior findings closed; 7 still reproduce** (F-001, F-002, F-003, F-004,
F-009, F-010, F-011), all of which were knowingly deferred as commercial or
cross-module decisions. No prior fix was found to be falsely claimed.

## Findings — new this session

### NEW-01 — A missing metric is scored **0**, not neutral, for two of five inputs

Classification: **Broken**
Risk: **high** — this is the module's characteristic failure: a confidently wrong tier.

`compositeScore()` (`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:430-464`)
returns a score whenever *either* `on_time` or `quality` is non-null:

```php
if ($onTime === null && $quality === null) { return null; }   // :437-439
$onTimeScore  = $onTime  ?? 0;                                 // :442
$qualityScore = $quality ?? 0;                                 // :443
$neutral = $this->setting('purchasing.supplier_score.neutral_missing_metric');
$ncrScore      = $ncrRate  === null ? $neutral : …             // :445
$priceScore    = $price    === null ? $neutral : …             // :446
$leadTimeScore = $leadTime === null ? $neutral : …             // :447
```

Three metrics honour the seeded `neutral_missing_metric` policy (**50**); the two
**most heavily weighted** ones (on-time 25%, quality 35% — 60% of the composite)
silently substitute **0**. "No data" is therefore scored identically to "total
failure" for 60% of the score, and the API cannot distinguish them: the snapshot
stores `quality_pass_rate = NULL` next to an `overall_score` that already
penalised that NULL as a zero.

Both arms are reachable in ordinary operation:

- **quality NULL, on-time present.** `qualityMetrics()` returns early at
  `:303-327` as soon as *any* GRN-linked inspection row exists. If none of them
  is terminal, `passRate` is NULL (`:309-311`) and the GRN-status fallback at
  `:330-344` is **never reached**. So a vendor whose incoming inspection is
  merely still `draft`/`in_progress` loses 35% of its score outright.
- **on-time NULL, quality present.** `onTimeDeliveryRate()` returns NULL when
  every receipt's PO has no `expected_delivery_date` (`:272`, `:279`). A vendor
  whose POs simply carry no promised date loses 25% of its score.

Arithmetic against the seeded policy (measured from `settings`: neutral=50,
weights .25/.35/.10/.15/.15, ncr factor 2, price factor 2, lead factor 5), using
the **repo's own existing fixture** in
`test_only_non_terminal_inspections_does_not_divide_by_zero` (one GRN received
2026-01-15, PO dated 2026-01-05 expecting 2026-01-20, one `draft` inspection, no
PO items):

| treatment of the NULL quality input | composite | tier |
|---|---|---|
| **as shipped (`?? 0`)** | 100(.25) + **0**(.35) + 100(.10) + 50(.15) + 75(.15) = **53.75** | **D** |
| neutral 50, as the other three metrics | 71.25 | C |
| excluded, weights renormalised over 0.65 | 82.69 | B |

A vendor with a perfect delivery record and an inspection that has not been
closed yet is stamped **D — the worst tier** — when the same policy applied
consistently yields **C**, and excluding the unknown yields **B**.

**Status: computed, not yet runtime-confirmed.** The three numbers above are
derived by hand from the code path and the measured `settings` rows; the
end-to-end assertion against a persisted snapshot was still outstanding when this
session was interrupted. The reachability of both arms is read off the control
flow and is not in doubt; the exact 53.75 should be confirmed by probe before it
is quoted to purchasing.

**This is NOT to be fixed unilaterally.** Consistency with the module's own
declared `neutral_missing_metric` policy is the *likely* intent, but choosing
between neutral-substitution and weight-renormalisation moves every tier
boundary and therefore decides which suppliers get business. Escalated as
QUESTION-1.

### NEW-02 — The quality metric's empty-denominator test never reaches the composite

Classification: **Incomplete**
Risk: medium — this is why NEW-01 survived a green suite.

`api/tests/Feature/Purchasing/SupplierQualityMetricsTest.php:296-317`
(`test_only_non_terminal_inspections_does_not_divide_by_zero`) calls
`compute()` and then asserts **only**:

```php
$this->assertNull($snapshot->quality_pass_rate, …);
```

It never asserts `overall_score` or `tier`, so the branch that converts that NULL
into a 0-weighted-at-35% score is executed on every run and checked by nothing.
This is the same shape as the analytics row-mapping branch a quality session
found: *the test asserts the empty case and stops*, so the interesting arm is
covered in name only.

Its explanatory comment is also wrong on two counts: it states the pass rate is
NULL "because the GRN fallback gives null because the GRN status is `pending_qc`
not `accepted`". The fallback is **not reached** (the early return at `:326`
fires first), and had it been reached, `pending_qc` would have produced
`round((0/1)*100, 2) = 0.0`, not NULL. The test passes for a different reason
than it documents.

### NEW-03 — Soft-deleted purchase orders and PO items still enter the score

Classification: **Broken**
Risk: medium — archived rows changing a live number is a measured pattern in this repo.

`purchase_orders` and `purchase_order_items` both carry `deleted_at` (confirmed
against `information_schema.columns`), and `PurchaseOrder` uses `SoftDeletes`
(`api/app/Modules/Purchasing/Models/PurchaseOrder.php:26`). Every consumer in
this service reaches them through **`DB::table()`**, which does not apply the
global scope:

- `priceVariancePct()` — `:377-385`
- `po_count` — `:60-63`
- `onTimeDeliveryRate()` join to `purchase_orders` — `:260-265`
- `leadTimeVarianceDays()` join to `purchase_orders` — `:396-401`

An archived PO therefore still contributes ordered quantity to the price metric,
still occupies a slot in `po_count`, and still supplies the `expected_delivery_date`
that decides on-time. This is the class that produced ₱111-vs-₱999 in
`journal-ledger` and a 500 on an archived customer in AR.

`goods_receipt_notes` and `inspections` have **no `deleted_at`** (verified via
`information_schema`), so the GRN/inspection arms of this question are **N/A**,
not unverified.

**Status: identified from schema + code; runtime probe outstanding.**

### NEW-04 — A soft-deleted vendor keeps its ranking slot, with a null identity

Classification: **Broken**
Risk: medium — an archived supplier displacing a live one in a ranking table.

`ranking()` (`:220-236`) mixes two different soft-delete behaviours on the same
vendor:

```php
->leftJoin('vendors', 'supplier_performance_snapshots.vendor_id', '=', 'vendors.id')
->with('vendor:id,name')
```

The `leftJoin` is raw SQL and does **not** filter `vendors.deleted_at`, so the
archived vendor's snapshot is still selected, still ordered, and still consumes
one of the `limit` rows. The eager load **does** apply `SoftDeletes`, so
`$s->vendor` resolves to `null` — and the controller then emits
`'vendor' => ['id' => null, 'name' => null]`
(`SupplierPerformanceController.php:120-124`). The result is a ranked row with a
score, a tier and PO/GRN counts but no supplier identity.

Note the same join is the `ORDER BY vendors.name` source (`:233`), so an archived
vendor also sorts on a name the response will not show.

`recomputeAll()` is **not** affected — it uses `Vendor::query()` (`:180`), which
does apply the scope.

**Status: identified from code; runtime probe outstanding.**

### NEW-05 — No purchase-order status filter anywhere in the score

Classification: **Incomplete** (raised as a QUESTION, not fixed)
Risk: medium

Neither `priceVariancePct()` (`:377-385`) nor the `po_count` query (`:60-63`)
constrains `purchase_orders.status`. `PurchaseOrderStatus` has eight cases
including `Draft`, `PendingApproval` and `Cancelled`. A **cancelled** PO retains
its ordered quantity and has `quantity_received = 0`, so it lands in the
shortfall numerator as a 100% "price variance" and, at the seeded
`price_penalty_factor = 2`, drives `priceScore` to `max(0, 100 - 200) = 0` —
15% of the composite zeroed by an order the supplier was told not to fill.

Which statuses should count is a commercial decision, so this is escalated
(QUESTION-2) rather than changed. Recorded here because "which rows count" is
the exact question the brief asks this module to pin down, and today the answer
is **all of them, including drafts and cancellations**.

### NEW-06 — `lead_time_variance_days` can overflow `numeric(5,2)`

Classification: **Incomplete**
Risk: low (narrow trigger, but an unhandled 500)

`lead_time_variance_days` is `numeric(5,2)` — maximum **999.99** (confirmed
against `\d supplier_performance_snapshots`). `leadTimeVarianceDays()` computes a
signed day difference with no clamp (`:415-422`), and the service's own comment
at `:410-414` documents that neither `expected_delivery_date` nor `received_date`
is constrained relative to the PO date. A PO whose expected date is mis-keyed by
more than ~2.7 years yields a variance the column cannot hold, and `compute()`
raises a PostgreSQL numeric-overflow error inside its transaction — surfacing as
a 500 on the recompute endpoint and as a per-vendor entry in `recomputeAll()`'s
failure list.

**Status: identified from schema + code; overflow not yet triggered at runtime.**

### NEW-07 — Dev database is missing the F-007 period CHECK constraint

Classification: **Not a code defect** — environment drift, recorded so the next
session does not re-discover it.

```
$ psql -d ogami_test_supperf -c "select conname, pg_get_constraintdef(oid) …"
supplier_performance_snapshots_period_check |
  CHECK (period_year >= 2000 AND period_year <= 2100
         AND period_month >= 1 AND period_month <= 12)

$ psql -d ogami       -c "select conname … contype='c'"
(0 rows)

$ psql -d ogami       -c "select migration from migrations
                          where migration like '%guard_supplier_performance%'"
(0 rows)
```

The migration `2026_08_25_180000_guard_supplier_performance_periods` has **run in
a fresh test database and its constraint is present and correct**; it has simply
never been run against the long-lived dev `ogami` database. Consistent with the
known "dev DB drifts from seeders" behaviour. The prior session's F-007 fix is
sound; the dev database is stale. No action taken (running migrations against
dev is outside this module's surface and other sessions share that database).

## Controls confirmed PASSING this session

Each measured, not assumed.

- **Route ordering / `/vendors/ranking` is not param-bound.** `routes.php:86`
  declares the literal `/vendors/ranking` **before** `/vendors/{vendor}/performance`
  at `:89` and `/vendors/{vendor}/performance/recompute` at `:92`, with a comment
  recording why. `SupplierRankingTest::ranking defaults to previous calendar
  month` and four sibling ranking cases resolve the literal route and pass — a
  param-bound `ranking` would 404 on vendor lookup instead.
- **Divide-by-zero is guarded on every ratio** (code-read, all five):
  `onTimeDeliveryRate` `$total > 0` (`:279`); `qualityMetrics` `$terminalCount > 0`
  (`:309`, `:319`) and `$grnRows->isEmpty()` (`:336`); `ncrRate`
  `$totalGrns === 0 → null` (`:359`); `priceVariancePct` `qty <= 0 → null`
  (`:387`); `leadTimeVarianceDays` `empty($diffs) → null` (`:420`). No unguarded
  divisor found. `test_only_non_terminal_inspections_does_not_divide_by_zero`
  passes. No AR-style `DivisionByZeroError` exists in this module.
- **Zero-history vendor returns NULL, not 0, at the metric level.** Every metric
  returns `null` on an empty base, and `compositeScore` returns `null` when
  on-time *and* quality are both absent (`:437-439`), so `tier` is `null`
  (`:131`) — the docblock's "vendors with no data don't get a synthetic letter"
  holds. `SupplierTierTest::tier is null when overall score is null` passes.
  **The distinction breaks only in the mixed case** — NEW-01.
- **On-time boundary semantics** (code-read at `:271-277`): reference date is the
  PO's `expected_delivery_date` (promised, never revised — there is no revised
  column). `->lte()` means **delivered exactly on the promised date counts as
  on time**, and an **early receipt counts as on time**. A receipt whose PO has
  **no promised date is excluded from both numerator and denominator**
  (`continue` at `:272`) rather than counted as late — the honest choice. A
  **partial receipt counts as a full independent vote** (GRN grain) — that is
  prior finding F-003, still open.
- **Recompute exit codes distinguish "nothing to do" from "everything threw."**
  `RecomputeSupplierPerformance::handle()` returns `self::FAILURE` whenever
  `$result['failed'] !== []` and echoes each vendor's error (`:78-85`);
  `recomputeAll()` collects per-vendor failures instead of swallowing them
  (`:185-198`). Zero vendors gives `computed=0 failed=0` → `SUCCESS`, which is a
  truthful "nothing to do". This module does **not** have the 8D-SLA
  false-green defect.
- **Permissions — every registry role can reach what it needs.** Measured in
  `RolePermissionSeeder`: `purchasing.suppliers.performance.view` and
  `.recompute` are both catalogued in the `purchasing` bucket (`:240-241`);
  `purchasing_officer` takes `module('purchasing', except: ['purchasing.po.sod_override'])`
  (`:637`) so it holds **both**; `finance_officer` is granted `.view` explicitly
  and only `.view` (`:549`); `system_admin` is wildcard. The registry row
  (`system_admin, finance_officer, purchasing_officer`) is satisfied — no route
  is gated on a slug no role holds.
- **HashIDs at the API boundary.** `hash_id` on vendor detail
  (`Controller:39`), ranking rows (`:122`) and recompute (`:91`); the snapshot's
  own id is exposed as `hash_id` in the alert payload
  (`AlertOnSupplierDeterioration:73`). `SupplierRankingTest::ranking returns
  hash id never raw id` passes.
- **No float/`round()` on money.** The five metrics are percentages and day
  counts in `numeric(5,2)`, not money; this service performs **no** monetary
  arithmetic at all (`priceVariancePct` divides *quantities*, which is precisely
  prior finding F-001). Rounding is `round(x, 2)` at every metric and
  `round($score, 2)` for the composite — PHP's default half-away-from-zero, and
  since every value is non-negative that is **half-up, consistently applied**.
  Direction is therefore defined, if not documented.
- **SPA money/number handling is safe here.** `spa/src/pages/purchasing/suppliers/performance.tsx:140`
  uses `latest?.overall_score ? Number(...) : null`, which would be a
  falsy-zero bug — except the model's `decimal:2` cast serialises the score as
  the **string** `"0.00"`, which is truthy in JS. Checked explicitly; a genuine
  zero score renders as `0.0`, not as "no data". `Number()` here is on a score,
  not on money, so the four-module `Number(x)`-on-money defect does not apply.
- **No NCR aggregate over `NonConformanceReport::actions()`.** `ncrRate()`
  (`:352-373`) queries `non_conformance_reports` through `DB::table()` with joins
  and no `GROUP BY`, so the `orderBy('performed_at')` / `->reorder()` trap
  (`SQLSTATE[42803]`) **cannot fire** in this module. Verified by reading every
  NCR touchpoint in the service — there is exactly one.
- **F-012's dead-surface risk is closed in both directions.** Backend
  `/vendors/ranking` now has a client (`supplier-performance.ts` `ranking()`), a
  typed response, a page (`pages/purchasing/suppliers/ranking.tsx`) and a
  permission-gated route (`purchasingRoutes.tsx:101`); the per-vendor page at
  `:109` matches the path the deterioration alert now emits.

## Questions for a human (not decided by this session)

- **QUESTION-1 (NEW-01, blocking).** When `on_time_delivery_rate` or
  `quality_pass_rate` is unknown, should the composite (a) substitute the seeded
  `neutral_missing_metric` = 50, as it already does for NCR/price/lead-time,
  (b) drop the metric and renormalise the remaining weights, or (c) keep the
  current 0 — i.e. deliberately treat "not measured" as "failed"? On the repo's
  own fixture these give tier **C / B / D**. Purchasing must choose; it moves
  every tier boundary.
- **QUESTION-2 (NEW-05).** Which `purchase_orders.status` values should enter
  `price_variance_pct` and `po_count`? Today `draft`, `pending_approval` and
  `cancelled` all count, and a cancelled PO reads as a 100% price variance.
- **QUESTION-3 (F-010, carried).** Is recompute purchasing-officer self-service
  (what the seeder does) or admin-only (what
  `SupplierPerformanceController.php:82` says)? One of the two is wrong.

## Not verified (stated plainly)

Interrupted mid-flight; these were planned and not reached.

- Runtime confirmation of the NEW-01 arithmetic (53.75 / tier D) against a
  persisted snapshot. Derived by hand from code + measured settings.
- Runtime probes for NEW-03 (soft-deleted PO), NEW-04 (soft-deleted vendor in
  ranking) and NEW-06 (`numeric(5,2)` overflow). All three are identified from
  schema and control flow, not yet executed.
- **Ranking tie determinism under both insertion orders.** The ordering clause is
  fully deterministic *by construction* (score → `vendors.name` → `vendor_id`,
  a unique final key) and the existing tie test passes, but the
  insert-in-both-orders probe the brief asks for was not run.
- Two-connection concurrent first-computation race (F-015). The `upsert()` is the
  right shape and the repeated-compute test is green; a genuine two-connection
  probe is subject to the `RefreshDatabase` visibility artifact and the deadlock
  a prior session correctly abandoned.
- Live HTTP 403 probes per endpoint (including any export). Permissions were
  verified from the seeder and the route middleware, not by issuing requests as
  each role. **There is no export endpoint on this surface** to gate.
- Quality PPM against real defect rows: this module has **no PPM metric**; its
  quality inputs are inspection pass rate and NCR rate. The NCR arm was not
  exercised against real NCR rows this session.
- `docs/USER-MANUAL.md` cross-check for documented-but-unbuilt features.

---

## Runtime measurements — 2026-09-01 (second half of session)

Everything marked "probe outstanding" above was subsequently executed against
real PostgreSQL rows. **Every hypothesis confirmed.** The probe was a scratch
file (deleted); the durable cases live in
`api/tests/Feature/Purchasing/SupplierScorecardInvariantsTest.php`.

Mid-session the compose project was torn down again (`ogami-db` and
`ogami-redis` both `Exited (255)` at the same moment — the same event that
interrupted this session). Only those two services were restarted
(`docker compose start db redis`); PostgreSQL then needed ~65s of crash
recovery before accepting connections. No other container was touched and
`docker compose down` was never run.

### NEW-01 — confirmed exactly, including the tier

`[PROBE] onTime='100.00' quality=NULL ncr='0.00' price=NULL lead='-5.00'
SCORE='53.75' TIER='D'`

The hand arithmetic was exact. A vendor with a **100% on-time delivery record**,
a **0% NCR rate**, and one incoming inspection still sitting in `draft` is
stamped **tier D — the worst tier the system has**. Applying the module's own
`neutral_missing_metric` consistently gives 71.25 (**C**); renormalising gives
82.69 (**B**).

The mirror arm also confirmed: `onTime=NULL quality='100.00' SCORE='60.00'
TIER='C'` — a vendor with a perfect quality pass rate whose POs simply carry no
promised date loses a flat 12.5 points.

Worst measured case: a single receipt with no promised date and QC not yet done
scored **25.00 / D** (`onTime=NULL quality='0.00' → SCORE='25.00'`).

### NEW-02 — the falsified comment, now measured

`[PROBE P4a] onTime='100.00' quality='0.00'` — with one `pending_qc` GRN and no
inspections, the GRN-status fallback returns **`0.00`**, not `NULL`. This
directly falsifies the existing test's comment, which asserts the fallback
"gives null because the GRN status is `pending_qc` not `accepted`". Measured: it
gives `0.00`. (In the test's own fixture the fallback is not reached at all.)

### NEW-03 — measured leak, then measured fix

| | `price_variance_pct` | `po_count` | archived PO leaked |
|---|---|---|---|
| live PO only | `0.00` | 1 | — |
| **+ one soft-deleted PO, before fix** | **`50.00`** | **2** | **true** |
| + same soft-deleted PO, after fix | `0.00` | 1 | false |

### NEW-04 — measured leak, then measured fix

Before: `ranking returned 2 row(s)` — `vendor_id=10 score='95.00'
vendor_relation=NULL name=NULL` ranked **first**, ahead of the live
`AAA Live Vendor` on 70.00. After: `ranking returned 1 row(s)`, archived vendor
absent, `vendor_relation=loaded`.

### NEW-06 — measured overflow, then measured score-neutral clamp

Before: `SQLSTATE[22003]: Numeric value out of range … A field with precision 5,
scale 2 must round to an absolute value less than 10^3` — the raw value was
`2206` days and it aborted the whole snapshot.
After: `lead='999.99' SCORE='17.50'`. The score is **identical** to what the
unclamped value would have produced, because `max(0, 100 - |v| × 5)` is already
0 at 20 days. Asserted in the test so the claim cannot rot.

### F-004 — confirmed against real rows

Two GRNs, one `accepted` and one `partial_accepted`, no inspections:
`quality='50.00'`. A **partially accepted receipt scores as a total quality
failure**. Still open (cross-module policy).

### NCR rate — confirmed against real NCR rows, no `42803`

One `failed` incoming inspection with a real `inspection_fail` NCR row:
`incoming='0.00' ncr='100.00' SCORE='43.75' TIER='D'`. The quality metric does
derive from real inspection rows and the NCR metric from real NCR rows — neither
is a constant, and the per-stage `incoming` breakdown does populate. No
`SQLSTATE[42803]` is reachable here: this module never aggregates over
`NonConformanceReport::actions()`.

Note `ncr_rate` is unbounded above (one NCR per GRN = 100%; several per GRN
exceeds 100%), and at the seeded `ncr_penalty_factor = 2` any rate at or above 50%
floors that component. Not a defect, but the metric is a ratio of NCRs to
receipts, not a percentage of receipts with an NCR — worth stating.

### NEW-08 — the same archived-vendor leak exists DOWNSTREAM (outside this module)

Classification: **Broken**, in the **Dashboard** module — **reported, not touched**.

`api/app/Modules/Dashboard/Services/PurchasingDashboardService.php:142-143`
joins `vendors` to `supplier_performance_snapshots` with **no `deleted_at`
guard**, so the purchasing dashboard's supplier widget still exhibits exactly the
NEW-04 leak this session fixed in `ranking()`. In addition,
`DashboardWidgetDataService.php:445` and `KpiSnapshotService.php:451` both
`avg('overall_score')` across every snapshot for the period without excluding
archived vendors, so the "average supplier score" KPI includes suppliers the
business has retired.

Out of surface for M038 — Dashboard belongs to another module's owner. No
Dashboard file was read beyond these three lines or modified.

## Scorecard invariant results — executed

| Invariant | Probe | Measured result |
|---|---|---|
| Vendor with zero POs / receipts / inspections | `test_vendor_with_no_history_returns_null_not_zero` | Every metric, `overall_score` and `tier` **NULL**; `po_count=0 grn_count=0`. No throw. "No history" **is** distinguishable from "scored zero" |
| Divide-by-zero on every ratio | same + code audit of all five | All guarded (`$total>0`, `$terminalCount>0`, `$totalGrns===0`, `qty<=0`, `empty($diffs)`). **No `DivisionByZeroError` reachable** |
| "No history" vs "scored zero" in the **mixed** case | NEW-01 probe | **FAILS** — a NULL on-time or quality is scored as **0** at 25%/35% weight. Score 53.75/D measured. Unresolved, QUESTION-1 |
| Which statuses enter each metric | code audit + NEW-05 | Quality: inspections `passed`/`failed` only (terminal), `entity_type='grn'` only. NCR: `source='inspection_fail'`. GRN fallback: `accepted` only. **PO: no status filter at all** — drafts and cancellations count |
| Soft-deleted **vendor** vs ranking | `test_ranking_excludes_soft_deleted_vendors` | Leaked (ranked 1st, null identity) → **FIXED**, 1 row |
| Soft-deleted **PO** vs every metric | 3 cases in the new test | Leaked into price, `po_count`, on-time, lead-time → **FIXED** |
| Soft-deleted **PO item** | `test_soft_deleted_purchase_order_item_…` | Leaked → **FIXED** |
| Soft-deleted **GRN / inspection** | `information_schema` | **N/A** — neither table has `deleted_at` |
| On-time boundary: delivered **exactly** on promised date | `test_receipt_exactly_on_promised_date_…` | `100.00` — **on time** (`lte`). Locked |
| Early receipt | `test_early_receipt_is_on_time_…` | On time; one early + one late = `50.00`. Locked |
| Partial receipt | code audit (F-003) | Counts as a **full independent vote** — GRN grain. Still open |
| Receipt with **no** promised date | `test_receipt_with_no_promised_date_…` | Excluded from **both** sides → `NULL`, **not** counted late. Locked |
| Quality metric against real defect rows | NCR probe | `incoming='0.00' ncr='100.00'` from a real failed inspection + real NCR row. Not a constant. **No PPM metric exists in this module** |
| Test actually reaches the row-mapping branch | NEW-02 | **NO** — the terminal-empty test asserts only `quality_pass_rate`, never `overall_score`. That is why NEW-01 survived a green suite |
| NCR aggregate `->reorder()` / `42803` | code audit + probe | **Cannot fire** — no `GROUP BY` over `actions()` anywhere in this module |
| Ranking tie determinism, **both** insertion orders | 2 cases | Zeta-first and Alpha-first both → `AAA Alpha Co | ZZZ Zeta Co`. Deterministic. Labelled pass-either-way lock |
| Money / percentage rounding direction | code audit | **No money arithmetic in this service.** All metrics `round(x, 2)`, composite `round($score, 2)`; values non-negative so half-up, consistently applied. Direction defined, undocumented |
| `/vendors/ranking` not param-bound | `routes.php:86` vs `:89` + 7 green ranking tests | Literal declared **first**; resolves correctly |
| Permission gate per endpoint incl. export | `RolePermissionSeeder` + route middleware | `.view` on detail+ranking, `.recompute` on recompute. **No export endpoint exists.** Live per-role 403 probes NOT run |
| Every registry role can reach what it needs | seeder read | `purchasing_officer` both slugs via `module('purchasing')`; `finance_officer` `.view` only (`:549`); `system_admin` wildcard. **Satisfied** |
| No supplier-facing surface exposes another vendor's score | grep of portal routes/controllers | **No supplier-portal surface references** `SupplierPerformanceSnapshot`, the service, or the snapshot table (see referencer list in fix-log). No cross-vendor score leak |
| Raw-id-free error bodies | validation + binding behaviour | `SupplierRankingRequest` returns 422 field errors only; `{vendor}` binding 404s via `HasHashId::resolveRouteBinding` with no id echo. No `{"id":<pk>}` oracle found |
| Scheduled recompute distinguishes "nothing to do" from "everything threw" | code audit of command + service | **YES** — `recomputeAll()` collects per-vendor failures; command returns `FAILURE` if any, prints each error. Zero vendors → `computed=0 failed=0` → truthful `SUCCESS`. Command NOT executed against seeded data this session |
| Lead-time overflow → 500 | `test_extreme_lead_time_variance_…` | `SQLSTATE[22003]` before → clamped `999.99`, score unchanged at `17.50`, after |
