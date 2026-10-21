# Email redesign coverage

Snapshot: 2026-10-21. IDs and classifications follow [01-inventory.md](01-inventory.md).
PR state was checked against GitHub; every linked redesign PR below is open and draft.
This maps implementation work. It does not claim the PR is complete, its CI passes, or
its visual review is committed. Review each PR's current checks and screenshots before
closing the corresponding row.

| ID | Email type | Design / secure-flow PR | Coverage status |
|---|---|---:|---|
| E-001 | Finalized customer invoice | [#216](https://github.com/kwat0g/kwatog/pull/216), [accounting polish #325](https://github.com/kwat0g/kwatog/pull/325) | Desktop/mobile and light/dark captures inspected; refresh after logo update; CI pending |
| E-002 | Customer official receipt | [#218](https://github.com/kwat0g/kwatog/pull/218), [accounting polish #325](https://github.com/kwat0g/kwatog/pull/325) | Desktop/mobile and light/dark captures inspected; refresh after logo update; CI pending |
| E-003 | Invoice dunning | [#220](https://github.com/kwat0g/kwatog/pull/220), [accounting polish #325](https://github.com/kwat0g/kwatog/pull/325) | Desktop/mobile and light/dark captures inspected; refresh after logo update; CI pending |
| E-004 | Finalized credit note | [#222](https://github.com/kwat0g/kwatog/pull/222), [accounting polish #325](https://github.com/kwat0g/kwatog/pull/325) | Eight customer/supplier captures inspected; mobile amount wraps `PHP`; formatter fix tracked in [#319](https://github.com/kwat0g/kwatog/pull/319); refresh logo after foundation update; CI pending |
| E-005 | Supplier/customer portal invitation | [#224](https://github.com/kwat0g/kwatog/pull/224) | Desktop/mobile and light/dark captures inspected; 60-minute setup-link copy readable; refresh logo and verify company settings; CI pending |
| E-006 | Supplier/customer portal password reset | [#224](https://github.com/kwat0g/kwatog/pull/224) | Desktop/mobile and light/dark captures inspected; 60-minute reset-link copy readable; refresh logo and verify company settings; CI pending |
| E-007 | Supplier invoice status | [#226](https://github.com/kwat0g/kwatog/pull/226) | Desktop/mobile and light/dark captures inspected; hostile contact text is escaped; amount-format follow-up #319 and logo refresh pending; CI pending |
| E-008 | Supplier purchase order cancellation | [#228](https://github.com/kwat0g/kwatog/pull/228) | Desktop/mobile and light/dark captures inspected; hostile contact text is escaped; refresh logo after foundation update; CI pending |
| E-009 | Customer complaint update | [#230](https://github.com/kwat0g/kwatog/pull/230) | Desktop/mobile and light/dark captures inspected; hostile contact text is escaped; refresh logo after foundation update; CI pending |
| E-010 | Customer sales order confirmation | [#232](https://github.com/kwat0g/kwatog/pull/232) | Desktop/mobile and light/dark captures inspected; PHP amounts tracked by #319; refresh logo after foundation update; CI pending |
| E-011 | Customer sales order decision | [#234](https://github.com/kwat0g/kwatog/pull/234) | Draft; visual and CI review pending |
| E-012 | Candidate application received | [#236](https://github.com/kwat0g/kwatog/pull/236) | Draft; visual and CI review pending |
| E-013 | Candidate application status | [#236](https://github.com/kwat0g/kwatog/pull/236) | Draft; visual and CI review pending |
| E-014 | Candidate interview scheduled | [#236](https://github.com/kwat0g/kwatog/pull/236) | Draft; visual and CI review pending |
| E-015 | Candidate interview changed | [#236](https://github.com/kwat0g/kwatog/pull/236) | Draft; visual and CI review pending |
| E-016 | Payslip ready notice | [#238](https://github.com/kwat0g/kwatog/pull/238) | Secure-link change drafted; visual and CI review pending |
| E-017 | Supplier purchase order decision | [#239](https://github.com/kwat0g/kwatog/pull/239) | Draft; visual and CI review pending |
| E-018 | Supplier purchase order published | [#243](https://github.com/kwat0g/kwatog/pull/243) | Draft; visual and CI review pending |
| E-019 | Supplier RFQ lifecycle | [#247](https://github.com/kwat0g/kwatog/pull/247) | Draft; inherited PHPStan defect blocks review |
| E-020 | Return case update | [#250](https://github.com/kwat0g/kwatog/pull/250) | Draft; visual and CI review pending |
| E-021 | Return request update | [#252](https://github.com/kwat0g/kwatog/pull/252) | Draft; visual and CI review pending |
| E-022 | Customer delivery confirmation | [#254](https://github.com/kwat0g/kwatog/pull/254) | Draft; visual and CI review pending |
| E-023 | Generic user notification | [#255](https://github.com/kwat0g/kwatog/pull/255) | Draft; recipient and deep-link follow-ups remain separate |
| E-024 | Notification digest | [#265](https://github.com/kwat0g/kwatog/pull/265) | Draft; visual and CI review pending |
| E-025 | Scheduled export notice | [#267](https://github.com/kwat0g/kwatog/pull/267) | Draft; visual and CI review pending |
| E-026 | Critical alert | [#269](https://github.com/kwat0g/kwatog/pull/269) | Draft; safety and time-zone follow-ups remain separate |
| E-027 | Daily production summary | [#272](https://github.com/kwat0g/kwatog/pull/272) | Draft; recipient and responsive-layout follow-ups remain separate |
| E-028 | Weekly production summary | [#272](https://github.com/kwat0g/kwatog/pull/272) | Draft; recipient and responsive-layout follow-ups remain separate |
| E-029 | Internal account welcome | [#273](https://github.com/kwat0g/kwatog/pull/273) | Secure-link change drafted; HR/security review and CI pending |
| E-030 | Legacy temporary-password reset | [#273](https://github.com/kwat0g/kwatog/pull/273) | Legacy password email replaced by reset link; confirm no reachable caller remains |
| E-031 | Auth welcome with temporary password | [#273](https://github.com/kwat0g/kwatog/pull/273) | Secure-link change drafted; security review and CI pending |
| E-032 | Employee password reset | [#273](https://github.com/kwat0g/kwatog/pull/273) | Secure-link change drafted; HR/security review and CI pending |
| E-033 | Employee welcome with temporary password | [#273](https://github.com/kwat0g/kwatog/pull/273) | Secure-link change drafted; HR/security review and CI pending |
| E-034 | Public contact inquiry alert | [#278](https://github.com/kwat0g/kwatog/pull/278) | Draft; visual and CI review pending |
| E-035 | Test-only integration message | [#280](https://github.com/kwat0g/kwatog/pull/280) | Harness inventory drafted; excluded from production email redesign |

## Shared foundation

- Shared email shell, branding, light/dark presentation, and non-production mail guard: [#213](https://github.com/kwat0g/kwatog/pull/213). Latest landing-page logo changes are local and not yet on the PR head.
- Queue/reliability foundation: [#214](https://github.com/kwat0g/kwatog/pull/214), dependent on #213.
- Raw Markdown table styles: [#320](https://github.com/kwat0g/kwatog/pull/320), dependent on #213.
- Findings affecting behavior or security are tracked separately in [FINDINGS.md](FINDINGS.md).

## Gaps before coverage can be called complete

- All rows remain draft. Several check runs fail on inherited defects or dependencies; use [PROGRESS.md](PROGRESS.md) for the latest run evidence.
- Screenshot coverage is distributed across PR branches. The requested committed desktop/mobile and light/dark screenshots are not all present in this branch's `screenshots/` directory.
- Runtime RBAC, external recipient ownership, notification preferences, failure handling, and company settings still need source-specific verification.
- Cross-client rendering in Outlook, Gmail, Apple Mail, and mobile clients is not verified.
- No email was sent during this work.
