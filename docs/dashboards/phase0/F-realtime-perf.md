# SECTION F — Real-time & performance (Phase 0 discovery)

Read-only research against PRIMARY SOURCES. Code wins over docs.
Branch: `docs/dss-discovery-phase0`. No code modified, no test suite run, no services started.

**Ground truth used:** `docker-compose.yml`, `api/config/broadcasting.php`, `api/config/queue.php`,
`api/config/cache.php`, `api/config/database.php`, `api/.env.example`, `api/routes/channels.php`,
`api/routes/console.php`, `api/app/Providers/AppServiceProvider.php` (boot listeners),
`api/app/Modules/*/Events/*.php`, `api/app/Modules/Dashboard/Services/*`,
`api/app/Modules/Dashboard/Services/Analytics/*`, `api/app/Modules/Production/Services/*`,
`api/app/Modules/Inventory/Services/InventoryDashboardService.php`,
`api/app/Modules/Purchasing/Controllers/ProcurementChainController.php`,
`api/app/Common/Services/OutboxService.php`, `api/phpunit.xml`, `api/tests/phpunit-bootstrap.php`,
`scripts/test-api-sharded.sh`, `Makefile`, `spa/src/lib/echo.ts`, `spa/src/hooks/useEcho.ts`,
`spa/src/api/dashboards.ts`, dashboard pages/hooks, `docs/SEEDS.md`,
`api/database/seeders/DemoDataSeeder.php`, `api/database/seeders/DashboardWidgetSeeder.php`,
cross-check `docs/dashboards/phase0/B-modules-data.md` (§1 inventory of tables/index gaps).

Counts (defined in §1–§2): **broadcast events = 14, cached key patterns = 32, query risks = 15.**

---

## 1. Reverb / Redis / queue as-built

### 1.1 Transport: Reverb + Redis + queue worker

- `docker-compose.yml:97-102` — `redis: redis:7-alpine`, volumes `redisdata`, network `ogami-net`.
- `docker-compose.yml:104-123` — `reverb` service builds from `docker/php/Dockerfile`,
  runs `php artisan reverb:start --host=0.0.0.0 --port=8080`, publishes
  `${REVERB_HOST_BIND:-127.0.0.1}:${REVERB_HOST_PORT:-8080}:8080`. Comment at
  `docker-compose.yml:115-120` states pusher-js cannot use a path prefix so the port must be
  directly reachable; dev default binds localhost only.
- `docker-compose.yml:125-141` — `queue` service runs
  `php artisan queue:work --tries=3 --timeout=1800 --sleep=3`; comment notes the 1800s timeout
  matches the longest durable listener (payroll compute).
- `docker-compose.yml:51-54` — SPA gets `VITE_REVERB_APP_KEY/HOST/PORT/SCHEME`; host blank means
  browser uses its own hostname.
- `api/config/broadcasting.php:31` — default broadcaster `reverb` (`BROADCAST_CONNECTION` env).
  `api/config/broadcasting.php:45-67` reverb connection reads `REVERB_APP_KEY/SECRET/ID` plus
  `REVERB_SERVER_HOST/PORT/SCHEME` (internal Docker target) falling back to public `REVERB_*`;
  comment explains pushing via public host would hairpin-NAT and nginx only proxies the browser WS path.
- `api/.env.example:44-53` — `REDIS_HOST=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`,
  `REDIS_QUEUE_RETRY_AFTER=2400` (must exceed longest job timeout), `BROADCAST_CONNECTION=reverb`.
- `api/config/queue.php:4` — default queue `redis` (`QUEUE_CONNECTION`).
  `api/config/queue.php:7-18` redis queue: `retry_after` 2400 (comment: longer than 1800s payroll
  listener so a slow listener is not redelivered mid-run), `block_for: null`, `after_commit: false`.
- `api/config/cache.php:6` — default cache `redis` (`CACHE_STORE`).
  `api/config/cache.php:17-21` redis cache uses `cache` connection; `api/config/database.php:43-58`
  splits Redis DB `0` (default/queue) vs DB `1` (cache).
- `api/config/database.php:9-22` — default DB `pgsql`, host `db`, database `ogami`.
- Tests override all three: `api/phpunit.xml:245-258` forces `BROADCAST_CONNECTION=log`,
  `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`
  (see §4).

### 1.2 What is broadcast today — 14 events

All broadcast events implement `ShouldBroadcast` except one `ShouldBroadcastNow`.
Every one uses `PrivateChannel` (no public channels). Auth rules live in `api/routes/channels.php`.

| # | Event (file) | Channel(s) (`broadcastOn`) | Wire name (`broadcastAs`) | Who listens (SPA / server) |
|---|---|---|---|---|
| 1 | `api/app/Modules/Dashboard/Events/BadgesChanged.php:20,25-33` | `badges` | `BadgesChanged` | `spa/src/hooks/useBadges.ts:39-41` (`echo.private('badges')` + invalidate `['sidebar','badges']`); channel auth open to any authenticated user (`api/routes/channels.php:51`) because payload is empty and each client refetches permission-scoped counts |
| 2 | `api/app/Common/Events/UserNotificationCreated.php:13,22-31` | `user.{hashId}` | `notification.created` | `spa/src/hooks/useNotificationRealtime.tsx:32` (`echo.private('user.${user.id}')`); bell polls 30s as fallback (`spa/src/components/layout/NotificationBell.tsx:11-13,57-58`); channel gated to self (`api/routes/channels.php:46`) |
| 3 | `api/app/Common/Events/PermissionOverrideChanged.php:14,26-32` | `user.{hashId}` | (file lines 32-37 define `broadcastAs`/`broadcastWith`; same `user.{id}` channel) | `spa/src/hooks/usePermissionSync.tsx:25-29` (`echo.private('user.${user.id}')`) |
| 4 | `api/app/Common/Events/ChainStepAdvanced.php:29,53-61` | `chain.{entityType}.{hashId}` | `chain.step_advanced` | `spa/src/hooks/useChainProgress.tsx:44,60-85` subscribes + 30s?/interval poll fallback (`spa/src/hooks/useChainProgress.tsx:51,97`); channel gated per entity type via `ChainDefinitions` (`api/routes/channels.php:58-67`); the ONLY `ShouldBroadcastNow` (sync broadcast, not queued) |
| 5 | `api/app/Modules/CRM/Events/SalesOrderConfirmed.php:20,26-32` | `production.dashboard` | (see §1.3; SPA listens `.sales_order.confirmed`) | `spa/src/pages/production/dashboard.tsx:47-49` invalidate `['production','dashboard']` |
| 6 | `api/app/Modules/MRP/Events/MrpPlanGenerated.php:21,33-38` | `production.dashboard` | (SPA `.mrp.plan_generated`) | `spa/src/pages/production/dashboard.tsx:50-52` |
| 7 | `api/app/Modules/MRP/Events/MachineStatusChanged.php:19,30-39` | `production.machine.{hash}` + `production.dashboard` | (SPA `.machine.status_changed`) | `spa/src/pages/production/dashboard.tsx:41-43`; channels gated `production.dashboard.view` / `mrp.machines.view` (`api/routes/channels.php:20-27`) |
| 8 | `api/app/Modules/MRP/Events/MoldShotLimitNearing.php:20,26-32` | `production.dashboard` | (SPA `.mold.shot_limit_nearing`) | `spa/src/pages/production/dashboard.tsx:56-58` |
| 9 | `api/app/Modules/MRP/Events/MoldShotLimitReached.php:19,25-31` | `production.dashboard` | (SPA `.mold.shot_limit_reached`) | `spa/src/pages/production/dashboard.tsx:59-61` |
| 10 | `api/app/Modules/Production/Events/WorkOrderStatusChanged.php:20,31-40` | `production.wo.{hash}` + `production.dashboard` | (SPA `.work_order.status_changed`) | `spa/src/pages/production/dashboard.tsx:62-64`, `spa/src/pages/production/work-orders/detail.tsx:149` (`production.wo.${id}` + `.output.recorded`) |
| 11 | `api/app/Modules/Production/Events/WorkOrderOutputRecorded.php:25,35-43` | `production.wo.{hash}` + `production.dashboard` | (SPA `.output.recorded`) | `spa/src/pages/production/dashboard.tsx:37-39`, `detail.tsx:149`, `record-output.tsx:97` |
| 12 | `api/app/Modules/Production/Events/MachineBreakdownDetected.php:20,32-41` | `production.dashboard` + `production.machine.{hash}` | (SPA `.machine.breakdown_detected`) | `spa/src/pages/production/dashboard.tsx:53-55` |
| 13 | `api/app/Modules/Maintenance/Events/MaintenanceWorkOrderCreated.php:20,26-35` | `maintenance.dashboard` | `maintenance.wo_created` | `spa/src/pages/maintenance/work-orders/index.tsx:49` (gated `maintenance.view`); channel `api/routes/channels.php:40-41` |
| 14 | `api/app/Modules/Payroll/Events/PayrollProgressEvent.php:29,40-52` | `payroll.period.{hash}` | `payroll.progress` | `spa/src/pages/payroll/periods/detail.tsx:151` (typed `PayrollProgressEventPayload`); 3s poll fallback + cached snapshot (see §1.3); channel gated `payroll.periods.view` (`api/routes/channels.php:32-33`) |

Wire-name note: production/MRP event `broadcastAs` values (`.output.recorded`,
`.machine.status_changed`, etc.) are asserted by the SPA hook doc
(`spa/src/hooks/useEcho.ts:9-12`) and the 8 subscriptions in
`spa/src/pages/production/dashboard.tsx:37-64`; the canonical strings live in each
`Events/*.php:39-46` (`broadcastAs`/`broadcastWith`). `useEcho` subscribes via
`echo.private(channel)` + `sub.listen(event)` and tears down with
`stopListening` + `leave` (`spa/src/hooks/useEcho.ts:25-38`); failure only logs
(`spa/src/hooks/useEcho.ts:39-42`) — real-time is optional, polling remains.

SPA transport: `spa/src/lib/echo.ts:30-50` lazy-imports `laravel-echo` + `pusher-js`
(~21 KB gzipped kept out of entry chunk), sets `broadcaster: reverb`,
`authEndpoint: /api/v1/broadcasting/auth` with an axios cookie+CSRF authorizer
(`spa/src/lib/echo.ts:51-73`). Singleton resolves once (`spa/src/lib/echo.ts:86-96`).
Declared-but-unlistened channel: `inventory.stock` is authorised in
`api/routes/channels.php:36-37` but no SPA `useEcho('inventory.stock', …)` subscriber was
found — effectively dead WS surface today.

### 1.3 What is cached — 32 key patterns (payload + version/lock)

Default store Redis (`api/config/cache.php:6`); tests use `array` (`api/phpunit.xml:247`).
TTLs below are literal code values; invalidation notes cite the writer.

**Procurement chain (global keys, 60/120s, no per-user scope):**
1. `procurement_chain_pr` — 60s (`api/app/Modules/Purchasing/Controllers/ProcurementChainController.php:37`)
2. `procurement_chain_po` — 60s (`…/ProcurementChainController.php:42`)
3. `procurement_chain_grn` — 60s (`…/ProcurementChainController.php:56`)
4. `procurement_chain_bills` — 60s (`…/ProcurementChainController.php:62`)
5. `procurement_chain_three_way` — 120s (`…/ProcurementChainController.php:74`)
   No explicit forget — TTL-only expiry. Counts run unscoped (no `visibleTo` policy).

**Production / inventory dashboards:**
6. `dashboard:production` — 30s (`api/app/Modules/Production/Services/ProductionDashboardService.php:31-33,47`);
   invalidated by `forgetCache()` (`…:40-43`) and via `SettingsService` when a `production.*` key
   changes (`api/app/Common/Services/SettingsService.php:256-261`).
7. `production:idem:{work_order_id}:{idempotencyKey}` — idempotency (`…/WorkOrderOutputService.php:874`,
   checked `…:575`).
8. `inv:dashboard:summary` — 30s (`api/app/Modules/Inventory/Services/InventoryDashboardService.php:28`).
   No explicit forget found — TTL-only.

**Locks / progress (not payload cache):**
9. `mrp:plant-run` — Redis lock 1200s (`api/app/Modules/MRP/Services/MrpEngineService.php:767`).
10. `payroll:progress:{periodId}` — 3600s (`api/app/Modules/Payroll/Services/PayrollProgressTracker.php:30,37,53`);
    written on every progress broadcast and read on first paint so a mid-run page load shows a real
    bar (`…:11-25`); best-effort with `Log::warning` fallback (`…:53-76`).

**Payroll gov tables:**
11. `gov_table:{agency}:active` — 300s (`api/app/Modules/Payroll/Services/GovernmentContributionTableService.php:17,30-38`)
12. `gov_table:{agency}:ver` — 86400s version counter (`…:53,193`)
13. `gov_table:{agency}:v{ver}:eff:{date}` — 300s effective-dated brackets (`…:55-57`);
    busted via `forget(active)` + version bump (`…:188-194`), also from import service
    (`api/app/Modules/Payroll/Services/GovernmentContributionTableImportService.php:307-308`).

**Auth / permissions:**
14. `auth:role_perms:{roleId}` — role permission set (`api/app/Modules/Auth/Models/User.php:128`;
    forgotten in `…:173-175`, `api/app/Modules/Admin/Services/RoleService.php:348`,
    `api/app/Modules/Admin/Services/UserAdminService.php:388,501`)
15. `auth:permissions:{userId}` (`api/app/Modules/Auth/Models/User.php:173`)

**Role dashboards (all 30s, per-user):**
16. `dashboard:hr:{userId}` (`api/app/Modules/Dashboard/Services/HrDashboardService.php:28,47`)
17. `dashboard:employee:{userId}` (`…/HrDashboardService.php:135`)
18. `dashboard:purchasing:{userId}` (`api/app/Modules/Dashboard/Services/PurchasingDashboardService.php:38`)
19. `dashboard:warehouse:{userId}` (`api/app/Modules/Dashboard/Services/WarehouseDashboardService.php:37`)
20. `dashboard:quality:{userId}` (`api/app/Modules/Dashboard/Services/QualityDashboardService.php:39`)
21. `dashboard:ppc:{userId}` (`api/app/Modules/Dashboard/Services/PpcDashboardService.php:41`)
22. `dashboard:accounting:{userId}` (`…/PpcDashboardService.php:84`)
23. `dashboard:plant_manager:{userId}:{range}` (`api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php:47`)
24. `dashboard:admin:{userId}` (`api/app/Modules/Dashboard/Services/AdminDashboardService.php:38`)
25. `finance_dashboard:summary:v2:{permissionSignature}` — 30s, tagged
    `['financial_statements','finance_dashboard']`
    (`api/app/Modules/Dashboard/Services/FinanceDashboardService.php:57-68`); signature carries the
    caller's gate answers so two viewers never share an entry.
26. Statement date-keyed entries under the same tags (BalanceSheet `api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:38`,
    TrialBalance `…/TrialBalanceService.php:30`, IncomeStatement `…/IncomeStatementService.php:29` —
    each `->remember($cacheKey, +60s)`); flushed on any JE `saved`/`deleted` via
    `api/app/Modules/Accounting/Observers/JournalEntryObserver.php:30-48` (try/catch: non-tag stores
    fall back to TTL).

**Badges / holidays / settings / system:**
27. `badges.user.{userId}.v{version}` — per-user badge map, TTL from
    `dashboard.badges.cache_ttl_seconds` default 30s (`api/app/Modules/Dashboard/Services/BadgeService.php:122-131,613-621`)
28. `badges.version` — global version; `touch()` bumps it to invalidate every user at once
    (`…/BadgeService.php:134-151`); bumped by generic observer on 26 badge-backing models
    (`api/app/Providers/AppServiceProvider.php:284-313`, `api/app/Modules/Dashboard/Observers/BadgeInvalidationObserver.php:35-44`;
    `StockLevel` deliberately excluded — write frequency would flood the channel).
29. `holidays:{year}` + 30. `holidays:cache_version`
    (`api/app/Modules/Attendance/Services/HolidayService.php:98-99,197-198`)
31. `settings:{key}` — 3600s (`api/app/Common/Services/SettingsService.php:21,46-57`);
    forgotten on `set`/`updateFromAdmin` (`…:154,249,387`); `getGroup('dashboard')` used by badges is
    uncached (one query per badge compute, `…/BadgeService.php:159`).
32. `system:user_id` — `forever` (`api/app/Common/Services/SystemUserResolver.php:28,59-60`).

### 1.4 What runs on queues vs sync

- Default prod/dev: Redis queue (`api/config/queue.php:4`); tests: `sync`
  (`api/phpunit.xml:257-258`), so every `ShouldQueue` listener runs inline in tests.
- Outbox is the durability pattern: `OutboxService::record()` writes `event_outbox` (+ optional
  `chain_step_runs`) in the business transaction, then `DB::afterCommit(DispatchOutboxMessage::dispatch)`
  as an optimisation (`api/app/Common/Services/OutboxService.php:29-77`); the minute-level
  `outbox:dispatch --limit=100` scheduler recovers rows when Redis/worker was down
  (`api/routes/console.php:227-228`). `BadgeInvalidationObserver` records `BadgesChanged` through the
  outbox (`api/app/Modules/Dashboard/Observers/BadgeInvalidationObserver.php:39-42`).
- Queued (`implements ShouldQueue`, Redis in prod): essentially every chain listener registered in
  `api/app/Providers/AppServiceProvider.php:315-420` — MRP coalescing (`QueueMrpOn*`),
  QC triggers (`TriggerIncomingQC/TriggerInProcessQC/TriggerOutgoingQC`), GRN/bill/invoice handoffs
  (`AcceptGrnOnIncomingQcPass`, `AutoCreateBillOnGrnAccepted`, `CreateDraftInvoiceOn…`),
  payroll compute/GL/bank/payslip (`RunPayrollComputationOnRequested`, `PostPayrollToGlOnRequested`,
  `GenerateBankFileOnPayrollFinalized`, `EmailPayslipPdfOnPayrollFinalized` + `ProcessPayrollJob`,
  `SendPayslipEmailJob`), all `NotifyOn*` / `Email*` listeners, `ConsolidatePurchaseOrders`,
  `PrepareSupplierDispatch`, `CreateNcrOnComplaintRequested`, depreciation/year-end-leave jobs.
  Module grep counts ~92 `implements ShouldQueue` hits under `api/app/Modules` (mails + listeners + jobs).
  Listeners default to `ShouldQueue` with try/catch + `Log::warning` so a failure never blocks the
  dispatcher (CLAUDE.md convention; failure-recorder caveat applies).
- Sync (never queued): `ChainStepAdvanced` is `ShouldBroadcastNow`
  (`api/app/Common/Events/ChainStepAdvanced.php:29`) — broadcasts inline. `WidgetAnalyticsService`
  and `DashboardWidgetDataService` are read-only and synchronous by construction
  (`api/app/Modules/Dashboard/Services/WidgetAnalyticsService.php:24-30`). KPI reads serve snapshots;
  only `kpi:compute-monthly` writes (`api/routes/console.php:437-441`). Queue lifecycle itself is
  observed synchronously for the chain ledger (`JobProcessing/Processed/ExceptionOccurred/Failed` →
  `ChainListenerRunService`, `api/app/Providers/AppServiceProvider.php:226-229`).
- Scheduler (all `->onOneServer()`, `api/routes/console.php`): minute-level
  `production:notify-upcoming-work-orders`, `purchasing:close-due-rfqs`, `outbox:dispatch`,
  `pricing:review-agreements --dispatch-only`; 5-min `supplier:dispatch-recover`, `exports:run-due`,
  `maintenance:check-assignment`; 15-min `alerts:run`, `ncr:escalate`, `complaints:check-8d-slas`,
  `payroll:reconcile-payslip-emails`, `grn:retry-pending-incoming-qc`; hourly `chain:check-bottlenecks`,
  `mrp:reap-stale-runs`, `payroll:reap-stale-runs`, `qc:sweep-missing-outgoing`; daily MRP
  (`mrp:run-daily 06:00`), forecasting, payroll auto-create, safety-stock, dunning, training/calibration/
  PPAP/effectiveness sweeps; monthly KPI (`kpi:compute-monthly`, 2nd 03:00), supplier-performance,
  depreciation, budget sync. Only `kpi:compute-monthly` and `budget:sync-actuals` touch dashboard-read
  tables on a schedule; everything else feeds dashboards indirectly via transactional writes.

---

## 2. Dashboard query risk list — 15 risks (file:line each)

Conventions: `B-§4` = missing-index inventory in `docs/dashboards/phase0/B-modules-data.md`
(`wo_operations.work_order_id`, `production_logs`, `grn_items.goods_receipt_note_id`,
`budgets` only-pkey, `credit_note_lines`, `return_requests` business FKs, `deliveries.actual_*`,
`purchase_requests/orders.date`, `containers.shipment_id`, etc.). Money = `decimal(15,2)`;
`id` = HashID at API.

- **R1 — Today's outputs loaded into PHP, then summed.** `WorkOrderOutput::whereBetween('recorded_at',…)->get()`
  + `->sum('good_count')` / `->sum('reject_count')` in
  `api/app/Modules/Production/Services/ProductionDashboardService.php:78-80`. Same shape in widget scalar
  `DB::table('work_order_outputs')->whereDate('recorded_at',$today)->sum(DB::raw('good_count + reject_count'))`
  (`api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:99`). No pagination; grows with
  outputs/day. Served under 30s cache but recomputed on every miss + 60s SPA poll
  (`spa/src/pages/production/dashboard.tsx:32`).
- **R2 — Chain stage breakdown hydrates every active sales order.** `SalesOrder::query()->whereNotIn(status…)->get()`
  then per-row PHP `match` in `api/app/Modules/Production/Services/ProductionDashboardService.php:122-156`.
  Widget scalar repeats the count (`DashboardWidgetDataService.php:115`). No `limit`, no aggregate;
  throws on unknown status (good) but cost is O(active SOs) per dashboard miss.
- **R3 — OEE fan-out: 3 queries × N machines per dashboard load.** `calculateForAllMachines()` loads active
  machines then calls `calculate()` per machine (`api/app/Modules/Production/Services/OeeService.php:119-145`);
  each `calculate()` runs 2× `calculateDowntimeMinutes()` (each a `MachineDowntime::…->get()` +
  PHP overlap loop, `…:399-422`) + 1× outputs query (`…:65-68`). 10 machines ≈ 31 queries, all inside the
  30s-cached `ProductionDashboardService::payload()` (`ProductionDashboardService.php:47-53`).
- **R4 — OEE outputs eager-load but still full-window `->get()`.**
  `WorkOrderOutput::with('workOrder.mold')->whereHas(machine)->whereBetween(recorded_at)->get()`
  (`api/app/Modules/Production/Services/OeeService.php:65-68`); sums + cycle-time avg done in PHP
  (`…:70-80`). `Model::preventLazyLoading` is on in non-prod (`api/app/Providers/AppServiceProvider.php:258`)
  so the `with` is required — but there is no chunking/pagination.
- **R5 — Inventory dashboard loads two full tables into PHP.**
  `StockLevel::query()->with('location.zone.warehouse')->get()` +
  `Item::query()->where(is_active)->get()` then per-item `bccomp` availability loop
  (`api/app/Modules/Inventory/Services/InventoryDashboardService.php:38-51`). `stock_levels` is the
  highest-write inventory table (mutated in place per movement; B-§1.7) yet has no dashboard pagination;
  only mitigation is the 30s `inv:dashboard:summary` cache (`…:28`).
- **R6 — Cash position scans the biggest ledger with an expression predicate.**
  `journal_entry_lines ⋈ journal_entries ⋈ accounts … SUM(l.debit - l.credit)` with
  `orWhereRaw('LOWER(a.name) LIKE %cash%')` in `DashboardWidgetDataService.php:385` (stricter
  code-only variant in `FinanceDashboardService.php:118-124`). `journal_entry_lines` has only
  `(journal_entry_id, account_id)` indexes (B-§1.6); the `LOWER()` expression + `OR` defeats index use.
  Runs per widget-data poll; finance path is 30s-cached but flushed on EVERY JE write
  (`JournalEntryObserver.php:30-48`).
- **R7 — Low-stock subquery aggregates the whole stock table.**
  `fromSub(items LEFT JOIN stock_levels GROUP BY i.id, reorder_point … SUM(qty-reserved)) → whereColumn(available <= reorder_point)->count()`
  (`api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:463`); badge twin uses a
  correlated `SELECT SUM… WHERE sl.item_id = items.id` per item
  (`api/app/Modules/Dashboard/Services/BadgeService.php:326-329`). No pagination; badge runs per user
  per 30s TTL.
- **R8 — `DATE(recorded_at)` GROUP BY busts the index.**
  `DB::table('work_order_outputs')->selectRaw('DATE(recorded_at) as day, …')->where(recorded_at >= 14d)->groupBy('day')`
  (`api/app/Modules/Dashboard/Services/Analytics/CoreWidgetAnalytics.php:96-100`). Function on the
  filtered column prevents range-index use; 14 points recomputed per rich layout, uncached (see R15).
- **R9 — AR aging = 4 full scans of `invoices`.** One `SUM(balance)` per bucket with
  `whereRaw('CURRENT_DATE - due_date BETWEEN ? AND ?')`
  (`api/app/Modules/Dashboard/Services/Analytics/CoreWidgetAnalytics.php:196-202`); scalar twins sum
  open AR/AP separately (`DashboardWidgetDataService.php:125-126`). `invoices.balance` has no
  dedicated index (B-§1.6 composite is status+date+cancelled); the date arithmetic blocks index use.
- **R10 — Monthly OEE KPI hydrates a month of work orders.** `DB::table('work_orders')->whereIn(completed,closed)->whereBetween(actual_end)->get([...])`
  then PHP loop over planned/good/rejected/minutes (`api/app/Modules/Dashboard/Services/KpiSnapshotService.php:324-349`).
  `actual_end` is UNINDEXED (B-§1.10: only `planned_start+status` composite + `planned_end` are indexed).
  Runs in `kpi:compute-monthly` (off-peak, fine) — risk is only if reused on a request path.
- **R11 — Inventory-turnover KPI = two full-table SUMs.**
  `SUM(total_cost)` over month's `stock_movements` + `SUM(quantity * weighted_avg_cost)` over ALL
  `stock_levels` (`api/app/Modules/Dashboard/Services/KpiSnapshotService.php:572-578`). Annualised
  (`×12`) from a single month; no snapshot table — recomputed per KPI run.
- **R12 — WO-completion KPI double-counts + known contract gap.**
  Two `COUNT(*)` over `work_orders` with `whereBetween(planned_end)` vs `whereBetween(actual_end)`
  (`api/app/Modules/Dashboard/Services/KpiSnapshotService.php:617-639`); numerator/denominator use
  different date columns so the ratio can exceed 100%, documented in-code (`…:601-611`). Both counts
  uncached on compute path.
- **R13 — Badge fan-out: up to ~30 COUNTs per user per TTL.**
  `BadgeService::compute()` loops `definitions()` (`api/app/Modules/Dashboard/Services/BadgeService.php:154-193,203-589`):
  approvals, action-center (which itself calls `ActionCenterService::for($user)`, `…:233`), PR/leave/OT/
  maintenance/low-stock/NCR/profile/payroll/training/postings/WO/deliveries/SO/inquiries/complaints/
  inspections/GRN/MRB/shipments/MRP-plans/returns/invoices/bills/budgets/transfers. Mitigated by per-user
  30s cache + global version invalidation (`…:122-151`) + 60s SPA poll (`spa/src/hooks/useBadges.ts:23-26`);
  worst case is still N_badges × polling_users contemporaneous misses.
- **R14 — Rich table tiles double-query with no cache.**
  `SupplyChainWidgetAnalytics` (`…/SupplyChainWidgetAnalytics.php:49-76,90-113`),
  `LoanWidgetAnalytics` (`…/LoanWidgetAnalytics.php:44-66`),
  `ReturnWidgetAnalytics` (`…/ReturnWidgetAnalytics.php:71-97`): each builds `$base()` then runs
  `limit(10)->get()` AND `$base()->count()` — 2 queries per tile per layout render. Same for
  `CoreWidgetAnalytics::headcount/poStatusMix` (`…/CoreWidgetAnalytics.php:222-287`). No
  `Cache::remember` anywhere in `Services/Analytics/*` (grep: only `DB::table`, `groupBy`, `limit`, `get`).
- **R15 — The two hottest dashboard paths have NO cache at all.**
  `DashboardWidgetDataService::summaries()` loops keys with per-key try/catch and no
  `Cache::remember` (`api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:50-74`);
  `WidgetAnalyticsService::payload()` is explicitly read-only with no transaction and no cache
  (`api/app/Modules/Dashboard/Services/WidgetAnalyticsService.php:24-30,58-81`). Every
  `GET /dashboard/widget-data?keys[]=…` (max 50 keys, `DashboardLayoutController.php:72-79`) and every
  `GET /dashboard/layout?rich=1` (`…:62-70`) therefore hits transactional tables live on each
  30–60s poll (`spa/src/api/dashboards.ts:10-17`, `spa/src/components/dashboard/RoleDashboard.tsx:37`).
  Failures degrade to `available:false` tiles (`DashboardWidgetDataService.php:59-70`) / `[]` rich payloads
  (`WidgetAnalyticsService.php:69-80`) — safe but silent under load.

B-report cross-reference (index gaps that amplify R1–R15): `wo_operations` has ONLY `deleted_at` indexed
besides pkey (`work_order_id` UNINDEXED — B-§1.10 HIGH); `production_logs` only-pkey (B-§1.10 HIGH);
`grn_items.goods_receipt_note_id` NO index (B-§1.7 HIGH); `return_requests`/`return_request_items` business
FKs ALL unindexed (B-§1.15 HIGH); `budgets` only-pkey, `credit_note_lines` credit_note_id+account_id
unindexed (B-§1.6 §4); `deliveries.actual_*`, `purchase_requests/orders.date`,
`containers.shipment_id` unindexed (B-§1.8/1.9 §4). Any future DSS aggregation touching these
columns table-scans by construction.

---

## 3. Table-size notes (seeds/schema only — no live DB queries)

Source: `docs/SEEDS.md` + `api/database/seeders/*`. These are seed volumes, NOT production sizes;
they bound what a fresh/dev database holds and what the dashboard queries above scan in dev.

- Reference data (`docs/SEEDS.md:7-86`): departments 11 listed codes (12 rows incl. ADMIN);
  positions ~35; shifts 4; holidays ~21 (2026 PH); leave types 8; COA ~45 accounts
  (`docs/SEEDS.md:87-138`); defect types 11 (`…:139-154`); SSS ~30 brackets, PhilHealth 1 rate row,
  Pag-IBIG 2 brackets, BIR 6 semi-monthly brackets (`…:155-233`); workflow definitions 18
  (`…:235-257`).
- Master/demo (`docs/SEEDS.md:258-343` + seeders): demo accounts 18; demo products 8 (each with BOM);
  demo customers 5; raw materials 12; vendors 8. `DemoDataSeeder::TARGET_EMPLOYEES = 50`,
  `TARGET_VENDORS = 10`, `TARGET_SOS = 12` (`api/database/seeders/DemoDataSeeder.php:44-46`;
  "50 employees across 12 departments", `…:59`); plus stock levels, attendance rows, sales orders,
  one complaint section (`…:50-56`). Attendance seeder guard references `employees × 50` rows
  (`api/database/seeders/RealisticDataSeeder.php:215`) — i.e. dev attendance volume is
  O(employees × days), the largest dev fact table by row count.
- Widget catalog: 55 scalar keys in `LINK_BY_KEY` (`api/database/seeders/DashboardWidgetSeeder.php:48-105`)
  + `kpi.*` per-definition widgets (`…:107-…`); `GET /dashboard/widget-data` accepts max 50 keys per call
  (`api/app/Modules/Dashboard/Controllers/DashboardLayoutController.php:74`). KPI scorecard size =
  active `kpi_definitions` rows; snapshots filled ONLY by `kpi:compute-monthly` (2nd 03:00,
  `api/routes/console.php:437-441`), so early-month dashboards read one month behind by design
  (`DashboardWidgetDataService.php:295-301`).
- Schema scale signal (no row counts claimed): 261 migrated tables per B-report header
  (`docs/dashboards/phase0/B-modules-data.md:6`); heaviest dashboard-scanned fact tables by columns/index
  shape are `journal_entry_lines`, `stock_movements`/`stock_levels`, `work_order_outputs`,
  `attendances`, `invoices`/`bills` (see B-§1 per-module key-column tables).

---

## 4. Test-infra constraints for future workers

- **Single shared test DB; two agents cannot share it.** `api/phpunit.xml:248-253` pins
  `DB_CONNECTION=pgsql`, host `db`, `DB_DATABASE=ogami_test`; `api/tests/phpunit-bootstrap.php:5-12`
  only allows `ogami_test` or `ogami_test_*` overrides (env wins over the XML). `RefreshDatabase` runs
  `migrate:fresh`, so a second suite tears the schema under the first: symptom is hundreds of failures
  with ZERO assertion failures (`relation "roles" does not exist`, `column roles.deleted_at does not
  exist`, `deadlock detected`) — the tell for DB contention (CLAUDE.md). Fix per CLAUDE.md:
  `CREATE DATABASE ogami_test_verify OWNER ogami;` then
  `docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test`
  (`<env>` does not force when the env var already exists).
- **Suite size/time.** CLAUDE.md records ~1900 tests / ~28 min full run (2026-08-17). Runner is sharded:
  `make test` (`Makefile:61-63`) runs `scripts/test-api-sharded.sh` (Unit, then top-level Feature files,
  then per-directory; `Accounting|B2B|Infrastructure|Inventory|Operations|Payroll|Purchasing|Quality|SupplyChain`
  run per-FILE, others per-directory — `scripts/test-api-sharded.sh:15-43`) plus SPA vitest.
  Tight loops must use `--filter='Foo|Bar'`; full suite only at end of feature. `failOnEmptyTestSuite=true`
  (`api/phpunit.xml:24`) so a mistyped filter fails loudly instead of passing on zero tests.
- **Per-test DB pattern.** `RefreshDatabase` + `migrate:fresh` per test class (implied by the contention
  note); test env forces `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=log`, `CACHE_STORE=array`,
  `SESSION_DRIVER=array`, `MAIL_MAILER=array` (`api/phpunit.xml:245-260`) — queued listeners run inline,
  broadcasts go to logs, cache is process-local. Consequence for perf work: timing/cache/queue assertions
  must not depend on Redis/Reverb; assert against `array` driver semantics and sync dispatch.
- **Memory + determinism.** PHP default 128M OOMs the full suite; bump via
  `echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini` (CLAUDE.md). Test seeds must keep
  varchars ≤20 chars (`'XX-T-'.substr(uniqid(),-5)`); users attach roles via
  `User::factory()->create(['role_id' => Role::where('slug',X)->value('id')])`, never `assignRole()`;
  file-upload tests use `Storage::fake('local')` (CLAUDE.md session patterns).
- **Scheduler/queue in tests.** Console schedule is real (`api/routes/console.php`, 60+ entries) but tests
  invoke commands directly; `Queue::createPayloadUsing(OutboxDispatchContext::payload())`
  (`api/app/Providers/AppServiceProvider.php:219`) and chain-run markers (`…:226-229`) still execute under
  `sync` — worker-recovery smokes (`make chain-smoke`, `make worker-recovery-smoke`, `Makefile:65-69`)
  are the only tests needing real Redis, and they use isolated DBs.

---

## 5. Gaps / blockers

1. **No WS on most dashboards — polling only.** Only `production.dashboard` (8 events),
   `maintenance.dashboard` (1), `payroll.period.{id}` (1), `badges` (1), `user.{id}` (2 event types),
   `chain.{type}.{id}` (1) and per-`production.wo/machine` channels push. Role dashboards
   (`/dashboards/hr|ppc|accounting|purchasing|warehouse|quality|admin|plant-manager`,
   `api/app/Modules/Dashboard/routes.php:21-42,69-70`), `GET /dashboard/widget-data`,
   `GET /dashboard/layout?rich=1`, `GET /dashboards/finance`, badges-adjacent KPIs and ALL
   `Services/Analytics/*` rich payloads have zero broadcast path — they rely on 30–60s polling
   (`spa/src/api/dashboards.ts:16-17`, `RoleDashboard.tsx:37`, `production/dashboard.tsx:32`,
   `inventory/dashboard.tsx:25`, approvals `30s` with "websocket upgrade is a future task",
   `spa/src/pages/approvals/index.tsx:66`). Forecast panels never poll by design (manual Refresh,
   `spa/src/api/dashboards.ts:11-14`).
2. **No cache on the two hottest composable paths (R15).** Widget scalars + rich analytics recompute
   live on every poll; only the bespoke role dashboards and the procurement-chain controller cache.
   A DSS that adds tiles to `widget-data` without adding a cache layer multiplies R1–R14 linearly
   with polling users × keys (≤50/call).
3. **Cache invalidation is coarse.** `dashboard:production` (30s) is forgotten on any
   `production.*` settings write; finance/statement tags flush on EVERY JE `saved`/`deleted`
   (`JournalEntryObserver.php:30-48`) — correct but high-churn: posting-heavy windows (payroll GL,
   billing runs) reduce the 30–60s caches to write-through. Procurement-chain + inventory-summary keys
   are TTL-only with no forget — stale up to 60s/30s by design.
4. **Dead/near-dead WS surface.** `inventory.stock` channel is authorised but unsubscribed
   (`api/routes/channels.php:36-37`, no SPA listener found). `ChainStepAdvanced` is the only
   `ShouldBroadcastNow` — everything else queues through Redis, so WS latency inherits queue health;
   the outbox minute-poll (`api/routes/console.php:227-228`) is the only fallback when Redis is down.
5. **Analytic failure mode hides load.** Per-key try/catch returns `available:false` / `[]`
   (`DashboardWidgetDataService.php:59-70`, `WidgetAnalyticsService.php:69-80`) and per-badge catch
   drops the key (`BadgeService.php:172-177`). Under DB pressure dashboards degrade tile-by-tile with
   only `Log::error/warning` server-side — operators see "no data", not "overloaded".
6. **Test-env blindness to prod perf topology.** `sync` queue + `array` cache + `log` broadcast in
   `api/phpunit.xml:245-258` mean no test exercises Redis contention, Reverb delivery, tag-flush
   behaviour (`Cache::tags()->flush()` is a no-op-safe try/catch, `JournalEntryObserver.php:42-47`),
   or the 1800s/2400s timeout/lease pairing (`docker-compose.yml:137`, `api/config/queue.php:15`).
   Perf guards need staging smokes (`chain-smoke`, `worker-recovery-smoke`), not PHPUnit.
7. **B-index debt constrains new aggregations.** Any DSS GROUP BY/JOIN over `wo_operations`,
   `production_logs`, `grn_items`, `budgets`, `credit_note_lines`, `return_requests` business FKs or
   `work_orders.actual_*` / `purchase_*.date` must add migrations first (B-§4) or accept table scans.
   Mixed `0NNN_` vs `2026_*` migration ordering (CLAUDE.md) further constrains where such index
   migrations may live — check the dependency table before numbering.
