# Email audit progress

Updated: 2026-10-20

## Status

- Phase 0 discovery: complete as static source/config inspection. See [00-discovery.md](00-discovery.md).
- Phase 1 inventory: provisional source-code inventory; owner approved continuing with “proceed.” See [01-inventory.md](01-inventory.md).
- Phase 2 shared foundation: complete in draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) on branch `email/shared-foundation`; all per-email template redesigns remain pending.
- Phase 4 mail queue isolation and delivery ledger: complete in draft PR [#214](https://github.com/kwat0g/kwatog/pull/214), stacked on #213; queued mailables and selected mail-only notifications use a dedicated queue with after-commit dispatch, bounded retries, durable status logs, and ambiguous-retry suppression. See [03-delivery.md](03-delivery.md). Sync critical-alert delivery and payslip transport inside its existing queue job remain unchanged pending their own delivery-state/security review.
- Invoice issue-time snapshot fix: in draft PR [#215](https://github.com/kwat0g/kwatog/pull/215), stacked on #214; captures customer email and invoice display values before the queued listener can rehydrate changed records. See finding EF-007 in [FINDINGS.md](FINDINGS.md).
- E-001 invoice template: complete in draft PR [#216](https://github.com/kwat0g/kwatog/pull/216), stacked on #215. Shared Markdown layout, event-time recipient/data snapshot, portal link, 10-item display limit, plain text, and browser screenshots. See [E-001-invoice-finalized.md](E-001-invoice-finalized.md).
- Official-receipt snapshot risk EF-008 is fixed in draft PR [#217](https://github.com/kwat0g/kwatog/pull/217), stacked on #216. Document-access gap EF-009 remains open: accountant must decide whether the email summary is sufficient or a receipt PDF/portal route is required.
- E-002 official receipt template: complete in draft PR [#218](https://github.com/kwat0g/kwatog/pull/218), stacked on #217. See [E-002-official-receipt.md](E-002-official-receipt.md); EF-009 remains pending accountant direction.
- Dunning findings EF-010 (queued snapshot), EF-011 (configurable tier copy), and EF-012 (per-invoice volume) are recorded. EF-010 is fixed in draft PR [#219](https://github.com/kwat0g/kwatog/pull/219), stacked on #218. E-003 redesign follows it; EF-012 remains pending product decision.
- E-003 invoice dunning template: complete in draft PR [#220](https://github.com/kwat0g/kwatog/pull/220), stacked on #219. EF-011 fixed; EF-012 remains pending product direction. See [E-003-invoice-dunning.md](E-003-invoice-dunning.md).
- E-004 audit found EF-013 (no external credit-note document route/PDF), EF-014 (queued snapshot drift), and EF-015 (unclassified reason text is exposed). EF-014 is fixed in draft PR [#221](https://github.com/kwat0g/kwatog/pull/221), stacked on #220; E-004 template removes EF-015 in draft PR [#222](https://github.com/kwat0g/kwatog/pull/222), stacked on #221. EF-013 still needs accountant direction.
- E-005 reusable-password exposure is fixed in security PR [#223](https://github.com/kwat0g/kwatog/pull/223). E-005 invitation and E-006 password-reset templates are complete in shared portal-security PR [#224](https://github.com/kwat0g/kwatog/pull/224), stacked on #223; customer/supplier copy uses the same safe setup flow.
- E-007 supplier invoice confirmation snapshot is fixed in draft PR [#225](https://github.com/kwat0g/kwatog/pull/225), stacked on #224. The supplier-facing redesign is complete in draft PR [#226](https://github.com/kwat0g/kwatog/pull/226), stacked on #225; screenshots cover 600px/390px and light/dark.
- Phase 2 tests: 13 tests, 31 assertions. Focused Phase 4 tests: 51 tests, 314 assertions. No mail transport was contacted.
- Focused email verification: portal security suite 12 tests passed, 65 assertions; four actual-mail render screenshots inspected in desktop/mobile and light/dark. Mailer forced to `array`; no real transport contacted.
- E-007 focused verification: 1 test passed, 16 assertions; four browser screenshots inspected in light/dark at desktop/mobile; rendered HTML about 24 KB. Mailer forced to `array`.
- Shared HTML shell in #213 now forwards per-message preheaders and document titles, preserves responsive width and dark-mode colors/links, and uses light/dark company-logo artwork.
- Email delivery: none sent.

## Blockers

- Initial local SMTP credentials were cleared, both local mailers changed to `log`, and Horizon restarted. The code branch adds fail-closed recipient redirection for non-production mail.
- Core company settings exist in the database; sales inbox and VAT status match old fallback literals and need owner verification. Runtime notification preferences were not queried.
- No production SMTP credentials remain in the local `.env` files checked during discovery; no message was sent.
- Owner reports DKIM setup succeeded for `ogamiph.dev` in AWS Asia Pacific (Singapore); not independently DNS-verified. SPF, DMARC, and provider delivery/bounce setup remain unverified.

## Findings

- See [FINDINGS.md](FINDINGS.md). Separate functional/security fixes from template work.
- Inventory provisional count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.
- Delivery caveat: same-job ambiguous retries are suppressed and marked `unknown` to avoid duplicates. Separate duplicate dispatches are not deduplicated; provider acceptance does not prove inbox delivery.

## Next step

Continue with E-008 supplier PO cancellation. Recheck stacked PRs against the updated shared foundation; regenerate E-001–E-004 screenshots for final dark-mode/mobile CSS. Confirm company values with the company owner before external delivery.
