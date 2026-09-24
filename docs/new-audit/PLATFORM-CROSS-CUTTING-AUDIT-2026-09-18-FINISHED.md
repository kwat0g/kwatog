# Platform / Cross-Cutting Audit (Dashboard, Admin, Auth, Edge, Landing, Common)

Date: 2026-09-18
 
## Re-audit 2026-09-23

Current verdict: **FINISHED**.

- Fixed/stable: approval escalation outcomes, alert deduplication, recovery audit subjects, approval-board pagination, business-policy authorization, outbox version checks, first-use sequence creation, vault checksum verification, notification deduplication, dashboard row scopes/failure signalling, supplier password expiry, auth timing/current-password/history controls, landing feature gating and PII handling, and Edge reference cleanup.
- By-design or policy dispositions: the Nginx SPA `SAMEORIGIN` PDF-preview carve-out, annual (not monthly) budget KPI semantics, the compatibility `/auth/login` alias, and the absence of 2FA pending an explicit product/security requirement.
- The historical findings below are retained as evidence; statuses in this re-audit section are authoritative.
- Full current classification: `RE-AUDIT-REGISTER-2026-09-19.md`.
Claims are **[confirmed]** (file:line/grep) or **[assumption/unverified]**.

The focused regression suites passed on 2026-09-23. A full suite, production scheduler, queue workers,
SMTP, and live Nginx deployment were not rerun here.

## Historical Findings

The original 2026-09-18 findings are preserved below for audit history. Items marked fixed were
verified against the current tree and focused tests; they are not open work items.

---

## 1. Top findings

1. **[fixed] 8D SLA cron still exited SUCCESS on total failure** (also in Quality doc):
   `RunComplaint8dSlaChecks:26` always returns `SUCCESS`; `Complaint8dEscalationService` catches
   `Throwable` → `recordFailure` + `[]`. A fully failing run prints `d3=0 d4=0 finalize=0` and exits
   0. Its NCR sibling was hardened; 8D was missed. **[confirmed]**
2. **[fixed] Fatal missing `SearchOperator` import in `Admin/Services/RoleService.php:28`.** Role list with
   `?search=` throws class-not-found (500). **[confirmed]**
3. **[fixed] `Landing/routes.php` inquiry inbox lacked `feature:crm`**, so disabling CRM did not close the
   backend inbox. **[confirmed]**
4. **[fixed] The `Edge` module does not exist.** Migrations show `edge_devices` was created then dropped
   (0451); `EdgeSystemUserResolver` was renamed to `SystemUserResolver`. `CLAUDE.md` and dead
   carve-outs in `SessionTimeout`/`CheckPasswordExpiry`/`bootstrap/app.php` still reference it.
   **[confirmed]**
5. **[dispositioned] Nginx SPA location overrides the security headers.** Server-level `security-headers-*.conf`
   set `X-Frame-Options: DENY` / `frame-ancestors 'none'`, but the SPA `location` re-declares
   `SAMEORIGIN` / `'self'` (`default.conf:36,40`; `prod.conf:87,91`) — a deliberate carve-out for
   same-origin PDF iframes, but it contradicts the mandated DENY. **[confirmed]**
6. **[fixed] `SettingsService::updateFromAdmin` was not transactional** — the `settings` UPDATE committed, then
   a separate `AuditLog::create`; a failed audit insert leaves a changed security setting unaudited.
   **[confirmed]**
7. **[fixed] Newsletter subscribers initially had no unsubscribe path** — `NewsletterStatus::Unsubscribed` and
   `unsubscribed_at` exist, but no route/handler anywhere (Data Privacy Act gap). **[confirmed]**

## 2. Dashboard

- Landing dashboard is permission-derived (rarity from live `role_permissions`, no role-name branch);
  widget layout strips anything the caller lacks; save locks and validates; `WidgetSeedIntegrityTest`
  is a real drift guard. Positive.
- `WidgetAnalyticsService` and `ActionCenterService` now expose failed widget/source metadata while
  retaining isolated degradation; a provider failure is no longer indistinguishable from an empty tile.
- `KpiSnapshotService` excludes soft-deleted work orders and caps completion at 100%. Budget utilization
  remains explicitly annual by contract; `$month` is retained for the shared KPI interface.
- Only `kpi:compute-monthly` is scheduled, so `kpi.*` widgets read one month behind (documented).
- Layout endpoints are intentionally ungated (service self-gates).

## 3. Admin

- Role/permission/override flows are lock-based and idempotent; `RequireSystemAdmin` + permission
  double-gates overrides; system roles are seed-only and uneditable. Positive.
- `SessionController::destroy` now protects the caller's own and system-administrator sessions and
  writes an audit row for successful termination.
- `GET /business-policies` now requires `business_policies.view`, exposing payment terms, VP
  threshold and currency codes to any authenticated user.
- `MasterDataImportService` documents only its registered importers and returns safe row errors while
  retaining diagnostic exceptions in application reporting.
- `RecordActivityFromEvent` still isolates an observability projection failure by design; it logs the
  failure rather than making a committed business event fail or retry indefinitely.

## 4. Auth / security

- Sanctum stateful cookie mode is the internal path; no Bearer/localStorage token usage; rate limiters
  (`auth` 5/min ip|email, `api` 300/min, `sensitive` 10/min, `public-form` 10/min). Password reset is
  sha256 single-use, row-locked, sessions revoked. Positive.
- Login lockout is per-user only; **unknown-email attempts are not counted** (mitigated by `throttle:auth`).
- `password-policy` complexity flags now share constants with `StrongPassword`; current-password reuse
  is rejected and depth zero trims history rather than accumulating hashes.
- `SessionTimeout` reads the short-timeout role set from settings, uses the database session's own idle
  timestamp when available, and leaves password-expiry enforcement to its dedicated middleware.
- **No 2FA anywhere.** Dispositioned pending a product/security decision; no implementation was
  invented as part of this audit remediation.
- Two public login endpoints (`/auth/sign-in`, `/auth/login`) remain as a compatibility alias. Both
  use the same hardened auth path; removal requires an API deprecation decision.

## 5. Edge

- No module, routes, device auth, resolver, or T2.x ingest remains. The live-code and agent-context
  references were removed; historical migrations and audit documents retain their original names.

## 6. Landing / Common

- Landing contact form requires `consent` and stamps `consent_at` server-side. Unsubscribe now uses a
  read-only signed GET confirmation page followed by a signed POST, and landing PII has configurable
  retention.
- `RunApprovalEscalations` reports failed/unstaffed candidates and exits FAILURE on candidate failure.
- `RunChainBottleneckCheck` now uses `AlertEngineService` for the unique-open-condition invariant.
- `ChainListenerRecoveryService` anchors recovery audit rows to the numeric outbox subject because
  listener-run primary keys are UUIDs while `audit_logs.model_id` is bigint.
- Strongly built: `OutboxService`/`OutboxDispatcher` (lease tokens + stale reclaim), `ChainBroadcaster`
  (unsupported class throws rather than skipping), `NotificationService` (deferred, batched prefs),
  `SystemActorService`/`SystemUserResolver`, `DocumentVaultService` (private disk, checksum, no-store),
  `DocumentSequenceService` (row-locked), `AlertEngineService` (unique open condition, bounded critical
  retry), `GlobalSearchService` (module row scopes reused), `ApprovalSourceScope` (board reuses module
  scopes).

## 7. Remediation Summary

- Added `business_policies.view`, dependency validation for module toggles, privileged custom-role
  authority checks, per-user bulk-role audit rows, audit JSON HashID conversion, import error redaction,
  dashboard cache invalidation, and row-scoped badge counts.
- Added `landing:prune-pii`, scheduled weekly, with `landing.pii_retention_months` configuration.
- Added notification dedupe keys and wired approval reminders/escalations to stable keys.
- Added focused regression coverage for password reuse/depth zero, supplier expiry, signed unsubscribe,
  notification replay, vault corruption, and the new feature gate.

## 8. Assumptions

- A1. No test run; source/grep. A2. The Nginx SPA carve-out is read as intentional from the inline
  comment; whether it is an accepted risk is a security decision. A3. Docker runtime config was read
  from files, not a running stack. A4. 2FA absence is from schema/middleware grep, not a product spec.
