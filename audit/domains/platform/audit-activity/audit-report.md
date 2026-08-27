# Re-audit Report — Platform / Audit Activity (M004)

Audit session: 2026-08-27

Claim: CLAIMED for platform/audit-activity (M004); fallback was not used.

Scope: this module only. Dependency modules were read for integration context and were not modified.
Final gate: 📋 Plan Ready; no implementation fixes were applied because the current plan is cross-module and not small.

## Re-audit conclusion

The prior hardening work is present: activity projection is registered, auth activity is mirrored, date-only filters use day boundaries, audit context and hash IDs are exposed, entity trails paginate, and activity events have model/database immutability protections. The focused backend and SPA static checks pass on the unique audit database.

The module is not ready for ✅ Verified. Two archive commands can permanently skip rows from a partially expired month, one durable MRP event is silently omitted from the feed, queued event actor IDs are not propagated, and producer taxonomy is wider than the API/UI contract. Those findings require coordinated behavior decisions and regression coverage.

## Discovery and current evidence

- audit/00-MODULE-REGISTRY.md listed M004 as 🔁 Needs Re-audit; the atomic claim succeeded for the preferred target.
- The admin routes and SPA route guards are present for both activity and audit-log surfaces (api/app/Modules/Admin/routes.php:25-33,101-121; spa/src/routes/dashboardRoutes.tsx:101-105; spa/src/routes/adminRoutes.tsx:118-142,197-198).
- The event wildcard projection is registered in api/app/Providers/AppServiceProvider.php:195-200; auth projection supplies actor, IP, and timestamp context in api/app/Modules/Admin/Listeners/RecordAuthActivity.php:31-44.
- Activity filtering now treats from/to as inclusive day boundaries (api/app/Common/Services/ActivityFeedService.php:132-137), and the focused activity tests cover idempotency, date boundaries, event projection, API hashes/context, entity trails, validation, and immutability (api/tests/Feature/Admin/AuditActivityTest.php:29-189).
- Retention is archive-only and scheduled monthly (api/routes/console.php:243-266), but the archive boundary behavior remains defective as described below.

## Findings

### F-01 — Broken — archive commands can permanently skip a partially expired month

Both commands derive a cutoff at the start of the day (api/app/Console/Commands/PruneAuditLogs.php:57; api/app/Console/Commands/ArchiveActivityEvents.php:45), select any month containing at least one row before that cutoff (PruneAuditLogs.php:67-72; ArchiveActivityEvents.php:54-59), and then skip the entire month whenever a valid final archive already exists (PruneAuditLogs.php:98-103; ArchiveActivityEvents.php:82-85). The archive query itself includes only rows before the cutoff (PruneAuditLogs.php:111-114; ArchiveActivityEvents.php:92-95).

If the cutoff falls inside a month, the first run writes only the expired prefix. Once the remainder of that month becomes eligible, the valid archive causes the command to skip it, so the later rows never enter the archive. Source rows are retained by policy, but the promised archive is incomplete and cannot be repaired by the scheduled rerun.

### F-02 — Missing — MrpReplanRequested is silently omitted from the activity feed

The wildcard projector requires either a model subject or one of the recognized request/run identities; otherwise eventIdentity() returns null (api/app/Modules/Admin/Listeners/RecordActivityFromEvent.php:200-219). MrpReplanRequested carries only salesOrderIds, reason, and initiatedBy (api/app/Modules/MRP/Events/MrpReplanRequested.php:14-19), and both durable dispatch sites construct that event without a model or request ID (api/app/Modules/MRP/Services/BomService.php:181-184; api/app/Modules/Production/Services/ProductionRoutingService.php:522-525). The projector therefore returns before ActivityFeedService::record() is called.

Automatic replans initiated by BOM or routing changes leave no activity evidence even though they cross the durable outbox boundary.

### F-03 — Incomplete — queued event actor attribution falls back to System

RecordActivityFromEvent::handle() forwards the description but does not pass actorUserId, actorType, or event time to the feed (api/app/Modules/Admin/Listeners/RecordActivityFromEvent.php:55-64). The affected durable events carry explicit actor fields: MrpPlanGenerated::$initiatingActorId (api/app/Modules/MRP/Events/MrpPlanGenerated.php:23-28), PayrollComputationRequested::$triggeredBy (api/app/Modules/Payroll/Events/PayrollComputationRequested.php:22-27), and YearEndLeaveProcessingRequested::$runById (api/app/Modules/Leave/Events/YearEndLeaveProcessingRequested.php:23-28). The outbox dispatcher re-emits the decoded event inside dispatch context without restoring an authenticated user (api/app/Common/Services/OutboxDispatcher.php:32-42).

For worker-delivered events, ActivityFeedService::record() therefore defaults the actor to System despite the originating actor being available on the event. This makes the activity trail materially less useful for accountability.

### F-04 — Incomplete — persisted activity taxonomy is wider than the API/UI contract

The endpoint exposes and validates only the five ActivityType enum cases (api/app/Modules/Admin/Enums/ActivityType.php:7-15; api/app/Modules/Admin/Controllers/ActivityFeedController.php:23-42), while direct producers persist hr and production.work_order (api/app/Modules/HR/Services/OnboardingService.php:114-129; api/app/Modules/Production/Services/WorkOrderService.php:611-619). Those rows can exist, but the activity endpoint rejects those values as a filter and the options response cannot offer them. The actor contract also declares only user | system in the SPA (spa/src/types/activity.ts:16-23), while auth audit producers persist self_service, supplier_portal, and customer_portal (api/app/Modules/Auth/Services/AuthAuditLogger.php:18-43, mirrored by api/app/Modules/Admin/Listeners/RecordAuthActivity.php:31-44).

The result is a split contract: valid persisted records are not consistently discoverable or representable by the admin surface.

### F-05 — Polish — audit model identity is formatted inconsistently across views

The list resource normalizes model_type for display (api/app/Modules/Admin/Resources/AuditLogResource.php:14-20), while the detail response returns the stored fully qualified value (api/app/Modules/Admin/Controllers/AuditLogController.php:64-72) and the detail page renders that value directly (spa/src/pages/admin/audit-logs/detail.tsx:100-112). The same audit record can therefore show a short model name in the list and a namespace-qualified name in detail. This is low-risk presentation drift, but it weakens investigation continuity.

### F-06 — Missing — current failure modes lack focused regression coverage

PruneAuditLogsTest covers one wholly expired month and corrupt-file rebuild only (api/tests/Feature/Infrastructure/PruneAuditLogsTest.php:17-55); there is no boundary case for a cutoff inside a month and no focused activity:archive test. AuditActivityTest does not cover MrpReplanRequested omission or queued actor propagation (api/tests/Feature/Admin/AuditActivityTest.php:29-189), and no module-specific SPA test/browser file exists under spa/src for these screens. Typecheck and lint can pass while these integration contracts remain broken.

## Prior finding disposition

The earlier report's producer, date-boundary, context, hash-ID, entity-pagination, activity-immutability, and visible-label issues were rechecked. The corresponding implementation is now present in AppServiceProvider, ActivityFeedService, AuditLogController, AuditLogResource, ActivityEvent, the immutability migration, and the SPA pages. The current findings above supersede the prior “no producers”, raw date-boundary, missing context, and missing-coverage conclusions; the archive, event identity/actor, taxonomy, and regression gaps remain open.

## Verification

- Unique DB: DB_DATABASE=ogami_test_m004_agent_c; migrations are complete, including 0475_harden_activity_events and the audit immutability migration.
- AuditActivityTest: 8 passed, 28 assertions.
- AuditLogSearchTest: 8 passed, 22 assertions.
- AuthEventsAuditTest, MaterialDetailAuditTest, PruneAuditLogsTest: 12 passed, 52 assertions.
- SPA npm run typecheck: passed; targeted ESLint for activity/audit files: passed.
- PHP syntax checks for audited backend files: passed; git diff --check: passed.
- Route and schedule checks confirmed the activity endpoints and monthly audit:prune/activity:archive jobs.
- The full suite and browser automation were not run; they are deferred in the action plan. No current execution blocker prevented the focused verification.

---

# Historical Audit Report — Platform / Audit Activity (M004)

Audit session: 2026-08-24 04:19–04:28 Asia/Manila  
Scope: the audit log and activity-feed module only. Dependency modules were read for integration context and were not modified.

## Executive finding

The admin audit-log surface is wired end to end and the existing focused audit tests pass, but the module is not complete for production: the company-wide activity feed has no production writers, and the advertised audit-log partition command cannot run against the table created by the migrations. Several API/UI consistency and investigation-quality gaps remain.

## Discovery

- `audit_logs` is created by `api/database/migrations/0008_create_audit_logs_table.php:13-28`, with the `HasAuditLog` observer writing create/update/delete rows from `api/app/Common/Traits/HasAuditLog.php:19-50`.
- `activity_events` is created by `api/database/migrations/0131_create_activity_events_table.php:20-55`; `ActivityFeedService::record()` is the only defined writer at `api/app/Common/Services/ActivityFeedService.php:28-55`.
- Admin routes exist for both surfaces: activity feed at `api/app/Modules/Admin/routes.php:28-32`, audit-log list/detail/entity/export at `api/app/Modules/Admin/routes.php:95-115`. The SPA routes are guarded by `admin.activity.view` and `admin.audit_logs.view` in `spa/src/routes/dashboardRoutes.tsx:103-107` and `spa/src/routes/adminRoutes.tsx:118-142,196-198`.
- The audit log has a broad model-trait adoption surface; the focused backend checks passed: `AuditLogSearchTest`, `MaterialDetailAuditTest`, `AuthEventsAuditTest`, and `PruneAuditLogsTest` — 18 tests, 71 assertions. SPA typecheck and targeted ESLint also passed.

## Findings

### F-01 — Missing — activity feed has no production event producers

`ActivityFeedService::record()` is documented as a helper for listeners and automation jobs (`api/app/Common/Services/ActivityFeedService.php:18-20`) and the migration says events are written by listeners (`api/database/migrations/0131_create_activity_events_table.php:12-16`), but a repository-wide search finds no call site outside the service itself. The controller only reads via `feed()` (`api/app/Modules/Admin/Controllers/ActivityFeedController.php:30-76`). The `/admin/activity` page therefore remains empty in normal operation except for data inserted manually or by future work.

Impact: the shipped system-activity feature does not surface the chain milestones, approvals, automation, alerts, or auth activity promised by its schema and UI.

### F-02 — Broken — monthly audit-log partition maintenance cannot run

`CreateAuditLogPartition` executes `CREATE TABLE ... PARTITION OF audit_logs` (`api/app/Console/Commands/CreateAuditLogPartition.php:64-77`), but the base migration creates an ordinary table and never declares `PARTITION BY` (`api/database/migrations/0008_create_audit_logs_table.php:13-28`). A direct test invocation against the configured PostgreSQL test database failed with: `"audit_logs" is not partitioned`. The command is also not scheduled in `api/routes/console.php:221-230`; only the archive command is scheduled there.

Impact: the partition command is a dead operational path, while the code comments claim future inserts could fail at a month boundary. This needs a coordinated schema/deployment decision, not a local command-only patch.

### F-03 — Broken — inclusive end-date filters exclude almost the entire selected day

The audit-log controller converts `to` to a date and compares timestamps with `<=` (`api/app/Modules/Admin/Controllers/AuditLogController.php:209-214`). The activity feed makes the same raw-string comparison (`api/app/Common/Services/ActivityFeedService.php:85-89`). The SPA sends date-only values from its `type="date"` inputs (`spa/src/pages/admin/activity/index.tsx:113-124`). A request for `to=2026-08-24` therefore ends at midnight and omits events later that day.

### F-04 — Incomplete — material audit context is stored but not exposed to investigators

`HasAuditLog` records `actor_type`, `source_command`, `correlation_id`, and `reason` (`api/app/Common/Traits/HasAuditLog.php:30-50`), and the migration adds those columns (`api/database/migrations/2026_08_13_120000_add_material_audit_metadata.php:13-18`). Neither `AuditLogResource` (`api/app/Modules/Admin/Resources/AuditLogResource.php:13-32`) nor the detail response (`api/app/Modules/Admin/Controllers/AuditLogController.php:50-70`) returns them. The detail SPA consequently cannot show the reason, request correlation, or system source for a sensitive operation.

Impact: the audit record is richer than the evidence available to the administrator reviewing it.

### F-05 — Incomplete — entity audit trail silently truncates at 100 rows

The API paginates the entity trail at 100 rows (`api/app/Modules/Admin/Controllers/AuditLogController.php:140-151`), and the client requests only the default page and renders it without pagination controls (`spa/src/pages/admin/audit-logs/entity.tsx:87-100,166-223`). A high-churn record can therefore appear to have only the first 100 entries even though more exist.

### F-06 — Incomplete / hardening — hash-ID contract is inconsistent at the audit boundary

The shared hash-ID convention permits numeric route values only in testing (`api/app/Common/Traits/HasHashId.php:30-42`). Audit-log `show()` accepts numeric IDs in every environment and returns the raw target `model_id` (`api/app/Modules/Admin/Controllers/AuditLogController.php:40-55`); entity trails likewise accept raw numeric IDs outside the shared trait (`api/app/Modules/Admin/Controllers/AuditLogController.php:135-138`). Meanwhile the list resource emits a hash string (`api/app/Modules/Admin/Resources/AuditLogResource.php:14-18`), while the SPA types list/detail `model_id` as `number` (`spa/src/api/admin/audit-logs.ts:4-15`, `spa/src/pages/admin/audit-logs/detail.tsx:29-40`).

Impact: production API behavior and client types disagree, and the audit surface reintroduces sequential identifiers that the rest of the application deliberately hides.

### F-07 — Incomplete / polish — investigation UI is inconsistent across audit pages

- The audit-log list API supports `user_id`, `from`, and `to`, but the list page exposes only action and model-type filters (`spa/src/pages/admin/audit-logs/index.tsx:95-108`).
- The entity trail renders raw field values with a generic formatter (`spa/src/pages/admin/audit-logs/entity.tsx:28-85`), while the detail endpoint has field metadata and encrypted-field handling (`api/app/Modules/Admin/Controllers/AuditLogController.php:225-257`). Money, dates, enums, and encrypted-field presentation can therefore differ between two views of the same audit row.
- The detail context label contains a visible typo, `LuUser agent` (`spa/src/pages/admin/audit-logs/detail.tsx:177-182`).

### F-08 — Missing — activity-feed behavior has no automated coverage

There is no activity-feed service/controller/page test under `api/tests` or `spa/src`. The existing focused tests cover audit-log search/export, model observer redaction, auth-event mirroring, and archive generation, but not `ActivityFeedService::record()`, filters, actor rendering, empty-feed behavior, or the missing producer integration.

### F-09 — Question / incomplete hardening — activity-event immutability policy is undefined

`activity_events` is described as “read-mostly” (`api/database/migrations/0131_create_activity_events_table.php:12-16`), but unlike `AuditLog`, `ActivityEvent` has no update/delete guard (`api/app/Common/Models/ActivityEvent.php:15-33`) and no database immutability trigger. There is currently no mutation endpoint, so this may be intentional. Confirm whether activity events are evidence that must be append-only; if so, enforce the same policy as `audit_logs` before adding producers.

## Polish pass against `docs/DESIGN-SYSTEM.md`

The pages use the shared `PageHeader`, `Button`, `Chip`, `DataTable`, `Panel`, loading, error, and empty-state components. The activity feed uses semantic text plus severity colour and supports keyboard activation for linked rows (`spa/src/pages/admin/activity/index.tsx:153-198`). The remaining polish gaps are the missing audit filters, entity pagination, inconsistent value formatting, and the detail-label typo above. No module-specific frontend test or browser evidence was available.

## Evidence checked

- Backend module models, observer, service, controllers, resource, routes, migrations, archive/partition commands, permission seeder, and PDF view.
- SPA API contracts, activity/audit pages, route guards, and design-system requirements.
- Focused backend test command: 18 passed, 71 assertions.
- SPA `npm run typecheck`: passed.
- Targeted ESLint for audit/activity files: passed.
- Direct `audit-log:create-partition --env=testing`: failed because `audit_logs` is not partitioned.
