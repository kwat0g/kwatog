# Redis topology for realtime and cache

## Target topology in source

| Workload | Connection | Redis target | Eviction / persistence | Owner |
|---|---|---|---|---|
| Default/application queues | `redis` → Redis connection `default` | `redis`, logical DB 0 | Persistent Redis service, `noeviction`, 256 MiB Compose cap | Horizon/default queue workers |
| Realtime broadcast jobs | `redis_broadcasts` → Redis connection `broadcasts` | `redis`, logical DB 1 by default; host, port, credentials and DB can be overridden | Same persistent `noeviction` Redis service; separate logical keyspace, no extra Redis process | `supervisor-broadcasts` |
| Transitional broadcast backlog | `redis_broadcasts_legacy` → Redis connection `default` | `redis`, logical DB 0 | Existing queue data is retained and drained; never flush the DB | One-off `queue:work` during first cutover only |
| Application cache | Laravel `redis` cache store → Redis connection `cache` | Separate `redis-cache` service, logical DB 0 | Bounded, `volatile-lru`, 96 MiB Compose cap | Laravel cache owners in `cache-registry.md` |
| Cache-generation markers | Laravel `generations` cache store → Redis connection `generations` | Persistent `redis` service, logical DB 4 by default | Same `noeviction` process as queues; each marker has a bounded TTL; never store payload data here | `CacheGeneration::currentDefault()` / `rotateDefault()` |
| HTTP sessions | Laravel session `database` store | PostgreSQL | Database TTL/cleanup policy | Sanctum/session middleware |
| WebSocket delivery | Laravel Reverb via Pusher-compatible HTTP transport | Reverb service on the Compose network | Reverb scaling remains disabled in the local stack | Broadcast workers publish; Echo clients subscribe |

`REDIS_BROADCAST_DB=1` and `REDIS_GENERATION_DB=4` are the defaults in the checked-in environment examples. A deployment that uses an external Redis service must provide logical DBs that exist there or configure the corresponding host/URL. Queue, broadcast, and cache-generation metadata remain on a non-evicting service; cache eviction cannot remove queue jobs or generation markers. PHPUnit overrides only the generation store's driver with an isolated array store.

### Live local development override (2026-11-04)

The current API and Horizon containers resolve the default Redis connection to DB 2 (`REDIS_DB=2`), broadcast jobs to DB 1, cache-generation markers to DB 4, and payload cache to the separate `redis-cache` service/DB 3. The checked-in environment examples still default `REDIS_DB=0`; do not infer a running container's Redis map from those examples. An exact-prefix audit found 11 old `ogami_erp_horizon:*` telemetry keys in DB 0, all idle for over two hours and no other DB 0 keys. Current processes had no DB 0 application connection. The 11 keys were removed atomically with prefix/count/database-size guards; DB 0 was empty afterward, Horizon remained running, and active DB 2/DB 1/DB 4 and cache-store data were preserved. The process that recreated the old DB 0 telemetry is not identified; monitor that namespace. This cleanup is local-runtime-specific and is not a reason to clear DB 0 on another environment.

## First-deploy migration

Before this change, the `broadcasts` queue used a distinct queue name on the same Redis DB as other queue lanes. On the first config rebuild and Horizon restart, new broadcasts go to DB 1. Any old broadcasts already queued in DB 0 remain intact and can be consumed through the retained `redis_broadcasts_legacy` connection:

```bash
docker compose -f docker-compose.prod.yml exec -T api \
  php artisan queue:work redis_broadcasts_legacy --queue=broadcasts --stop-when-empty
```

Run again after any broadcast retry/backoff window, confirm the legacy queue and delayed retries are empty through queue operations telemetry, then remove the one-off drain step in a later change. Do not `FLUSHDB`, `FLUSHALL`, or delete the queue prefix. API configuration, route, event and view caches and long-running Horizon/Reverb processes must be rebuilt/restarted using `DEPLOY.md` so each process uses the same connection and event settings. Production deploy paths now stop consumers before migration, build all four Laravel caches and check realtime settings before consumers resume; API restarts afterward to reload OPcache, and Horizon/Reverb/mail workers start from the new release. Dev HTTP source reload does not reload Horizon's or Reverb's in-memory configuration; use `make dev-refresh-php` after changing PHP or Laravel configuration.

## Evidence and limits

Compose source declares a 256 MiB `noeviction` Redis service and a separate 96 MiB `volatile-lru` cache service. Laravel session configuration defaults to PostgreSQL. Source configuration assigns broadcast jobs DB 1 and generation markers DB 4 on the persistent service, and retains DB 0 compatibility for queued messages during cutover. The focused generation-store integration test confirmed a marker can be written, scanned and cleaned in DB 4. On 2026-11-03, the dev API and Horizon were running with the dedicated broadcast supervisor active on `redis_broadcasts:broadcasts`; the outbox contained 13,523 published rows and no pending/failed rows. Laravel's prefixed queue API reported zero pending jobs in both the `high` and `broadcasts` lanes. This is a point-in-time process/queue check; no cache-memory, hit-rate or latency measurement was run, and production was not inspected.
