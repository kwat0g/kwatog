# Production Start Status and Proposal Recovery Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task. Work inline; do not deploy subagents without the user's explicit request. Steps use checkbox syntax for tracking.

**Goal:** Stop new earliest-production proposals from appearing immediately overdue while preserving actual late-start, expiry, and readiness safeguards.

**Architecture:** Classify current schedule timing once in the existing WorkOrderResource. Add an optional informational status while keeping warning-only compatibility, align notifications with execution's existing 15-minute grace, and reuse the existing proposal POST for explicit recovery.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL 16; React 18, TypeScript, TanStack Query, existing Atelier components; PHPUnit and Vitest.

**Spec:** [Production start status design](../specs/2026-10-08-production-start-status-and-proposal-recovery-design.md).

## Global constraints

- Status: completed October 8, 2026. Verification: [production start status evidence](../../testing/production-start-status-20261008.md). The earlier deadline warning and duplicate-message fix remain intact.
- `START_GRACE_MINUTES = 15`; exactly 15 minutes is allowed, more than 15 minutes is overdue.
- All production timestamps use Asia/Manila and 12-hour display.
- Keep early-start verified attendance, late-start acknowledgement, deadlines, resource locks, material and QC gates, and role scope.
- Do not add waiting time, settings, dependencies, silent reassignment, or confirmation controls to Production Schedule.
- Do not mutate live business records to test the change. Backend tests run sequentially in `ogami_test_earliest_planning`.
- The working tree contains extensive unrelated changes. Record a task-local baseline and apply only this task's edits; never reset or stage whole files without inspecting their existing changes. Do not commit unless requested.

## Task 1: Classify schedule timing without calling new proposals overdue

**Files**
- Modify: `api/app/Modules/Production/Services/ProductionResourceService.php` — shared grace constant and existing execution comparison.
- Modify: `api/app/Modules/Production/Resources/WorkOrderResource.php` — classify current reservation once; emit informational and warning envelopes.
- Test: `api/tests/Feature/Production/WorkOrderScheduleWarningTest.php`.
- Regression: `api/tests/Feature/Production/WorkOrderExecutionReadinessTest.php`.

**Interfaces:** Existing `executionWindow(WorkOrder, Machine, Mold, bool): array` keeps its signature and behavior. `schedule_status` is nullable and has `kind`, `message`, `scheduled_start`, `scheduled_end`; `schedule_warning` contains only the warning kinds from the same classified result.

- [x] Add a frozen-time API regression using the existing authenticated manager, WO and mold fixtures. Check both list and detail. For a slot starting at 08:00 and ending at 10:00, exercise these assertions:

```php
// A Planned active proposal must never be a missed actual start.
$response->assertJsonPath('data.schedule_status.kind', 'awaiting_confirmation')
    ->assertJsonPath('data.schedule_warning', null);
// A Confirmed active slot at 08:00, 08:14:59 and 08:15:00 is available.
$response->assertJsonPath('data.schedule_status.kind', 'start_available')
    ->assertJsonPath('data.schedule_warning', null);
// At 08:15:01, both status and warning describe a missed start.
$response->assertJsonPath('data.schedule_status.kind', 'start_overdue')
    ->assertJsonPath('data.schedule_warning.kind', 'start_overdue');
```

Include Planned expiry (`proposal_expired`, action names Confirm), Confirmed expiry, future bookings, paused/running expiry, terminal states, and released/superseded history. Preserve tests proving planning deadline is separate from slot expiry.

- [x] Run the test and capture the expected red result:

```bash
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test --filter=WorkOrderScheduleWarningTest
```

- [x] Add `public const START_GRACE_MINUTES = 15` to ProductionResourceService and replace the literal in executionWindow's late comparison. In the resource, classify only a loaded current reservation; exclude terminal states first, handle Planned separately, prioritize expiry, then compare Confirmed starts:

```php
$late = $now->gt($slot->scheduled_start->copy()
    ->addMinutes(ProductionResourceService::START_GRACE_MINUTES));
$kind = $late ? 'start_overdue' : 'start_available';
$message = $late
    ? 'Start overdue. Click Start to check readiness.'
    : 'Start available. Click Start to check readiness.';
```

Use `Awaiting confirmation. Open Confirm to review this proposal.` and `Proposal passed. Open Confirm to find a new slot.` for Planned states. Do not calculate full execution readiness for every list row; Start remains the authority for that check. Derive both API fields from the one status result, keeping warning kinds `proposal_expired`, `start_overdue`, `schedule_passed`.

- [x] Run both backend suites sequentially and inspect unrelated execution behavior:

```bash
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test --filter='WorkOrderScheduleWarningTest|WorkOrderExecutionReadinessTest'
```

## Task 2: Show status once on table and record; reserve attention cards for problems

**Files**
- Modify: `spa/src/types/production.ts` — optional schedule_status and warning-kind compatibility.
- Modify: `spa/src/pages/production/work-orders/index.tsx` — status chip and attention classification.
- Modify: `spa/src/pages/production/work-orders/detail.tsx` — one informational or warning notice.
- Test: `spa/src/pages/production/work-orders/detail.test.tsx` — existing table and record fixture harness.

**Interfaces:** Consume Task 1's `schedule_status`; use `schedule_status ?? schedule_warning` for an older API response. Do not render both fields separately.

- [x] Extend the existing parameterized `table`/`record` tests. Use a future `planned_end` for informational cases so independent deadline warnings do not obscure the test. For Start available, assert the following outcome:

```tsx
expect(await screen.findByText('Start available', { exact: true })).toBeVisible();
expect(screen.queryByText('Start overdue', { exact: true })).toBeNull();
expect(screen.queryByText('Needs attention', { exact: true })).toBeNull();
```

The exact chip assertion applies to the table. For record assertions inspect the status notice's complete `Start available. Click Start to check readiness.` text. Cover Awaiting confirmation, Proposal passed, old warning-only responses, and real overdue deadlines alongside a neutral timing status.

- [x] Run `npm run test:run -- src/pages/production/work-orders/detail.test.tsx` in `spa`; confirm red.
- [x] Extend the existing type with these exact kinds:

```ts
'awaiting_confirmation' | 'proposal_expired' | 'start_available'
  | 'start_overdue' | 'schedule_passed'
```

At each page, choose one envelope. Information chips use existing neutral/info tokens and no warning triangle. In `reasonFor`, only actual warning kinds become attention reasons; neutral status must continue through independent production-deadline/paused checks. An expired Planned proposal says Confirm, never Start. The record's start states display Scheduled start; expired states display Scheduled finish. Preserve the bounded attention-card container.

- [x] Run the page tests and targeted ESLint. Verify desktop and 390 px mobile views contain one notice and no horizontal overflow.

## Task 3: Align reminder stages with the same grace

**Files**
- Modify: `api/app/Console/Commands/NotifyUpcomingWorkOrders.php` — missed-stage threshold only.
- Test: `api/tests/Feature/Notifications/WorkOrderScheduleReminderTest.php`.

**Interfaces:** Consume `ProductionResourceService::START_GRACE_MINUTES`. Keep `production.schedule.reminder`, existing stage keys, recipients, links and deduplication.

- [x] Update frozen-time fixtures from the old 5-minute missed threshold to 15 minutes plus one second. Assert no missed-stage reminder at exactly 15 minutes, one after that point, and no duplicate when the command reruns. Preserve upcoming and due-stage coverage and attendance-change ownership alerts.
- [x] Run `docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test --filter=WorkOrderScheduleReminderTest`; verify red.
- [x] Replace only the stage boundary:

```php
$stage = $slot->scheduled_start->gt(now()) ? 'upcoming'
    : (now()->gt($slot->scheduled_start->copy()
        ->addMinutes(ProductionResourceService::START_GRACE_MINUTES))
        ? 'missed' : 'due');
```

Do not broaden recipients or trigger notifications directly from page reads. Preserve the 30-minute upcoming window and current-slot selection.
- [x] Rerun the reminder suite; verify notification counts, role-appropriate links, no actual Start, and no changed crew or attendance.

## Task 4: Make expired proposal recovery explicit and preserve reviewed choices

**Files**
- Modify: `spa/src/pages/production/work-orders/CrewPanel.tsx` — explicit retry for expired or rejected timing.
- Modify: `api/app/Modules/Production/Services/WorkOrderService.php` and `WorkOrderAssignmentService.php` — actionable expiry copy where confirmation already rejects timing.
- Test: `spa/src/pages/production/work-orders/CrewPanel.test.tsx`.
- Test: `api/tests/Feature/Production/WorkOrderCrewTest.php`.

**Interfaces:** Reuse `workOrdersApi.generateAssignmentSuggestion(woId, { minimum_workers, maximum_workers, preferred_production_date })` and the existing versioned `applyAssignment` payload. Do not add an endpoint or automatically apply a returned proposal.

- [x] Add an API scenario in the existing crew suite: generate a pending proposal, capture its raw schedule/WO/crew/material reservation state, advance time beyond remaining duration, then submit the reviewed confirmation. Expect 422, the recovery message, unchanged snapshots, no new crew/material reservation, and no actual_start. Repeat with a stale schedule version. Existing full-duration confirmation guard already exists; extend its proof rather than duplicating it.
- [x] Add a frontend deferred-response scenario: load a proposal, make it expire locally, assert no generation call occurs merely because time passed, then click Find next slot. Verify exactly one POST with the selected day/count, loading feedback, disabled Confirm until completion, restored manual selections, and unavailable workers still blocked.

```tsx
expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(1);
fireEvent.click(screen.getByRole('button', { name: 'Find next slot' }));
await waitFor(() =>
  expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(2));
expect(workOrdersApi.applyAssignment).not.toHaveBeenCalled();
```

- [x] Verify red using the targeted crew suites.
- [x] Reuse `useElapsedSeconds` from `spa/src/hooks/useElapsedSeconds.ts` for a local expiry calculation if its existing one-second tick fits the visible modal; otherwise use a cleaned-up timeout for the ended timestamp. Neither option may call refetch. Include that hook as a read-only dependency; do not change its other callers. Render `Proposal passed. Find a new slot.` once with Find next slot. For the Planned confirmation flow (`generate === true`), reuse the existing suggestion query's explicit `refetch()` and its loading/stale-response controls; that query already uses the required POST. For a read-only assignment review (`generate === false`), do not pretend its GET generates a new slot: direct the user back to Confirm or authorized Reschedule instead. When a server confirmation rejects insufficient remaining duration, display `Proposal no longer fits. Find a new slot before confirming.` and provide the same explicit recovery action. Reset the submit mutation only when requesting a newly reviewed proposal. Preserve the existing 300 ms deliberate-edit refresh, focus/reconnect suppression, invalid-date warning, previous reservation on failed generation, and manual crew/recorder choices. Keep the old proposal visible as history, never as a newly approved booking.
- [x] Run both crew suites. Ensure expiry does not enable late Start without its existing permission and acknowledgement, and does not forbid an otherwise allowed Start within grace.

## Task 5: Final proof and stop

- [x] Run backend gates sequentially in the dedicated test database:

```bash
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test --filter='WorkOrderScheduleWarningTest|WorkOrderScheduleReminderTest|WorkOrderCrewTest|WorkOrderExecutionReadinessTest|WorkOrderAttendanceSafetyTest|CapacityPlanningServiceTest'
```

- [x] Run frontend gates, build and targeted lint in `spa`:

```bash
npm run test:run -- src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/detail.test.tsx src/pages/production/work-orders/StartProductionDialog.test.tsx
npm run build
npx eslint src/pages/production/work-orders/CrewPanel.tsx src/pages/production/work-orders/detail.tsx src/pages/production/work-orders/index.tsx src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/detail.test.tsx
```

- [x] Run Pint only on changed PHP files, then task-local `git diff --check`. Review changes against the baseline; do not bundle previous audits or repairs.
- [x] Browser-check Planned, freshly Confirmed, exact grace boundary, truly overdue, expired proposal, invalid day and valid-day recovery. Verify one notice, bounded attention cards, preserved choices, disabled invalid confirmation, and no automatic regeneration while reading.
- [x] Record counts, commands, screenshots and limitations in `docs/testing/production-start-status-20261008.md`. Stop when the spec's acceptance cases pass. Do not alter live schedules, run live notifications, repair unrelated QC records, or move to another module.
