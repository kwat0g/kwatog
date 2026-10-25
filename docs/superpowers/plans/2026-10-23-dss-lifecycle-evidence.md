# DSS Lifecycle and Evidence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give DSS recommendations truthful source identity and freshness, explicit follow-up, stable active deduplication, and source-verified resolution only where current source records can prove it.

**Architecture:** Extend the existing detector emission, runner, recommendation, event, and resource boundaries. Store public evidence separately from private verifier context, recheck source permissions when projecting references, and stage detector output so only a successful evaluation can become current. Implement verifiers first for row-level work orders and NCRs; grouped findings without safe source identity remain unverifiable.

**Tech Stack:** Laravel, PostgreSQL, Eloquent, PHPUnit, React, TypeScript, Vitest, and the existing DSS route and presentation code.

**Spec:** `docs/superpowers/specs/2026-10-23-dss-system-design.md`; roadmap release R3 in `docs/superpowers/plans/2026-10-23-dss-system-roadmap.md`.

## Global Constraints

- Keep `expires_at` as recommendation validity; it never means business urgency or source resolution.
- Keep `updated_at` out of freshness and source-clock decisions.
- Set `source_observed_at` only from an authoritative source timestamp; leave it null otherwise.
- Keep financial values as decimal strings and compare only matching currency and metric.
- Preserve accepted/executed history and legacy API actions; never convert acknowledgement into verified success.
- New follow-up starts `in_progress`; it does not change source workflow state, assignment, approval, or task state.
- Only a successful verifier of the same issue can set `resolved`; missing emission, deleted source, failed/partial scan, disabled detector, changed parameters, lost permission, and expiry do not prove resolution.
- Use opaque public IDs and recheck source permissions for every linked record; do not emit PHP class names or derive URLs from display references.
- Do not edit applied historical migrations. Add reversible migrations and refuse rollback when it would destroy live follow-up or verification evidence.
- No generic graph framework, new assignment platform, or manual-resolved UI action.

---

## Scope and prerequisites

Read this plan with the approved system design, R1 readable-decisions plan, and R2 workbench plan. At execution, inspect the current diff and database migration state first. The workspace may contain parallel R2 changes; preserve them and coordinate shared `RecommendationService`, request, resource, and SPA edits.

This release has three bounded subprojects:

| Subproject | Outcome | Dependency |
|---|---|---|
| A. Identity and evidence | Additive source/evaluation identity, truthful clocks, typed public references, and receivables currency/metric separation | R1 presentation and current API |
| B. Follow-up and dedupe | Idempotent start, `in_progress`, active dedupe, compatible legacy actions and coherent list filters | R2 query contract |
| C. Verification | Resolve only from explicit source verifiers, initially work orders and NCRs; report automatic verification unavailable for unsupported grouped findings | A and B |

Each subproject has its own test gate. Do not expand this release into implementation of every D01–D33 verifier.

## File map

| File | Responsibility |
|---|---|
| `api/app/Modules/DSS/Contracts/DetectorEmission.php` | Add optional decision identity, typed public references, source observation time, business due time, currency, metric label, and private verifier context fields |
| `api/app/Modules/DSS/Contracts/DetectorScanResult.php` | Preserve explicit completeness; runner must never treat partial result as resolution evidence |
| `api/app/Modules/DSS/Contracts/RecommendationVerifierInterface.php` | New small optional verifier seam for a recommendation's exact source condition |
| `api/app/Modules/DSS/Contracts/VerificationResult.php` | New result with `still_open`, `verified_clear`, or `unavailable`, proof payload, and authoritative observed time |
| `api/app/Modules/DSS/Services/DssRunnerService.php` | Stage emissions; persist successful result, evaluation association, reconciliation and completed run atomically; verify only eligible missing emissions |
| `api/app/Modules/DSS/Services/RecommendationService.php` | Idempotent follow-up, active query semantics, source-scoped projection, verifier transition and event recording |
| `api/app/Modules/DSS/Enums/RecommendationStatus.php` | Add `in_progress` and `resolved`; define active/dedupe behavior explicitly |
| `api/app/Modules/DSS/Enums/RecommendationEventType.php` | Add follow-up and verification lifecycle events |
| `api/app/Modules/DSS/Models/DssRecommendation.php` | Cast and fill additive identity, freshness, due, currency, verifier, and predecessor fields |
| `api/app/Modules/DSS/Resources/RecommendationResource.php` | Return approved typed public fields and lifecycle timestamps; never return private verifier context or PHP class names |
| `api/app/Modules/DSS/Detectors/WorkOrderInterventionDetector.php` | Emit typed public work-order reference and authoritative planned-end deadline |
| `api/app/Modules/DSS/Detectors/NcrSlaDetector.php` | Emit typed public NCR reference and authoritative SLA source clock/deadline where derivable from configured policy |
| `api/app/Modules/DSS/Detectors/ChainBottleneckDetector.php` | Preserve opaque `entity_id` already supplied by `ChainBottleneckService`; retain bounded aggregate samples and document the verification limit |
| `api/app/Common/Services/ChainBottleneckService.php` | Change only if a source-specific authoritative clock is confirmed; do not relabel `updated_at` as status-entry time |
| `api/app/Modules/DSS/Detectors/ReceivablesPriorityDetector.php` | Emit currency `PHP` separately from a concise impact metric label |
| `api/app/Modules/DSS/Requests/RecommendationActionRequest.php` | Validate new `start_follow_up` action while retaining current legacy action validation |
| `api/app/Modules/DSS/Controllers/RecommendationController.php` and `routes.php` | Add no new route unless existing action route cannot carry the action; preserve route guards |
| New additive migration in `api/database/migrations/` | Add lifecycle/evidence columns, indexes, and active partial unique index; rollback guard for live new state |
| `api/tests/Feature/DSS/DssRunnerServiceTest.php` | Atomic evaluation freshness, failed/partial scans, and runner/action races |
| `api/tests/Feature/DSS/RecommendationLifecycleTest.php` | Follow-up idempotency, legacy actions, active dedupe, verification and recurrence |
| New `api/tests/Feature/DSS/RecommendationVerificationTest.php` | Work-order/NCR source proof, deleted-source behavior, and grouped-source unavailability |
| `api/tests/Feature/DSS/RecommendationScopingTest.php` | Linked-source authorization and no hidden counts/references |
| `api/tests/Feature/DSS/ReceivablesImpactContractTest.php` | Currency, metric, decimal-string, and database length compatibility |
| `spa/src/types/dss.ts` and `spa/src/api/dss.ts` | Add response fields and action type without removing legacy states/actions |
| `spa/src/pages/decision-center/presentation.ts` and relevant tests | Add human labels for supported `in_progress` and `resolved`; do not show technical status or a manual resolution action |
| R2 list/query files and tests | Add explicit status filters and a coherent active default after backend states exist |

## Contract examples

`source_references` is a bounded list. Use source kind as a stable business key, not a PHP class name:

```json
{
  "source_references": [
    {
      "source_kind": "work_order",
      "public_id": "opaque-hash-id",
      "display_reference": "WO-202610-0042",
      "destination": "/production/work-orders/opaque-hash-id"
    }
  ]
}
```

`first_detected_at` is stable for one occurrence. `last_evaluated_at` and `last_evaluated_run_id` move only after successful persistence of a complete or explicitly partial run result containing that row. `source_observed_at` is nullable and comes only from a source-owned timestamp. `business_due_at` is nullable unless an authoritative commitment or configured source SLA defines it. `expires_at` remains a separate validity boundary.

Keep any identifiers needed only to verify grouped/private records in `verification_context`, a server-only JSONB column. Never include it in `RecommendationResource`, rationale, logs visible to business users, or public source references. If a bounded safe private scope cannot prove the same issue without unauthorized joins, the verifier returns `unavailable`.

Receivables impact contract:

```json
{
  "impact_estimate": "1250.00",
  "impact_currency": "PHP",
  "impact_metric": "Overdue receivable balance"
}
```

Keep existing `impact_unit` readable for old clients during migration; never truncate the 22-character legacy label or convert the amount to a float.

---

## Subproject A: Truthful identity and freshness

### Task 1: Specify additive schema and compatibility tests

**Files:** new migration; `DssTablesTest.php`; new `ReceivablesImpactContractTest.php`.

- [ ] Assert current rows survive migration with acknowledgement status, events, notes, and existing API fields intact.
- [ ] Assert `impact_unit` remains backward compatible while new currency and metric columns accept bounded values; test currency length and decimal string preservation.
- [ ] Add nullable columns for `decision_code`, `policy_version`, `source_references`, `verification_context`, `first_detected_at`, `last_evaluated_at`, `last_evaluated_run_id`, `source_observed_at`, `business_due_at`, `resolution_evidence`, `predecessor_id`, `impact_currency`, and `impact_metric`.
- [ ] Add self-reference for predecessor and nullable run-log association with safe delete behavior. Add indexes only for actual lookups: `last_evaluated_run_id`, `predecessor_id`, and active dedupe.
- [ ] Replace the partial unique index with one covering `open`, `in_progress`, and `snoozed`; leave `accepted`, `executed`, `dismissed`, `expired`, and `resolved` outside it.
- [ ] Make `down()` refuse rollback if any `in_progress` rows, `resolved` rows, resolution evidence, predecessor links, or new fields contain data that dropping would destroy. Test refusal and clean rollback on an unused schema.
- [ ] Run focused schema tests and verify rollback preserves the original index when the guard refuses.

### Task 2: Add typed emissions and safe public source references

**Files:** `DetectorEmission.php`; row-level detectors; `ChainBottleneckDetector.php`; emission tests.

- [ ] Add constructor fields as trailing optional parameters to preserve existing detector call sites.
- [ ] Define and validate the public source reference shape: `source_kind`, opaque `public_id`, exact `display_reference`, and existing authorized `destination`. Reject empty IDs, overlong strings, unsupported shapes, and PHP class strings at the emission boundary.
- [ ] Add a test that model-backed work-order and NCR emissions use existing hash IDs and exact document codes without exposing raw integer IDs or model class names.
- [ ] In `ChainBottleneckDetector`, include the existing encoded `entity_id` and `entity_type` in bounded samples; keep document codes unchanged. Do not present aggregate membership as complete when the existing result cap marks the scan incomplete.
- [ ] Add detector-specific tests proving chain samples carry opaque public IDs and never include raw source IDs.
- [ ] Add any private verifier scope only for source queries that need it; assert it does not appear in the resource response.

### Task 3: Separate receivables currency and impact meaning

**Files:** `ReceivablesPriorityDetector.php`; `DetectorEmission.php`; runner/model/resource; new impact contract test.

- [ ] Emit `impactCurrency: 'PHP'` and a distinct metric such as `Overdue receivable balance`; keep balance as the decimal string returned by the database.
- [ ] Persist and serialize new fields additively. Keep old `impact_unit` bounded and compatible without truncation; for this detector set a concise legacy-safe value (`PHP`) while metric is in `impact_metric`.
- [ ] Assert `impact_unit` fits 20 characters, currency is exact, metric is separate, and an amount such as `1234567890123.45` is not rounded.
- [ ] Run `ReceivablesImpactContractTest` and detector tests.

### Task 4: Add independent freshness and successful run association

**Files:** `DssRunnerService.php`; `DssRunLog.php` if status helpers are needed; `DssRecommendation.php`; runner tests.

- [ ] Create a `running` run-log row before detection and associate each emitted recommendation with its run ID.
- [ ] Stage `DetectorScanResult` output in memory. Validate duplicate dedupe keys and bounded emission count before persistence.
- [ ] In one database transaction, persist staged emissions, update `last_evaluated_at` and `last_evaluated_run_id`, perform eligible complete-scan verification/reconciliation, and mark the run completed or partial. On any exception, roll back all staged recommendation writes and record a failed run separately; no failed run may leave rows looking current.
- [ ] Preserve the existing detector lock and execution limits. Do not claim query interruption safety beyond current database/worker controls.
- [ ] Keep `first_detected_at` unchanged when the same active occurrence is reevaluated; set it for a new row. Do not copy `created_at` or `updated_at` into source observation time.
- [ ] Replace recommendation freshness checks that compare `updated_at` to run start with successful evaluation association/timestamps. User view/action writes must not refresh detector evidence.
- [ ] Add tests for user action after run not making evidence fresh; detector reevaluation advancing only `last_evaluated_at`; failed transaction leaving no partial current emission; partial run updating emitted rows without clearing missing ones; and unchanged issue retaining first detection.
- [ ] Run `DssRunnerServiceTest`, `RecommendationScopingTest`, and focused migration tests.

### Task 5: Set source clocks only when authoritative

**Files:** `ChainBottleneckService.php` only for confirmed clock fixes; `ChainBottleneckDetector.php`; selected detector tests; `docs/dss/FINDINGS.md` only to record verified disposition.

- [ ] Inventory each chain detector query's selected timestamp and owning source field. Treat the existing F-05 finding as open unless the field is a proven transition/handoff timestamp.
- [ ] Use explicit source event fields already present, such as `accepted_at`, `confirmed_at`, or named handoff timestamps, only for the matching condition. Preserve its semantic name in output rather than aliasing all clocks to `updated_at`.
- [ ] Do not replace `updated_at` with `created_at` as a general fix: entity creation does not prove when the relevant status began.
- [ ] For conditions with no authoritative transition time, leave `source_observed_at` null, omit elapsed waiting duration, and do not invent `business_due_at` or a new business threshold.
- [ ] Add one focused test per changed source clock proving an unrelated edit cannot reset the measured source time. If source schema cannot support that assertion, do not change the detector clock; record the blocker.

**Subproject A exit:** additive schema and API support public typed references, independent detector freshness, and receivables currency/metric separation. No historical source clocks or deadlines are fabricated. Failed runs cannot present staged emissions as successfully evaluated.

---

## Subproject B: Follow-up and active dedupe

### Task 6: Add status/event contract with legacy compatibility

**Files:** status/event enums; action request; recommendation service; model/resource; lifecycle tests.

- [ ] Add status values `in_progress` and `resolved`; only `open`, `in_progress`, and `snoozed` block active dedupe.
- [ ] Add `started` and `resolved` events, with transition from/to status and actor recorded by the existing event model.
- [ ] Add `start_follow_up` to the existing action endpoint. Lock the recommendation row; open transitions once to `in_progress` and writes one event. Repeating the request on `in_progress` returns the unchanged row without another event. Reject start from all other statuses.
- [ ] Preserve `accept`, `execute`, `dismiss`, and `snooze` request behavior. Accepted/executed history remains unchanged and never creates `resolution_evidence`.
- [ ] Keep status absent from model mass assignment; transitions continue through the service.
- [ ] Test concurrent and repeated starts produce one status transition and one `started` event; assert unauthorized actions remain denied.
- [ ] Test old accept/execute clients still receive their existing status and event responses.

### Task 7: Expand active dedupe and define acknowledgement re-emission

**Files:** `DssRunnerService.php`; active partial unique index migration; `RecommendationService.php`; `RecommendationStatus.php`; runner and lifecycle tests.

- [ ] Update runner active lookup, missing-row reconciliation candidates, lazy expiry, snooze handling, and service queries to include `in_progress` where appropriate.
- [ ] On unchanged source issue, update the existing `open`, `in_progress`, or `snoozed` occurrence without changing user status, notes, first detection, or audit history.
- [ ] Preserve approved compatibility rule: accepted/executed rows remain historical acknowledgements; their API actions retain behavior. If an unresolved condition emits again after an acknowledgement, it may create a fresh `open` row under the current active-dedupe invariant; do not mark the old row resolved or claim recurrence after verified resolution.
- [ ] Only set a `predecessor_id` when creating a new occurrence after the same issue was previously source-verified resolved. Do not link arbitrary matching aggregate text.
- [ ] Test unique-index races between simultaneous detector runs and follow-up actions; test accepted/executed re-emission is fresh open without false resolution; test resolved-to-new-occurrence predecessor links only after verification.

### Task 8: Keep list, filters, and presentation coherent

**Files:** `RecommendationService.php`; R2 `ListRecommendationsRequest.php`, `RecommendationQuery.php` if created, controller/resource; `spa/src/types/dss.ts`; `presentation.ts`; list and detail tests.

- [ ] Define default active view as open and in-progress rows plus snoozed rows whose return time is due. Apply expiry and successful-evaluation freshness before page counts.
- [ ] Add explicit supported status filtering: `open`, `in_progress`, `snoozed`, `accepted`, `executed`, `dismissed`, `resolved`, and `expired`. Keep status validation allow-listed and all queries under the same recommendation visibility scope.
- [ ] Ensure an explicit snoozed/history filter can retrieve the requested authorized status without making future snoozes part of the default active count. Preserve R2 URL state and pagination reset behavior.
- [ ] Add human labels `In progress`, `Resolved`, and history labels in the shared presentation layer. Start-follow-up action is shown only for open recommendations. No user-facing manual resolve action exists.
- [ ] Test default active count, explicit status filters, future snooze exclusion from active default, browser URL round-trip, list/detail state labels, and parity between list and direct detail.
- [ ] Run R2 recommendation query/scoping tests and focused Decision Center tests/typecheck after changes.

**Subproject B exit:** start-follow-up is explicit and idempotent; active rows dedupe across reruns; old API acknowledgements still work; query defaults and status filters agree with the backend state model.

---

## Subproject C: Source verification

### Task 9: Add the small verifier contract and conservative transition

**Files:** new verifier interface/result; `DssRunnerService.php`; `RecommendationService.php`; model/resource; verifier tests.

- [ ] Define verifier result states `still_open`, `verified_clear`, and `unavailable`. `verified_clear` requires a non-empty, bounded source proof and authoritative observation time; other states cannot carry resolution evidence.
- [ ] Implement verification only after a successful complete detector evaluation and only for an active occurrence missing from that same scope. A partial or failed scan never calls a verifier for missing rows.
- [ ] A successful `verified_clear` transition sets status `resolved`, stores source condition, verifier/run reference, and observed time, and records one `resolved` event. Keep verifier writes within the successful run transaction.
- [ ] `still_open` keeps the occurrence active. `unavailable` leaves it active or expires it as unknown according to the already-defined validity/configuration path; it never sets `resolved`.
- [ ] Remove generic “detector no longer reports this condition” expiry as resolution evidence. A missing emission without a verifier may produce an expired/unknown record only under an existing validity or configuration rule, with reason that does not imply source clearance.
- [ ] Ensure `RecommendationResource` returns resolution evidence only after successful source verification and never returns private verifier context.
- [ ] Test verifier result validation and assert no API route permits manual `resolved` status assignment.

### Task 10: Implement work-order and NCR verifiers

**Files:** new focused verifier classes under `api/app/Modules/DSS/Services/` or `Verifiers/`; `WorkOrderInterventionDetector.php`; `NcrSlaDetector.php`; `DssServiceProvider.php`; `RecommendationVerificationTest.php`.

- [ ] Work-order verifier resolves only the exact source ID emitted by `WorkOrderInterventionDetector` and only when the source still exists and its authoritative condition no longer holds (inactive status or planned end no longer overdue). Deleted work order returns `unavailable`.
- [ ] NCR verifier resolves only the exact NCR emitted by `NcrSlaDetector` and only when its authoritative response condition no longer holds under its configured SLA and existing NCR state. Do not infer clearance from detector absence alone. Deleted NCR returns `unavailable`.
- [ ] Do not expose integer IDs or PHP class names in verifier proof. Keep the emitted public hash ID and authorized destination as the external reference.
- [ ] Recheck the source module's record permission before returning the reference, evidence, counts, or verifier proof. If permission is missing or revoked, return `unavailable`; do not leak source status through recommendation wording.
- [ ] Test source still open, source clears, source deleted, source permission revoked, detector disabled, changed parameters, failed run, partial run, and same-run successful verification for both verifier types.
- [ ] Verify work-order deadline uses `planned_end`; NCR source clock uses its existing escalation/creation rule only where the NCR SLA detector already defines it. No `updated_at` freshness inference.

### Task 11: Keep unsupported aggregate findings explicitly unverifiable

**Files:** `ChainBottleneckDetector.php`; `ApprovalBottleneckDetector.php`; `PreventiveMaintenanceDetector.php`; `TrainingExpirySummaryDetector.php`; `PayrollAnomalySummaryDetector.php`; `MasterHygieneDetector.php`; resource/presentation tests.

- [ ] Keep grouped rows without a proven same-issue source scope in `verification_context` only if the detector can safely recheck that exact private scope. Otherwise return `unavailable` and do not show a manual resolved button.
- [ ] Do not interpret the following as verified resolution from current aggregate output: approval-by-role counts, preventive maintenance-by-maintainable-type counts, master-hygiene sample labels, or chain-group absence without checking the exact bounded subject set.
- [ ] Training and payroll aggregates may receive a verifier only if it can re-evaluate the same training/period scope while preserving employee privacy and source permissions. Otherwise leave automatic verification unavailable.
- [ ] Chain samples already have opaque source IDs from `ChainBottleneckService`; verifier may use those only if the complete, authorized exact group scope is bounded and every source record is permission-checked. An aggregate count changing is not proof that each underlying issue cleared.
- [ ] Test every unsupported family returns honest `verification_status: unavailable`, contains no private verifier identifiers, and cannot be manually marked resolved.

### Task 12: Verify recurrence, events, and migration safety

**Files:** lifecycle, runner, verification, scoping, and migration tests.

- [ ] Test verified resolution creates one event and stores its proof; repeated verification is idempotent.
- [ ] Test same condition reappearing after verified resolution creates a new row linked to the resolved predecessor; preserve prior events and user notes.
- [ ] Test re-emission after legacy accepted/executed creates a fresh open row without predecessor or success evidence.
- [ ] Test simultaneous scan, start, and verification cannot create duplicate active rows or overwrite follow-up status.
- [ ] Test rollback is blocked when live follow-up/resolution evidence exists and succeeds on a clean unused migration state.
- [ ] Run focused DSS verification, lifecycle, runner, scoping, schema, receivables tests; then run `php artisan test tests/Feature/DSS` inside the API test environment and the focused Decision Center tests/typecheck.

**Subproject C exit:** only work-order and NCR source conditions that are explicitly proved may resolve automatically. Unsupported aggregate rows remain visibly unverifiable; deleted, unauthorized, stale, partial, failed, disabled, or changed-scope cases never masquerade as resolved.

## Overall R3 exit criteria

- [ ] Schema is additive and rollback refuses to erase live R3 lifecycle/evidence data.
- [ ] Each active row has independent evaluation identity; user edits do not refresh evidence.
- [ ] Source references use opaque IDs and source authorization is rechecked at projection time.
- [ ] Receivables currency and metric are separate; amounts stay decimal strings.
- [ ] `in_progress` is idempotent and participates in active dedupe; legacy actions and history remain compatible.
- [ ] Only a successful source verifier can resolve a recommendation. Missing emissions, complete scans without a verifier, expiry, and deletion do not prove clearance.
- [ ] Recurrence links to a predecessor only after verified resolution.
- [ ] The plan has not added a second assignment system or attempted unvalidated D01–D33 verification.

Do not promote detector modes to advisory as part of R3. Record delivered verifier coverage and remaining source prerequisites in `docs/dss/PROGRESS.md` when implementation is complete.
