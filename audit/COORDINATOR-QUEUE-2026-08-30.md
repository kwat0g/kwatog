# Coordinator queue — rolling 3-agent audit pipeline

Started 2026-08-30. Owner: coordinator session (not a module session).
**Discipline: max 3 agents in flight. On each completion, launch exactly one replacement from the top of the queue.**

Rules the coordinator holds and agents never touch:
- Only the coordinator runs `audit/scripts/regenerate-registry.sh`, once per batch when all in-flight agents have finished. It truncates then appends row-by-row, so a concurrent reader sees a partial table.
- Each agent gets exactly one assigned module and must not wander. LOCKED → report back, do not pick another.
- Each agent uses its own test database. `RefreshDatabase` runs `migrate:fresh`; two suites on one database tear the schema out from under each other. The tell is hundreds of failures with zero assertion failures among them.
- `db` and `redis` stay up for the whole pipeline. No agent restarts them.
- Commit as ONE invocation: `git commit -m "…" -- <paths>`. The index is shared state, so `git add` then `git commit` is a race — that is how 8 attendance files landed under a quality commit message on 2026-08-30 (`32d91307`, recorded in `cb493487`). Caveat: pathspec commit **rejects untracked files**, so new files need an adjacent `git add <newfile>`.
- **There is NO pre-commit hook in this tree** — verified 2026-08-30: `core.hooksPath` points at an empty `.git/hooks`, no `.husky`, no lint-staged config. An earlier coordinator briefing claimed a lint-staged Prettier hook; that was wrong. Dirty SPA files come from an agent running a formatter itself, so agents must not run one across files they did not change. Note `spa/src/stores/recentItemsStore.ts`, `Topbar.tsx`, `CommandPalette.tsx` and `e2e/command-palette.spec.ts` are committed Prettier-dirty — if a hook is ever installed, the next touch yields a whole-file whitespace diff.

## Coordinator to-do, raised by agents, owned by nobody yet

- `docker rm ogami-meili` — an orphan container labelled to this Compose project makes **every** `docker compose` command in the repo print an orphan warning. Meilisearch is not used, not required, and referenced nowhere in code or compose; global search is pure Postgres `ILIKE`.
- `release-module.sh` stamps `last_session` in UTC, so a release at 06:xx local (+08:00) records the previous day. Cosmetic but it makes the registry look a day stale.
- `App\Common\Services\DocumentSequenceService::generate()` has an insert-then-reselect path that may race two concurrent first-of-month callers. Shared `Common` service, so no module session will own it. Needs a home.
- `docs/PATTERNS.md:262-268` — fix the `direction` → `orderBy()` bug at the source, or every service copied from the template keeps inheriting a 500.
- **CLAUDE.md says the approval chain is "4 levels (Staff → Dept Head → Manager → Officer → VP)". Leave implements exactly 2** (`WorkflowSeeder.php:26-29`, two pending enum states) and code/seeder/enum agree, so it reads as deliberate. Either the doc or the seeder is wrong; decide once, centrally, because every approval-bearing module is audited against that sentence.
- **`AccountsPayableHardeningTest` has one pre-existing failure**, seen from the supplier-portal session's dependency run and confirmed not attributable to it. It belongs to `accounts-payable`, which is marked `✅ Verified` — so either that verification was optimistic or something regressed since. Confirm before trusting the status.
- **No browser has run in this pipeline.** Four agents have reported Chromium/Playwright binaries absent with no X server. Every SPA claim so far is source- or jsdom-level; installing Chromium is the only thing that closes the browser-acceptance findings accumulating across modules.
- **P0 CROSS-MODULE, needs an owner: `App\Modules\HR\Services\UserProvisioningService::deactivateForEmployee()` (`:82-104`) has NO last-admin guard**, across 4 call paths — one of them the *unattended* clearance listener (`DeactivateAccountOnClearanceComplete.php:54`). Measured from M003: an `hr_officer` deactivation returned 204 and left **0 active administrators**. M003 correctly refused to fix another module's file, and notes it wants a *shared* guard rather than a copied one. Assign with `people/employee-master` (M014) or `people/onboarding` (M015), whichever owns that service.
- `api/tests/Feature/Accounting/AccountingPeriodDuplicateRecoveryTest.php:39` calls `DB::commit()` with no `RefreshDatabaseState::$migrated` reset — same class of suite-poisoning defect M003 just fixed in `RbacConcurrencyTest`. Accounting's to own.
- **Sanctum abilities are not enforced anywhere on the supplier portal** — tokens mint with no ability list (defaults `['*']`) and no route uses the `ability` middleware. That is "no abilities model", not "attached but unenforced".


Corrections established by sessions — these OVERRIDE the doc, pass them to every agent:
1. **`EdgeSystemUserResolver` and `auth:edge_device` do NOT exist.** The working helper is **`App\Common\Services\SystemUserResolver::impersonate()`**. A portal write under `auth:supplier_portal` was empirically verified to write audit rows with no FK violation — so the hazard CLAUDE.md describes is real but its named remedy is wrong. `config/auth.php` declares only `web`, `supplier_portal`, `customer_portal`.
2. Migration max confirmed **0478** on 2026-08-30; the figure goes stale, re-confirm with `ls api/database/migrations | grep -E '^04' | sort | tail -3`.
3. `docs/PATTERNS.md:262-268` — the canonical service template carries an unvalidated `direction` → `orderBy()` that 500s. Do not copy the bug when copying the template.
4. CLAUDE.md says the approval chain is "4 levels"; Leave implements exactly 2 and its code/seeder/enum agree. Verify the seeder, not the sentence.
5. `HashIdFilter::decode` accepts raw integers in **every** environment while `HasHashId` gates that shortcut behind `environment('testing')` — a raw int may work where a HashID is expected.

Test-harness traps that have burned sessions in this pipeline — worth repeating in every prompt:
- `UploadedFile::fake()->getMimeType()` derives from the **filename**, not the bytes, so a fake named `payload.pdf` reports `application/pdf` whatever it contains. A MIME-validation test built on `fake()` proves nothing; use a real `Illuminate\Http\UploadedFile` (Symfony finfo). One session nearly filed a false-positive bypass this way.
- `assertStringNotContainsString('a/b', $response->getContent())` is **unsound** — `json_encode` escapes `/` as `\/`.
- `APP_TIMEZONE=Asia/Manila`: a suite was red exactly one day in seven because a fixture assumed a weekday. Check the day of week before assuming a code bug.
- `RbacConcurrencyTest` commits active `system_admin` rows that survive the PHPUnit process without resetting `RefreshDatabaseState::$migrated`, silently disarming any later test whose premise is "no active system admin". A permission test that passes suspiciously should be re-run alone.
- Agents must **delete their own scratch probes** before releasing. `api/probe_seed.php`, `api/probe_check.php`, `api/tests/Feature/Admin/ZzUserAdminProbeTest.php` were all left behind.

---

## TIER A — `🔁 Needs Re-audit`, stale-locked (32)

Ordered by tier, then dependency order. Locks are orphans from 2026-08-25, so the
default 6h stale rule reclaims them; expect RECLAIMED, and read the module's
existing `fix-log.md` / `git diff` before trusting its status.

| # | Tier | ID | Module | State |
|---|---|---|---|---|
| 1 | 1 | M001 | platform/auth-session | **in flight** |
| 2 | 1 | M003 | platform/user-administration | done |
| 3 | 2 | M026 | finance/journal-ledger | **in flight** |
| 4 | 2 | M028 | finance/accounts-receivable | queued — HOLD while M026 journal-ledger is in flight; AR posts through JournalEntryService, which that session is editing |
| 5 | 2 | M032 | commercial/customer-product-pricing | **in flight** |
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
| M001 | platform/auth-session | 2026-08-30 |
| M026 | finance/journal-ledger | 2026-08-30 |
| M032 | commercial/customer-product-pricing | 2026-08-30 |

## Completed this pipeline

| ID | Module | Released as | Notes |
|---|---|---|---|
| M031 | finance/fixed-assets-depreciation | 🔁 Needs Re-audit | F18 salvage bound + F20 N+1 fixed. F15 disposal-month policy open. |
| M036 | procurement/purchase-requests | 🔁 Needs Re-audit | 5 fixed incl. draft lock-then-guard race. 4 pricing questions open. |
| M059 | quality/calibration-quality-analytics | 🔁 Needs Re-audit | Calibration PATCH answered 500 in every non-production env. 6 fixed. |
| M018 | people/attendance-dtr | 🔁 Needs Re-audit | 3 fixed. Extended-shift OT pays nothing — open question. |
| M009 | platform/global-search | 🔁 Needs Re-audit | Switched-off modules were still searchable — fixed. 13×11 permission matrix executed, 0 leaks. F17 handed to purchase-orders. |
| M019 | people/leave-management | 🔁 Needs Re-audit | P0: cancelling an approved request after year-end resurrects already-encashed credits. 2 fixed (incl. a suite red 1 day in 7). |
| M047 | supply-chain/supplier-portal | 🔁 Needs Re-audit | 3 stacked defects in one method, each hiding the next — two supplier PO-detail panels had never displayed. Cross-tenant: 21/25 routes probed, no leak. |
| M003 | platform/user-administration | 🔁 Needs Re-audit | Prior session's 9 fixes were all already committed; 9/9 no longer reproduce. 16-row escalation matrix all refused. P0 found in HR's UserProvisioningService (cross-module). |


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
