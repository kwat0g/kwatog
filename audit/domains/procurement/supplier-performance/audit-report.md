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
