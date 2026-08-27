# Current Action Plan — Platform / Audit Activity (M004)

Re-audit date: 2026-08-27. The gate result is 📋 Plan Ready: no implementation fixes are authorized in this session. The plan is not small and does not have a majority of same-session-ok actions; most work crosses event, retention, or producer contracts.

1. **Correct partial-month archive handling in both retention commands.** Archive only closed buckets or make an existing archive safely complete when the cutoff advances; add boundary tests for audit and activity archives. **Scope:** medium. **Session recommendation:** separate-recommended.

2. **Define stable identity and actor propagation for durable activity projection.** Include the originating actor in RecordActivityFromEvent, preserve it through outbox dispatch, and give MrpReplanRequested a durable subject/request identity so it cannot be silently dropped. **Scope:** medium. **Session recommendation:** separate-recommended.

3. **Normalize activity type and actor taxonomy.** Reconcile direct producer values and portal/self-service actor values with the enum, options endpoint, persistence comments, API response, and SPA types; preserve filtering behavior for all supported values. **Scope:** medium. **Session recommendation:** separate-recommended.

4. **Add regression coverage for the resolved and open contracts.** Cover partial-month archive progression, MRP replan projection, queued actor attribution, taxonomy options/filtering, and activity archive execution; add focused frontend/browser checks for the investigation surfaces. **Scope:** medium. **Session recommendation:** separate-recommended.

5. **Align audit model identity presentation.** Use one display convention for list/detail/entity investigation views and add a small response/UI assertion. **Scope:** small. **Session recommendation:** same-session-ok after the API convention is chosen.

No action is being fixed now. Re-run the focused backend checks and SPA checks after each implementation slice, then reconsider ✅ Verified only when the archive and projection findings have executable evidence.

---

# Historical Action Plan — Platform / Audit Activity (M004)

The module is `📋 Plan Ready`. The plan has two large, cross-module/schema items, so fixes are deferred to a dedicated session rather than partially implementing a misleading subset.

1. **Wire the activity feed to canonical producers.** Add idempotent activity-event production for the intended auth, transaction, approval, automation, alert, and chain events through the existing event/outbox boundaries; define actor/subject/link conventions and add integration tests.  
   **Scope:** large  
   **Session recommendation:** `separate-recommended` — cross-module side effects and event semantics.

2. **Choose and implement a real audit-log retention/partition strategy.** Either migrate `audit_logs` to a PostgreSQL partitioned table with an initial/default partition and schedule/monitor monthly partition creation, or remove the dead partition command and document archive-only retention. Validate the migration and month-boundary failure mode in a clean database.  
   **Scope:** large  
   **Session recommendation:** `separate-recommended` — schema/deployment and data-retention behavior.

3. **Normalize date filters and harden list inputs.** Treat `to` as the end of the selected day in both audit and activity queries; validate audit list/export pagination and filter inputs, and validate activity `type` against `ActivityType`.  
   **Scope:** small  
   **Session recommendation:** `same-session-ok`.

4. **Complete the audit API contract.** Expose `actor_type`, `source_command`, `correlation_id`, and `reason`; return hash IDs consistently in list/detail/entity responses; remove production numeric-ID fallbacks while preserving testing-only compatibility; update SPA types and regression tests.  
   **Scope:** medium  
   **Session recommendation:** `same-session-ok`.

5. **Make entity trails complete and consistent.** Add page controls or an explicit load-more flow, use the same field metadata/encrypted-value handling as the detail view, and verify long trails.  
   **Scope:** medium  
   **Session recommendation:** `same-session-ok`.

6. **Add activity-feed test coverage.** Cover `record()` actor/subject resolution, filters/date boundaries, permission gates, empty/error states, producer integration, and the API/frontend response contract.  
   **Scope:** medium  
   **Session recommendation:** `same-session-ok` after item 1 establishes the producer contract.

7. **Decide and enforce activity-event retention semantics.** If activity events are audit evidence, add append-only model/database protections and archive/retention handling; otherwise document their read-mostly status and allowed lifecycle.  
   **Scope:** medium  
   **Session recommendation:** `separate-recommended` — data-integrity policy and migration.

8. **Finish the investigation UI.** Add date/user filters to the audit list, fix the detail label typo, and add responsive/browser checks for dense tables, timelines, loading, empty, error, and permission-denied states.  
   **Scope:** small  
   **Session recommendation:** `same-session-ok`.
