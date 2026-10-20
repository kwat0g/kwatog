# Delivery queue policy

Updated: 2026-10-20

## Mail queue

All application `Mailable` classes that already implement `ShouldQueue` now extend `QueuedMailable`. It preserves Laravel's existing queue behavior and applies the `mail` lane, after-commit dispatch, three attempts, and 60/300/900-second backoff when queued. Daily and weekly production summaries, contact inquiries, account welcome notices, and self-service password-reset links use the same lane and policy.

Both Compose deployments run a dedicated `queue-mail` worker. Mail transport work no longer competes with the existing `high,default` interactive lane or the `low` bulk lane. Queue worker timeout remains 1800 seconds, matching current worker policy.

Two response decision messages previously called `send()` during service work. They now enqueue after commit, so recipient transport does not block resolution or refer to uncommitted state. Recipient selection and message content remain unchanged.

PHPUnit uses the `array` mailer and redirects guarded messages to `mailpit@example.test`; this reserved test domain cannot receive real mail. Local `.env` configuration remains log-only, with SMTP host and credentials empty.

## Exceptions and remaining Phase 4 work

- `CriticalAlertEmail` remains synchronous because `AlertEngineService` records the transport handoff against the alert's own bounded retry state. Moving it to another queue requires redesigning that delivery-state contract.
- Payslip transport remains inside the existing queued `SendPayslipEmailJob` on the low lane. It contains highly sensitive payroll data and needs the separate secure-delivery review before changing its transport or queue behavior.
- Not all email paths have durable delivery logs, idempotency keys, a mail-specific failed-job alert, or admin controls. This PR does not claim those capabilities.
- Queue retries can repeat delivery if a provider accepts a message but the worker loses its acknowledgement. Provider-level idempotency and bounce handling remain open.
- The mail queue is active only when the new `queue-mail` service is deployed and healthy. Deployments outside these Compose files must add an equivalent worker.

## Checks

- Queue-policy test checks all queued application mail classes and mail notifications.
- Focused checks passed: 2 queue-policy tests (136 assertions), 32 supplier response tests (137 assertions), and 15 customer response tests (56 assertions). The first combined flow run overlapped another test-database migration and hit PostgreSQL deadlocks; each flow file passed when rerun alone after adding the reserved PHPUnit sink.
- No message was sent. Compose configuration and changed PHP syntax were checked. Email-client rendering remains unverified.
