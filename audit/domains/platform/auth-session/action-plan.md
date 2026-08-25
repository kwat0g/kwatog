# M001 — Auth / Session Action Plan

Status: `📋 Plan Ready`  
Audit report: `audit-report.md`  
Fix log: `fix-log.md`

No implementation changes were made in the audit session. Execute in this order:

## 1. Serialize B2B lockout mutations (AS-003)

- **Priority:** P1
- **Scope:** Medium
- **Session recommendation:** `separate-recommended`
- **Files/surface:** `api/app/Modules/B2B/Services/B2bAuthService.php`; supplier/customer auth feature tests; a two-connection race harness.
- **Work:** Re-read the portal model inside a `DB::transaction()` with `lockForUpdate()`. Make active/locked/password verification, counter reset/increment, threshold lock, and audit event decisions from that authoritative row. Preserve one-token-per-login behavior after the successful mutation commits.
- **Acceptance:** Concurrent supplier and customer failure/failure attempts never lose increments; a threshold produces one deterministic lockout; success/failure interleavings cannot overwrite the authoritative reset; sequential auth tests remain green; the race harness uses independent PostgreSQL connections.

## 2. Establish one internal email identity contract (AS-001)

- **Priority:** P1
- **Scope:** Large / cross-module
- **Session recommendation:** `separate-recommended`
- **Files/surface:** Auth login/forgot requests and services; Admin create-user request/service; HR provisioning request/service; users schema/unique constraint as needed; auth/admin/HR tests.
- **Work:** Normalize trim/lowercase at every internal-user write boundary or in a shared model hook, align the accepted email length/RFC rule across create/login/reset, and make uniqueness case-insensitive. Decide how to repair already-stored mixed-case rows before enforcing a new index.
- **Acceptance:** Mixed-case provisioned users can log in with any email casing; mixed-case reset requests find the account; duplicate case variants are rejected; valid 151–255-character addresses have the same create/login/reset contract; existing rows are migrated or explicitly verified.

## 3. Revoke internal sessions after credential mutation (AS-002)

- **Priority:** P1
- **Scope:** Medium / security-sensitive
- **Session recommendation:** `separate-recommended`
- **Files/surface:** `api/app/Modules/Auth/Services/AuthService.php`; `api/app/Modules/Auth/Services/PasswordResetService.php`; session/revocation helper if introduced; internal auth tests and two-browser/session fixtures.
- **Work:** Decide current-session behavior for self-service change. Add a centralized revocation primitive that invalidates all other internal `sessions` rows on password change/reset, or intentionally invalidates all and redirects to login. Revoke any future persistent-login credential as part of the same policy.
- **Acceptance:** An old authenticated session cannot call `/auth/user` after change/reset; the intended current-session behavior is explicit and tested; reset-token consumption and session revocation are transactionally safe; no portal bearer token is accidentally handled by the internal helper.

## 4. Define “Remember me” behavior (AS-004)

- **Priority:** P2
- **Scope:** Medium / authentication policy
- **Session recommendation:** `separate-recommended`
- **Files/surface:** `spa/src/pages/auth/login.tsx`, `spa/src/api/auth.ts`, `api/app/Modules/Auth/Requests/LoginRequest.php`, `api/app/Modules/Auth/Controllers/LoginController.php`, `api/app/Modules/Auth/Services/AuthService.php`, session configuration, browser/session tests.
- **Work:** Choose implementation or removal. If implementing, pass the boolean to `Auth::login`, define lifetime/cookie/revocation behavior, and ensure it does not weaken forced-password-change or logout semantics. If removing, delete the payload/type and update copy/tests.
- **Acceptance:** The UI matches the server behavior; browser restart and expiry behavior is tested; logout and password mutation behavior is documented.

## 5. Mirror credential recovery events to the Admin Audit Log (AS-005)

- **Priority:** P2
- **Scope:** Medium / audit-contract
- **Session recommendation:** `separate-recommended`
- **Files/surface:** internal and B2B password services, shared audit event helper if introduced, `AuditEvents`/Admin Audit Log tests.
- **Work:** Define non-secret event names for reset-requested, reset-completed, reset-rejected/replayed where appropriate, and portal password changes. Persist target identity, actor/source, IP, and user agent without raw passwords or reset tokens. Keep file-channel logging as a complementary operational log.
- **Acceptance:** Admin Audit Log shows internal and portal credential changes/resets; invalid/replayed tokens do not create misleading success rows; delivery failures remain diagnosable; tests cover both internal and portal audiences.

## 6. Restore keyboard access to password toggles (AS-006)

- **Priority:** P2
- **Scope:** Small
- **Session recommendation:** `same-session-ok`
- **Files/surface:** `spa/src/pages/auth/login.tsx`, `spa/src/pages/auth/change-password.tsx`, `spa/src/pages/portal/PortalLoginPage.tsx`, related component tests.
- **Work:** Remove `tabIndex={-1}` from the visible password controls or provide an equivalent keyboard-reachable control. Keep the existing accessible labels and verify focus/activation order.
- **Acceptance:** Keyboard users can tab to and activate every show/hide control on internal and portal login/change-password flows; auth SPA tests and lint/typecheck remain green.

## Re-audit gate

Before claiming `✅ Verified`, run the backend auth/B2B suites against an available PostgreSQL test database, run the two-connection concurrency harnesses, re-run the full SPA test/typecheck/lint gates, and perform authenticated narrow-width visual checks for internal and portal roles. Update `fix-log.md` with file:line before/after evidence for every implemented item; leave deferred items as `🔁 Needs Re-audit`.
