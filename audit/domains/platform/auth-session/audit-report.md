# M001 — Auth / Session Audit Report

- Audit date: 2026-08-24 (Asia/Manila; registry generated 2026-08-23 UTC)
- Domain/module: `platform / auth-session`
- Tier/surface: Tier 1 / M
- Session result: `📋 Plan Ready`
- Scope: internal SPA authentication and sessions, password policy/history/reset, lockout, auth middleware, RBAC entry points, B2B customer/supplier authentication, and the corresponding frontend flows.
- Boundary: adjacent Admin, HR, B2B, Common, and deployment files were read where they participate in the auth contract; no implementation files outside this audit module were changed.

## Executive result

The internal cookie-auth path has strong foundations: authoritative row locking for internal login and reset-token consumption, password history, session timeout/password-expiry middleware, CSRF-aware SPA wiring, generic reset responses, and focused regression coverage. The B2B bearer path also revokes portal tokens on password changes/resets and rejects portal principals from internal routes.

Six findings remain:

1. **Broken / P1:** internal user email canonicalization is inconsistent, so accounts created with mixed-case email can become unable to log in; the reset contract also rejects valid addresses longer than 150 characters.
2. **Incomplete / P1:** internal password change and reset do not revoke existing database-backed web sessions.
3. **Broken / P1:** B2B lockout counter mutations are not serialized with a row lock/transaction, unlike the internal path.
4. **Incomplete / P2:** the internal “Remember me” control is sent by the SPA but ignored by the API.
5. **Incomplete / P2:** self-service password-reset events are written to the auth log channel but are not mirrored to the Admin Audit Log, and the same gap exists for B2B password change/reset events.
6. **Polish / P2:** password-visibility buttons are removed from keyboard tab order on internal and portal login/change-password screens.

No production code was changed in this session. The action plan is intentionally separate-session for the policy-sensitive and cross-module items; the accessibility item is small and same-session-ok, but was deferred with the rest because it is not the dominant work.

## Discovery

### Backend surface

- Internal public routes are login, forgot-password, reset-password, and password-policy; authenticated routes cover logout, change-password, current user, preferences, notifications, and notification preferences in `api/app/Modules/Auth/routes.php:15-66`.
- All module routes are mounted under `/api/v1` in `api/app/Providers/ModuleServiceProvider.php:23-64`. Global API middleware adds request IDs, JSON/input hygiene, session-timeout/password-expiry enforcement, API throttling, and slow-query logging in `api/bootstrap/app.php:39-78`.
- Named limiters cover login (`5/minute` by IP and email), authenticated API traffic, and sensitive operations in `api/bootstrap/app.php:141-157`.
- Internal auth uses the web session guard through Sanctum SPA cookie mode (`api/config/auth.php:9-23`, `api/config/sanctum.php:7-29`, `api/config/session.php:5-21`). The database session table stores a nullable `user_id` and indexed activity timestamp (`api/database/migrations/0006_create_sessions_table.php:13-20`).
- Internal identity state is split across `users`, `password_histories`, `password_reset_requests`, `sessions`, and login history. Password history and reset-request persistence are present in the Auth migrations and services.
- Customer and supplier portals use separate Sanctum bearer guards and `HasApiTokens` models (`api/app/Modules/B2B/Models/CustomerPortalUser.php:14-31`, `api/app/Modules/B2B/Models/SupplierPortalUser.php:14-31`). Their public login/logout/reset routes and authenticated tenant-scoped routes are defined in `api/app/Modules/B2B/routes.php:18-99`.

### Frontend surface

- The internal API wrapper obtains the CSRF cookie before login and keeps the session cookie on the shared Axios client (`spa/src/api/auth.ts:50-69`, `spa/src/api/client.ts:1-31`).
- `authStore` bootstraps `/auth/user`, clears cache and local drafts on logout, applies server permissions/features/theme, and drives the internal guards (`spa/src/stores/authStore.ts:20-85`, `spa/src/components/guards/AuthGuard.tsx:11-36`, `spa/src/components/guards/GuestGuard.tsx:17-32`).
- Internal auth pages are login, forgot-password, reset-password, and forced change-password. Portal login/reset/change-password pages use separate token clients with session-storage token isolation (`spa/src/api/b2b/client.ts:1-39`, `spa/src/api/b2b/customer.ts:20-68`, `spa/src/api/b2b/supplier.ts:17-65`).
- The auth screens use Atelier tokens, serif display headings, responsive `AuthLayout`/`Panel` composition, and reduced-motion handling. The primary design-system deviation found in this pass is keyboard reachability of the password toggles, documented below.

### Role and authorization surface

- Internal authorization is permission-shaped and role-aware: `system_admin` is a Gate escape hatch and ordinary abilities resolve through `User::hasPermission` (`api/app/Providers/AuthServiceProvider.php:18-42`, `api/app/Modules/Auth/Models/User.php:105-155`).
- Authenticated routes use `auth:sanctum`, `session.timeout`, `password.expired`, portal guards, feature gates, and permission middleware. `SessionTimeout` and `CheckPasswordExpiry` deliberately distinguish internal cookie requests from bearer-token portal traffic (`api/app/Http/Middleware/SessionTimeout.php:24-105`, `api/app/Http/Middleware/CheckPasswordExpiry.php:20-86`).

## Findings

### AS-001 — Internal email identity contract is inconsistent

- **Classification:** Broken (login); Incomplete (password recovery)
- **Priority:** P1
- **Scope:** Large / cross-module
- **Evidence:**
  - The admin create-user request accepts an email up to 255 characters but does not normalize it, and its payload returns the validated value unchanged (`api/app/Modules/Admin/Requests/CreateUserRequest.php:17-40`). `UserAdminService` stores that value directly (`api/app/Modules/Admin/Services/UserAdminService.php:93-108`).
  - HR account provisioning has the same shape: no normalization in `api/app/Modules/HR/Requests/ProvisionAccountRequest.php:17-39`, then raw storage in `api/app/Modules/HR/Services/UserProvisioningService.php:51-65`.
  - Login lowercases/trims the submitted email (`api/app/Modules/Auth/Requests/LoginRequest.php:16-20`), then `AuthService` performs an exact `where('email', $email)` lookup (`api/app/Modules/Auth/Services/AuthService.php:47-52`). A user created as `Case.User@company.test` therefore cannot authenticate through the normal login request, which searches for `case.user@company.test`.
  - The users table allows the normal 255-character string length (`api/database/migrations/0004_create_users_table.php:13-17`), while forgot-password caps the same field at 150 characters and has no normalization hook (`api/app/Modules/Auth/Requests/ForgotPasswordRequest.php:16-20`). The reset service also performs an exact email lookup (`api/app/Modules/Auth/Services/PasswordResetService.php:24-29`).
- **Impact:** An otherwise valid account can be created but become unreachable through login when its stored case differs. Valid long email addresses accepted during account creation/login cannot use the recovery path. The inconsistency also makes the recovery behavior depend on the exact casing the user happens to submit.
- **Recommendation:** Establish one canonical email contract at the identity boundary: trim/lowercase every internal-user write path (or normalize in a shared `User` model hook), use the same RFC/length limit for login and recovery, and enforce case-insensitive uniqueness at the database/validation layer. Add mixed-case provisioning/login, mixed-case reset, duplicate-case, and 150–255-character boundary tests. This crosses Auth, Admin, HR, and schema policy, so it is separate-session recommended.

### AS-002 — Internal password changes do not revoke existing web sessions

- **Classification:** Incomplete
- **Priority:** P1
- **Scope:** Medium / security-sensitive
- **Evidence:**
  - `AuthService::changePassword` updates password history, password hash, timestamps, and the forced-change flag inside a transaction, but has no session deletion or token/session-version invalidation (`api/app/Modules/Auth/Services/AuthService.php:183-223`).
  - Self-service reset similarly changes the password and consumes the reset token but does not touch the `sessions` table (`api/app/Modules/Auth/Services/PasswordResetService.php:80-151`).
  - The application has a database-backed session store with user ownership (`api/config/session.php:5-15`, `api/database/migrations/0006_create_sessions_table.php:13-20`), and the account-deactivation paths explicitly delete user sessions (`api/app/Modules/Admin/Services/UserAdminService.php:132-140`, `api/app/Modules/HR/Services/UserProvisioningService.php:112-119`). This shows session revocation is an established security operation, but it is absent from credential mutation.
  - Portal bearer flows are different and already revoke portal tokens on change/reset (`api/app/Modules/B2B/Services/PortalPasswordService.php:34-43`, `api/app/Modules/B2B/Services/PortalPasswordResetService.php:81-94`); this finding is for internal users and their web sessions.
- **Impact:** A previously authenticated internal browser remains usable after a password change or password reset. If the old session was copied or left open on a shared device, changing the password does not remove that access.
- **Recommendation:** Decide whether a self-service change preserves only the current session or forces a full re-login. Then implement one centralized revocation primitive for reset/change, delete or invalidate all other `sessions` rows atomically, handle the current session deliberately, and cover two-browser/session tests plus reset-link replay. Also define the behavior if persistent login is later enabled. Separate-session recommended because this changes authentication state and current-user UX.

### AS-003 — B2B lockout counter is not serialized

- **Classification:** Broken
- **Priority:** P1
- **Scope:** Medium / security-sensitive
- **Evidence:**
  - Both portal controllers delegate login to the shared `B2bAuthService` (`api/app/Modules/B2B/Controllers/CustomerAuthController.php:42-56`, `api/app/Modules/B2B/Controllers/SupplierAuthController.php:42-56`).
  - `B2bAuthService` reads the user without a transaction or `lockForUpdate`, increments `failed_login_attempts`, and saves the whole stale model (`api/app/Modules/B2B/Services/B2bAuthService.php:54-98`). A successful login resets the same fields without a serialized authoritative read (`api/app/Modules/B2B/Services/B2bAuthService.php:90-98`).
  - The internal Auth service uses a transaction and authoritative user-row lock for this exact decision (`api/app/Modules/Auth/Services/AuthService.php:47-52`), and its two-connection threshold harness exists (`api/tests/Feature/Auth/LoginThresholdTwoConnectionHarnessTest.php:1-64`). Portal tests only exercise sequential attempts (`api/tests/Feature/B2B/SupplierPortalAuthTest.php:96-122`, `api/tests/Feature/B2B/CustomerPortalAuthTest.php:119-143`).
- **Impact:** Concurrent portal failures can overwrite one another, delaying or bypassing the five-strike lockout. A failure racing a successful login can also write a stale counter after the success reset. The existing `throttle:auth` limit is not a substitute for authoritative per-account mutation serialization.
- **Recommendation:** Mirror the internal lockout primitive for both portal model classes: lock the authoritative row inside a transaction, evaluate active/locked/password state and counter mutation from that row, then issue/revoke tokens after the committed success decision. Add independent-connection race tests for supplier and customer audiences, including failure/failure and success/failure interleavings. Separate-session recommended.

### AS-004 — Internal “Remember me” is a non-functional control

- **Classification:** Incomplete
- **Priority:** P2
- **Scope:** Medium / authentication policy
- **Evidence:**
  - The internal SPA schema, form defaults, submit payload, and rendered checkbox all expose `remember` (`spa/src/pages/auth/login.tsx:23-27`, `spa/src/pages/auth/login.tsx:124-135`, `spa/src/pages/auth/login.tsx:245-251`). The API type also sends it (`spa/src/api/auth.ts:32-55`).
  - The backend request validates only email and password (`api/app/Modules/Auth/Requests/LoginRequest.php:23-28`), the controller passes only those two values (`api/app/Modules/Auth/Controllers/LoginController.php:34-42`), and `AuthService::login` has no remember parameter and calls `Auth::login($user)` (`api/app/Modules/Auth/Services/AuthService.php:34-35`, `api/app/Modules/Auth/Services/AuthService.php:142-145`).
  - The web session default is a 30-minute lifetime with `expire_on_close=false`, but no persistent-login behavior is connected to the checkbox (`api/config/session.php:5-9`).
- **Impact:** Users are told they can persist their sign-in choice, but the server ignores it. The UI therefore creates an inaccurate expectation about session duration and security behavior.
- **Recommendation:** Make an explicit product/security decision: either remove the checkbox and payload, or implement Laravel remember semantics with a documented lifetime, secure cookie policy, logout/revocation behavior, and browser-restart/expiry tests. Separate-session recommended because it changes credential persistence semantics.

### AS-005 — Password-reset audit trail is incomplete

- **Classification:** Incomplete
- **Priority:** P2
- **Scope:** Medium / auditability
- **Evidence:**
  - Internal password change calls `logAuthEvent`, which writes both the auth channel and an `audit_logs` mirror (`api/app/Modules/Auth/Services/AuthService.php:222-257`).
  - Internal self-service reset records only `Log::channel('auth')->info('password.reset', ...)` after the transaction (`api/app/Modules/Auth/Services/PasswordResetService.php:146-150`); the reset-request event is likewise only an auth-channel log (`api/app/Modules/Auth/Services/PasswordResetService.php:73-77`).
  - The B2B password-change service emits only an auth-channel event, while the B2B reset service updates the credential/token state without an Admin Audit Log mirror (`api/app/Modules/B2B/Services/PortalPasswordService.php:45-51`, `api/app/Modules/B2B/Services/PortalPasswordResetService.php:51-94`).
  - The current audit test explicitly verifies the `password.changed` database row but has no reset-event assertion (`api/tests/Feature/Auth/AuthEventsAuditTest.php:177-196`).
- **Impact:** The Admin Audit Log cannot provide a complete credential-change/recovery timeline. File logs may be rotated or unavailable to an administrator reviewing an account takeover or reset incident.
- **Recommendation:** Define event names and actor/target semantics for reset-requested, reset-completed, reset-rejected/replayed where appropriate, and portal password changes. Mirror them to `audit_logs` without storing secrets or raw reset tokens. Add success, invalid/replay, inactive-account, and delivery-failure audit tests. Separate-session recommended because it touches the common audit contract.

### AS-006 — Password visibility controls are not keyboard reachable

- **Classification:** Polish / accessibility
- **Priority:** P2
- **Scope:** Small
- **Evidence:**
  - Internal login uses an `aria-label`d button with `tabIndex={-1}` (`spa/src/pages/auth/login.tsx:222-231`).
  - Internal change-password applies the same pattern to all three toggles (`spa/src/pages/auth/change-password.tsx:93-110`).
  - Portal login repeats it (`spa/src/pages/portal/PortalLoginPage.tsx:201-210`).
- **Impact:** Keyboard-only users can reach the password input but cannot reach the control that reveals/hides the password. The accessible name is present, but the control is excluded from normal keyboard navigation.
- **Recommendation:** Remove `tabIndex={-1}` or provide an equivalent keyboard-reachable control, retain the accessible name, and add a focused keyboard interaction test. Same-session-ok and small, but deferred in this audit because the larger auth/security items are separate-session work.

## Controls found working or substantially covered

- Internal login decisions are serialized on a locked user row before session issuance; failed-attempt, lockout, expired-lock, success-reset, and stale-snapshot cases are covered in `api/app/Modules/Auth/Services/AuthService.php:34-166` and `api/tests/Feature/Auth/AuthSecurityTest.php:86-205`.
- Reset-token consumption and password mutation are in one locked transaction, with token single-use and password-history checks (`api/app/Modules/Auth/Services/PasswordResetService.php:86-143`, `api/tests/Feature/Auth/PasswordResetTest.php:87-237`). Current lifecycle evidence marks the historical F-003/F-004 concurrency findings verified; this audit did not reopen those internal fixes.
- Session timeout and password expiry fail closed for internal requests and do not treat portal bearer principals as internal users (`api/app/Http/Middleware/SessionTimeout.php:24-105`, `api/app/Http/Middleware/CheckPasswordExpiry.php:20-86`, `api/tests/Feature/Auth/SessionEnforcementTest.php:1-200`); the portal cross-guard regression suite covers the 401/no-leak behavior (`api/tests/Feature/B2B/PortalTokenCrossGuardTest.php:43-103`).
- CSRF/stateful-cookie configuration uses secure, HTTP-only, SameSite=Lax defaults (`api/config/session.php:15-21`, `api/config/cors.php:1-28`), and the deployment runbook documents the required Origin/Referer headers rather than treating bare curl as a valid browser smoke test (`docs/DEPLOY.md:248-284`).
- Reset responses are generic for unknown accounts (`api/app/Modules/Auth/Controllers/ForgotPasswordController.php:15-21`, `api/app/Modules/Auth/Services/PasswordResetService.php:24-30`), and login, reset, and sensitive routes are throttled.
- B2B login issues one token per session and revokes prior tokens (`api/app/Modules/B2B/Services/B2bAuthService.php:90-103`); inactive portal accounts are rejected and their tokens are deleted by `EnsurePortalGuard` and the cross-guard tests.
- The current deployment findings F-056/F-057 are marked verified in `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json:57-58`; the production template now declares the admin/company settings keys. They were treated as historical verified context, not re-filed as current module defects.
- No MFA/2FA implementation was found in the repository auth surface. This is recorded as a policy question, not a scored finding, because no MFA requirement was provided in the module contract.

## Verification performed

| Check | Result |
|---|---|
| Registry regeneration and atomic claim | Passed; `M001 platform/auth-session` claimed with `./audit/scripts/claim-module.sh platform auth-session`. |
| Internal route inventory | Passed; `php artisan route:list --path=api/v1/auth --except-vendor` listed the expected eight auth routes. |
| Backend focused feature tests | **Blocked by environment**; `php artisan test tests/Feature/Auth tests/Feature/B2B/PortalTokenCrossGuardTest.php` reached test setup but all 64 tests failed before assertions because PostgreSQL host `db` could not resolve (`SQLSTATE[08006]`, database `ogami_test`). No containers were running. |
| SPA Vitest | Passed; `NODE_OPTIONS='--import=data:text/javascript,globalThis.__dirname=process.cwd()' npm run test:run -- --configLoader runner` — 35 files, 253 tests. |
| SPA typecheck | Passed; `npm run typecheck`. |
| SPA lint | Passed; `npm run lint`. |
| Browser/visual QA | Not run; this session used static inspection and unit/component coverage. Narrow-width authenticated visual evidence remains a follow-up. |

The Vitest config-loader workaround was needed because this checkout's ESM Vite config references `__dirname`; the runner avoided changing the repository or the root-owned `spa/node_modules/.vite-temp` directory.

## Open policy questions

1. Should internal password change preserve the current session while revoking all other sessions, or force a full re-login? Should reset always revoke every internal session?
2. Should “Remember me” be implemented with a persistent secure cookie, or removed from the UI?
3. Is MFA/2FA required for system administrators, finance, or all internal roles? No implementation was found, but the requirement is not stated in this module contract.

## Release decision

`📋 Plan Ready`. No same-session implementation was applied. Release is appropriate because the majority of the findings involve authentication policy, concurrent state mutation, audit-contract changes, or cross-module normalization. The module must be re-audited after the ordered action plan is implemented and the backend PostgreSQL test environment is available.

---
---

# RE-AUDIT — 2026-08-30

- Audit date: 2026-08-30 (Asia/Manila)
- Domain/module: `platform / auth-session` (M001, Tier 1, surface M)
- Claim: **RECLAIMED** — the lock was an orphan from `2026-08-25T14:40:07Z` (103 h old),
  owner string `codex-coordinator-blocker-quarantine`.
- Session result: `🔁 Needs Re-audit` — 2 findings fixed, 10 deferred, 1 handed to another module.
- Method: **measured, not read.** A live HTTP server was stood up against a dedicated database and
  every control below was driven with real requests. Source reading was used only to locate the
  mechanism behind a measured behaviour.

## State of the tree at reclaim (the orphan lock did NOT mean nothing happened)

Checked before trusting the status: `fix-log.md` was **not** a blank scaffold — the 2026-08-25
session had written a full log of six applied fixes. Those fixes are **committed**, not dangling in
the working tree:

- `git log` for the module paths shows them landing in `167de85e` ("remaining uncommitted work from
  ~50 crashed audit sessions"), with auth follow-ups in `38caef1f` and `3de11ba7`.
- Every file the log claims exists (`SessionRevocationService.php`, `AuthAuditLogger.php`,
  `LoginThresholdTwoConnectionHarnessTest.php`, …).
- `git status --short` showed **no** dirty files for this module. The only dirty files in the tree
  belong to the concurrent `supply-chain/supplier-portal` session and were left untouched.
- File mtimes (`2026-08-26 02:30` … `2026-08-27 03:36`) postdate the lock's `claimed-at`, consistent
  with the later commits rather than with abandoned work.

The 2026-08-25 migration blocker is **resolved**: `php artisan migrate` now runs to completion on a
fresh database (verified end-to-end; last migration
`2026_08_26_040000_harden_return_request_status_and_allocations`).

So this pass re-verified the previous fixes empirically rather than re-filing them, and spent its
effort on controls the earlier sessions had asserted from configuration rather than measured.

## Harness (stated so the numbers can be trusted or challenged)

- Database: `ogami_probe_m001` (live probe) and `ogami_test_m001_ra` (PHPUnit). Never the shared
  `ogami_test`. Both dropped at the end.
- Server: `php -S` inside the `api` container against `public/index.php`, port-mapped to
  `127.0.0.1:18000`. Probes send `Origin: http://localhost` so Sanctum treats them as frontend
  traffic; the decoded `XSRF-TOKEN` cookie is replayed as `X-XSRF-TOKEN`, exactly as Axios does.
- **`php artisan serve` is unusable for this kind of probe** and cost this session real time: its
  `ServeCommand` passes an env allow-list to its `php -S` child, so `-e DB_DATABASE=…` is dropped and
  every request silently hits the dev `ogami` database instead. Two `login_history` rows
  (`unknown_email`) and one session row were written to dev `ogami` before this was caught; nothing
  else touched it. Recorded here so the next session skips `artisan serve` entirely.
- **Timezone trap in the harness, not in the product.** `config('app.timezone')` is `Asia/Manila`
  (`config/app.php:15`) while the DB and containers run UTC, and `users.last_activity` is
  `timestamp without time zone`. Back-dating that column with SQL `now() - interval` therefore skews
  by 8 h and makes every session look expired. All idle-timeout numbers below were re-measured with
  the column written through the app's own clock. The application itself is self-consistent (writes
  and reads both go through Carbon in app time), so this is a probe artifact — but see Q4.
- nginx was **not running** (only `db` and `redis` are up for the pipeline). No claim is made here
  about live header delivery; the nginx findings are source-file findings only.

## Security checklist actually executed

| # | Control | Measured result | Probe |
|---|---|---|---|
| 1 | Session cookie `HttpOnly` | **PASS** — `ogami_erp_session=…; path=/; httponly; samesite=lax` | `Set-Cookie` on `GET /sanctum/csrf-cookie` and on login |
| 2 | Session cookie `Secure` | **absent in dev** (`api/.env:57` `SESSION_SECURE_COOKIE=false`); `config/session.php:17` defaults to `true`; `.env.production.example:35` sets `true` | same `Set-Cookie` inspection + env template review |
| 3 | Session cookie `SameSite` / `Path` | **PASS** — `samesite=lax`, `path=/` | same |
| 4 | `XSRF-TOKEN` readable by JS, session cookie not | **PASS by design** — `XSRF-TOKEN` has no `httponly`; session cookie does | same |
| 5 | No auth material in `localStorage`/`sessionStorage` (internal SPA) | **PASS** — `authStore` has no `persist`; only non-auth keys (`ogami:formdraft:*`, `ogami:table-prefs`, `ogami:recent-items`, cookie-consent) | repo-wide grep of `spa/src` + `spa/e2e` |
| 6 | No Bearer tokens (internal SPA) | **PASS** — zero `Authorization`/`Bearer` in `spa/src/api/client.ts` / `auth.ts`; `withCredentials: true` throughout | same grep |
| 6b | No Bearer tokens (portals) | **FAIL, cross-module** — supplier portal writes its Sanctum token to `sessionStorage` (RA-012). Customer portal is clean and cookie-based | same grep |
| 7 | Documented flow works end to end | **PASS** — `GET /sanctum/csrf-cookie` → 204 + cookies; `POST /api/v1/auth/login` with `X-XSRF-TOKEN` → 200 + user; session cookie carries `GET /auth/user` → 200 | full curl flow |
| 8 | State-changing request without `X-XSRF-TOKEN` refused | **PASS** — 419 `CSRF token mismatch` | `POST /auth/change-password` with session, no token |
| 9 | Wrong `X-XSRF-TOKEN` refused | **PASS** — 419 | same with `X-XSRF-TOKEN: totally-bogus-token-value` |
| 10 | Unauthenticated state-changer also CSRF-gated | **PASS** — `POST /auth/login` without token → 419 | login with no `X-XSRF-TOKEN` |
| 11 | Is any `/api/*` group CSRF-exempt? | **NO exemption** — `config/sanctum.php:19-21` wires `validate_csrf_token`; measured 419s confirm it is live | config + the three 419 probes |
| 12 | Cross-origin request with a valid session cookie | **PASS (defence in depth)** — `Origin: https://evil.example` → 401 `Unauthenticated.`; the session is never started for a non-stateful origin | change-password and `GET /auth/user` from evil origin |
| 13 | No-`Origin`/`Referer` request with a valid session cookie | **401** — cookie sessions require browser-shaped requests | curl with cookie, no Origin/Referer |
| 14 | Session id rotates on login | **PASS** — pre-login row `oLHWjj4e…` destroyed, post-login row `KXJF91Bu…` created; old id count = 0 | `sessions` table before/after login |
| 15 | Session invalidated on logout | **PASS** — 204, session row deleted, cookie rotated, replaying the old jar → 401 | logout then reuse jar |
| 16 | Lockout threshold = 5 | **PASS** — counter 1→5, `locked_until` set on the 5th | 5 failures, reading `users.failed_login_attempts` after each |
| 17 | Lock duration = 15 min | **PASS** — `locked_until` = now + 15 min | DB read + `423` body "Try again in 14 minutes" |
| 18 | Correct password refused during lock | **PASS** — 423, no session issued (lock check precedes `Hash::check`) | correct password against locked account |
| 19 | Lockout counter key | **per-account, case-insensitive** — 5 failures across 5 different email casings all landed on one row | 5 casings of one address |
| 20 | Lockout evadable by casing/whitespace? | **NO** — casing accumulates on one row; whitespace is trimmed by `SanitizeInput` before lookup | casing sweep + `"  probe.lock@… "` → 423 |
| 21 | `throttle:auth` = 5/min | **PASS for one identity** — 6th attempt → 429 | 7 attempts, same email |
| 22 | `throttle:auth` per-IP cap | **FAIL (RA-004)** — 12 attempts / 12 distinct emails from one IP → **zero** 429s | spray probe |
| 23 | `throttle:auth` evadable by casing | **FAIL (RA-004)** — bucket exhausted (429), one case change → fresh bucket (422) | exhaust then re-case |
| 24 | 429 body leaks nothing | **PASS** — generic "Too Many Attempts." | 429 bodies |
| 25 | Enumeration — status parity | **PASS** — unknown account and wrong password both 422 | 4 known + 4 unknown |
| 26 | Enumeration — body parity | **PASS** — byte-identical `{"message":"Invalid credentials.","errors":{"email":["Invalid credentials."]}}` | same |
| 27 | Enumeration — **timing** parity | **FAIL (RA-002)** — known 0.295–0.316 s vs unknown 0.057–0.065 s; distributions fully separated | 8 timed requests |
| 28 | Enumeration — lockout status oracle | **FAIL (RA-005)** — locked (∴ existing) account → 423 + minutes remaining; unknown → 422 | 423 vs 422 comparison |
| 29 | Enumeration via password reset — body | **PASS** — both "If an account exists for that email, a password reset link has been sent." | known + unknown |
| 30 | Enumeration via password reset — **timing** | **FAIL (RA-003)** — known **3.700 s** vs unknown **0.060 s** | same two requests |
| 31 | Password min length 8 | **PASS** — `Ab1!` → 422 "must be at least 8 characters" | change-password |
| 32 | Password requires uppercase | **PASS** — `probepass9!` → 422 | change-password |
| 33 | Password requires lowercase | **PASS** (stricter than documented) — `PROBEPASS9!` → 422 | change-password |
| 34 | Password requires special char | **PASS** — `ProbePass99` → 422 | change-password |
| 35 | bcrypt cost 12 | **PASS** — `config/hashing.php:6` default 12; stored hashes are `$2y$12$` | direct hash inspection |
| 36 | Cannot reuse last 3 passwords | **PASS** — reusing a password in history → 422 "You have used this password recently." | change, then change back |
| 37 | Cannot set new = **current** password | **FAIL (RA-007)** — HTTP 200 "Password updated successfully."; `password_changed_at` refreshed | change-password with new == current |
| 38 | 90-day expiry forces a change | **PASS with a caveat** — `password_changed_at` 200 days old: login 200, `/auth/user` 200 (exempt), gated route 403 `password_expired`. Forced on next *gated call*, not at login | login + exempt + gated route |
| 39 | `must_change_password` forces a change | **PASS** — gated route → 403 `password_expired` | login as flagged user |
| 40 | Forced-change bypassable by bearer header? | **NO** — 403 either way (`CheckPasswordExpiry` has no bearer bail) | with and without header |
| 41 | Idle timeout: employee 15 min | **PASS** — 14 min → 200, 16 min → 401 `session_timeout` | boundary sweep |
| 42 | Idle timeout: others 30 min | **PASS** — 29 min → 200, 31 min → 401 `session_timeout` | boundary sweep |
| 43 | Idle timeout enforced server-side | **PASS** — enforced from `users.last_activity` in middleware, not by the client | DB-driven sweep |
| 44 | Idle timeout bypassable by bearer header? | **WAS FAIL (RA-001), NOW FIXED** — 25 min idle on a 15 min policy: 200 with `Authorization: Bearer junk`, 401 without. After fix: 401 with header, 200 inside window | paired with/without probe, before and after |
| 45 | Password change revokes other sessions | **PASS (stronger than documented)** — two sessions → change via A → **both** 401, 0 session rows | two independent cookie jars |
| 46 | Audit row on successful login | **PASS** — `login.success`, with IP + user agent | `audit_logs` query |
| 47 | Audit row on failed login | **PASS** — `login.failed` | same |
| 48 | Audit row on lockout | **PASS** — `login.locked` + `login.locked_threshold` | same |
| 49 | Audit row on logout | **PASS** — `logout` | same |
| 50 | Audit rows carry IP + user agent | **PASS** — every row: `with_ip` = `with_ua` = row count | aggregate count query |
| 51 | Audit rows leak no secrets | **PASS** — `new_values` is `{"email": …}` only; reset tokens stored as sha256 (`token_hash varchar(64)`) | row inspection + schema |
| 52 | Unknown-email attempts recorded | **PASS** — `login_history` `unknown_email` rows (no `audit_logs` row, documented: needs a real `user_id`) | `login_history` breakdown |
| 53 | No raw integer ids in auth payloads/errors | **PASS** — 27 captured bodies (200/401/403/419/422/423/429) swept for integer `id`/`user_id`/`role_id`/`model_id`/`employee_id`: **all clean** | recursive JSON sweep |
| 54 | Security-header files exist | **PASS** — `docker/nginx/security-headers-{dev,prod}.conf`, all six headers in both, every one `always` | source read only |
| 55 | Headers inlined into a server block? | **YES, 2 places (RA-013)** — `default.conf:35-40`, `prod.conf:86-91` | source read only |
| 56 | Header delivery on a live response | **NOT VERIFIED** — nginx was not running | — |
| 57 | Production boot fails on dev defaults | **PASS for `APP_DEBUG`/`HASHIDS_SALT`/`APP_KEY`/`SERVER_NAME`**; `SESSION_SECURE_COOKIE` **was missing (RA-006), now added** | `ProductionAssertions` + its unit tests |
| 58 | `.env` secrets committed? | **NO** — `.env` is untracked and matched by `api/.gitignore:9` | `git ls-files` + `git check-ignore` |
| 59 | Custom-guard `audit_logs` FK violation (CLAUDE.md warning) | **DID NOT REPRODUCE** — see Q5; `EdgeSystemUserResolver` and `auth:edge_device` do not exist | `config/auth.php` + portal suites green |

## Findings

### Broken

**RA-001 — Idle session timeout bypassable with a client-supplied `Authorization` header. P1. FIXED.**
`api/app/Common/Middleware/SessionTimeout.php:30-32` (pre-fix) skipped all idle-session enforcement
whenever `$request->bearerToken()` was non-empty. That header is entirely client-controlled, so a
cookie-authenticated internal user could remain signed in indefinitely past the 15/30-minute policy
by attaching any junk bearer token. Measured: employee (15 min policy) idle 25 min returned **200**
with `Authorization: Bearer junk` and **401 `session_timeout`** without it — same account, same DB
state, one header apart. The bail existed to let portal principals through, but the resolved-principal
check already at `SessionTimeout.php:57` covers that, so the header test was both unnecessary and
unsafe. `CheckPasswordExpiry` has no equivalent bail, so forced-password-change was **not**
bypassable (measured: 403 either way) — which bounds the impact to the idle clock. Fixed; see
`fix-log.md` FIX-1.

### Missing

**RA-002 — Login has a user-enumeration timing oracle. P1. Deferred.**
`api/app/Modules/Auth/Services/AuthService.php:57-59` returns `['status' => 'unknown']` before the
`Hash::check` at `:86`, so an unknown account skips a bcrypt-cost-12 verification that a known
account pays. Measured over 8 requests: known-account/wrong-password **0.295–0.316 s** versus
unknown-account **0.057–0.065 s** — a ~245 ms gap with **fully separated** distributions (slowest
unknown is still 4.5× faster than the fastest known). Status and body are correctly identical, so
timing is the whole leak. There is no dummy-hash comparison. Compounded by RA-004: because the
limiter is keyed by `ip|email`, an attacker can enumerate an unlimited number of addresses from one
IP at one request each.

**RA-003 — Password reset has a much larger timing oracle, and sends SMTP synchronously on an unauthenticated route. P1. Deferred.**
`api/app/Modules/Auth/Services/PasswordResetService.php:33-35` returns immediately for an unknown or
inactive account, while a known account proceeds to token creation and then
`$user->notify(new PasswordResetLinkNotification(...))` at `:58`.
`api/app/Modules/Auth/Notifications/PasswordResetLinkNotification.php:17` declares
`class PasswordResetLinkNotification extends Notification` and uses the `Queueable` trait but
**does not `implement ShouldQueue`** — verified: zero occurrences of `ShouldQueue` anywhere in
`app/Modules/Auth/Notifications/`. The trait alone does not queue, so despite
`QUEUE_CONNECTION=redis` the mail is delivered inline. Measured: known account **3.700 s**, unknown
account **0.060 s** — a 3.64-second, 61× oracle that renders the correctly generic body
("If an account exists for that email…") worthless. Second-order: an unauthenticated endpoint holds
an SMTP connection open for ~3.7 s per request, and RA-004 means one IP can do that concurrently
across many distinct addresses.

**RA-004 — `throttle:auth` is keyed by `ip|email`, which defeats the per-IP cap it is documented to be. P1. Deferred.**
`api/bootstrap/app.php:146-147`:
```php
RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)
    ->by($r->ip().'|'.$r->input('email', '')));
```
CLAUDE.md documents `Limit::perMinute(5)->by($r->ip())`. Including the email in the key means the
"5 per minute per IP" cap does not exist. Measured twice: (a) 12 login attempts from one IP against
12 distinct emails → **zero** 429s, so password spraying one common password across every account is
unthrottled; (b) after exhausting one address's bucket to 429, changing a single character's **case**
produced a fresh bucket returning 422 again — because `SanitizeInput` trims but does not lowercase,
and the limiter reads the raw input before `LoginRequest::prepareForValidation` lowercases it.
The per-account lockout (RA-016/control 19) is the real backstop and it holds; what is missing is any
per-IP ceiling. **Needs a human decision** — see Q1, because 200+ employees behind one factory NAT
make a naive per-IP limit an availability risk.

**RA-005 — Lockout state is an account-existence oracle. P2. Deferred.**
`api/app/Modules/Auth/Services/AuthService.php:129-134` answers a locked account with
`abort(423, "Account locked. Try again in {$remaining} minutes.")`, while unknown accounts and wrong
passwords both get a generic 422. Measured: 423 + "Account locked. Try again in 14 minutes." versus
422 + "Invalid credentials." An attacker who can land 5 failures against an address learns
definitively whether it exists, and also learns the remaining lock window. Changing this alters a
status code the SPA may branch on, so it is a contract change, not a drop-in.

**RA-006 — `SESSION_SECURE_COOKIE` was not asserted at production boot. P2. FIXED.**
`api/app/Common/Support/ProductionAssertions.php:24-41` failed a production boot on `APP_DEBUG`,
`HASHIDS_SALT`, `APP_KEY` and `SERVER_NAME` dev defaults but not on a non-Secure session cookie —
the setting that decides whether the entire auth model may cross plaintext HTTP. `config/session.php:17`
defaults to `true`, but all three dev templates ship `false` (`api/.env:57`, `api/.env.example:55`,
`.env.example:38`). Not a live defect (`.env.production.example:35` is correct), so classified
Missing rather than Broken: the guard that existed for four dev defaults did not cover the fifth.
Fixed; see `fix-log.md` FIX-2.

### Incomplete

**RA-007 — Internal change/reset accepts the CURRENT password as the "new" password. P1. Deferred.**
Measured: `POST /auth/change-password` with `new_password` equal to the existing password returned
**HTTP 200 "Password updated successfully."**, refreshed `password_changed_at`, cleared
`must_change_password`, and wrote a history row. `api/app/Modules/Auth/Services/AuthService.php:195-203`
compares the new password only against `passwordHistory()` rows; the old hash is pushed to history at
`:206`, *after* the loop, and there is no `Hash::check($new, $locked->password)`. Same shape in
`api/app/Modules/Auth/Services/PasswordResetService.php:110-117`. Consequence: the 90-day expiry can
be satisfied without changing anything, and the "cannot reuse last 3" rule fails at the one value
most worth rejecting. The **portal** path blocks this explicitly —
`api/app/Modules/B2B/Services/PortalPasswordHistoryService.php:20-24` — so internal users are held to
a weaker standard than portal users under the same policy setting.

**RA-008 — The "preserve the current session" path on password change is dead in practice. P3. Deferred.**
`api/app/Modules/Auth/Services/AuthService.php:227-230` deliberately passes the current session id to
`SessionRevocationService::revokeOtherSessions()` so the acting browser survives — the policy the
2026-08-25 session chose for AS-002. Measured: it does not survive. With two logged-in sessions,
changing the password from session A left **both** A and B returning 401 and **zero** session rows.
`config/sanctum.php:18` wires `Laravel\Sanctum\Http\Middleware\AuthenticateSession`, which compares a
session-stored password hash on the next request and logs the session out when it changes. The
security outcome is stronger than intended; the defect is that the code states an intent it does not
achieve, so a future reader will mis-model the behaviour. Either drop the current-session argument or
document that `AuthenticateSession` overrides it.

**RA-009 — The idle clock is per-user, not per-session. P3. Deferred.**
`api/app/Common/Middleware/SessionTimeout.php:84-105` reads and stamps `users.last_activity`, a single
column on the user row. Two concurrent sessions therefore share one idle clock: activity in one
browser indefinitely keeps an abandoned session in another browser alive, which is precisely what an
idle timeout is meant to prevent on a shared shop-floor terminal. Per-session tracking would key off
the `sessions` row instead.

**RA-010 — Password-history trim ordering is non-deterministic. P3. Deferred. NOT independently measured.**
`api/app/Modules/Auth/Models/User.php:85` orders `passwordHistory()` by `created_at` only, and
`password_history.created_at` is second-resolution. The trim at
`api/app/Modules/Auth/Services/AuthService.php:219-225` deletes `whereNotIn($keepIds)`, so two changes
inside one second make the survivors arbitrary and a still-in-window password can be trimmed and
become reusable. The portal implementation adds an `id` tiebreak
(`api/app/Modules/B2B/Services/PortalPasswordHistoryService.php:34-35,65-66`); the internal one does
not. Identified by source reading; the same-second race was not reproduced.

**RA-011 — `LoginRequest` hardcodes `min:8` against a settings-driven policy. P3. Deferred. NOT independently measured.**
`api/app/Modules/Auth/Requests/LoginRequest.php:27` uses `'min:8'` while the real minimum is
`security.password_min_length` and `api/app/Modules/Admin/Requests/UpdateSettingRequest.php:28`
permits an admin to set it as low as 6. Lower the setting, let a user choose a 6-character password
via `StrongPassword`, and that user can then never log in — login 422s before `AuthService` is
reached. Identified by source reading; not reproduced (would require mutating a shared policy
setting).

### Polish

**RA-012 — Supplier portal stores its Sanctum bearer token in `sessionStorage`. CROSS-MODULE — not fixed here.**
`spa/src/api/b2b/client.ts:14-38` sets `client.defaults.headers.common.Authorization = \`Bearer ${token}\``
and persists the token via `window.sessionStorage.setItem(storageKey, token)`, rehydrating it on load
at `:34`. Activated by `spa/src/api/b2b/supplier.ts:18` (`createPortalClient('ogami_supplier_portal_token')`)
and `:30` (`setToken(data.data.token)`), and locked in as intended behaviour by
`spa/src/api/b2b/client.test.ts:13,33,43,45`. This violates CLAUDE.md's "NEVER use Bearer tokens.
NEVER store auth in localStorage/sessionStorage" and makes the token readable by any XSS on the
portal origin. The client already sets `withCredentials: true` (`:6`) and primes
`/sanctum/csrf-cookie` (`:43-44`), and the **customer** portal is already pure-cookie
(`spa/src/api/b2b/customer.ts:21-24`, with a comment stating the intent), so the correct pattern
already exists in the same directory. **Deliberately not fixed here:** the fix requires the
`supplier_portal` guard to accept a cookie session, and `config/auth.php:12-15` declares it as
`driver => sanctum` (the customer portal is `driver => session`). That is a guard-level change owned
by `supply-chain/supplier-portal`, which another session holds. Handed off, not touched.

**RA-013 — nginx: security headers inlined into two `location` blocks, and the port-80 server block has no header include. Source-only.**
`docker/nginx/default.conf:35-40` and `docker/nginx/prod.conf:86-91` hand-inline the full six-header
set inside `location ~ ^/api/v1/documents/[^/]+/view$`, which CLAUDE.md forbids. The in-file
justification is technically sound — nginx `add_header` cannot relax an already-added header, and the
PDF preview iframe needs `SAMEORIGIN`/`frame-ancestors 'self'` — but it creates two hand-maintained
CSP copies that will drift. Separately, `docker/nginx/prod.conf:8-22` (the port-80 redirect/ACME
server) includes no header file at all, so its responses carry none; low severity (HSTS is ignored
over plaintext and a 301 has no injectable body) but `X-Content-Type-Options: nosniff` is genuinely
absent on ACME token responses. To this module's credit, the classic `add_header`
non-inheritance bug was checked across all 12 `location` blocks and **no block currently drops a
security header** — the three inheritance-breaking blocks all compensate. Prod CSP is strict
(`script-src 'self'`, no `unsafe-eval`, `frame-ancestors 'none'`); the remaining weakness is
`style-src 'unsafe-inline'`, acknowledged in-file for Recharts. **Delivery not verified — nginx was
not running.**

**RA-014 — Timed 90-day expiry never reaches the SPA guards. P3.**
The SPA guards (`spa/src/components/guards/AuthGuard.tsx:32`, `GuestGuard.tsx:29`) branch only on
`must_change_password`. A user whose password merely aged past 90 days logs in successfully, renders
a dashboard, and is then bounced by the 403 interceptor on the first gated fetch. Measured: login
200, `/auth/user` 200, gated route 403 `password_expired`. Functionally forced, but "forced change on
next login" as documented in CLAUDE.md is really "forced change on next gated API call."

**RA-015 — CORS returns a fixed `Access-Control-Allow-Origin` for a mismatched Origin. P4.**
A request with `Origin: https://evil.example` came back with
`Access-Control-Allow-Origin: http://localhost` and `Access-Control-Allow-Credentials: true`.
Harmless — the browser blocks on the mismatch — but it needlessly discloses the configured origin
and is unusual enough to be worth a glance in `config/cors.php`.

## Controls re-verified as working (previous sessions' fixes, now measured not asserted)

- **Login decision serialization** (AS-003 lineage): the whole decision — active check, lock check,
  `Hash::check`, counter mutation — runs inside one `DB::transaction` under `lockForUpdate()`
  (`AuthService.php:50-112`), with session issuance deliberately outside it at `:147-148`.
- **Case-insensitive identity** (AS-001 lineage): a legacy row stored as `Probe.MIXED@Ogami.test`
  authenticated and locked correctly through five different submitted casings.
- **Session revocation on credential change** (AS-002 lineage): measured stronger than specified —
  all sessions die, not just the others (see RA-008).
- **Credential audit mirrors** (AS-005 lineage): `login.success`, `login.failed`, `login.locked`,
  `login.locked_threshold`, `password.changed`, `logout` all present in `audit_logs` with IP and UA.
- **Reset token hygiene**: `password_reset_requests.token_hash` is `varchar(64)` sha256, single-use
  via `used_at`, expiry-checked after a `lockForUpdate()` re-read
  (`PasswordResetService.php:92-100`), and the request IP is recorded.
- **HashIDs**: no raw integer id in any of 27 captured auth bodies across 7 status codes.

## Verification performed

| Check | Result |
|---|---|
| Atomic claim | `claim-module.sh platform auth-session` → `RECLAIMED` (103 h orphan) |
| Full `migrate` on a fresh database | Passed — completes; the 2026-08-25 blocker is gone |
| Live HTTP probe harness | 60+ real requests across 14 probe groups; all numbers in this report are measured |
| Baseline **before** any change | `php artisan test tests/Feature/Auth` → **64 passed (267 assertions)**, exit 0 |
| New tests vs unmodified source | **1 failed, 5 passed** — RED proven for RA-001 |
| `ProductionAssertions` tests vs guard disabled | **2 failed, 10 passed** — RED proven for RA-006; revert restored and proven by `sha256sum -c` + `diff -q` |
| **After** both fixes | `tests/Feature/Auth` + `ProductionAssertionsTest` → **79 passed (297 assertions)**, 0 failures |
| Cross-guard regression | `PortalTokenCrossGuardTest` + `CustomerPortalAuthTest` + `SupplierPortalAuthTest` → **25 passed (121 assertions)** |
| Cross-module regression (`SessionTimeout` is global) | `tests/Feature/Admin` → **149 passed (614 assertions)**, exit 0 |
| Live re-verify of FIX-1 | 401 `session_timeout` with the header (was 200); 200 inside the window (no over-blocking) |
| `php -l` | 4/4 changed files clean |
| PHPStan | `[OK] No errors` on both changed source files |
| Pint | `PASS … 4 files` — no new and no inherited style debt |
| Full test suite | **NOT run** — out of scope per the pipeline rules; 253 tests were run across 4 targeted suites |
| nginx live header delivery | **NOT verified** — nginx not running |
| SPA typecheck / vitest / eslint | **NOT run** — this session changed no SPA file |
| Browser/visual QA | **NOT run** |

## Open questions for a human

1. **RA-004 — what should the per-IP login ceiling be?** Restoring the documented `->by($r->ip())`
   would put all 200+ FCIE employees behind one factory NAT into a shared 5/min bucket and cause a
   self-inflicted denial of service at shift change. The usual answer is two limiters — a tight
   per-account one plus a loose per-IP one (e.g. 60/min) — but the numbers are a policy call about
   this plant's egress topology, which this session cannot observe.
2. **RA-005 — may the 423 lockout response change?** Suppressing the oracle means answering a locked
   account with the same 422 as a bad password, which removes the "try again in N minutes" message
   the user arguably needs. Does the SPA branch on 423 today, and is the usability loss acceptable?
3. **RA-007 — is "new password may equal current password" intentional?** The portal path explicitly
   forbids it and the internal path does not, which reads like drift rather than design — but
   confirming it is a policy decision, and it changes what an in-flight forced-change user can submit.
4. **Timezone: should `APP_TIMEZONE` be UTC?** `config/app.php:15` defaults to `Asia/Manila` while the
   database and containers run UTC, and `last_activity` / `password_changed_at` /
   `password_reset_requests.expires_at` are all `timestamp without time zone`. Every auth comparison
   verified here happens in PHP through Carbon and is therefore self-consistent, so **no auth defect
   was found**. But any raw SQL that compares one of these columns against the database's `now()` is
   silently 8 hours off — it broke this session's own probe. Flagged as a question, not a finding,
   because settling it is a platform-wide decision.
5. **Custom-guard `audit_logs` FK violation — did not reproduce; is the CLAUDE.md warning obsolete?**
   CLAUDE.md's "HasAuditLog + custom guards" section prescribes
   `App\Modules\Edge\Services\EdgeSystemUserResolver` and an `auth:edge_device` guard. **Neither
   exists**: `config/auth.php:7-20` declares exactly three guards — `web` (session),
   `supplier_portal` (sanctum), `customer_portal` (session). The portal suites are green and no FK
   violation appeared in any probe. Reported as an open question rather than invented around, per the
   audit brief. The CLAUDE.md section should probably be deleted or rewritten.
6. **RA-012 — who owns the supplier-portal token migration?** Moving the supplier portal to cookie
   auth requires changing its guard driver from `sanctum` to `session`, which is a
   `supply-chain/supplier-portal` change with an auth-boundary blast radius. Confirm the sequencing
   before either module acts.

## Release decision

`🔁 Needs Re-audit`. Two findings were fixed and verified (RA-001, RA-006); ten remain, of which
eight are `separate-recommended` and one (RA-012) belongs to another module. The deferred set is
dominated by items that change the authentication flow, a rate-limiting policy that needs a human
decision about NAT topology, or a client-visible status-code contract — exactly the categories the
pipeline gates. See `action-plan.md` for the ordered plan and the cross-module risk weighting.

