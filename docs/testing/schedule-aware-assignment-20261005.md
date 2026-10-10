# Schedule-aware assignment verification

Implementation date: October 5, 2026.

The approved production assignment slice uses existing WO schedules and HR shifts. PPC suggests compatible equipment and a Production-department crew, reviews the slot, and applies a versioned assignment. Production management owns live handover, paused equipment release, and reviewed remaining slots. Each crew has one output recorder; no global employee permission is granted.

## Implemented behavior

- PPC can edit workers, minimum/maximum staffing and the recorder inside the suggested-assignment review. Refreshing changed limits previews staffing for the same canonical equipment slot without saving those limits. Applying saves the reviewed crew and limits atomically with confirmation. The WO Confirm button now opens this shared review instead of the previous manual equipment picker. Existing valid crews remain preserved within the selected limits; maximum is a cap, not an automatic target.
- Add worker lists all active Production-department employees, including unavailable employees as disabled options with shift, approved leave or reservation reasons. Blank search is no longer capped at 50 employees. Automatic selection and saving still enforce availability. Manage assignment sits with the WO actions; worker information and editing appear in its modal, without a separate Production crew card or Assigned workers section inside Details. Production cost snapshot appears below Activity and preserves the recorded BOM/valuation basis.
- Separate reservations and exact current machine/mold ownership. Adjacent slots share equipment and workers; runtime takeover and overlapping reservations are blocked.
- Production department filtering, optional maximum and minimum staffing, scheduled HR shift and approved leave checks for suggestions, manual future assignment, schedule review, and start/resume. Live handover and output remain possible after the planned shift ends.
- Persistent crew names and recorder on the WO; editing and history inside Manage assignment. Suggest assignment previews equipment, timing, workers and blockers.
- Pausing retains equipment. Explicit authorized release requires a reason, preserves schedule history and issued materials, and clears only the exact owner. Resume requires a reviewed current slot and reacquisition.
- Normal and NCR repair reservations use the same schedule table. Manual repair reserves worker timing without equipment. Started production and repair schedules are immutable, including raw SQL changes of their context.
- Cumulative mold maintenance/lifetime budgeting includes earlier proposals and unscheduled runtime ownership. Counter estimates remain conservative and piece based.
- Changed mold rate recomputes duration. Planner searches a bounded horizon, waits for shared molds across machines and separates mold overlap from machine daily-hour budgets.
- Schedule list exposes staffing and current ownership. The existing alert sweep reports expired or invalid reservations with deduplicated notifications to PPC and production management; equipment and delivery risks also reach Maintenance and Sales.
- Saved output replay resolves before current assignment checks. Versioned assignment and reviewed-slot retries resolve identical saved actions.
- A current equipment owner with no approved active slot or an expired slot blocks new reservations until production management reviews actual release. Valid future reservations remain supported while another WO is running.

## Evidence

Frontend: 27 tests across CrewPanel, ReworkPanel, WO detail, production schedule and employee self-service passed. Type checking and production build passed.

Real headless Chromium: one combined scenario passed using separate PPC, production manager, QC, recorder, support and outsider accounts against `http://localhost:18080`. It exercised preview/application and duplicate application, multiple WOs for the same workers in separate slots, wrong-department and overlap rejection, current production despite future mold reservations, QC release before output, recorder-only output, handover and original-actor replay, retained pause, explicit equipment release, reviewed takeover, and resume rejection while another WO owns the mold. Desktop/mobile screenshots were inspected. A separate-process concurrency check ran two competing worker assignments against distinct WOs with overlapping slots: one committed, one was blocked, and exactly one current crew remained.

The final combined backend run passed 128 tests with 460 assertions, including added manual-assignment HR, newly approved leave, unresolved equipment ownership, and QC-start regressions. Test fixtures supply date-effective shifts and approved schedule rows; repair fixtures use the actual repair date rather than the original WO's later planned date. Frontend tests, the real headless scenario, the concurrent assignment proof and the production build were rerun successfully. Tests use `phpunit.pricing.20261004.xml` and its disposable database, never the business database. Database suites run serially.

## Readiness and rollout

The read-only development report identified 15 active WOs without active schedule rows, mostly prior QC/operation test records; 0 terminal reservations and 0 expired active reservations; 85 active Production employees. No existing crew or elapsed schedule was fabricated. PPC must review unstarted records; production management must review actual legacy execution ownership.

Three additive migrations were applied to the development and isolated audit applications. The repair guard function was refreshed after review hardened the original-context check. Business output and historical schedule timing were preserved. Ownership migration rollback deliberately refuses legacy application code that ignores current ownership.

An unrestricted development migration run encountered an existing ledger mismatch: `2026_10_03_000005_add_qc_recovery_quantity_snapshots` attempted to add an already existing `recovery_batch_quantity` column. The current slice's migrations were applied explicitly by path; the unrelated ledger mismatch remains outside this slice.

## Limits

This is production assignment verification, not a complete Sales Order-to-payment E2E certification. Department is the current qualification boundary; no certification catalog or optimization engine was added. Multi-day split jobs and guessed maintenance recovery windows are not supported. Planned staffing requires real HR coverage; no separate access-window or overtime permission model was introduced. Mold counters are not converted until their historical units are verified.

An approved interval is a planning estimate, not a guarantee of actual finish. Start is allowed within that interval; actual overtime retains ownership, blocks takeover, and raises scheduling review alerts. No automatic release or confirmed-plan movement occurs at the estimated end. Maintenance changes flag existing commitments for explicit review. NCR repair uses its existing crew assignment and slot-review flow, including crew-first setup; combined automatic equipment proposal/application is restricted to ordinary unstarted production.

## Reproduce

```bash
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter "ProductionResourceChangeTest|ProductionResourceReservationTest|CapacityPlanningServiceTest|WorkOrderCrewTest|WorkOrderStartResumeOccupancyTest|WorkOrderInlineReworkTest|WorkOrderMaterialIssueHandoffTest|WorkOrderCloseQualityGateTest|WorkOrderOutputIdempotencyKeyTest|MoldStatusRestoreOnCompleteTest|MachineStatusTransitionTest|MachineMoldCrudTest|MaintenanceWorkOrderMachineDowntimeTest|AlertsHardeningTest|MachineBreakdownLifecycleConcurrencyTest|WorkOrderStartQcGateTest"
```

The isolated browser fixture and scenario are `scripts/wo-crew-e2e-fixture.php` and `spa/e2e-real/wo-worker-assignment.spec.ts`. The separate-process proof uses a fresh fixture followed by concurrent `scripts/wo-crew-concurrency-check.php first` and `second`, then `verify`. All fixture scripts reject the business database.

### Editable review follow-up

The real headless scenario passed after clicking Confirm on a Planned WO, editing the maximum in the shared review, adding an eligible worker, verifying the cap, removing that worker and confirming the assignment. It retries the exact captured application payload and then continues through production, QC and handover. Desktop and mobile captures show equipment, scheduled time and crew controls. The frontend suite passed 29 tests; the combined crew, scheduler and resource gate passed 39 tests with 145 assertions. The final production build, targeted ESLint, Pint and diff whitespace checks passed. The design detector reported no findings.

### Worker visibility and Details layout follow-up

The headless scenario passed with an unavailable Production employee visible and disabled in Add worker, while eligible workers could still be added and assigned. The frontend suite passed 31 tests, including worker containment inside Details and cost placement after Activity. The combined crew, scheduler and resource gate passed 40 tests with 166 assertions, covering shift/leave/overlap reasons, office exclusion, assignment rejection and a list larger than 50 employees. Desktop/mobile captures were inspected; the final production build, targeted ESLint, Pint and design detection passed.

The subsequent modal-only layout removed Assigned workers from Details and moved Manage assignment into the WO action bar. Its crew and recorder remain visible inside the editing modal. The two affected frontend files passed 14 tests; the real headless assignment/production scenario and production build passed. Repair crew cards retain their existing context-specific display.

### Read-only worker summary and employee test setup

Details now includes a compact read-only Assigned workers row, with the output recorder marked. Editing remains in Manage assignment. The two affected frontend suites passed 14 tests; the real headless role-based assignment, production, QC, and handover scenario passed, with desktop/mobile worker summary captures. The production build, targeted ESLint, whitespace check, and design detection passed.

The explicitly authorized demo setup created 186 missing employee accounts with the employee role and test password, assigned 200 missing shifts, and preserved all 20 existing employee-linked accounts and six existing shift assignments. All 206 active employees now have active accounts and current shifts. Missing shop-floor shifts were balanced across 06:00–14:00, 14:00–22:00, and 22:00–06:00; office departments received 08:00–17:00. Four email collisions received distinct employee logins. A second preview reported zero additions. A private database backup was taken before applying the transactional script, which rejects production and non-demo databases and sends no welcome notifications.

Live headless checks verified new Production and HR employee logins, employee linkage, and rejection of general production access. PPC's existing login remained valid. WO-202610-0013 returned 28 eligible workers and no blockers; its Confirm modal automatically selected Roberto Santos as worker and output recorder. The work order was not confirmed by this check. Opening the modal before the data setup finished showed the old empty suggestion until refresh or reopening.

The login-and-shift roster is stored at `api/storage/app/employee-test-accounts-20261005.csv`. New accounts use the test password; existing account passwords were preserved. Run `docker exec ogami-api php scripts/employee-test-setup.php` for a preview, or add `--apply` for the guarded demo setup. The local loopback hostname was not configured for Sanctum's stateful browser domains, so live login verification used the user's configured application host.

### Automatic staffing refresh

New assignment reviews start with an empty minimum dropdown and maximum four. Selecting minimum or maximum refreshes the crew suggestion after 300 ms; existing crews retain their saved minimum when edited. Suggestions do not save assignments. The latest reviewed proposal must match the selected limits before Apply is enabled, and the entered reason survives refresh. Concurrent initial equipment generation shares one pending request. Minimum, maximum, and submitted crew size are capped at four in HTTP validation and the shared assignment service. Legacy null maximum is interpreted as four for new assignments. Remove worker uses semantic danger text.

The affected frontend suites passed 15 tests. The crew, scheduler, and resource gate passed 40 tests with 170 assertions, including server rejection above four. The real headless role-based assignment, production, QC, and handover scenario passed using dropdown staffing and automatic refresh. Live PPC verification on WO-202610-0013 confirmed blank initial minimum, automatic worker selection, and the rendered danger color on Remove worker without confirming the work order. Desktop and mobile review captures were inspected. Targeted ESLint, Pint, whitespace checks, and design detection passed.

### Automatic and manual assignment modes

The review now starts in automatic mode with one Number of workers dropdown, initially empty and limited to 1–4. The selected count is saved as both staffing bounds; a separate maximum field is no longer shown. Changes refresh the suggestion after 300 ms. Manual assign reveals Add worker and removal controls while hiding the count dropdown. Auto assign restores the count and automatic suggestion. Manual selections remain limited to four workers, and exactly one selected worker with an active account must record output. Existing crews open in manual mode inside Manage assignment.

Initial assignment no longer asks for a typed reason. The application records an initial-assignment reason automatically. Changing an existing crew retains a required Handover reason so responsibility changes remain traceable. Equipment, schedule, availability, permission, and version checks remain enforced.

The affected frontend suites passed 15 tests, including switching both ways, manual selection, exact staffing bounds, and initial assignment without a reason field. The real headless Chromium scenario passed in 56.5 seconds using separate PPC, production manager, QC, recorder, support, and outsider accounts. It exercised both modes before confirmation and continued through production, QC, scoped output, and handover. Desktop and mobile manual-mode captures were inspected. Targeted ESLint and the production build passed.

### Worker changes and employee-only crews

Change workers replaces Manage assignment and appears at the far right of the Assigned workers row in Details. On narrow screens the actions wrap below the names. Its modal contains worker selection, removal, one output recorder, and Save workers. Staffing bounds follow the selected crew size, capped at four. An unchanged crew cannot be saved. Removing the recorder selects another account-bearing worker when available. Initial assignments and changes before production receive an automatic audit reason; live or repair handovers still require an explicit reason. Review production slot is a separate action and modal, preserving scheduling and repair access without mixing them into worker editing.

Automatic suggestions, manual worker options, assignment validation, and production readiness now use active Production employees with active employee-role accounts. Managers and other roles are excluded even when their HR department is Production. Existing crew history remains intact, and server validation rejects a manager ID supplied manually.

The affected frontend suites passed 28 tests across worker assignment, WO Details, and repair. The combined crew, scheduling, and resource gate passed 43 tests with 191 assertions, including employee-role filtering and manual manager rejection. The final role-based headless scenario passed in 1.3 minutes after exercising initial worker selection, both confirmation modes, scoped output, and recorder handover through Change workers. Desktop and mobile captures were inspected; the narrow-screen worker row was adjusted to keep names readable. Targeted ESLint, Pint, and whitespace checks passed.
