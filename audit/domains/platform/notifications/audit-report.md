# M006 — Notifications audit report

- Audit session: 2026-08-24
- Domain/module: `platform/notifications`
- Tier/surface: Tier 4 / M
- Dependencies: `auth-session` (discovery available)
- Roles: system admin, HR, Finance, Production, PPC, Purchasing, Warehouse, QC, Maintenance, Impex, department head, employee, driver
- Prior disposition: `🔁 Needs Re-audit`
- Current re-audit disposition: `📋 Plan Ready`

## Re-audit — 2026-08-24

The current worktree still contains the five contained fixes from the prior session; no unrelated edits were made during this re-audit. The fixed paths were rechecked with 48 API tests / 151 assertions, SPA typecheck, lint, and `git diff --check`, all passing. The legacy sender, digest query bound, preference write strategy, send-level idempotency, and frontend interaction semantics remain open as documented below.

The remaining plan is majority `separate-recommended` and includes a Quality-module caller, scheduled-query design, and an idempotency contract decision. Per the audit workflow, this session stops at `📋 Plan Ready` without applying those changes inside M006.

## Scope covered

The module is cross-cutting rather than a single directory. The audit covered:

- API routes, controller validation, ownership scoping, preference updates, and the notification catalog.
- `NotificationService`, realtime broadcast event, notification mail, digest mail, digest command, prune command, schedule, and notification migrations/indexes.
- SPA API typing, notification bell, realtime hook, list page, metadata map, and notification-preferences page.
- Notification producer tests and the catalog/meta drift tests.

## Discovery summary

The authenticated notification endpoints are protected by `auth:sanctum`, session/password middleware, and `notifications.view`; preference endpoints additionally require `notifications.preferences.manage` (`api/app/Modules/Auth/routes.php:43-66`). Notification rows are UUID-backed, polymorphic, and JSON-enveloped (`api/database/migrations/0011_create_notifications_table.php:13-20`). Preference rows have a user/type/channel unique constraint (`api/database/migrations/0012_create_notification_preferences_table.php:13-22`).

The read/list service scopes every query to the authenticated `User` and orders by `created_at,id` for stable pagination (`api/app/Modules/Auth/Services/UserNotificationService.php:14-36`). The controller validates `per_page` 1–100, type, unread-only, and page (`api/app/Modules/Auth/Controllers/NotificationController.php:33-65`). The catalog is editable through settings, but the settings request only validates the outer value as an array (`api/app/Modules/Admin/Requests/UpdateSettingRequest.php:383-385`).

The preferred sender path batches recipient preferences and rows, then defers broadcast/email side effects through `DB::afterCommit` (`api/app/Common/Services/NotificationService.php:83-170`). The per-user realtime channel checks the decoded user identity (`api/routes/channels.php:43-51`). Read pruning runs daily at 02:30 and digest dispatch runs daily at 07:05 (`api/routes/console.php:208-212`, `api/routes/console.php:280-284`).

## Findings and disposition

### F-01 — Broken: email-only recipients were silently skipped — fixed

Before this session, the `in_app` opt-out branch exited the recipient loop before the independent email preference check. A user who disabled in-app delivery but explicitly enabled email received neither channel. The corrected loop evaluates in-app and email independently (`api/app/Common/Services/NotificationService.php:104-139`). Regression coverage is in `api/tests/Unit/NotificationServiceHardeningTest.php:287-323`.

### F-02 — Broken: “Load more” eventually exceeded the API page-size ceiling — fixed

The list page previously multiplied `PAGE_SIZE` by the number of clicks, so the third request could send `per_page=150` even though the API rejects values above 100. It now uses bounded `useInfiniteQuery` pages of 50 and follows `current_page/last_page` (`spa/src/pages/notifications/index.tsx:56-75`, `spa/src/pages/notifications/index.tsx:323-337`). The backend page contract is exercised by `api/tests/Feature/NotificationValidationTest.php:97-108`.

### F-03 — Incomplete hardening: malformed editable catalog JSON could break options/UI — fixed

Only the top-level catalog shape was validated. A stored group with a string `types` value, a missing title, or malformed type entries could reach merge/UI code and cause a type error or an unusable preferences table. `NotificationCatalog` now drops malformed groups/types and supplies safe string defaults before backfilling shipped types (`api/app/Common/Services/NotificationCatalog.php:58-96`, `api/app/Common/Services/NotificationCatalog.php:99-139`). The malformed-snapshot regression is in `api/tests/Unit/NotificationCatalogTest.php:148-171`.

### F-04 — Polish/reliability: rejected Reverb initialization promise was unhandled — fixed

The realtime hook subscribes lazily while the bell already polls every 30 seconds. A failed Echo/Reverb initialization previously produced an unhandled promise rejection. The hook now catches initialization failure and leaves polling as the fallback (`spa/src/hooks/useNotificationRealtime.tsx:28-57`).

### F-05 — Broken/destructive edge case: prune accepted non-positive days — fixed

`notifications:prune --days=0` or a negative value would move the cutoff to the present/future and could delete all read rows. The command now refuses values below one before querying (`api/app/Console/Commands/PruneOldNotifications.php:15-34`), with a no-delete regression at `api/tests/Unit/PruneOldNotificationsTest.php:64-84`.

### F-06 — Incomplete: legacy database-notification path bypasses the module contract — deferred

`NotificationService::notify()` still delegates an arbitrary Laravel notification object directly to `$user->notify()` and only applies the in-app preference (`api/app/Common/Services/NotificationService.php:173-195`). The current Quality caller builds a legacy `subject`/`body` payload and invokes it inside the NCR close transaction (`api/app/Modules/Quality/Services/NcrService.php:269-338`, `api/app/Modules/Quality/Services/NcrService.php:373-395`). That path does not use the standard `title`/`message`/`link_to` envelope, the `UserNotificationCreated` realtime event, or the email preference path; the SPA therefore falls back to metadata instead of rendering the payload consistently. Migrating it requires a Quality-module change and a decision about the legacy wrapper, so no out-of-scope code was changed.

### F-07 — Incomplete/operations: digest bounds email items but not the unread query — deferred

`NotificationDigestService::summarise()` takes at most 20 items, but `unreadFor()` loads every unread row for each opted-in user before that limit is applied (`api/app/Common/Services/NotificationDigestService.php:121-156`). The existing regression proves the email contains 20 items while counting all 25 unread rows (`api/tests/Unit/NotificationDigestServiceTest.php:125-143`); a long-lived unread backlog can still make the scheduled job’s memory grow. A separate design is recommended for a bounded/windowed item query plus an exact count query while preserving the displayed total.

### F-08 — Polish/operations: preference bulk updates are row-by-row — deferred

The API accepts up to 200 preference rows (`api/app/Modules/Auth/Controllers/NotificationController.php:103-115`), while `UserNotificationService::updatePreferences()` executes one `updateOrCreate` per row inside a transaction (`api/app/Modules/Auth/Services/UserNotificationService.php:82-99`). The column-wide SPA control intentionally sends one row per catalog type (`spa/src/pages/self-service/notification-preferences.tsx:87-92`). This is bounded and functionally covered, but a bulk upsert with duplicate-input handling would reduce query volume and make concurrent-tab behavior explicit against the unique key.

### F-09 — Incomplete/design question: send-level idempotency is not defined — deferred

Recipient deduplication exists within one call, but every in-app row receives a fresh UUID in the fan-out loop (`api/app/Common/Services/NotificationService.php:104-126`). A retried producer or redelivered domain event can therefore create another inbox row for the same business event unless the producer prevents it. The follow-up session should decide whether producers own dedupe keys or whether the notification contract needs an event/entity idempotency key and supporting unique constraint.

### F-10 — Polish/accessibility: menu and list interaction semantics need an accessibility pass — deferred

The bell declares `role="menu"` but its links/buttons are not menu items and it has no focus entry/return management (`spa/src/components/layout/NotificationBell.tsx:119-189`). The full list uses a focusable `div role="button"` containing separate action buttons (`spa/src/pages/notifications/index.tsx:243-313`), and the bell treats a failed query as an empty state because it only reads `data` (`spa/src/components/layout/NotificationBell.tsx:40-45`, `spa/src/components/layout/NotificationBell.tsx:136-137`). This is not a server-side access-control issue, but it should be resolved in a browser accessibility pass.

## Verification

- `docker compose run --rm api php artisan test --filter='Notification'` — **PASS**, 101 tests / 264 assertions. The run emitted four pre-existing PHPUnit doc-comment metadata deprecation warnings in unrelated tests.
- `docker compose run --rm api php artisan test --filter='NotificationServiceHardeningTest|NotificationCatalogTest|NotificationValidationTest|PruneOldNotificationsTest'` — **PASS**, 39 tests / 132 assertions.
- `npm run typecheck` in `spa/` — **PASS**.
- `npm run lint` in `spa/` — **PASS**.
- `git diff --check` — **PASS**.
- `npm run test:run` in `spa/` — **BLOCKED before test collection**: Vitest could not write `spa/node_modules/.vite-temp/vitest.config.ts.timestamp-…mjs` because of `EACCES`. This is a local dependency-directory ownership/permissions issue, not a test assertion failure.

Because F-06 through F-10 remain separate-recommended work, the module is released as `📋 Plan Ready` rather than `✅ Verified`.
