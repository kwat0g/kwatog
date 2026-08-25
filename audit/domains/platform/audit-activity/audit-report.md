# Audit Report — Platform / Audit Activity (M004)

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
