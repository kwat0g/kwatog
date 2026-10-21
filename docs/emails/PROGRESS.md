# Email audit progress

Updated: 2026-10-21

## Status

- Phase 0 discovery: complete as static source/config inspection. See [00-discovery.md](00-discovery.md).
- Phase 1 inventory: provisional source-code inventory; owner approved continuing. See [01-inventory.md](01-inventory.md).
- Phase 2 shared foundation: in draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) on branch `email/shared-foundation`; header uses the landing-page logo artwork, with a transparency-preserving white variant selected in dark mode. Header previews cover desktop/mobile and light/dark; existing per-email screenshots need regeneration as branches are rebased. Local commit `0e72558a` fixes the reserved PHPUnit sender/sink values and SPA lockfile audit findings; full API CI is still running on prerequisite PR #275 before these fixes can be pushed.
- PR scope: #213 now targets `fix/ui-ux-wave1-shell` (PR #209), removing unrelated inherited changes. GitHub compare now shows 37 files, 744 additions, and 87 deletions; local email fixes are not pushed while prerequisite full API CI runs.
- Tests: focused shared-shell coverage passed (6 tests, 22 assertions), including preheader delivery and white-logo pixel checks; temporary rendered preview passed (1 test). Mailer forced to `array`; no mail transport was contacted. Foundation CI rerun pending after the test sink and dependency lock fixes.
- Shared preheader forwarding defect: stock Markdown message component dropped per-email preheaders before the layout. Fixed in #213; E-005/E-006 tests exposed and verify the shared forwarding behavior.
- Shared visual defects: inlined light colors blocked dark-mode overrides and 600px table width overflowed mobile browser previews. Fixes in #213 remove color priority conflicts and constrain mobile tables; recheck all template screenshots after foundation update.
- CI EF-058: refreshed SPA lockfile to Vite 7.3.7 and `follow-redirects` 1.16.1 after audit failure; standalone PR #271 verified `npm audit`, typecheck, lint, Vitest, and build.
- CI EF-061: PHPUnit forces reserved `.test` sender and sink values so the non-production guard permits test mail and the array transport has a From header. PR #275 full API suite remains in progress.
- Email delivery: none sent.

## Blockers

- Initial local SMTP credentials were cleared, both local mailers changed to `log`, and Horizon restarted. The code branch adds fail-closed recipient redirection for non-production mail.
- Core company settings exist in the database; sales inbox and VAT status match old fallback literals and need owner verification. Runtime notification preferences were not queried.
- Local `.env` SMTP was disabled during continued work; both local files now use `MAIL_MAILER=log`, credentials blank, API/Horizon restarted. Test commands additionally forced the `array` mailer.
- Shared HTML shell now forwards each message's hidden preheader and document title, preserves responsive width and dark-mode colors/links, and uses light/dark company-logo artwork.

## Findings

- See [FINDINGS.md](FINDINGS.md). Separate functional/security fixes from template work.
- Inventory provisional count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.

## Next step

Owner has said “proceed.” Continue with reliability and per-email redesign units. Confirm company values with the company owner before any real send.
