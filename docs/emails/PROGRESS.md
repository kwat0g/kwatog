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
- E-002 official receipt template: implemented in a draft PR stacked on #217. See [E-002-official-receipt.md](E-002-official-receipt.md); EF-009 remains pending accountant direction.
- Phase 2 tests: 13 tests, 31 assertions. Focused Phase 4 tests: 51 tests, 314 assertions. No mail transport was contacted.
- Focused email verification: 8 integration tests passed, plus one temporary render fixture; 9 total tests and 38 assertions. Mail fake/array transport used; no mail transport contacted.
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

Continue with E-002, the next external-facing invoice email in the inventory. Confirm company values with the company owner before external delivery.
