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
- Dunning reminders now capture customer and invoice display data when the reminder is created.
- E-003 dunning template and light/dark desktop/mobile previews are integrated; accountant review and the documented reminder-volume decision remain open.
- Credit-note notices use finalization-time party, amount, and linked-document values; external free-text reason is omitted.
- E-004 customer/supplier templates and eight light/dark desktop/mobile previews are integrated; accountant review and credit-note document/portal decisions remain open.
- E-027/E-028 production-summary templates and previews are included. Empty recipient sets now raise an in-app admin notice instead of silently skipping the scheduled summary.
- E-029/E-031–E-033 account messages use expiring one-time setup/reset links; no password is included in the email. Existing serialized legacy jobs generate a fresh link at send time. HR/data-protection review remains required.
- E-005/E-006 portal invitation/reset templates use configured branding, one-time links, and sanitized names; eight light/dark desktop/mobile previews are integrated. Final API suite is pending.
- Supplier invoice confirmations now snapshot the recipient and supplier-visible submission data before queuing.
- E-007 supplier invoice confirmation is redesigned with branded subject, receipt-only notice, verified portal detail route, and four light/dark desktop/mobile previews; final API suite pending.
- Supplier PO cancellation notices now use event snapshots and retain the sent-to-supplier eligibility rule; internal cancellation remarks remain excluded.
- E-008 PO cancellation template and four light/dark desktop/mobile previews are integrated; supplier-only delivery gate remains intact.
- E-009 complaint notices now omit severity, description, affected quantity, and internal 8D text; event-time recipient/status snapshot and customer portal link are retained.
- E-009 status card and four light/dark desktop/mobile previews are integrated; complaint detail remains in the authenticated portal.
- E-001's four browser screenshots were refreshed in the local #216 worktree at `6913c986`; prior captures still showed the retired O mark. Current company logo variants now appear in light and dark previews. See [E-001 review](E-001-invoice-finalized.md).
- Re-inspected E-001's 1440px/390px light/dark screenshots: all layouts fit and the company logo is visible; invoice totals and line-item prices show `PHP` instead of the SPA's `₱`. EF-120/PR #319 fixes the shared formatter. Preview branding values are test data; no real mail was sent.
- E-002's four browser screenshots were refreshed in the local #218 worktree with the current landing-page logo variants; see [E-002 review](E-002-official-receipt.md).
- Re-inspected E-002's desktop/mobile light/dark captures. Layout and branding remain readable; found its amount uses `PHP 125,000.00` while the SPA shows `₱125,000.00`. EF-120 is fixed in draft PR [#319](https://github.com/kwat0g/kwatog/pull/319) with a focused formatter test (2 tests, 3 assertions), Pint, and Composer audit passing locally. Browser captures are from a test fixture, not company identity values.
- E-003's four browser screenshots were refreshed in the local #220 worktree with the current landing-page logo variants; see [E-003 review](E-003-invoice-dunning.md).
- Re-inspected E-003's desktop/mobile light/dark screenshots: all fit with readable copy; amount still shows `PHP 125,000.00` until EF-120 / PR #319 updates the shared formatter. No real email sent.
- E-004's eight customer/supplier browser screenshots were refreshed in the local #222 worktree with the current landing-page logo variants; see [E-004 review](E-004-credit-note.md).
- Re-inspected all eight E-004 customer/supplier desktop/mobile light/dark screenshots: no clipping; amounts use `PHP`, tracked by EF-120 / PR #319. No real email sent.
- E-005 and E-006 browser previews were refreshed from the actual branch mailables with dummy tokens; no mail was sent. Four captures per type cover 1440px/390px and light/dark, with no viewport overflow. Local PR #224 commit `75f1c70f`; see [E-005](E-005-portal-invitation.md) and [E-006](E-006-portal-password-reset.md).
- Re-inspected both portal security types' eight light/dark desktop/mobile captures: no clipping; one-time setup/reset instructions, 60-minute expiry, and security copy stay legible. No email sent.
- EF-119 found stale supplier/customer portal invite copy that still promised temporary-password email after the secure setup-link change. Commit `dd7a26d0` updates both forms and success messages in PR #223; targeted ESLint and `git diff --check` pass. GitHub PR head now matches `dd7a26d0`; no checks are attached to the new head yet.
- E-007's existing four screenshots were re-rendered from `SupplierInvoiceStatusMail::render()` with the snapshot fixture and current logo variants, then inspected. 24,492-byte HTML; 1440px/390px light/dark browser captures fit. No mail was sent. See [E-007](E-007-supplier-invoice-status.md).
- Re-inspected E-007 captures: hostile supplier name remains escaped text, no mobile overflow, and the PHP total uses `PHP` until EF-120 / PR #319 merges. No email sent.
- E-008's four screenshots were re-rendered from `SupplierPurchaseOrderCancelledMail::render()` with the cancellation snapshot and current logo variants, then inspected. 23,141-byte HTML; 1440px/390px light/dark browser captures fit. No mail was sent. See [E-008](E-008-supplier-po-cancellation.md).
- Re-inspected E-008 captures: hostile supplier text stays escaped and no overflow appears; original total uses `PHP`, covered by EF-120 / PR #319. No mail sent.
- E-009's four screenshots were re-rendered from `CustomerComplaintUpdateMail::render()` with a safe customer-facing snapshot and configured test SPA URL. 24,202-byte HTML; 1440px/390px light/dark captures fit. No mail was sent. See [E-009](E-009-customer-complaint-update.md).
- Re-inspected E-009 captures: hostile greeting renders as escaped text; status, reference, and CTA remain readable without overflow. No real email sent.
- E-012 PR #311 CI run [39565852712](https://github.com/kwat0g/kwatog/actions/runs/39565852712) failed at PHPStan before PHPUnit on the inherited E-004 undefined `$creditNote` enqueue-failure log. The correction exists in E-004 PR #222, but is not in E-011's current base branch; publish a small functional fix in the shared parent chain before expecting downstream CI to pass.
- E-010 review found subtotal and VAT were missing from the sales-order email although included in the order total. Local PR #232 changes now snapshot subtotal/VAT and show them; rendered HTML is 29,277 bytes. Four 1440px/390px light/dark screenshots inspected; PHP 120.00 + PHP 14.40 = PHP 134.40, mobile has no horizontal overflow. Focused tests updated; CI pending. No mail sent.
- Re-inspected E-010: subtotal/VAT arithmetic remains consistent, no mobile overflow, and table figures are legible. PHP prefixes are addressed by EF-120 / PR #319. No email sent.
- E-011's existing four sales-order-decision screenshots were inspected at desktop/mobile and light/dark. Recipient fallback and event snapshot tests are documented in PR #234; the prior CI failure had the same E-004 PHPStan blocker. No mail sent.
- Re-inspected E-011 captures: decision, record reference, and total remain legible with no overflow; currency prefix awaits EF-120 / PR #319. No real email sent.
- E-012 applicant confirmation redesigned in draft PR [#311](https://github.com/kwat0g/kwatog/pull/311), stacked on E-011. Focused test passed (1 test, 6 assertions) with `Mail::fake()`; four light/dark desktop/mobile previews inspected. Candidate email still needs HR and data-protection review. No mail sent.
- E-012 dark desktop CTA label confirmed present in a cropped screenshot; full-page image scaling hid it at preview size. Footer data is seeded and unverified.
- Reopened E-014 dark desktop and E-015 light mobile captures: CTA and schedule fields are readable, Manila timezone is explicit, and mobile text wraps without clipping. These are browser captures; candidate-data review still applies.
- Reopened all E-016 payslip captures: no salary details are exposed; a cropped dark desktop screenshot confirms CTA label. Link route exists in the SPA. HR/data-protection review and company footer verification remain open.
- Reopened E-017 desktop-dark and mobile-light previews: CTA is visible and mobile details remain readable; `PHP` still awaits EF-120. Footer shows `.test` contact and certification preview values, requiring settings verification before external sends.
- E-018 screenshots exposed unstyled Markdown tables: current shared CSS targeted only `.table` wrappers, but Laravel Markdown emits direct tables. Shared layout fix is draft PR [#320](https://github.com/kwat0g/kwatog/pull/320); regression assertion passed focused PHPUnit (1 test, 2 assertions). Re-rendered actual test mailable at 1440px/390px, light/dark; table now has clear rows and header, CTA label is present, and document width matches viewport. Real mail clients remain unverified.
- Scan found raw Markdown tables in 17 email views, including E-019 RFQ. PR #320's shared selectors cover them without per-template changes; their screenshots need refresh after the foundation lands.
- Reopened E-019's published/invited desktop light capture: detail-table header fill and row separators are absent. Recorded the required 20-capture refresh in PR #247's review doc.
- PR #320 now includes all 20 E-019 Chromium captures rendered with its CSS selectors (commit `521521da`); `SupplierRfqLifecycleMailTest` passed (1 test, 73 assertions), all 20 document widths matched viewports, and representative desktop/mobile light/dark states were inspected. The PR #320 CI rerun is still in progress.
- E-013 applicant status redesign is local commit `628cb1d3`; focused transition/rejection tests passed (2 tests, 11 assertions) with `Mail::fake()`. Raw enums were fixed in separate local commit `6b122579` (EF-025). Four light/dark desktop/mobile previews inspected. No mail sent.
- E-014/E-015 interview notices redesigned in local commit `dfdcb121`, stacked on snapshot fix `fc4e76d4` (EF-026). Four light/dark desktop/mobile previews per type inspected. Focused queue, snapshot, and content tests passed (2 tests, 14 assertions) with `Mail::fake()`. No mail sent.
- E-016 secure-link fix, UI redesign, tests, and four desktop/mobile light/dark screenshots are included in draft PR #238; no separate template PR is needed. Focused job and finalization-listener tests passed earlier (3/13 and 4/8). Current CI fails before PHPUnit on inherited EF-027 PHPStan and EF-058 SPA dependency audit. HR/data-protection review remains required; no email sent.
- PR #312 fixes the inherited credit-note PHPStan error (EF-027). Earlier runs exposed a dunning MailFake assertion and a title assertion belonging to E-003; commit `e057a3e4` removes that duplicate assertion. Run [39575548527](https://github.com/kwat0g/kwatog/actions/runs/39575548527) passed PHPStan but failed `SalesOrderResponsePortalTest::test_portal_accept_replay_is_idempotent_and_crm_can_acknowledge_it`: array transport lacked a From header. Main now configures a safe `.test` sender for PHPUnit; refresh downstream PR bases before treating their historical failure as current.
- Shared foundation local commit `6967b23a` changes a missing `FRONTEND_URL` fallback from `APP_URL` to `http://localhost` (EF-029), so email links cannot silently target the API host.
- Draft PR [#317](https://github.com/kwat0g/kwatog/pull/317) applies the EF-029 fallback fix as a one-line config change. Its first CI run stopped at Composer audit because main has vulnerable `symfony/http-foundation` 7.4.14; PR #317 now depends on #318. Re-run after the dependency fix reaches its base. No local PHP CLI; no email sent.
- Draft PR [#318](https://github.com/kwat0g/kwatog/pull/318) upgrades `symfony/http-foundation` to 7.4.21 for PKSA-xmwy-rmx5-m7h4 / CVE-2026-108082, discovered from PR #317 CI. `composer audit --locked --no-interaction` and `git diff --check` pass. CI run 39624448863 reached PHPStan and failed on EF-121/EF-122, unrelated baseline code defects; findings are recorded for separate fixes.
- EF-121 fix is draft PR [#321](https://github.com/kwat0g/kwatog/pull/321): replaced full model hydration with query-level `pluck`; `CapacityPlanningServiceTest` passed (22 tests, 64 assertions) using the `array` mailer. CI is blocked at Composer audit by EF-118; PHPUnit did not run. EF-122's model import is present on current `origin/main`; older PHPStan run still needs a rerun on the updated base.
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
- PR #315 run [39613744141](https://github.com/kwat0g/kwatog/actions/runs/39613744141) was canceled after 47 minutes at PHPUnit to stop prolonged waiting; PHP syntax and PHPStan had passed. Full-suite result is unavailable. Local Manila-date fix `beee680e` passes targeted ESLint; demo test-count fix `ca367877` remains a separate branch, untested.
- PR #310's CI failed an unrelated legacy supplier-shipment reconciliation test (expected 422, got 200). EF-123 is fixed in separate draft PR [#322](https://github.com/kwat0g/kwatog/pull/322); its targeted test passed (1 test, 7 assertions). Re-run #310 after the fix reaches its base.
- PR #322's own CI is currently blocked at Composer audit by EF-118; PHPUnit was skipped. The focused test passed locally.

## Email UI work — 2026-10-21

- E-001 invoice issued, E-002 official receipt, and E-003 invoice dunning now use the shared email shell's preheader/title, clearer key-fact tables, and consistent peso display. Invoice line items use a stacked, mobile-safe layout. No trigger, recipient, or record source changed.
- Rendered the three mailables with synthetic fixture records in a network-isolated PHP container, with `MAIL_MAILER=array`; no email was sent. Playwright screenshots cover 1440px desktop and 390px mobile in light/dark (`e001-accounting-*`, `e002-accounting-*`, `e003-accounting-*`). Inspected all 12 captures; viewport and document widths match. The first invoice line-item table wrapped badly on mobile, so it now uses a stacked list. E-003 links to the existing customer invoice detail route; the portal layout redirects unauthenticated users to sign-in while preserving that path. Added a focused link assertion; no local PHPUnit rerun. PR #325's CI check was already running on an earlier commit. Real email clients remain unverified.
- Reviewed existing desktop/light and mobile/dark captures for E-011–E-017 and E-020–E-022. No visible clipping or theme defects found in those captures. E-017's summary table wraps on mobile but remains readable. E-010's compressed item table was changed to stacked details in PR #232 commit `0006d775`; E-018 received the same treatment in PR #243 commit `a18dde45`.
- Refreshed E-026–E-028 from current notification renders: 12 desktop/mobile light/dark captures. Inspected critical alert, long daily summary, and weekly summary; logo, CTA, and footer are present, and document widths match 1440px/390px viewports. Commits pushed: E-026 `79842524`/`442df4b1`, E-027/E-028 `c0a44d9f`. No message sent; real clients unverified.
- CI audit found E-025's inherited undefined `$creditNote` log reference. Separate draft fix PR [#326](https://github.com/kwat0g/kwatog/pull/326) uses the event snapshot hash; E-025 PR #267 now targets that fix branch. PHP syntax and diff checks passed; hosted CI is pending.
- Inspected all 20 E-029 and E-031–E-034 screenshots across desktop/mobile and light/dark. Security emails and contact-inquiry notice retain clear hierarchy and fit; CTAs and logo are present. E-030's temporary-password flow was removed, so no preview retained. Captures use synthetic branding; real clients remain unverified.
- Re-rendered E-010 from the updated Mailable with synthetic order/branding fixtures in a network-isolated container and `MAIL_MAILER=array`: 27,352-byte HTML. Re-rendered E-018 the same way: 34,357-byte HTML. Refreshed and inspected each email's desktop/mobile light/dark screenshots; browser document width matched viewport and CTA labels were visible in focused captures. No email sent. Footer values are preview data; real clients remain unverified.
- PR #232's API CI failed at PHPStan on undefined `$creditNote` in `EmailPartyOnCreditNoteFinalized.php:55`; PHPUnit was skipped. EF-027 is tracked in [FINDINGS.md](FINDINGS.md), and the separate fix is in draft PR #312. This finding predates the E-010 UI change.

## Current hosted checks — 2026-10-21

- PR #213 head `f9aa0220` adds isolated PHPUnit sender/sink values. PHPStan passed; PHPUnit remains in progress. Accessibility, visual-baseline, and finding-lifecycle gates passed. The web dependency audit still reports high-severity `vite` and `follow-redirects` advisories. The passing lockfile fix is [PR #271](https://github.com/kwat0g/kwatog/pull/271), on a separate 98-commit branch, so it is not present in #213's check merge.
- Three shared API failures in `AssetAssociationTest` came from a midnight-sensitive attendance fixture (`now() - 1 hour` before a midnight shift). Test-only correction is in [PR #328](https://github.com/kwat0g/kwatog/pull/328); its PHPStan and PHPUnit checks are running.
- PR #325's earlier API run failed because its stale base lacked `MAIL_TEST_TO`; the test sender config is now in PR #213. Rebase stacked branches after shared checks settle. No real email was sent.
- Fresh sender/class scan confirms the inventory count: 34 production message implementations plus one test-only Mailable. The two HR account messages subclass the auth welcome/reset notifications, so a direct `extends Mailable|Notification` scan alone misses them; they are covered as E-032/E-033.

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

| E010 | Customer sales order confirmation | Snapshot captured at confirmation; SPA order link; email design integrated | Visual/client review remains limited to recorded screenshots and code inspection |

| E010 | Customer sales order confirmation | Snapshot, shared email layout, configured branding, SPA order link, and desktop/mobile light/dark screenshots integrated | Real-client rendering remains unverified |

| E011 | Customer sales order decision | Snapshot-based content and one-time response links integrated | Real-client rendering remains unverified |

| E011 | Customer sales order decision | Snapshot, shared email layout, customer portal link, safe response summary | Real-client rendering remains unverified |

| E012–E015 | Candidate application and interview emails | Event-time snapshots integrated; use configured company branding | Visual inspection evidence and real-client rendering remain unverified |

| E012–E015 | Candidate application and interview emails | Event-time snapshots, shared layout, configured branding, safe tracking links, light/dark desktop/mobile screenshots integrated | Real-client rendering remains unverified |

| E016 | Payslip availability | Secure authenticated SPA link, no payroll amounts or attachment, template and mobile/desktop screenshots integrated | HR/privacy review and real-client rendering remain unverified |

| E017 | Supplier purchase order decision | Shared email layout, decision snapshot, supplier portal link, and desktop/mobile light/dark screenshots integrated | Real-client rendering remains unverified |

| E018 | Supplier purchase order publication | Snapshot and supplier portal link; shared layout; desktop/mobile light/dark screenshots integrated | Real-client rendering remains unverified |

| E019 | Supplier RFQ lifecycle | Event snapshot and recipient-specific status/action summary; supplier portal link; scenario screenshots integrated | Real-client rendering remains unverified |

| E020 | Return request updates | Snapshot, minimal status notice, authenticated customer/supplier portal links, shared layout integrated | Real-client rendering remains unverified |

| E021 | Customer delivery confirmation | Event-time snapshot, shared layout, customer portal link, and desktop/mobile light/dark screenshots integrated | Real-client rendering remains unverified |
