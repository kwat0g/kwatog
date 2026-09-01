# M045 — Fleet & Driver Audit Report

**Domain:** Supply Chain  
**Tier:** 3  
**Audit date:** 2026-08-25  
**Audit mode:** Full re-audit after visible source changes since the previous report  
**Status:** 🔁 Needs Re-audit

## Scope and re-audit trigger

This audit is limited to `supply-chain/fleet-driver`. The worktree already contained substantial uncommitted changes in this module when it was claimed, including the assignment workflow, driver resource, fleet UI, and hardening tests. Those changes were treated as existing work and were not attributed to this session. The previous report was therefore not sufficient evidence for the current code and was replaced by this report.

## Discovery

The module now contains:

- Fleet CRUD, soft-delete/archive and restore endpoints, status guards, and a fleet management page (`api/app/Modules/SupplyChain/Controllers/VehicleController.php:32-133`, `spa/src/pages/supply-chain/fleet.tsx:1-208`).
- Delivery creation, assignment, status, receipt upload, and driver-option endpoints (`api/app/Modules/SupplyChain/routes.php:95-112`).
- A self-scoped driver API and a narrow driver resource rather than the broad internal delivery resource (`api/app/Modules/SupplyChain/Services/DriverDeliveryService.php:35-120`, `api/app/Modules/SupplyChain/Resources/DriverDeliveryResource.php:11-61`).
- Feature- and permission-gated driver routes (`api/app/Modules/SupplyChain/routes.php:137-145`) and a touch-oriented driver SPA (`spa/src/routes/driverRoutes.tsx:13-28`).
- Hardening coverage for assignment, vehicle lifecycle, driver scoping, resource shape, feature gating, and lifecycle concurrency in `api/tests/Feature/SupplyChain/FleetDriverHardeningTest.php`, `DriverDeliveryTest.php`, `CreateDeliveryDriverGateTest.php`, and `DeliveryLifecycleConcurrencyTest.php`.

The remaining missing or incomplete boundaries are reservation of vehicles before loading, normal-role dispatch permission coverage, historical vehicle identity after archive, the existing asset/maintenance integration, large-fleet pagination, and a small number of floor-UI details.

## Findings

### F001 — Future delivery can enter Loading

**Classification:** Broken  
**Priority:** P1  
**Session recommendation:** separate-recommended

`DriverDeliveryController::updateStatus()` passes the validated status as a string (`api/app/Modules/SupplyChain/Controllers/DriverDeliveryController.php:33-40`). The service compares that string to the `DeliveryStatus::Loading` enum object before applying the future-date guard (`api/app/Modules/SupplyChain/Services/DriverDeliveryService.php:98-106`). The comparison is therefore false, so a driver can move a future-scheduled delivery into Loading. The regression test `FleetDriverHardeningTest::future_delivery_cannot_enter_loading` failed at `api/tests/Feature/SupplyChain/FleetDriverHardeningTest.php:111-122`: expected 422, received 200.

This is a state/lifecycle invariant failure. Normalize the value to `DeliveryStatus` before the comparison, or compare enum values consistently, and retain a regression test through the HTTP endpoint.

### F002 — Scheduled vehicle assignments are not reserved

**Classification:** Broken  
**Priority:** P1  
**Session recommendation:** separate-recommended

The assignment service permits only Scheduled deliveries, then calls `assertDispatchAssignmentAvailable()` (`api/app/Modules/SupplyChain/Services/DeliveryService.php:299-318`). That availability check rejects only another delivery already in Loading or InTransit (`api/app/Modules/SupplyChain/Services/DeliveryService.php:355-367`); it does not reject or reserve another Scheduled delivery using the same vehicle. The detail UI presents every `available` vehicle without filtering scheduled reservations (`spa/src/pages/supply-chain/deliveries/detail.tsx:73-85`).

Two scheduled deliveries can consequently accept the same vehicle. The conflict is discovered only when the first delivery loads and the vehicle changes state, leaving the second delivery assigned but unable to progress. Define the reservation invariant and enforce it under the existing transaction/lock, with a concurrency regression test.

### F003 — Dispatch permission is catalogued but not assigned to normal operating roles

**Classification:** Incomplete  
**Priority:** P1 if warehouse/purchasing staff are intended dispatchers; otherwise documentation/role metadata is incomplete  
**Session recommendation:** separate-recommended

The assignment endpoint and request require `supply_chain.deliveries.create` (`api/app/Modules/SupplyChain/routes.php:107-108`, `api/app/Modules/SupplyChain/Requests/AssignDeliveryRequest.php:16-19`). The permission is present in the catalog, but the seeded `warehouse_staff` role receives only `supply_chain.deliveries.view` (`api/database/seeders/RolePermissionSeeder.php:626-652`); purchasing and import/export roles likewise receive view but not create (`api/database/seeders/RolePermissionSeeder.php:606-623`, `686-692`). The process flow says warehouse staff pick/load outbound deliveries (`docs/PROCESS-FLOWS.md:341-348`), while the module metadata lists only `system_admin` and `driver` roles.

This needs a business decision rather than an assumption: either seed a dedicated dispatcher/staging permission to the intended role(s), or explicitly document that system administrators are the only internal users allowed to assign and progress deliveries.

### F004 — Archived vehicles disappear from historical delivery detail

**Classification:** Incomplete  
**Priority:** P2  
**Session recommendation:** separate-recommended

The archive guard allows a vehicle to be soft-deleted once it has no Scheduled, Loading, or InTransit delivery (`api/app/Modules/SupplyChain/Controllers/VehicleController.php:96-121`). `Delivery` then uses a normal vehicle relation that excludes soft-deleted vehicles (`api/app/Modules/SupplyChain/Models/Delivery.php:52-55`), while the delivery detail service loads that normal relation (`api/app/Modules/SupplyChain/Services/DeliveryService.php:186-204`). A delivered or confirmed historical delivery can therefore retain `vehicle_id` but render no vehicle identity after the vehicle is archived.

Decide whether historical delivery records must retain the archived vehicle label/plate. If yes, use an intentional `withTrashed` read path or snapshot the relevant identity; if no, document the behavior and cover it with an acceptance test.

### F005 — Vehicle asset and maintenance integration remains inert

**Classification:** Incomplete  
**Priority:** P2  
**Session recommendation:** separate-recommended

Migration `0106` adds a nullable vehicle `asset_id` foreign key (`api/database/migrations/0106_add_asset_id_to_vehicles_table.php:9-20`), but `Vehicle` has no asset relation or fillable field (`api/app/Modules/SupplyChain/Models/Vehicle.php:15-31`), and the vehicle resource/controller do not expose or validate it (`api/app/Modules/SupplyChain/Resources/VehicleResource.php:15-25`, `api/app/Modules/SupplyChain/Controllers/VehicleController.php:44-94`). Maintenance schedule matching still handles only Machine and Mold (`api/app/Modules/Maintenance/Enums/MaintainableType.php:7-16`, `api/app/Modules/Maintenance/Services/MaintenanceScheduleService.php:56-80`).

The schema promises a cross-module relationship that the application cannot use. Either complete the vehicle asset/maintenance path end-to-end, or remove/deprecate the unused schema contract after confirming the intended domain boundary.

### F006 — Fleet list has a hard 100-row ceiling in the SPA

**Classification:** Incomplete  
**Priority:** P2  
**Session recommendation:** same-session-ok

The API paginates vehicle results and caps `per_page` at 100 (`api/app/Modules/SupplyChain/Controllers/VehicleController.php:32-42`). The fleet page always requests 100 and does not pass pagination state or an `onPageChange` handler to `DataTable` (`spa/src/pages/supply-chain/fleet.tsx:63-66`, `173`). Once more than 100 vehicles exist, the remaining fleet is not reachable from the page.

Wire the table to the returned pagination metadata, or make the page explicitly search-first with a tested result limit.

### F007 — Driver photo-capture actions miss the floor hit target

**Classification:** Polish  
**Priority:** P2 for floor/PWA usability  
**Session recommendation:** same-session-ok

The design system requires 44px minimum hit targets for floor controls (`docs/DESIGN-SYSTEM.md:382-385`, `spa/src/components/ui/Button.tsx:17-27`). The camera, gallery, and upload buttons use `size="lg"`, whose component height is 36px, in the driver photo flow (`spa/src/pages/driver/DriverPhotoCapture.tsx:123-140`). Increase these actions to the touch sizing used elsewhere in the driver flow and verify on a narrow viewport.

### F008 — Delivered-state confirmation copy describes the wrong actor

**Classification:** Polish  
**Priority:** P3  
**Session recommendation:** same-session-ok

The driver detail page labels the Delivered transition as customer receipt confirmation and tells the driver to capture the receipt photo first (`spa/src/pages/driver/DriverDeliveryDetail.tsx:184-186`). The endpoint records the driver's delivery progression; customer confirmation is a distinct later state. Update the copy to describe delivery completion and receipt evidence without conflating the actors.

### F009 — Process-flow documentation omits the new assignment contract

**Classification:** Incomplete  
**Priority:** P2  
**Session recommendation:** same-session-ok

The documented endpoint list covers delivery creation, status, proof, and confirmation but omits the assignment and driver-options endpoints (`docs/PROCESS-FLOWS.md:327-332`). The test flow says to assign a vehicle but does not describe driver selection or the assignment reason (`docs/PROCESS-FLOWS.md:341-348`), even though those are now required by the API. Update the flow and role ownership after the RBAC decision in F003.

## Verified strengths

- Driver routes now enforce authentication, session timeout, the supply-chain feature gate, and driver access permission (`api/app/Modules/SupplyChain/routes.php:137-145`).
- Driver queries are self-scoped by `driver_id`, use a bounded paginator, and support status/date filters (`api/app/Modules/SupplyChain/Services/DriverDeliveryService.php:35-67`).
- The driver resource excludes the broad internal invoice, pricing, and line-item payload (`api/app/Modules/SupplyChain/Resources/DriverDeliveryResource.php:11-61`).
- Assignment validates an active driver and available vehicle at the request/service boundary (`api/app/Modules/SupplyChain/Requests/AssignDeliveryRequest.php:31-49`, `api/app/Modules/SupplyChain/Services/DeliveryService.php:341-381`).
- Vehicle archive, restore, and active-delivery guards are implemented (`api/app/Modules/SupplyChain/Controllers/VehicleController.php:96-133`).
- Writes in delivery creation, assignment, status changes, and receipt upload use transactions and row locks in the relevant services.

## Verification performed

`docker compose run --rm api php artisan test tests/Feature/SupplyChain/FleetDriverHardeningTest.php tests/Feature/SupplyChain/DriverDeliveryTest.php tests/Feature/SupplyChain/CreateDeliveryDriverGateTest.php tests/Feature/SupplyChain/DeliveryLifecycleConcurrencyTest.php` produced **25 passed, 1 failed, 73 assertions**. The only failure was F001; the remaining hardening, driver-scope, gate, and concurrency tests passed.

`docker compose run --rm spa npm run typecheck` did not complete cleanly because unrelated pre-existing files outside this module report a missing `qrcode` dependency and JSX/type errors in `src/pages/assets/detail.tsx` and `src/pages/return-management/detail.tsx`. No module-specific type error was reported before those diagnostics. No browser-driven QA was run.

## Session decision

The dedicated fixing session implemented F001 and the contained UI fixes F006–F008. F002–F005 still require explicit business or cross-module decisions, and F009 depends on the RBAC decision in F003. The focused backend suite could not reach assertions because the shared PostgreSQL test migration `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42` fails while dropping a constraint-backed index. Details are recorded in `fix-log.md`; the module is released as `🔁 Needs Re-audit`.

---

# M045 — Fleet & Driver Re-audit (2026-09-01)

**Domain:** Supply Chain · **Tier:** 3 · **Claim:** RECLAIMED (orphan lock, 169h old, from 2026-08-25)
**Audit date:** 2026-09-01
**Status:** IN PROGRESS (skeleton committed before probing, per protocol)

## Where the code actually lives

_(to be filled)_

## Prior-session reproduction

_(to be filled — F001..F009 from the 2026-08-25 report)_

## Baseline

_(to be filled — tests + assertions + exit code)_

## HTTP vs service-only coverage split

_(to be filled)_

## Findings

_(to be filled — Broken / Missing / Incomplete / Polish)_

## Invariant table

_(to be filled)_

## Questions for a human

_(to be filled)_
