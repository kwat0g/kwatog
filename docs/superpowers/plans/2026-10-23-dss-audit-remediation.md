# DSS Audit Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the audited DSS correctness, safety, operations, and history gaps while keeping the wider D01–D33 capability program tied to validated source evidence.

**Architecture:** Keep DSS advisory, role-scoped, and source-authoritative. Fix the shared query and lifecycle boundaries first, then promotion controls and runner health, followed by history/search. Use the existing roadmap and focused plans for delivery-risk, lifecycle, notifications, and business-domain expansion instead of building parallel services.

**Tech Stack:** Laravel, PostgreSQL, Redis/cache locks, React, TypeScript, React Query, React Router, Atelier UI, PHPUnit, Vitest, and Playwright.

**Spec:** [System design](../specs/2026-10-23-dss-system-design.md); audit evidence: [DSS audit](../../dss/AUDIT-2026-10-23.md), [readiness](../../dss/READINESS-2026-10-23.md), and [system roadmap](2026-10-23-dss-system-roadmap.md).

## Global Constraints

- Every detector stays in shadow until its own source, role, calibration, and owner-review gates pass.
- Global defaults must never promote detectors; advisory mode requires a deliberate per-detector change.
- Apply `dss.view`, detector ownership, role ownership, and linked-source permissions on every list, count, option, detail, event, and action path.
- Keep all business decisions in their source modules; DSS actions link to existing workflows and never bypass approvals.
- Keep human-facing evidence curated and readable; never serialize arbitrary JSON as the display fallback.
- Keep recommendation and event history intact during migration; do not rewrite acknowledged recommendations as verified outcomes.
- Preserve all existing workspace changes. Re-read current diffs before implementation; stage only files belonging to the approved task.

---

## Audit findings covered

| Finding | Planned closure |
|---|---|
| Due snoozed recommendations reappear without available detail actions | Task 2: status-aware actions and explicit resume behavior |
| Valid status/area/team filter combinations can fail validation | Task 1: validate filter choices against the unfiltered authorized scope |
| Global advisory default can expose every detector | Task 3: shadow-only global fallback and per-detector promotion |
| Run Now reports success for failed runs; run errors and interrupted runs are hidden | Tasks 4–5: truthful run feedback, surfaced errors, and stale-run recovery |
| Runner time budget excludes persistence and missing-row verification | Task 5: one cumulative deadline and conservative incomplete-run behavior |
| Outcomes and event history have no user-facing timeline | Task 6: authorized event endpoint and detail timeline |
| Event history grows without retention and may hold free text | Task 7: retention/archive policy and bounded event data |
| Search misses displayed business references | Task 8: bounded allowlist includes displayed document identifiers |
| Active follow-up work disappears from admin detector counts | Task 4: count open, in-progress, and due snoozed work with an accurate label |
| Concurrent detail opens can duplicate daily view events | Task 6: database-enforced idempotency for daily view recording |
| 11 of 33 decisions remain partial; forecast/scenario, notification, and quality evaluation capabilities remain incomplete | Task 9: execute existing R5–R7 roadmap through gated domain plans |

## File and ownership map

| Files | Responsibility |
|---|---|
| `api/app/Modules/DSS/Services/RecommendationService.php`, `Requests/ListRecommendationsRequest.php`, `tests/Feature/DSS/RecommendationQueryTest.php`, `RecommendationScopingTest.php` | Authorized query composition, option validation, status semantics, and lifecycle behavior |
| `spa/src/pages/decision-center/RecommendationDetails.tsx`, `index.tsx`, their focused tests, `spa/src/api/dss.ts`, `spa/src/types/dss.ts` | Status-aware controls, displayed counts, search parameters, and event timeline |
| `api/app/Modules/DSS/Services/DssParameterService.php`, `Requests/UpdateEngineRequest.php`, `Controllers/DssAdminController.php`, `tests/Feature/DSS/DssParameterServiceTest.php`, `DssAdminTest.php` | Fail-safe detector modes and truthful administration API |
| `spa/src/pages/admin/dss.tsx` and its focused test | Run Now result handling and detector workload labels |
| `api/app/Modules/DSS/Services/DssRunnerService.php`, `Models/DssRunLog.php`, `Console/Commands/MaintainDss.php`, `tests/Feature/DSS/DssRunnerServiceTest.php` | Cumulative runtime bounds and recovery of interrupted runs |
| `api/app/Modules/DSS/Models/DssRecommendationEvent.php`, `Resources/RecommendationEventResource.php` (new), `Controllers/RecommendationController.php`, `routes.php`, `tests/Feature/DSS/RecommendationLifecycleTest.php` | Scoped, human-readable event history and concurrency-safe view logging |
| `api/database/migrations/` (new additive migrations), `api/app/Console/Commands/` (new archive/prune command if retention approval is recorded) | Daily event uniqueness and approved event retention |
| `api/app/Modules/DSS/Services/RecommendationService.php`, `spa/src/pages/decision-center/index.tsx`, `RecommendationQueryTest.php`, `index.test.tsx` | Business-reference search across the full authorized result set |
| `docs/dss/READINESS-2026-10-23.md`, `docs/superpowers/plans/2026-10-23-dss-system-roadmap.md`, existing R4/R6 plans | Coverage status and release gates; no duplicate detector program |

Before implementation, confirm exact test and migration state against the current dirty worktree. Do not roll back or replace the in-progress Status `All` fix; add regression coverage around it.

## Delivery order

| Phase | Tasks | Gate |
|---|---|---|
| Correctness | 1–2 | Filter combinations and snoozed records behave consistently in API and detail UI |
| Safety and operations | 3–5 | No global promotion; run status is truthful; incomplete runs cannot clear recommendations |
| Evidence and usability | 6–8 | Idempotent event trail, approved retention, complete business-reference search |
| Full coverage | 9 | Each decision family remains accurately marked partial/validated and follows its domain release gates |

## Task 1: Make status and business filters composable

**Files:** `RecommendationService.php`; `ListRecommendationsRequest.php`; `RecommendationQueryTest.php`; `RecommendationScopingTest.php`; `spa/src/pages/decision-center/index.tsx`; `index.test.tsx`.

**Interfaces:** The list and summary continue to accept the same validated filters. The options endpoint continues returning only choices available within the viewer's authorized recommendation scope.

- [x] Add API regressions for `status + domain`, `status + owner_role`, and `domain + owner_role`. Use valid individually available values whose combination has no matching record; expect HTTP 200 with total 0, not HTTP 422.
- [x] Add `All` regressions proving list totals and summary totals include every visible status while excluding inaccessible detectors, expired active rows, and active rows without a successful evaluation.
- [x] Change `validateAvailableFilters()` to validate submitted domain, owner-role, and detector choices against `visibleQuery($user)`, before applying the selected filters. Apply all selected filters as intersections after choice validation.
- [x] Keep `queryFor()` status semantics explicit: `All` includes valid active records and visible history; selecting a status returns only that status; active records still require current validity and a successful run association.
- [x] Keep summary and list on the same filtered query. Changing a filter resets the page to 1 and keeps URL state stable.
- [x] Run `cd api && php artisan test --compact tests/Feature/DSS/RecommendationQueryTest.php tests/Feature/DSS/RecommendationScopingTest.php`.
- [x] Run `cd spa && npm run test:run -- src/pages/decision-center/index.test.tsx`.

**Expected:** Valid filters never become validation errors just because their intersection is empty. Hidden option values remain rejected. `All` totals match visible rows across pagination.

## Task 2: Make snoozed records actionable at the right time

**Files:** `RecommendationService.php`; `RecommendationActionRequest.php`; `RecommendationDetails.tsx`; `RecommendationDetails.test.tsx`; `RecommendationLifecycleTest.php`.

**Interfaces:** Reuse the existing `start_follow_up` action. It becomes valid for an open or snoozed recommendation and transitions the record to `in_progress` once.

- [x] Add lifecycle tests for a future snooze, a due snooze, an expired recommendation, a legacy snooze whose return date is after expiry, and repeated follow-up requests.
- [x] Update the existing snooze-list test: `All` includes a valid future snooze, while accept remains rejected until the return time. Use the explicit `open` filter to assert the snoozed row is absent from new/open work.
- [x] Allow `start_follow_up` from `snoozed`; persist one `started` event with `from_status=snoozed`, `to_status=in_progress`, and clear `snoozed_until`. Keep repeats on `in_progress` idempotent.
- [x] Keep `expires_at` authoritative. Reject new snooze dates at or after expiry; transition legacy snoozes past expiry to `expired` and keep them in history. Do not extend validity to match snooze time.
- [x] In the detail UI, show “Snoozed until …” for future snoozes and a “Resume follow-up” action. Future snoozes allow resume or dismiss; due snoozes allow accept, dismiss, or start follow-up. Hide accept for future snoozes; keep snooze available only for open/in-progress rows.
- [x] Keep all action eligibility checks in the service after row lock. The UI state is guidance, not authorization.
- [x] Run `cd api && php artisan test --compact tests/Feature/DSS/RecommendationLifecycleTest.php`.
- [x] Run `cd spa && npm run test:run -- src/pages/decision-center/RecommendationDetails.test.tsx`.

**Expected:** A due recommendation can be acted on when opened from the queue; a future snooze can be resumed deliberately; stale or expired rows remain blocked server-side.

## Task 3: Remove global detector promotion

**Files:** `DssParameterService.php`; `UpdateEngineRequest.php`; `DssAdminController.php`; `DssParameterServiceTest.php`; `DssAdminTest.php`; `spa/src/api/dss.ts`; `spa/src/pages/admin/dss.tsx` if the default-mode label changes.

**Interfaces:** Keep detector-specific `mode` as `shadow | advisory`. Global default is shadow-only. The engine response includes `legacy_global_advisory_ignored: boolean`; legacy stored global advisory values are ignored and reported to administrators until corrected.

- [x] Add tests proving a detector with no detector-specific mode remains shadow even when a legacy global parameter contains `advisory`.
- [x] Make `detectorMode()` default to shadow independently of global settings. Make `defaultMode()` report the effective shadow-only default.
- [x] Reject attempts to set global `default_mode=advisory` with a clear validation error. Preserve global engine enable/disable behavior.
- [x] Keep explicit per-detector advisory mode behind the existing `dss.manage` route. Do not promote detectors during migration, seeding, or default changes.
- [x] Surface a manager warning when stored legacy global mode is advisory; offer a corrective `PATCH /dss/admin/engine` with `default_mode=shadow` through the existing audited engine update path.
- [x] Run `cd api && php artisan test --compact tests/Feature/DSS/DssParameterServiceTest.php tests/Feature/DSS/DssAdminTest.php`.
- [x] Run `cd spa && npm run typecheck`.

**Expected:** A single global setting cannot expose every detector. Existing explicit detector modes and their audit records remain intact.

## Task 4: Make admin health truthful and count active work

**Files:** `DssAdminController.php`; `spa/src/api/dss.ts`; `spa/src/pages/admin/dss.tsx`; `api/tests/Feature/DSS/DssAdminTest.php`; new `spa/src/pages/admin/dss.test.tsx`.

**Interfaces:** Detector rows return `active_count` and `last_run` fields `status`, `started_at`, `finished_at`, `error_summary`, `run_reference`, and `stale`. Keep `open_count` as a temporary alias.

- [x] Add API tests where Run Now returns `completed`, `partial`, `failed`, and `skipped`; verify status and a fixed safe error summary are present for `dss.manage` users, with no SQL, stack trace, or source payload.
- [x] Include `started_at`, `error_summary`, and `run_reference` in the latest-run projection. Keep raw exception text in restricted server logs; never return it from the `dss.manage` endpoint.
- [x] Change the Run Now mutation to inspect the returned detector status. Show success only for completed runs, a warning for partial/skipped runs, and an error for failed runs; always invalidate detector and coverage data.
- [x] Count `open`, `in_progress`, and due `snoozed` recommendations as active work. Exclude future snoozes, expired records, and terminal history. Rename the admin display from “open” to “active” so the count matches its meaning.
- [x] Add `active_count` to the detector API and SPA type. Keep `open_count` as a deprecated alias for one compatibility release; update the admin page to read `active_count`.
- [x] Add an explicit stale indicator for a `running` run older than 310 seconds, matching the current 300,000 ms maximum budget plus 10-second lock margin. Do not label an old `running` row healthy.
- [x] Run `cd api && php artisan test --compact tests/Feature/DSS/DssAdminTest.php`.
- [x] Run `cd spa && npm run test:run -- src/pages/admin/dss.test.tsx && npm run typecheck`.

**Expected:** Managers see partial and failed detector runs accurately; follow-up work remains visible in detector counts.

## Task 5: Bound the complete detector run and recover interrupted workers

**Files:** `api/app/Modules/DSS/Services/DssRunnerService.php`; `api/app/Console/Commands/MaintainDss.php`; `api/app/Modules/DSS/Models/DssRunLog.php` only if a status helper is needed; `api/tests/Feature/DSS/DssRunnerServiceTest.php`.

**Interfaces:** A detector run has one absolute deadline from start through detection, persistence, and verification. A run is `completed` only when the scan and all required reconciliation finish within that deadline.

- [x] Add tests with a slow detector and a slow verifier. Assert the cumulative budget is enforced after detection, missing recommendations stay active when verification is incomplete, and an incomplete run never records source-verified resolution.
- [x] Compute one monotonic deadline in `runLocked()`. Before persistence and each verification batch, compare remaining time; set database statement timeout to no more than the remaining budget.
- [x] Bound missing-row verification work. If all eligible rows cannot be checked before the deadline, keep unresolved rows active and record a `partial` or `failed` run with an explicit timeout reason; never run complete-scan reconciliation on a partial scan.
- [x] Keep the detector lock longer than the maximum configured budget. If a worker exits with a run still `running` beyond that lock window, mark it `failed` with a stable interruption reason and `finished_at`; do not alter recommendation status.
- [x] Lock the `dss_run_logs` row before final persistence and require status `running`. The reaper locks the same row before marking it failed, so a late worker cannot overwrite an interrupted run as completed.
- [x] Extend the existing daily `dss:maintain` command to mark stale `running` logs failed. Process bounded chunks and make repeated runs idempotent; do not add another scheduler entry.
- [x] Add coverage that failed/interrupted runs do not satisfy `hasSuccessfulEvaluation()` and do not make recommendations appear fresh.
- [x] Run `cd api && php artisan test --compact tests/Feature/DSS/DssRunnerServiceTest.php tests/Feature/DSS/RecommendationScopingTest.php tests/Feature/DSS/RecommendationVerificationTest.php`.

**Expected:** The budget covers the full run. A timed-out, killed, or partly reconciled worker cannot leave false healthy status or falsely resolve a business issue.

## Task 6: Add a scoped event timeline and idempotent view recording

**Files:** `RecommendationService.php`; `RecommendationController.php`; `routes.php`; new `RecommendationEventResource.php`; `DssRecommendationEvent.php`; additive DSS migration; `RecommendationDetails.tsx`; `spa/src/api/dss.ts`; `spa/src/types/dss.ts`; `RecommendationLifecycleTest.php`; `DssLifecycleMigrationTest.php`; detail tests.

**Interfaces:** Add `GET /dss/recommendations/{recommendation}/events`, returning up to 50 newest events and a `next_cursor` when older events remain. Each event has a human label, timestamp, permitted actor display name, bounded reason, and curated outcome summary. It uses the same recommendation visibility checks as detail.

- [x] Add API tests for event order, the 50-row limit, stable cursor continuation, forbidden detector/role scope, and private metadata omission.
- [x] Add the event endpoint and resource. Whitelist event/status labels and outcome keys; do not return raw metadata, detector internals, source payloads, or unrestricted notes.
- [x] Use `cursorPaginate(50)` ordered by event ID descending and return Laravel's `next_cursor`. Render the timeline with “Load older events”, an empty state, and loading/error retry; reuse the shared recommendation detail layout and date formatting.
- [x] Add nullable `viewed_on DATE` to events and a partial unique index on `(recommendation_id, actor_id, viewed_on)` where `event='viewed'`, `actor_id IS NOT NULL`, and `viewed_on IS NOT NULL`. Backfill only the current app-local date, assigning the date to one existing event per recommendation/user/day and leaving duplicate and older history rows untouched.
- [x] Record a daily view with `DB::table('dss_recommendation_events')->insertOrIgnore(...)` inside the existing transaction, using the app-local `viewed_on` date. A uniqueness conflict creates no second `viewed` event; recommendation actions and other events remain append-only.
- [x] Add a concurrency test proving simultaneous opens create one daily view event, and a boundary test proving the next app-local date permits a new event.
- [x] Run `cd api && php artisan test --compact tests/Feature/DSS/RecommendationLifecycleTest.php tests/Feature/DSS/RecommendationScopingTest.php tests/Feature/DSS/DssLifecycleMigrationTest.php`.
- [x] Run `cd spa && npm run test:run -- src/pages/decision-center/RecommendationDetails.test.tsx src/pages/decision-center/detail.test.tsx && npm run typecheck`.

**Expected:** Users can understand what happened to a recommendation over time. Concurrent page loads produce one daily view record without deleting old history.

## Task 7: Apply an approved retention policy to DSS event history

**Files:** new `api/app/Console/Commands/ArchiveDssRecommendationEvents.php`; new `api/tests/Feature/DSS/ArchiveDssRecommendationEventsTest.php`; `api/routes/console.php` only after retention approval; `docs/dss/READINESS-2026-10-23.md`.

**Interfaces:** `dss:archive-events {--dry-run} {--months=12} {--delete-archived}` archives without deletion by default; `--dry-run` only previews. Physical deletion requires recorded retention approval and the explicit `--delete-archived` flag.

- [ ] Before adding automatic deletion, obtain the organization's record-retention approval. Proposed default: keep 12 months online, archive older rows to private storage, verify archive checksum and row count, then delete only rows included in a verified archive. Retain the source recommendation and stable event IDs in the archive.
- [x] Reuse the repository's archive-only patterns from `PruneAuditLogs` and `ArchiveActivityEvents`; never write DSS archives to a public disk or log outcome/reason text.
- [x] Add the archive command. Dry run reports eligible rows and cutoff without writing. Archive is the default behavior; delete only with `--delete-archived` after recorded approval. Make archive output deterministic and idempotent per closed calendar month. Do not delete an event if archive creation or verification fails.
- [x] Bound or redact free-text reason/outcome content at write time, and expose only curated fields through Task 6's event resource.
- [x] Test archive retry, corrupt archive replacement, row-count mismatch, and no-delete-on-failure. Keep the 12-month proposal configurable only after the retention owner approves it.
- [x] Run `cd api && php artisan test --compact tests/Feature/DSS/ArchiveDssRecommendationEventsTest.php` and review the generated archive in a temporary private test disk.

**Expected:** Event growth has a governed lifecycle and history remains recoverable. No retention deletion runs before approval and verified archival.

## Task 8: Search all displayed business references

**Files:** `RecommendationService.php`; `RecommendationQueryTest.php`; `spa/src/pages/decision-center/index.tsx`; `index.test.tsx`; detector evidence fixtures where needed.

**Interfaces:** Preserve the list endpoint's existing `search` parameter. Search remains server-side and only matches explicitly approved human-facing evidence fields.

- [x] Add one query fixture each for `bill_number`, `po_number`, `inspection_number`, and `material_lot_number`, plus the currently supported `customer`, `work_order`, `ncr_number`, `training`, `rule`, and `sample_documents[].document` values.
- [x] Extend the server-side search allowlist only with these business-facing scalar references. Validate JSON type before comparing; never search serialized JSON or technical detector fields.
- [x] Verify literal `%`, `_`, and backslash search characters remain literal and search applies before pagination to the full authorized set.
- [x] Verify a role-hidden record's displayed identifier cannot affect search results or totals.
- [x] Run `cd api && php artisan test --compact tests/Feature/DSS/RecommendationQueryTest.php tests/Feature/DSS/RecommendationScopingTest.php`.
- [x] Run `cd spa && npm run test:run -- src/pages/decision-center/index.test.tsx`.

**Expected:** Every reference a user can see in approved evidence can find its record without exposing unrelated or hidden data.

## Task 9: Finish broad decision coverage through the existing roadmap

**Files:** `docs/superpowers/plans/2026-10-23-dss-system-roadmap.md`; `docs/superpowers/plans/2026-10-23-dss-delivery-risk.md`; `docs/superpowers/plans/2026-10-23-dss-lifecycle-evidence.md`; `docs/superpowers/plans/2026-10-23-dss-updates-notifications.md`; `docs/dss/01-decision-inventory.md`; `docs/dss/READINESS-2026-10-23.md`.

**Interfaces:** These plans and readiness documents remain the source of truth for each domain's coverage status and evidence gate. They do not add a second runtime or API contract.

- [x] Use R5's five separate business-domain plans: `2026-10-23-dss-planning-purchasing-inventory.md`, `2026-10-23-dss-finance-commercial.md`, `2026-10-23-dss-quality-returns.md`, `2026-10-23-dss-maintenance-assets.md`, and `2026-10-23-dss-people-payroll.md`. Each implementation plan names exact source owners, permissions, verifier, demo data, and promotion gate.
- [x] Keep the 11 partial decisions visibly partial in readiness and Decision Center coverage. For each delivered decision, require a source owner, authoritative facts, role scope, supported action, verified clear condition, isolated scenario, and evidence of review.
- [x] Keep R6 delivery on the existing notification plan. No email, no shadow publishing, recheck user/source permissions at delivery time, and retain the configured daily cap and duplicate protection.
- [x] Keep R7 outcome measurement, forecasts, and scenarios gated on validated history and source facts. Forecast/scenario database models alone do not count as user-facing capability; scenarios must prove zero production writes.
- [x] Do not promote detectors to advisory as a consequence of adding domain coverage. Promotion remains per detector after its own accepted calibration evidence.
- [ ] Before closing a domain plan, run its focused API/service/UI tests, its source-derived demo scenario, authorization review, and the readiness update.

**Expected:** The DSS has a traceable full-system scope without presenting unvalidated rules, forecasts, or scenarios as production-ready decisions.

## Final acceptance

- `All` list and summary totals match the authorized dataset across pages; valid filter intersections return empty results instead of validation errors.
- Due and future snoozes have clear, valid actions; API checks remain authoritative and idempotent.
- No global setting can promote detectors. Run Now and coverage states distinguish completed, partial, failed, skipped, and interrupted runs.
- One cumulative detector deadline covers source scan, persistence, and verification; incomplete work cannot prove resolution.
- Decision detail includes a permission-scoped history timeline; daily view events are concurrency-safe; retention preserves recoverable history.
- Search covers displayed business references and remains authorization-scoped.
- `D01–D33` readiness reflects current evidence; unvalidated detector families, notifications, outcomes, forecasts, and scenarios remain gated.
- Run the focused backend and frontend commands listed under the changed tasks, then `cd spa && npm run audit:dss-routes` and `cd spa && npm run test:e2e -- e2e/decision-center.spec.ts`. Complete one desktop/mobile browser review and record actual outcomes; do not claim checks that were not run.

## Self-review

- All eleven audit findings map to Tasks 1–9.
- R5–R7 reuse the accepted system roadmap and their focused plans; no second recommendation engine, assignment system, notification transport, or forecast framework is introduced.
- Historical event rows are retained during daily-view uniqueness migration. Event deletion is blocked until a retention owner approves the proposed period and archives verify.
- Global advisory fallback is removed without changing explicit detector mode records.
