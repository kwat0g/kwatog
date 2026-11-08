# Real-time and cache health runbook

The Settings → System Info panel and `GET /api/v1/admin/system-health` are available only to users with `admin.settings.manage`. The authenticated endpoint is private and `no-store`.

## Reading the panel

- **Real-time delivery** uses the same queue/outbox health decision as `/api/v1/realtime/health`. Queue/outbox counts are shared environment metrics cached for one second.
- **Reverb TCP** means the API container could open a TCP connection to the configured Reverb host and port within 250 ms. It does not verify a WebSocket upgrade, app-key/signature, channel authorization, or delivery to a browser.
- **Queue and outbox** show aggregate broadcast queue size and failed/pending outbox counts. A processing lease older than two seconds also marks delivery degraded because its queue job may have been lost; stale-lease recovery can take up to ten minutes. The oldest pending age is a diagnostic value, not an event-to-screen latency measurement.
- **Cache Redis memory** reports used and configured maximum memory from the dedicated app-cache Redis connection.
- **Persistent Redis memory** reports used and configured maximum memory from the Redis instance reached by the `generations` store. Redis `INFO memory` is instance-wide, so it includes all logical databases and workloads on that Redis service; it is not a DB 4 or generation-key-only measurement.
- **Latest cache audit** shows the last successful metadata scan, retained for up to eight days. Unknown means no valid summary is available or the audit-summary cache read failed. The scan records counts only; it does not retain keys or cached values.

## When a screen does not update

1. Check the SPA connection indicator and whether the tab is visible. On focus/reconnect the active queries should catch up through their authorized APIs.
2. Check the System Health panel. A growing queue, failed outbox rows, an old pending event, or a stale processing lease means clients may rely on their degraded-mode refresh until delivery recovers.
3. On the local stack, inspect service state and recent logs:

   ```bash
   docker compose ps api horizon reverb scheduler redis redis-cache
   docker compose logs --tail=100 api horizon reverb scheduler
   docker compose exec -T api php artisan queue:failed
   ```

4. Check cache retention without printing keys or values:

   ```bash
   docker compose exec -T api php artisan cache:audit --quiet-keys --limit=1
   docker compose exec -T api php artisan cache:prune-unbounded --limit=500
   docker compose exec -T api php artisan cache:prune-retired-generations --limit=500
   ```

   Both prune commands are dry runs unless `--apply` is supplied. `cache:prune-unbounded` can remove only app-prefixed entries with no TTL; `cache:prune-retired-generations` can remove only registered versioned payload keys whose generation UUID is no longer active and whose TTL is still positive. Each apply run is limited to 500 keys; generation markers, sessions, queues, and framework-owned restart keys are excluded. Do not use `Cache::flush`, `FLUSHDB`, or `FLUSHALL` to troubleshoot freshness.

   Laravel schedules the metadata audit daily at 04:10, the bounded unbounded-key prune at 04:20, and the bounded retired-generation prune at 04:30. The audit reads metadata only; cleanup never reads cached values.

5. Trace one event through the existing event/outbox lifecycle logs, queue worker, authorized private channel, SPA subscription, central invalidation map, and the resulting API refetch. The health panel is an infrastructure signal, not proof that a particular entity event reached its intended viewer.

In dev, API and SPA source are bind-mounted; FPM checks OPcache timestamps every two seconds and Vite HMR applies frontend edits. To keep long-running PHP consumers in sync with source edits, start `make dev-watch-php` in a persistent local shell. Compose Watch restarts Horizon and the mail worker when `api/app`, `api/config`, or `api/bootstrap` changes, and restarts Reverb when `api/config` changes. These actions restart containers without rebuilding their image. The watcher must remain running; it is not enabled by `docker compose up` itself. The local watcher was verified by adding/removing a temporary PHP probe and observing Horizon/mail-worker restarts; Reverb remained up for application-only edits. `.env`, Compose, Dockerfile, dependency, or cached-framework changes still require the guarded `make dev-refresh-php` workflow, which clears Laravel config/route/event/view artifacts and restarts API, Horizon, mail worker and Reverb. It does not clear database or Redis/cache data, and it briefly interrupts requests and socket connections. Production continues to use the deployment checklist in [`DEPLOY.md`](../DEPLOY.md); the dev watcher does not alter it. Verify `php artisan env`, `php artisan horizon:status`, `docker compose ps`, and the public SPA/Vite URLs afterward.

## Current verification boundary

On 2026-11-03, the running local stack's pre/post cache metadata audits inspected 507 entries across payload and generation stores: 0 application entries lacked a TTL, 1 expected persistent queue-restart marker was excluded from app candidates, 0 entries exceeded 1 MiB, and 0 had unknown size. Both `cache:prune-unbounded --apply --limit=500` and `cache:prune-retired-generations --apply --limit=500` found/deleted 0 entries. These are point-in-time dev samples; no cache values or business data were deleted. A separate key-name comparison found zero same-name legacy marker copies. A direct health snapshot on 2026-11-02 reported persistent Redis at 6,960,192 bytes used of 268,435,456 configured and available. No WebSocket handshake/delivery, production metrics, cache hit rate, API p95, or commit-to-UI SLO was measured. The panel does not report the deployed frontend version or independently verify cache-registry completeness.

Delta 735's latest local audit inspected 447 payload/generation entries: no application key lacked a TTL, one expected persistent Laravel infrastructure marker was excluded, and no entries were oversized or had unknown size. Both bounded cleanup dry-runs found zero candidates. Cache Redis used 1.43 MiB / 96 MiB; queue/broadcast Redis used 6.63 MiB / 256 MiB. These values are point-in-time and do not show hit rate, semantic freshness, memory trend, or production conditions.

Delta 745's current local snapshot inspected 448 entries with the same retention result: zero application entries without TTL, one expected persistent marker, and zero oversized or unknown-size entries. Unbounded and retired-generation dry-runs found zero candidates. Cache Redis used 1.50 MiB / 96 MiB (`volatile-lru`), and queue/broadcast Redis used 4.20 MiB / 256 MiB (`noeviction`). The delivery health snapshot was healthy with zero pending broadcast/outbox rows or failures. These are local point-in-time values only.

## Current slice — delta 479: expose persistent Redis memory

The generation-marker store uses the persistent Redis service rather than the evictable payload cache. Added its service-wide used/max memory to the permission-gated system-health response and panel, alongside the existing payload-cache memory metric. This is a read-only Redis `INFO memory` sample; it adds no cache reads/writes and does not expose key names or values. The API health route remains authenticated and `no-store`.

The API feature test first failed because `redis_persistent` was absent, then passed **1 test / 30 assertions** after the response change. The targeted admin health panel test likewise failed on the missing label before the UI change, then passed **3 tests**, including an older-API response without the optional metric. A live local service snapshot returned `available`, 6,960,192 used bytes, and 268,435,456 max bytes. No post-change screenshot, production telemetry, cache hit rate, API latency, or full suite was measured; PHPUnit emitted its existing warning that `/var/www/.env` is absent.
