# DSS Findings Register (separate track — own PRs, never tangled into DSS features)

Source: Phase 0 static analysis 2026-10-12. Status `OPEN` unless noted. Line numbers are
pointers — re-confirm before fixing. Each item: location, what's wrong, why it matters to
decisions, proposed fix track (S = small, M = medium).

## F-01 Supplier `price_variance` measures fill-rate, not price [data-semantics, HIGH]

- Location: `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:444-469`
  (composite weights `:532-566`).
- `price_variance_pct` = receipt shortfall `(ordered−received)/ordered×100`. A vendor raising prices
  20% with full delivery scores perfectly; buyer decisions on this scorecard are price-blind.
- Impact: supplier-selection DSS built on this column inherits the lie.
- Fix (S/M): add true unit-price variance vs `approved_suppliers.last_price` at receipt time; keep
  shortfall metric renamed to `fill_rate_shortfall_pct`. Backfill from `po_items`/`grn_items` prices.

## F-02 Three demand definitions disagree for the same item [consistency, HIGH]

- Locations: `Inventory/Services/AutoReplenishmentService.php:220-226`
  (`material_issue+delivery+scrap`) vs `SafetyStockRecomputeService` (`material_issue+delivery+
  adjustment_out+scrap+return_to_vendor`) vs `Forecasting/Services/StockOutProjectionService.php:99-108`
  (`material_issue|consume|production_issue`).
- Same item gets three different "daily demand" numbers → replenishment, safety stock, and projection
  can contradict each other on the same screen.
- Fix (S): one `DemandBasis` value object / settings key `inventory.demand.movement_types` consumed by
  all three. DSS Wave 0 prerequisite.

## F-03 `reorder_date` comment/code mismatch; forecast-join fragility [correctness, MEDIUM]

- Location: `Forecasting/Services/StockOutProjectionService.php:122-123` (comment claims +1 buffer day;
  code is `days−leadTime`), join `items.code == products.part_number` (`:78-81`).
- Raw-material codes never match → always historical fallback; no warning surfaced.
- Fix (S): align code/comment; add explicit `demand_source: forecast|historical|none` to the API payload
  (DSS consumes this as the sufficiency flag).

## F-04 Alert-engine measurement quirks [correctness, MEDIUM]

- Location: `api/app/Common/Services/AlertEngineService.php` — WO overdue uses `abs(diff)` (`:448`,
  1h and 100h overdue both `Warning`); AP `dueInDays` absolute (`:605`); inventory pass mixes
  `SUM(quantity)` then overwrites with `usable_quantity` (`:301-317`); OEE check named
  `OeeBelowThreshold` is quality-rate-only (`:525`); molds with `max_shots=null/0` never alert.
- Fix (S, batched PR): severity by overdue magnitude; rename OEE-proxy or add availability/performance;
  alert on unbounded molds as master-hygiene instead of skipping silently.

## F-05 Bottleneck clock resets on any touch [correctness, MEDIUM]

- Location: `api/app/Common/Services/ChainBottleneckService.php` — `hours_stuck` from `updated_at`
  (any comment/view resets the stuck clock); `invoice_draft_overdue`/`pr_pending` use `updated_at`
  not `created_at`; bill detector relies on `three_way_match_snapshot->overall_status` (legacy null
  snapshots invisible).
- Fix (S): stuck-clock from `created_at` (or first-detected ledger); backfill legacy snapshots.

## F-06 Safety-stock writes SS=0, uncapped, unaudited [robustness, MEDIUM]

- Location: `Inventory/Services/SafetyStockRecomputeService.php:65-68` (`saveQuietly`, no events).
- Flat demand wipes the buffer to zero; demand spike writes huge SS (over-ordering until next run);
  changes untraceable.
- Fix (S): floor (keep max(computed, prior×0.5)? — needs owner default), cap (e.g. 3× prior or
  `max_cover_days`), audit the writes.

## F-07 Auto-PR `department_id=null` bypasses budget ownership [governance, MEDIUM]

- Location: `Inventory/Services/AutoReplenishmentService.php:139-150` (MRP PRs carry PPC dept).
- Null-department PRs skip department budget checks that manual PRs face.
- Fix (S): attribute owning department (requesting/stock-owning) or route through explicit budget-ack step.

## F-08 Predictive-maintenance dedup by description substring [robustness, LOW]

- Location: `Maintenance/Services/PredictiveMaintenanceService.php:258` (open-MWO match on `[Predictive]`
  in description).
- Editing the description breaks dedup → duplicate corrective MWOs (machine lock bounds the race).
- Fix (S): `source` enum/nullable `source_reading_id` on MWO instead of string match.

## F-09 Arbitrary approver pick; no load-balancing [fairness/ops, LOW]

- Location: `api/app/Common/Services/ApprovalService.php` or `ApprovalEscalationService` —
  `resolveCurrentApprover` picks `orderBy(id)` first active holder.
- Same person gets every escalation in multi-holder roles.
- Fix (S): round-robin or least-pending pick; record pick reason.

## F-10 AR dunning marks `sent` on queue-accept, not delivery [reliability, LOW]

- Location: `Accounting/Services/ArDunningService.php:118-121` + tier-as-days-int (`:160`,
  CSV reorder changes semantics).
- Transient mail-queue failure after commit still advances the tier claim; reordering
  `tier_days_csv` rewrites history meaning.
- Fix (S): confirm on mail-sent event or keep `claimed` vs `delivered` states; tier config as stable
  key→days map.

## F-11 ABC classification has no schedule [staleness, LOW]

- Location: `Inventory/Services/AbcClassificationService.php` (no cron; manual/service call only).
- Classification rots; any ABC-driven DSS (cycle counts, service levels) degrades silently.
- Fix (S): monthly cron after supplier-perf recompute + `abc_class_computed_at`.

## F-12 Settings-write audit unverified [governance, LOW]

- Dozens of DSS-driving thresholds live in `settings` (`SettingsService` cache `settings:{key}`).
  Whether every admin settings write produces an `audit_logs` row is **not verified**.
- Fix (S, Wave 0): verify; add test `SettingsWriteAuditTest`; DSS parameter history depends on this.

## F-13 Full frontend route-guard audit still open [security, MEDIUM]

- Sampled DSS routes match backend (see 00-discovery §C); **exhaustive** guard-vs-middleware matrix
  not done. Prompt's "RBAC inaccuracies especially frontend" not confirmed, not refuted project-wide.
- Fix (M, Wave 0): automated test asserting every SPA route's guard permission exists in the backend
  catalog and every DSS API route has middleware + scoping (extend `cd75c509`-style catalog test).

## F-14 MTBF window math is fleet-view; WO qty grain coarse [modeling, LOW]

- `DowntimeAnalyticsService` MTBF uses `windowMinutes×machineCount` (correct for fleet, misleading
  per-machine cards); `work_orders.quantity_* decimal(10,0)` — no partial-unit precision.
- Fix: label fleet vs per-machine explicitly in DSS cards; no schema change proposed now.
