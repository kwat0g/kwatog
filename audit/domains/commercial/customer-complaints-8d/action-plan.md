# M034 — Customer complaints / 8D re-audit action plan

Date: 2026-08-27
Status: 🔁 Needs Re-audit  
Plan basis: current worktree after the 2026-08-24 report

The earlier plan's P1 integrity and portal-boundary work is present in the
current code. R01-R03 and the three additional executable findings R08-R10 are
fixed and verified; R04-R05 require explicit policy decisions and are not
guessed.

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
  no-recipient retry. The focused sweep ran on the isolated M034 database;
  two-worker concurrency remains a dedicated follow-up.

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

### 4. M034-R04 — Decide lifecycle policy and implement or retire unused states

- Classification/severity: Missing, P2
- Scope: small/medium; lifecycle, audit field, permission, UI, and tests
- Session recommendation: separate-recommended
- Requires a product/quality decision about whether investigation should be an
  explicit transition and whether a complaint can be cancelled, why, who may
  do it, and what the customer portal should display. This session defers the
  item pending that decision.

### 5. M034-R05 — Split internal complaint permissions if required

- Classification/severity: Incomplete, P2
- Scope: medium; RBAC seeder, routes, UI gates, and matrix tests
- Session recommendation: separate-recommended
- Requires an explicit role/action matrix for view, create/update, 8D
  finalize, lifecycle, NCR retry, and PDF. This session defers the item
  pending RBAC-owner input.

### 6. M034-R08 — Repair complaint update email rendering — completed

- Classification/severity: Broken, P1
- Scope: small; complaint listener, mailable view, and regression tests
- Session recommendation: same-session-ok
- Replaced invalid `label()` calls on the complaint status and shared severity
  enums with value-based `Str::headline` formatting. Both the valid-email
  mailable render and missing-email internal fallback are covered by
  `ComplaintEmailTest`.

### 7. M034-R09 — Fail closed on invalid internal customer filters — completed

- Classification/severity: Incomplete, P2
- Scope: small; internal list controller and API regression test
- Session recommendation: same-session-ok
- An invalid supplied customer hash now becomes an impossible filter instead of
  being decoded to `null` and silently broadening the list.

### 8. M034-R10 — Align the NCR list/UI completion contract — completed

- Classification/severity: Incomplete, P2
- Scope: small; CRM list projection/resource and complaint detail type/gate
- Session recommendation: same-session-ok
- List responses now select and expose NCR severity/disposition, while the
  internal lifecycle affordance checks the disposition required by the server.

## Already addressed and requiring regression verification

- R01-R03 and R08-R10: focused assertions executed successfully on the
  isolated M034 database.
- F01/F03: authoritative complaint/report locks and lifecycle transition matrix.
- F02: finalized-8D plus closed/dispositioned-NCR completion gate.
- F04/F07: customer-safe portal resource, finalized-only portal report, and
  finalized-only internal PDF.
- F06: request-boundary and transaction-time source/assignee validation.
- F10/F11: searchable source selectors, explicit portal product contract, and
  bounded portal complaint history.
- F12: restrictive complaint/customer foreign key migration and regression test.

## Verification constraint

The focused feature suite passed on isolated PostgreSQL database
`ogami_test_m034_20260827`: 62 tests and 205 assertions. SPA typecheck and
scoped ESLint also passed. A full browser/e2e run and multi-worker concurrency
run remain outside this session. Laravel Pint is not clean on pre-existing
formatting drift in several legacy files touched by the module; no unrelated
formatter rewrite was included.

## Definition of done for the next release

- SLA delivery ledger is atomic, retryable, and covered by failure/retry tests;
  multi-worker concurrency still needs a dedicated environment.
- Cancellation and RBAC decisions are documented and either implemented or
  removed from the public contract.
- The focused CRM/B2B suite and browser flows pass on a clean database.
