# Phase 0 — DSS Discovery (whole project)

Date: 2026-10-12. Branch: `main` @ `61b63a55`. Method: static analysis (two systematic
research passes over `api/app`, `api/routes/console.php`, `api/database/migrations`
(789 files), `api/database/seeders`, `spa/src`) + spot-verification of key files by the author.
No code changed, no tests run, no DB queries executed. **Line numbers below are as reported
and spot-checked, not exhaustively re-verified — treat them as pointers, re-confirm before building.**

Prior art this builds on (do not duplicate): `docs/dashboards/phase0/D-dashboard-dss.md`
(now stale — it describes 8 dashboards; the SPA now ships 14+ pages including `company`,
`logistics`, `department`, `sales`, `customer-service`, `maintenance`; verified
`spa/src/pages/dashboard/` + `spa/src/routes/dashboardRoutes.tsx:70-77`).

Prompt assumptions corrected by code (§5): the system already has MRP, forecasting,
alert engine, safety-stock math, supplier scoring, AR dunning, SPC/COPQ, predictive
maintenance, KPI snapshots — far more than "scattered partial logic". What it lacks is
**unification**: no common detector interface, no recommendation lifecycle, no scoring,
no explainability surface, no shadow mode, no outcome tracking.

---

## A. Existing decision-support logic (explicit + implicit)

### A.1 Trigger inventory (authoritative: `api/routes/console.php`, ~63 entries)

| Command | Cadence | DSS role |
|---|---|---|
| `mrp:run-daily` | daily 06:00 | net-requirements → auto-PR + draft WOs |
| `mrp:reap-stale-runs` | hourly | reaps heartbeat-dead MRP runs (>2h) |
| `forecasting:generate` | monthly 1st 04:00 | advisory demand forecasts (preserves manual) |
| `forecasting:reconcile-actuals` | monthly 2nd 04:00 | backfills actuals/variance → MAPE/bias |
| `alerts:run` | every 15 min | 11-check alert engine (stock, mold, OEE-proxy, AR/AP, scrap, scheduler…) |
| `alerts:retry-critical-emails` | every 15 min | bounded-backoff email recovery (5 attempts) |
| `inventory:recompute-safety-stock` | daily 02:15 | SS = Z·σ·√L rewrite of `items.safety_stock` |
| `inventory:reconcile-replenishment` | daily 02:30 | bounded re-run after master-data repair |
| `purchasing:recompute-supplier-performance` | monthly 1st 02:00 | 5-metric composite + tiers |
| `purchasing:close-due-rfqs` | every minute | `open→closed` at `closes_at` |
| `approvals:run-escalations` | every 6 h | reminder → escalation → auto-resolve |
| `ncr:escalate` | every 15 min | 3-tier NCR SLA escalation |
| `complaints:check-8d-slas` | every 15 min | 8D D3/D4/finalize escalation |
| `ncr:check-effectiveness` | daily 02:05 | CAPA verification due/overdue (IATF §10.2.1) |
| `ar:run-dunning` | daily 07:00 | 7/15/30-day dunning tiers |
| `chain:check-bottlenecks` | hourly | 16 stuck-document detectors |
| `production:notify-upcoming-work-orders` | every minute | upcoming-WO push |
| `production:send-daily-summary` / `send-weekly-summary` | 18:00 / Fri | EOD summaries |
| `grn:retry-pending-incoming-qc` | every 15 min | idempotent QC-handoff recovery |
| `qc:sweep-missing-outgoing` | hourly | repairs or escalates missing outgoing QC |
| `maintenance:recompute-hours` | daily 01:30 | machine-hour counters before PM sweep |
| `maintenance:request-preventive-generation` | daily 02:00 | time-based + mold-shot PM staging |
| `maintenance:check-assignment` | every 5 min | unassigned-critical + handover suggest |
| `maintenance:check-repeat-breakdowns` | daily 07:10 | ≥3 breakdowns/30d |
| `training:check-expiries` | daily 06:30 | 30/14/7/expired tiers |
| `calibration:check-due` | daily 06:50 | due/overdue recompute |
| `quality:expire-ppaps` | daily 06:55 | Approved→Expired |
| `kpi:compute-monthly` | monthly 2nd 03:00 | 11 KPIs → `kpi_snapshots` (sole writer) |
| `pricing:review-agreements` | daily 07:00 + every-min dispatch | cost-refresh recovery + pricing-risk reminders |
| `recruitment:check-bottlenecks` | hourly | hiring-pipeline stall scan |
| `hr:onboarding-reminders` | daily 09:00 | onboarding >3d |
| `operations:rollout-health` | hourly :10 | rollout observability |
| `budget:sync-actuals` | monthly 1st 03:00 | GL → budget actuals |
| `notifications:send-digest` | daily 07:05 | per-user email digest |

Event-driven (verified `api/app/Providers/AppServiceProvider.php` wiring exists per report):
`StockMovementCompleted→CheckReorderPoint`, `LowStockPrCreated→NotifyOnLowStockPrCreated`,
`SalesOrderConfirmed→QueueMrpOnSalesOrderConfirmed`, `StockMovementCompleted→QueueMrp…`,
`InspectionFailed→QueueMrpOnOutgoingInspectionFailed`, `GoodsReceiptNoteCreated→TriggerIncomingQC`,
`InspectionPassed→AcceptGrnOnIncomingQcPass`, `InspectionFailed→RejectGRNOnQcFail`,
`GoodsReceiptNoteAccepted→AutoCreateBillOnGrnAccepted`, `PurchaseRequestApproved→ConsolidatePurchaseOrders`,
`PurchaseOrderApproved→PrepareSupplierDispatch`, `PurchaseOrderSent→CreateDraftGrnOnPoSent`,
`SupplierPerformanceComputed→AlertOnSupplierDeterioration`, `DeliveryConfirmed→NotifyFinance…`,
`WorkOrderStatusChanged→TriggerInProcessQC`, `WorkOrderCompleted→TriggerOutgoingQC`,
`NcrRecurrenceLinked→AutoSpawn8DOnNcrRecurrence`, `ComplaintNcrRequested→CreateNcrOnComplaintRequested`.

### A.2 Demand → supply automation (the most DSS-mature chain)

- **MRP engine** (`api/app/Modules/MRP/Services/MrpEngineService.php`, ~700 lines):
  gross = BOM explosion netted by delivered/good output; `net = max(0, gross − openPR − linkedPO − issuedWO − available-above-safety)`;
  `order_by = earliest_delivery − max(supplier_lead, item_lead) − safetyBufferDays`;
  PR qty = ceil(net) rounded up to MOQ; WO priority by `daysUntilDelivery vs urgent_delivery_days`
  (settings, not hardcoded). Outputs: versioned `mrp_plans` row (supersedes prior), **one consolidated
  draft auto-PR** (`is_auto_generated`), draft planned WOs (root per SO line + children per manufactured
  subassembly), per-material `diagnostics[]`, `cost_summary`. Race-hardened: row locks,
  `pg_advisory_xact_lock`, draft-PR reuse + surplus-cancel under lock, stale-WO cancel only if untouched,
  heartbeat + reaper, plant-wide `Cache::lock`. **Correctness verdict: strongest logic in the repo.**
- **Capacity planner (MRP II)** (`MRP/Services/CapacityPlanningService.php`): priority-first greedy,
  children-before-parents, earliest feasible window, 90-day horizon (hardcoded), mold shot-life check,
  machine/mold/crew row locks, `suggestRepair`/`suggestReschedule` read-only proposals. Verdict: correct
  but greedy-not-optimal; no auto-slip on date conflicts.
- **Auto-replenishment** (`Inventory/Services/AutoReplenishmentService.php`): `position = usable+inTransit+heldQc`,
  `committed = openRequests+unapprovedAutoPOs`; critical items try direct auto-PO first
  (`Purchasing/Services/AutoPurchaseOrderService.php` — single-preferred-supplier only, zero-price guard,
  VP-threshold consistent), else draft auto-PR; blocked cases raise `ReplenishmentBlocked` alerts (no silent
  failure). Qty: fixed `2×reorder` (hardcoded) or days-of-supply (`demand_history_days=30`,
  `coverage_buffer_ratio=1.20`, settings).
- **Safety stock** (`Inventory/Services/SafetyStockRecomputeService.php`): `SS = Z·σ_daily·√L`,
  Z=1.65 (95%), 90-day zero-filled series, `min_demand_days=14`, `safety_stock_locked` respected, silent
  (no alert). Gaps: writes SS=0 on flat demand; no cap/floor; `saveQuietly` skips audit.
- **ABC** (`Inventory/Services/AbcClassificationService.php`): cumulative value split 70/90 (settings).
  Gap: **no schedule — classification rots unless manually rerun.**
- **Stock-out projection** (`Forecasting/Services/StockOutProjectionService.php`): on-demand API,
  `(on_hand−safety)/daily` with forecast-hint join on `items.code == products.part_number` (fragile for
  raw materials), risk bands from settings. Gaps: three different "demand" movement-sets across
  replenishment/safety-stock/projection disagree for the same item (see FINDINGS F-06).

### A.3 Forecasting (explicit, advisory)

`Forecasting/Services/ForecastingService.php`: moving_avg + linear-weighted avg over 6-month zero-filled
confirmed-demand series (draft/cancelled excluded); confidence = `100−100·σ/μ`; MAPE/bias over `actual>0`
rows only; manual rows preserved; reconcile idempotent; advisory lock + unique key. Gaps: no
seasonality/intermittent-demand methods; zero-actual periods dropped (intermittent products look
artificially accurate); `weighted_avg` weights arbitrary. Derivatives (read-only, hardcoded bands):
capacity (`BREACH_UTIL_PCT=90`), attendance (8%/15%), cash-flow/headcount/revenue/defect
moving-average + seasonality adjust.

### A.4 Purchasing intelligence

- **Supplier scorecard** (`Purchasing/Services/SupplierPerformanceService.php`): monthly composite
  25% on-time + 35% quality + 10% NCR + 15% price + 15% lead (weights settings, must sum 1.0), tiers A–D
  (settings, strictly descending). **F-01: `price_variance` measures receipt shortfall (fill-rate), not
  price — a vendor raising prices 20% with full delivery scores perfectly.** Scorecard up to 1 month stale.
- **Three-way match** (`Purchasing/Services/ThreeWayMatchService.php`): per-PO-line qty/price tolerance
  gate on bill post, UOM-normalized, BCMath comparisons, snapshot persisted, override retained. Strong.
- **Sourcing**: `VendorSourcingService` 4-tier candidates (only tiers 1–2 qualified); `RfqCommercialCalculator`
  is the ONE money path; RFQ auto-close; `ConsolidatePurchaseOrders` (approved PR → one draft PO/vendor,
  lines without preferred supplier + price → `manual_required`); pricing-review recovery loop.
- **Approvals**: Finance→VP ≥₱50k (`approval.po.vp_threshold`, settings); RFQ-sourced PRs skip need-approval;
  self-approval blocks; escalation every 6h (reminder→escalation→auto-resolve, default off, default action
  reject); unstaffed stays eligible + counted. Gap: current-approver pick is `orderBy(id)` first — arbitrary,
  no load-balancing.

### A.5 Financial automation

AR dunning (7/15/30 tiers, row-lock + tier claim, blocked-escalation, exits non-zero on failure);
AR 30/60 + AP 3-day alert bands; auto-bill on GRN accept (draft); auto-invoice on delivery (draft +
`manual_required` fallback, never poisons queue); CoC auto-attach guarded on measurement evidence.
Zero tolerances: over-receipt 0%, adjustment-approval threshold 0, urgent-PR skip 0 (all settings).

### A.6 Production / Quality / Maintenance automation

- WO lifecycle: confirm (material reservation via split logic) → start/pause/resume → auto-finish at target →
  auto-close when quality resolved; `WorkOrderOutputService::record` is THE output path (idempotent, mold
  shots + scrap + receipt handoff).
- QC: tolerance auto-eval on measurement record; incoming/in-process/outgoing triggers; GRN accept/reject on
  QC verdict; 15-min QC-handoff recovery + hourly missing-outgoing sweep (repairs or escalates).
- NCR/CAPA/complaints: 3-tier NCR SLA escalation (15m, durable deliveries, recorder-failure never swallowed);
  CAPA effectiveness verify loop (IATF §10.2.1); 8D D3/D4/finalize escalation; recurrence→auto-8D;
  complaint→NCR handoff. Replacement-WO ownership rule via `WorkOrder::coversSalesOrderLine()` (MRP owns
  root-SO-line, else NCR creates). Analytics: defect Pareto (30d window), SPC (`SpcService`, Cp/Cpk
  thresholds settings, subgroup 5), COPQ spike 0.25, PPAP expiry (gate default off), calibration + training sweeps.
- Maintenance: hours recompute → PM staging (time + mold-shot 100%); predictive breach→corrective MWO
  (consecutive-breach window, 24h freshness, per-metric min/max settings, machine-locked dedup);
  assignee suggestion (presence + load + blockers); 5-min unassigned-critical escalation; repeat-breakdown
  ≥3/30d; MTBF/MTTR/availability/Pareto analytics. Gaps: no spare-part auto-reorder hook; predictive dedup
  by description-substring (brittle).

### A.7 Dashboard / KPI / Action Center

- 11 KPIs (`KpiDefinitionSeeder`: oee 85/75, dppm 500/750, FPY 98/95, OTD 95/90, supplier_quality 90/80,
  attendance 96/93, ar_aging_60d 5/8, budget_util 90/80, ncr_closure_days 5/8, turnover 6/4, wo_completion 95/90),
  monthly snapshots (`kpi:compute-monthly`, sole writer → widgets read 1 month behind early-month, known).
  Calculators null-on-no-data (no synthetic zeros). Gaps: OEE inflates zero-duration WOs; turnover annualizes
  one month ×12; WO completion misses late completions by design.
- Widget scalars + color thresholds mostly settings-backed (`dashboard.badges.danger=20/warning=0`, zone
  ratios, tier floors); a few hardcoded component tones in SPA (utilization ≥80 warning, pipeline tones).
- Action Center: permission-aware personal queue (alerts/inspections/NCRs/maintenance/production/deliveries),
  critical>high>medium>low + overdue-first, capped max_items; approvals deliberately excluded (ApprovalBoard owns).
- Frontend rule (verified sample): **SPA computes almost nothing** — suggestions, forecasts, Pareto, MAPE all
  come from API; components only filter/color/format (one exception: `mrp/plans/detail.tsx:61`
  `shortageCount = rows.filter(r=>r.net>0).length`). Good separation; DSS can live backend-first.

### A.8 End-to-end traces (verified paths, abbreviated)

1. **SO → cash**: SO(confirmed) → outbox → auto-MRP (net req + diagnostics) + FG allocation (`stock_first`
   lines) → draft auto-PR → submit/approve (Finance→VP≥₱50k) → auto-consolidate → draft POs → approve →
   supplier dispatch → draft GRN on PO sent → GRN → incoming QC → accept/partial/reject → weighted-avg cost
   → draft bill → 3-way match at post → payment → GL. Recovery at every handoff (retry sweeps + bottleneck
   detectors). Shortage actions surfaced in MRP `diagnostics.action`.
2. **Reservation/allocation**: SO allocation (advisory + row locks, fingerprint idempotency, FEFO, spec/GL/expiry
   revalidation at delivery — no silent ship) → delivery reservation (exact-coverage gate, lot-specific shortage
   errors) → WO material reservation on confirm (split logic; release must pair or phantom reserved).
   Shortage → PR/PO path (§A.2); approval stall → escalation chain. **On material shortage production waits**
   (confirm-time reservation + departure gate block); partial-proceed is manual via split issue, no
   make-vs-wait recommendation exists (DSS gap).
3. **Production → collection**: planned WO → schedule (pending→confirmed) → confirm → issue (lot trail) →
   output record → FG receipt + GL JE → outgoing QC (AQL 0.65 LII) → pass → delivery draft → reserve → depart →
   confirm → draft invoice → finalize → dunning tiers → receipt → GL. Failures → NCR → ownership rule →
   rework/replacement WO. Bottleneck detectors watch every handoff.
4. **Hire → retire**: hire → profile → shift → biometric CSV → DTR → leave/OT approvals → payroll (derive-half,
   cycle-claim uniqueness, flat-rate + employed-fraction, gov tables by payroll_date) → payslip/bank/GL →
   separation → clearance → final pay. Automation: missing-timeout notice, auto-period creation + reconcilers,
   stale-run reaper, onboarding reminders, recruitment bottleneck scan, training expiries, probation horizon.
5. **Returns**: `ReturnManagement` module is real (RMA Draft→…→Completed, cases with events, inspection handoff,
   disposition NCRs, quarantine held/released/scrapped, credit notes, redelivery service, pipeline analytics,
   `return_without_inspection` bottleneck). **Missing**: no auto-approval/disposition rules, no return-reason
   scoring, no `returns:*` cron (hourly bottleneck only), no refund-posting automation, no supplier-debit
   automation, no fraud heuristics. Precisely: the flow exists, the intelligence doesn't.
6. **Quality/Maintenance events**: as §A.6; no auto-close of NCR on effectiveness pass (human `verifyAction`
   required — correct), no PM deferral optimizer, no spare reorder hook.

---

## B. Data readiness audit

### B.1 Global patterns

- History = `HasAuditLog` → `audit_logs` (append-only via PG BEFORE UPDATE/DELETE trigger), NOT
  per-entity `status_history` tables. Statuses overwrite in place; step history lives in `approval_records`;
  real event ledgers exist only for: return cases, action-center tasks, mold history, production logs,
  warehouse scans, chain step runs, scheduler runs, activity events, recruitment application events.
- Soft deletes on master data (items, customers, vendors, users, employees, machines, molds, products —
  23 migrations); **fact tables NOT soft-deleted** (stock_levels, stock_movements, BOMs, WOs, inspections, shipments).
- `stock_levels` (qty/reserved/wac) overwritten live, `$timestamps=false`; history only via
  `stock_movements` ledger replay + audit diffs. **No `stock_level_snapshots`, no kanban table.**
- Money `decimal(15,2)`; item qtys `decimal(10,3)`; WO qtys `decimal(10,0)` (coarse).

### B.2 Per-module CAN vs CANNOT (today)

| Module | CAN support today | CANNOT (data lost/coarse) | Must-add snapshot/history |
|---|---|---|---|
| Inventory | reorder/low/critical live; movements ledger; GRN/MRB trace | trends, days-of-cover, lead analysis (levels overwritten) | `stock_level_snapshots` daily |
| Purchasing | open PR/PO; ASL preferred+lead+last_price; monthly score | price trend, lead-time distribution (single `last_price`) | `supplier_price_history`; `po_leadtime_actuals` |
| MRP | plan versions + run ledger + shortages; mold history | capacity-utilisation trend; plan-vs-actual variance | `capacity_snapshots`; machine-hour ledger (append) |
| Production | WO progress, scrap, downtime rows, live OEE; monthly OEE/WO KPIs | shift-grain OEE/downtime trend | `oee_snapshots` (machine×shift) |
| CRM | order book, `customers.credit_limit` gate, complaints+8D | backlog aging, win/loss | reuse `demand_forecasts.actuals`; else `sales_order_snapshots` |
| Quality | Pareto, pass/fail, NCR aging, 8D SLA, COPQ monthly | sub-monthly defect trend | `defect_snapshots` weekly (if needed) |
| Maintenance | readings time-series, due schedules, open WOs | MTBF/MTTR trend (recomputed each time) | `maintenance_kpi_snapshots` monthly |
| Accounting | AR/AP aging live, JE ledger, budget-vs-actual live; monthly AR/budget KPIs | aging trend, cash-flow accuracy | `ar_ap_snapshots` monthly |
| SupplyChain | overdue deliveries, OTD live/monthly | lane lead-time distribution | `delivery_leadtime_actuals` |
| HR | headcount, DTR, payroll runs + anomaly flags | attrition/attendance trend | `headcount_snapshots` monthly (if workforce DSS) |
| Dashboard | 11 monthly KPIs, forecasts+actuals, alerts, action queue | any daily/weekly grain | `kpi_daily` for 3–4 critical KPIs (optional) |
| Returns/Assets | RMA queues + case events; register + depreciation rows | return-rate/cost trend | `return_snapshots` monthly (if returns DSS) |

Master-data completeness columns exist (`items`: reorder_point/safety_stock(+locked)/MOQ/lead_time/
standard_cost/is_critical/abc_class; `customers.credit_limit + payment_terms_days`; ASL lead/last_price/
preferred/qualification). Fill-rates need live queries — DSS Wave 0 master-hygiene detector covers this.
`machine.running_hours_total` overwritten (time series lost); MWO polymorphic (`maintainable_type/id`,
no direct `machine_id` index path — DSS-hostile join).

### B.3 Index sampling (verified present)

`stock_levels[item,location]` unique; `stock_movements[item,created_at]`, `[reference]`, `movement_type`;
PR `[status,priority,requested_by,department,is_auto_generated]`; PO `[status,vendor,pr,expected_date,vp_flag]`;
WO `[status,so,machine,mrp_plan,planned_start]`; BOM `[product,is_active]` + unique `[product,version]`;
`alerts[is_dismissed,severity]`, `[entity]`, `type`, `created_at`; `demand_forecasts` unique scope+period;
`kpi_snapshots[definition,year,month]` unique; `payroll_cycle_claims(employee,cycle)` unique (race guard);
`action_center_tasks.item_key` unique. No missing-index finding asserted without query evidence.

---

## C. Decision stakeholders (17 roles, `api/database/seeders/RolePermissionSeeder.php`)

Terminal money approver everywhere: `vice_president` (PR/PO ≥₱50k, bill payment ≥₱50k, loans, asset
disposal, budget). `system_admin` = IT only (`:*` technically, but no workflow step by design).
`finance_officer`: PR/PO step 1, payroll checker, adjustment checker, return finance-only.
`hr_officer`: leave s2, loan s2, separation s5; payroll maker (no approve). `department_head`: leave/loan s1,
return s1, OT approve, PR creator only. `production_manager`: return s2, WO/schedule/output/crew, maintenance
verify. `ppc_head`: MRP/schedule/forecasting owner, **no approval step**. `purchasing_officer`: buyer (creates,
no chain step), RFQ/award/PO send. `warehouse_staff`: GRN/issue/count/MRB maker (finance checks adjustments).
`qc_inspector`: inspections/specs/NCR/calibration/PPAP + MRB; NCR/8D "steps" are RESERVED (defined,
`is_active=false`, non-blocking). `maintenance_supervisor` assigns/verifies; `maintenance_tech` executes.
`impex_officer`: shipments/deliveries, no approval step. `customer_service_officer`: complaints/RMA lifecycle.
`sales_officer`: SO lifecycle. `employee`/`driver`: self-service (+driver PWA).

Dashboards are permission-derived (`DashboardDispatchService::resolve`, rarest-permission wins;
`DashboardLayoutService` strips unpermitted widgets): company (VP), plant-manager, ppc, finance, purchasing,
warehouse, quality, maintenance (shared), logistics (impex), department, sales, customer-service, admin
(system health only), default/employee self-service. **RBAC spot-check: no missing gates on DSS routes**
(`/forecasting/*`→`forecasting.view`, `/mrp/*`→`mrp.*`, `/alerts`→`alerts.view`,
`/action-center`→`dashboard.action_center.view`, `/approvals`→`approvals.board.view`; backend
`permission:` middleware + `FormRequest::authorize()` match on sampled routes). Known traps for DSS work:
`warehouse_staff` lacks `supply_chain.view` (widgets needing it silently strip); `leave.view`/`payroll.view`
are self-scoped for all roles — never use as global gates (use module row scopes:
`PurchaseOrderAccessPolicy::visibleTo`, `LoanAccessPolicy::visibleTo`, etc.); RESERVED workflows
(NCR/8D/maintenance/separation/department-transfer/WO) are non-blocking — DSS must not assume their steps.

---

## D. Technical foundations

- **Queue/scheduler**: Redis queue driver (`retry_after` 2400s for 1800s payroll listener); queues
  `high,default` (interactive worker) + `low` (bulk worker, so payroll never starves approvals);
  `failed_jobs` + `job_batches` on PG; all cron `withoutOverlapping()->onOneServer()`. Durable evidence:
  `scheduler_tick_runs/scheduler_task_runs`, `event_outbox` (dedupe_key unique), `chain_step_runs`.
  Capacity verdict: healthy pattern; DSS detectors must be queue-friendly + time-budgeted (new constraint,
  not a current problem).
- **Redis**: cache (`settings:{key}`, `auth:role_perms:{id}`, dashboard keys, badge versions, statement
  caches) + queues + scheduler locks + broadcast auth. Verdict: available for DSS result caching + dedupe
  locks; no new infra needed.
- **Reverb**: channels for production dashboard, WO, machine, payroll period, maintenance dashboard,
  per-user, badges, chain entities; ~10 `ShouldBroadcast` events (SO confirmed, badges, machine status,
  mold-shot limits, MRP plan, MWO created, payroll progress, output recorded…). Verdict: reuse for
  high-severity DSS pushes only; polling otherwise.
- **Notifications**: `NotificationService::send/sendInApp` — in-app DB always + email opt-in only;
  per-user prefs; role audiences via `settings` keys (`*_notification_roles`) + `notification_catalog`.
  Verdict: reuse; DSS needs digest/rate-limit layer on top (missing).
- **Audit**: `HasAuditLog` on most models (snapshots/runs mostly unaudited by design) + immutability
  trigger + `activity_events` + `login_history`. Verdict: reuse for parameter-change + recommendation-lifecycle audit.
- **Charts/UI/state**: `recharts` (only chart lib), leaflet, zustand (client-only stores), TanStack Query
  (all server state), RHF+Zod, React Router 7. Verdict: no new deps needed for Decision Center.
- **Settings**: `settings(key unique, value JSON, group)` + cached `SettingsService` + admin CRUD. Dozens of
  DSS-relevant keys already live here (alerts.*, approvals.*, forecasting.*, quality.*, maintenance.*,
  purchasing.*, inventory.*, dashboard.*). Verdict: reuse for detector parameters + change history
  (audit the writes — gap: settings writes may not all hit `audit_logs`; verify in Wave 0).

---

## §5 Discrepancies vs the mission prompt (code wins)

1. Prompt implies DSS logic is "scattered, partial" — code shows several **production-grade subsystems**
   (MRP engine, alert engine with race-safe dedup, NCR/8D escalation with failure accounting, 3-way match).
   The gap is unification, not absence.
2. Prompt lists "Dashboard module code" as unknown — it is extensive (14+ pages, 68 widget keys,
   11-KPI monthly snapshots, Action Center). Prior `docs/dashboards/` docs exist but are stale on page count.
3. Prompt says "returns … known to be incomplete" — precisely: the **flow** exists (RMA + cases + inspection
   + credit notes + analytics); the **intelligence + some automation** (auto-disposition, refund posting,
   supplier debit, fraud heuristics, scheduled scan) is missing.
4. Prompt says "RBAC has inaccuracies, especially frontend" — spot-check found guards matching backend on
   sampled DSS routes; historical seed bugs fixed. RBAC risk is now narrow traps (warehouse scope, self-scoped
   reuse, RESERVED workflows), not broad inaccuracy. Full route-guard audit still recommended (Wave 0).
5. "No silent automatic actions" (prompt hard rule 4): code already performs silent auto-actions today
   (RFQ auto-close, PPAP expiry, WO auto-finish/close, GRN settle, period auto-create, MRP draft-PR/WO
   creation). These are reversible/idempotent and accepted; the DSS must not add *new* silent actions beyond
   this established class without explicit approval.
6. Migration count: 789 files (prompt doesn't state; informational). Numeric prefixes reach ~0575 plus
   `2026_*` timestamped files; four prefixes used twice (0198/0427/0442/0450 — pre-existing, do not rename).
