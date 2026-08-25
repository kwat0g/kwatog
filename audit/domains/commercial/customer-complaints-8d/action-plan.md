# M034 — Customer complaints / 8D re-audit action plan

Date: 2026-08-25  
Status: 🔁 Needs Re-audit  
Plan basis: current worktree after the 2026-08-24 report

The earlier plan's P1 integrity and portal-boundary work is present in the
current code. The three executable findings in this plan are now fixed; the
two remaining items require explicit policy decisions and are not guessed.

## Ordered implementation plan

### 1. M034-R01 — Add durable SLA delivery records — completed

- Classification/severity: Incomplete, P1
- Scope: medium/large; CRM model, migration, escalation service, tests
- Session recommendation: separate-recommended
- Add a unique complaint/tier delivery ledger with pending/sent status,
  idempotency key, attempt count, recipient count, timestamps, and last error.
  Keep the complaint row lock and transaction. A failed notification insert
  must roll back the claim and leave a retryable pending record; no active
  recipient must not consume the tier.
- Implemented in the M034 CRM model, migration, and escalation service.
- Regression assertions cover sequential replay, simulated failure, and
  no-recipient retry. Two-worker concurrency remains to be run after the
  shared database reset is repaired.

### 2. M034-R02 — Reject cancelled portal source orders early — completed

- Classification/severity: Incomplete, P2
- Scope: small; portal request validator and negative test
- Session recommendation: same-session-ok
- Rejects a cancelled order as a validation error while retaining the
  authoritative CRM service-side lock/re-check for races. Negative API test
  added.

### 3. M034-R03 — Normalize the portal complaint audit action — completed

- Classification/severity: Broken, P2
- Scope: small; portal service and contract test
- Session recommendation: same-session-ok
- Uses the stable `customer.complaint.submitted` identifier consistently with
  the portal contract test.

### 4. M034-R04 — Decide cancellation policy and implement or retire it

- Classification/severity: Missing, P2
- Scope: small/medium; lifecycle, audit field, permission, UI, and tests
- Session recommendation: separate-recommended
- Requires a product/quality decision about whether a complaint can be
  cancelled, why, who may do it, and what the customer portal should display.
  This session defers the item pending that decision.

### 5. M034-R05 — Split internal complaint permissions if required

- Classification/severity: Incomplete, P2
- Scope: medium; RBAC seeder, routes, UI gates, and matrix tests
- Session recommendation: separate-recommended
- Requires an explicit role/action matrix for view, create/update, 8D
  finalize, lifecycle, NCR retry, and PDF. This session defers the item
  pending RBAC-owner input.

## Already addressed and requiring regression verification

- R01-R03: current-session fixes are linted and covered by focused assertions;
  execution is pending the shared migration repair.
- F01/F03: authoritative complaint/report locks and lifecycle transition matrix.
- F02: finalized-8D plus closed/dispositioned-NCR completion gate.
- F04/F07: customer-safe portal resource, finalized-only portal report, and
  finalized-only internal PDF.
- F06: request-boundary and transaction-time source/assignee validation.
- F10/F11: searchable source selectors, explicit portal product contract, and
  bounded portal complaint history.
- F12: restrictive complaint/customer foreign key migration and regression test.

## Verification constraint

The focused feature suite is blocked before assertions by the shared
`ogami_test` database reset: the current attempt found duplicate
`migrations`/`roles` relations, and an earlier reset reached the unrelated
`enforce_one_active_holiday_per_date` migration at line 42, where PostgreSQL
rejects dropping an index-backed constraint. Do not modify those dependencies
from this module session; rerun the M034 suite after the shared test database
and migration teardown are repaired.

## Definition of done for the next release

- SLA delivery ledger is atomic, retryable, and covered by failure/concurrency
  tests.
- Cancellation and RBAC decisions are documented and either implemented or
  removed from the public contract.
- The focused CRM/B2B suite and browser flows pass on a clean database.
