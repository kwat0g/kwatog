# E-035 — Test harness email

## Trigger and recipient

`EmailIntegrationTestMail` is constructed only by email feature tests and `MailQueueConfigurationTest`. Repository search found no route, command, listener, scheduler, or production service that constructs it. Its tests use Laravel's mail fake or the array transport. `api/phpunit.xml` forces `MAIL_MAILER=array` and uses a reserved `.test` sender and sink address.

## Content and rendering

The message has a fixed test subject, shared Markdown email shell, settings-backed company identity, and no record-specific content or links. Laravel generates the plain-text alternative from the Markdown view. The mail is queued on the shared mail queue with after-commit handling, three attempts, and `[60, 300, 900]` backoff through `QueuedMailable`.

Its failure hook writes an in-app fallback only when a caller supplied a fallback user ID. No production caller was found. No external mail was sent as part of this audit; tests were not run during this documentation pass.

## Classification and disposition

Test-only system-ops message; excluded from production email coverage. Keep it available for safe integration rendering and queue tests. Do not use it as an unguarded live-send control: it has no production recipient resolver or administrative authorization flow.
