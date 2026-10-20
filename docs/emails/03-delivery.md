# Delivery queue policy

Updated: 2026-10-20

## Mail queue

All application `Mailable` classes that already implement `ShouldQueue` now extend `QueuedMailable`. It preserves Laravel's existing queue behavior and applies the `mail` lane, after-commit dispatch, three attempts, and 60/300/900-second backoff when queued. Daily and weekly production summaries, contact inquiries, account welcome notices, and self-service password-reset links use the same lane and policy.

Both Compose deployments run a dedicated `queue-mail` worker. Mail transport work no longer competes with the existing `high,default` interactive lane or the `low` bulk lane. Queue worker timeout remains 1800 seconds, matching current worker policy.

Two response decision messages previously called `send()` during service work. They now enqueue after commit, so recipient transport does not block resolution or refer to uncommitted state. Recipient selection and message content remain unchanged.

## Delivery ledger and retry behavior

`email_deliveries` records the intended recipients, type, subject, triggering user when available, attempt count, provider message ID, status, and timestamps. It never stores message bodies. Rows older than `MAIL_LOG_RETENTION_DAYS` (default 90) are pruned daily. This is a database ledger, not an Email Center; there is no permission-gated search or resend UI yet.

Queued mailables carry a unique delivery key across retries. Successful or ambiguous prior attempts are not sent again. If a worker dies after transport acceptance but before recording success, the next attempt becomes `unknown` and is suppressed to avoid a duplicate. This favors duplicate prevention over automatic recovery; an operator must compare provider logs and decide whether to resend. SMTP/provider acceptance cannot prove inbox delivery. Separate duplicate dispatches receive different keys and are not deduplicated by business event.

The ledger logs intended recipient addresses, including in non-production, while the mail guard redirects actual delivery to the configured sink. Restrict database access accordingly. Provider error details and message bodies are not logged; failure records keep exception class only.

PHPUnit uses the `array` mailer and redirects guarded messages to `mailpit@example.test`; this reserved test domain cannot receive real mail. Local `.env` configuration remains log-only, with SMTP host and credentials empty.

## Exceptions and remaining Phase 4 work

- `CriticalAlertEmail` remains synchronous because `AlertEngineService` records the transport handoff against the alert's own bounded retry state. Moving it to another queue requires redesigning that delivery-state contract.
- Payslip transport remains inside the existing queued `SendPayslipEmailJob` on the low lane. It contains highly sensitive payroll data and needs the separate secure-delivery review before changing its transport or queue behavior.
- Synchronous mail paths have no reliable failure transition in the generic ledger; critical alerts continue to own their retry state. Payslip transport remains within its existing queued job.
- There is no mail-specific failed-job alert, Email Center, resend audit, provider idempotency, or bounce/complaint handling.
- Duplicate dispatches are not deduplicated. The retry key only covers retries of the same queued mail job.
- The mail queue is active only when the new `queue-mail` service is deployed and healthy. Deployments outside these Compose files must add an equivalent worker.

## Checks

- Queue-policy and delivery-ledger checks cover queue policy, queued/sent states, body exclusion, and ambiguous-retry suppression.
- Focused checks passed: 4 mail queue/ledger tests (121 assertions), 32 supplier response tests (137 assertions), and 15 customer response tests (56 assertions). The first combined flow run overlapped another test-database migration and hit PostgreSQL deadlocks; each flow file passed when rerun alone after adding the reserved PHPUnit sink.
- No message was sent. Compose configuration and changed PHP syntax were checked. Email-client rendering remains unverified.
