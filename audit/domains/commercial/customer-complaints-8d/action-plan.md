# M034 — Customer complaints / 8D re-audit action plan

Date: 2026-08-27
Status: 📋 Plan Ready
Plan basis: current worktree and batch3-agent-a re-audit

The previously recorded integrity, portal-boundary, SLA, email, source-filter,
and NCR-contract fixes are present and verified. R04-R05 remain policy
decisions; R11-R12 are current hardening findings.

## Ordered pending actions

### 1. M034-R04 — Decide lifecycle policy and implement or retire unused states

- Classification/severity: Missing, P2
- Scope: medium/large
- Session recommendation: separate-recommended
- Decide whether investigation is an explicit transition and whether complaint
  cancellation is permitted, including reasons, actors, NCR behavior, and
  customer-visible behavior. Then implement the authorized workflow or retire
  the unreachable enum/options/SPA branches.

### 2. M034-R05 — Define and implement internal complaint permissions

- Classification/severity: Incomplete, P2
- Scope: medium
- Session recommendation: separate-recommended
- Define view, create/update, 8D edit, 8D finalize, lifecycle, NCR retry, and
  PDF permissions with the RBAC owner. Apply the approved matrix to API routes,
  SPA gates, seeded roles, and authorization tests.

### 3. M034-R12 — Add an auditable 8D child-record history

- Classification/severity: Missing, P2
- Scope: medium
- Session recommendation: separate-recommended
- Decide whether `HasAuditLog` is sufficient for the D-field content and
  finalization event, confirm redaction/retention requirements, then add the
  durable audit trail and tests for create, edit, finalize, and immutable
  post-finalization behavior.

### 4. M034-R11 — Validate internal complaint list query parameters

- Classification/severity: Incomplete, P2
- Scope: small
- Session recommendation: same-session-ok
- Validate status/severity enums, customer hash, search length, and
  `per_page` bounds/shape before calling the service; preserve fail-closed
  behavior for invalid customer hashes and return stable 422 responses.

## Completed findings and regression evidence

The following actions are complete in the current implementation. Their tags
remain for traceability; they are not candidates for another same-session fix.

| Finding | Classification | Scope | Session recommendation | Result |
|---|---|---|---|---|
| R01 durable SLA delivery records | Incomplete, P1 | medium/large | separate-recommended | fixed and verified |
| R02 reject cancelled portal source orders | Incomplete, P2 | small | same-session-ok | fixed and verified |
| R03 normalize portal complaint audit action | Broken, P2 | small | same-session-ok | fixed and verified |
| R08 repair complaint update email rendering | Broken, P1 | small | same-session-ok | fixed and verified |
| R09 fail closed on invalid internal customer filters | Incomplete, P2 | small | same-session-ok | fixed and verified |
| R10 align NCR list/UI completion contract | Incomplete, P2 | small | same-session-ok | fixed and verified |

Earlier F01/F03 authoritative locks, F02 quality completion gating, F04/F07
safe publication/PDF gates, F06 source and assignee validation, F10/F11 source
and history contracts, and F12 retention protection are also present and
covered by the current focused tests.

## Gate decision

The final status is `📋 Plan Ready`. Of the four pending actions, only R11 is
tagged `same-session-ok`; R04, R05, and R12 are `separate-recommended`, and the
total scope is not a small majority-same-session plan. No implementation fixes
were applied in this session.

## Verification constraint

The focused Docker feature suite passed on isolated PostgreSQL database
`ogami_test_m034_agent_a2`: **55 tests and 180 assertions**. PHP lint,
`git diff --check`, SPA typecheck, and scoped ESLint for the complaint pages,
APIs, and types also passed. A full browser/e2e run and multi-worker
concurrency run remain outside this session. Laravel Pint remains non-clean on
pre-existing formatting drift in several legacy files; no unrelated formatter
rewrite was included.

## Definition of done for the next release

- Lifecycle and RBAC decisions are documented and either implemented or
  removed from the public contract.
- 8D field/finalization audit history is implemented with the approved
  redaction and retention policy.
- Internal complaint list parameters return a stable validated contract.
- Focused CRM/B2B tests, browser flows, and a dedicated multi-worker
  concurrency run pass on clean isolated environments.
