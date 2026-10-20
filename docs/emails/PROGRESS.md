# Email audit progress

Updated: 2026-10-20

## Status

- Phase 0 discovery: complete as static source/config inspection. See [00-discovery.md](00-discovery.md).
- Phase 1 inventory: provisional source-code inventory; owner approved continuing with “proceed.” See [01-inventory.md](01-inventory.md).
- Phase 2 shared foundation: complete in draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) on branch `email/shared-foundation`; all per-email template redesigns remain pending.
- Phase 4 mail queue isolation: complete in draft PR [#214](https://github.com/kwat0g/kwatog/pull/214), stacked on #213; queued mailables and selected mail-only notifications use a dedicated queue with after-commit dispatch and bounded retry. See [03-delivery.md](03-delivery.md). Sync critical-alert delivery and payslip transport inside its existing queue job remain unchanged pending their own delivery-state/security review.
- Phase 2 tests: 13 tests, 31 assertions. Phase 4 queue-policy and response-flow tests: 49 tests, 329 assertions. No mail transport was contacted.
- Email delivery: none sent.

## Blockers

- Initial local SMTP credentials were cleared, both local mailers changed to `log`, and Horizon restarted. The code branch adds fail-closed recipient redirection for non-production mail.
- Core company settings exist in the database; sales inbox and VAT status match old fallback literals and need owner verification. Runtime notification preferences were not queried.
- No production SMTP credentials remain in the local `.env` files checked during discovery; no message was sent.

## Findings

- See [FINDINGS.md](FINDINGS.md). Separate functional/security fixes from template work.
- Inventory provisional count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.

## Next step

Continue with external-facing email types in separate PRs. Confirm company values with the company owner before any real send.
