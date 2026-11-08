# Add real-time and cache coverage for a record

Use this checklist for new record types and for any mutation added to an existing type. The realtime
payload is a refresh signal; clients must fetch the authorized representation from the API.

## Backend

1. Add the record, every write path, and each transition to `00-record-types.csv`,
   `00-mutation-endpoints.csv`, and `00-explicit-state-transitions.csv`.
2. Reuse the module's existing `*Changed` event and realtime observer when their scope fits. Emit
   through `OutboxService` inside the transaction so the queued broadcast is delivered only after
   commit. Coalesce writes from bulk operations. For writers that bypass Eloquent events, stage the
   same signal explicitly in the service/job/command after its successful write.
3. Keep the event payload content-free: event ID, entity type/opaque ID, action, version or update
   timestamp, actor reference, and related opaque references needed to invalidate queries. Do not
   include the record fields themselves.
4. Define private record/list/user channels in `api/routes/channels.php`. Match each callback to the
   feature and read scope of the API endpoint the listener will refetch. Add positive and negative
   authorization coverage.
5. Return the current `version` and server-computed `available_actions` from the resource where the
   record has actions. Keep action validation and state checks on the server; use the established 409
   conflict response when a state/version changed.
6. Register the cache observer before the realtime observer when both are model observers. Add every
   backend cache family to `cache-registry.md` with its owner, scope, TTL, invalidation boundary, and
   sensitivity. Rotate its generation after commit; never use a global cache flush.

## Frontend

1. Define query keys in the entity's central key module. Include record ID, filters/page/sort, and the
   authenticated identity or permission scope wherever those affect the response.
2. Add the entity to `spa/src/lib/realtime/invalidationMap.ts`, including detail, list, related-record,
   count/badge, dashboard, and inbox families affected by its transitions.
3. Subscribe through `useRealtimeRecord`, `useRealtimeList`, or `useRealtimeSignal`. Do not add page-
   local Echo listeners. Remove the subscription automatically on unmount or identity change.
4. On mutation success, reconcile the actor's cache from the API response, then invalidate linked
   queries through the central map. After a 409, refetch and show the server's conflict message.
   Never overwrite unsaved form input with an event-triggered refetch.
5. Keep authenticated API data out of service-worker caches and persistent browser storage. Clear
   in-memory query data when the user or permission scope changes.

## Verification and records

- Add focused backend coverage for after-commit emission, coalescing, minimal payload, and channel
  allow/deny behavior. Add focused frontend coverage for actor response reconciliation and the
  invalidation map.
- Run one two-context scenario for a high-impact transition when the environment is available: actor
  response updates immediately; a differently scoped viewer receives the signal and refetches through
  their own API; status and actions change without reload. Record actual timings and what was not
  exercised in `01-stale-risk-matrix.csv`.
- Update `00-event-catalog.csv`, `00-broadcast-payload-audit.csv`, `cache-registry.md`, and
  `coverage-report.md`. Source wiring and component tests are not evidence of live socket delivery.

The current project has broader source wiring than runtime proof. Use `PROGRESS.md` as the resume
point and leave unverified matrix cells marked `NOT_VERIFIED` until a qualifying scenario is recorded.
