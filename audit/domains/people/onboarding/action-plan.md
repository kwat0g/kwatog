# M015 — People / Onboarding action plan

Status: 🔁 Needs Re-audit  
Audit date: 2026-08-24  
Overall recommendation: re-audit after environment blockers are cleared

## Ordered work

| Priority | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---|---|---|---|---|
| P1 | M015-F01 department-notification completion contract | medium | separate-recommended | HR/system-admin can complete the attestation through a guarded API/UI action; unauthorized roles are denied; `completed_at` is set only after all seven steps; audit evidence records actor/time. |
| P1 | M015-F02 stale reminder delivery | medium | separate-recommended | The daily command inserts a catalogued in-app notification for the configured HR audience with `link_to`; sent timestamps follow durable-insert success and failures remain retryable. |
| P1 | M015-F04 onboarding regression suite | medium | separate-recommended | HTTP auth, completion, reminder, retry, notification-preference, and SPA/browser cases execute against PostgreSQL and a writable build cache. |
| P2 | M015-F03 pure status versus reconciliation | small-to-medium | same-session-ok | After the workflow contract is settled, GET status is either pure or explicitly transactional/reconciliatory; POST recompute does not perform duplicate recomputation; behavior is covered by a no-write or reconciliation test. |
| P2 | stepper action and failure polish | small | same-session-ok | After F01, incomplete steps show the supported action or blocking reason; retry/server errors are visible; completion refreshes the query and is covered in the browser path. |

## Suggested implementation sequence

1. Define the source of truth for `Dept Team Notified`: an HR attestation,
   notification dispatch event, or another auditable domain event. Do not expose
   an unrestricted arbitrary step-key endpoint.
2. Implement that contract in a transaction with actor authorization, audit
   evidence, idempotency, and a completion transition test.
3. Replace the nonexistent `notifyRole()` call with the shared notification
   service and explicit HR recipient resolution. Add the notification catalog
   key, use `link_to`, and update `reminder_sent_at` only after durable success.
4. Add negative and positive API tests for route permissions, completion,
   reminder delivery/retry, and notification preferences. Run them with the
   PostgreSQL test service.
5. Split or document status reconciliation, then add the SPA action, loading,
   error, retry, incomplete, and completed-state coverage.
6. Re-run PHP lint, backend feature tests, SPA typecheck/lint/build, and an
   authenticated browser smoke path before verification.

## Session outcome — 2026-08-25

The planned implementation was completed: the department-team attestation is
guarded and audited, stale reminders use the shared durable notification path,
status reconciliation is transactional without duplicate POST recomputation,
and API/SPA regression coverage plus stepper retry/action polish were added.

The module is released as `🔁 Needs Re-audit` because the PostgreSQL feature
suite could not resolve host `db`, and the browser smoke could not mount the
SPA due a pre-existing `LuAward is not defined` error in the dirty shared
sidebar. Focused frontend unit tests pass 13/13; the exact pending checks and
environment causes are recorded in `fix-log.md`.
