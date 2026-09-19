# B2B Portal + Maintenance Audit

Date: 2026-09-18
 
## Re-audit 2026-09-19

Current verdict: **B2B open; Maintenance open**. Historical claims were rechecked against current code.

- B2B: supplier password expiry is still missing; customer invitation audit/active-state checks, reset-token pruning, supplier RFQ-document download, portal write throttles, customer response/RMA SPA reachability, and order idempotency remain open.
- Maintenance: breakdown MWO creation exists but no-actor handling is still fail-open; predictive duplicate/freshness, downtime mismatch/double-counting, due-widget visibility, dead notification route, and audit/integrity gaps remain.
- Full current classification: `RE-AUDIT-REGISTER-2026-09-19.md`.
Claims are **[confirmed]** (file:line/grep) or **[assumption/unverified]**.

---

## Part A — B2B Portal (`api/app/Modules/B2B/`)

### Walkthrough
- Two portal realms: supplier (POs, shipments, docs, invoices, deliveries/GRN, listings, PPAP read,
  RFQ quotes, SOA) and customer (catalog, SO negotiation, invoices, deliveries + proof + confirm,
  complaints/8D, RMA, SOA).
- Guards `supplier_portal`/`customer_portal` with `EnsurePortalGuard`; `B2BTenancyScopeMiddleware`
  registers per-tenant global scopes on 12 models and restores the registry in `finally`. Services
  scope explicitly by `vendor_id`/`customer_id` and re-check ownership on every bound endpoint.

### Findings
1. **Supplier portal never enforces password expiry.** `CheckPortalPasswordExpiry` is only on the
   customer group and hardcodes `CustomerPortalUser`; expired supplier passwords stay valid. **[confirmed]**
2. **Customer invite skips the audited wrapper.** `PortalAccessController::inviteCustomer` calls
   `PortalInvitationService` directly (no `portal_user.invited` audit row, no active-customer check),
   unlike `inviteSupplier` which routes through `PortalAccessService`.
3. **Token revoke is supplier-only** (no customer counterpart), though both models carry `HasApiTokens`.
4. **Reset tokens are never pruned** — no scheduled cleanup; the table grows unbounded despite 60-min
   expiry.
5. **Supplier cannot re-download its own uploaded RFQ documents** (`downloadDocument` permits only
   vendor-null requirement docs) — likely a dead-end.
6. Login response shapes differ between realms; `SupplierPortalUserResource` exposes
   `failed_login_attempts`/`locked_until` although the model hides them.
7. Authenticated writes have only the global `api` limiter (300/min); no per-endpoint throttle on
   expensive submissions.
8. `SupplierPortalDispatchGateway` correctly counts recipients and does not mark the PO sent.

---

## Part B — Maintenance (`api/app/Modules/Maintenance/`)

### Walkthrough
- `MaintenanceWorkOrderService` (create/assign/start/complete/cancel/log/spare parts), single
  `MaintenanceWorkOrderStateMachine`. Starting a machine MWO → machine `maintenance` + planned
  downtime; completing → `idle` + schedule recompute.
- `MaintenanceScheduleService`, `MachineHoursService` (daily recompute), `DowntimeAnalyticsService`,
  `SparePartUsageService`.
- `PredictiveMaintenanceService::recordAndEvaluate` is **unreachable over HTTP** (condition-reading
  routes commented) but still runs from the daily preventive job's `evaluateAllMachines`.

### Findings
1. **Breakdown → MWO gap (confirmed again).** `HandleMachineBreakdown` pauses the WO and opens a
   `breakdown` downtime with no `maintenance_order_id`; no listener/job creates a corrective MWO.
   `closeMachineDowntime` only closes rows keyed by `maintenance_order_id`.
2. **Predictive race guard is case-sensitive and ineffective on PG.** `hasOpenCorrectiveWoForMachine`
   uses `ilike` + `'predictive'` but the in-transaction re-check uses raw `like` + `'%predictive%'`
   against `[Predictive]` descriptions, which fails on Postgres.
3. **Condition-reading surface is disabled end-to-end** (routes commented, controller not imported)
   while the daily job still evaluates readings; `ConditionReadingHashIdTest` still exists despite a
   comment claiming it was removed.
4. **`trend()` hardcodes metrics**, conflicting with `metricOptions()`/settings, so newly configured
   metrics cannot be trended.
5. **`complete()` trusts operator `downtime_minutes`** instead of deriving from the downtime ledger
   the start path opened — WO downtime and downtime analytics can disagree.
6. **`MachineHoursService` inflates running hours** when a WO is left open (accrues to now) and sums
   every downtime ever; `topMachines` counts breakdowns per overlapping row while minutes are
   window-clipped (count vs minutes bases differ).
7. **Controller duplicates route permission checks** inline via `can()` on start/complete/cancel/log,
   against the authorize-in-request convention.
8. `MaintenanceWorkOrderResource` does per-row `Machine::find`/`Mold::find` (N+1).
9. `MaintenanceWorkOrderService::start()` can pull a running machine into maintenance without
   checking `current_work_order_id`; a mold MWO `complete()` frees an `in_use` mold unconditionally.

## Assumptions
- A1. No test run; source/grep. A2. Whether the condition-reading feature is permanently cut or
  temporarily disabled is not stated in code. A3. Portal role/permission grants not fully enumerated.
