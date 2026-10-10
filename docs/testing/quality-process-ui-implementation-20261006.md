# Quality inspection process and interface implementation

Implemented the approved [audit](quality-process-ui-improvements-20261006.md) using existing QC services, route filters, dialogs, notification outcomes, and the Atelier visual system. No schema changes or dependencies were introduced. Source business records in the customer's manual test were not changed.

## Delivered

- Added an existing NCR link, disposition and status to inspection details. Failed inspections explain the recovery owner; separate late incidents remain explicitly available as another defect report.
- Incoming warehouse acceptance reads the exact GRN line in its recorded unit. Outgoing quantities are labeled approved for release. Checklist analytics includes attribute failures and actual-size failures once each; dashboard totals explicitly count failed checks, not defective pieces.
- Added dataset-wide work queue counts, awaiting-review filtering, source-number search and quantity/unit/coverage display. Counts retain stage, source, date and search scope without inheriting the currently selected queue.
- Preserved record flow above compact inspection details. Checklist follows details; links and complete history sit in the desktop side column and follow the checklist on mobile. No details dropdown was introduced. Completed evidence uses readable result chips and text instead of disabled form fields.
- Added completion progress, a first-invalid-check shortcut, sticky submit controls, suggested editable failure descriptions and a single unique defective-piece count. Approved size readings determine failure without allowing a contradictory physical-defect answer.
- Added unsaved navigation protection and debounced local recovery drafts scoped by user, inspection and result version. Drafts can recover unfinished readings, problems and counts; coverage attestation is never saved or restored. Explicit Save draft persists checklist evidence through the server; remaining batch fields remain local until final submission. Logout clears local recovery drafts.
- Manual creation describes source-linked versus standalone evidence and the effective coverage policy. Removed AQL fields from the simplified creation flow; historical measurement evidence and AQL endpoints remain available. Disabled hidden SPC requests for checklist records and respected destination permissions on links.
- Closed work orders with missing outgoing QC now escalate for controlled reconciliation. They are not automatically reopened or given new release evidence. Existing delayed-event and sweep paths agree on that policy.
- Temporary session verification failures show retry guidance and honor Retry-After. They do not grant protected access or force a false logout. Confirmed unauthorized sessions still clear identity and cached data.

## Verification

- 91 focused frontend tests passed, including real navigation-seam protection, draft recovery without coverage, rejection retention, role-aware links, incoming acceptance, temporary session failures, and all five checklist stage renderers.
- Full backend Quality suite: 406 tests and 1,754 assertions passed. The focused backend gate also passed 38 tests and 133 assertions.
- Real Playwright headless: 15 fresh stage/scenario combinations passed (five stages, PASS, out-of-limit size, and document failure), plus 30 unauthorized write/review checks across employee, warehouse and PPC accounts. Independent review, stale/replay rejection, one NCR per failure and certificate restrictions were exercised against the real audit API. No API route mocks; no browser page errors or HTTP 500 responses.
- Additional real-browser draft check passed: failed check and count restored after reload, coverage unchecked, navigation cancel kept the page, server draft/version unchanged.
- Desktop 1440 px and mobile 390 px captures have no document-level horizontal overflow. The checklist starts around 599 px on desktop and 693–754 px on mobile, improved from 779–827 px and 1270–1300 px respectively.
- TypeScript, targeted ESLint, production Vite build and deterministic Impeccable scan passed. Vite retains the existing NotFound static/dynamic import warning.

Evidence: [verification artifacts](evidence/quality-process-ui-implementation-20261006/).

The first broad run inherited the live checklist flag, making legacy measurement tests exercise a different capture policy. Both PHPUnit configurations now explicitly default to legacy capture, while checklist tests opt in. Production configuration and safeguards remain unchanged. The resulting full suite passed; see `backend-deterministic.log`.

No automatic acceptance, PASS decisions, actual readings, coverage confirmations, or parallel recovery workflow was added.
