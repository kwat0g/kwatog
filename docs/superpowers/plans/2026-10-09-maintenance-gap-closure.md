# Maintenance Gap Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the remaining Maintenance lifecycle, stock, assignment, and measurement gaps before a role-based thesis demonstration.

**Architecture:** Keep the existing Laravel service, transaction, resource, and React Query boundaries. Add guards to locked service transitions, two additive nullable migrations for part traceability and reconciliation, then make both SPA layouts reflect API readiness. Preserve historical rows and the legacy-versus-verified distinction. Use current code and live read-only checks as evidence; do not use the old manual test runbook.

**Tech Stack:** Laravel/PHP, PostgreSQL, PHPUnit, React/TypeScript, React Query, Vitest.

**Spec:** [Maintenance Gap Closure Design](../specs/2026-10-09-maintenance-gap-closure-design.md)

## Global constraints

- Work in an isolated checkout and runtime. The current SPA directory is mounted into a live container, and this checkout has substantial unrelated edits. Never run `RefreshDatabase`, seeders, or fixture tests against production. Do not reset or commit unrelated work.
- No administrator account in role E2E. Link any production test user to an existing real employee after a fresh read-only audit; do not invent shifts, presence, qualifications, measurements, or historical records.
- Preserve `maintenance.closed_loop.enabled` as a new-order switch. Do not retroactively change MWO-202610-0008 or other existing orders to verified status. Deploy additive API/migrations before SPA; only enable the switch after the new role flow passes.
- Reuse existing structured `MaintenanceLog.result`, inventory movement, part-return, readiness, suggestion, and notification logic. Do not add IoT data, labor-cost calculations, or another work-order state machine.
- Each implementation task begins with the named failing test, then the smallest production change, then focused tests. Run broader Maintenance tests once after all dependent changes, not after every UI copy edit.

---

## Phase 0: establish current truth without live mutation

### Task 0.1: isolate code and record baseline

**Files:** No product files. Save command output outside the repository or in a new dated implementation note; never rewrite existing runbooks.

- [ ] Record `git status --short`, current branch, `docker compose ps`, and which containers bind-mount `spa`/`api`. Create an isolated worktree and use separate ports and database credentials for all implementation tests.
- [ ] Run existing focused tests in the isolated test database: `cd api && php artisan test tests/Feature/Maintenance/MaintenanceWorkRecordTest.php tests/Feature/Maintenance/MaintenanceVerifiedRecoveryTest.php tests/Feature/Operations/MaintenanceConsumptionAccountingTest.php tests/Feature/Maintenance/DowntimeSummaryMetricsTest.php`; `cd spa && npm test -- --run src/pages/maintenance/work-orders/complete-gate.test.tsx src/pages/maintenance/work-orders/SparePartSheet.test.tsx`.
- [ ] Record only the observed failures and current runtime/config versions. Treat any live status, account, attendance, or stock observation as time-specific; recheck at role testing.

**Stop condition:** Isolated tests demonstrably cannot connect to the live database or change the live SPA.

## Phase 1: make recovery outcome control closure

### Task 1.1: reject a stale pass after later failed work

**Files:** `api/tests/Feature/Maintenance/MaintenanceWorkRecordTest.php`, `api/tests/Feature/Maintenance/MaintenanceVerifiedRecoveryTest.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php`.

- [ ] Add red cases: no structured record; only `in_progress`; only `needs_follow_up`; `passed` then `needs_follow_up`; `passed` then `in_progress`; and later `passed`. Test both legacy Complete and verified Submit Repair. Test a plain lifecycle note after a pass does not revoke it.
- [ ] In the locked service transaction, query only structured records with `whereNotNull('finding')->whereNotNull('action_taken')->orderByDesc('id')->value('result')`. Require `passed` before `finalize` or `submitRepair`. Keep the existing independent verifier and no-self-verification checks.
- [ ] Remove the controller-only `exists()` guard so every service caller sees the same rule. Return a field-specific 422 error that tells the technician to record a passing result. Keep status transitions atomic with the guard.
- [ ] Run `cd api && php artisan test tests/Feature/Maintenance/MaintenanceWorkRecordTest.php tests/Feature/Maintenance/MaintenanceVerifiedRecoveryTest.php tests/Feature/Maintenance/MaintenanceDoubleCompleteRaceTest.php`.

**Stop condition:** A later failed/incomplete record cannot be closed through direct API calls, while a later pass can. Existing completed orders remain unchanged.

### Task 1.2: make desktop and phone readiness agree

**Files:** `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php`, `spa/src/types/maintenance.ts`, `spa/src/pages/maintenance/work-orders/detail.tsx`, `spa/src/pages/maintenance/mobile/work-order.tsx`, `spa/src/pages/maintenance/work-orders/RecoveryPanel.tsx`, `spa/src/pages/maintenance/work-orders/complete-gate.test.tsx`.

- [ ] Add a detail-only `closure_readiness` response containing `latest_work_result`, `can_close_work`, and a short blocker. Derive it from loaded logs ordered by `id`, matching the service guard, so list serialization adds no per-row query. Keep `available_actions` for permissions/status, not final readiness.
- [ ] Replace both pages' `logs.some(finding)` completion check with `closure_readiness.can_close_work`. For verified orders, gate Submit Repair the same way. Show the blocker beside the top action; keep Record work reachable. Prompt for the check performed, expected behavior, and observed behavior in the existing functional-test narrative form.
- [ ] Add a frontend test for `passed` followed by `needs_follow_up` and for a later `passed` on desktop and phone. Run `cd spa && npm test -- --run src/pages/maintenance/work-orders/complete-gate.test.tsx src/pages/maintenance/work-orders/WorkLogControl.test.tsx`.

**Stop condition:** The action shown on either viewport matches the service guard. A stale page still receives the API 422.

## Phase 2: make multiple records and parts traceable

### Task 2.1: attach every new part issue to one work record

**Files:** new `api/database/migrations/2026_10_09_232000_link_maintenance_parts_to_work_records.php`, `api/app/Modules/Maintenance/Models/SparePartUsage.php`, `api/app/Modules/Maintenance/Requests/RecordSparePartUsageRequest.php`, `api/app/Modules/Maintenance/Services/SparePartUsageService.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php`, `api/tests/Feature/Operations/MaintenanceConsumptionAccountingTest.php`.

- [ ] Add a nullable, indexed `work_log_id` foreign key to `spare_part_usage`; leave old issues null. Add a red test that a record from another order, a free-text lifecycle log, or a missing record is rejected, and that every row of a multi-lot issue references the chosen structured log.
- [ ] Resolve a `work_record_id` hash ID at the request boundary. Inside the locked issue transaction, verify its order and structured fields before moving stock. Copy `work_log_id` into every issue-group row. Preserve operation-key idempotency; a replay must return its original issue rather than require a new record.
- [ ] Load the linked log and issuing user in `show()`. Serialize linked work-record ID/summary and issuer name, with `null` for historical issues. Do not backfill guessed links.
- [ ] Run `cd api && php artisan test tests/Feature/Operations/MaintenanceConsumptionAccountingTest.php tests/Feature/Maintenance/SparePartUsageLostCostRaceTest.php`.

**Stop condition:** A reviewer can identify which recorded action used each newly issued part without altering historical issue rows or stock accounting.

### Task 2.2: show the link where technicians choose and review parts

**Files:** `spa/src/types/maintenance.ts`, `spa/src/api/maintenance/workOrders.ts`, `spa/src/pages/maintenance/work-orders/SparePartSheet.tsx`, `spa/src/pages/maintenance/work-orders/SparePartSheet.test.tsx`, `spa/src/pages/maintenance/work-orders/detail.tsx`, `spa/src/pages/maintenance/mobile/work-order.tsx`.

- [ ] Add a required Work record selector in the existing Add part sheet, defaulting to the latest structured record, with finding/action preview. If none exists, direct user to Record work and block issue. Send `work_record_id` with the existing issue payload.
- [ ] Show issuer, linked work record, lot, gross/returned/net quantity, and net parts cost in both layouts. Label old links “Work record not captured.” Keep existing Return control and exact lot accounting.
- [ ] Test the missing-record state, selected record payload, and old unlinked row. Run `cd spa && npm test -- --run src/pages/maintenance/work-orders/SparePartSheet.test.tsx`.

**Stop condition:** Multiple work records and multiple part issues remain allowed, with each new issue attributable to one record.

## Phase 3: reconcile outstanding parts before handoff

### Task 3.1: persist a technician attestation without another stock move

**Files:** new `api/database/migrations/2026_10_09_232100_add_maintenance_part_reconciliation.php`, `api/app/Modules/Maintenance/Models/MaintenanceWorkOrder.php`, `api/app/Modules/Maintenance/routes.php`, `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `api/app/Modules/Maintenance/Services/SparePartUsageService.php`, `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php`, new `api/tests/Feature/Maintenance/MaintenancePartReconciliationTest.php`.

- [ ] Add nullable `parts_reconciled_at` and `parts_reconciled_by` fields. Add red tests for zero parts, fully returned parts, outstanding parts, issue or return after attestation, Submit Repair, Complete, Cancel, verifier acceptance, replay, and concurrent issue/close.
- [ ] Add `POST /maintenance/work-orders/{workOrder}/reconcile-parts` with `permission_any:maintenance.wo.complete,maintenance.wo.assign`; enforce assigned-technician or maintenance-supervisor role in the service. A supervisor must provide an inspection reason and may use this path only to cancel when the technician is unavailable. Within a locked transaction, calculate outstanding quantity from issue rows using decimal arithmetic; require explicit `confirm_consumed: true` only if positive. Store actor/time and a lifecycle log. Do not create a stock movement.
- [ ] Allow supervisor cancellation through the existing Cancel route only with a mandatory reason and physical-reconciliation evidence. Update route middleware, `asExecutor`, and detail `available_actions` narrowly for that role. Test that a supervisor still cannot Start, record technician work, Submit Repair, or Complete merely because cancellation is allowed.
- [ ] Clear the attestation only after a successful new issue or return in the same work-order lock transaction. Complete, Submit Repair, Cancel, and verified acceptance must recheck outstanding quantity and attestation under lock. Keep the current rule that submitted verified orders freeze part edits.
- [ ] Run `cd api && php artisan test tests/Feature/Maintenance/MaintenancePartReconciliationTest.php tests/Feature/Operations/MaintenanceConsumptionAccountingTest.php tests/Feature/Maintenance/SparePartUsageLostCostRaceTest.php tests/Feature/Maintenance/MaintenanceDoubleCompleteRaceTest.php`.

**Stop condition:** No positive outstanding part quantity reaches a terminal or verification handoff without a current, attributable reconciliation. Net cost still equals issues minus returns.

### Task 3.2: present an explicit, readable part closeout

**Files:** `spa/src/api/maintenance/workOrders.ts`, `spa/src/types/maintenance.ts`, `spa/src/pages/maintenance/work-orders/detail.tsx`, `spa/src/pages/maintenance/mobile/work-order.tsx`, `spa/src/pages/maintenance/work-orders/RecoveryPanel.tsx`, new `spa/src/pages/maintenance/work-orders/part-reconciliation.test.tsx`.

- [ ] Before Complete, Submit Repair, or Cancel, show outstanding quantity by item and lot. Offer Return unused quantity, then “Confirm remaining parts consumed” with actor/time shown after success. For supervisor cancellation, show the inspection-reason field and the same part review. For no net parts, display “No parts to reconcile” without an extra click.
- [ ] Hide close/submit actions until work result and part readiness are met; retain a visible reason and reachable actions to fix the blocker. Invalidate React Query work-order detail after issue, return, or reconciliation. API remains authoritative.
- [ ] Test issue-after-attestation invalidation and both viewport actions. Run `cd spa && npm test -- --run src/pages/maintenance/work-orders/part-reconciliation.test.tsx src/pages/maintenance/work-orders/complete-gate.test.tsx`.

**Stop condition:** Technician can finish part closeout from the MWO page without guessing why Complete or Submit Repair is unavailable.

## Phase 4: close assignment and production safety consistently

### Task 4.1: remove legacy-only advisory bypass on future transitions

**Files:** `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `api/tests/Feature/Maintenance/MaintenanceAssigneeSuggestionTest.php`, `api/tests/Feature/Maintenance/MaintenanceRoleWorkflowTest.php`, `api/tests/Feature/Maintenance/MaintenanceOperationalSafetyTest.php`, `spa/src/pages/maintenance/work-orders/AssignmentPanel.tsx`, `spa/src/pages/maintenance/work-orders/AssignmentPanel.test.tsx`.

- [ ] Add red cases for a legacy open order: known absence, off-shift, busy technician, wrong department/role, unknown future attendance, and permitted supervisor attestation for unverified presence. Include an already assigned old order that remains readable, but must pass the Start recheck.
- [ ] Call the existing candidate check on every new assignment and Start, regardless of `verification_required`. Keep the expected-assignee concurrency token and handover note. Do not permit attestation for known absence or failed shift coverage.
- [ ] Show exact blocking reason and next action in Assignment. Refresh suggestions on user request and just before submission, so later shift/attendance edits are picked up. Preserve backend recheck for stale suggestions.
- [ ] Run `cd api && php artisan test tests/Feature/Maintenance/MaintenanceAssigneeSuggestionTest.php tests/Feature/Maintenance/MaintenanceRoleWorkflowTest.php tests/Feature/Maintenance/MaintenanceOperationalSafetyTest.php`; `cd spa && npm test -- --run src/pages/maintenance/work-orders/AssignmentPanel.test.tsx`.

**Stop condition:** No newly assigned or newly started order bypasses employee availability; an existing historical assignment is not silently changed.

### Task 4.2: preserve machine and mold ownership at create and Start

**Files:** `api/tests/Feature/Maintenance/MaintenanceReadinessTest.php`, `api/tests/Feature/Maintenance/MaintenanceWindowConflictTest.php`, `api/tests/Feature/Maintenance/MaintenanceManualTargetAvailabilityTest.php`, `api/tests/Feature/Maintenance/MaintenanceOperationalSafetyTest.php`; product files only if a regression is found.

- [ ] Regression-test occupied machine and mold search results, forged create payloads, active production reservation, Production Release resources, and Start recheck after a race. Confirm schedule generation cannot seize a production-owned target.
- [ ] If a case fails, patch the existing selectable-target/readiness or Start guard, never just the dropdown. Run `cd api && php artisan test tests/Feature/Maintenance/MaintenanceReadinessTest.php tests/Feature/Maintenance/MaintenanceWindowConflictTest.php tests/Feature/Maintenance/MaintenanceManualTargetAvailabilityTest.php tests/Feature/Maintenance/MaintenanceOperationalSafetyTest.php`.

**Stop condition:** Creation and Start cannot claim equipment still owned or reserved by production; the release path is visible to the authorized production role.

## Phase 5: make measures and list cards truthful

### Task 5.1: define repair and downtime cohorts precisely

**Files:** `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php`, `api/app/Modules/Maintenance/Services/DowntimeAnalyticsService.php`, `api/tests/Feature/Maintenance/DowntimeSummaryMetricsTest.php`, `spa/src/types/maintenance.ts`, `spa/src/pages/maintenance/work-orders/RecoveryPanel.tsx`, `spa/src/pages/maintenance/downtime/index.tsx`.

- [ ] Add red metric cases: open breakdown spanning `to`, breakdown resolved inside range but started before `from`, multiple machines, and legacy started order without repair submission. Assert money and durations never become negative.
- [ ] Add `maintenance_elapsed_minutes` and provisional status to detail resource; retain `technician_repair_minutes` for compatibility. Label creation-to-start “Time to start” and start-to-submission “Start to repair submission.” Show no labor-cost claim.
- [ ] Compute MTTR from resolved breakdowns ending inside the selected range, using each full start-to-end duration. Expose resolved/open counts separately. Keep clipped overlap for the calendar availability numerator. Label availability and current MTBF estimate with their actual denominator and cohort; do not call either scheduled-production performance.
- [ ] Run `cd api && php artisan test tests/Feature/Maintenance/DowntimeSummaryMetricsTest.php tests/Feature/Maintenance/DowntimeParetoTest.php`; `cd spa && npm run typecheck`.

**Stop condition:** Open faults change live downtime but never final MTTR; every displayed duration and rate has a defensible definition.

### Task 5.2: scope work-order summary cards correctly

**Files:** `spa/src/pages/maintenance/work-orders/index.tsx`, new `spa/src/pages/maintenance/work-orders/index.test.tsx` if no existing list test.

- [ ] Test a paginated response where `meta.total` exceeds `data.length`; ensure card counts and parts cost are labeled “on this page.” Do not aggregate `data.data` under “Total Cost.”
- [ ] Rename cost card “Parts cost on this page.” Make priority card “Critical priority” and filter to `critical`, matching the existing critical queue. Keep table label “Parts cost.”
- [ ] Run `cd spa && npm test -- --run src/pages/maintenance/work-orders/index.test.tsx` and `cd spa && npm run typecheck`.

**Stop condition:** No card implies a total across all filtered orders when only the current page was summed.

## Phase 6: remove friction, then run the complete role scenario

### Task 6.1: keep only one target-search debounce

**Files:** `spa/src/pages/maintenance/work-orders/create.tsx`, `spa/src/pages/maintenance/schedules/create.tsx`, new `spa/src/pages/maintenance/work-orders/create.test.tsx`, new `spa/src/pages/maintenance/schedules/create.test.tsx`, `spa/src/components/ui/Select.test.tsx`.

- [ ] Add or extend fake-timer tests proving one request after the shared 300 ms debounce, plus server rejection of stale/occupied targets.
- [ ] Remove the page-level `useDebounce` from both create pages; use the shared searchable Select behavior. Keep the selected target visible during request refresh and clear it when the maintainable type changes.
- [ ] Run `cd spa && npm test -- --run src/pages/maintenance/work-orders/create.test.tsx src/pages/maintenance/schedules/create.test.tsx src/components/ui/Select.test.tsx`, then `cd spa && npm run typecheck`.

**Stop condition:** Search waits once, and no client behavior weakens server target eligibility.

### Task 6.2: run a fresh, role-based E2E matrix

**Files:** New `docs/maintenance/2026-10-09-gap-closure-role-e2e.md` execution record created when testing occurs; never use the old manual test runbook as steps or fixture truth.

- [ ] Read-only audit live roles/users, real employee links, current shifts and attendance, available machine/mold, production ownership, spare-part lots, and feature flag. Record IDs and time; do not copy credentials into the repository. Use existing real employees for any needed role accounts. No administrator account.
- [ ] In isolated test database, run preventive machine and corrective machine cases, plus mold target rejection and cancellation. Assert create target filters and forged-payload rejection; assignment candidate and Start recheck; production pause/Release resources; multiple records with follow-up then pass; multi-lot issues, partial return, and reconciliation; submit/reject/rework/accept; downtime close, equipment restoration, schedule next due, and exact net parts cost.
- [ ] Check denial with a non-maintenance account, self-verification denial, role-specific verifier, stale assignee suggestion, duplicate operation key, concurrent part issue/close, and phone-width actions.
- [ ] Only after isolation checks pass, repeat the approved demo path using current live role accounts and a fresh work order. Record actual observed times, item codes, stock before/after, screenshots, and any blockers. Do not fabricate attendance or measurements to force a pass.

**Stop condition:** Every state transition and role denial has an observed result, with net stock/cost and recovery time reconciled. Any failed step stays open as a named defect.

### Task 6.3: deploy and verify without changing historical meaning

**Files:** Migration files from Tasks 2 and 3, new `docs/maintenance/2026-10-09-gap-closure-release.md`, and the fresh role execution record.

- [ ] Review the migration SQL against a current database backup and check for long table locks; confirm nullable columns do not rewrite old issue rows. Run migrations in staging first, deploy compatible API before SPA, then smoke-test detail/list and role permissions.
- [ ] Enable `maintenance.closed_loop.enabled` only for future orders after the role test and verifier accounts pass. Verify an old in-progress order still uses its legacy route, while a newly created order uses submission and independent verification.
- [ ] Recheck MWO-202610-0008 is unchanged unless its actual technician performs a new transition. Record an explicit rollback: disable the new-order setting, redeploy prior compatible code, and retain additive columns and truthful audit history; do not delete recorded stock movements.

**Stop condition:** Production creates no new unverifiable order, historical rows retain their meaning, and rollback does not discard maintenance or inventory evidence.

## Final focused gate

- [ ] Run `cd api && php artisan test tests/Feature/Maintenance tests/Feature/Operations/MaintenanceConsumptionAccountingTest.php` against the isolated test database.
- [ ] Run `cd spa && npm test -- --run src/pages/maintenance` and `cd spa && npm run typecheck`.
- [ ] Inspect the final diff for unrelated files, permissions that rely only on hidden buttons, guessed historical data, destructive migrations, and any dashboard label that claims labor cost or scheduled availability without evidence.
- [ ] Report exact tests, observed role results, unresolved defects, and whether the live new-order switch is enabled.
