# B2B Portal + Maintenance Audit — FINISHED

Original audit date: 2026-09-18  
Final re-audit: 2026-09-23  
Status: **FINISHED** — every current finding in this report is fixed, already fixed in the current worktree, or documented as an intentional scope disposition.

The implementation was checked against the current source and regression tests. The `ogami_test_verify` database was used for the API suite so concurrent test resets cannot alter the default test database.

## B2B Portal

| Finding | Final disposition |
|---|---|
| Supplier password expiry was not enforced. | Enforced for both portal guards. The SPA redirects expired customer and supplier sessions to their realm-specific password-change route. Supplier expiry regression is covered in `PortalPasswordResetTest`; customer expiry was already covered by `CustomerPortalAuthTest`. |
| Customer invitations bypassed the audited service and did not check the customer’s active state. | The controller now uses `PortalAccessService`, which records `portal_user.invited`; invitation rejects inactive/deleted customers. Both portal realms verify their parent customer/vendor at login and on authenticated requests. Admin lists report inactive parents as inactive, and reactivation requires an active parent. Covered by portal access lifecycle and reset tests. |
| Customer portal tokens had no revocation counterpart. | Added customer token revocation through the access API, service and admin screen, with permission and audit coverage. |
| Reset-token rows accumulated indefinitely. | Added `portal:prune-reset-tokens`, scheduled daily. Expired and consumed tokens are removed; live tokens remain. Covered by `PortalPasswordResetTest`. |
| Suppliers could not re-download their own RFQ uploads. | RFQ downloads allow shared requirement documents and documents owned by the authenticated supplier’s vendor, while refusing another vendor’s files. The supplier quote screen links to uploaded documents. Covered by `SupplierPortalCrossTenantTest`. |
| Login/resource contracts exposed or differed on lockout details. | Customer and supplier login/me responses use their portal resources. `failed_login_attempts` and `locked_until` are no longer serialized; the derived status remains. |
| Authenticated portal writes only had the broad API limiter. | All authenticated portal mutations use the existing `sensitive` limiter; authentication and reset endpoints retain `auth` throttling. An HTTP regression verifies the customer-order write limit. |
| Supplier dispatch gateway behavior. | Verified as correct: recipient selection is counted and delivery is not marked sent merely because a notification was queued. No code change was needed. |
| Customer response and RMA APIs had no customer SPA reachability. | Customer order detail now supports accept, propose and decline responses. The portal now has return-request list, create and detail routes, with source lines resolved by the server. The Chromium portal E2E test exercises response submission and RMA creation. |
| Customer portal order creation was not idempotent. | `Idempotency-Key` is required. The key is unique per customer and paired with a request fingerprint; the customer row lock serializes first submissions. Same-key/same-payload retries return the existing order, while changed payloads are rejected. Covered by `CustomerPortalOrderTest`. |
| Supplier invoice submissions failed on the shipped default expense account. | Re-audit testing found that `5000` is a header account. The default now points to posting leaf `5010`; migration `0527` only corrects the shipped invalid value and preserves other configured mappings. `SupplierPortalServiceTest` passes. |

## Maintenance

| Finding | Final disposition |
|---|---|
| Machine breakdown handling could commit without a corrective MWO when no system actor existed. | It now throws instead of silently returning; the pause, downtime and alert transaction rolls back for queue retry. Tests cover the no-actor rollback and the linked corrective-MWO path. Breakdown-to-MWO creation was already present in the current worktree. |
| Predictive MWO duplicate guard had a PostgreSQL case-sensitive recheck; stale readings could trigger work. | Duplicate detection now happens inside the machine-row lock and uses the same case-insensitive search for both decisions. Readings older than the configurable `maintenance.predictive.max_reading_age_hours` (default 24) or in the future cannot trigger a corrective MWO. Covered by `MobileMaintenanceTest`. |
| Condition-reading HTTP routes were disabled while the daily service remained. | **Intentional scope disposition:** the desktop/mobile entry routes remain hidden because no IoT/edge source contract exists. The retained scheduled evaluator is freshness-bounded. The configured metric catalogue is now used by trend validation, so newly configured metrics do not hit a stale hardcoded allowlist. |
| MWO completion trusted operator-entered downtime and breakdown repair could double-open downtime. | Completion derives `downtime_minutes` from the linked ledger, unions overlapping intervals, and reuses an open breakdown interval rather than opening a second planned interval. Downtime analytics use disjoint intervals and count incidents by their start within the report window. Covered by maintenance downtime and summary tests. |
| Machine-hours recomputation accrued unowned open WOs to now and subtracted unrelated/all-time downtime. | Runtime is derived from ended WOs or the machine’s currently owned running WO; only that WO’s downtime is clipped/subtracted, and overlapping intervals are unioned. Covered by `MachineHoursServiceTest`. |
| Due-maintenance widget omitted a default audience. | Added `maintenance.due_schedules` to the production-manager default layout. PPC and maintenance-tech defaults already included it; `/maintenance/schedules` exists and is permission-gated. Covered by `DashboardWidgetDataTest`. |
| Breakdown notification pointed at a dead SPA path. | Notification destination now uses `/mrp/machines/{hash_id}`, which resolves to the registered machine detail route. `MachineBreakdownNotificationTest` asserts the exact destination. |
| Controller repeated route permission checks; MWO list resource queried targets per row. | Removed duplicate inline permission checks; route middleware and FormRequests remain authoritative. Machine/mold targets are eager-loaded for list/detail and include soft-deleted targets for readable history. Covered by query-count regression. |
| Polymorphic target integrity and condition-reading audit coverage were incomplete. | Schedule/MWO creation locks and validates the target. PostgreSQL checks limit target type/id; triggers reject nonexistent/deleted targets and prevent hard deletion while history references the row. Condition readings now use `HasAuditLog` in addition to `recorded_by`. Covered by PostgreSQL integrity tests. |
| Machine ownership and mold completion guards. | Verified already present: maintenance cannot take a running/assigned production machine, and completion does not release a mold still in use. Covered by `MaintenanceWorkOrderMachineDowntimeTest`. |

## Verification evidence

- API focused suite on the isolated verification database: **210 tests passed, 899 assertions** across B2B, RFQ/supplier invoicing, Maintenance, Production breakdown notifications, and dashboard widgets.
- PHPStan, scoped to the changed API areas: **no errors**.
- SPA unit suite: **313 tests passed**; TypeScript typecheck and ESLint passed.
- SPA production build passed in the Compose container. The host build path encounters the workspace’s existing root-owned `spa/dist/assets`; the container build is the repository’s documented build path.
- Chromium customer-portal E2E: **5 tests passed**, including response submission and RMA creation.

## Scope disposition

No IoT/edge source is available, so predictive condition-reading UI and HTTP routes remain intentionally hidden. The daily evaluator and service-level regression coverage remain, with stale readings excluded. The report’s other previously open findings are fixed or verified as already satisfied above.
