# M035 — Customer Portal action plan

Plan status: `📋 Plan Ready`  
Ordering: security/data boundary first, then correctness and scale, then UX polish.

## 1. Replace portal bearer tokens with the repository auth contract

- Findings: F-01.
- Scope: **large**.
- Session: **separate-recommended**.
- Work: migrate customer portal login/logout/me/change/reset flows to stateful HTTP-only cookie authentication; align the guard and CSRF behavior with the existing SPA stack; remove browser token persistence and update the client/tests. Preserve the dedicated customer guard and cross-guard denial tests.
- Acceptance: no portal token is readable from JavaScript or browser storage; authenticated customer requests use the cookie path; login, logout, expiry, reset, inactive-account, and cross-guard tests pass.

## 2. Seal the customer-facing response boundary and repair order detail relations

- Findings: F-02, F-03, F-12.
- Scope: **medium**.
- Session: **separate-recommended** because this changes API contracts and audit attribution.
- Work: serialize dashboard deliveries/complaints with safe resources or dedicated DTOs; include relationship foreign keys in order detail eager loads; establish one customer-user response shape instead of the unused/stale `CustomerPortalUserResource`; add an external portal actor/source field or equivalent durable audit metadata for complaint creation.
- Acceptance: no raw internal model attributes or integer IDs appear in customer responses; order detail fixtures include related delivery/invoice/work-order data; login/me/resource TypeScript shapes agree; complaints retain portal submitter traceability.

## 3. Bring portal password controls and concurrency into the security policy

- Findings: F-04, F-05, F-10.
- Scope: **large**.
- Session: **separate-recommended**.
- Work: make an explicit policy decision for portal password history/age; implement history writes/checks and expiry for both portal models if in scope; normalize email addresses consistently; serialize failed-attempt counters and one-session token replacement with row locks or guarded atomic updates.
- Acceptance: portal change/reset rejects configured recent passwords, expired portal passwords are gated, mixed-case login behaves consistently, concurrent lockout/token tests are deterministic, and audit logs retain request/user context.

## 4. Correct financial arithmetic and validate all portal query inputs

- Findings: F-07, F-08.
- Scope: **medium**.
- Session: **separate-recommended** because financial correctness is involved.
- Work: replace float conversion with exact `Money`/decimal-string accumulation; add typed query validation for status, search length, positive page size, and ISO date; decide and enforce whether future `as_of` values are allowed; return stable 4xx validation errors.
- Acceptance: rounding/boundary tests match SOA totals; malformed dates and invalid page sizes never reach a 500; API docs/types reflect the validated envelope.

## 5. Define schedule idempotency and complaint traceability contracts

- Findings: F-06, F-11, F-12.
- Scope: **large**.
- Session: **separate-recommended**.
- Work: define whether a second same-month schedule with a different payload is a conflict, replacement, or update; use an idempotency key/fingerprint and handle the unique-index race. Add customer-owned order/product selectors to the complaint form, validate HashIDs, and retain the external actor metadata through CRM/NCR handoff.
- Acceptance: same request retries are safe, changed requests have documented deterministic behavior, concurrent first submissions have a stable response, and complaint records are linked to the selected order/product when supplied.

## 6. Make growing portal lists paginated end to end

- Findings: F-13.
- Scope: **large**.
- Session: **separate-recommended**.
- Work: standardize a portal pagination envelope; paginate deliveries, complaints, and schedules as well as orders/invoices; expose metadata in the SPA API types; add page-size/status/search controls where appropriate and a design-system pagination footer.
- Acceptance: no customer list performs an unbounded collection read; pages retain query state, show total/page controls, and remain usable on narrow screens.

## 7. Improve proof delivery and feature-gate behavior

- Findings: F-09, F-18.
- Scope: **small to medium**.
- Session: **same-session-ok** after the auth/data contract work; otherwise separate.
- Work: stream proofs from storage without loading the whole file, encode the download filename safely, and decide whether the public auth/reset routes should also be disabled by `b2b_portals`.
- Acceptance: large proof responses stay bounded in memory, filenames cannot alter headers, and the disabled-feature response is consistent for public and authenticated portal routes.

## 8. Close frontend information and accessibility gaps

- Findings: F-14, F-15, F-16.
- Scope: **small to medium**.
- Session: **same-session-ok**.
- Work: either render or remove recent delivery/complaint dashboard data; show scheduled dates for undelivered records; restore keyboard reachability for the password visibility button; add component assertions for these states.
- Acceptance: dashboard/API fields are intentional, delivery dates are meaningful for every status, and keyboard-only login can operate all controls.

## 9. Add customer portal browser regression coverage

- Findings: F-17.
- Scope: **large**.
- Session: **separate-recommended**.
- Work: add Playwright fixtures and flows for login/logout, forced password change, customer-only ownership, cross-guard rejection, orders/invoices/deliveries/proof/SOA/complaints/schedules, responsive layouts, loading/error/empty states, and feature-disabled behavior.
- Acceptance: the role matrix and defense traceability entries have executable browser evidence, with no supplier/employee data visible to a customer account.

## Session recommendation

Do not start implementation in the audit session. F-01, F-04, F-07, F-11, and F-12 cross security, financial, or module boundaries; F-03, F-05, F-06, F-08, F-13, and F-17 also need coordinated tests. After those contracts are approved, bundle F-14–F-16 as a small UX pass.
