# DSS R6 — Updates and selective notifications

## Goal

Deliver timely in-app notices for validated, actionable DSS changes without repeating messages, exposing revoked access, or turning a shadow scan into a user alert.

## Current evidence

- `dss:run` queues one detector per isolated Horizon job. Queue timeout failure and the stale-run maintainer mark interrupted runs failed.
- Recommendation persistence and detector run completion share a database transaction. A persistence-interruption test confirms recommendation writes roll back and the run is marked failed with zero emissions.
- `NotificationService` has per-user, notification-type, and channel preferences. In-app delivery is enabled unless the user disables it; email requires explicit opt-in.
- DSS in-app delivery has a configurable cap of three notices per recipient per application-local day (`DSS_NOTIFICATION_DAILY_CAP`). A recipient-row lock serializes cap checks; the existing recipient-scoped notification dedupe index makes retries safe.
- No publisher calls the capped DSS delivery method; detector runs send no notices.
- `NotificationService` suppresses duplicate in-app broadcasts when an insert is ignored, but collects email recipients before that insert. A dedupe key therefore does not make email handoff idempotent.
- All current detectors remain in shadow pending reviewed production evidence. No DSS notice may be published yet.
- Source/user permissions must be re-read at delivery time; source permissions recorded on an emission are not durable authorization.

## Recommended first release policy

- Start with in-app only. Keep email disabled until a separate durable, idempotent mail handoff exists.
- Publish only a newly opened critical recommendation or a material severity increase to critical. Skip warnings, information, partial scans, failed runs, disabled detectors, and every shadow result.
- Use the existing in-app preference as an opt-out. Recheck `dss.view`, current owner permissions, current owner roles, user active state, detector advisory mode, recommendation status, expiry, and any source-reference permission at delivery time.
- Enforce a hard cap of three DSS notices per recipient per application-local calendar day. Use `config('app.timezone')`; over-cap items remain visible in Decision Center. Count delivery time, so retries crossing midnight use the day when delivery actually occurs.
- Configure and enforce this cap before any publishing. A zero cap disables delivery; missing duplicate protection keeps publishing disabled.
- Do not publish follow-up reminders until a decision family has a validated business deadline and reminder cadence.
- Keep current hourly scans as recovery. Add source-event triggers only after confirming an existing reliable after-commit event and a bounded detector path for that source.

## Delivery design

1. After a successful complete advisory evaluation, write a durable DSS delivery intent in the same transaction as the recommendation transition. Give each intent a stable occurrence and material-change identity with a unique constraint.
2. Dispatch an idempotent worker after commit. A recurring dispatcher recovers undispatched intents; the hourly detector scan remains source-of-truth reconciliation.
3. At delivery, derive recipients from the current DSS visibility predicate. Re-read permissions and opt-out preference; suppress any recipient whose access changed.
4. Serialize cap checks per recipient and application-local date. `sendDssRecommendationInApp()` locks the recipient row, checks the configured cap, and inserts an in-app notification with a stable hashed event identity in the existing unique dedupe column. Use this path; do not call `send()`.
5. If a broadcast fails, keep the durable inbox row and mark the intent delivered. If persistence fails before commit, retain the intent for retry. Record delivered, suppressed, capped, and failed outcomes without storing recommendation rationale or source payload in logs.

## Runtime boundary

- Queue workers run one detector per job with a bounded timeout; queue-failure handling and stale-run recovery are tested. Confirm actual blocked-child termination under Horizon in staging.
- The runner enforces one monotonic deadline across detection, persistence, and reconciliation. PostgreSQL refreshes its transaction-local statement timeout before each query; other drivers check before each query and after non-SQL work returns. Hard termination of a blocked worker remains open.
- Interrupted runs remain incomplete and do not advance recommendation freshness or trigger disappearance verification.

## Release gates

- Select at least one detector after shadow review demonstrates acceptable relevance and role scope. Do not promote every detector to unlock notifications.
- Add coverage for duplicate event triggers, concurrent workers, retry after persistence failure, retry across midnight, the three-notice cap, preference opt-out, permission revocation, advisory-to-shadow changes, expired recommendations, and user deactivation. Cap enforcement, duplicate replay, local-midnight reset, persistence rollback, timeout failure, and stale-run recovery have focused tests. Notification-intent delivery gates remain uncovered until a publisher exists.
- Confirm no email is sent; confirm duplicate delivery creates one durable inbox row and at most one realtime event.
- At delivery, recheck current user, role, and source-record permissions; revoked access suppresses delivery. Keep stable occurrence/change deduplication and the configured daily cap.
- Keep DssRunnerService usable without a queue worker for manual local diagnosis, but prevent direct scheduled execution from bypassing the hard timeout.

## Limits

This plan does not add a second recommendation engine, change source workflow permissions, infer causes, send email, or publish from unvalidated detectors. The initial cap and application-timezone rule are configurable defaults from the approved “all recommended” direction; review them before production rollout. Durable intent recovery and delivery-time permission checks remain required before a publisher can use the capped delivery method.
