# Email audit progress

Updated: 2026-10-21

## Status

- Phase 0 discovery and Phase 1 inventory are documented in [00-discovery.md](00-discovery.md) and [01-inventory.md](01-inventory.md). Owner directed work to proceed.
- Shared framework and non-production mail guard are implemented on `email/shared-foundation`; draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) is open. Local branch is at `c4b70bd5`; GitHub PR still points to `0d1917b9`, so local commits are not yet published.
- Reliability foundation draft PR [#214](https://github.com/kwat0g/kwatog/pull/214) depends on #213.
- Per-email redesign and defect-fix work exists in draft PRs. GitHub currently lists 92 open PRs matching the email work branches or titles; each still needs a current check result and independent review.
- E-001's four browser screenshots were refreshed in the local #216 worktree at `6913c986`; prior captures still showed the retired O mark. Current company logo variants now appear in light and dark previews. See [E-001 review](E-001-invoice-finalized.md).
- E-002's four browser screenshots were refreshed in the local #218 worktree with the current landing-page logo variants; see [E-002 review](E-002-official-receipt.md).
- E-003's four browser screenshots were refreshed in the local #220 worktree with the current landing-page logo variants; see [E-003 review](E-003-invoice-dunning.md).
- E-004's eight customer/supplier browser screenshots were refreshed in the local #222 worktree with the current landing-page logo variants; see [E-004 review](E-004-credit-note.md).
- E-005 and E-006 browser previews were refreshed from the actual branch mailables with dummy tokens; no mail was sent. Four captures per type cover 1440px/390px and light/dark, with no viewport overflow. Local PR #224 commit `75f1c70f`; see [E-005](E-005-portal-invitation.md) and [E-006](E-006-portal-password-reset.md).
- E-007's existing four screenshots were re-rendered from `SupplierInvoiceStatusMail::render()` with the snapshot fixture and current logo variants, then inspected. 24,492-byte HTML; 1440px/390px light/dark browser captures fit. No mail was sent. See [E-007](E-007-supplier-invoice-status.md).
- E-008's four screenshots were re-rendered from `SupplierPurchaseOrderCancelledMail::render()` with the cancellation snapshot and current logo variants, then inspected. 23,141-byte HTML; 1440px/390px light/dark browser captures fit. No mail was sent. See [E-008](E-008-supplier-po-cancellation.md).
- E-009's four screenshots were re-rendered from `CustomerComplaintUpdateMail::render()` with a safe customer-facing snapshot and configured test SPA URL. 24,202-byte HTML; 1440px/390px light/dark captures fit. No mail was sent. See [E-009](E-009-customer-complaint-update.md).
- E-012 PR #311 CI run [39565852712](https://github.com/kwat0g/kwatog/actions/runs/39565852712) failed at PHPStan before PHPUnit on the inherited E-004 undefined `$creditNote` enqueue-failure log. The correction exists in E-004 PR #222, but is not in E-011's current base branch; publish a small functional fix in the shared parent chain before expecting downstream CI to pass.
- E-010 review found subtotal and VAT were missing from the sales-order email although included in the order total. Local PR #232 changes now snapshot subtotal/VAT and show them; rendered HTML is 29,277 bytes. Four 1440px/390px light/dark screenshots inspected; PHP 120.00 + PHP 14.40 = PHP 134.40, mobile has no horizontal overflow. Focused tests updated; CI pending. No mail sent.
- E-011's existing four sales-order-decision screenshots were inspected at desktop/mobile and light/dark. Recipient fallback and event snapshot tests are documented in PR #234; the prior CI failure had the same E-004 PHPStan blocker. No mail sent.
- E-012 applicant confirmation redesigned in draft PR [#311](https://github.com/kwat0g/kwatog/pull/311), stacked on E-011. Focused test passed (1 test, 6 assertions) with `Mail::fake()`; four light/dark desktop/mobile previews inspected. Candidate email still needs HR and data-protection review. No mail sent.
- E-013 applicant status redesign is local commit `628cb1d3`; focused transition/rejection tests passed (2 tests, 11 assertions) with `Mail::fake()`. Raw enums were fixed in separate local commit `6b122579` (EF-025). Four light/dark desktop/mobile previews inspected. No mail sent.
- E-014/E-015 interview notices redesigned in local commit `dfdcb121`, stacked on snapshot fix `fc4e76d4` (EF-026). Four light/dark desktop/mobile previews per type inspected. Focused queue, snapshot, and content tests passed (2 tests, 14 assertions) with `Mail::fake()`. No mail sent.
- E-016 payslip attachment replaced with an authenticated SPA link in local commit `3d859a3d` (EF-028); UI redesign/screenshots are in local commit `c2fbafe6`. Focused job tests passed (3 tests, 13 assertions); finalization listener tests passed (4 tests, 8 assertions). Browser inspected at desktop/mobile light/dark; no email sent. HR/data-protection review remains required.
- PR #312 fixes the inherited credit-note PHPStan error (EF-027). Run 39568212202 passed PHPStan but failed `RunArDunningCommandTest::test_sends_tier_email_for_invoice_8_days_overdue` because no mailable was recorded by `MailFake`. Commit `17e0d6c3` now asserts the service `sent`/`failed` result and rendered content; the adjacent snapshot test still asserts queued recipient and event-time values. New CI run 39571951294 is active in PHPStan.
- Shared foundation local commit `6967b23a` changes a missing `FRONTEND_URL` fallback from `APP_URL` to `http://localhost` (EF-029), so email links cannot silently target the API host.
- E-017 through E-034 already have local or open draft PR work; E-017–E-034 are not untouched. Their checks, base-chain health, per-type tests, and screenshot evidence still need a consolidated audit. E-035 is test-only harness coverage, documented in PR #280.
- Sampled remote failures show shared-base issues: E-017 PR #239 and E-023 PR #255 fail PHPStan on EF-027; E-034 PR #278 fails an E-008 test pinned to `https://ogamiph.dev` while CI uses `app.frontend_url`; E-001 PR #216 fails when an array-transport notification has no From header. PR #312 addresses EF-027; PR #275 contains the configured-URL assertion and reserved PHPUnit sender fixes. Those fixes still need to reach affected PR bases and checks must be rerun.
- PR #275's current remote run (head `67be3750`) also failed because E-009's complaint-link test hardcoded `https://ogamiph.dev`. Local branch head `08194e93` now asserts `app.frontend_url` and records EF-064; it is not pushed or tested. Publish after PR #312's queued suite finishes.
- PR #273's remote run failed on the same missing array-transport From header seen in PR #216. PR #275 carries the shared `.test` sender fix but is not yet in PR #273's base. Local account-security branch commit `d70d6e2d` also changes Admin/HR toggles to “Send account setup email,” matching the new secure-link body; not pushed or checked.
- PR #248's light/dark company-logo favicon update now uses the current theme; commit `e787c5c1` updates vulnerable frontend dependencies and is pushed. `npm audit --audit-level=high` reports zero vulnerabilities. CI run 39573300734 failed on five existing return-management date-input lint errors; current `origin/main` already uses `manilaIsoDate()` at those sites. Rerun PR checks after the active API suite ends.
- Visual review and screenshots for every email are not complete. Real email-client rendering remains unverified.
- No email was sent during this work.
- Mail safety rechecked 2026-10-21: root and API `.env` both use `MAIL_MAILER=log`; `MAIL_HOST`, `MAIL_USERNAME`, and `MAIL_PASSWORD` are empty. PHPUnit forces the `array` mailer. No real SMTP credentials were used.

## Blockers

- Do not publish another test-triggering commit while any API test suite is running; recheck GitHub Actions before publishing.
- PR #213's current remote checks are from its older head and failed: API tests hit the non-production guard without a test inbox, and SPA dependency audit found advisories. The local branch contains subsequent fixes; rerun checks after publishing once the active suite finishes.
- Owner reported DKIM setup succeeded for `ogamiph.dev` in AWS Asia Pacific (Singapore). Verify SPF, DMARC, bounce handling, and provider suppression with domain administrators. Verify company sales contact and VAT settings before real external delivery. Keep statutory, payroll, HR, and personal-data messages flagged for accountant/HR/legal/data-protection review.

## Findings

- See [FINDINGS.md](FINDINGS.md). Keep functional and security defects separate from template redesigns.
- Inventory count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.
- Focused foundation tests previously passed (13 tests, 31 assertions) with no mail transport contacted. This does not prove the current branch's full suite passes.

## Next steps

1. Wait for PR #312 run 39571951294; do not start another test suite or publish another CI-triggering commit while it runs. PR #311 run 39565852712 failed before PHPUnit on EF-027; PR #312 replaces the undefined `$creditNote` reference with the captured snapshot hash. After it completes, rerun PR #248 against current `main` and publish PR #275's prepared fixes.
2. After the active run ends, publish shared baseline fixes and align affected PR bases: E-011/E-012 with #312, and downstream PRs with the E-008/E-009 URL-test and array-sender fixes in #275. Rerun affected checks.
3. Publish local E-013, E-014/E-015, and E-016 functional/design branches as independent draft PRs once their parent checks are current.
4. Audit existing E-017–E-034 PRs and the E-035 harness against inventory, including current CI, dependencies, screenshots, and focused test evidence. Refresh stale findings and coverage docs.
5. Finish the master coverage report and visual review for every remaining email. The company/deliverability checklist is in [02-foundation.md](02-foundation.md); company values, SPF/DMARC, bounce handling, and Reply-To still need external verification. Real email-client rendering remains unverified.
