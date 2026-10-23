# DSS R6 — Updates and selective notifications

## Goal

Deliver timely in-app notices for validated, actionable DSS changes without repeating messages, exposing revoked access, or turning a shadow scan into a user alert.

## Current evidence

- `dss:run` is an hourly synchronous scheduler command. Its elapsed-time check runs only after `detect()` returns, so it cannot stop a blocked database query.
- Recommendation persistence and detector run completion share a database transaction. A failed scan does not commit partial emissions as a completed run.
- `NotificationService` has per-user, notification-type, and channel preferences. In-app delivery is enabled unless the user disables it; email requires explicit opt-in.
- No daily notification cap exists in the service or preference schema.
- `NotificationService` suppresses duplicate in-app broadcasts when an insert is ignored, but collects email recipients before that insert. A dedupe key therefore does not make email handoff idempotent.
- All current detectors remain in shadow pending reviewed production evidence. No DSS notice may be published yet.

## Recommended first release policy

- Start with in-app only. Keep email disabled until a separate durable, idempotent mail handoff exists.
- Publish only a newly opened critical recommendation or a material severity increase to critical. Skip warnings, information, partial scans, failed runs, disabled detectors, and every shadow result.
- Use the existing in-app preference as an opt-out. Recheck `dss.view`, current owner permissions, current owner roles, user active state, detector advisory mode, recommendation status, expiry, and any source-reference permission at delivery time.
- Enforce a hard cap of three DSS notices per recipient per application-local calendar day. Use `config('app.timezone')`; over-cap items remain visible in Decision Center. Count delivery time, so retries crossing midnight use the day when delivery actually occurs.
- Do not publish follow-up reminders until a decision family has a validated business deadline and reminder cadence.
- Keep current hourly scans as recovery. Add source-event triggers only after confirming an existing reliable after-commit event and a bounded detector path for that source.

## Delivery design

1. After a successful complete advisory evaluation, write a durable DSS delivery intent in the same transaction as the recommendation transition. Give each intent a stable occurrence and material-change identity with a unique constraint.
2. Dispatch an idempotent worker after commit. A recurring dispatcher recovers undispatched intents; the hourly detector scan remains source-of-truth reconciliation.
3. At delivery, derive recipients from the current DSS visibility predicate. Re-read permissions and opt-out preference; suppress any recipient whose access changed.
4. Serialize cap checks per recipient and application-local date. Create the in-app notification row with a recipient-scoped dedupe key in the same transaction that marks the intent delivered. Use `sendInApp()` or equivalent; do not call `send()`.
5. If a broadcast fails, keep the durable inbox row and mark the intent delivered. If persistence fails before commit, retain the intent for retry. Record delivered, suppressed, capped, and failed outcomes without storing recommendation rationale or source payload in logs.

## Runtime boundary

- Move detector execution behind a worker process with a configured hard timeout shorter than the detector lock lifetime; confirm worker supervision actually terminates a blocked child and the database connection.
- PostgreSQL detectors now use a transaction-local per-statement timeout. Add a cumulative query or worker boundary; the post-return timer remains diagnostic, and non-PostgreSQL drivers do not get the statement timeout.
- Interrupted runs must remain visibly incomplete and must not advance recommendation freshness or trigger disappearance verification.

## Release gates

- Select at least one detector after shadow review demonstrates acceptable relevance and role scope. Do not promote every detector to unlock notifications.
- Add coverage for duplicate event triggers, concurrent workers, retry after persistence failure, retry across midnight, the three-notice cap, preference opt-out, permission revocation, advisory-to-shadow changes, expired recommendations, and user deactivation.
- Confirm no email is sent; confirm duplicate delivery creates one durable inbox row and at most one realtime event.
- Keep DssRunnerService usable without a queue worker for manual local diagnosis, but prevent direct scheduled execution from bypassing the hard timeout.

## Limits

This plan does not add a second recommendation engine, change source workflow permissions, infer causes, send email, or publish from unvalidated detectors. The initial cap and application-timezone rule are proposed defaults from the approved “all recommended” direction; keep them configurable and review them before production rollout.
