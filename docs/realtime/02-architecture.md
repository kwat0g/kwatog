# Phase 2 — Freshness contract and target architecture

This architecture was completed after the two-context reproductions and Phase 1 risk review. The user later directed the work to proceed autonomously without waiting for a review response; that direction supersedes the original review hold. This document is the implementation contract. It does not claim the target behavior is already implemented.

## Freshness contract

| Class | Data | User-facing guarantee | Mechanism |
|---|---|---|---|
| Live | Open record details, status, permitted actions, approval decisions, active inventory/production quantities | Other authorized viewers update within 1–2 seconds of commit at p95 on the single-node dev stack | Post-commit minimal event → authorized private record channel → debounce → authorized API refetch. Active-screen polling with backoff is only the disconnected/degraded fallback. |
| Near-live | Filtered lists, counts, sidebar badges, notifications, approval inbox, linked records | Event-driven refetch within 3 seconds on the dev stack | User and permission/scope channels plus centralized entity-to-query invalidation map; coalesce short bursts. |
| Periodic | Analytics, executive dashboards, reports and forecasts | Explicit `as of` timestamp; default maximum age 60 seconds for operational summaries and a documented class-specific TTL for heavier analytics | Bounded TTL with jitter and event invalidation where computation is safe and affordable. |
| Reference/static | Lookups, settings, roles and permissions | Cached until an explicit change signal; new permissions take effect on the next authorized request and clear the current user's client cache | Versioned key or explicit invalidation after commit; permissions/settings have owner-specific invalidators and tests. |

Current implementation note (Delta 793): role dashboard API snapshots now expose their source `generated_at` and the shared shell displays it. Delta 794 records the remaining deviation: the default and role dashboard widget-data hooks still refetch every 60 seconds while connected; retire those intervals only after event-source and authorized consumer coverage is complete. The stated periodic guarantee is a target, not a project-wide verified SLO.

Accounting-period close/reopen and scheduler relock are live state changes for the period list and available actions; their authorized list signal also invalidates the Finance dashboard's current-period panel. The panel remains permission-gated and is re-fetched through the viewer's own API response.

### SLO and measurement

Target for live records: database commit to other authorized browser rendering the refetched state, p95 ≤ 2 seconds; near-live state p95 ≤ 3 seconds. Measure with a trace ID/event ID carried in structured server logs and the development-only debug panel. Record `committed_at`, `broadcasted_at`, `received_at`, `refetch_started_at`, `refetch_completed_at`, and the final query version. Sample at least 100 transitions for each high-priority workflow before claiming p95. The two Phase 0 runs prove connectivity and reproduce failures, but are too few samples for a latency distribution.

The acting user's response is synchronous from its perspective: successful mutation responses include the fresh resource/version/actions when the endpoint already returns a resource; endpoints returning only an acknowledgement invalidate/refetch the actor's relevant queries immediately. A client event is never required for the actor to see their own action.

Clock-derived Action Center overdue/priority/snooze boundaries are returned as `next_transition_at` and refreshed once at that server-provided boundary; this is source-wired but needs timing verification. Alert detection itself is a separate batch computation (`alerts:run`, currently every 15 minutes), so new time-eligible alerts are periodic until source-triggered detection or a measured faster cadence is implemented. Do not use the record-change SLO to imply that batch detector discovery is live.

## Target event and channel path

1. A centralized `RealtimeChangeDispatcher` records one normalized change per entity transition inside the owning database transaction. Change shape: event UUID, entity type, opaque entity ID, action (`created`, `updated`, `deleted`, `status_changed`), monotonically increasing version (or a server timestamp where the entity has no version column), actor ID, committed timestamp, and opaque related-entity references. It contains no field values, business payload, permission details or customer/employee data.
2. The durable outbox row commits atomically with the state change. Delivery starts only after commit on a dedicated `broadcasts` queue with retry/backoff, queue-age metrics and failed-event logging. A transactional outbox recovery command republishes undelivered rows. Bulk work coalesces by type/ID and short time window; one notification per affected aggregate is enough to invalidate.
3. The broadcaster sends only a “changed” signal. Every receiving client refetches through its existing authorized API. The API remains the only source of values and actions.
4. Channels are private and have no business-data payloads:
   - `user.{opaqueUserId}` for the user's own inbox, counts and notifications.
   - `record.{entityType}.{opaqueRecordId}` after the same record policy/scope check used by its detail API.
   - `entity.{entityType}.{opaqueScopeId}` after a centralized permission and exact-scope check compatible with the list API. Scope identifiers are derived server-side; no role-wide channel.
   - `production.operator.{opaqueUserId}` for an employee's own assigned-work-order screens where the self-service API scope is an active Production-department employee assignment rather than the ERP work-order list permission. Only assigned/currently affected accounts are targeted; the channel carries a refresh signal and refetches the employee-scoped API.
   - `production.dashboard` may also authorize `dashboard.plant_manager.view` or `dashboard.ppc.view` for refresh-only production signals; each dashboard API still gates its panels by permission before returning data.
   - `quality.dashboard` authorizes `dashboard.quality.view` for content-free Quality aggregate refresh signals; the Quality dashboard API still gates its panels by permission before returning data. The source observer retires the per-user Quality generation before staging a coalesced signal.
   - `dashboard.plant_manager` authorizes `dashboard.plant_manager.view` for content-free Plant Manager aggregate refresh signals; the API remains the only source of panel values. Cache generations rotate before the coalesced post-commit outbox signal is staged.
   - Badge and approval-inbox refreshes use the authenticated user's private `badge-user.{userHashId}` channel; each viewer refetches their user-scoped API. The event contains no count, approval, or record data.
   - Separate-guard portals use their own authenticated broadcast-auth route. Supplier listing changes can target `supplier.vendor.{opaqueVendorId}` only after matching the active supplier account to that active vendor; internal users cannot authorize the portal channel. Personal B2B notifications use `portal.customer.user.{opaqueUserId}` or `portal.supplier.user.{opaqueUserId}` and authorize only the matching active portal principal; notification payloads are content-free and refetch through that principal's API.
   - No public channel. Presence is optional and is not part of the correctness contract.
5. Channel auth stays on the cookie/Sanctum flow proven in Phase 0. Both `/api/v1/broadcasting/auth` and Laravel's `/broadcasting/auth` compatibility route run through `api` plus `auth:sanctum`, so the shared inactive-session and password guards cannot be bypassed through the alternate path; B2B portals retain their separate guarded endpoints. It must re-evaluate permissions on each channel auth and on role refresh. The central channel registry is allow-listed and maps each entity type to policy, module permission, ID decoder, scope resolver and API fetch family. Unknown mappings deny subscription.

The current `ChainBroadcaster`/outbox path is a useful durable precedent for eight entity types, not the final universal path. Migration must avoid duplicate signals where existing domain events already publish. The event catalog and a guard test will assert exactly one normalized invalidation for each covered transition.

## Server state, versioning and conflicts

- Shared resource helpers will add `version` and `available_actions`/`permissions` where the module policy can determine them. Buttons only render from the server-provided actions; client role-name checks are not authorization.
- The write endpoint remains authoritative. Each action re-checks policy and current state inside the transaction. A stale transition returns a stable 409 problem response with safe message/code, after which the client refetches and shows the conflict.
- Version increments occur with each persisted aggregate mutation. Updates accept the current version or `If-Match`; stale writes return 409. Add columns/backfill only where an existing monotonic field is insufficient. ETags are scoped to the authorized representation.
- A record refresh never replaces unsaved form fields. If the version changes while editing, show an unobtrusive “Updated by …; review changes” banner with reload/compare actions.
- Ignore incoming events with a version not newer than the last applied version. A timestamp fallback must use server time and be paired with an event ID for dedupe.

These are the target contracts; the Phase 0 inventory shows that support is not yet uniform and each module needs migration evidence.

## Frontend real-time layer

- One `RealtimeProvider` owns the Echo connection lifecycle, auth refresh, subscription registry/ref counts, reconnect state, visibility/focus catch-up and multi-tab coordination. Catch-up triggers share one in-flight invalidation pass and retain one trailing pass when a new trigger arrives during a fetch; individual record hooks do not start duplicate fallback loops. Auth `/me` refreshes use the same single-flight-plus-follow-up rule and reject results from an obsolete session generation. Sign-in/sign-out requests are serialized in the SPA to prevent same-tab session mutation reordering. The shared session-sync utility sends only an allowlisted realm/stage/nonce through same-origin BroadcastChannel or the transient storage fallback; a matching tab clears its local QueryClient and re-fetches identity through its own guard. Sign-in and access changes can target one guard; logout and portal password-change target `all` because the current Laravel endpoints invalidate the shared session container. Customer/supplier API identities still come only from their own portal guards; internal ERP identity is never fabricated for portal users. Pages use `useRealtimeRecord`, `useRealtimeList`, and `useRealtimeCounts`; page code does not create Echo channels directly.
- A central invalidation map maps entity types and related references to detail, list, linked-record, dashboard, badge, approval-inbox and global-search query families. Shared system signals use the same map; `BadgesChanged` invalidates active badge, approval and Action Center queries, while `SettingsChanged` invalidates active settings, dashboard, badge, action-center and approval views. Signals contain no setting values; the API remains the authorization boundary. The map debounces repeated pings and invalidates only active/relevant queries. Global-search queries are event-invalidated for the fourteen indexed entity types, with a five-second per-QueryClient minimum interval (12/minute per tab) under event bursts; inactive terms are marked stale and fetch only when enabled. All search refetches pass through the caller's current permission- and row-scoped API. This client cap does not coordinate multiple tabs sharing one user's server-side 30/minute allowance; cross-tab request coordination or a separately reviewed endpoint budget remains required before claiming the near-live SLO. Source-wired only until browser/request-rate verification. The map is unit-tested against the entity/event catalog.
- Query keys use a hierarchical entity prefix plus normalized filters/sort/page and a user/role/scope identity key for permission-dependent data, including vendor identity for supplier-portal listing data. Auth user, role, permission-version, portal-user switch or deploy changes clear the complete in-memory data cache before exposing the new identity. Sensitive transactional data is not persisted.
- Connection status is visible as Live / Reconnecting / Offline, with a last-updated time. `Live` requires the socket and active private channels to be ready **and** the dedicated broadcast delivery lane to have no backlog. No-store `GET /api/v1/realtime/health` returns only a `healthy|degraded` signal derived from outbox-high/broadcasts queue depth plus failed or overdue pending outbox rows; it contains no record, user, or permission data. Pending rows have a two-second enqueue grace. It authorizes internal SPA, supplier-portal, or customer-portal sessions through their separate guards. The provider uses same-origin `fetch` with credentials, no-store, a two-second timeout, and one request in flight; this avoids the internal Axios auth interceptor so an expired portal session cannot redirect into the ERP sign-in realm. It checks every five seconds while healthy and every two seconds while degraded; endpoint failure is treated as degraded. The first connection does not trigger a duplicate global catch-up: active page channels refetch their own registered query families after authorization. Subsequent reconnects and delivery-health transitions perform a global active-query catch-up; a detected backlog also activates visible, exponentially backed-off reads. A healthy response restores Live without re-subscribing unchanged Echo channels. Focus/reconnect also triggers catch-up. The health check fetches no transactional API data. The healthy cadence is 12 checks per visible tab each minute (60% fewer than the former 30); degraded cadence remains 30 per minute. The latest four-context test observed healthy delivery only; controlled load impact remains unmeasured.
- Mutations replace actor cache entries from the API response and invalidate related queries without waiting for Echo. When a successful response contains the fresh detail record, broad related-family invalidation must exclude that exact detail query; this keeps the actor's authoritative response from being overwritten by a redundant or stale refetch while sibling list/count queries still refresh. The central invalidator snapshots excluded query data, cancels older reads with TanStack's normal revert behavior, restores the authoritative snapshot into the now-idle query state, and then refetches affected authorized families. This prevents stale overwrites and canceled-query error states. Acknowledgement-only successes and 409 conflicts refetch the actor's relevant detail. Focus, reconnect, and degraded catch-up never cancel an active read: they invalidate active authorized queries with cancellation disabled, then issue one trailing refetch for non-event queries that were already fetching. Event-owned reads are allowed to finish; a query still idle and invalidated afterward gets one trailing retry. This prevents first-load starvation under repeated degraded-mode catch-up and remains bounded. Optimistic status changes reconcile from the response; a 409 presents the server conflict. Unmount releases shared subscriptions so duplicate listeners do not accumulate.
- A dev-only panel shows channel, event ID, entity, measured latency, invalidated query families and connection state. It must never expose payloads or tokens.

**Bounded recovery exception:** `spa/src/pages/auth/recovery.tsx` has no authenticated ERP or portal `User`; it holds a short-lived recovery bearer token and reads a file-backed restore operation from `BackupManifestStore`, which may continue while the primary database is offline or being replaced. `RequireRecoveryAccess` validates that token and sends `Cache-Control: no-store, private`; `/api/v1/broadcasting/auth` requires `auth:sanctum`, and existing channel callbacks take ERP/portal principals. The active-operation status uses a foreground 2-second query that stops at terminal/error status. It is intentionally not subscribed to a public or ordinary user channel: a future event path must have a dedicated authorizer that validates the recovery token and binds it to the one current operation ID. This is a security-boundary exception, not the degraded-mode fallback for authenticated ERP records.

## Cache policy

### Browser and HTTP

- Authenticated/permission-dependent API success and error responses default to `Cache-Control: private, no-store`; the exception responder applies the same policy as a defense-in-depth layer. Use `private, no-cache` plus ETag only for endpoints explicitly reviewed for revalidation and with correct representation validators. Preserve existing CORS `Vary` dimensions while adding `Cookie, Authorization` where credentials determine representation. Shared proxies never cache API or authenticated responses.
- Static assets keep hashed immutable caching. `index.html` and `sw.js` remain `no-cache`. PWA caches its small shell plus content-hashed `/assets/`; API/Sanctum/transactional endpoints are network-only. Activation removes prior app cache versions; logout clears app-owned caches. Production builds publish a non-sensitive `/version.json` build ID with `no-store`; active tabs check while visible/on focus and at a one-minute interval, then offer Reload/Later without interrupting work. Vite development skips this check so HMR remains immediate.

### Frontend data cache

- Live and near-live queries are stale immediately after a change event and refetch while active; static/reference query TTLs are explicit. Periodic data uses its freshness class TTL and displays `as of`.
- Cache identity includes user, role/permission revision and scope wherever response differs. Clear on logout, login as another user, role/permission switch and app build mismatch. A cache clear also advances the shared QueryClient session revision: mutations capture that revision, are blocked if queued across a scope change, and cannot run success/error/settled callbacks or return an old response after the scope changes. Do not persist server-returned transactional data or sensitive fields. The narrowly scoped browser recovery exceptions are typed form drafts (seven-day TTL, user/access-scope key, sensitive-field blocklist and auth cleanup) and exact-operation idempotency journals (preserved until the ambiguous write is resolved); see the cache registry for each owner and cleanup rule.

### Laravel/Redis

- Every retained entry must have an owner, namespaced/versioned key, exact scope, TTL, size bound, after-commit invalidation, and a regression test in `cache-registry.md`. Cache-generation markers live in the named `generations` store on persistent Redis (DB 4 by default), separate from disposable payloads in the bounded `redis-cache` store (DB 3); the metadata is scanned alongside payload keys. Dashboard summaries, widget payloads, and Finance dashboard data share a seven-day generation marker that rotates after badge-backed commits; user, permission, and scope identity remain in permission-sensitive payload keys. Other dashboard writers still need source audit. Cache arrays/DTOs, not Eloquent objects. Use bounded payloads, jitter/stampede guards for expensive analytics, and no global cache flush.
- Use separate logical Redis databases/instances with explicit environment prefixes. The queue/default lane uses Redis DB 0 on the no-eviction queue store; broadcast jobs use DB 1 by default through `REDIS_BROADCAST_DB` on that same server (or a separately configured broadcast host); transactional cache uses the bounded `redis-cache` service; sessions remain in PostgreSQL. A legacy queue connection preserves a controlled drain path for broadcasts already queued on DB 0. Reverb is single-node with scaling off. Set memory budgets from the 4 GB host and monitor before raising limits.
- Permission/role changes invalidate server permission entries after commit and clear the active browser query cache on the same user's auth revision change. Framework caches are rebuilt and workers/reverb restarted in the documented deploy order.
- A scheduled prefix-scoped audit reports keys without TTL/oversized keys/counts. Separate bounded cleanup removes only TTL-bearing payload keys from registered generation families when their embedded UUID is absent from the durable marker store; it scans the dedicated cache prefix, never queue/session Redis. An audited operator command clears a named cache group, never everything.

## Delivery plan and migration order

The user's later direction authorizes continuing without pausing for Phase 2 review. Keep each unit independently revertable and do not mix unrelated functional defects; record those in `FINDINGS.md` for a separate change.

| Unit / PR | Scope | Exit evidence |
|---|---|---|
| 1. Real-time foundation | Normalized event/outbox, dedicated broadcast queue, channel registry, policy tests, lifecycle logs/health, version/action/conflict shared contracts | Event after-commit/exactly-once tests; authorized/denied channels; queue worker verified |
| 2. Frontend real-time foundation | Provider, ref-counted hooks, central invalidation map, auth/cache identity reset, reconnect/focus catch-up, status/debug UX | Hook/invalidation tests; actor-response and reconnect browser tests |
| 3. Cache correctness foundation | API cache headers, cache registry, versioned/scoped keys, after-commit invalidator, Redis topology hygiene, P2P reproduced key fix | write/read freshness, scope isolation and TTL tests; before/after API timing |
| 4. Approval/status chains | Auth, Leave, Purchasing, Accounting approvals, CRM sales orders, Supply Chain, Inventory receiving, Production | Two-context status/actions/list/inbox test per workflow; matrix rows updated |
| 5. Operations workflows | Quality, Maintenance, MRP, Assets, Attendance, HR/Loans, Payroll | Per-transition event/cache tests and two-context critical action tests |
| 6. Remaining modules/admin | B2B, ReturnManagement, Dashboard/DSS/Forecasting, Admin, Landing and remaining common records | Coverage report accounts for every route/model/async writer, with explicit deferrals |
| 7. Operations & deploy | Redis/realtime metrics, health widget, runbook, stale-config checks, worker/Reverb restart sequence | Local stack restart/catch-up and deployment checks |
| 8. Full verification | Full test suite once at phase end, Playwright two-context scenarios one at a time, final route matrix, cache registry and performance baselines | All claimed cells have evidence; exceptions named |

### Risks and sequencing defaults

- Existing event producers and the event outbox can duplicate pings during migration. The central dispatcher must dedupe by entity/version and tests must reject a duplicate.
- Generic channel access must match actual API policies. Until a type has a registry policy/scope resolver, it stays off private list channels and is explicitly deferred; no broad fallback channel.
- The host is 4 GB RAM. Run one queue worker lane at a time in dev, cap cache memory independently, and run suites sequentially. Reverb remains one node; do not enable Redis pub/sub clustering without evidence it is needed.
- Database schema changes need backfill/rollback checks. No destructive cleanup is part of realtime work.
- Public CDN WebSocket behavior, production deployment process, every role/scope combination, offline floor actions and presence remain open verification items. Default is no presence/offline action queue until product rules and conflict handling are defined.

## Open decisions with defaults

No response is required to continue. Defaults used unless the repository or a safety constraint contradicts them:

1. `If-Match` versus explicit numeric version: use numeric version in resources and support `If-Match` as transport; preserve existing clients that omit the header until each module is migrated.
2. Deleted or newly forbidden detail: refetch returns 404/403; render an explicit unavailable/removed state and release the channel.
3. User activity cue: show the actor's display name only if the receiving user's record-view permission allows it; no presence list by default.
4. Redis isolation: queue gets noeviction; cache is a separate bounded logical DB/store (or service if current Compose supports it without exceeding RAM). Never place cache eviction pressure on the queue DB.
5. Periodic analytics freshness: default 60 seconds plus `as of`; module owners can document a longer report TTL if computation cost requires it.

## Acceptance state at architecture handoff

The architecture and inventory are written; implementation is not yet complete. Phase 0 has two multi-user reproductions and Phase 1 has only those directly verified rows passing. SLO samples, remaining matrix cells, complete cache tests and module migration are still open.


## HR training implementation delta (source-wired; evidence incomplete)

Training catalog and EmployeeTraining changes now use the existing durable outbox path with coalesced after-commit pings, private record/list channels, and central query invalidation. EmployeeTraining detail authorization permits the exact linked employee or an HR viewer with row-scope visibility; catalog channels require the HR feature and catalog view permission. Payloads contain only refresh/event/action/version/time metadata; authorized APIs provide the record values and actions. The daily expiry job is included because its Eloquent updates may change alert metadata and status. The SPA actor reconciles successful API responses, stale completion/cancellation returns 409, catalog updates send `If-Match` and reject a stale locked version with 409, and dirty catalog edits wait for explicit user reload after a remote version change. Older update clients may omit the header to preserve the existing endpoint contract.

This implementation has not been runtime-verified: no HR training test, scheduled run, browser scenario, two-user delivery, cache isolation test, or latency measurement was run under the stop-testing instruction. Its matrix cells remain `NOT_VERIFIED`; source wiring does not establish the 1–2 second SLO.

## Employee master implementation delta (source-wired; evidence incomplete)

Employee Eloquent writes, including the scheduled due-salary adjustment path, now stage a coalesced `EmployeeChanged` outbox signal after commit. SalaryAdjustment create/approval/rejection also stage `SalaryAdjustmentChanged`, so future-dated approval status is visible before the scheduled pay update arrives. Record channels reuse employee row scope; list channels split company-wide readers from current-department readers, linked users receive only their own content-free signal, and salary-adjustment channels require the queue's view permission. SPA employee detail/list and salary-adjustment queue pages subscribe through the shared realtime layer; the central map invalidates employee, salary/payroll, HR, attendance/leave and production crew/readiness query families. The existing Employee badge observer also rotates the shared dashboard generation after commit. API `no-store`, memory-only QueryClient data, identity/access-scope clearing, and the existing bounded cache TTL remain in force.

No tests, build/typecheck or browser session ran after the user's stop-testing instruction. This remains source-wired only; no schedule execution, cache isolation, event delivery, UI freshness, cache hit rate or API latency is claimed.

## Database restore freshness boundary

`DatabaseRestoreCacheInvalidator` is called by both restore and rollback after the snapshot and migrations are installed, before the maintenance gate or paused queues are released. It rotates each registered data-cache owner generation and records the owner-by-owner result in operation metadata. An incomplete rotation leaves service paused for operator recovery. Operational coordination keys and durable idempotency state are preserved. After service resumes, one content-free private `DatabaseRestoreCompleted` event reaches active internal, supplier, and customer sessions; each SPA reloads and fetches through its own current API session. Old generation payloads become unreachable and expire under their owner's TTL. Runtime delivery, Redis behavior, and the additional settings/DSS/payroll marker-read cost remain unmeasured.

### Implementation follow-up — default frontend freshness (Delta 813)

The shared TanStack Query default is `staleTime: 0` for API-backed data so a cached response is revalidated on remount. The in-memory cache remains active; longer lifetimes must be explicit for reference or periodic data and must retain an invalidation/TTL owner in `cache-registry.md`. Remount request-rate impact has not yet been measured.

### Near-live query follow-up (Delta 814)

The shared dashboard attention list and sidebar badge counts use zero `staleTime`, so remounts revalidate these near-live snapshots. Their existing private event signals remain the normal peer-update path; the in-memory cache remains enabled.

The customer portal invoice overdue/unpaid summary also follows the near-live remount contract with `staleTime: 0`; the query is keyed to the portal identity and refetches through the same authorized endpoint.

### Chain bottleneck signal follow-up (Delta 816)

The widget uses the private `dashboard.chain-bottlenecks` refresh-only channel, authorized by the same `dashboard.view_bottlenecks` permission as its API. A model observer stages one transaction-coalesced after-commit outbox signal for detector sources and GRN/return line dependencies; the SPA central signal map invalidates the current user/role/audience query. The API recomputes viewer and delegation scope on every request. The query has `staleTime: 0`; its 60-second periodic check remains for wall-clock SLA threshold crossings, and the widget shows the API's `generated_at`. This is source wiring plus focused tests, not proof of Reverb delivery or target UI latency.

The audit also found `ChainBottleneckController` joins `ApprovalRecord` to resolve the next approver; its Eloquent lifecycle is now registered with the source observer. The widget's asynchronous listener-health section also invalidates from the existing `chain_listener_runs_changed` signal.

The widget's automation summary also reads chain-listener state. `ChainListenerRun` lifecycle changes stage the same minimal bottleneck signal, so viewers with the bottleneck API permission receive updates without needing the separate recovery-list grant.

### Central mount revalidation follow-up — Delta 817

QueryClient derives `refetchOnMount` from the central entity, aggregate-signal and cache-group invalidation maps. A realtime-managed query always revalidates through its existing authorized API function on mount, even when a legacy hook specifies positive `staleTime`; a mapped live key takes precedence even when it contains an `options` segment. Only unmapped reference/option keys keep their registered TTL. In-memory data remains available for rendering and request deduplication; a cached value may remain visible while its refetch is pending. This improves catch-up after an inactive/missed event without replacing action disabling or conflict-safe writes. Additional request cost is unmeasured.

### Audit-log freshness follow-up — Delta 818

The append-only Admin audit-log list and entity trail are near-live data. Every Eloquent `AuditLog::created` write stages a transaction-coalesced outbox ping; after commit it broadcasts only `{refresh,event_id,timestamp}` on private `admin.audit-logs`. Channel authorization requires an active user with the same `admin.audit_logs.view` grant as the API. Both page families invalidate through the central map and refetch with their existing authenticated queries. Responses remain `private, no-store`, SPA data remains memory-only, and the cache registry owns the query family. Focused producer/payload and channel-auth tests pass; browser delivery and the near-live latency SLO remain unverified.

### Cold-start browser cache scope — Delta 982

On successful internal auth resolution, the SPA reconciles persisted app-owned recovery state with `ogami:auth-cache-scope:v1`, which stores only the user ID, an opaque access fingerprint and a timestamp. Missing markers with existing persisted state, malformed or expired markers, and identity/access-scope changes clear app-owned retry/draft storage before the authenticated subtree is exposed. Same-scope retries may survive a cold reload; logout removes both retries and the marker. The marker expires after 30 days and does not grant access: every retry/read still passes through the authorized API. This is unit-tested storage behavior; cross-browser and tablet isolation remain unverified.
