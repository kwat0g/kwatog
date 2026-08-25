# M053 — Maintenance / machine health action plan

Date: 2026-08-25  
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session

The focused suite passed, but the primary risks are authoritative lifecycle behavior, inventory/financial integrity, scheduler semantics, and a missing Production–Maintenance handoff. Implement in the order below and re-audit after the P1 controls are covered.

## Ordered implementation plan

### 1. Establish one authoritative work-order state machine

- Findings: M053-F01, M053-F02, M053-F03.
- Classification/severity: Broken, P1.
- Scope: large; service transition matrix, row locks, controller guards, resource actions, desktop/mobile UI, audit behavior, and concurrency tests.
- Session recommendation: separate-recommended.
- Work: define allowed source/target states for assign, start, complete, cancel, log, and spare-part issue. Lock and re-read the work order for every mutation, reject stale/terminal transitions with 422s, make desktop actions follow `available_actions`, and decide whether logs remain allowed after completion.
- Acceptance: direct completion from `open`/`assigned` is rejected; assignment cannot move a work order backward or overwrite a terminal result; terminal inventory/log mutations are rejected; stale/concurrent tests are deterministic; desktop and mobile actions match the same server matrix.

### 2. Replace float lifecycle money arithmetic

- Findings: M053-F04 and the financial portion of M053-F03.
- Classification/severity: Broken, P1.
- Scope: medium; maintenance service arithmetic and financial regression tests.
- Session recommendation: separate-recommended.
- Work: use decimal-string/bcmath or the repository money abstraction for mold maintenance totals, preserve scale through the resource/database, and keep the exact parts-cost source of truth.
- Acceptance: repeated fractional costs reconcile exactly between spare-part usage, work-order cost, mold lifecycle total, and history; no float casts remain on financial paths.

### 3. Repair machine-hour preventive-maintenance semantics and scheduler ordering

- Finding: M053-F05.
- Classification/severity: Broken, P1.
- Scope: medium/large; schedule schema/service, recompute command, cron order, generation job, and tests/backfill decision.
- Session recommendation: separate-recommended.
- Work: decide runtime-since-service versus wall-clock semantics. Prefer a persisted last-maintenance running-hours baseline or due-hours value, run the recompute before generation, make schedule completion/cancellation updates atomic, and prevent duplicate work-order materialization.
- Acceptance: daily generation uses current hours; a PM does not retrigger without new runtime; first-run and pre-existing-runtime behavior are documented; retries and concurrent generation remain idempotent.

### 4. Complete the breakdown corrective-work-order handoff

- Findings: M053-F06, M053-F07.
- Classification/severity: Missing/Broken, P1/P2.
- Scope: large; Production listener, Maintenance service/event, downtime linkage, notification target, outbox/idempotency, and cross-module tests.
- Session recommendation: separate-recommended and cross-module coordination required.
- Work: create or recover one corrective MWO per authoritative breakdown, link `machine_downtimes.maintenance_order_id`, preserve the paused production context/reason, make event replay safe, and point notifications to a supported MWO/downtime destination. Keep machine-health UI hidden unless its source criteria are met.
- Acceptance: one breakdown produces one actionable linked MWO; duplicate/stale events do not duplicate or relink incorrectly; restoration closes the correct downtime; notification links resolve for maintenance roles.

### 5. Repair schedule archive and restore

- Finding: M053-F08.
- Classification/severity: Broken, P1.
- Scope: small/medium; list filter, route binding, transactional lifecycle, and API/UI tests.
- Session recommendation: same-session-ok as an isolated maintenance-schedule change, after the lifecycle owner confirms the archive policy.
- Work: apply `TrashedFilter`, opt the restore binding into `withTrashed()`, keep active/only/with scopes explicit, and make archive/restore behavior auditable and recoverable.
- Acceptance: active, archived-only, with-trashed, delete, restore, invalid-ID, and permission cases all work through the SPA and direct API.

### 6. Finish the assignment capability

- Finding: M053-F09.
- Classification/severity: Missing, P2.
- Scope: small/medium; role policy, assignee selector, resource action contract, API types, and UI tests.
- Session recommendation: same-session-ok after step 1 defines valid assignment transitions.
- Work: identify the dispatcher/admin role, add a searchable authorized employee selector, expose `assign` only when valid, and align the HashID request/client contract and role gates.
- Acceptance: authorized users can assign from the supported work-order surface; maintenance techs retain the intentional restriction; direct API and UI visibility tests match the seeded role matrix.

### 7. Make downtime analytics contracts truthful

- Findings: M053-F10, M053-F11, M053-F12.
- Classification/severity: Incomplete, P2.
- Scope: medium; analytics query semantics, response DTOs/types, filter UI, indexes, and endpoint tests.
- Session recommendation: separate-recommended because the window definition and identifier contract affect all analytics consumers.
- Work: implement or remove search, return HashIDs or remove identity fields, and clip downtime intervals consistently at report boundaries (including open rows). Keep summary, trend, top-machines, all-machines, and Pareto semantics aligned.
- Acceptance: search changes results, no raw IDs appear, TypeScript matches the API, and fixtures spanning before/inside/after/open intervals produce documented totals.

### 8. Decide the live work-order broadcast contract

- Finding: M053-F13.
- Classification/severity: Incomplete, P2.
- Scope: small; Reverb subscription/query invalidation or event removal.
- Session recommendation: same-session-ok if a supported maintenance dashboard is retained; otherwise defer with an explicit removal decision.
- Work: subscribe to `maintenance.dashboard` and invalidate/update work-order queries on `maintenance.wo_created`, or remove the unused broadcast/channel contract and document polling behavior.
- Acceptance: a generated MWO appears without a manual refresh when realtime is supported, or no dead event contract remains.

## Cross-module decisions to record

- Whether breakdown handling owns corrective MWO creation or delegates to an outbox consumer, and what event key makes it idempotent.
- Whether a maintenance log is allowed after completion/cancellation and whether material issue is allowed before `in_progress`.
- Whether hour schedules mean runtime since last service or elapsed wall-clock time.
- Which role owns assignment and whether reassignment during active work is permitted.
- Whether machine-health/predictive code remains dormant until source freshness, device identity, and alert ownership exist.

## Session decision

No production-code implementation is authorized for this audit session. The main findings are not safely reducible to isolated low-risk edits: lifecycle changes affect inventory and UI contracts, scheduler changes affect data semantics and cron order, breakdown handling crosses module boundaries, and analytics changes alter API output. The archive/restore and dead search fixes should be bundled only after the relevant contracts are confirmed.

## Definition of done for re-audit

- Work-order transitions are authoritative, locked, explicit, and identical across API/resource/desktop/mobile.
- Terminal work orders cannot receive inventory or unapproved log mutations.
- Maintenance money totals use exact decimal arithmetic and reconcile across related records.
- Machine-hour PM generation uses current, baselined runtime and is ordered/idempotent.
- Breakdown events create one linked corrective MWO and resolve to a supported notification destination.
- Schedule archive/restore works through active/trashed API and UI paths.
- Assignment, analytics search, identifier shape, and boundary semantics have role/API/browser evidence.
