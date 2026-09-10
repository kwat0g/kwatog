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
