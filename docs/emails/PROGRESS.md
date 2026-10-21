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
- E-005/E-006 desktop/mobile light/dark previews were refreshed from their mailables with dummy tokens in PR #224 commit `75f1c70f`; 8 captures inspected with no clipping. PR #224's current head `6c15a115` includes that refresh. E-018's four previews are refreshed at PR #243 commit `8c244f17`; no stale-preview action remains for these three groups.
- Refreshed and inspected E-007, E-008, and E-009's four desktop/mobile light/dark captures in their own PRs (`275319ac`, `ccf582d5`, `af2946a3`). Confirmed hostile supplier/customer text remains escaped and layouts fit; no mail sent.
- Refreshed all E-012–E-015 desktop/mobile light/dark previews from current mailables on PR #236; inspected all 16 views across the group. Logo, CTA labels, details, footer, and mobile widths render correctly. E-013's extra raw-URL paragraph was stale capture content, not a template defect. Synthetic branding/data only; no mail sent.
- Reopened all four E-016 and E-017 desktop/mobile light/dark captures. E-016 contains no salary data; its footer is fixture branding. E-017's two-column summary wraps on mobile but remains readable. CTA labels and dark logo are visible.
- Email delivery: none sent.

## Email UI work — 2026-10-21

- E-001 invoice issued, E-002 official receipt, and E-003 invoice dunning now use the shared email shell's preheader/title, clearer key-fact tables, and consistent peso display. Invoice line items use a stacked, mobile-safe layout. No trigger, recipient, or record source changed.
- Rendered the three mailables with synthetic fixture records in a network-isolated PHP container, with `MAIL_MAILER=array`; no email was sent. Playwright screenshots cover 1440px desktop and 390px mobile in light/dark (`e001-accounting-*`, `e002-accounting-*`, `e003-accounting-*`). Inspected all 12 captures; viewport and document widths match. The first invoice line-item table wrapped badly on mobile, so it now uses a stacked list. E-003 links to the existing customer invoice detail route; the portal layout redirects unauthenticated users to sign-in while preserving that path. Added a focused link assertion; no local PHPUnit rerun. PR #325's CI check was already running on an earlier commit. Real email clients remain unverified.
- Reviewed existing desktop/light and mobile/dark captures for E-011–E-017 and E-020–E-022. No visible clipping or theme defects found in those captures. E-017's summary table wraps on mobile but remains readable. E-010's compressed item table was changed to stacked details in PR #232 commit `0006d775`; E-018 received the same treatment in PR #243 commit `a18dde45`.
- Reviewed existing desktop/light and mobile/dark captures for E-026 and E-027. E-026's logo remains visible in both themes and the alert card/CTA are clear. E-027's long daily report and weekly comparison row wrap but remain readable without viewport clipping. These are existing captures; current-foundation re-render is still pending.
- Reviewed desktop/light and mobile/dark captures for E-029 and E-031–E-034. Security emails and the contact-inquiry notice have clear hierarchy, visible CTAs, and no clipping. E-030's temporary-password flow was removed; no preview retained. Security-email captures use synthetic configured branding. Existing captures only; no real client was tested.
- Re-rendered E-010 from the updated Mailable with synthetic order/branding fixtures in a network-isolated container and `MAIL_MAILER=array`: 27,352-byte HTML. Re-rendered E-018 the same way: 34,357-byte HTML. Refreshed and inspected each email's desktop/mobile light/dark screenshots; browser document width matched viewport and CTA labels were visible in focused captures. No email sent. Footer values are preview data; real clients remain unverified.
- PR #232's API CI failed at PHPStan on undefined `$creditNote` in `EmailPartyOnCreditNoteFinalized.php:55`; PHPUnit was skipped. EF-027 is tracked in [FINDINGS.md](FINDINGS.md), and the separate fix is in draft PR #312. This finding predates the E-010 UI change.

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
