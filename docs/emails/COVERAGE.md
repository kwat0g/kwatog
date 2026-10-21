# Transactional email coverage

Snapshot: 2026-10-21. This ledger reconciles every discovered email ID in [the inventory](01-inventory.md) with its design work and review record. “Implemented” means code exists on an open draft PR; it does not mean merged or approved for production. Check linked PRs for current CI state.

## Email-by-email coverage

| ID | Email | Implementation PR | Review record | State and remaining gap |
|---|---|---:|---|---|
| E-001 | Finalized customer invoice | [#216](https://github.com/kwat0g/kwatog/pull/216) | [E-001](E-001-invoice-finalized.md) | Implemented in draft; accountant and company-settings review pending. |
| E-002 | Official receipt | [#218](https://github.com/kwat0g/kwatog/pull/218) | [E-002](E-002-official-receipt.md) | Implemented in draft; accountant must decide whether a receipt PDF or portal route is required. |
| E-003 | Invoice dunning | [#220](https://github.com/kwat0g/kwatog/pull/220) | [E-003](E-003-invoice-dunning.md) | Implemented in draft; per-invoice volume policy remains open. |
| E-004 | Finalized credit note | [#222](https://github.com/kwat0g/kwatog/pull/222) | [FINDINGS](FINDINGS.md) EF-013–015 | Implemented in draft; accountant must decide on external document access. |
| E-005 | Supplier/customer portal invitation | [#224](https://github.com/kwat0g/kwatog/pull/224) | [E-005](E-005-portal-invitation.md) | Implemented in draft; verify owner-approved company data. |
| E-006 | Portal password reset | [#224](https://github.com/kwat0g/kwatog/pull/224) | [E-006](E-006-portal-password-reset.md) | Implemented in draft; real-client and provider verification pending. |
| E-007 | Supplier invoice status | [#226](https://github.com/kwat0g/kwatog/pull/226) | [E-007](E-007-supplier-invoice-status.md) | Implemented in draft; supplier-facing wording review pending. |
| E-008 | Supplier PO cancellation | [#228](https://github.com/kwat0g/kwatog/pull/228) | [E-008](E-008-supplier-po-cancellation.md) | Implemented in draft; purchasing review pending. |
| E-009 | Customer complaint update | [#230](https://github.com/kwat0g/kwatog/pull/230) | [E-009](E-009-customer-complaint-update.md) | Implemented in draft; customer wording review pending. |
| E-010 | Customer sales order confirmation | [#232](https://github.com/kwat0g/kwatog/pull/232) | [E-010](E-010-sales-order-confirmation.md) | Implemented in draft; accountant review pending. |
| E-011 | Customer sales order decision | [#234](https://github.com/kwat0g/kwatog/pull/234) | [E-011](E-011-sales-order-decision.md) | Implemented in draft; customer portal wording review pending. |
| E-012 | Candidate application received | [#236](https://github.com/kwat0g/kwatog/pull/236) | [E-012–015](E-012-015-candidate-emails.md) | Implemented in draft; HR/legal/data-protection review pending. |
| E-013 | Candidate application status | [#236](https://github.com/kwat0g/kwatog/pull/236) | [E-012–015](E-012-015-candidate-emails.md) | Implemented in draft; HR/legal/data-protection review pending. |
| E-014 | Candidate interview scheduled | [#236](https://github.com/kwat0g/kwatog/pull/236) | [E-012–015](E-012-015-candidate-emails.md) | Implemented in draft; HR/legal/data-protection review pending. |
| E-015 | Candidate interview changed | [#236](https://github.com/kwat0g/kwatog/pull/236) | [E-012–015](E-012-015-candidate-emails.md) | Implemented in draft; HR/legal/data-protection review pending. |
| E-016 | Employee payslip notice | [#238](https://github.com/kwat0g/kwatog/pull/238) | [E-016](E-016-payslip.md) | Implemented in draft; HR/legal/data-protection review pending. |
| E-017 | Supplier PO decision | [#239](https://github.com/kwat0g/kwatog/pull/239) | [E-017](E-017-supplier-po-decision.md) | Implemented in draft; purchasing/accountant and supplier-contact privacy review pending. |
| E-018 | Supplier PO publication | [#243](https://github.com/kwat0g/kwatog/pull/243) | [E-018](E-018-supplier-po-published.md) | Implemented in draft; CI and purchasing/accountant review pending. The separate inherited PHPStan fix is [#329](https://github.com/kwat0g/kwatog/pull/329). |
| E-019 | Supplier RFQ lifecycle | [#247](https://github.com/kwat0g/kwatog/pull/247) | [E-019](E-019-rfq-lifecycle.md) | Implemented in draft; shared Markdown-table styling follow-up is #320; supplier review pending. |
| E-020 | Return case update | [#250](https://github.com/kwat0g/kwatog/pull/250) | [E-020](E-020-return-case-update.md) | Implemented in draft; party-contact privacy review pending. |
| E-021 | Return request update | [#252](https://github.com/kwat0g/kwatog/pull/252) | [E-021](E-021-return-request-update.md) | Implemented in draft; party-contact privacy review pending. |
| E-022 | Customer delivery confirmed | [#254](https://github.com/kwat0g/kwatog/pull/254) | [E-022](E-022-delivery-confirmed.md) | Implemented in draft; shipping-document review pending. |
| E-023 | Generic user notification | [#255](https://github.com/kwat0g/kwatog/pull/255) | [caller audit](E-023-caller-audit.md), [template](E-023-generic-user-notifications.md) | Template implemented; **not complete**. The updated audit records 83 generic notification call sites. Recipient/link fixes are in open drafts #256–#310 and #324; EF-124 is fixed in draft #331. Verify every call site against its source and current CI before treating this as complete. |
| E-024 | Notification digest | [#265](https://github.com/kwat0g/kwatog/pull/265) | [E-024](E-024-notification-digest.md) | Implemented in draft; preference and owner review pending. |
| E-025 | Scheduled export | [#267](https://github.com/kwat0g/kwatog/pull/267) | [E-025](E-025-scheduled-export.md) | Implemented in draft; idempotency assertion and artifact-parity review remain pending. |
| E-026 | Critical alert | [#269](https://github.com/kwat0g/kwatog/pull/269) | [E-026](E-026-critical-alert.md) | Implemented in draft; time-format follow-up is #276; alert-recipient review pending. |
| E-027 | Daily production summary | [#272](https://github.com/kwat0g/kwatog/pull/272), follow-up [#277](https://github.com/kwat0g/kwatog/pull/277) | [E-027–028](E-027-028-production-summaries.md) | Implemented in draft; audience and permission review pending. |
| E-028 | Weekly production summary | [#272](https://github.com/kwat0g/kwatog/pull/272), follow-up [#277](https://github.com/kwat0g/kwatog/pull/277) | [E-027–028](E-027-028-production-summaries.md) | Implemented in draft; audience and permission review pending. |
| E-029 | Internal password reset link | [#273](https://github.com/kwat0g/kwatog/pull/273) | [E-029–033](E-029-033-account-security.md) | Implemented in draft; auth-flow CI and security review pending. |
| E-030 | Legacy temporary-password reset | [#273](https://github.com/kwat0g/kwatog/pull/273) | [E-029–033](E-029-033-account-security.md) | Legacy body removed in draft; verify no live callers and drain old queued payloads. |
| E-031 | Internal welcome | [#273](https://github.com/kwat0g/kwatog/pull/273) | [E-029–033](E-029-033-account-security.md) | Implemented in draft; HR/security review pending. |
| E-032 | Employee password reset | [#273](https://github.com/kwat0g/kwatog/pull/273) | [E-029–033](E-029-033-account-security.md) | Implemented in draft; HR/security review pending. |
| E-033 | Employee welcome | [#273](https://github.com/kwat0g/kwatog/pull/273) | [E-029–033](E-029-033-account-security.md) | Implemented in draft; HR/security review pending. |
| E-034 | Public contact inquiry alert | [#278](https://github.com/kwat0g/kwatog/pull/278) | [E-034](E-034-contact-inquiry.md) | Implemented in draft; sales inbox readers and data-protection review pending. |
| E-035 | Email integration test message | [#280](https://github.com/kwat0g/kwatog/pull/280) | [E-035](E-035-test-harness.md) | Test-only; excluded from production email count. |

## Module coverage

| Module | Email IDs |
|---|---|
| Accounting | E-001–E-004 |
| Auth / Admin | E-029–E-031 |
| B2B portals | E-005–E-008 |
| CRM | E-009–E-011 |
| HR / Recruitment | E-012–E-015, E-032–E-033 |
| Payroll | E-016 |
| Purchasing | E-017–E-019 |
| Return Management | E-020–E-021 |
| Supply Chain | E-022 |
| Common / Admin Operations | E-023–E-026, E-035 |
| Production | E-027–E-028 |
| Landing / Public | E-034 |

The inventory contains the role/audience × email matrix and the trigger → recipient rule → source-of-truth matrix. Those source-level rules remain provisional where the ledger says recipient or permission review is pending. “No dedicated email” module findings and candidate missing-email recommendations are also in [the inventory](01-inventory.md).

## Shared foundation and unresolved acceptance checks

- Shared framework and branding: draft PR [#213](https://github.com/kwat0g/kwatog/pull/213). Delivery queue/ledger: draft PR [#214](https://github.com/kwat0g/kwatog/pull/214).
- All PRs above are drafts. This ledger does not claim merge, production rollout, deliverability, or real-client rendering.
- Owner must verify company identity, statutory fields, sales inbox, sender/reply policy, and approved disclaimer. Accountant/HR/legal/data-protection reviewers must review the financial, payroll, HR, and personal-data messages noted above.
- SPF, DMARC, bounce/complaint handling, and DNS/provider state remain unverified. Owner reported DKIM setup succeeded for `ogamiph.dev` in AWS Singapore; no DNS lookup was performed.
- Visual evidence is browser-rendered desktop/mobile light/dark captures linked from each review record. Gmail, Outlook, Apple Mail, Yahoo, and mobile mail-client rendering remain unverified; use the manual checklist in [the inventory](01-inventory.md).
- No real email was sent during this audit.
