# OGAMI ERP — Dashboards/DSS Progress (single source of truth)

## Mission + branch

- Mission: dashboard/DSS discovery → design → build, starting with Phase 0 read-only
  discovery of roles/access, modules/data, approvals, existing dashboard/DSS, frontend
  foundation, and realtime/perf.
- Branch: `docs/dss-discovery-phase0`. Phase 0 writes docs only — no feature code.

## Resource constraints (binding on all later phases)

- Sequential agents only; never dispatch subagents (Phase 0 synthesis rule; later phases
  follow the mission's own parallelism rules, but DB access stays serialised).
- One test suite at a time per database. Two agents/suites must NOT share `ogami_test`
  (`RefreshDatabase` + `migrate:fresh` tears the schema mid-run; tell = hundreds of
  failures with zero assertion failures). Mint a private DB per worker:
  `CREATE DATABASE ogami_test_<name> OWNER ogami;` + `DB_DATABASE=ogami_test_<name>`.
- Targeted tests in tight loops (`--filter='Foo|Bar'`); full suite (~1900 tests / ~28 min,
  sharded via `make test`) only at end of feature. Perf/Redis/Reverb claims need staging
  smokes (`make chain-smoke`, `make worker-recovery-smoke`), not PHPUnit (test env forces
  sync/array/log).
- Index migrations must respect `0NNN_` vs `2026_*` ordering (dependency rule in CLAUDE.md).

## Phase 0 status — COMPLETE (6/6 sections + synthesis)

| # | Section | File | Status |
|---|---|---|---|
| A | Roles & access (17 roles, authority chain, 12 mismatches) | `docs/dashboards/phase0/A-roles-access.md` | done |
| B | Modules & data (20 areas, 3 chains, verdicts, 86 index gaps) | `docs/dashboards/phase0/B-modules-data.md` | done |
| C | Approvals (9 ledger + 14 custom, stuck risks, readiness) | `docs/dashboards/phase0/C-approvals.md` | done |
| D | Dashboard & DSS as-built (pages, KPIs, forecasts, 38 behaviors, 12 opportunities) | `docs/dashboards/phase0/D-dashboard-dss.md` | done |
| E | Frontend (deps, 30 widgets, tokens, gaps) | `docs/dashboards/phase0/E-frontend.md` | done |
| F | Realtime/perf (14 events, 32 cache keys, 15 risks, test constraints) | `docs/dashboards/phase0/F-realtime-perf.md` | done |
| S | Executive synthesis | `docs/dashboards/00-discovery.md` | done |
| S | Bug/gap log (68 items, all open) | `docs/dashboards/FINDINGS.md` | done |
| S | This file | `docs/dashboards/PROGRESS.md` | done |

Conflicts noted during synthesis (see `00-discovery.md` "Discrepancies" for detail):
1. "9 ledger types" = 8 live + 1 dead (`payroll` never writes rows).
2. Widget scalar count 54 (D) vs 55 (F) — recount `DashboardWidgetSeeder.php:48-105`
   before citing. No other cross-section conflicts.

## Phase 1 status — PENDING

Not started. Suggested entry order from ranked blockers (`00-discovery.md`):
board-blind card sourcing → notification gaps → index migrations → finance authority pick.

## Later phases — PENDING

Awaiting mission phasing past Phase 1.

## Coverage checklist skeleton (unchecked — check off as later phases cover each)

### Roles (from A §1)

- [ ] system_admin
- [ ] hr_officer
- [ ] finance_officer
- [ ] vice_president
- [ ] customer_service_officer
- [ ] sales_officer
- [ ] production_manager
- [ ] ppc_head
- [ ] purchasing_officer
- [ ] warehouse_staff
- [ ] qc_inspector
- [ ] maintenance_tech
- [ ] maintenance_supervisor
- [ ] impex_officer
- [ ] department_head
- [ ] employee
- [ ] driver

### Modules / data areas (from B §1)

- [ ] HR
- [ ] Attendance
- [ ] Leave
- [ ] Payroll
- [ ] Loans
- [ ] Accounting (incl. budgeting)
- [ ] Inventory
- [ ] Purchasing
- [ ] SupplyChain
- [ ] Production
- [ ] MRP
- [ ] CRM
- [ ] B2B
- [ ] Forecasting
- [ ] ReturnManagement
- [ ] Assets
- [ ] Quality
- [ ] Maintenance
- [ ] Dashboard (KPI/widgets/layout/action-center)
- [ ] Auth/Admin (roles, overrides, audit, delegations)

### Approval chains (from C §1a ledger + §1b custom)

Ledger (step-ledger):
- [ ] leave
- [ ] pr
- [ ] po
- [ ] bill_payment
- [ ] loan
- [ ] salary_adjustment
- [ ] asset_disposal
- [ ] return_request
- [ ] payroll (dead kind — wire or drop)

Custom single-step:
- [ ] overtime
- [ ] stock_adjustment
- [ ] item_cost_review
- [ ] stock_count_variance
- [ ] grn_reject
- [ ] payroll_adjustment
- [ ] budget
- [ ] budget_transfer
- [ ] invoice_prebill
- [ ] supplier_listing
- [ ] asset_transfer (unrouted)
- [ ] return_case
- [ ] loan_write_off
- [ ] ppap / late_production_report

## How a fresh session resumes

1. Read this file (`docs/dashboards/PROGRESS.md`) for mission state.
2. Read the synthesis (`docs/dashboards/00-discovery.md`) for the full picture.
3. Drop into section files (`docs/dashboards/phase0/A–F`) only for the detail a task needs.
4. Check `docs/dashboards/FINDINGS.md` for the open bug/gap relevant to the task.
5. Respect the resource constraints above (serial DB access, private test DBs, targeted tests).
6. Before touching chains/permissions: run
   `tests/Feature/Approvals/ApprovalChainRolePermissionDriftTest.php` first (C §5.9).
