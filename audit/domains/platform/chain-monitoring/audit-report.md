# M013 — chain-monitoring audit report

Status: 📋 Plan Ready  
Session: 2026-08-25  
Claim: platform / chain-monitoring  
Scope: durable chain-step publication, bottleneck detection and alerting, listener recovery, permissions/routes, and the SPA chain/recovery/dashboard surfaces.

## Decision

No application source fixes were applied in this session. The finding set spans RBAC policy, negative lifecycle semantics, settings contracts, migration integrity, role dashboard composition, and shared navigation/accessibility components. The majority of fixes are therefore separate-session work. The module is released as 📋 Plan Ready with the ordered remediation work in action-plan.md.

## Findings

### F-001 — Bottleneck data is globally readable behind a shared role permission

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- api/routes/api.php:119-121 protects GET /chain/bottlenecks only with dashboard.view_bottlenecks.
- api/app/Common/Controllers/ChainBottleneckController.php:24-35 calls every detector and treats audience as an optional, caller-supplied filter. Omitting it returns all detector groups.
- api/app/Common/Controllers/ChainBottleneckController.php:52-57 also returns the aggregate automation summary without an audience boundary.
- api/database/seeders/RolePermissionSeeder.php:519-520,545-546,580-581,599-600 grants the bottleneck and recovery permissions to finance, production, PPC, and purchasing roles.
- The seeded detector policy contains audiences outside those four roles, including warehouse_staff, qc_inspector, impex_officer, and next_approver at api/database/migrations/0331_seed_chain_bottleneck_settings.php:11-18.

Impact: A user who has the shared widget permission can omit ?audience or choose another audience and receive cross-module document numbers, statuses, SLA age, and system-wide automation counts. The SPA narrows finance and PPC requests, but that is not an authorization boundary.

### F-002 — Cancellation and rejection are represented as successful terminal progress

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- api/app/Common/Support/ChainDefinitions.php:38-51,64-73,86-97,108-115,126-151,162-171 maps cancelled SO/WO/PO/bill/invoice and cancelled delivery or rejected GRN to the normal terminal step (closed or confirmed).
- api/app/Common/Services/ChainBroadcaster.php:80-90 uses that mapping for the durable ChainStepAdvanced event and api/app/Common/Services/OutboxService.php:100-123 persists the resulting step in chain_step_runs.
- api/app/Common/Events/ChainStepAdvanced.php:63-74 exposes active_step and completed_steps, but has no terminal outcome field for rejected/cancelled progress.
- The existing SPA contract supports rejected and skipped states at spa/src/types/chain.ts:1-13, while the resolver tests explicitly pin cancelled bill and invoice statuses to closed at api/tests/Unit/ChainDefinitionsTest.php:137-156.

Impact: The raw new_status still carries cancelled or rejected, but the canonical active/completed representation and durable step ledger present the transition as ordinary forward completion. Consumers cannot render or query a negative terminal outcome from the chain evidence alone.

### F-003 — Chain policy settings accept malformed values and silently disable detectors

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- api/app/Modules/Admin/Requests/UpdateSettingRequest.php:362 validates dashboard.chain_bottlenecks only as a non-empty array; it does not validate detector keys or nested hours, audience, and label fields.
- api/app/Modules/Admin/Requests/UpdateSettingRequest.php:395 applies the same shallow validation to workflow.chain_definitions.
- api/app/Common/Services/ChainBottleneckService.php:106-120 only checks key presence, casts arbitrary values, and allows zero/negative hours or empty/stringified values.
- Unknown detector names return [] at api/app/Common/Services/ChainBottleneckService.php:122-140, while missing or malformed detector definitions also return [] at lines 110-115.
- api/app/Common/Support/ChainDefinitions.php:193-201,246 filters malformed custom definitions and silently merges the remainder with defaults.

Impact: An administrator can save a structurally valid setting that causes a detector to disappear, uses an invalid SLA window, or introduces a definition that is silently ignored. The API and hourly command can remain successful while alert coverage changes without an explicit operator-visible failure.

### F-004 — Navigation maps do not cover the entity types emitted by chain monitoring

Classification: Broken  
Priority: P1  
Session: same-session-ok, but held because the overall module requires separate work

Evidence:

- The built-in GRN detector is seeded at api/database/migrations/2026_08_10_310000_seed_grn_incoming_qc_chain_bottleneck.php:23-29 and verified to emit entity_type = grn at api/tests/Feature/Chain/ChainBottleneckServiceTest.php:339-377.
- spa/src/components/dashboard/ChainBottleneckWidget.tsx:192-206 has no grn or purchase_order case, so its fallback destination is #.
- The duplicated PPC map has the same omission at spa/src/pages/dashboard/ppc.tsx:691-702.
- The recovery page's correlated-record map at spa/src/pages/chains/recovery.tsx:65-90 has no sales_order case, although api/app/Modules/CRM/Services/SalesOrderService.php:334-340 records sales-order chain steps and the recovery row renders the link only when entityHref returns a URL at spa/src/pages/chains/recovery.tsx:332-340.

Impact: A finance or plant-manager user can click View on a real GRN bottleneck and land on #; a listener recovery row for a sales-order chain step shows no correlated-record link. The tracker page has a more complete mapping at spa/src/pages/chains/index.tsx:49-77, but the mappings are duplicated and can drift.

### F-005 — Purchasing is granted bottleneck visibility but has no bottleneck dashboard surface

Classification: Missing  
Priority: P2  
Session: separate-recommended

Evidence:

- api/database/seeders/RolePermissionSeeder.php:586-600 grants purchasing_officer dashboard.view_bottlenecks and both recovery permissions.
- The role dashboard route exists at spa/src/routes/dashboardRoutes.tsx:68-70, but spa/src/pages/dashboard/purchasing.tsx:245-347 renders purchasing KPIs, queues, charts, stock-out, and forecast panels without ChainBottleneckWidget.
- The widget is mounted on finance at spa/src/pages/dashboard/finance.tsx:181-183, plant manager at spa/src/pages/dashboard/plant-manager.tsx:236, and PPC has a co-located bottleneck query/render at spa/src/pages/dashboard/ppc.tsx:495-501,613-614.

Impact: Purchasing can call the API or open Automation Recovery, but the permission named “View Chain Bottleneck Widget” does not result in a widget on the role's primary dashboard. The intended audience for purchasing versus approval-owner bottlenecks also remains implicit.

### F-006 — Automation health can report Healthy while listener outcomes are unclassified

Classification: Incomplete  
Priority: P2  
Session: same-session-ok, but held because the health contract should be decided with operations

Evidence:

- api/app/Common/Services/ChainBottleneckService.php:638-650 counts unclassified listener outcomes and exposes the count.
- api/app/Common/Services/ChainBottleneckService.php:71-89 does not include that count in the Healthy/attention decision.
- spa/src/components/dashboard/ChainBottleneckWidget.tsx:158-165 displays failed, manual, and skipped outcomes, but not unclassified outcomes.
- api/app/Common/Services/ChainListenerRunService.php:355-365 intentionally swallows telemetry-write failures so business jobs continue; this makes an unknown outcome a plausible production state.
- The current test asserts an unclassified count at api/tests/Feature/Chain/ChainBottleneckServiceTest.php:479-491, but only alongside other failures that already force attention.

Impact: A completed listener whose outcome telemetry was not recorded can leave the automation badge Healthy and give operators no indication that the business result is unknown. The fix must distinguish expected in-flight rows from terminal rows with missing outcome data.

### F-007 — Chain links do not consistently provide the required keyboard focus treatment

Classification: Polish  
Priority: P2  
Session: same-session-ok

Evidence:

- spa/src/components/chain/ChainHeader.tsx:32-38 puts focusRing on a non-focusable inner div; the interactive wrapper at lines 80-94 uses focus:outline-none.
- spa/src/styles/globals.css:28-35 supplies a global focus outline for buttons, inputs, selects, textareas, and role=button, but not anchors.
- Dashboard and recovery links also omit the shared ring at spa/src/components/dashboard/ChainBottleneckWidget.tsx:116-121, spa/src/pages/chains/index.tsx:201-204, spa/src/pages/dashboard/ppc.tsx:677-682, and spa/src/pages/chains/recovery.tsx:336-339.
- The design contract requires every interactive element to remain keyboard reachable with a visible focus ring at docs/DESIGN-SYSTEM.md:280-287,523-530.

Impact: Keyboard users can focus chain links without a visible indicator, especially in ChainHeader where the focus classes are attached to the nested non-focusable node.

### F-008 — Durable ledger relationships are not enforced at the database boundary

Classification: Incomplete  
Priority: P2  
Session: separate-recommended

Evidence:

- api/database/migrations/2026_08_10_110000_create_event_outbox_and_chain_step_runs.php:38-62 makes chain_step_runs.outbox_id unique but does not add a foreign key to event_outbox.
- api/database/migrations/2026_08_10_120000_create_chain_listener_runs.php:19-37 indexes chain_listener_runs.outbox_id without a foreign key.
- api/database/migrations/2026_08_10_190000_add_chain_listener_recovery_state.php:25-34 indexes replayed_from_id without a self-referencing foreign key.
- api/app/Common/Services/ChainListenerRecoveryService.php:39-45,448-463 eager-loads these relationships and serializes null related records without treating a detached ledger row as an integrity alert.

Impact: Manual cleanup, retention, or a partial write outside the service boundary can leave chain or listener records that no longer point to their source event. The recovery UI then presents an incomplete correlation rather than a visible data-integrity exception. The retention policy needs to decide whether source rows are immutable, restricted from deletion, or explicitly archived together.

## Verified strengths

- api/app/Common/Services/ChainBroadcaster.php:22-25,101-111 stages chain evidence durably and rethrows staging failures so a business transition cannot commit without its chain record.
- The outbox dispatcher and chain ledger are updated together in transactions, and focused tests cover publication, retry, stale leases, and replay behavior.
- api/app/Common/Services/ChainListenerRecoveryService.php:358-401 blocks active runs and restricts replay to approved queued listener classes/methods.
- Replay and resolve actions are audited, and the recovery API intentionally omits event payload data from its serialized response.

## Verification

Passed:

- docker compose run --rm api ./vendor/bin/phpunit tests/Unit/ChainDefinitionsTest.php tests/Unit/ChainBroadcasterTest.php tests/Feature/Chain/ChainBottleneckServiceTest.php tests/Feature/Chain/ChainListenerRecoveryControllerTest.php tests/Feature/Chain/ChainEventDispatchTest.php tests/Feature/Chain/ChainListenerWiringTest.php tests/Feature/Infrastructure/ChainListenerRunTest.php — 62 tests, 293 assertions.
- docker compose run --rm spa npm run test:run -- src/components/dashboard/ChainBottleneckWidget.test.tsx — 5 tests passed.
- docker compose run --rm spa npm run typecheck — passed.
- PHP syntax checks over api/app/Common — passed.

Evidence limits:

- Direct host-side test execution could not resolve the configured PostgreSQL hostname db; the container-network run above is the authoritative focused result.
- No browser-driven keyboard audit, forbidden-audience API test, or live Redis/Reverb outage/replay drill was run in this session.
- No source fix was applied, so no fix-log entry is required.
