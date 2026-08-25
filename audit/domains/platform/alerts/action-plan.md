# M008 — alerts action plan

Audit date: 2026-08-24  
Disposition: Plan Ready

No production source fixes were applied. Alert lifecycle, idempotency,
delivery recovery, retention, and SPA contract decisions cross the scheduler,
notification, API, and dashboard surfaces.

## Ordered work

1. **Define the alert lifecycle and condition identity — F-001, F-004**
   - Scope: [medium/large]
   - Session recommendation: [separate-recommended]
   - Decide whether an alert is a current condition, an acknowledgement
     record, or both. Define stable condition keys, escalation/recovery/
     rearm behavior, dismissal semantics, active counts, and the operator
     meaning of read versus resolved.

2. **Make alert creation concurrency-safe — F-001**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Add a durable deduplication key/constraint or transaction-safe lock,
     handle unique conflicts, and ensure one critical delivery decision for
     concurrent raises. Add PostgreSQL interleaving tests for scheduled,
     manual, and cross-module callers.

3. **Build retryable critical-alert delivery — F-002, F-012**
   - Scope: [medium/large]
   - Session recommendation: [separate-recommended]
   - Choose queued notification versus an outbox, persist delivery attempts
     and failure state, add bounded retry/backoff and operator visibility,
     then confirm the role audience for every critical type. Include provider
     rejection and recovery tests.

4. **Resolve AP due-soon semantics — F-003**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Decide exact offset or inclusive window, align the setting label/API
     contract, use explicit date bounds and application timezone, and test
     every boundary.

5. **Add alert retention/archive controls — F-005**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Set history requirements, protect active/required evidence, add an
     idempotent archive or prune command and schedule, and measure list/count/
     dedup query plans after retention is applied.

6. **Bound the engine and measure production-scale runtime — F-006**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Replace all-at-once loads and repeated lookups with chunked/set-based
     queries, separate detection from delivery, define overlap/timeout
     behavior, and add query-count/runtime tests at expected data volumes.

7. **Align the SPA/backend alert contract — F-007, F-008**
   - Scope: [medium]
   - Session recommendation: [same-session-ok]
   - Represent every backend enum value in the client, add contract coverage,
     and implement accessible pagination or a deliberate infinite list for
     active and dismissed results.

8. **Make read/count behavior coherent — F-009, F-010**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Decide shared versus per-user acknowledgement, align unread-count with
     that decision, wire the page action or remove the unused route, validate
     boolean filters strictly, and test two-user behavior.

9. **Repair run observability — F-011**
   - Scope: [small]
   - Session recommendation: [same-session-ok]
   - Return run-scoped created counts and by-type totals, preserve per-check
     failure context, and emit metrics/log fields that distinguish one run
     from standing table volume.

10. **Build the M008 acceptance suite**
    - Scope: [large]
    - Session recommendation: [separate-recommended]
    - Cover concurrent deduplication, critical email failure/retry, audience
      policy, AP boundaries, recovery/escalation/dismissal, retention safety,
      engine volume/runtime, all enum values, pagination over 50 rows,
      read/count semantics, invalid filter values, route permissions, and
      scheduler partial/dead coverage.

## Re-audit acceptance gates

- Concurrent raises produce one condition record and at most one critical
  delivery attempt per intended event.
- A failed critical delivery remains visibly retryable and eventually records
  success or terminal failure without duplicate fan-out.
- AP due-soon behavior is explicit and tested at all date boundaries.
- Recovered and escalated conditions have deterministic active/resolved/
  dismissed/rearmed state, and counts match their names.
- Alert history has a documented retention/archive path that protects active
  and required audit evidence.
- The engine completes within the measured schedule budget at expected volume,
  with bounded memory/query count and observable partial failure.
- SPA types and icons cover every backend alert type, all pages are reachable,
  and the UI shows authoritative pagination and read state.
- API validation rejects malformed filters and route tests prove both view and
  dismiss boundaries.

## Session decision

Do not apply fixes in this audit session. F-001 through F-006 and F-009
require coordinated lifecycle, delivery, data-volume, or cross-module
decisions; F-012 is an explicit policy question. Although F-007, F-010, and
F-011 are small, they are not a majority of the production-impacting work.
Keep M008 at Plan Ready and schedule an implementation pass followed by a
fresh audit.
