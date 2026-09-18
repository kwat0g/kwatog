# Platform / Cross-Cutting Audit (Dashboard, Admin, Auth, Edge, Landing, Common)

Date: 2026-09-18
Claims are **[confirmed]** (file:line/grep) or **[assumption/unverified]**.

---

## 1. Top findings

1. **8D SLA cron still exits SUCCESS on total failure** (also in Quality doc):
   `RunComplaint8dSlaChecks:26` always returns `SUCCESS`; `Complaint8dEscalationService` catches
   `Throwable` → `recordFailure` + `[]`. A fully failing run prints `d3=0 d4=0 finalize=0` and exits
   0. Its NCR sibling was hardened; 8D was missed. **[confirmed]**
2. **Fatal missing `SearchOperator` import in `Admin/Services/RoleService.php:28`.** Role list with
   `?search=` throws class-not-found (500). **[confirmed]**
3. **`Landing/routes.php:24` inquiry inbox lacks `feature:crm`**, so disabling CRM does not close the
   backend inbox. **[confirmed]**
4. **The `Edge` module does not exist.** Migrations show `edge_devices` was created then dropped
   (0451); `EdgeSystemUserResolver` was renamed to `SystemUserResolver`. `CLAUDE.md` and dead
   carve-outs in `SessionTimeout`/`CheckPasswordExpiry`/`bootstrap/app.php` still reference it.
   **[confirmed]**
5. **Nginx SPA location overrides the security headers.** Server-level `security-headers-*.conf`
   set `X-Frame-Options: DENY` / `frame-ancestors 'none'`, but the SPA `location` re-declares
   `SAMEORIGIN` / `'self'` (`default.conf:36,40`; `prod.conf:87,91`) — a deliberate carve-out for
   same-origin PDF iframes, but it contradicts the mandated DENY. **[confirmed]**
6. **`SettingsService::updateFromAdmin` is not transactional** — the `settings` UPDATE commits, then
   a separate `AuditLog::create`; a failed audit insert leaves a changed security setting unaudited.
   **[confirmed]**
7. **Newsletter subscribers can never unsubscribe** — `NewsletterStatus::Unsubscribed` and
   `unsubscribed_at` exist, but no route/handler anywhere (Data Privacy Act gap). **[confirmed]**

## 2. Dashboard

- Landing dashboard is permission-derived (rarity from live `role_permissions`, no role-name branch);
  widget layout strips anything the caller lacks; save locks and validates; `WidgetSeedIntegrityTest`
  is a real drift guard. Positive.
- `WidgetAnalyticsService` catches provider exceptions → `Log::warning` → `[]` (tile silently degrades);
  `ActionCenterService` similarly drops a failing source silently.
- `KpiSnapshotService` documents metric-contract gaps (WO completion can exceed 100%; raw queries
  ignore `deleted_at` while attendance excludes it; budget utilization ignores `$month`).
- Only `kpi:compute-monthly` is scheduled, so `kpi.*` widgets read one month behind (documented).
- Layout endpoints are intentionally ungated (service self-gates).

## 3. Admin

- Role/permission/override flows are lock-based and idempotent; `RequireSystemAdmin` + permission
  double-gates overrides; system roles are seed-only and uneditable. Positive.
- `SessionController::destroy` terminates **any** session by id gated only by `admin.settings.manage`
  (no self/system_admin scoping, no audit row).
- `GET /business-policies` has `auth:sanctum` only (no `permission:`), exposing payment terms, VP
  threshold and currency codes to any authenticated user.
- `MasterDataImportService` docblock advertises BOM/mold/machine importers that do not exist.
- `RecordActivityFromEvent` swallows `Throwable → Log::warning` (broken feed invisible).

## 4. Auth / security

- Sanctum stateful cookie mode is the internal path; no Bearer/localStorage token usage; rate limiters
  (`auth` 5/min ip|email, `api` 300/min, `sensitive` 10/min, `public-form` 10/min). Password reset is
  sha256 single-use, row-locked, sessions revoked. Positive.
- Login lockout is per-user only; **unknown-email attempts are not counted** (mitigated by `throttle:auth`).
- `password-policy` endpoint hardcodes complexity flags (only length is setting-driven), so the
  advertised policy can drift.
- `password_history_depth = 0` disables the reuse check **and** trimming (unbounded hash accumulation).
- `SessionTimeout` idle branch keys on role **slug** `'employee'` (the one role-name branch in an
  otherwise permission-derived codebase); `must_change_password` check is duplicated in two middleware.
- **No 2FA anywhere.**
- Two public login endpoints (`/auth/sign-in`, `/auth/login`) — duplicate surface.

## 5. Edge

- No module, routes, device auth, resolver, or T2.x ingest. Residual references to remove:
  `SessionTimeout:123`, `CheckPasswordExpiry:68`, `bootstrap/app.php:69` alias comment, and
  `SystemUserResolver` doc mentioning `edge.system_user.*`.

## 6. Landing / Common

- Landing contact form requires `consent` and stamps `consent_at` server-side (good); no unsubscribe
  (above).
- `RunApprovalEscalations` always returns `SUCCESS` and the service returns no failure counts — a
  fully failing escalation run looks idle (same class as 8D).
- `RunChainBottleneckCheck` bypasses `AlertEngineService` dedup and writes `Alert::create` directly,
  against the `condition_key` unique-open-condition invariant (comment claims otherwise).
- `ChainListenerRecoveryService` hardcodes `model_id => null` in recovery audit rows, so they can
  never be returned by the entity-scoped audit endpoint.
- Strongly built: `OutboxService`/`OutboxDispatcher` (lease tokens + stale reclaim), `ChainBroadcaster`
  (unsupported class throws rather than skipping), `NotificationService` (deferred, batched prefs),
  `SystemActorService`/`SystemUserResolver`, `DocumentVaultService` (private disk, checksum, no-store),
  `DocumentSequenceService` (row-locked), `AlertEngineService` (unique open condition, bounded critical
  retry), `GlobalSearchService` (module row scopes reused), `ApprovalSourceScope` (board reuses module
  scopes).

## 7. Assumptions

- A1. No test run; source/grep. A2. The Nginx SPA carve-out is read as intentional from the inline
  comment; whether it is an accepted risk is a security decision. A3. Docker runtime config was read
  from files, not a running stack. A4. 2FA absence is from schema/middleware grep, not a product spec.
