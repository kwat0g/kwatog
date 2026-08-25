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
