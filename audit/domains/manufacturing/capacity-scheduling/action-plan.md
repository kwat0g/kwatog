# M050 — Capacity Scheduling Action Plan

**Status:** 📋 Plan Ready  
**Audit report:** [audit-report.md](./audit-report.md)

The plan is intentionally staged around the canonical schedule state and resource model. Most items are large or cross-module; no implementation fixes were made in this audit session.

## Ordered actions

1. **Define the authoritative schedule/work-order lifecycle (M050-001, M050-014).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - Specify pending, confirmed, started, paused, executed, superseded, cancelled, and disrupted semantics. Wire completion/cancellation/breakdown effects through a durable, idempotent transaction/listener path and reconcile existing rows.

2. **Build a complete machine and mold resource ledger (M050-002, M050-003).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - Reserve machine and mold time together, account for current running work, accumulate mold shots across proposals, and revalidate the same occupancy model at confirmation. Add concurrent and cumulative-capacity tests.

3. **Add real finite-capacity calendars (M050-004).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - Define shifts, working hours, operator/skill requirements, planned maintenance, downtime, and resource exceptions. Integrate Maintenance without silently treating a current status as a future calendar.

4. **Decide and implement operation-level routing scheduling (M050-005).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - Confirm whether M050 is injection-job-only or the finite scheduler for routings. If routing-driven, schedule `WoOperation` rows with sequence precedence, alternate resources, setup/cycle/labor data, and MES feedback.

5. **Set the planning-window and dispatching policy (M050-006).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - Add bounded horizon input, due-date/lateness diagnostics, EDD or documented setup-aware sequencing, and explicit expedite/priority policy. Make manual reorder semantics part of the same policy.

6. **Recover and edit pending proposals safely (M050-007, M050-008).**
   - **Scope:** medium
   - **Session recommendation:** separate-recommended
   - Add a server-backed pending-proposal list, ownership/age, selection and refresh/revalidation, explicit discard/expiry, and working reorder/reassign controls. Do not rely on component memory for a persisted planning state.

7. **Correct and bound snapshot queries (M050-009, M050-010).**
   - **Scope:** medium
   - **Session recommendation:** separate-recommended
   - Use interval-overlap filtering, validate dates and range, enforce a maximum horizon, return useful 422s, and replace per-machine querying with a bounded grouped query.

8. **Align scheduler, dashboard, and work-order schedule sources (M050-012).**
   - **Scope:** medium
   - **Session recommendation:** separate-recommended
   - Choose whether `production_schedules` or work-order planned dates are canonical for proposed and committed work. Update the PPC rich/scalar widgets, detail page, and tests to use the same status/window contract.

9. **Make disruption response actionable (M050-014).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - On breakdown or planned maintenance, identify affected future rows, freeze in-process work, calculate impact, and queue an authorized replan with stability-window and notification behavior.

10. **Harden API identity, storage invariants, and locking (M050-011, M050-015, M050-016).**
    - **Scope:** medium
    - **Session recommendation:** separate-recommended
    - Return hash IDs consistently, add temporal/confirmation invariants, preflight existing rows, standardize lock ordering, and add two-transaction deadlock/retry coverage.

11. **Resolve schedule confirmation ownership (policy question in M050-019).**
    - **Scope:** medium
    - **Session recommendation:** separate-recommended
    - Confirm whether production manager is the intended checker and PPC only proposes. If PPC should confirm, update the grant, route contract, UI, and role regression tests together.

12. **Complete SPA error and accessibility behavior (M050-013, M050-019).**
    - **Scope:** small
    - **Session recommendation:** same-session-ok
    - Add options/machine/confirm error states, retry and stale-state handling, an accessible Gantt alternative/legend, and visible status text. Defer implementation until the server contract is settled.

13. **Normalize route documentation (M050-018).**
    - **Scope:** small
    - **Session recommendation:** same-session-ok
    - Choose the user-facing `/production/schedule` route or add a redirect from `/mrp/scheduler`, then update process flows, browser fixtures, and support copy.

14. **Expand verification coverage (M050-017).**
    - **Scope:** medium
    - **Session recommendation:** separate-recommended
    - Add HTTP permission/request tests, lifecycle/resource/calendar/routing/snapshot tests, concurrency tests, raw-ID assertions, and SPA run/confirm/reload/retry/Gantt tests. Run the suite with PostgreSQL and the frontend test environment available.

## Suggested delivery gates

- Do not call the scheduler finite-capacity ready until machine, mold, calendar, and current-work occupancy tests pass.
- Do not allow confirmation of a proposal that cannot be recovered after refresh or revalidated against current resources.
- Do not claim dashboard schedule accuracy until it reads the same canonical rows as the detail Gantt.
- Do not mark M050 verified until terminal lifecycle, breakdown, and migration/invariant behavior is demonstrated in a production-like database.
