# Email redesign coverage

Updated: 2026-10-21. IDs follow [the inventory](01-inventory.md). This maps discovered email types to their current implementation unit; it is not a production sign-off. Draft PRs remain unmerged, and PR presence alone does not prove runtime delivery or recipient correctness.

| Inventory IDs | Email group | Implementation unit | State |
|---|---|---|---|
| E-001–E-003 | Customer invoice, official receipt, and dunning | [PR #325](https://github.com/kwat0g/kwatog/pull/325) | Draft; current branch includes browser previews at desktop/mobile and light/dark. Latest CI is running. |
| E-004 | Finalized credit note | [PR #222](https://github.com/kwat0g/kwatog/pull/222) | Draft |
| E-005–E-006 | Portal invitation and password reset | [PR #224](https://github.com/kwat0g/kwatog/pull/224) | Draft; secure-link follow-up in [PR #223](https://github.com/kwat0g/kwatog/pull/223) |
| E-007 | Supplier invoice status | [PR #226](https://github.com/kwat0g/kwatog/pull/226) | Draft |
| E-008 | Supplier PO cancellation | [PR #228](https://github.com/kwat0g/kwatog/pull/228) | Draft |
| E-009 | Customer complaint update | [PR #230](https://github.com/kwat0g/kwatog/pull/230) | Draft |
| E-010 | Customer sales-order confirmation | [PR #232](https://github.com/kwat0g/kwatog/pull/232) | Draft; mobile line items changed to stacked details in commit `0006d775`; screenshot refresh pending. |
| E-011 | Customer sales-order decision | [PR #234](https://github.com/kwat0g/kwatog/pull/234) | Draft |
| E-012–E-015 | Candidate receipt, status, and interview notices | [PR #236](https://github.com/kwat0g/kwatog/pull/236) | Draft |
| E-016 | Payslip access | [PR #238](https://github.com/kwat0g/kwatog/pull/238) | Draft; secure-link treatment |
| E-017 | Supplier PO decision | [PR #239](https://github.com/kwat0g/kwatog/pull/239) | Draft |
| E-018 | Supplier PO publication | [PR #243](https://github.com/kwat0g/kwatog/pull/243) | Draft; mobile line items changed to stacked details in local commit `a18dde45`; screenshot refresh and push pending. |
| E-019 | Supplier RFQ lifecycle | [PR #247](https://github.com/kwat0g/kwatog/pull/247) | Draft; screenshot evidence recorded in `screenshots/e019-rfq-lifecycle-*.png` |
| E-020 | Return-case update | [PR #250](https://github.com/kwat0g/kwatog/pull/250) | Draft |
| E-021 | Return-request update | [PR #252](https://github.com/kwat0g/kwatog/pull/252) | Draft |
| E-022 | Customer delivery confirmation | [PR #254](https://github.com/kwat0g/kwatog/pull/254) | Draft |
| E-023 | Generic user notification | [PR #255](https://github.com/kwat0g/kwatog/pull/255) | Draft; caller-audit and recipient/link follow-ups include [PR #257](https://github.com/kwat0g/kwatog/pull/257), [PR #264](https://github.com/kwat0g/kwatog/pull/264), and [PR #310](https://github.com/kwat0g/kwatog/pull/310) |
| E-024 | Notification digest | [PR #265](https://github.com/kwat0g/kwatog/pull/265) | Draft |
| E-025 | Scheduled export | [PR #267](https://github.com/kwat0g/kwatog/pull/267) | Draft |
| E-026 | Critical alert | [PR #269](https://github.com/kwat0g/kwatog/pull/269) | Draft |
| E-027–E-028 | Daily and weekly production summaries | [PR #272](https://github.com/kwat0g/kwatog/pull/272) | Draft |
| E-029–E-033 | Internal account-security messages | [PR #273](https://github.com/kwat0g/kwatog/pull/273) | Draft; replaces temporary-password content with secure links |
| E-034 | Public contact inquiry | [PR #278](https://github.com/kwat0g/kwatog/pull/278) | Draft |
| E-035 | Test-only integration message | Excluded from product redesign | Test harness only; not a production email |

## Remaining sign-off

- E-001–E-003 and E-019 have checked-in desktop/mobile and light/dark screenshot evidence. Other groups still need visual evidence checked against the current shared foundation before their coverage is considered complete.
- Existing desktop/light and mobile/dark captures were reviewed for E-011–E-017 and E-020–E-022; no visible clipping or theme defects found. E-017's two-column summary wraps on mobile but remains readable. E-018's 3-column item table was too compressed on mobile and is changed to stacked item details; updated preview remains pending.
- E-010's 4-column mobile item table had compressed descriptions and currency values; the same stacked-detail treatment is committed, with a refreshed preview pending.
- E-005–E-006's checked-in dark screenshots show blank CTA labels. Fresh network-isolated renders of the current templates show white CTA text and no viewport overflow; refresh the checked-in captures before sign-off.
- All mapped implementation PRs are drafts. The stack has dependent base branches; independent merge/revert readiness has not been verified across the full stack.
- Runtime recipient resolution, delivery safety, queues, logs, and tests remain subject to their linked PR checks and review. This report does not claim those gates passed.
- Confirm company settings with the owner before any live send. Review external financial documents with the accountant and personal-data emails with HR/legal/data protection. Verify SPF/DKIM/DMARC with the mail provider; the code cannot prove DNS or provider configuration.
- Missing-email recommendations and other-channel coordination remain in [the inventory](01-inventory.md).
