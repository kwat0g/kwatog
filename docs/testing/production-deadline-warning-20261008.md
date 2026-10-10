# Production deadline warning correction

Verified October 8, 2026. This change addresses the preferred-day warning and duplicated confirmation-modal messages. The separate [start status and proposal recovery plan](../superpowers/plans/2026-10-08-production-start-status-and-proposal-recovery.md) was subsequently implemented; see [its verification evidence](production-start-status-20261008.md).

## Cause and delivered behavior

A day beginning at or after the exact production deadline cannot contain a permissible production interval. The shared planner previously exhausted its equipment search and returned a generic equipment-capacity message. It now recognizes this date constraint before selecting equipment and returns `Choose a day before production due: Oct 16, 2026 · 12:00 AM.` for the reported deadline. This does not establish an employee shortage.

The confirmation modal checks the preferred day immediately, shows the warning once, and disables confirmation. While the date is invalid, it hides redundant deadline/delivery lines, cached equipment and quality messages, worker fields, submit errors and loading feedback for a previous request. Choosing a valid day restores the review with the worker count preserved. It does not bypass quality or availability checks. The read-only assignment review is not newly blocked by a saved preference after the deadline; its actual booking remains the authority.

When a valid day has no returned slot, the modal displays the actual planner reason instead of a generic equipment warning, suppresses meaningless recorder/count errors, and retains worker-count controls so a staffing shortage can be corrected. Additional distinct equipment blockers remain visible. Failed date searches preserve the previous pending reservation and do not save a preferred day.

## Evidence

- A frontend regression reproduced two simultaneous date warnings before the rendering fix. It passes after the fix and also verifies hidden cached QC messages, disabled confirmation, no request for an invalid day, and valid-date recovery.
- The backend preferred-day scenario reproduced the generic equipment-capacity reason before the planner change. It now verifies both a day after the deadline and the deadline day at exactly midnight, with unchanged reservation snapshots and no saved preference.
- `CapacityPlanningServiceTest` and `WorkOrderCrewTest`: **49 tests passed, 252 assertions**, sequentially against dedicated PostgreSQL database `ogami_test_earliest_planning`.
- `CrewPanel.test.tsx`: **25 tests passed** in the final run.
- `npm run build`: passed, including TypeScript compilation. An initial test fixture lacked the required readiness-blocker `owner`; the fixture was corrected before the final passing build. Vite reports the existing NotFound static/dynamic import advisory.
- Targeted ESLint and Laravel Pint: passed.
- Targeted `git diff --check`: passed.
- Isolated Playwright browser fixture: desktop 1440 × 1000 and mobile 390 × 844; one alert; invalid confirmation disabled; cached equipment/worker fields hidden; no horizontal overflow, page errors, or focus-triggered request; valid date restores worker count. Screenshots are `/tmp/deadline-warning-evidence/desktop.png` and `/tmp/deadline-warning-evidence/mobile.png`.

Representative commands:

```bash
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test --filter='CapacityPlanningServiceTest|WorkOrderCrewTest'
```

From `spa`:

```bash
npm run test:run -- src/pages/production/work-orders/CrewPanel.test.tsx
npx eslint src/pages/production/work-orders/CrewPanel.tsx src/pages/production/work-orders/CrewPanel.test.tsx
npm run build
```

## Limits and preservation

Browser scenarios use mocked responses, not live confirmation. No live WO was confirmed, started, rescheduled, reassigned or issued materials during this task. No existing QC specification or attendance was changed. Independent quality blockers remain applicable once a valid production day is selected. The 15-minute timing classification and reminder alignment were implemented in the subsequent task linked above.
