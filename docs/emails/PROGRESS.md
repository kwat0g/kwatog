# Email audit progress

Updated: 2026-10-21

## Status

- Phase 0 discovery and Phase 1 inventory are documented in [00-discovery.md](00-discovery.md) and [01-inventory.md](01-inventory.md). Owner directed work to proceed.
- Shared framework and non-production mail guard are implemented on `email/shared-foundation`; draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) is open. Local branch is at `44719ed5`; GitHub PR still points to `0d1917b9`, so local commits are not yet published.
- Reliability foundation draft PR [#214](https://github.com/kwat0g/kwatog/pull/214) depends on #213.
- Per-email redesign and defect-fix work exists in draft PRs. GitHub currently lists 92 open PRs matching the email work branches or titles; each still needs a current check result and independent review.
- E-001's four browser screenshots were refreshed in the local #216 worktree at `6913c986`; prior captures still showed the retired O mark. Current company logo variants now appear in light and dark previews. See [E-001 review](E-001-invoice-finalized.md).
- E-002's four browser screenshots were refreshed in the local #218 worktree with the current landing-page logo variants; see [E-002 review](E-002-official-receipt.md).
- E-003's four browser screenshots were refreshed in the local #220 worktree with the current landing-page logo variants; see [E-003 review](E-003-invoice-dunning.md).
- E-004's eight customer/supplier browser screenshots were refreshed in the local #222 worktree with the current landing-page logo variants; see [E-004 review](E-004-credit-note.md).
- E-005 and E-006 browser previews were refreshed from the actual branch mailables with dummy tokens; no mail was sent. Four captures per type cover 1440px/390px and light/dark, with no viewport overflow. Local PR #224 commit `75f1c70f`; see [E-005](E-005-portal-invitation.md) and [E-006](E-006-portal-password-reset.md).
- Full PHPUnit run [39556310646](https://github.com/kwat0g/kwatog/actions/runs/39556310646) is active on `fix/phpunit-mail-from`. Wait for it to finish before starting another suite or publishing commits that trigger CI.
- Visual review and screenshots for every email are not complete. Real email-client rendering remains unverified.
- No email was sent during this work.

## Blockers

- Do not publish a second test-triggering commit while PHPUnit run 39556310646 is active.
- PR #213's current remote checks are from its older head and failed: API tests hit the non-production guard without a test inbox, and SPA dependency audit found advisories. The local branch contains subsequent fixes; rerun checks after publishing once the active suite finishes.
- Owner reported DKIM setup succeeded for `ogamiph.dev` in AWS Asia Pacific (Singapore). Verify SPF, DMARC, bounce handling, and provider suppression with domain administrators. Verify company sales contact and VAT settings before real external delivery. Keep statutory, payroll, HR, and personal-data messages flagged for accountant/HR/legal/data-protection review.

## Findings

- See [FINDINGS.md](FINDINGS.md). Keep functional and security defects separate from template redesigns.
- Inventory count: 34 production Mailable/Notification implementations plus one test-only implementation. Generic `UserNotificationMail` represents multiple runtime trigger types.
- Focused foundation tests previously passed (13 tests, 31 assertions) with no mail transport contacted. This does not prove the current branch's full suite passes.

## Next steps

1. Wait for run 39556310646; inspect full test result.
2. Publish the reviewed local foundation commits and rerun PR #213 checks.
3. Validate reliability PR #214, then process the per-email PR chain in external/high-volume order.
4. Build/render every email in desktop/mobile and light/dark views; capture before/after screenshots in `docs/emails/screenshots/`.
5. Complete the coverage report, missing-email recommendations, settings/deliverability checklist, and manual client checklist.
