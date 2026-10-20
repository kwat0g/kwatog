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
- E-008 supplier PO cancellation snapshot fix: complete in draft PR [#227](https://github.com/kwat0g/kwatog/pull/227), stacked on #226. EF-021 covers queued recipient/data drift and render-time date. E-008 redesign is complete in draft PR [#228](https://github.com/kwat0g/kwatog/pull/228), stacked on #227; screenshots cover desktop/mobile and light/dark.
- Shared HTML shell in #213 now forwards per-message preheaders and document titles, preserves responsive width and dark-mode colors/links, and uses light/dark company-logo artwork.
- E-008 focused verification: 5 tests passed, 22 assertions; four browser screenshots inspected in light/dark at desktop/mobile; rendered HTML about 22 KB. Mailer forced to `array`.
- E-009 complaint email content/security/snapshot fix: complete in draft PR [#229](https://github.com/kwat0g/kwatog/pull/229), stacked on #228. EF-022 records excessive customer data in external messages and queued-record drift. E-009 redesign is complete in draft PR [#230](https://github.com/kwat0g/kwatog/pull/230), stacked on #229; screenshots cover desktop/mobile and light/dark.
- E-009 focused verification: 3 tests passed, 19 assertions; four browser screenshots inspected in light/dark at desktop/mobile; rendered HTML about 21 KB. Mailer forced to `array`; no transport contacted.
- E-010 customer sales-order confirmation snapshot: complete in draft PR #231, stacked on #230. EF-023 records mutable customer/order/line values; event-time snapshot now carries recipient, order/line values, and SPA URL. Existing queued mailable payloads retain a fallback path.
- E-010 customer sales-order confirmation redesign: complete in draft PR [#232](https://github.com/kwat0g/kwatog/pull/232), stacked on #231. Tests: 2 passed, 20 assertions. Outbox fact-event tests: 7 passed, 15 assertions. Four browser screenshots inspected at 1440px desktop and 390px mobile in light/dark. Rendered HTML 27 KB; mailer forced to `array`; no transport contacted.
- E-011 customer sales-order decision snapshot: complete in draft PR [#233](https://github.com/kwat0g/kwatog/pull/233), stacked on #232. EF-024 covers mutable content, invalid addresses, grouped recipient disclosure, and no-recipient fallback.
- E-011 customer sales-order decision redesign: complete in `email/customer-sales-order-decision`, stacked on #233; draft PR pending. `SalesOrderResponseTest`: 18 passed, 84 assertions. Four browser screenshots inspected at 1440px desktop and 390px mobile in light/dark. Rendered HTML about 24 KB; mailer forced to `array`; no transport contacted.
- E-012–E-015 candidate-email snapshot/security fix: implementation and focused tests complete in draft PR #235, stacked on #234. EF-025 freezes event-time applicant/interview values and stops internal outcome-only changes from notifying candidates. See [E-012-015-candidate-emails.md](E-012-015-candidate-emails.md). Tracking code remains sensitive candidate access data and requires HR/legal/data-protection review.
- E-012–E-015 candidate template family: complete in draft PR #236, stacked on #235. Shared branding, safe labels, tracking CTA, escaping, event-time values, no assessment outcomes, and plain-text render verified. `RecruitmentApplicationTest`: 19 passed, 83 assertions; focused template/snapshot subset: 5 passed, 31 assertions. 16 Chromium screenshots inspected at 1440px/390px in light/dark; no horizontal overflow; HTML 21–23 KB. Mailer forced to `array`; no message sent. Real email clients remain unverified.
- EF-026 SPA return-path fix: complete in draft PR #237, stacked on #236. Preserves the exact internal route through sign-in and required password change. `AuthGuard.test.tsx`: 3 passed; SPA typecheck passed.
- E-016 payslip email: complete in draft PR #238, stacked on #237. Removed sensitive PDF attachment; deep link opens authenticated, employee-owned confirmation page and downloads only after a deliberate click. Existing finalization queue/retry/status behavior preserved. Backend tests: 9 passed, 45 assertions across job, trigger, and owner-scope files; SPA download-page test passed; SPA typecheck passed. Four Chromium screenshots inspected at 1440px/390px, light/dark; 19.7 KB HTML, no payroll amounts. Mailer forced to `array`; no mail sent. EF-027 needs HR/legal/data-protection review.
- E-017 supplier PO decision: complete in this draft PR, stacked on #238. Mailable captures PO/vendor/response/decision values at queue time; redesigned status notice uses shared brand shell, human response label, recorded total, proposed date, and supplier portal link. `SupplierPoDecisionMailTest`: 1 passed, 18 assertions. Four Chromium screenshots inspected at 1440px/390px, light/dark; HTML 25.3 KB, no overflow or missing logo. Mailer forced to `array`; no mail sent. EF-028 (shared external recipient list) remains a separate functional fix.
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

Continue E-018 onward. E-017 external recipient-address exposure EF-028 remains separate. Recheck stacked PRs against the updated shared foundation; regenerate E-001–E-004 screenshots for final dark-mode/mobile CSS. Confirm company values with the company owner before external delivery.
