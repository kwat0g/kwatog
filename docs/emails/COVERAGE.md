# Email redesign coverage

Snapshot: 2026-10-21. IDs and classifications follow [01-inventory.md](01-inventory.md).
PR state was checked against GitHub; every linked redesign PR below is open and draft.
This maps implementation work. It does not claim the PR is complete, its CI passes, or
its visual review is committed. Review each PR's current checks and screenshots before
closing the corresponding row.

| ID | Email type | Design / secure-flow PR | Coverage status |
|---|---|---:|---|
| E-001 | Finalized customer invoice | [#216](https://github.com/kwat0g/kwatog/pull/216), [accounting polish #325](https://github.com/kwat0g/kwatog/pull/325) | Desktop/mobile and light/dark captures inspected and copied here; refresh after landing-page logo reaches foundation; CI pending |
| E-002 | Customer official receipt | [#218](https://github.com/kwat0g/kwatog/pull/218), [accounting polish #325](https://github.com/kwat0g/kwatog/pull/325) | Desktop/mobile and light/dark captures inspected and copied here; refresh after landing-page logo reaches foundation; CI pending |
| E-003 | Invoice dunning | [#220](https://github.com/kwat0g/kwatog/pull/220), [accounting polish #325](https://github.com/kwat0g/kwatog/pull/325) | Desktop/mobile and light/dark captures inspected and copied here; refresh after landing-page logo reaches foundation; CI pending |
| E-004 | Finalized credit note | [#222](https://github.com/kwat0g/kwatog/pull/222), [accounting polish #325](https://github.com/kwat0g/kwatog/pull/325) | Eight customer/supplier captures inspected and copied here; mobile amount wraps `PHP`; formatter fix tracked in [#319](https://github.com/kwat0g/kwatog/pull/319); refresh logo after foundation update; CI pending |
| E-005 | Supplier/customer portal invitation | [#224](https://github.com/kwat0g/kwatog/pull/224) | Desktop/mobile and light/dark captures inspected and copied here; 60-minute setup-link copy readable; refresh logo and verify company settings; CI pending |
| E-006 | Supplier/customer portal password reset | [#224](https://github.com/kwat0g/kwatog/pull/224) | Desktop/mobile and light/dark captures inspected and copied here; 60-minute reset-link copy readable; refresh logo and verify company settings; CI pending |
| E-007 | Supplier invoice status | [#226](https://github.com/kwat0g/kwatog/pull/226) | Desktop/mobile and light/dark captures inspected and copied here; hostile contact text is escaped; amount-format follow-up #319 and logo refresh pending; CI pending |
| E-008 | Supplier purchase order cancellation | [#228](https://github.com/kwat0g/kwatog/pull/228) | Desktop/mobile and light/dark captures inspected and copied here; hostile contact text is escaped; refresh logo after foundation update; CI pending |
| E-009 | Customer complaint update | [#230](https://github.com/kwat0g/kwatog/pull/230) | Desktop/mobile and light/dark captures inspected and copied here; hostile contact text is escaped; refresh logo after foundation update; CI pending |
| E-010 | Customer sales order confirmation | [#232](https://github.com/kwat0g/kwatog/pull/232) | Desktop/mobile and light/dark captures inspected and copied here; PHP amounts tracked by #319; refresh logo after foundation update; CI pending |
| E-011 | Customer sales order decision | [#234](https://github.com/kwat0g/kwatog/pull/234) | Desktop/mobile and light/dark captures inspected and copied here; PHP amount tracked by #319; refresh logo after foundation update; CI pending |
| E-012 | Candidate application received | [#236](https://github.com/kwat0g/kwatog/pull/236) | Desktop/mobile and light/dark browser captures inspected and copied here; layout fits; logo and synthetic footer need refresh; CI pending |
| E-013 | Candidate application status | [#236](https://github.com/kwat0g/kwatog/pull/236) | Desktop/mobile and light/dark captures inspected and copied here; layout fits, but fixture uses invalid `updated` for both stages and renders fallback labels; logo/footer refresh and fixture correction pending |
| E-014 | Candidate interview scheduled | [#236](https://github.com/kwat0g/kwatog/pull/236) | Desktop/mobile and light/dark browser captures inspected and copied here; schedule block wraps cleanly; logo and synthetic footer need refresh; CI pending |
| E-015 | Candidate interview changed | [#236](https://github.com/kwat0g/kwatog/pull/236) | Desktop/mobile and light/dark browser captures inspected and copied here; schedule block wraps cleanly; logo and synthetic footer need refresh; CI pending |
| E-016 | Payslip ready notice | [#238](https://github.com/kwat0g/kwatog/pull/238) | Desktop/mobile and light/dark browser captures inspected and copied here; no salary details in body; secure portal CTA clear; logo and synthetic footer refresh pending; CI pending |
| E-017 | Supplier purchase order decision | [#239](https://github.com/kwat0g/kwatog/pull/239) | Desktop/mobile and light/dark captures inspected and copied here; mobile two-column detail table wraps awkwardly; replace with stacked facts; logo/footer refresh and CI pending |
| E-018 | Supplier purchase order published | [#243](https://github.com/kwat0g/kwatog/pull/243) | Desktop/mobile and light/dark captures inspected and copied here; mobile two-column facts table is cramped, ten line items make a very long email; stack facts and cap line items with portal link; logo/footer refresh and CI pending |
| E-019 | Supplier RFQ lifecycle | [#247](https://github.com/kwat0g/kwatog/pull/247) | All five lifecycle states visually spot-checked in mobile light mode, plus invited desktop/light and mobile/dark; no overflow; compact two-column facts lack separation; 20 captures copied here; shared table style #320 pending; inherited PHPStan failure blocks CI; logo unchanged from old shell |
| E-020 | Return case update | [#250](https://github.com/kwat0g/kwatog/pull/250) | Customer/supplier desktop/mobile light/dark browser captures inspected and copied here; compact facts and secure portal CTA fit; refresh logo and synthetic footer; CI pending |
| E-021 | Return request update | [#252](https://github.com/kwat0g/kwatog/pull/252) | Customer/supplier desktop/mobile light/dark browser captures inspected and copied here; compact facts and portal action fit; refresh logo and synthetic footer; CI pending |
| E-022 | Customer delivery confirmation | [#254](https://github.com/kwat0g/kwatog/pull/254) | Desktop/mobile light/dark captures inspected and copied here; dates show Asia/Manila; mobile invoice-reference label wraps but stays readable; logo/footer refresh and CI pending |
| E-023 | Generic user notification | [#255](https://github.com/kwat0g/kwatog/pull/255) | Desktop/mobile light/dark captures inspected and copied here; subject wraps at mobile but no overflow; recipient visibility, deep-link caller coverage, and grouped external To follow-ups remain open; logo/footer refresh pending |
| E-024 | Notification digest | [#265](https://github.com/kwat0g/kwatog/pull/265) | Desktop/mobile light/dark captures inspected and copied here; exposes unread count only and keeps details behind login; logo/footer fixture values need refresh; CI pending |
| E-025 | Scheduled export notice | [#267](https://github.com/kwat0g/kwatog/pull/267) | Desktop/mobile light/dark captures inspected and copied here; mobile metadata table wraps but stays readable; email attaches saved export, so exact row/column/filter parity and HR/accountant/DPO review remain required; logo/footer fixture refresh pending; CI pending |
| E-026 | Critical alert | [#269](https://github.com/kwat0g/kwatog/pull/269) | Desktop/mobile light/dark captures inspected and copied here; layout fits; preview timestamp incorrectly says PST, fix tracked in #276; logo/footer fixture refresh and RBAC follow-up pending |
| E-027 | Daily production summary | [#272](https://github.com/kwat0g/kwatog/pull/272) | Desktop/mobile light/dark captures inspected and copied here; long but capped summary sections and “more in ERP” copy; responsive and permission follow-ups #277/#314 pending; logo/company values redacted in screenshot |
| E-028 | Weekly production summary | [#272](https://github.com/kwat0g/kwatog/pull/272) | Desktop/mobile light/dark captures inspected and copied here; concise date-range and comparable-period copy; responsive and permission follow-ups #277/#314 pending; company values redacted in screenshot |
| E-029 | Internal account welcome | [#273](https://github.com/kwat0g/kwatog/pull/273) | Desktop/mobile light/dark captures inspected and copied here; 60-minute set-password link, no credential in body; replace old icon, verify company settings; security/CI review pending |
| E-030 | Legacy temporary-password reset | [#273](https://github.com/kwat0g/kwatog/pull/273) | Legacy password email replaced by secure reset link; no separate preview; confirm no reachable caller remains |
| E-031 | Auth welcome with temporary password | [#273](https://github.com/kwat0g/kwatog/pull/273) | Desktop/mobile light/dark captures inspected and copied here; 60-minute set-password link, no credential in body; replace old icon, verify company settings; security/CI review pending |
| E-032 | Employee password reset | [#273](https://github.com/kwat0g/kwatog/pull/273) | Desktop/mobile light/dark captures inspected and copied here; 60-minute reset link, no credential in body; replace old icon, verify company settings; HR/security/CI review pending |
| E-033 | Employee welcome with temporary password | [#273](https://github.com/kwat0g/kwatog/pull/273) | Desktop/mobile light/dark captures inspected and copied here; 60-minute set-password link, no credential in body; replace old icon, verify company settings; HR/security/CI review pending |
| E-034 | Public contact inquiry alert | [#278](https://github.com/kwat0g/kwatog/pull/278) | Desktop/mobile light/dark captures inspected and copied here; sensitive sender details stay in authorized CRM; mobile fits; replace old icon and verify company settings; CI pending |
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
