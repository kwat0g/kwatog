# SECTION A — Roles & Access (Phase 0 discovery)

Primary-source research only: seeders, migrations, models, policies, routes, frontend guards.
Branch: `docs/dss-discovery-phase0`. No code modified.

Primary files cited inline as `path:line`. Line numbers refer to the current branch checkout.

---

## 0. How access works (authority chain)

- `users.role_id` is a single FK to `roles` (`api/database/migrations/0004_create_users_table.php:19`).
  `User::role()` is `BelongsTo` (`api/app/Modules/Auth/Models/User.php:75-78`);
  `Role::users()` is `HasMany` (`api/app/Modules/Auth/Models/Role.php:29-32`). One user = one role.
- Effective permissions = role grants + per-user grants − per-user revokes (expired rows ignored),
  resolved in `User::getPermissionSlugsAttribute()` (`api/app/Modules/Auth/Models/User.php:117-159`);
  backing table `user_permission_overrides` (`api/database/migrations/0127_create_user_permission_overrides_table.php:22-35`).
  Overrides are read fresh per call (no slug-level caching) so `expires_at` is honored
  (`api/app/Modules/Auth/Models/User.php:102-111`).
- `User::hasPermission()` short-circuits to `true` for `role.slug === 'system_admin'`
  (`api/app/Modules/Auth/Models/User.php:161-167`); overrides are intentionally NOT applied to
  `system_admin` (`api/app/Modules/Auth/Models/User.php:109-111`).
- Backend route enforcement is `CheckPermission` (`permission:<slug>` → 403,
  `system_admin` bypass by role slug) (`api/app/Common/Middleware/CheckPermission.php:18-30`).
  Row-level visibility is separate per-module policy code (see §1 "Dept?" notes and §2).
- Frontend: `usePermission().can()` = `isAdmin || permissions.has(slug)` where
  `isAdmin = is_superuser === true || role.slug === 'system_admin'`
  (`spa/src/hooks/usePermission.ts:3-12`). Identity bootstrap is `GET /auth/user`
  (`spa/src/api/auth.ts:73-75`) shaped by `UserResource`
  (`api/app/Modules/Auth/Resources/UserResource.php:13-45`), which sends `role {id,name,slug}`,
  `employee {id, employee_no, full_name, department_id, photo_url}`, `permissions[]`, `features[]`.
- Landing page is server-side: `GET /dashboard/dispatch` → `DashboardDispatchService::resolve()`
  (`api/app/Modules/Dashboard/Services/DashboardDispatchService.php:32-50`,
  `api/app/Modules/Dashboard/routes.php:89`, `spa/src/pages/dashboard/index.tsx:24-37`).
  Candidacy = holds the dashboard's gating permission; ties broken by live rarity
  (fewest roles holding the permission wins, then `key`)
  (`api/app/Modules/Dashboard/Services/DashboardDispatchService.php:45-80`).
  Rarity counts roles in `role_permissions`, not per-user overrides
  (`api/app/Modules/Dashboard/Services/DashboardDispatchService.php:89-104`).
- Dashboard catalog (8 purpose-built + default fallback `/dashboard/default`):
  `admin → /dashboard/admin`, `plant-manager → /dashboard/plant-manager`, `hr → /dashboard/hr`,
  `ppc → /dashboard/ppc`, `finance → /dashboard/finance` (permission slug keeps old name
  `dashboard.accounting.view`), `purchasing`, `warehouse`, `quality`
  (`api/app/Modules/Dashboard/Support/DashboardCatalog.php:33-47`).
- All seeded roles are `is_system = true` (cannot be edited/deleted via admin UI):
  seeder sets it (`api/database/seeders/RolePermissionSeeder.php:1054`),
  migration `0126` backfilled the original 12 (`api/database/migrations/0126_add_is_system_to_roles_table.php:23-36`).
- Every seeded role also receives the cross-cutting set on top of its listed grants:
  `notifications.view`, `notifications.preferences.manage`, `calendar.view`,
  `approvals.board.view`, `hr.directory.view`, `dashboard.action_center.view`,
  `dashboard.layout.reset`, `business_policies.view`
  (`api/database/seeders/RolePermissionSeeder.php:1060-1081`).
- Session timeouts: short-timeout roles (`['employee']` by default,
  `api/database/seeders/SettingsSeeder.php:359-364`,
  `api/database/migrations/0521_seed_session_timeout_short_roles.php:12-20`)
  get 15 min (`api/database/seeders/SettingsSeeder.php:352-357`,
  `api/database/migrations/0292_seed_security_policy_settings.php:15`);
  everyone else 30 min (`api/database/seeders/SettingsSeeder.php:366-371`,
  `api/database/migrations/0292_seed_security_policy_settings.php:16`);
  enforced in `SessionTimeout` (`api/app/Common/Middleware/SessionTimeout.php:91-98`).
- Department link: `User → employee_id → employees.department_id`
  (`api/app/Modules/Auth/Models/User.php:80-83`,
  `api/app/Modules/HR/Models/Employee.php:46`, `api/app/Modules/HR/Models/Employee.php:115`),
  surfaced to the SPA as `employee.department_id` (`api/app/Modules/Auth/Resources/UserResource.php:30-41`,
  `spa/src/api/auth.ts:9-15`).

---

## 1. Role table (17 seeded roles)

Permission summaries are from `RolePermissionSeeder::roleCatalog()`
(`api/database/seeders/RolePermissionSeeder.php:501-988`) against the catalog
(`api/database/seeders/RolePermissionSeeder.php:20-494`).
"Dept?" = whether any backend row scope reads this user's department (beyond own-employee self-scope).
"Landing" = `DashboardDispatchService` outcome for a user holding exactly the seeded grants.

### 1.1 `system_admin` — System Administrator

- Key permissions: `'*'` — every permission row
  (`api/database/seeders/RolePermissionSeeder.php:504-508`).
- Dept?: No (global everywhere; `hasPermission` short-circuit,
  `api/app/Modules/Auth/Models/User.php:161-167`).
- Landing: `/dashboard/admin` — holds all 8 dashboard permissions; `dashboard.admin.view`
  is the rarest (1 holder: itself) so it wins the rarity sort
  (`api/app/Modules/Dashboard/Services/DashboardDispatchService.php:45-50`).
- Sees/can do: every SPA page (all `PermissionGuard`s pass via `isAdmin`,
  `spa/src/hooks/usePermission.ts:8`; sidebar bypass by role slug,
  `spa/src/components/layout/Sidebar.tsx:801-811`) and every backend endpoint
  (`CheckPermission` bypass, `api/app/Common/Middleware/CheckPermission.php:23-25`).
  Exceptions encoded in code, not permissions: cannot award RFQs
  (`RequestForQuoteAccessPolicy::canAward()` excludes the slug,
  `api/app/Modules/Purchasing/Policies/RequestForQuoteAccessPolicy.php:50-53`);
  payroll maker-checker still applies unless also granted
  `payroll.periods.self_approve_override` (which the wildcard includes, but the control exists,
  `api/database/seeders/RolePermissionSeeder.php:127-131`); first-login dashboard-layout
  clone is skipped for this slug (`api/app/Modules/Auth/Services/AuthService.php:161-171`).
  Demo account: `admin@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:24`).

### 1.2 `hr_officer` — HR Officer

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:509-556`):
  full `hr` module EXCEPT `hr.salary_adjustments.act`, `hr.employees.reset_password`,
  `hr.employees.deactivate_account`, `hr.profile_updates.finance_review`;
  full `attendance`, `leave`, `hr_separation` (except `hr.clearance.work_company`),
  `hr_recruitment` modules; `loans` except write-off request/approve;
  `payroll.view`, `payroll.periods.view/create/compute`, `payroll.adjustments.view/create`,
  `payroll.payslip.view_all`, `payroll.statutory.export`, `payroll.thirteenth_month.run`,
  `payroll.anomalies.review`, `payroll.gov_tables.manage`, `admin.gov_tables.manage`;
  `dashboard.hr.view`; `search.global`; `alerts.view`. Maker (create/compute), NOT approver,
  on payroll (`payroll.periods.approve` deliberately omitted,
  `api/database/seeders/RolePermissionSeeder.php:538-541`).
- Dept?: No department gate — `leave.approve_hr` is the view-all tier in
  `DepartmentScope` (`api/app/Common/Support/ApprovalSourceScope.php:89-98`);
  company-wide loan operator (`LoanAccessPolicy::isGlobal`,
  `api/app/Modules/Loans/Policies/LoanAccessPolicy.php:198-203`).
- Landing: `/dashboard/hr` (`dashboard.hr.view`, 2 holders).
- Sees/can do — frontend: `/dashboard/hr` (`spa/src/routes/dashboardRoutes.tsx:58-59`);
  `/hr/departments`, `/hr/positions`, `/hr/employees*`, `/hr/profile-update-requests`
  (`spa/src/routes/hrRoutes.tsx:68-97`); attendance pages incl. import + shifts + holidays + OT
  (`spa/src/routes/hrRoutes.tsx:100-133`); leaves pages
  (`spa/src/routes/hrRoutes.tsx:136-153`); loans pages (`spa/src/routes/hrRoutes.tsx:159-172`);
  separations (`spa/src/routes/hrRoutes.tsx:175-180`); training/recruitment
  (`spa/src/routes/hrRoutes.tsx:189-227`); payroll periods/adjustments/statutory
  (`spa/src/routes/payrollRoutes.tsx:21-50`); gov tables (`spa/src/routes/adminRoutes.tsx:187-194`);
  self-service (`spa/src/routes/selfServiceRoutes.tsx:29-75`).
  Backend: HR employee/department/position endpoints (`api/app/Modules/HR/routes.php:58-72`);
  attendance incl. import (`api/app/Modules/Attendance/routes.php:37-62`);
  leave incl. HR approve (`api/app/Modules/Leave/routes.php:36-49`);
  payroll create/compute (not approve/finalize); separation step 5 (HR)
  (`api/database/seeders/WorkflowSeeder.php:153-159`); salary-adjustment chain unaffected
  (not a step role).

### 1.3 `finance_officer` — Finance Officer

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:557-623`):
  full `accounting` EXCEPT `accounting.customers.manage`, `accounting.invoices.collect_manual`;
  full `b2b`, `budgeting`, `loans`, `assets` modules; payroll checker/payment set
  (`payroll.periods.view/approve/request_correction/finalize/bank_file/disburse`);
  `crm.price_agreements.approve`, `crm.products.view`, `crm.price_agreements.view`;
  `inventory.costs.review/approve`, `inventory.adjust.approve`;
  `purchasing.view`, `purchasing.rfq.view`, `purchasing.pr.approve`, `purchasing.po.approve`,
  `purchasing.po.view_all`, `purchasing.suppliers.performance.view`;
  `hr.separation.view/view_all`, `hr.clearance.sign`, `hr.profile_updates.finance_review`;
  `return_management.view/approve`; `supply_chain.deliveries.view`;
  `dashboard.accounting.view`, `dashboard.view_bottlenecks`, `dashboard.chain_recovery.view/manage`;
  `forecasting.view`; `search.global`; `alerts.view/dismiss`; loans write-off request+approve.
- Dept?: No. Clearance signing is per-department-item gated in `SeparationService`
  (comment at `api/database/seeders/RolePermissionSeeder.php:611-616`); Finance is step 4 of
  separation clearance (`api/database/seeders/WorkflowSeeder.php:153-159`).
- Landing: `/dashboard/finance` (`dashboard.accounting.view`, 2 holders). Holds BOTH
  `dashboard.accounting.view` (route gate) and `accounting.dashboard.view` (data endpoint) —
  see M1.
- Sees/can do — frontend: `/dashboard/finance` (`spa/src/routes/dashboardRoutes.tsx:64-67`);
  accounting COA/journal/vendors/bills/customers/invoices/statements/periods/credit-notes/portal-access
  (`spa/src/routes/accountingRoutes.tsx:44-114`); budgeting (`spa/src/routes/advancedRoutes.tsx:59-124`);
  payroll periods (approve/finalize/disburse), adjustments view, bank files
  (`spa/src/routes/payrollRoutes.tsx:21-50`); PR/PO detail + approve buttons (via
  `actionsFor`, `api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:209-239`,
  `api/app/Modules/Purchasing/Policies/PurchaseOrderAccessPolicy.php:211-258`);
  RFQ list/compare (`spa/src/routes/purchasingRoutes.tsx:59-108`); supplier performance
  (`spa/src/routes/purchasingRoutes.tsx:158-174`); cost reviews
  (`spa/src/routes/inventoryRoutes.tsx:47-54`); stock adjustments approve
  (`spa/src/routes/inventoryRoutes.tsx:143-158`); deliveries read
  (`spa/src/routes/supplyChainRoutes.tsx:29-34`); separations + profile-update finance queue
  (`spa/src/routes/hrRoutes.tsx:93-96`, `spa/src/routes/hrRoutes.tsx:175-180`).
  Backend: full accounting endpoints; payroll approve/finalize/disburse;
  PR step 1 + PO step 1 approver (`api/database/seeders/WorkflowSeeder.php:57-80`);
  bill-payment single step approver for ≥₱50k (`api/database/seeders/WorkflowSeeder.php:89-95`);
  asset-disposal step 1 (`api/database/seeders/WorkflowSeeder.php:142-149`);
  finance-only return approval (`api/database/seeders/WorkflowSeeder.php:179-185`).

### 1.4 `vice_president` — Vice President

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:629-670`):
  `purchasing.view/pr.approve/po.approve/po.view_all/rfq.view`; `loans.view/approve`;
  `hr.salary_adjustments.view/act`; `assets.view/dispose.approve`;
  `accounting.view/coa.view/bills.view/bills.payment_approve/invoices.view/statements.view`;
  `budgeting.view/approve`; `crm.view/complaints.view`; `dashboard.plant_manager.view`,
  `dashboard.view_bottlenecks`; `search.global`; `alerts.view/dismiss`. No bespoke dashboard
  ("reads what it signs", `api/database/seeders/RolePermissionSeeder.php:624-628`).
- Dept?: No.
- Landing: `/dashboard/plant-manager` (`dashboard.plant_manager.view`, 3 holders:
  system_admin, vice_president, production_manager). No VP-specific page exists.
- Sees/can do — frontend: `/dashboard/plant-manager` route + API
  (`spa/src/routes/dashboardRoutes.tsx:56-57`,
  `api/app/Modules/Dashboard/routes.php:21-22`); PR/PO approve affordances on detail pages;
  bills/invoices/statements/budgets read pages; complaints read; salary-adjustment queue tab
  on `/hr/employees` (route `anyOf` includes `hr.salary_adjustments.view`,
  `spa/src/routes/hrRoutes.tsx:77-80`); asset detail/disposal approve.
  Backend: terminal VP step on PR/PO (≥₱50k), cash_advance step 3, company_loan step 4,
  bill_payment, salary_adjustment step 2, asset_disposal step 2
  (`api/database/seeders/WorkflowSeeder.php:32-49`, `:57-80`, `:89-103`, `:142-149`).
  Demo account: `vp@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:38`).

### 1.5 `customer_service_officer` — Customer Service Officer

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:671-697`):
  `crm.view`, `accounting.customers.view/manage`, `crm.products.view`,
  `crm.price_agreements.view/manage/propose`, `crm.sales_orders.view`, `crm.complaints.view/manage`,
  `crm.inquiries.view/manage`, `return_management.view/manage/dispose/complete`,
  `search.global`, `alerts.view/dismiss`. No `dashboard.*.view`.
- Dept?: No.
- Landing: `/dashboard/default` (fallback — qualifies for no catalog dashboard).
- Sees/can do — frontend: CRM products/customers/price-agreements/sales-orders (read)/complaints/inquiries
  (`spa/src/routes/crmRoutes.tsx:39-235`); return-management list/detail/create/edit/complete
  (not receive/inspect — those are warehouse/QC, `spa/src/routes/advancedRoutes.tsx:127-184`);
  self-service. Backend: customer/inquiry/complaint/price-agreement/RMA lifecycle endpoints
  (except receive/inspect/dispose-gated steps owned by other roles).
  Demo account: `customerservice@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:47`).

### 1.6 `sales_officer` — Sales Officer

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:698-719`):
  `crm.view`, `accounting.customers.view`, `crm.products.view`,
  `crm.price_agreements.view/propose`, `crm.sales_orders.view/create/update/delete/confirm/cancel`,
  `search.global`, `alerts.view/dismiss`. No `dashboard.*.view`. No complaints/inquiries/RMA.
- Dept?: No.
- Landing: `/dashboard/default` (fallback).
- Sees/can do — frontend: `/chains` tracker (holds `crm.sales_orders.view`,
  `spa/src/routes/dashboardRoutes.tsx:102-105`) but NOT its bottleneck data (lacks
  `dashboard.view_bottlenecks` — see M10); CRM products/pricing-requests/sales-orders pages;
  self-service. Backend: SO full lifecycle (create/update/delete/confirm/cancel) + price-request
  propose (`api/app/Modules/CRM/routes.php:33-58`).
  Demo account: `crm@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:40`).

### 1.7 `production_manager` — Production Manager

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:720-771`):
  full `production` module EXCEPT `production.routings.manage` (view retained);
  `mrp.view/schedule/boms.view/machines.view/plans.view/runs.view`;
  `inventory.view`; `quality.view/inspections.view/ncr.view/inspections.review`;
  `crm.view/complaints.view`; `purchasing.view` (read-only, no approve);
  `loans.view/approve` (legacy Manager step on older company loans);
  `maintenance.view/wo.verify`; `assets.view`; `hr.salary_adjustments.view/act`;
  `return_management.view/approve` (final step); `forecasting.view`;
  `dashboard.plant_manager.view`, `dashboard.view_bottlenecks`, `dashboard.chain_recovery.view/manage`;
  `search.global`; `alerts.view/dismiss`.
- Dept?: No global-role branch in PO/PR policies (not `isGlobal`, not `department_head`);
  visible to own creations + plant-wide chain-participant rows
  (`api/app/Modules/Purchasing/Policies/PurchaseOrderAccessPolicy.php:47-84`,
  `api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:47-77`;
  loans chain-participant branch,
  `api/app/Modules/Loans/Policies/LoanAccessPolicy.php:89-97`).
- Landing: `/dashboard/plant-manager`.
- Sees/can do — frontend: plant-manager dashboard; production work-orders/schedule/routings(view)/dashboard/OEE
  (`spa/src/routes/productionRoutes.tsx:22-57`); MRP boms/machines/molds/plans read
  (`spa/src/routes/mrpRoutes.tsx:25-59`, machine/mold create-edit gated on
  `production.machines.manage`/`production.molds.manage` which this role HOLDS via the module);
  quality inspections/NCRs read + review; complaints read; PR/PO read; factory PWA `/factory`
  (sole non-admin holder of `production.operator.access` via `module('production')` —
  catalog `api/database/seeders/RolePermissionSeeder.php:300`, grant `:732`, gate
  `spa/src/routes/factoryRoutes.tsx:12-25`); self-service work-orders
  (`spa/src/routes/selfServiceRoutes.tsx:36-39`).
  Backend: WO lifecycle, output recording, schedule confirm; return_request final approval
  (`api/database/seeders/WorkflowSeeder.php:171-178`); salary_adjustment step 1
  (`api/database/seeders/WorkflowSeeder.php:96-103`); company_loan legacy step 2
  (`api/database/seeders/WorkflowSeeder.php:41-50`,
  backfill `api/database/migrations/0483_grant_chain_step_roles_their_route_permissions.php:28-36`).
  Demo account: `production@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:29`).

### 1.8 `ppc_head` — PPC Head

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:772-811`):
  full `mrp` + `forecasting` modules; `production.view/work_orders.view/wo.create/wo.confirm/
  wo.cancel_stock/schedule.view/wo.assign/routings.view/routings.manage`;
  `crm.view/products.view/products.manage`; `maintenance.view`; `assets.view`;
  `inventory.costs.review`; `return_management.view` (read-only);
  `dashboard.ppc.view`, `dashboard.view_bottlenecks`, `dashboard.chain_recovery.view/manage`;
  `search.global`; `alerts.view/dismiss`.
- Dept?: No.
- Landing: `/dashboard/ppc` (`dashboard.ppc.view`, 2 holders).
- Sees/can do — frontend: PPC dashboard (`spa/src/routes/dashboardRoutes.tsx:60-61`);
  MRP full incl. BOM authoring (`spa/src/routes/mrpRoutes.tsx:25-59`); production WO create/confirm,
  schedule, routings authoring (`spa/src/routes/productionRoutes.tsx:22-57`); finished-good catalog
  (`spa/src/routes/crmRoutes.tsx:60-91`); forecasting (`spa/src/routes/advancedRoutes.tsx:31-56`).
  Backend: MRP run/schedule, BOM/machine/mold master data, WO create/confirm, routing versions.
  Cannot open `/chains` (lacks `crm.sales_orders.view`) though it can call the bottleneck API
  (holds `dashboard.view_bottlenecks` — see M10).
  Demo account: `ppc@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:30`).

### 1.9 `purchasing_officer` — Purchasing Officer

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:812-834`):
  full `purchasing` EXCEPT `purchasing.po.sod_override`, `purchasing.pr.approve`, `purchasing.po.approve`
  (runs sourcing; financial approvals follow the workflow);
  `inventory.view`, `inventory.costs.review`, `supply_chain.shipments.view/manage`,
  `supply_chain.deliveries.view`, `accounting.vendors.view`, `accounting.bills.view`;
  full return lifecycle `view/manage/receive/inspect/dispose/complete`;
  `forecasting.view`; `dashboard.purchasing.view`, `dashboard.view_bottlenecks`,
  `dashboard.chain_recovery.view/manage`; `search.global`.
- Dept?: Company-wide PR operator (`isGlobal`,
  `api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:369-374`);
  PO list scope is permission-driven (`po.approve`/`po.view_all` = global tier;
  without them, own creations + chain-waiting rows,
  `api/app/Modules/Purchasing/Policies/PurchaseOrderAccessPolicy.php:47-84`).
- Landing: `/dashboard/purchasing`.
- Sees/can do — frontend: purchasing dashboard; PR list/detail/create, RFQ full lifecycle,
  PO list/detail (via `purchasing.view`), approved-suppliers, supplier-listings review,
  procurement chain, supplier performance (`spa/src/routes/purchasingRoutes.tsx:32-175`);
  shipments manage + deliveries read (`spa/src/routes/supplyChainRoutes.tsx:18-37`); GRN/vendor/bill
  read surfaces; returns full lifecycle. Backend: PR CRUD/submit/convert, RFQ manage/award
  (incl. `canAward`, `api/app/Modules/Purchasing/Policies/RequestForQuoteAccessPolicy.php:50-53`),
  PO store/update/submit/send/cancel/close (NOT approve/reject — no `po.approve`).
  Demo accounts: `purchasing@ogami.test`, `buyer2@ogami.test`
  (`api/database/seeders/DemoAccountSeeder.php:31-35`).

### 1.10 `warehouse_staff` — Warehouse Staff

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:835-868`):
  `inventory.view/items.manage/warehouse.manage/grn.create/issue.create/adjust`,
  `inventory.stock_count.view/manage`, `inventory.picking.view`, `inventory.mrb.view/manage`;
  `forecasting.view`; `return_management.view/receive`; `supply_chain.deliveries.view`
  (deliberately NOT `supply_chain.view`); `hr.separation.view`, `hr.clearance.sign`,
  `hr.clearance.work_company`; `dashboard.warehouse.view`.
- Dept?: Clearance signing scoped by `SeparationService` per-department gate
  (comment at `api/database/seeders/RolePermissionSeeder.php:861-865`); otherwise self + module rows.
- Landing: `/dashboard/warehouse`.
- Sees/can do — frontend: warehouse dashboard; inventory items/UOMs/warehouse/stock-levels/
  adjustments/create/transfer-orders/picking/stock-count(via map)/scanner/GRN/material-issues/MRB
  (`spa/src/routes/inventoryRoutes.tsx:46-284` — note warehouse page requires
  `inventory.warehouse.manage`, materials issues require `inventory.issue.create`, both held);
  deliveries read (`spa/src/routes/supplyChainRoutes.tsx:29-34`); separations read + sign.
  CANNOT open fleet (requires `supply_chain.view`, `spa/src/routes/supplyChainRoutes.tsx:35-36`)
  or shipments create (requires `shipments.manage`). Backend: GRN store/finalize/accept/reject,
  material issues, stock adjustments (maker — approve needs `inventory.adjust.approve`, not held),
  transfer orders, MRB hold/release, return receive.
  Demo account: `warehouse@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:41`).

### 1.11 `qc_inspector` — QC Inspector

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:869-887`):
  full `quality` module (specs/inspections/NCR/calibration/PPAP view+manage, incl.
  `quality.inspections.review`); `return_management.view/inspect`; `purchasing.rfq.view`;
  `crm.view/complaints.view`; `inventory.view/mrb.view/mrb.manage`;
  `dashboard.quality.view`.
- Dept?: No.
- Landing: `/dashboard/quality` (`dashboard.quality.view`, 2 holders).
- Sees/can do — frontend: quality dashboard (`spa/src/routes/qualityRoutes.tsx:34-36`);
  specs/inspections (incl. create)/NCRs/templates/traceability/capability/calibration
  (`spa/src/routes/qualityRoutes.tsx:37-79`); QC dashboard page
  (`spa/src/routes/dashboardRoutes.tsx:73-74`); return inspection staging; MRB quarantine.
  Backend: inspection spec authoring, `recordMeasurements` flow, NCR lifecycle, calibration
  register, PPAP; return `inspect` staging; high-risk inspection review queue.
  Demo account: `qc@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:42`).

### 1.12 `maintenance_tech` — Maintenance Technician

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:888-906`):
  `maintenance.view/wo.create/wo.complete`; `mrp.machines.view`; `assets.view`;
  `hr.separation.view`, `hr.clearance.sign`, `hr.clearance.work_company`;
  `search.global`. NO `dashboard.*.view`. NOTE: does NOT hold `maintenance.work.assigned`
  (only `employee` does, §1.16) and does NOT hold `maintenance.wo.assign` or
  `maintenance.schedules.manage`.
- Dept?: Clearance signing per-department gated (comment at
  `api/database/seeders/RolePermissionSeeder.php:898-902`).
- Landing: `/dashboard/default` (fallback).
- Sees/can do — frontend: maintenance home (unguarded, see M5), work-orders list
  (`maintenance.view`), create (`wo.create`), detail (via `maintenance.view`;
  `anyOf` also names `work.assigned` which this role lacks,
  `spa/src/routes/maintenanceRoutes.tsx:21-40`); schedules read-only; self-service
  maintenance-work-orders pages REQUIRE `maintenance.work.assigned` — this role FAILS that
  guard (`spa/src/routes/selfServiceRoutes.tsx:41-44`). Backend: WO store/start/complete/
  submit-repair/cancel via `permission_any:wo.complete,wo.assign` (holds `wo.complete`,
  `api/app/Modules/Maintenance/routes.php:62-73`); verify/reconcile/assign denied;
  list/show via `CheckMaintenanceWorkAccess` (`api/app/Modules/Maintenance/routes.php:46-55`).
  Demo accounts: `maintenance@ogami.test`, `maintenance2@ogami.test`
  (`api/database/seeders/DemoAccountSeeder.php:43`, `:48`).

### 1.13 `maintenance_supervisor` — Maintenance Supervisor

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:907-914`):
  `maintenance.view/wo.create/wo.assign/wo.verify/schedules.manage`;
  `mrp.machines.view`; `assets.view`; `search.global`. NO `dashboard.*.view`,
  NO `maintenance.wo.complete`.
- Dept?: No.
- Landing: `/dashboard/default` (fallback).
- Sees/can do — frontend: maintenance home, work-orders list/detail/assignee-suggestions,
  schedules full CRUD (`spa/src/routes/maintenanceRoutes.tsx:21-40`).
  Backend: assign, verify, schedule manage, window confirm
  (`api/app/Modules/Maintenance/routes.php:28-71`); start/complete/submit-repair/cancel
  via `permission_any:wo.complete,wo.assign` (holds `assign`,
  `api/app/Modules/Maintenance/routes.php:62-73`).
  DEPLOYMENT DIVERGENCE: the `2026_10_09` migration grants a SMALLER set (no `wo.verify`,
  no `mrp.machines.view`) than the seeder
  (`api/database/migrations/2026_10_09_230100_add_maintenance_supervisor_role.php:39-46`
  vs `api/database/seeders/RolePermissionSeeder.php:910-913`) — migrated databases lack those
  two grants until the seeder re-runs (see §5).
  Demo account: `maintenancehead@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:49`).

### 1.14 `impex_officer` — ImpEx Officer

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:915-938`):
  `supply_chain.view`, `supply_chain.shipments.manage`, `supply_chain.deliveries.view/create/confirm`,
  `supply_chain.fleet.manage`; `purchasing.view` (read-only). NO `dashboard.*.view`.
- Dept?: No.
- Landing: `/dashboard/default` (fallback).
- Sees/can do — frontend: shipments + deliveries + fleet
  (`spa/src/routes/supplyChainRoutes.tsx:18-37`); PR/PO read. CANNOT open driver PWA
  (lacks `supply_chain.driver.access`, `spa/src/routes/driverRoutes.tsx:20-35`).
  Backend: shipment CRUD/status/documents, delivery dispatch/confirm, vehicle CRUD
  (`api/app/Modules/SupplyChain/routes.php:19-179`).
  Demo account: `impex@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:44`).

### 1.15 `department_head` — Department Head

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:939-970`):
  `hr.employees.view`, `hr.employees.trainings.view`, `attendance.ot.approve`,
  `leave.approve_dept`, `loans.view/approve`, `purchasing.view`, `purchasing.pr.create`,
  `return_management.view/approve`, `hr.separation.view`, `hr.clearance.sign`,
  `search.global`. Creation-only on PRs (no chain step names this role since the 2026-09-10
  redesign, `api/database/seeders/RolePermissionSeeder.php:953-958`).
- Dept?: YES — the department-scoped role: PR list adds own-department rows
  (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:63-65`);
  PO list adds POs whose PR belongs to their department
  (`api/app/Modules/Purchasing/Policies/PurchaseOrderAccessPolicy.php:64-71`);
  loans ladder: own + department's
  (`api/app/Modules/Loans/Policies/LoanAccessPolicy.php:67-82`);
  leave board via `DepartmentScope` (`approve_dept` = department + own,
  `api/app/Common/Support/ApprovalSourceScope.php:89-98`); clearance signs only own-department
  items (comment at `api/database/seeders/RolePermissionSeeder.php:961-966`).
  All read `users.employee.department_id` (`api/app/Modules/Auth/Resources/UserResource.php:38`).
- Landing: `/dashboard/default` (fallback) with a department-scoped default widget set
  (`api/database/seeders/DashboardRoleLayoutSeeder.php:82-94`).
- Sees/can do — frontend: employees list (read) + salary-adjustment queue label
  (`spa/src/routes/hrRoutes.tsx:77-80`, relabeled when lacking `hr.employees.view`,
  `spa/src/components/layout/Sidebar.tsx:788-790`); OT approve, leaves approve pages;
  loans + PR create + RMA approve; separations read + sign; RFQ detail via `purchasing.view`
  anyOf (`spa/src/routes/purchasingRoutes.tsx:99-108`) but NOT RFQ list/compare
  (require `rfq.view`, not held).
  Backend: leave dept approve, loan step 1 (both chains), PR submit (as creator),
  return_request step 1, separation clearance step 1
  (`api/database/seeders/WorkflowSeeder.php:24-40`, `:41-50`, `:150-160`, `:169-178`).
  Demo account: `depthead@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:45`).

### 1.16 `employee` — Employee (self-service baseline)

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:971-978`):
  self-service (`attendance.view`, `leave.view/create`, `payroll.view`) +
  `maintenance.work.assigned`. Plus the universal cross-cutting set (§0).
  15-minute session timeout (`api/database/seeders/SettingsSeeder.php:352-364`).
- Dept?: No (own rows only).
- Landing: `/dashboard/default` with self-only widgets
  (`api/database/seeders/DashboardRoleLayoutSeeder.php:95-98`).
- Sees/can do — frontend: self-service home/DTR/overtime/leave(s)/loans/payslips/me/profile/documents
  (`spa/src/routes/selfServiceRoutes.tsx:29-75`); assigned maintenance work detail
  (`maintenance.work.assigned`, `spa/src/routes/selfServiceRoutes.tsx:41-44`,
  `spa/src/routes/maintenanceRoutes.tsx:30-31`); approvals board + action center + calendar
  (cross-cutting). CANNOT open any `/hr/*` management list (all require approve/manage perms
  this role lacks — see M2/M3). Backend: self-scoped reads (controllers scope to
  `auth.user.employee_id`, seeder comment at
  `api/database/seeders/RolePermissionSeeder.php:1004-1008`); assigned-order access via
  `CheckMaintenanceWorkAccess` (`api/app/Modules/Maintenance/Middleware/CheckMaintenanceWorkAccess.php:25-36`).
  Demo account: `employee@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:39`).

### 1.17 `driver` — Driver

- Key permissions (`api/database/seeders/RolePermissionSeeder.php:979-986`):
  self-service + `supply_chain.driver.access` ONLY (no general logistics/inventory/procurement).
- Dept?: No (own assigned runs; service scopes to driver).
- Landing: `/dashboard/default` (generic widgets; role default is approvals-pending + self set,
  `api/database/seeders/DashboardRoleLayoutSeeder.php:123-127`).
- Sees/can do — frontend: `/driver`, `/driver/:id`, `/driver/:id/photo` phone-first pages
  (`spa/src/routes/driverRoutes.tsx:20-35`, gate `supply_chain.driver.access`); self-service.
  Backend: `GET/PATCH/POST /driver/deliveries*` group gated on `supply_chain.driver.access`
  (`api/app/Modules/SupplyChain/routes.php:181-191`). Nothing else.
  Demo account: `driver@ogami.test` (`api/database/seeders/DemoAccountSeeder.php:46`).

### Role → landing summary

| Role | Dispatch permission (holders) | Landing path |
|---|---|---|
| system_admin | all 8 (admin.view: 1 holder) | `/dashboard/admin` |
| hr_officer | `dashboard.hr.view` (2) | `/dashboard/hr` |
| finance_officer | `dashboard.accounting.view` (2) | `/dashboard/finance` |
| vice_president | `dashboard.plant_manager.view` (3) | `/dashboard/plant-manager` |
| production_manager | `dashboard.plant_manager.view` (3) | `/dashboard/plant-manager` |
| ppc_head | `dashboard.ppc.view` (2) | `/dashboard/ppc` |
| purchasing_officer | `dashboard.purchasing.view` (2) | `/dashboard/purchasing` |
| warehouse_staff | `dashboard.warehouse.view` (2) | `/dashboard/warehouse` |
| qc_inspector | `dashboard.quality.view` (2) | `/dashboard/quality` |
| customer_service_officer | none | `/dashboard/default` |
| sales_officer | none | `/dashboard/default` |
| maintenance_tech | none | `/dashboard/default` |
| maintenance_supervisor | none | `/dashboard/default` |
| impex_officer | none | `/dashboard/default` |
| department_head | none | `/dashboard/default` |
| employee | none | `/dashboard/default` |
| driver | none | `/dashboard/default` |

Holder counts computed from the seeder grants above; the live source is `role_permissions`
(`api/app/Modules/Dashboard/Services/DashboardDispatchService.php:89-104`).
No non-admin seeded role holds two dispatch permissions, so every non-admin landing is
single-candidate deterministic.

---

## 2. Backend-vs-frontend gating mismatches (12)

Convention: backend denies with 403 (`CheckPermission`), frontend denies with a 404-lookalike
(`PermissionGuard`, `spa/src/components/guards/PermissionGuard.tsx:12-24`). "Frontend allows,
backend denies" = page opens, API call fails. "Frontend denies, backend allows" = 404 page for
an API the user could call directly.

- **M1 — Finance dashboard: route guard and data endpoint use DIFFERENT permissions.**
  Frontend `/dashboard/finance` guards `dashboard.accounting.view`
  (`spa/src/routes/dashboardRoutes.tsx:64-65`); the page's data call
  `financeDashboardApi.summary()` hits `GET /dashboards/finance`
  (`spa/src/api/accounting/dashboard.ts:5-8`), which requires `accounting.dashboard.view`
  (`api/app/Modules/Dashboard/routes.php:69-70`, also aliased at `GET /dashboard/finance`,
  `api/app/Modules/Accounting/routes.php:151-152`). The page even documents reaching it with
  `accounting.dashboard.view` (`spa/src/pages/dashboard/finance.tsx:54-60`), contradicting its
  own route guard. `finance_officer` holds both (module grant
  `api/database/seeders/RolePermissionSeeder.php:163` + explicit `:587`), so seeded roles show
  no break; any custom/override user holding exactly one of the two slugs gets page-without-data
  or data-without-page. The sibling `GET /dashboards/accounting` (guard `dashboard.accounting.view`,
  `api/app/Modules/Dashboard/routes.php:27-28`) matches the route guard but is not what the SPA
  finance page calls.

- **M2 — Leave management list requires approver perms in SPA, only `leave.view` in API.**
  Frontend `/hr/leaves`, `/hr/leaves/calendar`, `/hr/leaves/create`, `/hr/leaves/:id` all require
  `leave.approve_dept` / `leave.approve_hr` / `leave.types.manage`
  (`spa/src/routes/hrRoutes.tsx:136-153`); backend `GET /requests` and `GET /requests/{leaveRequest}`
  require only `leave.view` (`api/app/Modules/Leave/routes.php:36`, `:40`), which EVERY role holds
  via `selfService()` (`api/database/seeders/RolePermissionSeeder.php:1011-1019`).
  Frontend denies what backend allows for all non-approvers (by design they use
  `/self-service/leaves`, but the divergence is real at the guard layer).

- **M3 — Attendance register requires manager perms in SPA, only `attendance.view` in API.**
  Frontend `/hr/attendance` requires `attendance.edit` / `attendance.import` / `attendance.ot.approve`
  (`spa/src/routes/hrRoutes.tsx:101-104`); backend `GET /attendances` and `GET /attendances/{attendance}`
  require only `attendance.view` (`api/app/Modules/Attendance/routes.php:39`, `:41`), held by every
  role via `selfService()`. Same shape as M2.

- **M4 — OT create page and OT store endpoint check DIFFERENT slugs.**
  Frontend `/hr/attendance/overtime/create` requires `attendance.edit`
  (`spa/src/routes/hrRoutes.tsx:124-128`); backend `POST /overtime-requests` requires
  `attendance.ot.create` (`api/app/Modules/Attendance/routes.php:55`). A user granted `edit`
  without `ot.create` opens the form then 403s on submit; a user granted `ot.create` without
  `edit` is 404'd off a form the API would accept. No seeded role splits them (both live only in
  `hr_officer`'s full `attendance` module grant), so this bites custom roles/overrides only.

- **M5 — `/maintenance` home page has NO permission guard at all.**
  Frontend renders for any authenticated user (`spa/src/routes/maintenanceRoutes.tsx:22`);
  every backend maintenance endpoint is gated (`maintenance.view`, `schedules.manage`,
  `wo.create/assign/verify/complete`, or `CheckMaintenanceWorkAccess`,
  `api/app/Modules/Maintenance/routes.php:16-127`). Users with none of these (e.g. `driver`,
  `sales_officer`) open an empty shell whose data calls all fail.

- **M6 — Maintenance WO detail guard is broader than the backend read gate.**
  Frontend `/maintenance/work-orders/:id` allows `maintenance.view` OR `maintenance.work.assigned`
  (`spa/src/routes/maintenanceRoutes.tsx:30-31`); backend `show` uses
  `CheckMaintenanceWorkAccess:read`, which for `work.assigned`-only holders additionally requires
  assignment to THAT order and answers 404 otherwise
  (`api/app/Modules/Maintenance/Middleware/CheckMaintenanceWorkAccess.php:20-36`).
  Any `work.assigned` holder (i.e. every `employee`) can open arbitrary order URLs the API then
  404s. (Note the inverse trap: `maintenance_tech` does NOT hold `work.assigned` at all — §1.12 —
  so the `anyOf` second arm never fires for the very role that does the repairs; it enters via
  `maintenance.view`.)

- **M7 — Self-service pages are guard-free; the API is not.**
  `/self-service`, `/profile`, `/me`, `/documents`, `/notification-preferences` carry no
  `PermissionGuard`, and DTR/overtime/leave(s)/loans/work-orders carry only `ModuleGuard`
  (`spa/src/routes/selfServiceRoutes.tsx:29-75`). Backend endpoints behind them still require
  their permissions (e.g. payslips page itself re-guards `payroll.view`, `:69-72`, while DTR does
  not re-guard `attendance.view`). A user whose self-service slug is revoked via override keeps
  the page and eats API 403s. Self-scoping to `employee_id` is backend-side
  (`api/database/seeders/RolePermissionSeeder.php:1004-1008`).

- **M8 — `/approvals/delegations` has no frontend guard; backend is auth + service-scoped.**
  Route renders for any authenticated user (`spa/src/routes/dashboardRoutes.tsx:92-95`);
  backend allows managing only own delegations unless holding `admin.delegations.manage_any`
  (`api/app/Modules/Admin/routes.php:231-244`,
  `api/app/Modules/Admin/Services/ApprovalDelegationService.php:29-31`, `:152-157`).
  Non-delegator users open a page whose mutations the service refuses.

- **M9 — Warehouse structure pages are narrower in SPA than in API.**
  Frontend `/inventory/warehouse` and `/inventory/warehouse-map` require
  `inventory.warehouse.manage` (`spa/src/routes/inventoryRoutes.tsx:125-132`, `:161-169`);
  backend `GET /warehouse/options`, `GET /warehouses` require only `inventory.view`
  (`api/app/Modules/Inventory/routes.php:90`, `:92`). `inventory.view`-only holders
  (e.g. `production_manager`, `qc_inspector`, `purchasing_officer`) can call the structure APIs
  but are 404'd off the pages.

- **M10 — `/chains` page gate and bottleneck API gate diverge BOTH ways for seeded roles.**
  Frontend `/chains` requires `crm.sales_orders.view` (`spa/src/routes/dashboardRoutes.tsx:102-105`,
  sidebar likewise `spa/src/components/layout/Sidebar.tsx:170-175`); backend
  `GET /chain/bottlenecks` requires `dashboard.view_bottlenecks` (`api/routes/api.php:118-121`).
  `sales_officer` holds the former but not the latter (opens the tracker, bottleneck widget 403s);
  `ppc_head` holds the latter but not the former (can call the API, 404'd off the page).
  (`sales_officer` grants `api/database/seeders/RolePermissionSeeder.php:698-719`;
  `ppc_head` grants `:772-811`.)

- **M11 — `is_superuser` bypass exists only in the SPA.**
  `usePermission` grants everything when `user.is_superuser === true`
  (`spa/src/hooks/usePermission.ts:6`, type at `spa/src/api/auth.ts:22`), but the API never
  sends `is_superuser` (`api/app/Modules/Auth/Resources/UserResource.php:13-45` has no such
  field) and neither backend authority checks it (`api/app/Modules/Auth/Models/User.php:161-167`,
  `api/app/Common/Middleware/CheckPermission.php:23-25` check only `role.slug`). A principal
  carrying that flag would pass every SPA guard and fail every gated backend route. Currently
  inert (no sender), but the two authorities disagree on what "admin" means.

- **M12 — Sidebar shows `/notifications` to everyone; the route requires `notifications.view`.**
  Sidebar item has no `permission` field (`spa/src/components/layout/Sidebar.tsx:190-196`;
  visibility helper `:797-812`); route guards `notifications.view`
  (`spa/src/routes/dashboardRoutes.tsx:119`). All seeded roles hold it via the cross-cutting
  merge (`api/database/seeders/RolePermissionSeeder.php:1063`), so this diverges only under a
  per-user revoke override — sidebar link renders, page 404s.

Verified MATCHES (checked, not mismatches — do not regress):
RFQ detail `anyOf[rfq.view, rfq.manage, purchasing.view]` both sides
(`spa/src/routes/purchasingRoutes.tsx:99-108`,
`api/app/Modules/Purchasing/routes.php:64`); RFQ compare `rfq.view`-both
(`:83-90` vs `:56`); PR/PO list+show `purchasing.view`-both (`:35-58`, `:110-127` vs
`api/app/Modules/Purchasing/routes.php:22-29`, `:84-92`); approved-suppliers `po.create`-both
(`:129-136` vs `:108-113`); shipments + deliveries read `permission_any`-both
(`spa/src/routes/supplyChainRoutes.tsx:20-34` vs `api/app/Modules/SupplyChain/routes.php:22-29`,
`:114-125`); fleet list `supply_chain.view`-both (`:35-36` vs `:92-95`); product show
`permission_any[crm.products.view, inventory.costs.approve]`-both
(`spa/src/routes/crmRoutes.tsx:77-83` vs `api/app/Modules/CRM/routes.php:34`);
driver PWA `supply_chain.driver.access`-both (`spa/src/routes/driverRoutes.tsx:20-35` vs
`api/app/Modules/SupplyChain/routes.php:181-191`); dashboard role pages except finance-M1
(`spa/src/routes/dashboardRoutes.tsx:56-76` vs `api/app/Modules/Dashboard/routes.php:21-42`).

---

## 3. Multi-role users & super admin

- **Users hold exactly ONE role.** `users.role_id` single FK
  (`api/database/migrations/0004_create_users_table.php:19`); no `role_user`/`user_roles`
  pivot table exists (no such migration; only per-user override table
  `api/database/migrations/0127_create_user_permission_overrides_table.php`).
  Role assignment UI + demo seeding both write `role_id` directly
  (`api/database/seeders/DemoAccountSeeder.php:62-74`).
- **Closest thing to multi-role (both additive, both scoped):**
  1. Per-user grant/revoke overrides (`user_permission_overrides`, unique per user+permission,
     expirable) layered over the single role's set at read time
     (`api/app/Modules/Auth/Models/User.php:134-158`), managed behind
     `admin.users.manage_permissions` + `RequireSystemAdmin`
     (`api/app/Modules/Admin/routes.php:56`,
     `api/app/Modules/Admin/Controllers/UserPermissionOverrideController.php:23`).
  2. Approval delegations: `ApprovalDelegation::actsForRoles($user->id, now())` adds the
     delegator's step role to row-scope and approve-path checks in all four scoped policies
     (`api/app/Modules/Loans/Policies/LoanAccessPolicy.php:170-186`,
     `api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:193-204`,
     `api/app/Modules/Purchasing/Policies/PurchaseOrderAccessPolicy.php:179-190`)
     and to bottleneck audience (`api/app/Common/Controllers/ChainBottleneckController.php:38-54`).
     A delegation may only cover the delegator's current role
     (`api/app/Modules/Admin/Services/ApprovalDelegationService.php:99`).
- **Super admin = `system_admin` role slug (not a flag, not a separate user type).**
  Wildcard `hasPermission` (`api/app/Modules/Auth/Models/User.php:161-167`), route-middleware
  bypass (`api/app/Common/Middleware/CheckPermission.php:23-25`), SPA bypass
  (`spa/src/hooks/usePermission.ts:6`, sidebar `spa/src/components/layout/Sidebar.tsx:801`).
  Business-process carve-outs still apply where coded by slug: RFQ award refused
  (`api/app/Modules/Purchasing/Policies/RequestForQuoteAccessPolicy.php:50-53`); login skips
  dashboard-layout cloning (`api/app/Modules/Auth/Services/AuthService.php:161-171`); it is the
  top step of NO seeded workflow (VP replaced it 2026-09-10,
  `api/database/seeders/RolePermissionSeeder.php:624-628`,
  all `vice_president` steps in `api/database/seeders/WorkflowSeeder.php`).
  Portal principals (supplier/customer bearer tokens) are separate guards/models, rejected from
  internal endpoints with 401 (`api/app/Modules/Auth/Controllers/AuthUserController.php:27`,
  `api/app/Common/Middleware/SessionTimeout.php:63-74`).

---

## 4. Discrepancies vs repo docs (CLAUDE.md seeded-roles list)

CLAUDE.md lists 14 slugs and states "NO plant_manager — use production_manager".

- **Seeder actually defines 17 roles** (`api/database/seeders/RolePermissionSeeder.php:501-988`).
  Missing from the CLAUDE.md list: `customer_service_officer` (`:671-697`),
  `sales_officer` (`:698-719`), `maintenance_supervisor` (`:907-914`).
- "No `plant_manager` role" is ACCURATE — no such role slug exists; `plant_manager` survives only
  as the dashboard key/permission `dashboard.plant_manager.view`
  (`api/app/Modules/Dashboard/Support/DashboardCatalog.php:37`,
  `api/database/seeders/RolePermissionSeeder.php:419`), held by `production_manager` + `vice_president`.
- `vice_president` executive tier and its terminal-step replacement of `system_admin` are as
  documented (`api/database/seeders/WorkflowSeeder.php` steps; migration
  `api/database/migrations/0483_grant_chain_step_roles_their_route_permissions.php`).
- Session-timeout doc ("Employee 15 min, others 30 min") matches seeds + middleware (§0).
- Stale reference: `finance_officer` includes `$this->module('crm_commissions')`
  (`api/database/seeders/RolePermissionSeeder.php:584`), but no `crm_commissions` key exists in
  `permissionCatalog()` (`:20-494`) — `module()` returns `[]` for it (`:993-1002`), so the line
  grants nothing.
- `0126` backfill names "12 originally-seeded roles" predating `vice_president`,
  `customer_service_officer`, `sales_officer`, `maintenance_supervisor`
  (`api/database/migrations/0126_add_is_system_to_roles_table.php:23-36`); harmless since the
  seeder upserts `is_system=true` for all current roles (`:1049-1056`).

---

## 5. Gaps / blockers for dashboard work

1. **8 of 17 roles land on `/dashboard/default`.** `customer_service_officer`, `sales_officer`,
   `maintenance_tech`, `maintenance_supervisor`, `impex_officer`, `department_head`, `employee`,
   `driver` hold no `dashboard.*.view` grant (§1 table). Any dashboard-coverage matrix must treat
   the generic widget home as these roles' primary surface, not an edge case.
2. **3 roles have NO role-default layout rows.** `DashboardRoleLayoutSeeder::roleWidgets()` covers
   14 roles and omits `vice_president`, `customer_service_officer`, `sales_officer`
   (`api/database/seeders/DashboardRoleLayoutSeeder.php:31-129`); first login clones nothing for
   them (clone is per-role-default, `api/app/Modules/Auth/Services/AuthService.php:157-171`).
   Its comment "five roles with no `dashboard.*.view` grant" is stale — the actual count is eight.
3. **`vice_president` has no dashboard of its own** by design ("reads what it signs",
   `api/database/seeders/RolePermissionSeeder.php:624-628`) yet lands on the Plant Manager
   dashboard via a shared permission — executive vs operations readings are not separated.
4. **M1 blocks finance-widget reuse by permission.** The two finance slugs
   (`dashboard.accounting.view` for page/catalog/dispatch vs `accounting.dashboard.view` for data)
   mean widget/panel gating (`PanelGate`, `DashboardWidgetSeeder` uses `accounting.dashboard.view`,
   `api/database/seeders/DashboardWidgetSeeder.php:199-202`) and page gating answer to different
   authorities; any new finance surface must pick deliberately.
5. **`maintenance_supervisor` grants differ between migration and seeder.**
   Migration set lacks `maintenance.wo.verify` + `mrp.machines.view`
   (`api/database/migrations/2026_10_09_230100_add_maintenance_supervisor_role.php:39-46`);
   seeder adds both (`api/database/seeders/RolePermissionSeeder.php:910-913`). Deployed databases
   migrated but never re-seeded show a thinner supervisor than fresh installs.
6. **Factory PWA (`production.operator.access`) is held only by `production_manager` (+ admin).**
   No operator-rank role holds it (catalog `:300`, sole grant via `module('production')` at `:732`);
   shift operators on `employee`/`department_head` cannot open `/factory`
   (`spa/src/routes/factoryRoutes.tsx:12-25`) and must use `/self-service/work-orders` instead.
7. **Driver PWA (`supply_chain.driver.access`) is held only by `driver` (+ admin)**
   (sole grant at `api/database/seeders/RolePermissionSeeder.php:984`); ImpEx/warehouse cannot
   preview the driver surface.
8. **Approval-board visibility is row-scope-driven, not permission-driven** — any dashboard widget
   reusing `ApprovalTypeRegistry::userCanView()` (any-of-permissions,
   `api/app/Common/Support/ApprovalTypeRegistry.php:147-156`) over-exposes `leave` cards exactly
   the way the board used to; the correct call is `ApprovalSourceScope::visibleIds()`
   (`api/app/Common/Support/ApprovalSourceScope.php:49-104`), which encodes:
   leave = DepartmentScope ladder, loans/PRs/POs = owning policies, payroll/assets/bill_payment/
   returns = permission-gate only (no row scope exists).
9. **Reserved (non-enforced) workflow types must not appear as dashboard approval work:**
   `department_transfer`, `separation_clearance`, `maintenance_request`, `8d_report`, `work_order`,
   `ncr` are seeded but NOT wired to `ApprovalService::submit()`
   (`api/database/seeders/WorkflowSeeder.php:14-23`); `ApprovalTypeRegistry` covers only 9 kinds
   (`api/app/Common/Support/ApprovalTypeRegistry.php:20-107`).
