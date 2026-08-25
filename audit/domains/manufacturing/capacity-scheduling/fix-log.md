# M050 — Capacity Scheduling Fix Log

## Session 2026-08-25

- **No production-code fixes applied.** The existing `audit-report.md` and `action-plan.md` were read in full. The report remains applicable: no MRP, Production, Maintenance, scheduler-page, Gantt, or capacity-test source file changed after the 2026-08-24 audit. Unrelated shared-worktree edits in Dashboard/permission/sidebar files were left untouched.
- **Plan stopped before implementation at item 1.** M050-001 and M050-014 require a human decision on the authoritative schedule/work-order lifecycle and breakdown/disruption semantics. The current code has schedule and work-order states but no specified mapping for start, pause/resume, completion, cancellation, or disruption; choosing one would change cross-module state behavior.
- **Still pending because item 1 is unresolved:**
  - M050-002/M050-003 — machine/mold ledger and running-machine occupancy depend on the chosen lifecycle and confirmation model.
  - M050-004 — plant calendars, shifts, labor, planned maintenance, and downtime need an agreed resource-calendar contract.
  - M050-005 — requires the business choice between injection-job scheduling and operation-level routing scheduling.
  - M050-006 — horizon, due-date, lateness, expedite, and dispatching rules are not specified.
  - M050-007/M050-008 — pending-proposal ownership, expiry, recovery, and manual edit semantics depend on the canonical schedule contract.
  - M050-009/M050-010 — snapshot filtering and validation were not changed because the session stopped before the ordered API-contract work.
  - M050-012 — dashboard/source alignment requires choosing whether `production_schedules` or work-order intent is canonical.
  - M050-011/M050-015/M050-016 — identity, storage invariants, and lock ordering depend on the selected lifecycle/resource model.
  - M050-013/M050-019 — confirmation ownership, SPA error states, and accessible schedule semantics depend on the settled server contract; M050-019 also contains the unresolved production-manager/PPC policy question.
  - M050-017 — endpoint, concurrency, lifecycle, and SPA coverage must follow the finalized contracts.
  - M050-018 — route documentation needs an explicit choice between the user-facing route and a redirect policy.
- **Next action:** resolve the lifecycle, routing-scope, canonical-source, confirmation-owner, and dispatching policy questions, then resume the plan at item 1 in a dedicated M050 session.

## Session 2026-08-25 (resumed)

- Re-read the existing report and action plan in full. Module-scoped git history has no commits after the 2026-08-24 report, and the current worktree has no changes under MRP, Production, Maintenance, the scheduler SPA, or the audited schedule migrations. The unrelated Dashboard widget diff does not change the production schedule source.
- No production-code fixes applied. Plan item 1 remains blocked by the unresolved authoritative schedule/work-order lifecycle and breakdown/disruption policy; the dependent resource, calendar, routing, proposal, snapshot, dashboard, identity, locking, UI, and test items remain pending as recorded above.
- Release status remains **Needs Re-audit** until those policy decisions are supplied and item 1 can be implemented safely.
