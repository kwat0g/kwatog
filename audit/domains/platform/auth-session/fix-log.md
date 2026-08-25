# M001 — Auth / Session Fix Log

Audit session: 2026-08-24 (plan creation), 2026-08-25 (execution)  
Status: `🔁 Needs Re-audit`

The original audit session made no production changes. This execution session claimed the existing Plan Ready handoff and applied the contained fixes below. Existing uncommitted B2B auth hardening in the shared working tree was preserved and verified; it was not rewritten.

## Applied fixes

- **AS-003 — B2B lockout serialization:** The pre-existing working-tree implementation at `api/app/Modules/B2B/Services/B2bAuthService.php:58-136` now canonicalizes the lookup email, re-reads the portal row inside `DB::transaction()`, uses `lockForUpdate()`, and makes active/locked/password/counter/token decisions from the locked row. This session added `api/tests/Feature/B2B/LoginThresholdTwoConnectionHarnessTest.php:1-183`, covering supplier and customer failure-threshold and success-reset interleavings. The implementation was not duplicated or overwritten.
- **AS-001 — internal identity boundary (partial):** `api/app/Modules/Auth/Models/User.php:20-28` canonicalizes model-backed internal-user writes; `api/app/Modules/Auth/Services/AuthService.php:35-55` and `api/app/Modules/Auth/Services/PasswordResetService.php:28-32` use trimmed, case-insensitive lookup; `api/app/Modules/Auth/Requests/ForgotPasswordRequest.php:16-26` now applies the same normalization and the 255-character contract instead of 150. Regression coverage for legacy mixed-case rows is in `api/tests/Feature/Auth/AuthEventsAuditTest.php:232-245` and `api/tests/Feature/Auth/PasswordResetTest.php:88-104`.
- **AS-002 — internal session revocation:** `api/app/Modules/Auth/Services/SessionRevocationService.php:10-32` centralizes database-session deletion. `api/app/Modules/Auth/Services/AuthService.php:186-234` locks the authoritative user row, preserves the current session for self-service change, and revokes other sessions atomically; `api/app/Modules/Auth/Services/PasswordResetService.php:125-135` revokes all sessions atomically during reset. The chosen current-session policy is covered by `api/tests/Feature/Auth/AuthEventsAuditTest.php:198-231`; reset coverage is in `api/tests/Feature/Auth/PasswordResetTest.php:117-149`.
- **AS-004 — Remember me:** The unsupported control and payload were removed from `spa/src/pages/auth/login.tsx:1-27,119-137,216-242` and `spa/src/api/auth.ts:32-35`. The UI no longer promises persistence the API does not implement.
- **AS-005 — credential audit mirrors:** `api/app/Modules/Auth/Services/AuthAuditLogger.php:13-86` records non-secret file and Admin Audit Log events with source, correlation, IP, and user-agent metadata. Internal reset events are wired at `api/app/Modules/Auth/Services/PasswordResetService.php:78,149`; portal change/reset/request events are wired at `api/app/Modules/B2B/Services/PortalPasswordService.php:49` and `api/app/Modules/B2B/Services/PortalPasswordResetService.php:70,135`. Assertions were added to `api/tests/Feature/Auth/PasswordResetTest.php:78-89,136-140`, `api/tests/Feature/B2B/PortalPasswordResetTest.php:45-66`, and `api/tests/Feature/B2B/CustomerPortalAuthTest.php:109-134`.
- **AS-006 — keyboard access:** Removed `tabIndex={-1}` from internal login and change-password visibility buttons at `spa/src/pages/auth/login.tsx:216-229` and `spa/src/pages/auth/change-password.tsx:101-109`. The portal login toggle had already been corrected in the pre-existing working tree at `spa/src/pages/portal/PortalLoginPage.tsx:201-210`.

## Deferred findings / gates

- **AS-001 uniqueness and existing-data repair remains deferred.** The model and read-path fixes make new writes and legacy lookups canonical, but enforcing a case-insensitive database uniqueness contract for already-stored mixed-case rows requires a migration strategy for duplicate normalized addresses. No authoritative repair policy was available, so no destructive rename or arbitrary account merge was invented. This is the remaining human/data-policy item.
- The focused backend suite could not reach assertions locally because the host-side `db` name was unresolved. Running inside Docker reached PostgreSQL but migration setup stopped at the unrelated uncommitted `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`, which attempts to drop a constraint-owned index. No out-of-scope migration was changed.
- `npm run typecheck` remains blocked by unrelated existing errors in `spa/src/pages/assets/detail.tsx` (`qrcode` module and implicit `any`) and `spa/src/pages/return-management/detail.tsx` (duplicate JSX attribute). The changed auth files pass targeted ESLint.
- Full SPA Vitest completed 260 passing tests and one unrelated failure in `spa/src/pages/quality/inspection-specs/editor.test.tsx:108`.

## Verification

- Passed: PHP syntax checks for all changed PHP source and test files.
- Passed: `git diff --check` for the auth-session changes.
- Passed: `php artisan route:list --path=api/v1/auth --except-vendor` (8 routes) and B2B route inventory.
- Passed: targeted ESLint for `src/api/auth.ts`, `src/pages/auth/login.tsx`, and `src/pages/auth/change-password.tsx`.

Next session should resolve the internal-email duplicate repair/uniqueness policy, run the backend auth/B2B suite after the unrelated migration is repaired by its owning session, and re-check only AS-001 plus the applied findings.
