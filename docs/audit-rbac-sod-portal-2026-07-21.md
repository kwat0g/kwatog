# RBAC · SoD · Dashboards · Notifications · Portals — Hardening Audit

> Scoped `/rebuild-audit` run. Focus: security/RBAC/SoD, per-role dashboards,
> notifications, customer/supplier portal UI. Not a full 17-module audit.
> Stage: pilot-credible + adviser-defense checkpoint. Date: 2026-07-21.
> Every claim cites `path:line`. Read-only discovery — no code changed.

---

## Executive summary (read this first)

**Headline: the five audited surfaces are already built and mostly solid.**
This is not a stub audit — RBAC, dashboards, notifications, and both portals
are real, wired end-to-end, and enforced server-side. The work left is
**hardening + alignment**, not construction. Ranked:

| # | Finding | Sev | Effort |
|---|---|---|---|
| 1 | **No SoD conflict matrix** — segregation is scattered one-off `if` guards, not a rule engine. Biggest tech-depth + differentiation gap; an adviser probing "show me your SoD design" finds ad-hoc checks. | P0 | M |
| 2 | **Two 4-eyes holes**: budget transfer + employee salary/bank/profile change have **no self-approval guard** — requester can approve own. | P0 | S |
| 3 | **`salary_adjustment` workflow is orphaned** — seeded 2-step maker-checker chain defined but never enforced by the engine (doc/code drift). Salary change is the textbook 4-eyes case. | P0 | S |
| 4 | **Row-level scoping is per-controller, not global** — action permission gates *what verb*, not *which rows*; a handler that forgets the dept/self filter leaks cross-department data despite correct permission. | P1 | M |
| 5 | **Notification digest is a user dead-end** — backend service + 07:05 schedule exist, but the preferences API rejects the `digest` channel (`in:in_app,email`) and the UI has no column. Unreachable without manual DB insert. | P1 | S |
| 6 | **HR dashboard omits payroll KPIs** — brief expects "HR sees payroll KPIs"; HR dashboard is workforce/attendance/leave only. Alignment gap. | P1 | S |
| 7 | **Portal defense-in-depth**: supplier `storeDeliverySchedule` skips service-layer PO→vendor ownership re-check (relies solely on FormRequest); 3 portal write paths skip `EdgeSystemUserResolver`. Latent, not an active exploit. | P1 | S |
| 8 | **Customer portal has no CoC download** — IATF differentiator; Toyota/Honda/Nissan customers expect Certificate of Conformance self-service. | P2 | M |
| 9 | **Alignment/dead-code drift**: sidebar `roles` allowlist enforced but never set on any item; sidebar bypasses the `isAdmin` short-circuit; 3 stale comments contradict shipped code. | P2 | S |

**2-week list for the defense checkpoint:** REC-01 (SoD matrix — the
showpiece), REC-02 (close 2 self-approval holes), REC-03 (wire salary
workflow), REC-06 (HR payroll KPIs), REC-09 (kill drift/dead-code). These
five convert "scattered + advertised" into "designed + demonstrable."

---

## Phase 1 — Ground-truth map (what exists)

**Stack:** Laravel 11 / PHP 8.3 API + React 18 / TS SPA. Custom RBAC (NOT
Spatie — `composer.json` has only `spatie/laravel-ignition`). Sanctum cookie
auth for employees; separate Sanctum **token** guards for portals.

**RBAC data model** — single role per user + override table:

| Table | Evidence | Key columns |
|---|---|---|
| `roles` | `database/migrations/0001_create_roles_table.php:13-18` | `name`, `slug` unique, `is_system` (`0126_*:41`) |
| `permissions` | `0002_create_permissions_table.php:13-21` | `slug` unique, `module` indexed |
| `role_permissions` | `0003_create_role_permissions_table.php:13-16` | composite PK `[role_id, permission_id]` |
| `users` | `0004_create_users_table.php:13-34` | **single** `role_id` FK, lockout cols |
| `user_permission_overrides` | `0127_*:22-34` | `type` grant/revoke, `granted_by`, `expires_at` |

**Scale:** **236 permissions**, **13 roles** (`RolePermissionSeeder.php:20-409,
417-641`). Convention `{module}.{resource}.{action}` enforced by regex in
`AuthServiceProvider.php:34-36`. Roles:
`system_admin, hr_officer, finance_officer, production_manager, ppc_head,
purchasing_officer, warehouse_staff, qc_inspector, maintenance_tech,
impex_officer, department_head, employee, driver`.

**Enforcement:** `permission` middleware alias → `CheckPermission`
(`bootstrap/app.php:44-45`, `CheckPermission.php:18-30`), **697 `permission:`
usages across 23 route files**. `Gate::before` + `hasPermission()` unify
`$user->can()` with slugs (`AuthServiceProvider.php:24-39`). Three
`system_admin` short-circuits (`User.php:151`, `CheckPermission.php:23`,
`AuthServiceProvider.php:29`).

**Approval engine:** `ApprovalService.php` — morph-based, DB-backed workflow
definitions, sequential single-approver, threshold-skip, delegation
(`ApprovalDelegation`), escalation (`ApprovalEscalationService.php`).

**Dashboards:** role-router `pages/dashboard/index.tsx:24-34` → **8 distinct
role dashboards**.

**Notifications:** `NotificationService.php:24-28` (in-app + email + Reverb
websocket), **37 send sites**, full SPA bell + list + preferences.

**Portals:** two Sanctum guards + separate `*_portal_users` tables
(`config/auth.php:16-44`), 18 supplier + 15 customer endpoints, 18 SPA pages.

### Doc/Code drift found (log immediately)

- `PurchaseOrderService.php:325-328` comment says vendor-SoD guard "never
  fires" — **stale**; migration `0222_add_created_by_to_vendors.php` +
  `VendorService.php:48-51` made it active.
- `NotificationBell.tsx:11-13` comment says realtime push is "a separate
  follow-up" — **stale**; `useNotificationRealtime.ts:22-29` already
  implements it.
- `salary_adjustment` workflow seeded (`WorkflowSeeder.php:87-93`) but **no
  `submit()` caller** — advertised, not enforced.
- Sidebar `roles` allowlist defined + enforced (`Sidebar.tsx:79-84,275`) but
  **set on zero menu items** — dead code.

---

## Phase 2 — Frame (what "aligned + hardened" means here)

The adviser will probe the exact axes the user named. For a PH IATF
manufacturer the bar is:

- **RBAC:** does an action check *who* AND *which rows*? (menu-hiding ≠
  security).
- **SoD:** can one person create-and-approve a money/master-data change? Is
  there a *designed* matrix, or scattered checks? This is the thesis
  differentiator vs prior PH ERP theses.
- **Dashboards:** does each role land on a screen that answers *their* first
  question Monday 7am? (QC → pareto, plant mgr → OEE, finance → AR aging.)
- **Notifications:** do the right people get the right alert, and can they
  tune it? Any advertised-but-dead paths embarrass on demo.
- **Portals:** can a supplier/customer only ever see *their own* rows, and is
  every write audit-traceable?

Differentiators the repo **actually delivers** (verified, not stubbed):
per-role dashboards (8), Reverb realtime notifications, dual B2B portals with
row-level tenancy + `EnsurePortalGuard` cross-guard-bleed defense
(`EnsurePortalGuard.php:20-44`), field-level PII masking
(`EmployeeResource.php:56-95`). Differentiator **advertised but weak**:
SoD/maker-checker — present as one-offs, missing as a design (REC-01).

---

## Phase 3 — Findings by bucket

### Security / SoD

- **No SoD conflict matrix anywhere.** Grepped `segregation`, `sod`,
  `conflict_matrix`, `incompatible_role`, `duty_conflict` — only hits are
  point-in-time `abort(403)` guards + override slugs. No data-driven rule
  answering "can user X both create a vendor AND approve a PO to it." Each
  conflict is hardcoded per-service. **Structural gap.**
- **Self-approval guards that DO exist** (good): PO vs vendor creator
  (`PurchaseOrderService.php:258,330-352`), JE post
  (`JournalEntryService.php:205,229-256`), payroll approve
  (`PayrollPeriodService.php:231-235`), payroll adjustment
  (`PayrollAdjustmentService.php:79-81`), overtime
  (`OvertimeService.php:164-167`), and all ApprovalService workflows
  (`ApprovalService.php:66-69,93-96`).
- **Self-approval guards MISSING** (holes): budget transfer
  (`BudgetTransferService.php:42-66` — `approve($transfer, $approvedBy)` never
  compares to `requested_by`), employee profile/salary/bank change
  (`ProfileUpdateRequestService.php:96-140` — reviewer id never compared to
  `requested_by`, both HR and Finance stages).
- **6 orphaned seeded workflows** (`WorkflowSeeder.php:87-152`):
  `salary_adjustment`, `department_transfer`, `maintenance_request`,
  `asset_disposal`, `separation_clearance`, `8d_report` — defined, never
  submitted to the engine.
- **Approvals are sequential single-approver only** — no parallel/quorum/M-of-N
  (`ApprovalService.php:59-62`).
- **17 `authorize(): return true`** FormRequests — all on public/portal/edge/
  driver surfaces relying on a *different* guard (portal Sanctum, edge token,
  FK self-scope), none on internal ERP module endpoints. Defensible but
  undocumented; security depends entirely on route guards being attached.

### RBAC / scoping

- **Field masking works** — `EmployeeResource.php:56-95` masks SSS/PhilHealth/
  Pag-IBIG/TIN/bank; unmask on self-view, `hr.employees.view_sensitive`, or
  system_admin. Same pattern in Customer/Vendor resources.
- **Row-level scoping is per-controller, not global** — no `addGlobalScope`
  anywhere. Dept scoping is ad-hoc (`LeaveRequestController.php:46-52` does it
  right, server-side). A handler that forgets the filter leaks rows despite a
  correct `permission:` slug. **Systemic risk.**
- **Per-user overrides** fully implemented with grant/revoke + expiry
  (`UserPermissionOverrideService.php`, `User.php:122-144`).

### Dashboards

- **8 role dashboards + router** (`dashboard/index.tsx:24-34`), redirect only
  if slug + gating permission both match (`:36-48`), else generic default.
  QC pareto present (`quality.tsx:383`), OEE present
  (`plant-manager.tsx:140`), finance AR/AP aging (`finance.tsx:40-52`).
- **HR dashboard omits payroll KPIs** (`hr.tsx:107-165` — attendance/leave/
  headcount only). Brief expected payroll KPIs. Alignment gap.
- Sidebar filters by permission + feature and **removes** hidden items
  (`Sidebar.tsx:272-286`) — good. But `roles` allowlist is dead code, and
  sidebar's raw `permissions.has()` (`:274`) bypasses the `isAdmin`
  short-circuit `usePermission().can()` uses (`usePermission.ts:8`) — a
  superuser with an empty perms set sees a thinner menu than pages they can
  open. Cosmetic.

### Notifications

- Full-stack, wired: `NotificationService.php:24-60` (3 channels), 37 send
  sites, Reverb broadcast (`UserNotificationCreated.php:13`, channel authz
  `routes/channels.php:49-52`), SPA bell + poll(30s) + websocket
  (`NotificationBell.tsx`, `useNotificationRealtime.ts`), list page,
  per-type×channel preferences matrix. Audience is role-scoped, not
  broadcast-to-all (`NotifyOnLowStockPrCreated.php:23-26`).
- **Digest = user dead-end** — service + 07:05 schedule exist
  (`NotificationDigestService.php`, `console.php:211-212`) but preferences API
  validates `in:in_app,email` (`NotificationController.php:80`) and UI has no
  digest column. Unreachable.
- **Quiet hours absent** (zero hits for `quiet_hour|snooze|dnd`).
- **Mold-80%/critical alerts bypass the bell** — separate `alerts` +
  `CriticalAlertEmail` subsystem (`AlertEngineService.php:425`); operator
  watching the bell won't see them.

### Portals

- Real: separate guards/tables, 18 supplier + 15 customer endpoints, 18 SPA
  pages, row-level tenancy enforced via `vendor_id`/`customer_id` filters +
  `abort(403)` + `EnsurePortalGuard`. Login hardening (5-attempt lockout,
  one-token-per-session) in `B2bAuthService.php:61-129`.
- **Gaps:** customer has **no CoC download** and **no profile update**;
  supplier orders are view-only (no place-order); `storeDeliverySchedule`
  (`SupplierPortalService.php:380-392`) skips service-layer PO→vendor
  ownership re-check (FormRequest-only); 3 write paths
  (`storeDeliverySchedule`, `uploadShippingDocument`) skip
  `EdgeSystemUserResolver` — safe only because those models lack
  `HasAuditLog`.

---

## Phase 4 — Recommendations

### [REC-01] SoD conflict matrix (data-driven, not scattered `if`s) — P0
- **Bucket:** security / missing-feature. **Module:** Auth/Admin, cross-cutting.
- **Why it matters:** The thesis differentiator the user named. Right now SoD
  is a scattered set of hardcoded guards; an adviser asking "walk me through
  your segregation-of-duties design" sees ad-hoc `abort(403)` lines, not a
  model. A *matrix* is demonstrable, testable, and beats prior PH theses.
- **What breaks without it:** cross-transaction conflicts are unmodeled —
  e.g. one user creating a PR **and** approving the PO on the same chain, or
  creating a vendor **and** the PR that uses it. No screen an auditor can read.
- **Proposal:**
  - `sod_conflict_rules` table: `(permission_a, permission_b, severity,
    active)` — declares incompatible permission pairs.
  - `SodService::check(User, permissionBeingUsed, contextRecord)` consulted at
    the existing approval choke-points; centralizes the 6 scattered guards.
  - Admin screen: matrix grid (permission × permission) + a "who violates SoD
    today" report over current role assignments — the demo artifact.
  - Seed the known conflicts (create-vs-approve PO, post-vs-approve JE,
    create-vendor-vs-approve-PO, maker-vs-checker payroll).
- **Dependencies:** existing permission catalog, approval engine.
- **Effort:** M (4–5d). **Priority:** P0. **Verdict:** enhance.
- **Evidence:** no matrix found (grep §Security); scattered guards at
  `PurchaseOrderService.php:330-352`, `JournalEntryService.php:229-256`.

### [REC-02] Close the two self-approval holes — P0
- **Bucket:** missing-feature (4-eyes). **Module:** Accounting/Budget + HR.
- **Why it matters:** Budget transfers move money; salary/bank changes are the
  #1 payroll-fraud vector. Both currently let the requester approve their own.
- **What breaks:** requester creates + approves a budget transfer or their own
  salary/bank change unchecked — an audit finding on day one of a pilot.
- **Proposal:** in `BudgetTransferService::approve` compare `$approvedBy` to
  `requested_by` and `abort(403)` (mirror `JournalEntryService.php:229-256`).
  Same in `ProfileUpdateRequestService::approve`/`financeApprove` vs
  `requested_by`. Add an override slug seeded to `system_admin` only, matching
  the existing pattern.
- **Effort:** S (1d incl. tests). **Priority:** P0. **Verdict:** enhance.
- **Evidence:** `BudgetTransferService.php:42-66`,
  `ProfileUpdateRequestService.php:96-140`.

### [REC-03] Wire the orphaned `salary_adjustment` workflow — P0
- **Bucket:** doc/code drift. **Module:** HR/Payroll.
- **Why it matters:** A 2-step maker-checker chain is *seeded and advertised*
  (`WorkflowSeeder.php:87-93`) but nothing calls it — the exact case where
  4-eyes is expected. Drift the adviser can catch by reading the seeder.
- **Proposal:** route salary changes through `ApprovalService::submit(...,
  'salary_adjustment')`, or delete the orphaned definitions if out of scope.
  Decide per workflow for the other 5 orphans (`department_transfer`,
  `asset_disposal`, `separation_clearance`, `8d_report`,
  `maintenance_request`) — wire or remove, don't leave advertised-dead.
- **Effort:** S (1–2d). **Priority:** P0. **Verdict:** enhance.
- **Evidence:** `WorkflowSeeder.php:87-152`, no `submit()` caller.

### [REC-04] Row-level scoping — global policy pass + tests — P1
- **Bucket:** cross-cutting security. **Module:** all.
- **Why it matters:** Permission slugs gate the *verb*, not the *row set*. A
  dept head with `leave.view` could see other departments if any handler
  forgets its filter. "Can a dept head see another dept's data?" is a natural
  adviser probe.
- **Proposal:** audit every list/detail controller for a dept/self filter;
  where a natural tenancy key exists (department_id, employee_id), add an
  Eloquent global scope or a shared `ScopesToActor` trait so the filter is
  default-on, not opt-in. Add feature tests asserting cross-dept 403/empty.
- **Effort:** M (4–6d, ~20 controllers). **Priority:** P1. **Verdict:**
  refactor.
- **Evidence:** no `addGlobalScope`; correct example
  `LeaveRequestController.php:46-52`.

### [REC-05] HR dashboard — surface payroll KPIs — P1
- **Bucket:** alignment. **Module:** Dashboard/HR.
- **Why it matters:** Brief expects "HR sees payroll KPIs"; Maria (HR clerk)
  lands on a screen with no payroll signal. Small, visible, aligns to spec.
- **Proposal:** add a payroll KPI strip to `hr.tsx` (current period status,
  headcount on payroll, pending adjustments, last net-pay total) gated by
  `payroll.*` permission so non-payroll HR users don't see it.
- **Effort:** S (1–2d). **Priority:** P1. **Verdict:** enhance.
- **Evidence:** `hr.tsx:107-165` (no payroll widgets).

### [REC-06] Notification alignment — digest reachable OR removed; quiet hours — P1
- **Bucket:** alignment / missing-feature. **Module:** Notifications.
- **Why it matters:** A shipped-but-unreachable digest is exactly the kind of
  advertised-dead path that undercuts a demo. Pick one: make it reachable or
  cut it.
- **Proposal:** (a) add `digest` to preferences validation
  (`NotificationController.php:80`) + a digest column in
  `notification-preferences.tsx`, OR remove the digest service/schedule. (b)
  Optionally add quiet-hours (`quiet_from`/`quiet_to` on preferences, gate
  `send()` for in-app push) — P2 nice-to-have. (c) Route mold-80% alerts into
  the bell too, or document why they're separate.
- **Effort:** S (1–2d for digest decision + quiet hours skeleton).
  **Priority:** P1. **Verdict:** enhance.
- **Evidence:** `NotificationController.php:80`, `NotificationDigestService.php`.

### [REC-07] Portal defense-in-depth — P1
- **Bucket:** security hardening. **Module:** B2B.
- **Why it matters:** Tenancy currently holds, but two latent gaps would bite
  if code moves. Cheap to close now.
- **Proposal:** (a) in `SupplierPortalService::storeDeliverySchedule` re-check
  PO→vendor ownership at service layer (`abort_if($po->vendor_id !==
  $vendorId, 403)`), not just in the FormRequest. (b) wrap
  `storeDeliverySchedule` + `uploadShippingDocument` writes in
  `EdgeSystemUserResolver::impersonate` so adding `HasAuditLog` later can't
  throw the FK violation. (c) act on the `SupplierAuthController.php:12-15`
  TODO — fold the 50+ inline `abort_if` tenancy guards into a model scope.
- **Effort:** S (2d). **Priority:** P1. **Verdict:** enhance.
- **Evidence:** `SupplierPortalService.php:380-392,150`,
  `SupplierAuthController.php:12-15`.

### [REC-08] Customer portal — CoC self-service download — P2
- **Bucket:** missing-feature / IATF differentiator. **Module:** B2B/Quality.
- **Why it matters:** Toyota/Honda/Nissan customers expect to pull the
  Certificate of Conformance per shipment. CoC is already generated from
  inspection data internally — exposing it to the customer portal is a
  high-visibility IATF differentiator for the defense.
- **Proposal:** customer portal endpoint + page to list shipments with a CoC
  and download the PDF, scoped to `customer_id`. Reuse existing CoC generation.
- **Effort:** M (3d). **Priority:** P2. **Verdict:** enhance.
- **Evidence:** customer routes have no CoC route (`B2B/routes.php:58-73`).

### [REC-09] Kill drift + dead code (alignment) — P2
- **Bucket:** alignment. **Module:** cross.
- **Why it matters:** "Everything perfectly aligned" = no advertised-dead
  paths or lying comments for the adviser to trip on.
- **Proposal:** fix 3 stale comments (`PurchaseOrderService.php:325-328`,
  `NotificationBell.tsx:11-13`), remove or use the sidebar `roles` allowlist
  (`Sidebar.tsx:79-84,275`), and align the sidebar permission check with
  `usePermission().can()`'s `isAdmin` short-circuit (`Sidebar.tsx:274`).
- **Effort:** S (0.5d). **Priority:** P2. **Verdict:** enhance.

### Verdicts per surface
| Surface | Verdict | Justification |
|---|---|---|
| RBAC core | **Keep** | 236 perms, real middleware (697 uses), overrides, masking. Solid. |
| SoD | **Enhance** | Guards exist but no matrix; 2 holes; orphaned workflows (REC-01/02/03). |
| Row scoping | **Refactor** | Per-controller → make default-on (REC-04). |
| Dashboards | **Keep** (+HR tweak) | 8 role dashboards work; only HR payroll-KPI gap (REC-05). |
| Notifications | **Keep** (+align) | Full-stack, realtime. Only digest dead-end + quiet hours (REC-06). |
| Portals | **Keep** (+hardening) | Real, tenant-scoped. Latent DiD gaps + CoC (REC-07/08). |

---

## Sequencing (to the defense checkpoint)

| Order | Items | Outcome demo-able |
|---|---|---|
| Week 1 | REC-02, REC-03, REC-09 | Every money/master-data change is 4-eyes; no drift. |
| Week 1–2 | REC-01 | **SoD matrix screen + violation report** — the showpiece. |
| Week 2 | REC-05, REC-06 | HR lands on payroll KPIs; notifications coherent. |
| Post-checkpoint | REC-04, REC-07, REC-08 | Row-scoping hardened, portal DiD, customer CoC. |

**If you only had 2 weeks:** REC-01, REC-02, REC-03, REC-05, REC-09 — highest
`credibility × blast-radius / effort`.

---

## Phase 5 — What I would NOT add

1. **Multi-role-per-user** — single `role_id` + override table is simpler and
   covers the exception cases; multi-role adds resolution complexity a 200-
   person single-plant ERP doesn't need.
2. **Parallel/quorum approvals** — the org is a 4-level linear chain
   (Staff→Head→Mgr→VP). Sequential is correct; M-of-N is scope creep.
3. **External IdP / SSO** — Sanctum cookie auth is right for this deployment;
   SAML/OIDC is enterprise theater for a single-site pilot.
4. **Quiet hours as P0** — nice, but no role's Monday-morning job breaks
   without it. Kept at P2.
5. **Rewriting the notification stack** — it's genuinely good (realtime +
   prefs + role-scoped audiences). Rewrite would be motion, not progress.

---

## Coverage statement

**Read (via 5 parallel discovery passes, all evidence-cited):** RBAC schema +
seeder + models + middleware + `AuthServiceProvider` + `CheckPermission` +
overrides + resource masking; `ApprovalService` + `ApprovalEscalationService`
+ `WorkflowSeeder` + all self-approval guards across Purchasing/Accounting/
Payroll/Leave/Loans/Budget/Profile; frontend guards + `usePermission` +
`authStore` + 8 dashboard pages + `Sidebar` + login flow; `NotificationService`
+ 37 send sites + Reverb wiring + SPA bell/list/preferences + digest/quiet-
hours; both portal guards + tables + 33 endpoints + 18 SPA pages + tenancy
scoping + `EdgeSystemUserResolver`.

**Not read (out of scope for this focus):** the 17 business modules' internal
CRUD/reporting, PDF templates, payroll/MRP compute engines, migration tooling,
seed-volume realism. A follow-up full `/rebuild-audit` (no `--focus`) would
cover those.

**Confidence:** high on the six audited surfaces — every finding cites
`path:line`. The one item I did **not** exhaustively verify: that all 17
`authorize(): return true` FormRequests have their portal/edge/driver route
middleware correctly attached (spot-checked, not proven for all 17).
