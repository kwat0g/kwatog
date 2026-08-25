# M006 — Notifications fix log

Session: 2026-08-24  
Claim: `platform/notifications` claimed successfully before changes.  
Initial release disposition: `🔁 Needs Re-audit`; re-audit disposition: `📋 Plan Ready` because the remaining plan is majority separate-recommended.

## F-01 — Independent channel preference evaluation

- Before: `api/app/Common/Services/NotificationService.php:104-136` used the in-app opt-out as an early `continue`, preventing the email branch from running for an email-only recipient.
- After: `api/app/Common/Services/NotificationService.php:104-145` builds in-app rows only when in-app is enabled, then evaluates explicit email opt-in independently; empty row/email batches are safe no-ops.
- Test: `api/tests/Unit/NotificationServiceHardeningTest.php:287-323` proves no in-app row, one queued email, and no realtime event for an in-app-disabled/email-enabled user.

## F-02 — Bounded infinite pagination

- Before: `spa/src/pages/notifications/index.tsx` multiplied the fixed page size by click count, eventually sending a `per_page` value above the backend’s 100-row ceiling.
- After: `spa/src/pages/notifications/index.tsx:56-75` requests 50-row pages with an explicit page parameter; `spa/src/pages/notifications/index.tsx:323-337` follows `last_page` and fetches only the next bounded page.
- Test: `api/tests/Feature/NotificationValidationTest.php:97-108` proves `per_page=2&page=2` returns the later page correctly.

## F-03 — Safe catalog normalization

- Before: `api/app/Common/Services/NotificationCatalog.php:58-96` merged the editable top-level array without validating nested group/type shapes.
- After: `api/app/Common/Services/NotificationCatalog.php:58-60` normalizes before merging; `api/app/Common/Services/NotificationCatalog.php:107-139` drops malformed entries and supplies safe defaults.
- Test: `api/tests/Unit/NotificationCatalogTest.php:148-171` exercises malformed groups, malformed types, and default backfill.

## F-04 — Realtime initialization fallback

- Before: `spa/src/hooks/useNotificationRealtime.tsx` attached no rejection handler to the lazy `getEcho()` promise.
- After: `spa/src/hooks/useNotificationRealtime.tsx:28-57` catches initialization failure and explicitly leaves the bell’s polling path in place.
- Verification: `npm run typecheck` and `npm run lint` both pass.

## F-05 — Safe prune window

- Before: `api/app/Console/Commands/PruneOldNotifications.php:15-23` accepted zero/negative `--days` values and calculated a broadened cutoff.
- After: `api/app/Console/Commands/PruneOldNotifications.php:15-34` returns command failure before the delete when days is below one.
- Test: `api/tests/Unit/PruneOldNotificationsTest.php:64-84` asserts `--days=0` fails and leaves the notification row intact.

## Verification record

- API focused run: 39 passed / 132 assertions.
- API notification regression run: 101 passed / 264 assertions.
- SPA typecheck: passed.
- SPA lint: passed.
- Diff whitespace check: passed.
- SPA Vitest: blocked before collection by `EACCES` writing `spa/node_modules/.vite-temp`; no test assertions ran.

## Re-audit — 2026-08-24

- No new code changes were made. The five deferred findings were rechecked against the current source and remain open: legacy `notify()` migration, digest query bounding, preference bulk-write strategy, send-level idempotency, and frontend interaction semantics.
- Rechecked tests: 48 API tests / 151 assertions passed, including digest, catalog, service hardening, validation, and prune coverage.
- Rechecked frontend gates: `npm run typecheck`, `npm run lint`, and `git diff --check` passed.
- `npm run test:run` remains blocked before collection by root-owned `spa/node_modules/.vite-temp` (`EACCES`); no assertion result is available.

## Fix session — 2026-08-25

### F-06 — Legacy Quality caller deferred

- Before/after: `api/app/Modules/Quality/Services/NcrService.php:373-395` still uses the legacy `NotificationService::notify()` wrapper and anonymous database notification payload.
- Deferred because the session constraint forbids modifying the Quality module from the notifications module. The migration also needs a cross-module contract decision for the NCR title/message/link envelope.

### F-07 — Bounded digest backlog fixed

- Before: `api/app/Common/Services/NotificationDigestService.php:54-89` loaded every unread notification for each opted-in user, then capped only the rendered email items.
- After: `api/app/Common/Services/NotificationDigestService.php:58-66,129-189` uses an exact grouped count query plus a `ROW_NUMBER()` window query that materialises only the newest configured item window per user.
- Tests: `api/tests/Unit/NotificationDigestServiceTest.php:145-182` verifies a 100-row backlog produces a 20-item email, an exact total, one count query, and one windowed item query. The focused digest test and bounded-backlog test pass.

### F-08 — Preference bulk write fixed

- Before: `api/app/Modules/Auth/Services/UserNotificationService.php:85-99` performed one `updateOrCreate` call per submitted preference row.
- After: `api/app/Modules/Auth/Services/UserNotificationService.php:89-111` deduplicates composite keys with last-row-wins semantics and performs one transaction-scoped `upsert` against the existing unique key.
- Test: `api/tests/Feature/NotificationControllerTest.php:127-150` verifies duplicate keys leave one row with the final submitted value; the focused controller suite passes.

### F-09 — Send-level idempotency deferred

- No code change. Ownership of a stable event/entity key versus a service/database-generated idempotency contract remains a product and cross-producer decision. Adding a unique index without that decision could suppress legitimate repeated notifications.

### F-10 — Notification interaction accessibility fixed

- Before: `spa/src/components/layout/NotificationBell.tsx:143-151` exposed a menu role without menu-item semantics or focus return, and the bell treated query errors as an empty state. `spa/src/pages/notifications/index.tsx:242-301` used a focusable `div role="button"` containing action buttons.
- After: the bell uses a labelled non-modal dialog with trigger linkage, focus entry/return, native keyboard controls, and a distinct retry error state (`spa/src/components/layout/NotificationBell.tsx:90-170`). List rows now use a standalone content button with sibling action buttons (`spa/src/pages/notifications/index.tsx:243-301`).
- Verification: targeted ESLint for both notification files passes. Full `npm run lint` remains red only on four unrelated pre-existing files; full `npm run typecheck` remains red only on unrelated missing `qrcode`/JSX issues.

### Session verification notes

- `docker compose run --rm api php artisan test tests/Unit/NotificationDigestServiceTest.php --filter='test_only_opted_in_users_are_emailed'` — PASS.
- `docker compose run --rm api php artisan test --filter='NotificationDigestServiceTest|NotificationControllerTest'` — 17 passed; one test was blocked by a PostgreSQL `RefreshDatabase` deadlock caused by concurrent sessions sharing the test database, then the affected test passed in isolation.
- `git diff --check` — PASS for all changed notification source/test files.
- A later whole-file digest retry again ran 9/10 tests successfully; its first test hit a concurrent migration race (`journal_entries` was dropped by another `RefreshDatabase` session), confirming the shared-test-database limitation rather than a digest assertion failure.
