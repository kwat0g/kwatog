# M053 — Maintenance / machine health fix log

Session date: 2026-08-25  
Final status: 🔁 Needs Re-audit

## Production changes

The earlier audit session recorded no changes. This resumed Plan Ready session implemented the in-scope items below.

### Implemented

- M053-F01/F02/F03 — `api/app/Modules/Maintenance/Support/MaintenanceWorkOrderStateMachine.php:15-55` adds the authoritative `TRANSITIONS` matrix and in-progress mutation guard. `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:160-324` now locks/re-reads every lifecycle write, rejects invalid/stale transitions, and records internal lifecycle logs without bypassing external guards. `api/app/Modules/Maintenance/Services/SparePartUsageService.php:35-53` rejects non-`in_progress` issues and locks the stock row. `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php:91-105` and `spa/src/pages/maintenance/work-orders/detail.tsx:133-165` now expose/render only server-authorized actions. Before: completion, assignment, logs, and parts relied on inconsistent terminal guards and desktop could show Complete for any non-terminal order. After: the locked state matrix is authoritative and terminal/non-running mutations return 422.
- M053-F04 — `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:243-253` replaces float/`number_format()` mold lifecycle accumulation with decimal-string `bcadd()` at scale 2. Before: binary float arithmetic could alter repeated fractional totals. After: exact decimal accumulation is used for the mold total and history cost.
- M053-F05 — `api/app/Modules/Maintenance/Services/MaintenanceScheduleService.php:74-121,160-229,252-267`, `api/database/migrations/2026_08_25_190000_add_running_hours_baseline_to_maintenance_schedules.php:14-35`, `api/app/Modules/Maintenance/Jobs/GeneratePreventiveMaintenanceJob.php:53-70`, and `api/routes/console.php:104-107` persist a runtime baseline, backfill it, clear misleading calendar due dates, recompute hours before generation, and trigger on baseline-plus-interval with decimal comparison. Before: cumulative lifetime hours and stale daily ordering could retrigger or delay PMs. After: runtime since schedule creation/last completion is the due signal; completion captures a fresh baseline. Regression coverage is in `api/tests/Feature/Maintenance/MaintenanceScheduleRecomputeRaceTest.php:66-99`.
- M053-F08 — `api/app/Modules/Maintenance/Services/MaintenanceScheduleService.php:31-47,126-141` applies `TrashedFilter` and performs locked transactional delete/restore; `api/app/Modules/Maintenance/routes.php:27-32` enables `withTrashed()` for restore; `api/app/Modules/Maintenance/Controllers/MaintenanceScheduleController.php:66-69` delegates restoration to the service. Before: archive listing and soft-deleted route binding could not complete the lifecycle. After: active/trashed filtering and restoration use the shared conventions and locked writes.
- M053-F09 — `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php:68-84`, `api/app/Modules/Maintenance/routes.php:36-46`, `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php:101-103`, and `spa/src/pages/maintenance/work-orders/detail.tsx:281-312` add the permission-gated active-employee selector and assign action. Before: the permission and API existed without a web surface or resource action. After: authorized users can assign from the desktop detail view using HashIDs.
- M053-F10/F11/F12 — `api/app/Modules/Maintenance/Services/DowntimeAnalyticsService.php:40-300` and `api/app/Modules/Maintenance/Controllers/DowntimeAnalyticsController.php:25-156` apply search consistently, return HashIDs, and clip closed/open intervals to the report window across summary, trend, top-machines, all-machines, and Pareto. `spa/src/pages/maintenance/downtime/index.tsx:87-103` now sends search to every query and `spa/src/types/maintenance.ts:181-194` matches the HashID contract. Before: search refetched unchanged data, raw integer IDs leaked, and stored full durations distorted boundaries. After: all analytics share one validated filter/interval contract.
- M053-F13 — `spa/src/pages/maintenance/work-orders/index.tsx:42-45` subscribes to `maintenance.dashboard` / `.maintenance.wo_created`, invalidates list queries, and keeps a 60-second polling fallback. Before: the broadcast had no SPA consumer. After: the supported work-order list reacts to the event and also refreshes periodically.

## Deferred work

- M053-F06/F07 remain pending. The corrective-MWO handoff and notification destination require coordinated changes in the Production breakdown listener/notification flow and a cross-module idempotency/linking decision. The strict module scope forbids modifying those Production files in this session, so they were not changed or guessed around. The next session should implement the linked corrective MWO and update the notification target together, then re-audit this module.

## Verification

- Compose maintenance suite: 22 passed, 75 assertions.
- Narrow maintenance SPA ESLint check: passed with zero warnings.
- `npm run typecheck`: still reports only pre-existing unrelated errors in `spa/src/pages/assets/detail.tsx:6,67` (`qrcode` module/types); no changed maintenance file appears in the output.
- PHP syntax checks for the Maintenance module, module tests, the new migration, and `api/routes/console.php`: passed.
- `git diff --check`: passed.
- Maintenance route registration check: passed, including assignees and soft-delete restore routes.

## Handoff

See `audit-report.md` and `action-plan.md` for the original findings and ordered implementation sequence. The module was released as `🔁 Needs Re-audit`; the next session should start with M053-F06/F07 and then re-audit the completed acceptance evidence.
