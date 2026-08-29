# M001 — Auth / Session Action Plan

Status: `🔁 Needs Re-audit`
Audit report: `audit-report.md` (see the `RE-AUDIT — 2026-08-30` section)
Fix log: `fix-log.md`

---

## Superseded: the 2026-08-24 plan (AS-001 … AS-006) is CLOSED

All six original items were implemented by the 2026-08-25 session and are committed
(`167de85e`, with follow-ups in `38caef1f` and `3de11ba7`). The 2026-08-30 re-audit
**measured** them rather than re-reading them and confirms each works — see
`audit-report.md` §"Controls re-verified as working". Two nuances surfaced and are
re-filed below as RA-008 (AS-002's "preserve the current session" intent is not what
actually happens) and, still open from AS-001, the case-insensitive **uniqueness
constraint** for already-stored mixed-case rows, which remains a data-policy decision
and is carried forward as item 9.

---

## How cross-module risk was weighted

Every module in the system depends on M001, and two of its middleware —
`SessionTimeout` and `CheckPasswordExpiry` — are appended to the **global** API group
(`api/bootstrap/app.php:75-82`), so they execute on every authenticated request in all 17
modules. A regression here does not fail one suite, it fails the product.

That was weighted as follows, and it is the reason most items below are gated:

- **Widening enforcement is gated; narrowing an attacker-controlled escape hatch is not.**
  RA-001 was fixed in-session because the change only affects requests that carry *both* a
  valid internal cookie session *and* an `Authorization` header — a combination no
  legitimate client produces (verified: zero `Authorization` writes in
  `spa/src/api/client.ts`; the header is set only on the separate portal client instance,
  `spa/src/api/b2b/client.ts:16`). The portal pass-through it protected is preserved by
  keying on the resolved principal, and that preservation was locked with a test.
  Cross-module exposure was then *measured*, not assumed: `tests/Feature/Admin`
  (149 tests, 614 assertions) and the three portal auth suites (25 tests) were run green.
- **Anything that changes a status code or response body the SPA branches on is gated**
  (RA-005), because the consumer is every error path in the frontend.
- **Anything that changes who can authenticate, or how often, is gated** (RA-002, RA-003,
  RA-004, RA-007), because the failure mode is locking real employees out of a factory —
  an availability incident, not a cosmetic bug.
- **Additive, production-only guards are not gated** (RA-006): the new
  `ProductionAssertions` check cannot execute in dev, test, or CI, so its blast radius in
  every environment an agent can run is exactly zero.

---

## DONE this session

### 0a. RA-001 — Close the idle-timeout bypass  ✅ FIXED

- **Priority:** P1 (only `Broken` finding) · **Scope:** small · **Session recommendation:** `same-session-ok` (applied)
- **File:** `api/app/Common/Middleware/SessionTimeout.php:24-50`
- Skip idle enforcement on the **resolved principal**, not on the presence of a
  client-controlled `Authorization` header. Verified live (401 with header, was 200) and
  locked by one red-to-green test plus two pass-either-way regression locks.

### 0b. RA-006 — Assert `SESSION_SECURE_COOKIE` at production boot  ✅ FIXED

- **Priority:** P2 · **Scope:** small · **Session recommendation:** `same-session-ok` (applied)
- **File:** `api/app/Common/Support/ProductionAssertions.php:38-52`
- Production boot now refuses a non-Secure session cookie, alongside the existing
  `APP_DEBUG` / `HASHIDS_SALT` / `APP_KEY` / `SERVER_NAME` guards. Red-to-green tested.

---

## DEFERRED — execute in this order

### 1. RA-004 — Give `throttle:auth` a real per-IP ceiling

- **Priority:** P1 · **Scope:** medium · **Session recommendation:** `separate-recommended`
- **Blocked on a human decision (Q1).** Ordered first because RA-002 and RA-003 are only
  cheaply exploitable *because* of this; fixing the limiter shrinks both before either is
  touched.
- **Files/surface:** `api/bootstrap/app.php:146-147`; login/forgot/reset route definitions
  in `api/app/Modules/Auth/routes.php:17-19`; a new throttle feature test.
- **Work:** keep a tight per-identity bucket (per-account, lowercased so casing cannot mint
  a fresh one) and add a separate, looser per-IP bucket. Lowercase/normalize the email
  *before* the limiter reads it, or key the identity bucket off something the client cannot
  re-case. Apply the same shape to `forgot-password` and `reset-password`.
- **Why gated:** a naive per-IP limit puts 200+ employees behind one factory NAT into one
  bucket and denies service at shift change. The numbers are a policy call about egress
  topology this session cannot observe.
- **Acceptance:** 12 attempts from one IP across 12 distinct emails is throttled; re-casing
  an address does **not** mint a fresh bucket; a single legitimate user behind shared NAT is
  not throttled by their colleagues; 429 bodies stay generic.

### 2. RA-003 — Queue the reset mail and flatten the reset-response timing

- **Priority:** P1 · **Scope:** medium · **Session recommendation:** `separate-recommended`
- **Files/surface:** `api/app/Modules/Auth/Notifications/PasswordResetLinkNotification.php:17`;
  `api/app/Modules/Auth/Services/PasswordResetService.php:33-35,57-76`;
  `api/tests/Feature/Auth/PasswordResetTest.php`.
- **Work:** add `implements ShouldQueue` so delivery leaves the request (this alone removes
  ~3.6 s of the oracle and the inline-SMTP hold). Then make the unknown-account arm perform
  equivalent work, or shape the response to a constant floor, so the residual difference is
  noise. Keep the existing `EmailDeliveryFailureNotifier` behaviour working from the queued
  path — note that a queued notification's failure no longer surfaces inside the request, so
  the failure path needs re-checking (this is exactly the class of "caught-and-logged hides a
  dead subsystem" defect CLAUDE.md warns about).
- **Why gated:** it moves credential-recovery email onto a worker. If the worker is not
  running, password reset silently stops working — a support incident with no error visible
  to the user. Needs a deployment check, not just a code change.
- **Acceptance:** known and unknown timings are statistically indistinguishable over ≥20
  samples; the reset email still arrives with the worker running; delivery failure is still
  reported to the user and to `admin.users.manage` holders; no raw token is ever logged.

### 3. RA-002 — Remove the login timing oracle

- **Priority:** P1 · **Scope:** small–medium · **Session recommendation:** `separate-recommended`
- **Files/surface:** `api/app/Modules/Auth/Services/AuthService.php:50-119`;
  `api/tests/Feature/Auth/AuthSecurityTest.php`.
- **Work:** for the `unknown` **and** `inactive` arms, perform a `Hash::check` against a
  fixed dummy bcrypt hash of the same cost so every arm pays the same ~250 ms. Cover both
  arms — fixing only `unknown` moves the oracle to "exists but inactive".
- **Why gated (despite being additive and outcome-neutral):** it is a change inside the login
  transaction path, the single hottest authentication code path in the system, and it should
  land together with items 1 and 2 as one coherent anti-enumeration change with one timing
  test harness rather than three partial passes.
- **Acceptance:** a timed probe over ≥20 samples per arm shows overlapping distributions for
  unknown / inactive / wrong-password; every existing lockout and counter test stays green.

### 4. RA-007 — Reject the current password as the new password

- **Priority:** P1 · **Scope:** small · **Session recommendation:** `separate-recommended`
- **Blocked on a human decision (Q3)** — confirm it is drift, not policy.
- **Files/surface:** `api/app/Modules/Auth/Services/AuthService.php:191-203`;
  `api/app/Modules/Auth/Services/PasswordResetService.php:110-117`; reuse the existing
  wording and shape from `api/app/Modules/B2B/Services/PortalPasswordHistoryService.php:20-24`
  rather than inventing a second message.
- **Work:** add `Hash::check($new, $locked->password)` before the history loop on both the
  change and reset paths, and prefer extracting the portal helper so internal and portal
  share one implementation instead of two.
- **Why gated:** it changes what a user mid-forced-password-change is allowed to submit. A
  user who has been quietly satisfying the 90-day policy by re-entering the same password
  will be refused on their next attempt, so it needs to ship deliberately.
- **Acceptance:** new == current is refused on change **and** reset with the portal's wording;
  `password_changed_at` is not touched on refusal; history depth 3 still enforced; the
  portal path keeps its existing behaviour.

### 5. RA-005 — Decide and implement the lockout response contract

- **Priority:** P2 · **Scope:** medium · **Session recommendation:** `separate-recommended`
- **Blocked on a human decision (Q2).**
- **Files/surface:** `api/app/Modules/Auth/Services/AuthService.php:129-134`; the SPA login
  error branch in `spa/src/pages/auth/login.tsx`; auth feature tests.
- **Work:** either return the generic 422 for a locked account (closing the oracle, losing
  the "try again in N minutes" affordance) or keep 423 and accept the oracle as a documented
  trade-off. If 423 stays, at minimum drop the exact remaining-minutes count.
- **Why gated:** 423 is a client-visible contract; changing it without the SPA branch is a
  UX regression, and changing both is a two-surface change.
- **Acceptance:** the chosen contract is documented in `CLAUDE.md`; locked and unknown
  accounts are indistinguishable if option A is chosen; the SPA renders something useful
  either way.

### 6. RA-008 — Make the session-preservation intent match reality

- **Priority:** P3 · **Scope:** small · **Session recommendation:** `separate-recommended`
- **Files/surface:** `api/app/Modules/Auth/Services/AuthService.php:227-230`;
  `api/app/Modules/Auth/Services/SessionRevocationService.php`; `config/sanctum.php:18`;
  `api/tests/Feature/Auth/AuthEventsAuditTest.php:198-231`.
- **Work:** decide whether Sanctum's `AuthenticateSession` full-logout is the intended
  policy. If yes, drop the current-session argument and the code that computes it, and fix
  the test that claims the current session survives. If no, the session's stored password
  hash must be refreshed as part of the change.
- **Why gated:** it touches `config/sanctum.php` middleware semantics, which affects every
  authenticated request, and it decides a UX policy (does a password change sign you out?).
- **Acceptance:** the documented policy, the code, and a measured two-session test all agree.

### 7. RA-009 — Move the idle clock from the user row to the session row

- **Priority:** P3 · **Scope:** medium · **Session recommendation:** `separate-recommended`
- **Files/surface:** `api/app/Common/Middleware/SessionTimeout.php:84-105`; the `sessions`
  table; `api/tests/Feature/Auth/SessionEnforcementTest.php`.
- **Work:** derive idleness from the acting session's own `last_activity` rather than the
  shared `users.last_activity`, so one active browser cannot keep an abandoned session alive.
  Keep `users.last_activity` if other features read it — check before removing.
- **Why gated:** `SessionTimeout` is global; changing what it reads changes the logout
  behaviour of every module at once, and shop-floor PWAs are the most affected surface.
- **Acceptance:** two sessions expire independently; the once-per-minute write throttle is
  preserved; no module that reads `users.last_activity` regresses.

### 8. RA-010 + RA-011 — Two small correctness fixes with no policy content

- **Priority:** P3 · **Scope:** small · **Session recommendation:** `same-session-ok` (for a *future* session; not done here only because this session's remaining budget was spent on measurement)
- **RA-010:** add an `id` tiebreak to `api/app/Modules/Auth/Models/User.php:85` and to both
  trim queries, mirroring `PortalPasswordHistoryService.php:34-35,65-66`. Needs a
  same-second regression test to be worth anything.
- **RA-011:** replace `'min:8'` at `api/app/Modules/Auth/Requests/LoginRequest.php:27` with
  the settings-driven minimum, or drop the length rule from login entirely (login must not
  re-validate policy — it verifies a hash).
- **Acceptance:** two password changes inside one second retain the correct 3; lowering
  `security.password_min_length` to 6 does not make a valid account un-loggable-in.

### 9. AS-001 carry-forward — case-insensitive uniqueness for existing rows

- **Priority:** P2 · **Scope:** large / cross-module · **Session recommendation:** `separate-recommended`
- Still the same blocker as 2026-08-25: new writes and all lookups are canonical (verified
  this session — a legacy `Probe.MIXED@Ogami.test` row authenticates through five casings),
  but a case-insensitive unique index cannot be added until someone decides how to
  reconcile already-stored rows that collide once normalized. **Needs a human data-repair
  policy.** Do not invent a rename or an account merge.

### 10. RA-012 — Supplier portal bearer token in `sessionStorage`

- **Priority:** P2 · **Scope:** medium · **Session recommendation:** `separate-recommended` — **and owned by `supply-chain/supplier-portal`, not by M001**
- Blocked on Q6. Requires flipping the `supplier_portal` guard from `driver => sanctum` to
  `driver => session` (`config/auth.php:12-15`) so the SPA can drop the token, mirroring the
  already-correct customer portal. Files: `spa/src/api/b2b/client.ts:14-38`,
  `spa/src/api/b2b/supplier.ts:18,30`, `spa/src/api/b2b/client.test.ts:13,33,37-45`.
- Deliberately not touched by this session: another agent holds that module, and the change
  crosses the auth guard boundary.

### 11. RA-013 / RA-014 / RA-015 — Polish

- **Priority:** P3–P4 · **Scope:** small each · **Session recommendation:** `same-session-ok` individually
- **RA-013:** split the four invariant headers into a third include so the two document-view
  `location` blocks inline only `X-Frame-Options` + CSP instead of duplicating all six; add a
  header include to the port-80 server block in `docker/nginx/prod.conf:8-22`. **Re-verify
  with nginx actually running** — this session could not.
- **RA-014:** have the SPA treat timed expiry like `must_change_password` so the redirect
  happens at login rather than on the first gated fetch.
- **RA-015:** confirm `config/cors.php` is not returning a fixed `Access-Control-Allow-Origin`
  for non-matching origins.

---

## Re-audit gate

Before this module may be claimed `✅ Verified`:

1. Items 1–4 implemented, each with the measurement that proves it (a timing probe for
   RA-002/RA-003, a spray + re-case probe for RA-004, a request-level test for RA-007).
   Reading the diff is not sufficient for any of them — all four were *found* by measurement
   and none is visible from the code alone.
2. Items 5–8 either implemented or explicitly closed with a recorded human decision.
3. `tests/Feature/Auth`, `tests/Unit/Common/Support`, the three B2B portal auth suites and
   `tests/Feature/Admin` green on a **private** database — never the shared `ogami_test`.
4. `nginx` brought up so RA-013 can be verified against live response headers.
5. Q1–Q6 in `audit-report.md` answered, and the stale CLAUDE.md "HasAuditLog + custom guards"
   section (which names an `EdgeSystemUserResolver` and an `auth:edge_device` guard that do
   not exist) corrected or deleted.
6. `fix-log.md` updated with file:line before/after evidence per item, written as each item
   lands rather than at the end.
