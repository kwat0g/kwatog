# Coordinator queue — rolling 3-agent audit pipeline

Started 2026-08-30. Owner: coordinator session (not a module session).
**Discipline: max 3 agents in flight. On each completion, launch exactly one replacement from the top of the queue.**

Rules the coordinator holds and agents never touch:
- Only the coordinator runs `audit/scripts/regenerate-registry.sh`, once per batch when all in-flight agents have finished. It truncates then appends row-by-row, so a concurrent reader sees a partial table.
- Each agent gets exactly one assigned module and must not wander. LOCKED → report back, do not pick another.
- Each agent uses its own test database. `RefreshDatabase` runs `migrate:fresh`; two suites on one database tear the schema out from under each other. The tell is hundreds of failures with zero assertion failures among them.
- `db` and `redis` stay up for the whole pipeline. No agent restarts them.
- Commit as ONE invocation: `git commit -m "…" -- <paths>`. The index is shared state, so `git add` then `git commit` is a race — that is how 8 attendance files landed under a quality commit message on 2026-08-30 (`32d91307`, recorded in `cb493487`).
- A lint-staged Prettier pre-commit hook reformats neighbouring modules' SPA files. Agents report that dirt; the coordinator cleans it.

Known-stale CLAUDE.md spots, passed to every agent:
1. `EdgeSystemUserResolver` and the `auth:edge_device` guard **do not exist**. `config/auth.php` declares only `web`, `supplier_portal`, `customer_portal`.
2. The migration-max figure goes stale as sessions land. Confirm with `ls api/database/migrations | grep -E '^04' | sort | tail -3`.
3. `docs/PATTERNS.md:262-268` — the canonical service template carries an unvalidated `direction` → `orderBy()` that 500s. Do not copy the bug when copying the template.

---

## TIER A — `🔁 Needs Re-audit`, stale-locked (32)

Ordered by tier, then dependency order. Locks are orphans from 2026-08-25, so the
default 6h stale rule reclaims them; expect RECLAIMED, and read the module's
existing `fix-log.md` / `git diff` before trusting its status.

| # | Tier | ID | Module | State |
|---|---|---|---|---|
| 1 | 1 | M001 | platform/auth-session | queued |
| 2 | 1 | M003 | platform/user-administration | queued |
| 3 | 2 | M026 | finance/journal-ledger | queued |
| 4 | 2 | M028 | finance/accounts-receivable | queued |
| 5 | 2 | M032 | commercial/customer-product-pricing | queued |
| 6 | 2 | M020 | people/loans-cash-advances | queued |
| 7 | 2 | M021 | people/payroll-period-processing | queued |
| 8 | 2 | M023 | people/separation-final-pay | queued |
| 9 | 3 | M037 | procurement/purchase-orders | queued |
| 10 | 3 | M038 | procurement/supplier-performance | queued |
| 11 | 3 | M041 | inventory/goods-receiving | queued |
| 12 | 3 | M040 | inventory/warehouse-stock-control | queued |
| 13 | 3 | M042 | inventory/material-issues-reservations | queued |
| 14 | 3 | M056 | quality/inspections-certificates | queued |
| 15 | 3 | M057 | quality/ncr-capa | queued |
| 16 | 3 | M054 | quality/material-review-board | queued |
| 17 | 3 | M058 | quality/traceability-ppap | queued |
| 18 | 3 | M051 | manufacturing/production-work-orders | queued |
| 19 | 3 | M050 | manufacturing/capacity-scheduling | queued |
| 20 | 3 | M053 | manufacturing/maintenance-machine-health | queued |
| 21 | 3 | M048 | manufacturing/demand-forecasting | queued |
| 22 | 3 | M043 | supply-chain/import-shipments-customs | queued |
| 23 | 3 | M044 | supply-chain/deliveries-proof | queued |
| 24 | 3 | M045 | supply-chain/fleet-driver | queued |
| 25 | 4 | M006 | platform/notifications | queued |
| 26 | 4 | M008 | platform/alerts | queued |
| 27 | 4 | M013 | platform/chain-monitoring | queued |
| 28 | 4 | M015 | people/onboarding | queued |
| 29 | 4 | M016 | people/recruitment-careers | queued |
| 30 | 4 | M017 | people/training-skills | queued |
| 31 | 4 | M024 | people/employee-self-service | queued |
| 32 | 4 | M060 | public/corporate-site-contact | queued |

## TIER B — `📋 Plan Ready` (14): audit done, fixes deferred

These need an implementation session against an existing `action-plan.md`, not a
fresh discovery pass.

| # | Tier | ID | Module | State |
|---|---|---|---|---|
| 33 | 1 | M004 | platform/audit-activity | queued |
| 34 | 1 | M005 | platform/approval-workflows | queued |
| 35 | 1 | M014 | people/employee-master | queued |
| 36 | 2 | M025 | finance/chart-of-accounts-periods | queued |
| 37 | 2 | M029 | finance/financial-statements | queued |
| 38 | 2 | M022 | people/payslip-statutory-disbursement | queued |
| 39 | 2 | M033 | commercial/sales-orders | queued |
| 40 | 3 | M049 | manufacturing/bom-mrp-planning | queued |
| 41 | 3 | M034 | commercial/customer-complaints-8d | queued |
| 42 | 3 | M046 | supply-chain/returns-rma | queued |
| 43 | 4 | M007 | platform/dashboards-kpis | queued |
| 44 | 4 | M011 | platform/documents-exports | queued |
| 45 | 4 | M012 | platform/backups-system-settings | queued |
| 46 | 4 | M035 | commercial/customer-portal | queued |

---

## In flight

| ID | Module | Launched |
|---|---|---|
| M019 | people/leave-management | 2026-08-30 |
| M009 | platform/global-search | 2026-08-30 |
| M047 | supply-chain/supplier-portal | 2026-08-30 |

## Completed this pipeline

| ID | Module | Released as | Notes |
|---|---|---|---|
| M031 | finance/fixed-assets-depreciation | 🔁 Needs Re-audit | F18 salvage bound + F20 N+1 fixed. F15 disposal-month policy open. |
| M036 | procurement/purchase-requests | 🔁 Needs Re-audit | 5 fixed incl. draft lock-then-guard race. 4 pricing questions open. |
| M059 | quality/calibration-quality-analytics | 🔁 Needs Re-audit | Calibration PATCH answered 500 in every non-production env. 6 fixed. |
| M018 | people/attendance-dtr | 🔁 Needs Re-audit | 3 fixed. Extended-shift OT pays nothing — open question. |

## Held out deliberately

`✅ Verified` (7): M002, M010, M027, M030, M039, M052, M055.

The four completed above are `🔁 Needs Re-audit` but are **decision-blocked, not
code-blocked** — every remaining item waits on a human answer about a reported
money figure or a product scope call. Re-auditing them before those answers
arrive would burn a session to re-derive findings already written down.

## Open decisions blocking those four

1. **M018** — what does Ogami pay someone rostered 6AM–6PM? Every extended-shift payslip moves either way.
2. **M018** — is the OT ceiling 4h or 8h? One of two live settings is wrong.
3. **M031** — disposal-month depreciation convention. ₱11,000 vs ₱10,800 loss on the same fixture.
4. **M059** — capability-study access: Quality-owned endpoint, grant `crm.products.view` to `qc_inspector`, or narrow the route guard? Today only `system_admin` can run one.
5. **M036** — may a PR line carry an unknown price; is a catalog price an enforced standard cost or a requester estimate; does the 2026-08-08 template scope cut still stand; `urgency_reason` + urgent-skip as one control.
