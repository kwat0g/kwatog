# Audit: production — 2026-09-06

## Summary

The Production module backend is unusually hardened: output recording is genuinely
idempotent (durable key + fingerprint, WO/mold row locks, over-target and negative-count
guards), the WO state machine is strict with lock-then-recheck transitions, the FG-receipt
handoff degrades to manual instead of rolling back, and reservation/issue accounting is
real. All 99 feature tests pass (~58s). The defects cluster at the edges: the shop-floor
PWA is broken at its entry point (a comma-joined status filter the backend treats as
equality returns zero rows), the floor reject path cannot succeed (backend demands a
defect breakdown the floor UI never collects), `resume()`/`start()` re-acquire machines
with no occupancy recheck (machine double-booking), and a closed accounting period aborts
all output recording. Mold shots count pieces, not shots. OEE math is sound with proper
zero-guards; the downtime ledger has no overlap protection and its MWO link is never
written.

## Findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| PR-01 | Broken process | High | S | Floor PWA lists zero work orders: comma-joined status filter treated as equality | spa/src/api/factory.ts:10; api/app/Modules/Production/Services/WorkOrderService.php:96-98 | `status: 'in_progress,confirmed,paused'` vs `$q->where('status', $filters['status'])` — literal equality on varchar(20) matches nothing. ActiveOrders/QcQuickCheck picker/RecordOutput header all read this query → factory PWA always shows "No active work orders". No backend split, no paramsSerializer. |
| PR-02 | Broken process | High | M | Floor PWA cannot record rejects: no defect breakdown, backend requires one | spa/src/pages/factory/RecordOutput.tsx:53-76; api/app/Modules/Production/Requests/RecordOutputRequest.php:48-55 | Any `reject_count > 0` from the floor hits 422 "You must specify the defect types" — surfaced as generic "Failed to record output" toast. Rejects are only classifiable on the office page (work-orders/record-output.tsx), so floor-rejected parts go unrecorded or get recorded elsewhere without defect data (Pareto/IATF gap). |
| PR-03 | Risk | High | M | resume()/start() re-acquire machine+mold with no occupancy recheck → double-booking | api/app/Modules/Production/Services/WorkOrderService.php:313-380 (start), 440-484 (resume); assertMachineAvailable only called from confirm() at :259 | pause() frees the machine (Idle, current_work_order_id=null). A second WO can confirm (non-overlapping schedule windows pass assertMachineAvailable) and start (start() accepts machine status Running). resume() then sets the machine Running + current_work_order_id back to the paused WO silently — two InProgress WOs on one machine/mold; breakdown-pause → reassign → resume is the normal repro. No test covers resume conflict. |
| PR-04 | Broken process | High | M | Closed accounting period aborts ALL production output recording | api/app/Modules/Production/Services/WorkOrderOutputService.php:266-285; Controllers/WorkOrderController.php:297-309 | `ClosedPeriodException` from the FG movement's GL posting is deliberately excluded from the degrade-to-manual catch union; the whole record() transaction rolls back → 422 to the floor terminal. Every month-end close blocks shop-floor output capture until the period reopens. Pinned by test `…closed_period…is_not_absorbed_by_the_degrade_arm` — needs a policy decision with accounting. |
| PR-05 | Broken process | High | M | Factory QC FAIL promises an NCR + batch hold but creates only a Draft inspection with verdict in notes | spa/src/pages/factory/QcQuickCheck.tsx:60-82; api/app/Modules/Quality/Services/InspectionService.php:279-371 | POST /quality/inspections creates status=Draft, PASS/FAIL embedded only in `notes`; no NCR, no hold, no verdict field. Also: operator-entered sample size is ignored (in-process sample = batch_quantity = WO target) and scaffold rows = WO target × spec parameters — a 10k-target quick check inserts tens of thousands of rows. Cross-module → quality. |
| PR-06 | Gap | Medium | M | complete() needs no produced quantity and no material-issue reconciliation | api/app/Modules/Production/Services/WorkOrderService.php:486-546 | A WO can be completed (and closed) with quantity_produced = 0; nothing checks issued-vs-BOM variance at completion (variance columns exist on work_order_materials but are never gated). Legacy WOs started before reservation-issue wiring can complete with material never issued. |
| PR-07 | Risk | Medium | M | Cancel after start never reverses material issues | api/app/Modules/Production/Services/WorkOrderService.php:569-621, 1058-1073 | cancel() (legal from paused) releases only `reserved` reservations; MaterialIssue movements from start() stand, so stock left the warehouse attributed to a WO that no longer exists — no return movement, no flag. |
| PR-08 | Risk | Medium | M | Mold shots count pieces, not shots (cavity_count ignored) | api/app/Modules/Production/Services/WorkOrderOutputService.php:256-261; WorkOrderService.php:821; api/app/Modules/MRP/Services/MoldService.php:112 | record() passes good+reject (pieces) to incrementShots; MoldService adds it verbatim to current_shot_count. A 4-cavity mold over-counts shots 4×, hitting maintenance thresholds early; assertAssignmentValid compares pieces to shot life too. Semantics decision belongs to mrp unit — flagged. |
| PR-09 | Gap | Medium | M | Downtime ledger: no overlap guard, OEE window attribution by start_time only, maintenance_order_id never written | api/app/Modules/Production/Services/OeeService.php:47-64; Models/MachineDowntime.php; WorkOrderService::pause :399-411 | Overlapping open rows on one machine are possible (no exclusion constraint; pause() never checks for an existing open row) and OEE sums durations → double-counted unplanned downtime. Rows spanning window boundaries are attributed whole to the window containing start_time. `maintenance_order_id` is fillable but no code anywhere sets it — downtime↔MWO link is dead. Cross-module → maintenance. |
| PR-10 | Missing | Medium | M | Routing qc_required trigger is Log::info only — in-process QC hook not wired | api/app/Modules/Production/Services/WoOperationService.php:258-265 | completeOperation() logs "actual event integration comes in a later task"; no inspection is auto-created for qc_required operations. IATF in-process sampling depends on the manual floor QC page (PR-05) which itself doesn't record verdicts. |
| PR-11 | Risk | Medium | M | Dual output ledgers: operation output bypasses WO totals, mold shots, FG receipt; no target cap | api/app/Modules/Production/Services/WoOperationService.php:203-232; Controllers/WoOperationController.php:165-185 | `/operations/{operation}/output` accumulates qty_completed/qty_scrapped with no check against qty_planned (validation max only) and never touches work_orders totals, mold shots or inventory. WO detail exposes both surfaces — operators can over-report an operation indefinitely while WO progress shows nothing. |
| PR-12 | Other (bug) | Medium | S | Daily summary email lists all downtime categories as breakdowns | api/app/Modules/Production/Services/ProductionSummaryService.php:52-66 | `whereBetween(start) ->orWhereNull(end) ->where(category,breakdown)` → SQL `A OR (B AND C)`: any category starting that day lands in the "breakdowns" section of the daily/weekly email. |
| PR-13 | Bad practice | Low | S | Scheduler confirm failure is silent | spa/src/pages/production/schedule.tsx:92-100 | confirm useMutation has no onError — a GiST exclusion rejection (0479) or conflict at confirm time surfaces no toast; loud backend failure, silent frontend. |
| PR-14 | Risk | Low | S | OEE endpoints parse unvalidated date params | api/app/Modules/Production/Controllers/OeeController.php:42-75 | `Carbon::parse($request->query('from'))` → 500 on garbage input; no from≤to validation; report trend does machines×days calculate() calls (92-day cap bounds it). |
| PR-15 | Bad practice | Low | S | Chain-stage breakdown always shows 0 for three of seven stages | api/app/Modules/Production/Services/ProductionDashboardService.php:111-164 | 'QC Pending', 'Delivered Unpaid', 'At Risk' are declared and rendered but never assigned — dashboard rows permanently 0 (misleading, not wrong totals). |
| PR-16 | Bad practice | Low | S | Floor output form stuck after fingerprint conflict | spa/src/pages/factory/RecordOutput.tsx:50-76 | Idempotency key rotates only on success; if the key was recorded with a different payload (422 fingerprint conflict), every retry fails with the generic toast until full page reload. |
| PR-17 | Gap | Low | S | Factory routes skip ModuleGuard | spa/src/routes/factoryRoutes.tsx:12-25 | AuthGuard + PermissionGuard only; backend `feature:production` still enforces, but the SPA shows no "module disabled" state. |
| PR-18 | Bad practice | Low | S | FG receipt movement_id serializes null unless relation eager-loaded | api/app/Modules/Production/Resources/WorkOrderOutputResource.php:46-49; Controllers/WorkOrderController.php:338-340 | retryProductionReceipt loads recorder+defects only → `movement_id` null in the response even when a movement is linked. |

### Detail — High findings

**PR-01.** The factory PWA's single data source is `factoryApi.activeOrders()` sending
`status=in_progress,confirmed,paused`. `WorkOrderService::list()` does
`$q->where('status', $filters['status'])` — a literal equality on a varchar(20) column.
The 30-character joined string matches no row, so `/factory` (the floor home screen),
the WO header inside RecordOutput, and the QcQuickCheck picker all see an empty list,
forever. Everything downstream of the floor PWA's first screen is therefore unreachable
under normal use. Fix is one line either side: send an array (or split server-side).
The backend filter also accepts arbitrary status strings silently (empty result), which
is how this shipped unnoticed; there is no feature test for the multi-status filter.

**PR-02.** The floor is the natural place to record rejects (they happen at the press),
but RecordOutput.tsx posts only good/reject/remarks while RecordOutputRequest mandates a
defect breakdown whose sum equals reject_count. Every floor reject attempt returns 422
with a field error the page never displays (generic toast). Net effect: rejects either
go unrecorded or are laundered through as "good", and the defect Pareto on the production
dashboard is fed only by the office-side form. The two surfaces must be reconciled —
either collect defect types on the floor (the defect-types endpoint already exists) or
relax the floor path into an "unclassified" defect bucket that still feeds Pareto.

**PR-03.** Machine exclusivity is enforced once, at confirm(), and only approximately
(schedule-window overlap). start() accepts a machine whose status is Running (i.e.
already cutting another WO), and resume() — the path used after every pause, including
breakdowns — locks the machine and writes `current_work_order_id` without checking
anyone else is on it. Sequence: WO1 paused (machine freed) → WO2 confirmed on the same
machine with a non-overlapping planned window → WO2 started → WO1 resumed. Result: two
in_progress WOs on one machine and one mold, OEE output double-attributed, and the mold
shot counter fed by both. resume() also never rechecks mold status (a mold that flipped
to Maintenance mid-pause is silently reused). Needs the same assertMachineAvailable-style
gate inside resume()/start() plus a test.

**PR-04.** The FG-receipt handoff degrades gracefully for every failure class except a
closed accounting period: ClosedPeriodException escapes the catch union, so the *entire*
output transaction (output rows, WO totals, mold shots, event) rolls back and the floor
terminal gets a 422 naming the accounting period. During month-end close — a routine,
recurring event — production recording stops completely. The degrading pattern already
exists (markManual + outbox retry); extending it to closed periods (post the receipt
when the period reopens) is the consistent fix, but a test deliberately pins current
behavior, so this is a policy call spanning production + accounting.

**PR-05.** QcQuickCheck is the IATF in-process sampling touchpoint on the floor. Its
FAIL sheet tells the operator "This raises an NCR … and holds the batch"; the API call
creates a Draft inspection with the verdict stringified into `notes`. No NCR is raised
(NcrService is never called), nothing is held, and the inspection never leaves Draft
unless someone finishes it in the Quality module. Additionally the entered sample size
is decorative — in-process inspections scaffold `batch_quantity × spec parameters`
measurement rows, and batch_quantity here is the WO *target*, so a quick check on a
large order inserts tens of thousands of rows. Cross-module to quality, but the floor
page's false promise is production's surface.

## Cross-module flags

- **quality** — PR-05: QcQuickCheck creates Draft inspections with no verdict/NCR; sample size ignored; scaffold-row explosion from WO-target batch quantity.
- **mrp** — PR-08: pieces-vs-shots semantics for `MoldService::incrementShots` / `max_shots_before_maintenance` (Production passes pieces; decision belongs to mrp). Also: `assertAssignmentValid` shot-life comparison uses pieces.
- **mrp** — scheduler confirm (GiST exclusion from migration 0479) fails loudly server-side; frontend PR-13 swallows it. CapacityPlanningService is the only ProductionSchedule writer; overlap invariant is enforced at DB level — healthy.
- **accounting** — PR-04: ClosedPeriodException surfacing policy for production output recording during period close.
- **maintenance** — PR-09: `machine_downtimes.maintenance_order_id` never populated by any module; downtime↔MWO link dead; DowntimeAnalyticsService reads these same rows.

## What was NOT checked

- MRP CapacityPlanningService internals (schedule creation/confirm paths) beyond the Production interaction surface; scheduler run/confirm endpoints live in MRP.
- Quality module beyond the `InspectionService::create()` entry used by QcQuickCheck (NCR lifecycle, AQL outgoing flow).
- Runtime confirmation of PR-01 against a live database — established by code reading (no paramsSerializer, literal equality); no e2e/Playwright runs.
- Edge-device ingestion paths (`auth:edge_device`), biometric/MRP/CRM modules except where Production calls them (SalesOrderService::markInProduction, MoldService, StockMovementService).
- Deep rendering math of GanttChart/ShopFloorMap/OeeGauge beyond division/empty guards; notification mail templates; DailyProductionSummary command internals beyond the summary service it feeds.
- Performance under load (dashboard 30s cache noted; OEE trend per-day loop bounded at 92 days).

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Result

This is a read-only re-audit of the requested Production backend/frontend scope at
commit `56e0d431e41d74d422ad81684ff50e0b2b49960e`. No application code, migration,
test, registry, or roadmap files were changed. Existing findings are retained by
ID below; only their current status and materially current evidence are restated.

### Status changes

| ID | Status | Current evidence |
|---|---|---|
| PR-01 | Closed in source | `spa/src/api/factory.ts:8-11` now sends `status[]`; `WorkOrderService.php:97-108` normalizes and validates scalar/array status filters before `whereIn`. `WorkOrderListFilterTest.php:32-84` covers the union and invalid-status cases. |
| PR-03 | Closed in source | `WorkOrderService.php:339-355` and `:477-493` lock the machine and call `assertMachineNotOccupied()` before binding it; the helper is at `:932-946`. `WorkOrderStartResumeOccupancyTest.php:120-220` covers start, resume, and mold-availability conflicts. |
| PR-10 | Closed in source | `TriggerInProcessQC.php:41-117` creates an idempotent in-process inspection on the `in_progress` transition, and `AppServiceProvider.php:329-331` explicitly registers the listener. This does not close the separate floor quick-check defects in PR-05. |

### Unresolved findings

| ID | Category | Severity | Effort | Location | Current evidence / reproduction |
|---|---|---:|---:|---|---|
| PR-02 | Broken process | High | M | `spa/src/pages/factory/RecordOutput.tsx:47-75`; `api/app/Modules/Production/Requests/RecordOutputRequest.php:28-55` | The floor form submits `good_count`, `reject_count`, and remarks only. Any `reject_count > 0` reaches the request validator without `defects`, returns 422, and the UI reduces it to `Failed to record output`. Rejects cannot be classified or recorded from the shop floor. |
| PR-04 | Broken process | High | M | `api/app/Modules/Production/Services/WorkOrderOutputService.php:263-285`; `api/app/Modules/Production/Controllers/WorkOrderController.php:297-308` | `ClosedPeriodException` is outside the receipt-handoff degrade union. A closed-period exception therefore rolls back the output row, WO counters, and mold-shot update and returns 422. Month-end accounting close can still stop physical production capture. `WorkOrderOutputFgReceiptTest.php:424-447` pins this behavior. |
| PR-05 | Broken process | High | M | `spa/src/pages/factory/QcQuickCheck.tsx:69-82,277-330`; `api/app/Modules/Quality/Services/InspectionService.php:332-397` | The FAIL confirmation says it raises an NCR and holds the batch, but the request only creates a Draft inspection with a defect description in `notes`; it does not complete the inspection, call NCR creation, or create a hold. The same create path uses the WO target as the in-process batch/sample basis, so the newly registered automatic trigger (`TriggerInProcessQC.php:75-109`) can scaffold one measurement matrix per target piece. A 10,000-piece WO with several spec items creates thousands of draft measurement rows, while the quick-check sample input is only written into notes. |
| PR-06 | Gap | Medium | M | `api/app/Modules/Production/Services/WorkOrderService.php:506-525` | `complete()` permits `quantity_produced = 0` and does not require good/reject output or reconcile `work_order_materials.actual_quantity_issued` against the material plan. A legacy or manually altered WO can therefore complete with no production evidence or no material issue. |
| PR-07 | Risk | Medium | M | `api/app/Modules/Production/Services/WorkOrderService.php:589-608` | `cancel()` releases only reservations. It does not reverse `MaterialIssue` movements already posted by `start()`, so a started WO cancelled from `paused` leaves warehouse stock consumed against a cancelled order. |
| PR-08 | Risk | Medium | M | `api/app/Modules/Production/Services/WorkOrderOutputService.php:252-260`; `api/app/Modules/Production/Services/WorkOrderService.php:830-842` | Output records pass piece total (`good + reject`) to `MoldService::incrementShots()`, and assignment capacity compares `quantity_target` directly to shot life. A multi-cavity mold therefore consumes/alerts by pieces rather than actual mold shots. |
| PR-09 | Risk | Medium | M | `api/app/Modules/Production/Services/WorkOrderService.php:407-423`; `api/app/Modules/Production/Services/OeeService.php:47-64,246-261`; `api/app/Modules/Production/Models/MachineDowntime.php:19-22` | Pausing creates downtime without checking for another open row on the machine; OEE groups/sums rows without overlap protection and attributes a full row to the window containing `start_time`. `maintenance_order_id` remains fillable but no Production path writes it. An additional current gap is that OEE requires `duration_minutes` to be non-null, so an ongoing breakdown (`end_time = null`, as created at `WorkOrderService.php:412-418`) contributes zero downtime until restoration. |
| PR-11 | Gap | Medium | M | `api/app/Modules/Production/Services/WoOperationService.php:203-232`; `api/app/Modules/Production/Controllers/WoOperationController.php:165-184` | Operation output updates only `wo_operations.qty_completed/qty_scrapped`. There is no `qty_planned` cap and no handoff to `work_orders` totals, mold shots, finished-goods receipt, or the canonical output/defect ledger. The WO detail exposes both ledgers, so operation output can exceed plan while WO progress and inventory remain unchanged. |
| PR-12 | Broken process | Medium | S | `api/app/Modules/Production/Services/ProductionSummaryService.php:52-66` | The breakdown query is `whereBetween(start_time)` OR `whereNull(end_time)` followed by `where(category, breakdown)`. SQL precedence makes any open row a breakdown and any row starting that day match regardless of category. Daily and weekly summary consumers receive misclassified downtime. |
| PR-13 | Bad practice | Low | S | `spa/src/pages/production/schedule.tsx:92-100` | The schedule-confirm mutation has `onSuccess` but no `onError`. A backend conflict or exclusion-constraint rejection leaves the operator with no toast or actionable feedback. |
| PR-14 | Bad practice | Low | S | `api/app/Modules/Production/Controllers/OeeController.php:42-58,70-74` | OEE report and machine windows parse arbitrary date strings with `Carbon::parse()` and do not enforce `from <= to`; malformed input can become a 500 and reversed input can produce an empty/misleading report. Invalid `machine_id` silently falls back to all machines. |
| PR-15 | Bad practice | Low | S | `api/app/Modules/Production/Services/ProductionDashboardService.php:124-156` | `QC Pending`, `Delivered Unpaid`, and `At Risk` remain declared output stages but no sales-order status maps to them. The dashboard permanently renders those stages as zero, presenting incomplete chain information as a real distribution. |
| PR-16 | Stuck process | Low | S | `spa/src/pages/factory/RecordOutput.tsx:50-75`; `api/app/Modules/Production/Services/WorkOrderOutputService.php:177-193` | The floor idempotency key changes only after success. If a key is durably associated with a different payload, every retry reuses it and receives the fingerprint-conflict error; the page shows only the generic failure toast and offers no reset/retry path short of reload. |
| PR-17 | Missing | Low | S | `spa/src/routes/factoryRoutes.tsx:12-25` | Factory routes have `AuthGuard` and `PermissionGuard` but no `ModuleGuard`. The backend still enforces `feature:production`, but the SPA has no module-disabled state and can render the factory shell before the API rejects its requests. |
| PR-18 | Bad practice | Low | S | `api/app/Modules/Production/Controllers/WorkOrderController.php:338-340`; `api/app/Modules/Production/Resources/WorkOrderOutputResource.php:41-45` | Receipt retry loads recorder and defects but not `productionReceiptMovement`. When a movement is linked, the resource conditionally emits `movement_id` as null because the relation is not eager-loaded. |

### New findings

| ID | Category | Severity | Effort | Location | Evidence / reproduction |
|---|---|---:|---:|---|---|
| RA-01 | Gap | High | M | `api/app/Modules/Production/Services/WorkOrderService.php:300-320,1050-1075`; `api/app/Modules/Inventory/Services/StockMovementService.php:108-163` | The start path issues reserved material without a `lotNumber`, so the authoritative `material_issue` movement has no supplier-lot identity. The later WO snapshot searches only the latest `grn_items` row for the item and records its lot, regardless of the locked reservation/location actually issued; it also records BOM quantity rather than actual issued quantity. With two receipts for the same resin, a WO can therefore display the newer lot while consuming the older lot. This is false IATF backward traceability, not merely missing optional metadata. |
| RA-02 | Broken process | Medium | S | `api/app/Modules/Production/Services/OeeService.php:141-163`; `spa/src/components/production/ShopFloorMap.tsx:248-257` | The dashboard machine payload exposes `active_wo` as `wo_number` (`WO-...`), but the Shop Floor Map treats that field as a route ID and links to `/production/work-orders/${active_wo}`. Work-order route binding expects a HashID, so the map's "View Work Order" link for a running machine resolves to 404 rather than the active WO. |
| RA-03 | Stuck process | Medium | M | `spa/src/pages/production/work-orders/create.tsx:32-43,87-98`; `api/app/Modules/Production/Requests/StoreWorkOrderRequest.php:44-45`; `api/app/Modules/Production/Services/WorkOrderService.php:729-742` | The backend supports `service`, `non_stock`, and `prototype` work-order classes with an authorized exception reason, but the manual create form has no class/reason fields and always sends the default standard shape. Creating a no-BOM one-off through the UI produces a standard WO that later fails the start gate (“require an effective BOM/material plan”), while the supported no-BOM exception path is unreachable from the page. |
| RA-04 | Risk | Medium | M | `api/app/Modules/Production/Services/OeeService.php:47-64`; `api/app/Modules/Production/Services/WorkOrderService.php:407-418` | A live machine breakdown is represented by an open downtime row with null duration, but OEE sums only rows with non-null `duration_minutes`. During the entire active breakdown window, availability and OEE can therefore omit the outage and show an overstated result until the restoration listener closes the row. |

### Clean areas

- Durable output idempotency is present: the WO-scoped key and fingerprint are checked against the durable row before the status guard, and the WO row is locked before totals are incremented (`WorkOrderOutputService.php:141-201`).
- WO lifecycle transitions use lock-then-recheck semantics, and committed status changes stage both the outbox and chain records in the transaction (`WorkOrderService.php:231-277,329-389,573-585,800-821`).
- Material reservation is transactional, split across locations when required, and excludes quarantine/scrap locations (`WorkOrderService.php:959-1042`).
- Routing versions are immutable in practice, serialized per product, and guarded by a one-active-version database invariant (`ProductionRoutingService.php:120-151,202-235,267-319`); the focused routing tests cover stale-version and authorization behavior.
- Production resources consistently expose HashIDs for the production-owned identifiers reviewed here, and the output/WO resources return decimal quantities as strings where precision matters.
- Receipt-handoff failures other than closed-period exceptions leave a durable `manual_required` state and replayable outbox request (`WorkOrderOutputService.php:267-301`; `CreateProductionReceiptOnOutputRequested.php:67-93`).

### Verification limits

- This was code-reading only. No PHPUnit, Playwright, browser, Docker, Artisan, queue worker, Redis/Reverb, database, migration, or live HTTP execution was run.
- Focused Production tests were read for context but not executed; their assertions are not current runtime verification.
- MRP scheduler internals, Quality lifecycle internals, Inventory stock-ledger policy, Supply Chain delivery allocation, and shared approval/aggregator infrastructure were inspected only at the Production call sites relevant to these findings.
- The current worktree had unrelated modifications in `audit/accounting-budgeting.md`, `audit/mrp.md`, and `spa/playwright.config.ts`; none were changed.
- No application code, migrations, tests, registry, roadmap, or existing audit content was modified; only this new section was appended to `audit/production.md`.
