# Email audit progress

Updated: 2026-10-21

## Status

- Phase 0 discovery: complete as static source/config inspection. See [00-discovery.md](00-discovery.md).
- Phase 1 inventory: provisional source-code inventory; owner approved continuing. See [01-inventory.md](01-inventory.md).
- Phase 2 shared foundation: in draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) on branch `email/shared-foundation`; header uses the landing-page logo artwork, with a transparency-preserving white variant selected in dark mode. Header previews cover desktop/mobile and light/dark; existing per-email screenshots need regeneration as branches are rebased.
- Tests: focused shared-shell coverage passed (6 tests, 22 assertions), including preheader delivery and white-logo pixel checks; temporary rendered preview passed (1 test). Mailer forced to `array`; no mail transport was contacted.
- Shared preheader forwarding defect: stock Markdown message component dropped per-email preheaders before the layout. Fixed in #213; E-005/E-006 tests exposed and verify the shared forwarding behavior.
- Shared visual defects: inlined light colors blocked dark-mode overrides and 600px table width overflowed mobile browser previews. Fixes in #213 remove color priority conflicts and constrain mobile tables; recheck all template screenshots after foundation update.
- Markdown table styling: isolated follow-up branch `fix/email-markdown-table-style` styles Laravel's direct Markdown tables in light/dark, while excluding CTA tables. `FeatureEmailIntegrationTest::test_supplier_purchase_order_email_is_queued_with_supplier_data`: 1 test, 2 assertions passed; rendered test mail inspected at 1440px/390px in light/dark, no horizontal overflow. Preview contains test fixture company data. Focused test uses `MAIL_MAILER=array`; no message sent.
- E-019 cross-template validation: rendered the RFQ lifecycle mailable with this PR's layout selectors. `SupplierRfqLifecycleMailTest` passed (1 test, 73 assertions); 20 Chromium screenshots cover five statuses at 1440px/390px and light/dark. Every document width matched its viewport; inspected captures show the styled table, logo, CTA, and footer. Test branding only; no email sent. Screenshots: `e019-rfq-lifecycle-*.png`.
- Email delivery: none sent.

## Email UI work — 2026-10-21

- E-001 invoice issued, E-002 official receipt, and E-003 invoice dunning now use the shared email shell's preheader/title, clearer key-fact tables, and consistent peso display. Invoice line items now use the shared responsive Markdown table styling. No trigger, recipient, or record source changed.
- Browser screenshots and template tests are pending; this worktree has no `api/vendor` or local `.env`. No email was sent. Do not mark this group complete until its rendered desktop/mobile and light/dark previews are inspected.

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
