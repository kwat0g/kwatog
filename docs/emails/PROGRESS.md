# Email audit progress

Updated: 2026-10-20

## Status

- Phase 0 discovery: complete as static source/config inspection. See [00-discovery.md](00-discovery.md).
- Phase 1 inventory: provisional source-code inventory; owner approved continuing. See [01-inventory.md](01-inventory.md).
- Phase 2 shared foundation: in draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) on branch `email/shared-foundation`; header uses the landing-page logo artwork, with a transparency-preserving white variant selected in dark mode. Header previews cover desktop/mobile and light/dark; existing per-email screenshots need regeneration as branches are rebased.
- Tests: focused shared-shell coverage passed (6 tests, 22 assertions), including preheader delivery and white-logo pixel checks; temporary rendered preview passed (1 test). Mailer forced to `array`; no mail transport was contacted.
- Shared preheader forwarding defect: stock Markdown message component dropped per-email preheaders before the layout. Fixed in #213; E-005/E-006 tests exposed and verify the shared forwarding behavior.
- Shared visual defects: inlined light colors blocked dark-mode overrides and 600px table width overflowed mobile browser previews. Fixes in #213 remove color priority conflicts and constrain mobile tables; recheck all template screenshots after foundation update.
- Email delivery: none sent.

## Blockers

- Initial local SMTP credentials were cleared, both local mailers changed to `log`, and Horizon restarted. The code branch adds fail-closed recipient redirection for non-production mail.
- Core company settings exist in the database; sales inbox and VAT status match old fallback literals and need owner verification. Runtime notification preferences were not queried.
- Local `.env` SMTP was disabled during continued work; both local files now use `MAIL_MAILER=log`, credentials blank, API/Horizon restarted. Test commands additionally forced the `array` mailer.

## Findings

- See [FINDINGS.md](FINDINGS.md). Separate functional/security fixes from template work.
- Inventory provisional count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.

## Next step

Owner has said “proceed.” Continue with reliability and per-email redesign units. Confirm company values with the company owner before any real send.
