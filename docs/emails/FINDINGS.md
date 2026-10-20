# Email audit findings

Date: 2026-10-20. Static findings only; reachability and runtime impact remain unverified. Fixes belong in separate functional/security changes, not the email-template work.

| ID | Finding | Evidence / impact | Follow-up |
|---|---|---|---|
| EF-001 | Local development mail initially could reach external SMTP | `.env` and `api/.env` used SMTP with AWS Mail Manager credentials. Both now use `MAIL_MAILER=log`, credentials are blank, and Horizon was restarted. The shared-foundation branch adds redirect-or-block enforcement. | Keep local mail on log or a verified sink; verify the deployed guard before any non-production send. |
| EF-002 | Company settings need owner verification | Shared foundation now requires `company.legal_name` and `company.address` instead of using fallback literals, and makes the other optional identity values settings-only. The local settings rows exist; sales inbox and VAT status match the old fallback strings. | Owner verifies settings before external send. Accountant reviews statutory fields. |
| EF-003 | Welcome/reset messages include temporary passwords | Auth and HR notification constructors accept temporary password strings and their mail bodies include them. Exact active call paths need confirmation. | Trace reachability; replace with secure activation/reset flow. Security review. |
| EF-004 | Some email transport remained synchronous | Customer sales-order decision and supplier PO decision now enqueue after commit on the dedicated mail lane. Payslip still calls `Mail::send` inside its existing queued low-lane job; critical-alert notification is synchronous to preserve its alert-owned retry state. | Keep payslip in sensitive-delivery review; redesign alert delivery state before moving its transport. |
| EF-005 | Generic emails vary in sensitivity but share one body path | `NotificationService` can queue `UserNotificationMail` with arbitrary caller title/message/link for opted-in notification types. | Classify runtime type keys and remove sensitive body details or restrict eligibility as a separate privacy/RBAC change. |
| EF-006 | Delivery state lacks a common durable email ledger | Existing status tracking is per-feature; most send paths do not share queued/sent/failed logs or provider identifiers. | Add an email ledger with redaction and retention rules before building an Email Center. |
| EF-007 | Finalized invoice mail could use a later customer address and changed invoice values | `InvoiceFinalized` queues its email listener. Laravel rehydrates its invoice model when that listener runs, so customer email, balance, customer name, and line items could differ from the values at issue time. | Fixed in the separate invoice snapshot change: capture issue-time recipient and display fields in the event; test delay-shaped mutations before listener handling. |

No code-level fix was applied as part of Phase 0/1.
