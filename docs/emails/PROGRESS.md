# Email audit progress

Updated: 2026-10-20

## Status

- Phase 0 discovery: complete as static source/config inspection. See [00-discovery.md](00-discovery.md).
- Phase 1 inventory: provisional source-code inventory; owner approved continuing. See [01-inventory.md](01-inventory.md).
- Email ID-to-PR map: [COVERAGE.md](COVERAGE.md). Rows stay open until the mapped PR's checks and visual evidence are reviewed.
- Phase 2 shared foundation: in draft PR [#213](https://github.com/kwat0g/kwatog/pull/213); header uses the exact `spa/public/brand-logo.png` asset in light mode and the white logo variant in dark mode. Header previews cover desktop/mobile and light/dark; existing per-email screenshots need regeneration as branches are rebased.
- Local update commits `ca956fe5`, `ad106b68`, and `16571e62` sit on a clean branch based on the current PR #213 head; they add the landing-page logo, the ID-to-PR coverage map, and email content/security guidance. Not pushed while PR #213 run `39653397144` and fixture-fix run `39654544791` remain active. No new test run started.
- PR #213 run `39653397144` finished failed: one unrelated `SupplierShipmentTest::test_legacy_unquantified_shipment_must_be_reconciled_before_another_dispatch` expected 422 and got 200. EF-123's correction is tracked in draft PR #322; do not attribute this failure to email changes.
- Tests: focused shared-shell coverage passed (6 tests, 22 assertions), including preheader delivery and white-logo pixel checks; temporary rendered preview passed (1 test). Mailer forced to `array`; no mail transport was contacted.
- Shared preheader forwarding defect: stock Markdown message component dropped per-email preheaders before the layout. Fixed in #213; E-005/E-006 tests exposed and verify the shared forwarding behavior.
- Shared visual defects: inlined light colors blocked dark-mode overrides and 600px table width overflowed mobile browser previews. Fixes in #213 remove color priority conflicts and constrain mobile tables; recheck all template screenshots after foundation update. E-012–E-015 desktop/mobile light/dark captures have now been inspected and copied into this branch. E-013 preview fixture uses invalid identical stage values; logged as EF-021. E-016 payslip screenshots were inspected and copied here; they show period/status only and direct employees to the secure portal. E-017 captures were inspected; mobile PO details wrap awkwardly in two columns, logged as EF-022. E-018 captures are copied here; the 10-row fixture makes a very long email and mobile facts table is cramped, logged as EF-023. E-019 lifecycle states have representative mobile/desktop light/dark review and all 20 captures copied here; details table needs shared style #320; CI PHPStan fails.
- Email delivery: none sent.

## Blockers

- Initial local SMTP credentials were cleared, both local mailers changed to `log`, and Horizon restarted. The code branch adds fail-closed recipient redirection for non-production mail.
- Core company settings exist in the database; sales inbox and VAT status match old fallback literals and need owner verification. Runtime notification preferences were not queried.
- Local `.env` SMTP was disabled during continued work; both local files now use `MAIL_MAILER=log`, credentials blank, API/Horizon restarted. Test commands additionally forced the `array` mailer.
- Shared HTML shell forwards each message's hidden preheader and document title, preserves responsive width and dark-mode colors/links, and selects the company logo for light/dark mode. Existing header screenshots predate the exact landing-page asset and need refresh after the local update reaches the PR.

## Findings

- See [FINDINGS.md](FINDINGS.md). Separate functional/security fixes from template work.
- Inventory provisional count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.

## Next step

Owner has said “proceed.” Continue with reliability and per-email redesign units. Confirm company values with the company owner before any real send.
