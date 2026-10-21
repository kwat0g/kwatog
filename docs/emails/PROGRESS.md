# Email audit progress

Updated: 2026-10-21

## Status

- Phase 0 discovery: complete as static source/config inspection. See [00-discovery.md](00-discovery.md).
- Phase 1 inventory: provisional source-code inventory; owner approved continuing. See [01-inventory.md](01-inventory.md).
- Phase 2 shared foundation: in draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) on branch `email/shared-foundation`; header uses the landing-page logo artwork, with a transparency-preserving white variant selected in dark mode. Header previews cover desktop/mobile and light/dark; existing per-email screenshots need regeneration as branches are rebased.
- Tests: focused shared-shell coverage passed (6 tests, 22 assertions), including preheader delivery and white-logo pixel checks; temporary rendered preview passed (1 test). Mailer forced to `array`; no mail transport was contacted.
- Shared preheader forwarding defect: stock Markdown message component dropped per-email preheaders before the layout. Fixed in #213; E-005/E-006 tests exposed and verify the shared forwarding behavior.
- Shared visual defects: inlined light colors blocked dark-mode overrides and 600px table width overflowed mobile browser previews. Fixes in #213 remove color priority conflicts and constrain mobile tables; recheck all template screenshots after foundation update.
- Email delivery: none sent.

## Current hosted checks — 2026-10-21

- PR #213 head `f9aa0220` adds isolated PHPUnit sender/sink values. PHPStan passed; PHPUnit remains in progress. Accessibility, visual-baseline, and finding-lifecycle gates passed. The web dependency audit still reports high-severity `vite` and `follow-redirects` advisories. The passing lockfile fix is [PR #271](https://github.com/kwat0g/kwatog/pull/271), on a separate 98-commit branch, so it is not present in #213's check merge.
- Three shared API failures in `AssetAssociationTest` came from a midnight-sensitive attendance fixture (`now() - 1 hour` before a midnight shift). Test-only correction is in [PR #328](https://github.com/kwat0g/kwatog/pull/328); its PHPStan and PHPUnit checks are running.
- PR #325's earlier API run failed because its stale base lacked `MAIL_TEST_TO`; the test sender config is now in PR #213. Rebase stacked branches after shared checks settle. No real email was sent.
- Fresh sender/class scan confirms the inventory count: 34 production message implementations plus one test-only Mailable. The two HR account messages subclass the auth welcome/reset notifications, so a direct `extends Mailable|Notification` scan alone misses them; they are covered as E-032/E-033.

## Blockers

- Initial local SMTP credentials were cleared, both local mailers changed to `log`, and Horizon restarted. The code branch adds fail-closed recipient redirection for non-production mail.
- Core company settings exist in the database; sales inbox and VAT status match old fallback literals and need owner verification. Runtime notification preferences were not queried.
- Local `.env` SMTP was disabled during continued work; both local files now use `MAIL_MAILER=log`, credentials blank, API/Horizon restarted. Test commands additionally forced the `array` mailer.
- Shared HTML shell now forwards each message's hidden preheader and document title, preserves responsive width and dark-mode colors/links, and uses light/dark company-logo artwork.

## Findings

- See [FINDINGS.md](FINDINGS.md). Separate functional/security fixes from template work.
- Inventory provisional count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.

## Next step

Owner has said “proceed.” Finish shared CI gates, synchronize dependent draft branches, then report remaining owner/provider checks. Confirm company values with the company owner before any real send.
