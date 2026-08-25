# M017 — People / Training & Skills action plan

Audit date: 2026-08-24  
Module status: 📋 Plan Ready  
Recommended implementation mode: **separate hardening session**

## Priority plan

| Priority | Workstream | Findings | Size | Exit criterion |
|---|---|---|---|---|
| P1 | Row-level authorization | F01 | medium | Department heads can read only their department/self through employee, training, skill, and direct hash-ID subresource paths; HR/admin behavior remains explicit and tested. |
| P1 | Training state machine and concurrency | F02, F05 | medium | Allowed transitions are enforced under row locks/expected-state predicates; assignment/reassignment is idempotent and unique conflicts return domain validation. |
| P1 | SPA/API contract repair | F03 | small | Employee detail can assign a skill with required dates and receives field-level validation feedback; API and browser regression pass. |
| P1 | Certification evidence | F04 | medium | Certificate/skill evidence is uploaded to private storage, metadata is owned by the record, and authorized retrieval works without exposing raw paths. |
| P1 | Alert delivery and idempotency | F06 | medium | Markers advance only after durable delivery/outbox success; empty recipients, opt-outs, retries, and concurrent checks are safe and observable. |
| P1 | Matrix contract and scale | F07, F08 | medium-to-large | One canonical hash-ID/expiry contract is supported, results are bounded or asynchronous, and production-sized performance is measured. |
| P2 | UI discoverability/accessibility | F09 | small | Archive/restore, validity validation, Skills navigation, icon labels, focus, and destructive-action feedback pass browser/accessibility checks. |
| P2 | Self-service handoff | inventory boundary | medium, M024-owned | Employee self-service consumes the scoped training feed or explicitly defers it with a visible product decision and contract test. |

## Suggested implementation sequence

1. Add negative authorization tests for a department head reading another department’s employee training/skill records; centralize the subresource scope before changing UI.
2. Write the transition matrix and two-connection PostgreSQL tests. Add row locks/expected-state checks, assignment conflict handling, and a deliberate soft-delete/reassignment policy.
3. Repair the skill modal/API type contract and add an employee-detail browser/API journey.
4. Implement private certification evidence storage and authorized downloads; define retention/replacement behavior and audit metadata.
5. Make expiry alerts claimable and retryable with durable delivery semantics; test no recipients, disabled channels, repeated jobs, and concurrent runs.
6. Retire or consolidate the secondary skills matrix endpoints, normalize hash/date semantics, and add bounded matrix/gap queries plus a production-sized benchmark.
7. Finish archive/form/accessibility polish and then hand the self-service consumer contract to M024.

## Release gate

Keep M017 at **📋 Plan Ready** until the P1 exit criteria pass in Compose-backed PostgreSQL, the focused API suite includes negative/concurrency cases, and an authenticated browser journey covers employee training/skill assignment and matrix filtering. Do not treat the current passing happy-path suite as a release sign-off.
