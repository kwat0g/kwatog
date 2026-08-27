# M035 — Customer Portal action plan

Plan status: `📋 Plan Ready`
Gate result: **do not implement in this audit session**. The open work is
mostly API/security/data-contract work, the majority is `separate-recommended`,
and the total scope is not small.

Ordering is deliberate: seal the response/auth boundary first, then make writes
durable and bounded, then align decimal/UI contracts and browser evidence.

## 1. Replace the internal sales-order resource at the customer boundary

- Findings: F-19.
- Scope: **large**.
- Session: **separate-recommended**.
- Work: add a customer-specific sales-order resource/DTO for dashboard, list,
  detail, and any linked order payload. Allowlist customer-relevant order/item
  fields; remove internal notes, edit/cancel capabilities, transition metadata,
  MRP/work-order/inspection projections, deleted state, and unrelated internal
  timestamps. Keep HashIDs and customer ownership checks.
- Acceptance: customer responses contain only the documented customer contract;
  tests assert forbidden internal fields are absent for dashboard/list/detail and
  linked data remains available where intentionally supported.

## 2. Give customer sessions a safe currency-policy contract

- Findings: F-21.
- Scope: **medium**.
- Session: **separate-recommended**.
- Work: either expose only the functional currency through a route protected by
  the customer portal guard or remove the customer policy query and provide a
  server-owned portal currency field. Do not grant the customer session access
  to the internal `/business-policies` response.
- Acceptance: a real customer session receives the intended currency without a
  401; customer monetary UI displays the configured code; internal policy fields
  remain unavailable; browser mocks cover the same contract rather than hiding
  the route mismatch.

## 3. Complete complaint product provenance

- Findings: F-11.
- Scope: **large**.
- Session: **separate-recommended**.
- Work: decide the product-selection contract with CRM. Add a customer-owned
  order-item/product options response, validate the selected HashID against the
  selected order and customer inside the write path, and persist `product_id`
  through the complaint/NCR handoff. Preserve the current order ownership and
  cancelled-order checks.
- Acceptance: a complaint on a multi-product order identifies the affected
  product; foreign order/item IDs are rejected; unlinked complaints are either
  explicitly supported and documented or disallowed by the UI/API contract.

## 4. Make portal complaint audit attribution atomic or durably recoverable

- Findings: F-20 (and the attribution portion of F-12).
- Scope: **medium**.
- Session: **separate-recommended**.
- Work: move the external actor audit event into the complaint transaction, or
  introduce a durable post-commit outbox/retry path. Preserve the internal
  `created_by` foreign key while retaining portal user HashID, email, request ID,
  actor type, and request metadata.
- Acceptance: an audit persistence failure cannot silently leave an untraceable
  committed complaint; retry behavior is deterministic and duplicate complaint
  creation is tested.

## 5. Bound and throttle customer schedule writes

- Findings: F-23.
- Scope: **medium**.
- Session: **separate-recommended**.
- Work: cap the number of schedule lines, enforce a documented quantity scale and
  maximum, validate a supported month horizon, and add a per-account/IP throttle
  for complaint and schedule mutations. Keep the existing fingerprint and
  unique-index conflict behavior.
- Acceptance: oversized/over-precision/out-of-range payloads return stable 422s;
  repeated mutation attempts are rate limited; same-payload retries still replay
  and conflicting same-month submissions still return the documented conflict.

## 6. Align decimal quantity serialization and TypeScript contracts

- Findings: F-22.
- Scope: **small**.
- Session: **separate-recommended** because it changes a shared API contract.
- Work: return decimal quantities as strings at the customer delivery boundary,
  change `PortalSoItem`, invoice-item, and delivery-item types to the canonical
  decimal representation, and use the quantity formatter for display. Add API
  resource/type contract assertions.
- Acceptance: no customer decimal quantity is coerced through PHP/JS floating
  point merely for transport; API payloads and SPA types agree for order,
  invoice, and delivery detail.

## 7. Add complete executable customer browser coverage

- Findings: F-17.
- Scope: **large**.
- Session: **separate-recommended**.
- Work: extend the existing Playwright customer fixture to cover real response
  shapes and the customer-only boundary for orders, invoices, deliveries/proof,
  SOA, complaints/8D, schedules, feature-off behavior, forced password change,
  logout, loading/error/empty states, and desktop/mobile navigation. Include a
  customer-vs-supplier/internal cross-guard assertion.
- Acceptance: the role matrix has executable evidence, the business-policy
  contract is not mocked into an impossible 200, and the suite runs in a
  writable test-artifact directory.

## 8. Correct local month formatting

- Findings: F-24.
- Scope: **small**.
- Session: **same-session-ok** (after the contract work above is scheduled).
- Work: derive `YYYY-MM` from local year/month components instead of converting
  local midnight through `toISOString()`; add a positive-UTC-offset boundary
  test.
- Acceptance: the first selectable month is the current local month at local
  midnight and the six options remain consecutive across timezone edges.

## Handoff gate

Do not fix these findings in the audit session. The only same-session candidate
is the isolated timezone polish item; all security, response, transaction,
input, and contract work is separate-recommended, so the majority/small-scope
gate is not met. After F-19 and F-21 are approved and implemented, rerun the
focused API suites on a unique database and run the browser suite before moving
M035 to verified.
