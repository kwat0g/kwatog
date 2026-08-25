# M045 — Fleet & Driver Action Plan

**Audit date:** 2026-08-25  
**Plan status:** Partially executed; remaining items need decisions and re-audit

## Ordered fixes

### 1. Repair the future-date Loading guard (F001)

- **Scope:** small
- **Session recommendation:** same-session-ok
- Normalize the driver status request to `DeliveryStatus` before applying the date guard, or compare scalar values consistently.
- Keep the HTTP regression test that expects 422 for a future delivery and add a direct service-level assertion if useful.
- Re-run the focused fleet/driver test set.

### 2. Define and enforce scheduled vehicle reservation (F002)

- **Scope:** large
- **Session recommendation:** separate-recommended
- Decide whether a vehicle may be reserved by only one Scheduled delivery for an overlapping dispatch window.
- Enforce the rule transactionally under the existing delivery/vehicle locks; do not rely on the vehicle's later `in_use` status.
- Make the assignment UI reflect the same reservation rule.
- Add sequential and concurrent duplicate-assignment tests, including two auto-drafted deliveries.

### 3. Resolve internal dispatch RBAC (F003)

- **Scope:** medium
- **Session recommendation:** separate-recommended
- Confirm whether warehouse staff, purchasing, or a dedicated dispatcher owns assignment and staging.
- If they do, seed the least-privilege permission through `RolePermissionSeeder` and test the allowed/denied matrix. If not, document system-admin-only dispatch and align the module role metadata and process flow.

### 4. Preserve or explicitly define historical vehicle identity (F004)

- **Scope:** medium
- **Session recommendation:** separate-recommended
- Decide whether historical delivery detail must show an archived vehicle.
- If required, add an intentional `withTrashed` read path or snapshot the plate/name at assignment; cover archive-after-delivery and detail responses.

### 5. Complete or retire the vehicle asset/maintenance contract (F005)

- **Scope:** large
- **Session recommendation:** separate-recommended
- Confirm ownership and lifecycle semantics with the Assets and Maintenance modules.
- Either expose/validate the existing `asset_id`, add the vehicle maintainable type and matching behavior, and test the cross-module path, or remove/deprecate the unused schema contract through the approved migration process.

### 6. Make fleet pagination complete (F006)

- **Scope:** small
- **Session recommendation:** same-session-ok
- Connect `DataTable` pagination to vehicle API metadata, preserve search/status/archive filters across pages, and add a >100-vehicle UI/API test.

### 7. Bring driver floor controls and copy into alignment (F007–F008)

- **Scope:** small
- **Session recommendation:** same-session-ok
- Use the 44px touch sizing for camera, gallery, and upload actions.
- Rewrite Delivered-state text so driver completion and customer receipt evidence are distinct.
- Verify the flow at the narrow floor viewport.

### 8. Update process-flow documentation (F009)

- **Scope:** small
- **Session recommendation:** same-session-ok
- Document assignment, driver options, assignment reason, permission ownership, and the handoff from QC auto-draft to dispatch.
- Apply the final RBAC decision from item 3 rather than documenting an assumed role.

## Execution decision

The dedicated fixing session completed F001 and F006–F008. F002–F005 remain deferred pending the decisions described in the plan, and F009 depends on F003. The next session that claims this module should read this plan and `fix-log.md`, resolve or receive those decisions, then implement the remaining items and re-check the affected findings.
