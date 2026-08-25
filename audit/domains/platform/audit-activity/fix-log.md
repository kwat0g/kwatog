# Fix Log — Platform / Audit Activity (M004)

Session: 2026-08-25  
Claim: `audit/scripts/claim-module.sh platform audit-activity`  
Final status: ✅ Verified

## 1. Canonical activity producers

- Added `RecordActivityFromEvent` at `api/app/Modules/Admin/Listeners/RecordActivityFromEvent.php:23-74` and registered the wildcard listener in `api/app/Providers/AppServiceProvider.php:199-200`. It projects durable module events plus chain and permission-override events into the existing activity feed, classifies transaction/approval/automation/alert types, resolves model subjects and links, records scalar event context, and logs projection failures without breaking the business event.
- Added `RecordAuthActivity` at `api/app/Modules/Admin/Listeners/RecordAuthActivity.php:14-50` and registered it as the `AuditLog` created observer. Existing `auth.event` rows now become activity entries with the original actor, IP, timestamp, action, severity, and audit-log link.
- Extended `ActivityFeedService::record()` at `api/app/Common/Services/ActivityFeedService.php:31-99` with actor/context overrides, idempotency keys, race-safe `insertOrIgnore`, and JSON-safe query-builder inserts. Event producers deduplicate through the unique key without mutating immutable rows.

## 2. Retention / partition decision

- Removed the dead `CreateAuditLogPartition` command because `audit_logs` is an ordinary table, not a partitioned table; no scheduler or code references remain.
- Added archive-only `activity:archive` at `api/app/Console/Commands/ArchiveActivityEvents.php:20-152`. It archives closed calendar months to locked gzipped JSON files, validates the archive, is rerun-safe, and retains source rows.
- Scheduled activity archiving at `api/routes/console.php:232-237` for 04:05, after the existing audit archive. The archive-only decision is documented in `docs/SCHEMA.md:60-69`.

## 3. Date boundaries and input validation

- Activity `from`/`to` filters now use start/end-of-day boundaries in `api/app/Common/Services/ActivityFeedService.php:121-155`.
- Audit list, CSV/PDF export, and entity-trail inputs are validated and date-only `to` filters include the selected day in `api/app/Modules/Admin/Controllers/AuditLogController.php:146-172` and `244-266`.
- Activity `type` is validated against `ActivityType` in `api/app/Modules/Admin/Controllers/ActivityFeedController.php:29-43`.

## 4. Audit API contract and identifier handling

- Added actor type, source command, correlation ID, reason, and canonical diff metadata to list/detail/entity responses through `api/app/Modules/Admin/Resources/AuditLogResource.php:10-36`, `api/app/Modules/Admin/Controllers/AuditLogController.php:54-90`, and `api/app/Modules/Admin/Support/AuditDiffBuilder.php:8-58`.
- Detail, list, entity, CSV, and PDF surfaces now expose hashed public record IDs; raw numeric decoding is testing-only in `api/app/Modules/Admin/Controllers/AuditLogController.php:259-266`.
- Updated the SPA contract in `spa/src/api/admin/audit-logs.ts:3-45`; audit detail/entity rendering uses the shared diff labels/types and masks encrypted values.

## 5. Complete entity trails

- Added `page`/`per_page` support to the entity API and SPA client, URL-backed Previous/Next controls, and loading/error/empty/data states in `spa/src/pages/admin/audit-logs/entity.tsx:80-223`.
- Entity and detail views now consume the same backend diff metadata, including money/date/enum/datetime formatting and encrypted-field handling.

## 6. Test coverage

- Added `api/tests/Feature/Admin/AuditActivityTest.php:18-181` covering idempotent writes, date boundaries, event classification/replay, auth projection, hashed IDs and CSV output, entity pagination/diff metadata, enum validation, and model immutability.
- Existing `AuditLogSearchTest` and `AuthEventsAuditTest` remain green with the new observer and public-ID behavior.

## 7. Activity-event integrity

- Added `idempotency_key` and PostgreSQL update/delete triggers in `api/database/migrations/0475_harden_activity_events.php:12-63`.
- Added Eloquent append-only guards in `api/app/Common/Models/ActivityEvent.php:21-62`.
- `activity:archive` retains source rows; a no-op `--months=999` run completed successfully.

## 8. Investigation UI

- Added actor and date filters to `spa/src/pages/admin/audit-logs/index.tsx:61-103`.
- Fixed the detail label typo and added audit context rows in `spa/src/pages/admin/audit-logs/detail.tsx:174-211`.
- Preserved list/timeline loading, error, empty, data, and permission-gated states.

## Verification evidence

- `APP_ENV=testing php artisan test --filter=AuditActivityTest`: **8 passed, 28 assertions**.
- `APP_ENV=testing php artisan test --filter=AuditLogSearchTest`: **8 passed, 22 assertions**.
- `APP_ENV=testing php artisan test --filter="AuthEventsAuditTest|MaterialDetailAuditTest"`: **9 passed, 42 assertions**.
- `APP_ENV=testing php artisan migrate:status`: migration `0475_harden_activity_events` **Ran**.
- `APP_ENV=testing php artisan schedule:list`: audit archive at 04:00 and activity archive at 04:05 confirmed.
- Host-side SPA `tsc --noEmit --pretty false --incremental false`: **passed**; targeted ESLint for all four changed audit SPA files: **passed**.
- PHP syntax checks for all new/modified PHP implementation files: **passed**; `git diff --check`: **passed**.
- Pint could not load the repository path because pre-existing `api/pint.json` is a directory; no formatting changes were applied outside this module.
