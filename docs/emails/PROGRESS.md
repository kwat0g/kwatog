# Email audit progress

Updated: 2026-10-23

## Status

- E-018/E-019 previews: PR #320 merged as `06540f88`, adding 28 desktop/mobile, light/dark browser captures and regression coverage for supplier PO line text and peso totals. Main's warmer brand palette remains authoritative. Real email-client behavior remains unverified.
- Phase 0 discovery and Phase 1 inventory are documented in [00-discovery.md](00-discovery.md) and [01-inventory.md](01-inventory.md). Owner directed work to proceed.
- Shared framework and non-production mail guard merged in PR [#213](https://github.com/kwat0g/kwatog/pull/213), main commit `dc4f335b`.
- Queued delivery, after-commit dispatch, retry logging, and mail queue worker merged from PR [#214](https://github.com/kwat0g/kwatog/pull/214), main commit `a74476ef`.
- Per-email redesigns remain incomplete. Inventory lists 34 production Mailable/Notification implementations plus one test-only implementation; each remaining type still needs current verification.
- E-001–E-003 customer invoice, receipt, and dunning notices now include record references, balance/due details, and portal actions; four light/dark desktop/mobile previews per type are included in the rollup. Real email-client rendering remains unverified.
- Finalized invoice delivery now uses issue-time recipient and display-value snapshots so queued mail cannot switch to a changed address or invoice values.
- Official receipt delivery now snapshots the recipient, customer name, amount, receipt date, and linked invoice at issue time.
- E-002 official-receipt template and light/dark desktop/mobile previews are integrated; accountant review remains required before external production delivery.
- E-027/E-028 production-summary templates and previews are included. Empty recipient sets now raise an in-app admin notice instead of silently skipping the scheduled summary.
- E-029/E-031–E-033 account messages use expiring one-time setup/reset links; no password is included in the email. Existing serialized legacy jobs generate a fresh link at send time. HR/data-protection review remains required.
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
- E-016 secure-link fix, UI redesign, tests, and four desktop/mobile light/dark screenshots are included in draft PR #238; no separate template PR is needed. Focused job and finalization-listener tests passed earlier (3/13 and 4/8). Current CI fails before PHPUnit on inherited EF-027 PHPStan and EF-058 SPA dependency audit. HR/data-protection review remains required; no email sent.
- PR #312 fixes the inherited credit-note PHPStan error (EF-027). Earlier runs exposed a dunning MailFake assertion and a title assertion belonging to E-003; commit `e057a3e4` removes that duplicate assertion. Run [39575548527](https://github.com/kwat0g/kwatog/actions/runs/39575548527) passed PHPStan but failed `SalesOrderResponsePortalTest::test_portal_accept_replay_is_idempotent_and_crm_can_acknowledge_it`: array transport lacked a From header. Main now configures a safe `.test` sender for PHPUnit; refresh downstream PR bases before treating their historical failure as current.
- Shared foundation local commit `6967b23a` changes a missing `FRONTEND_URL` fallback from `APP_URL` to `http://localhost` (EF-029), so email links cannot silently target the API host.
- E-017 through E-034 already have local or open draft PR work; E-017–E-034 are not untouched. Their checks, base-chain health, per-type tests, and screenshot evidence still need a consolidated audit. E-035 is test-only harness coverage, documented in PR #280.
- E-020 and E-021's 16 desktop/mobile light/dark screenshots were re-rendered from current branch HTML and visually inspected. Refreshed captures show visible CTA labels in dark mode; updated local commits `a60de199` (#250) and `5829b4a6` (#252). No email sent.
- E-022 delivery confirmation's 4 current desktop/mobile light/dark captures visually reviewed. Reference, timestamp, CTA, and text remain readable; mobile detail labels wrap without horizontal overflow. No template change needed.
- E-024 unread digest's 4 desktop/mobile light/dark screenshots visually reviewed. Count-only copy, CTA, and layout remain readable; sample branding intentionally uses fixture labels. No template change needed.
- E-025 scheduled export's 4 desktop/mobile light/dark screenshots visually reviewed. Attached filename wraps on mobile but remains legible; no overflow. Employee-directory attachment and other personal-data exports remain flagged for HR/data-protection review before real delivery.
- E-026 critical-alert PR #276 CI run [39533094221](https://github.com/kwat0g/kwatog/actions/runs/39533094221) failed in inherited `SupplierPurchaseOrderCancelledNotificationTest`: its expected URL still used `https://ogamiph.dev`, while the app uses `app.frontend_url`. PR #275 has that test correction; the PR base chain needs alignment. Current E-026 desktop/mobile light/dark screenshots were visually inspected; no overflow and correct light/dark landing-page logo variants. Full-suite verification remains pending.
- EF-115: critical alert title/message could inject Markdown links from free text. A separate fix branch `fix/critical-alert-email-markdown-safety` strips markup/control characters and URLs, escapes Markdown, and adds hostile-input tests. PHP syntax and `git diff --check` pass; PHPUnit waits for run 39575548527 to finish.
- E-027/E-028 production summaries' 8 desktop/mobile light/dark screenshots visually reviewed. Summary rows and CTA remain readable at 390px; no horizontal overflow. Footer values are deliberately redacted test fixtures.
- E-029–E-033 account-security visuals reviewed for all 4 active variants (16 desktop/mobile light/dark captures). Reset/setup copy, expiry, CTA, logos, and footer remain legible; legacy E-030 uses the same shared template. Company identity values are test placeholders. HR/legal/data-protection review remains required.
- E-034 public contact inquiry alert's 4 desktop/mobile light/dark captures visually reviewed. Privacy-minimized copy, reference, timestamp, and CRM CTA remain legible; no overflow. Sales inbox and personal-data handling still need owner/data-protection review.
- Historical branch failures exposed shared-base issues: EF-027 credit-note PHPStan, EF-058 SPA dependency audit, hardcoded `https://ogamiph.dev` assertions, and array mail transport without a From header. The current main rollup includes the applicable main fixes; downstream stacked PRs still need rebasing before their old checks can be treated as current.
- E-023 caller inventory contains 82 generic notification calls (84 raw `->send()` calls, including two non-email false positives). Rechecked its 82 source anchors against the `audit/e023-callers` worktree and corrected 11 shifted line numbers in commit `761bfb0d`; caller-level audience, route, payload, and snapshot verification remains partial.
- Historical #275 count, URL, and test-sender fixes are superseded by current main's seeder-derived counts, configured portal URL assertions, and `.test` mail sender. Its remaining stale-base test changes were not copied where their target tests do not exist on main.
- E-029–E-033 secure-link changes are included in the current rollup; old temporary-password content is not rendered. The queued-job compatibility path creates a fresh reset token. HR/data-protection review remains outstanding.
- PRs #248 and #316 are merged. The favicon uses the current theme, and the five RMA date inputs use Manila dates; SPA checks passed on main before this rollup.
- EF-002/065 foundation fixes remove the hardcoded email display-name fallback and company-name literals in email copy, use the configured legal name/sender name, and update regression coverage; `git diff --check` passes. PHPUnit verification is pending until the active suite ends.
- Source scan after EF-065 found no hardcoded company-name literals in email views or mail classes. Remaining company-name literals are in a BIR 2307 PDF fallback and a portal-reset UI message, outside email templates; review them separately before treating all company-branded outputs as verified.
- Visual review confirmed the dark header-only logo captures show the white variant; older full-shell dark captures showed the pre-variant clipped fallback. They are now labeled as before evidence. E-026 current-shell desktop/mobile captures were opened and inspected in light/dark: no overflow, readable CTA/body, and correct white logo variant in dark mode. Browser rendering only; real mail-client rendering remains unverified.
- Visual review and screenshots for every email are not complete. Real email-client rendering remains unverified.
- No email was sent during this work.
- Mail safety rechecked 2026-10-21: root and API `.env` use `MAIL_MAILER=log`; AWS SMTP settings remain in those files but are inactive. Horizon was stopped and restarted after the change; runtime `config('mail.default')` returned `log`. Shared-foundation guard redirects non-production mail to `MAIL_TEST_TO` or fails closed. PHPUnit uses the `array` mailer. No email was sent.
- PR #315 run [39613744141](https://github.com/kwat0g/kwatog/actions/runs/39613744141) was canceled after 47 minutes at PHPUnit to stop prolonged waiting; PHP syntax and PHPStan had passed. Full-suite result is unavailable. Local Manila-date fix `beee680e` passes targeted ESLint; supplier-shipment finding fix `ac1df72b` and demo test-count fix `ca367877` are separate branches, untested.

## Blockers

- CI for the current main rollup is pending. Historical PR #315 full-suite result remains unavailable because its run was canceled.
- Older #213 checks predate the merged main fixes and should not be used as the current foundation result.
- Owner reported DKIM setup succeeded for `ogamiph.dev` in AWS Asia Pacific (Singapore). Verify SPF, DMARC, bounce handling, and provider suppression with domain administrators. Verify company sales contact and VAT settings before real external delivery. Keep statutory, payroll, HR, and personal-data messages flagged for accountant/HR/legal/data-protection review.

## Findings

- See [FINDINGS.md](FINDINGS.md). Keep functional and security defects separate from template redesigns.
- Inventory count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.
- Focused foundation tests previously passed (13 tests, 31 assertions) with no mail transport contacted. This does not prove the current branch's full suite passes.

## Next steps

1. Reconcile remaining email PRs against the inventory; keep only changes not already present on main.
2. Finish per-type data/recipient verification and screenshots; record deliberate deferrals.
3. Verify company values, SPF/DMARC, bounce handling, and Reply-To with the relevant company contacts. Real email-client rendering remains unverified.
