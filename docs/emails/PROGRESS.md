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
- PR #312 isolates the inherited credit-note PHPStan error (EF-027) into a functional fix; targeted PHPStan passed, full CI run 39568212202 is running. E-012 run 39565852712 failed at PHPStan before PHPUnit for this same inherited error.
- Visual review and screenshots for every email are not complete. Real email-client rendering remains unverified.
- No email was sent during this work.

## Blockers

- Do not publish another test-triggering commit while any API test suite is running; recheck GitHub Actions before publishing.
- PR #213's current remote checks are from its older head and failed: API tests hit the non-production guard without a test inbox, and SPA dependency audit found advisories. The local branch contains subsequent fixes; rerun checks after publishing once the active suite finishes.
- Owner reported DKIM setup succeeded for `ogamiph.dev` in AWS Asia Pacific (Singapore). Verify SPF, DMARC, bounce handling, and provider suppression with domain administrators. Verify company sales contact and VAT settings before real external delivery. Keep statutory, payroll, HR, and personal-data messages flagged for accountant/HR/legal/data-protection review.

## Findings

- See [FINDINGS.md](FINDINGS.md). Keep functional and security defects separate from template redesigns.
- Inventory count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.
- Focused foundation tests previously passed (13 tests, 31 assertions) with no mail transport contacted. This does not prove the current branch's full suite passes.

## Next steps

1. Wait for PR #312 run 39568212202; if green, add its fix to E-011's base chain and rerun E-012 CI.
2. Publish E-013, E-014/E-015, and E-016 functional/design PRs sequentially.
3. Continue E-017–E-034 in inventory order, then finish coverage and company/deliverability checklists.
4. Complete desktop/mobile light/dark review for remaining emails. Real email-client rendering remains unverified.
