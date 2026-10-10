# Production schedule verification — October 5, 2026

The production schedule page previously overlaid work orders sharing a machine and calendar day. Its UTC day calculation also positioned Manila midnight on the previous day. Existing automatic proposals could only be approved after rerunning the scheduler.

The timeline now gives each work order its own row and uses the server's plant timezone. The schedule list shows product, sales order, quantity, machine, mold, scheduled times, schedule approval state, and work order state. Search is debounced. Date ranges include the selected final day and reject reversed or excessively large ranges.

Users with `production.schedule.confirm` can select saved pending proposals without rerunning the scheduler. Approval requires a review dialog and a fresh read; changed resources, changed times, stale work order states, and failed reads block submission. Failed confirmation keeps the review available. The scheduler rerun dialog explains that its scope is all planned work orders rather than the page filters. Existing backend permission and reservation rules remain authoritative.

PPC currently has schedule viewing and planning permission but lacks schedule approval permission. The page explains the handoff to Production Manager instead of granting additional access.

## Verification

- Six frontend regression tests passed, including same-day visibility, Manila dates, saved selection approval, role handoff, changed proposal rejection, and read recovery.
- Eight backend capacity-planning tests passed with 22 assertions on the isolated `ogami_test_pricing_20261004` database.
- Three headless Chromium scenarios passed using mocked Production Manager and PPC accounts, covering selection, confirmation failure recovery, mobile overflow, and failed snapshot recovery.
- Real PPC and Production Manager accounts passed read-only headless checks against the running application. SO-202610-0028 has three separately visible proposals dated October 11, 2026. The manager opened and cancelled the three-WO approval review. No scheduling or production business mutations were submitted.
- Desktop and phone screenshots were inspected. Scoped ESLint, TypeScript, and the SPA build passed; the design detector reported no deterministic issues.

The live work orders remain planned. BU-001 still requires a QC inspection specification before production proceeds through its quality gates.
