# Production start status and explicit proposal recovery

Implemented and verified October 8, 2026, against the [approved design](../superpowers/specs/2026-10-08-production-start-status-and-proposal-recovery-design.md) and [implementation plan](../superpowers/plans/2026-10-08-production-start-status-and-proposal-recovery.md).

## Delivered behavior

Planned orders with a current proposal show **Awaiting confirmation**, even when the proposed start has passed. An ended Planned proposal shows **Proposal passed**, directing the user to Confirm. These orders no longer receive a premature actual-start warning.

A Confirmed active slot shows **Start available** from its start through exactly 15 minutes later. At 15 minutes and one second, it becomes **Start overdue**. Ended slots take precedence and show **Schedule passed**. Completed, Closed, Cancelled, and unscheduled orders do not receive a schedule status. Future Confirmed bookings keep their existing schedule display.

The API classifies timing once and derives both its optional informational `schedule_status` and warning-only `schedule_warning`. Table and record select one envelope, including compatibility with older warning-only responses. Informational states use existing info colors and do not create Needs attention cards. Independent production deadlines and pauses still create attention items. The existing attention container remains bounded and scrollable.

The execution comparison, status classification, and missed-start reminders share `ProductionResourceService::START_GRACE_MINUTES = 15`. Upcoming and due reminders, recipients, ownership alerts, and deduplication remain unchanged.

When a confirmation proposal expires while open, a local one-second tick disables Confirm and shows **Proposal passed. Find a new slot.** It sends no request. **Find next slot** explicitly reuses the existing generation POST and displays loading feedback. Date and worker-count changes retain their deliberate 300 ms refresh. Focus and reconnect do not regenerate the proposal.

Insufficient remaining duration is rejected by the server with **Proposal no longer fits. Find a new slot before confirming.** An ended proposal is rejected with **Proposal passed. Find a new slot before confirming.** A failed confirmation preserves the work order, preferred day, pending schedule, crew, and material reservations. A fresh proposal is never automatically applied. Manual workers and recorder remain selected across refresh; a worker unavailable for the new interval blocks confirmation until replaced. A read-only review directs the user back to Confirm or authorized Reschedule instead of pretending its GET generates a new reservation.

## Verification

- **137 backend tests passed, 888 assertions**, sequentially in dedicated PostgreSQL database `ogami_test_earliest_planning`. Suites: `WorkOrderScheduleWarningTest`, `WorkOrderScheduleReminderTest`, `WorkOrderCrewTest`, `WorkOrderExecutionReadinessTest`, `WorkOrderAttendanceSafetyTest`, and `CapacityPlanningServiceTest`.
- API boundary scenarios cover before start, exact start, 14 minutes 59 seconds, exactly 15 minutes, 15 minutes one second, exact expiry, Planned proposals, terminal states, and superseded history. List and detail agree. Notification tests confirm due-stage deduplication through grace and one missed stage after it.
- **60 frontend tests passed** across `CrewPanel.test.tsx`, `detail.test.tsx`, and `StartProductionDialog.test.tsx`. After adding explicit refresh-payload and unavailable-worker assertions, the final CrewPanel run passed **27 tests**.
- `npm run build` passed, including TypeScript compilation. Vite retained its existing NotFound static/dynamic import advisory. No new build errors or dependencies were introduced.
- Targeted ESLint, Prettier, Laravel Pint, and task-local patch whitespace checks passed. Review used a baseline captured before this task because the shared working tree contains unrelated changes.
- Isolated Playwright exercised eight table/record status cases, desktop **1440 × 1000**, mobile **390 × 844**, and expired-proposal recovery. One status notice appeared per surface, no informational attention card appeared, and the 25-card attention region stayed **208 px** tall with internal scrolling. Page and modal had no horizontal overflow or page errors; the table retains its existing internal horizontal scrolling on narrow screens.
- The expiry scenario sent one initial generation request and one explicit recovery request, with **zero assignment applications**. It preserved manual workers and recorder. Window focus and local expiry sent no generation request.
- The prior deadline browser scenario passed again: one invalid-day warning, disabled confirmation, no invalid-day request, no focus regeneration, and valid-date recovery with the count preserved.

The original red runs reproduced the premature start status, old reminder boundary, and missing explicit expiry recovery. Final verification also corrected test-only issues: database snapshots now reload persisted defaults and types, confirmation fixtures use usable future slots, and the existing current-minute test freezes Date to avoid crossing a minute between clicking and asserting. SessionTimeout is disabled only in the new time-travel tests; authentication and permissions remain tested.

Commands:

```bash
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test --filter='WorkOrderScheduleWarningTest|WorkOrderScheduleReminderTest|WorkOrderCrewTest|WorkOrderExecutionReadinessTest|WorkOrderAttendanceSafetyTest|CapacityPlanningServiceTest'
```

From `spa`:

```bash
npm run test:run -- src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/detail.test.tsx src/pages/production/work-orders/StartProductionDialog.test.tsx
npm run build
npx eslint src/pages/production/work-orders/CrewPanel.tsx src/pages/production/work-orders/detail.tsx src/pages/production/work-orders/index.tsx src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/detail.test.tsx
```

Screenshots and browser assertions:

- [Desktop table](evidence/production-start-status-20261008/table-desktop.png) and [mobile table](evidence/production-start-status-20261008/table-mobile.png).
- [Desktop record](evidence/production-start-status-20261008/record-desktop.png) and [mobile record](evidence/production-start-status-20261008/record-mobile.png).
- [Desktop expired review](evidence/production-start-status-20261008/expired-1440.png) and [mobile expired review](evidence/production-start-status-20261008/expired-390.png).
- [Browser assertions](evidence/production-start-status-20261008/browser.json) and [design detector result](evidence/production-start-status-20261008/design-scan.json).

## Limits and preserved safeguards

Browser scenarios use mocked API responses and a local Vite harness, not live business actions. Backend integration scenarios use the isolated test database. No live WO, schedule, crew, attendance, quality specification, or material reservation was changed; live notifications were not run. No commit, push, or unrelated module change was made.

Start available describes the timing window only. Actual Start still checks employee presence, HR coverage, permissions, machine and mold capacity, quality setup, materials, full duration, and production deadlines. Early Start and authorized late Start retain their existing gates and acknowledgements. The minimum two-hour reservation and longer estimate-plus-buffer behavior remain intact.

The local expiry display uses the browser clock and a one-second tick; server validation remains authoritative. Existing page polling refreshes list and record status, so a page notice may update on its next refresh rather than immediately at the boundary. This change does not add a scheduler optimizer, automatic reassignment, or automatic production Start.
