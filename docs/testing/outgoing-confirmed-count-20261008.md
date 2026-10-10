# Outgoing confirmed-check QC verification

New eligible finish-first WO output inspections use immutable counted-capture version 3 with `evidence_method: confirmed_checks` when `WO_OUTGOING_CONFIRMED_CHECKS_ENABLED=true`. Existing version-2 inspections retain mandatory actual readings. Incoming, returns, and mandatory intermediate inspections keep their existing methods.

The outgoing page uses the existing QC record layout. Inspectors see pinned target/limit and surface references, record defective quantity and one description/primary failed check per defective piece, and confirm every physical piece against every listed requirement. Shared problems apply to selected pieces. PASS ALL records zero defects; it does not confirm physical checks or release stock. Independent review recalculates the outcome. Numeric entry is rejected for this method; certificates disclose that numeric measurements were not recorded.

## Evidence

- 59 distinct Quality backend tests passed across confirmed checks, controlled replacement, version-2 evidence, certificates, recovery, inline repair, and finish-first policy. The changed confirmed-check and replacement suites were rerun after their final changes.
- 20 late-production tests passed, including former recorder access after the schedule expires, locked times, independent review, retry safety, and historical source guards.
- 31 focused UI tests passed across the panel, outgoing page, and existing inspection detail page. The final panel/page rerun passed 20 tests.
- TypeScript/Vite production build passed. Changed-file ESLint, PHP syntax checks, and diff whitespace checks passed. The one Impeccable detector run reported no findings.
- Desktop 1440 px and mobile 390 px renders had no page overflow or JavaScript errors. Confirmed-check rendering requires no numeric reading input and keeps submission disabled until full confirmation. Screenshots: `/tmp/final-qc-polish/final-qc-1440.png` and `/tmp/final-qc-polish/final-qc-390.png`.
- Two real-backend Playwright scenarios passed together on fresh disposable 500-piece fixtures in 46.5 seconds. Final transcript: `/tmp/confirmed-qc-browser-final.log`; clean last-run report: `/tmp/kwatog-outgoing-confirmed-final-last-run.json`. Final changed-backend transcript: `/tmp/confirmed-qc-final-backend.log`. Production manager starts/finishes; recorder reports 500; QC inspector uses zero or five defects; recorder QC writes return 403; maker review returns 403; an independent reviewer approves. Output quantity stays unchanged. Warehouse sees 500 held before review; zero defects release 500, while five defects keep 500 held pending disposition.
- Backend five-defect recovery proves only 495 of 500 can release after independent physical segregation; five defective pieces stay on the existing repair/disposition path. Critical/unclassified failures remain conservative. Blank descriptions and missing confirmation cannot submit. Direct version-3 tests cover all 500 pieces defective with zero release and atomic multi-output confirmation/review. The multi-output test exposed missing eager loading of result authors and source work orders; the shared query now loads both explicitly.

All test writes used `ogami_test_finish_first_core_20261008` or `ogami_test_finish_first_e2e_20261008` in `ogami-finish-first-20261008`. No live production, PASS confirmation, measurements, defects, or segregation evidence was fabricated.

## Reproduce browser checks

Copy the current API, fixture script, and built SPA into the existing disposable workspace first. Its API must enable `WO_OUTGOING_CONFIRMED_CHECKS_ENABLED=true`; apply the additive defect-reference migration before creating fixtures. Each fixture is consumed by one scenario and must be recreated before rerunning it.

```bash
docker exec -e DB_DATABASE=ogami_test_finish_first_e2e_20261008 -e E2E_FINISH_FIRST_QUANTITY=500 -e E2E_FINISH_FIRST_CONFIRMED_CHECKS=true -e E2E_FINISH_FIRST_SCHEDULE_HOURS=8 -e E2E_FINISH_FIRST_MANIFEST_OUTPUT=/var/www/storage/app/outgoing-confirmed-zero.json -w /var/www ogami-finish-first-20261008 php scripts/finish-first-e2e-fixture.php
docker exec -e DB_DATABASE=ogami_test_finish_first_e2e_20261008 -e E2E_FINISH_FIRST_QUANTITY=500 -e E2E_FINISH_FIRST_CONFIRMED_CHECKS=true -e E2E_FINISH_FIRST_SCHEDULE_HOURS=8 -e E2E_FINISH_FIRST_MANIFEST_OUTPUT=/var/www/storage/app/outgoing-confirmed-five.json -w /var/www ogami-finish-first-20261008 php scripts/finish-first-e2e-fixture.php
```

From `spa`:

```bash
E2E_BASE_URL=http://localhost:18084 E2E_FINISH_FIRST_ZERO_MANIFEST=/tmp/kwatog-finish-first-20261008/api/storage/app/outgoing-confirmed-zero.json E2E_FINISH_FIRST_FIVE_MANIFEST=/tmp/kwatog-finish-first-20261008/api/storage/app/outgoing-confirmed-five.json PLAYWRIGHT_LAST_RUN_OUTPUT_FILE=/tmp/kwatog-outgoing-confirmed-last-run.json ./node_modules/.bin/playwright test --config=playwright.outgoing-confirmed.config.ts --project=real-chromium --reporter=line --output=/tmp/kwatog-outgoing-confirmed-final-results
```

## Live rollout

The additive migration was applied before activation. Compatible API/SPA passed verification before enabling creation. Configuration was cleared and the queue restart signal produced a new worker.

WO-202610-0004 was still completed with 500 produced pieces and untouched QC-202610-0005: draft, version 2, zero authors/readings/defects/accepted quantity. Its approved explicit replacement created QC-202610-0007 as a version-3 draft. The original remains cancelled with version 2 and replacement reason; both use the same output and published specification revision. The audit records QC inspector user 13 and source command `approved-outgoing-confirmed-check-rollout-20261008`. Repeating the exact original manifest returned the same replacement without creating another inspection.

After replacement: 500 produced, zero accepted, zero readings/authors/defects on the new draft. No live physical-check confirmation or release was performed.

## Limits and rollback

Turning new creation off does not weaken or erase existing version-3 records. The migration refuses schema rollback while those records exist; retain compatible readers and the additive column. Actual measurement evidence, customer-required measurement reports, and calibration remain distinct from confirmed checks. This method does not satisfy a customer requirement to retain individual numeric readings.

The existing inverted production finish timestamp on WO-202610-0004 is outside this change and remains for a separate correction. No broader repository suite, serial scanning, machine vision, or measurement import was added.
