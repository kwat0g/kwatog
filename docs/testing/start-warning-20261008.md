# Start dialog attendance warnings

Verified October 8, 2026. This correction addresses the repeated early-start attendance warning shown for WO-202610-0003.

## Cause and correction

Execution readiness appended the same general early-start warning for each worker without verified attendance. With two workers, the modal rendered identical warnings twice. The API now groups those workers into one warning and includes their names: `Early start needs verified attendance: Grace Aquino, Angelica Salazar.` Individual presence entries remain intact, and non-early blocked-attendance messages retain their individual reasons.

The separate coverage warning is shorter: `Crew does not cover this time. Check HR shifts, leave, or other assignments.` The modal recovery instruction is `Use Change workers or ask HR to verify attendance.` Distinct quality, materials, equipment, and scheduling blockers remain visible.

This changes warning presentation, not execution eligibility. Missing attendance remains different from recorded absence. Early Start still requires verified attendance for every worker, and the dialog offers no checkbox to bypass that requirement. Shift, leave, assignment, resource, quality, and material checks remain enforced.

## Verification

- Backend regression and surrounding readiness, attendance, crew, and planning suites: **129 tests passed, 751 assertions**, run sequentially against the dedicated test database.
- The new backend regression reproduced two presence warnings before the change. It now verifies one warning containing both worker names, no changes from readiness reads, a rejected actual Start, and unchanged work order, schedule, machine, mold, crew, stock movement, and activity snapshots. The attendance suite inherits the scenario and runs it again with its own coverage.
- Frontend regression and neighboring crew tests: **42 tests passed**. The dialog shows one attendance warning, preserves the independent crew blocker and short recovery instruction, and exposes neither a Start production action nor a presence-confirmation checkbox while blocked.
- Production build, including TypeScript, passed. The existing Vite NotFound static/dynamic import advisory remains.
- Targeted ESLint, Laravel Pint, design detector, and task-local whitespace checks passed. Existing unrelated working-tree changes were preserved.
- Isolated Playwright fixture: **1440 × 1000 desktop** and **390 × 844 mobile**, one attendance warning with two names, separate crew warning, no page errors or horizontal overflow, and **zero business mutations**.

Commands:

```bash
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test --filter='WorkOrderExecutionReadinessTest|WorkOrderCrewTest|WorkOrderAttendanceSafetyTest|CapacityPlanningServiceTest'
```

From `spa`:

```bash
npm run test:run -- src/pages/production/work-orders/StartProductionDialog.test.tsx src/pages/production/work-orders/CrewPanel.test.tsx
npm run build
npx eslint src/pages/production/work-orders/StartProductionDialog.tsx src/pages/production/work-orders/StartProductionDialog.test.tsx
```

Evidence: [desktop](evidence/start-warning-20261008/warning-1440.png), [mobile](evidence/start-warning-20261008/warning-390.png), [browser assertions](evidence/start-warning-20261008/browser.json), and [design scan](evidence/start-warning-20261008/design-scan.json).

## Limits

Browser verification uses mocked API responses; backend tests use dedicated database `ogami_test_earliest_planning`. No live WO, attendance, shift, assignment, schedule, or stock record was changed. No commit or deployment was performed.
