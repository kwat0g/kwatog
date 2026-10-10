# Remaining-module audit evidence

Date: 2026-10-05. These artifacts support the [audit report](../../remaining-business-modules-deep-audit-20261005.md) and [proposed implementation plan](../../../superpowers/plans/2026-10-05-remaining-business-module-hardening.md).

`RemainingModulesAuditProbeTest.php` asserts reproduced current behavior, including defects. It is an audit artifact, not a desired-behavior regression suite. Its successful output is **19 tests / 64 assertions**. Do not copy its defect-accepting assertions into shipped tests. PHP tests ran against the explicitly guarded disposable `ogami_test_pricing_20261004` database through the dedicated configuration; fixtures roll back. Events were faked, so most probes isolate owning services rather than downstream notification/queue behavior.

`phpunit-safeguards.txt` records **29 tests / 184 assertions** from these existing suites:

- `tests/Feature/Maintenance/MaintenanceOperationalSafetyTest.php`
- `tests/Feature/Infrastructure/ScheduledExportExecutionTest.php`
- `tests/Feature/Purchasing/ProcurementRoleBoundaryTest.php`
- `tests/Feature/Security/PayrollAuthorizationTest.php`

`browser-results.json` records **80 read-only page visits**: 40 routes across seven roles, at 1440px and 390px. It also retains seven API-boundary observations. One original Warehouse endpoint was incorrect and returned 404; use the corrected `/journal-entries` 403 in `ui-followup-results.json`. Impex vehicle lookup 200 is an authorized lookup, not a denial failure. Hidden native select mirrors are excluded from missing-label findings.

`ui-followup-results.json` records 18 layout/theme observations: three pages, three widths, two themes, plus the corrected Warehouse journal boundary. It measures all nine balance-sheet amount/header/total cells clipped beyond their initial container edges at 1440px, 768px and 390px, and shipment detail body width 455px at a 390px viewport. Theme changes were local document attributes; no preference write occurred. Screenshots show rendered audit fixtures, not production business records.

`palette-contrast.txt` records **93 passing token-contrast tests**. This proves the tested combinations, not complete rendered WCAG compliance. `impeccable-detector.json` is an empty detector result; it does not supersede manual layout findings. `rbac-static-audit.txt` reports zero referenced permissions missing from the seed catalog.

Browser scripts require `spa/node_modules/@playwright/test` and run from `spa` against the isolated application at `http://localhost:18080`:

```bash
node ../docs/testing/evidence/remaining-modules-20261005/remaining-modules-browser.cjs
node ../docs/testing/evidence/remaining-modules-20261005/remaining-modules-ui-followup.cjs
```

Authentication creates test sessions. Business requests are GET only; document-vault-generating routes, form submissions, dispatch, money posting, backup restore, live mail and production jobs were not run. These scripts provide rendered/read-boundary evidence, not full transaction E2E coverage.

Final artifact review checked Markdown links, 25 finding IDs and their priority counts, plan coverage, missing placeholders, and browser-script JavaScript syntax. Independent review corrected approval rollback wording, PostgreSQL unique-conflict recovery, queued-mail transport verification, and asset monetary bounds in the plan. Product files were not changed by this audit.
