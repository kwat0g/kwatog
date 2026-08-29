# M001 — Auth / Session Fix Log

Audit sessions: 2026-08-24 (plan creation), 2026-08-25 (execution), **2026-08-30 (re-audit + 2 contained fixes)**
Status after 2026-08-30: `🔁 Needs Re-audit`

---

## 2026-08-24 / 2026-08-25 (historical — preserved)

Audit session: 2026-08-24 (plan creation), 2026-08-25 (execution)
Status: `🔁 Needs Re-audit`

The original audit session made no production changes. This execution session claimed the existing Plan Ready handoff and applied the contained fixes below. Existing uncommitted B2B auth hardening in the shared working tree was preserved and verified; it was not rewritten.

### Applied fixes

- **AS-003 — B2B lockout serialization:** The pre-existing working-tree implementation at `api/app/Modules/B2B/Services/B2bAuthService.php:58-136` now canonicalizes the lookup email, re-reads the portal row inside `DB::transaction()`, uses `lockForUpdate()`, and makes active/locked/password/counter/token decisions from the locked row. This session added `api/tests/Feature/B2B/LoginThresholdTwoConnectionHarnessTest.php:1-183`, covering supplier and customer failure-threshold and success-reset interleavings. The implementation was not duplicated or overwritten.
- **AS-001 — internal identity boundary (partial):** `api/app/Modules/Auth/Models/User.php:20-28` canonicalizes model-backed internal-user writes; `api/app/Modules/Auth/Services/AuthService.php:35-55` and `api/app/Modules/Auth/Services/PasswordResetService.php:28-32` use trimmed, case-insensitive lookup; `api/app/Modules/Auth/Requests/ForgotPasswordRequest.php:16-26` now applies the same normalization and the 255-character contract instead of 150. Regression coverage for legacy mixed-case rows is in `api/tests/Feature/Auth/AuthEventsAuditTest.php:232-245` and `api/tests/Feature/Auth/PasswordResetTest.php:88-104`.
- **AS-002 — internal session revocation:** `api/app/Modules/Auth/Services/SessionRevocationService.php:10-32` centralizes database-session deletion. `api/app/Modules/Auth/Services/AuthService.php:186-234` locks the authoritative user row, preserves the current session for self-service change, and revokes other sessions atomically; `api/app/Modules/Auth/Services/PasswordResetService.php:125-135` revokes all sessions atomically during reset. The chosen current-session policy is covered by `api/tests/Feature/Auth/AuthEventsAuditTest.php:198-231`; reset coverage is in `api/tests/Feature/Auth/PasswordResetTest.php:117-149`.
- **AS-004 — Remember me:** The unsupported control and payload were removed from `spa/src/pages/auth/login.tsx:1-27,119-137,216-242` and `spa/src/api/auth.ts:32-35`. The UI no longer promises persistence the API does not implement.
- **AS-005 — credential audit mirrors:** `api/app/Modules/Auth/Services/AuthAuditLogger.php:13-86` records non-secret file and Admin Audit Log events with source, correlation, IP, and user-agent metadata. Internal reset events are wired at `api/app/Modules/Auth/Services/PasswordResetService.php:78,149`; portal change/reset/request events are wired at `api/app/Modules/B2B/Services/PortalPasswordService.php:49` and `api/app/Modules/B2B/Services/PortalPasswordResetService.php:70,135`. Assertions were added to `api/tests/Feature/Auth/PasswordResetTest.php:78-89,136-140`, `api/tests/Feature/B2B/PortalPasswordResetTest.php:45-66`, and `api/tests/Feature/B2B/CustomerPortalAuthTest.php:109-134`.
- **AS-006 — keyboard access:** Removed `tabIndex={-1}` from internal login and change-password visibility buttons at `spa/src/pages/auth/login.tsx:216-229` and `spa/src/pages/auth/change-password.tsx:101-109`. The portal login toggle had already been corrected in the pre-existing working tree at `spa/src/pages/portal/PortalLoginPage.tsx:201-210`.

### Deferred findings / gates (as of 2026-08-25)

- **AS-001 uniqueness and existing-data repair remains deferred.** The model and read-path fixes make new writes and legacy lookups canonical, but enforcing a case-insensitive database uniqueness contract for already-stored mixed-case rows requires a migration strategy for duplicate normalized addresses. No authoritative repair policy was available, so no destructive rename or arbitrary account merge was invented. This is the remaining human/data-policy item.
- The focused backend suite could not reach assertions locally because the host-side `db` name was unresolved. Running inside Docker reached PostgreSQL but migration setup stopped at the unrelated uncommitted `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`.
- `npm run typecheck` remained blocked by unrelated existing errors in `spa/src/pages/assets/detail.tsx` and `spa/src/pages/return-management/detail.tsx`.

> **2026-08-30 note on the above:** all six 2026-08-25 items are now **committed** (they landed in
> `167de85e`, with follow-ups in `38caef1f` and `3de11ba7`), so the working tree was clean for this
> module at the start of the re-audit. The migration blocker is resolved: `php artisan migrate` now
> runs to completion (verified — full migrate on a fresh database, last entry
> `2026_08_26_040000_harden_return_request_status_and_allocations`).

---

## 2026-08-30 re-audit — applied fixes

Two findings were fixed; the rest were deferred as `separate-recommended` (see `action-plan.md`).
Both fixes are additive guards, verified live over real HTTP **and** locked with tests that were
proven to fail against unmodified source.

`docs/PATTERNS.md` contains no middleware or PHPUnit template (its sections are migration, model,
service, form request, resource, controller, route, and the SPA patterns), so both edits follow the
conventions of the files they modify. Neither change is a service, so the documented
`direction` → `orderBy()` defect at `docs/PATTERNS.md:262-268` was not in scope to copy.

### FIX-1 — RA-001 (Broken, P1): idle session timeout was bypassable with a client-supplied `Authorization` header

**File:** `api/app/Common/Middleware/SessionTimeout.php:24-50`

The middleware skipped all idle-session enforcement whenever an `Authorization: Bearer` header was
present. The header is entirely client-controlled, so any cookie-authenticated internal user could
opt out of the idle timeout indefinitely — and stay signed in past the 15/30-minute policy forever —
by attaching a junk bearer token to each request. The bail existed to let B2B portal principals pass
through, but the resolved-principal check that already lives at line 57 (`! $user instanceof User`)
covers that case; the header test was both unnecessary and attacker-controlled.

Before (`SessionTimeout.php:26-32`):

```php
        // Portal clients authenticate with a bearer token and their own
        // guards. Idle-session bookkeeping is only for the cookie-backed
        // internal SPA session; applying it to a portal token would reject
        // an otherwise valid portal request as an internal-user mismatch.
        if ($request->bearerToken()) {
            return $next($request);
        }
```

After (`SessionTimeout.php:31-48`) — the route-guard check was moved ahead of it, and the skip now
keys off the resolved principal:

```php
        if ($request->bearerToken() && ! ($request->user() instanceof User)) {
            return $next($request);
        }
```

`$request->user()` resolves the default `web` session guard (`config/auth.php:4` — `AUTH_GUARD`
defaults to `web`). A genuine supplier-portal bearer token cannot be resolved by the web guard, so it
still returns `null`, is still not an internal `User`, and still short-circuits exactly as before. A
cookie-authenticated internal user now resolves to a `User` and is enforced regardless of what
headers it sends.

**Measured before the fix (live HTTP, `probe.emp@ogami.test`, `employee` role, 15-minute policy, 25 minutes idle):**

```
idle 25m (employee, policy 15) + 'Authorization: Bearer junk' -> HTTP 200  code=None
idle 25m (employee, policy 15) WITHOUT header (control)       -> HTTP 401  code=session_timeout
```

**Measured after the fix (same probe, same DB state):**

```
idle 25m (employee policy 15) + 'Authorization: Bearer junk' -> HTTP 401  code=session_timeout
idle 5m  (inside window) + 'Authorization: Bearer junk'      -> HTTP 200   (no over-blocking)
```

### FIX-2 — RA-006 (Missing, P2): `SESSION_SECURE_COOKIE` was not asserted at production boot

**File:** `api/app/Common/Support/ProductionAssertions.php:38-52`

`assertSafeOrFail()` already fails a production boot on `APP_DEBUG`, `HASHIDS_SALT`, `APP_KEY` and
`SERVER_NAME` dev defaults, but not on a non-Secure session cookie — the one setting that governs
whether the entire cookie-based auth model may traverse plaintext HTTP. `config/session.php:17`
defaults `secure` to `true`, but **every** dev template ships `SESSION_SECURE_COOKIE=false`
(`api/.env:57`, `api/.env.example:55`, `.env.example:38`), so a deployment seeded from one of those
would silently serve a non-Secure session cookie with nothing objecting.

Added after the `SERVER_NAME` check:

```php
        if (config('session.secure') !== true) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true in production so the session cookie is never sent over plaintext HTTP.';
        }
```

This is defence-in-depth, not a live defect: `.env.production.example:35` already sets
`SESSION_SECURE_COOKIE=true`, and the dev value is correct for local HTTP. Classified **Missing**
because the guard that exists for the other four dev defaults did not cover this one.

### Tests added

| File | Test | Fails against unmodified source? |
|---|---|---|
| `api/tests/Feature/Auth/SessionEnforcementTest.php:74-90` | `test_idle_timeout_cannot_be_bypassed_with_a_client_supplied_bearer_header` | **YES** — `Expected response status code [401] but received 200` |
| `api/tests/Feature/Auth/SessionEnforcementTest.php:92-105` | `test_active_internal_session_is_still_allowed_when_a_bearer_header_is_present` | No — pass-either-way regression lock (proves no over-blocking) |
| `api/tests/Feature/Auth/SessionEnforcementTest.php:107-121` | `test_bearer_only_request_without_an_internal_session_is_left_to_the_sanctum_guard` | No — pass-either-way regression lock (proves portal pass-through preserved) |
| `api/tests/Unit/Common/Support/ProductionAssertionsTest.php:75-90` | `test_throws_when_session_cookie_is_not_secure_in_production` | **YES** — `Failed asserting that exception of type "RuntimeException" is thrown` |
| `api/tests/Unit/Common/Support/ProductionAssertionsTest.php:92-112` | `test_aggregates_multiple_failures_into_one_message` (extended) | **YES** — same failure |

2 of the 5 new/changed tests are genuine red-to-green locks; 2 are labelled pass-either-way
regression locks that exist to prove the narrowed guard did not over-block; 1 is an extension of an
existing aggregate test.

`setSafeDefaults()` in `ProductionAssertionsTest.php:23-33` now sets `session.secure` explicitly so
the negative test fails for the right reason rather than depending on the ambient test env (which
leaves `SESSION_SECURE_COOKIE` unset, i.e. `true` by config default).

### Verification

Test database: `ogami_test_m001_ra` (created for this session, dropped afterwards). Never the shared
`ogami_test`.

| Check | Command | Result |
|---|---|---|
| Baseline **before** any change | `php artisan test tests/Feature/Auth --no-coverage` | **64 passed (267 assertions)**, exit 0 |
| New tests vs unmodified source | `php artisan test tests/Feature/Auth/SessionEnforcementTest.php` | **1 failed, 5 passed** — RED proven |
| `ProductionAssertions` test vs guard disabled | temporary `&& false`, then restored | **2 failed, 10 passed** — RED proven |
| Restore of temporary revert | `sha256sum -c` + `diff -q` | `ProductionAssertions.php: OK` / `IDENTICAL` |
| **After** both fixes | `php artisan test tests/Feature/Auth tests/Unit/Common/Support/ProductionAssertionsTest.php` | **79 passed (297 assertions)**, 0 failures |
| Cross-guard regression (portal bearer paths) | `php artisan test tests/Feature/B2B/PortalTokenCrossGuardTest.php CustomerPortalAuthTest.php SupplierPortalAuthTest.php` | **25 passed (121 assertions)** |
| Cross-module regression (`SessionTimeout` is global) | `php artisan test tests/Feature/Admin` | see `audit-report.md` §Verification |
| Live HTTP re-verify of FIX-1 | curl probe, real `Set-Cookie` session | 401 `session_timeout` with header (was 200) |
| `php -l` | all 4 changed files | No syntax errors |
| PHPStan | `phpstan analyse SessionTimeout.php ProductionAssertions.php --memory-limit=1G` | **`[OK] No errors`** |
| Pint | `pint --test` on all 4 changed files | **`PASS ... 4 files`** — no inherited or new style debt |

### Files changed by this session (exhaustive)

```
api/app/Common/Middleware/SessionTimeout.php
api/app/Common/Support/ProductionAssertions.php
api/tests/Feature/Auth/SessionEnforcementTest.php
api/tests/Unit/Common/Support/ProductionAssertionsTest.php
audit/domains/platform/auth-session/audit-report.md
audit/domains/platform/auth-session/action-plan.md
audit/domains/platform/auth-session/fix-log.md
audit/domains/platform/auth-session/status.md
```

No file outside this module was modified. Three throwaway probe scripts
(`api/probe_seed.php`, `api/probe_check.php`, `api/probe_backdate.php`) were created to drive the
live HTTP harness and **deleted** before commit; they are listed here only so their absence is not
mistaken for an oversight.

### Deferred — see `action-plan.md`

RA-002 (login timing oracle), RA-003 (reset timing oracle + synchronous SMTP), RA-004
(`throttle:auth` keyed by `ip|email`), RA-005 (423-vs-422 lockout oracle), RA-007 (current password
accepted as the new password), RA-008 (dead "preserve current session" path), RA-009 (per-user rather
than per-session idle clock), RA-010 (non-deterministic history trim), RA-011 (`LoginRequest`
hardcodes `min:8`), RA-012 (supplier-portal bearer token in `sessionStorage` — **cross-module**,
belongs to `supply-chain/supplier-portal`), plus the nginx header items which could not be verified
live because nginx was not running.
