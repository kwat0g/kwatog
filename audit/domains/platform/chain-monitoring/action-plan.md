# M013 — chain-monitoring action plan

Status: 📋 Plan Ready  
Recommended execution: separate sessions for the P1 items; do not combine RBAC, lifecycle semantics, and migrations into a quick polish pass.

## Ordered fixes

### 1. Enforce server-side bottleneck audience scope

Findings: F-001  
Scope: large  
Session: separate-recommended

Define the authoritative role/audience policy. Derive allowed detector audiences from the authenticated user's permissions/role policy on the API; never trust the optional query string as authorization. Decide whether plant/system operators get a global monitor permission and whether finance, PPC, production, and purchasing get role-scoped views. Scope the automation summary consistently or split global infrastructure health into a separately authorized response.

Acceptance:

- A role cannot retrieve another role's rows by omitting or changing audience.
- Global-monitor access is explicit and tested.
- Feature tests cover no filter, allowed filter, forbidden filter, and aggregate automation data.

### 2. Make negative terminal outcomes first-class

Findings: F-002  
Scope: large  
Session: separate-recommended

Agree on the canonical representation for cancelled and rejected transitions. Extend the durable event/ledger contract with an explicit terminal outcome or a separate rejected/cancelled step, preserve the raw status, and mirror the decision in SPA chain types and renderers. Add transition tests for every supported entity type and every negative terminal status.

Acceptance:

- Cancelled/rejected records cannot be mistaken for successful completion in the durable ledger, event payload, or UI.
- Realtime and fetched chain views agree.
- Existing cancellation behavior and downstream listener semantics are covered before migration/backfill decisions are made.

### 3. Introduce typed validation and fail-closed normalization for chain settings

Findings: F-003  
Scope: medium  
Session: separate-recommended

Add nested request validation for detector keys, labels, positive bounded hours, and approved audiences. Add a typed validator/normalizer shared by ChainBottleneckService and ChainDefinitions; reject or surface invalid persisted settings instead of silently returning empty detector output. Decide whether custom chain definitions are allowed and, if so, validate every type, step, and status-map target against an explicit contract.

Acceptance:

- Invalid admin payloads return validation errors.
- Invalid persisted rows produce a visible unavailable/configuration state and an operational alert, not an empty scan.
- Tests cover missing keys, negative/zero hours, wrong scalar types, unknown detector keys, and stale custom definitions.

### 4. Restore durable ledger referential integrity

Findings: F-008  
Scope: medium  
Session: separate-recommended

Choose and document the retention policy for event_outbox, chain_step_runs, and chain_listener_runs. Add foreign keys or an explicit archival/repair process for outbox and replay lineage; backfill or quarantine existing orphans before enforcing constraints. Expose an integrity metric if historical rows may intentionally outlive source records.

Acceptance:

- A source event cannot be deleted or detached without the documented archival behavior.
- Orphaned rows are detected in CI/health checks.
- Recovery serialization distinguishes an intentionally archived source from an unexpected broken correlation.

### 5. Consolidate entity navigation and complete role surfaces

Findings: F-004, F-005  
Scope: medium  
Session: separate-recommended for the audience decision; same-session-ok for the pure mapping work afterward

Create one typed entity-to-route registry shared by the bottleneck widget, PPC dashboard, tracker, and recovery page. Include all currently emitted types, especially GRN and sales order, and add route-map tests. After the audience policy is settled, mount the appropriately scoped bottleneck surface on the purchasing dashboard and verify system-admin behavior.

Acceptance:

- Every emitted entity type resolves to a real route or an intentional disabled state; no production fallback is #.
- Purchasing sees the monitor surface promised by its permission, with only authorized audiences.
- Navigation tests assert GRN, sales order, PO, and every detector entity type.

### 6. Correct unknown-outcome health semantics

Findings: F-006  
Scope: small  
Session: same-session-ok after operations sign-off

Separate expected processing/retrying rows from terminal rows with missing or invalid outcome telemetry. Mark the latter as attention (or an explicit unknown state), show the count in the widget, and add an isolated test where the only anomaly is unclassified terminal telemetry.

Acceptance:

- Healthy cannot hide a terminal listener with no known business outcome.
- In-flight listeners do not create false incidents.
- The widget and alert command use the same status contract.

### 7. Apply the shared focus and surface polish

Findings: F-007  
Scope: small  
Session: same-session-ok

Move focus classes to the actual Link/button elements, remove only redundant outline suppression, and apply the shared focus ring to dashboard/recovery links. Replace translucent info/error surface classes with opaque semantic tokens per the design system. Add an automated keyboard/focus smoke check for ChainHeader and the bottleneck/recovery links.

Acceptance:

- Tab focus is visibly indicated for every chain link and action.
- No chain-monitoring surface uses bg-*/opacity or border-*/opacity where the design system requires opaque surfaces.
- Reduced-motion behavior remains intact.

## Session recommendation

The module remains 📋 Plan Ready. Do not apply any of the above in the current audit session: F-001, F-002, F-003, and F-008 require separate policy or migration decisions, and they dominate the risk. F-004, F-006, and F-007 are safe follow-up fixes once those contracts are settled.
