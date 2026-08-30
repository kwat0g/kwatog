# Coordinator queue — rolling 3-agent audit pipeline

Started 2026-08-30. Owner: coordinator session (not a module session).
**Discipline: max 3 agents in flight. On each completion, launch exactly one replacement from the top of the queue.**

**Scheduling rule learned 2026-08-30:** do not run two modules that share a Laravel module directory concurrently. `supplier-performance` and `goods-receiving` both live in / write to `api/app/Modules/Purchasing/`, so they wait while `purchase-orders` is live. Same reason AR and payroll waited for `journal-ledger`. Deviating from queue order for this is correct; record the reason in the table.

**Verification rule learned 2026-08-30 (the most important one):** a `fix-log.md` full of confident verification claims can be worthless. Four `separation-final-pay` sessions wrote them from runs that produced *"34 failures and 0 assertions"* — the signature of two suites sharing one database. Every agent must be told to re-measure by probe and to check whether a prior claim came from a run that actually executed.

Rules the coordinator holds and agents never touch:
- Only the coordinator runs `audit/scripts/regenerate-registry.sh`, once per batch when all in-flight agents have finished. It truncates then appends row-by-row, so a concurrent reader sees a partial table.
- Each agent gets exactly one assigned module and must not wander. LOCKED → report back, do not pick another.
- **VERIFY `docker compose ps` BEFORE ANY PROBE.** The real cause of four sessions' "0 assertions" was found 2026-08-30: **every container in the compose project had been stopped**, so `SQLSTATE[08006] host "db" could not be resolved` read as a broken test bootstrap. Every agent must run `docker compose ps` + a `select 1;` first, start only `db`+`redis` if needed, and quote a real numeric baseline before changing anything.
- **Instruct every agent to commit incrementally and log as it goes.** Three agents were killed mid-flight by quota on 2026-08-30; the two that had saved logging and committing for the end lost everything they had discovered.
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
- **`api/app/Modules/CRM/routes.php:25` — `/crm/customers/{customer}/restore` lacks `->withTrashed()`, so it 404s for every valid target.** Third instance of this exact defect in the pipeline (Assets and one other). Belongs to a CRM module not yet audited; worth a single sweep across all restore routes rather than three more rediscoveries.
- **`ReturnRequestService:1362-1364` forwards an RMA's `customer_id`/`invoice_id` without cross-checking they belong together**, and `CustomerReturnRestockOnDisposeTest:135,155,205,238` calls a `customer()` helper that mints a NEW row per call — so the fixtures are genuinely inconsistent. AR wrote a guard for the AR side, found it turned 3 return-management tests red on that fixture data, and reverted it (revert proven comment-only). Return-management owns this.
- **CONFIRMED: a harness `PostToolUse` hook reformats UI files.** `.claude/settings.local.json` runs the `impeccable` skill's `hook.mjs` on `Edit|Write|MultiEdit`. A session measured it Prettier-reformatting its two `.tsx` files (605/343 lines for a 5-line change) **and `spa/src/lib/emptyStateCopy.ts`, which it never touched**. This — not agents running formatters by hand — is the real cause of the neighbouring-module SPA dirt seen all pipeline. The earlier queue note blaming agents was wrong. Restoring the untouched file from HEAD was safe **only because its owning module had already committed** its one-word fix, which is precisely why Step 7b is not ceremony. The repo is broadly not Prettier-clean (28 accounting files fail at HEAD), so accepting a reformat makes files outliers; running Prettier repo-wide is a separate decision.
- **P0 CROSS-MODULE, needs an owner: `App\Modules\HR\Services\UserProvisioningService::deactivateForEmployee()` (`:82-104`) has NO last-admin guard**, across 4 call paths — one of them the *unattended* clearance listener (`DeactivateAccountOnClearanceComplete.php:54`). Measured from M003: an `hr_officer` deactivation returned 204 and left **0 active administrators**. M003 correctly refused to fix another module's file, and notes it wants a *shared* guard rather than a copied one. Assign with `people/employee-master` (M014) or `people/onboarding` (M015), whichever owns that service.
- `api/tests/Feature/Accounting/AccountingPeriodDuplicateRecoveryTest.php:39` calls `DB::commit()` with no `RefreshDatabaseState::$migrated` reset — same class of suite-poisoning defect M003 just fixed in `RbacConcurrencyTest`. Accounting's to own.
- **CROSS-MODULE, needs an owner: the supplier portal writes its bearer token to `sessionStorage`** (`spa/src/api/b2b/client.ts:14-38`), which CLAUDE.md forbids outright ("NEVER store auth in localStorage/sessionStorage"). The fix is to flip the `supplier_portal` guard from `sanctum` to `session` as `customer_portal` already is — so it spans B2B + auth config, and `supply-chain/supplier-portal` has already released. Needs re-assignment.
- **Decision #12's blast radius is now measured in four modules** — Assets (0 of 36 depreciation JEs had a maker), AR (every AR posting), Payroll (every payroll JE), GL itself (9 of 11 reference types pass a real user and have it discarded). Because `posted_by` gets the same user, `assertNotSelfPosting()` early-returns, so the JE maker-checker guard is **inert system-wide on automated postings**. This is ONE control, not four findings. `PayrollMoneyFindingsRegressionTest:208` is deliberately left RED asserting it.
- **Sanctum abilities are not enforced anywhere on the supplier portal** — tokens mint with no ability list (defaults `['*']`) and no route uses the `ability` middleware. That is "no abilities model", not "attached but unenforced".


Corrections established by sessions — these OVERRIDE the doc, pass them to every agent:
1. **`EdgeSystemUserResolver` and `auth:edge_device` do NOT exist.** The working helper is **`App\Common\Services\SystemUserResolver::impersonate()`**. **TWO independent sessions failed to reproduce the `audit_logs` FK violation** that section warns about — treat the whole section as obsolete rather than a hazard to design around. `config/auth.php` declares only `web`, `supplier_portal`, `customer_portal`.
2. Migration max confirmed **0478** on 2026-08-30; the figure goes stale, re-confirm with `ls api/database/migrations | grep -E '^04' | sort | tail -3`.
3. `docs/PATTERNS.md:262-268` — the canonical service template carries an unvalidated `direction` → `orderBy()` that 500s. Do not copy the bug when copying the template.
4. CLAUDE.md says the approval chain is "4 levels"; Leave implements exactly 2 and its code/seeder/enum agree. Verify the seeder, not the sentence.
5. `HashIdFilter::decode` accepts raw integers in **every** environment while `HasHashId` gates that shortcut behind `environment('testing')` — a raw int may work where a HashID is expected.

Test-harness traps that have burned sessions in this pipeline — worth repeating in every prompt:
- `UploadedFile::fake()->getMimeType()` derives from the **filename**, not the bytes, so a fake named `payload.pdf` reports `application/pdf` whatever it contains. A MIME-validation test built on `fake()` proves nothing; use a real `Illuminate\Http\UploadedFile` (Symfony finfo). One session nearly filed a false-positive bypass this way.
- `assertStringNotContainsString('a/b', $response->getContent())` is **unsound** — `json_encode` escapes `/` as `\/`.
- `APP_TIMEZONE=Asia/Manila`: a suite was red exactly one day in seven because a fixture assumed a weekday. Check the day of week before assuming a code bug.
- `RbacConcurrencyTest` commits active `system_admin` rows that survive the PHPUnit process without resetting `RefreshDatabaseState::$migrated`, silently disarming any later test whose premise is "no active system admin". A permission test that passes suspiciously should be re-run alone.
- **Do NOT use `php artisan serve` for live HTTP probes.** It passes an env allow-list to its `php -S` child, so `-e DB_DATABASE=…` is **silently dropped** and every request hits the dev `ogami` database. One session found this only after writing 2 `login_history` rows and 1 session row into dev. Use `php -S` with an explicit router, or stay inside PHPUnit.
- `APP_TIMEZONE=Asia/Manila` but containers are UTC, so back-dating a timestamp with SQL `now()` skews **8 hours**. Go through Carbon / the app clock. No auth defect came from this (all comparisons use Carbon) but raw SQL on those columns is wrong.
- Agents must **delete their own scratch probes** before releasing. `api/probe_seed.php`, `api/probe_check.php`, `api/tests/Feature/Admin/ZzUserAdminProbeTest.php` were all left behind.

---

## TIER A — `🔁 Needs Re-audit`, stale-locked (32)

Ordered by tier, then dependency order. Locks are orphans from 2026-08-25, so the
default 6h stale rule reclaims them; expect RECLAIMED, and read the module's
existing `fix-log.md` / `git diff` before trusting its status.

| # | Tier | ID | Module | State |
|---|---|---|---|---|
| 1 | 1 | M001 | platform/auth-session | done |
| 2 | 1 | M003 | platform/user-administration | done |
| 3 | 2 | M026 | finance/journal-ledger | done |
| 4 | 2 | M028 | finance/accounts-receivable | done |
| 5 | 2 | M032 | commercial/customer-product-pricing | done |
| 6 | 2 | M020 | people/loans-cash-advances | done |
| 7 | 2 | M021 | people/payroll-period-processing | done |
| 8 | 2 | M023 | people/separation-final-pay | done |
| 9 | 3 | M037 | procurement/purchase-orders | done (work committed in `dd120ef0`; died at the release step only) |
| 10 | 3 | M038 | procurement/supplier-performance | **in flight** |
| 11 | 3 | M041 | inventory/goods-receiving | queued |
| 12 | 3 | M040 | inventory/warehouse-stock-control | queued |
| 13 | 3 | M042 | inventory/material-issues-reservations | queued |
| 14 | 3 | M056 | quality/inspections-certificates | done |
| 15 | 3 | M057 | quality/ncr-capa | queued |
| 16 | 3 | M054 | quality/material-review-board | queued |
| 17 | 3 | M058 | quality/traceability-ppap | queued |
| 18 | 3 | M051 | manufacturing/production-work-orders | **in flight** (re-launched after quota abort) |
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

Quota restored and the pipeline resumed 2026-08-30 via a **single-agent probe**
(M056) rather than three at once — the cheap way to test a budget stop. It
completed, so the other two slots were refilled.

| ID | Module | Launched |
|---|---|---|
| M051 | manufacturing/production-work-orders | 2026-08-30 (re-launch) |
| M038 | procurement/supplier-performance | 2026-08-30 |

Next up: M041, M040, M042, then the remaining Tier 3/4 list.

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
| M026 | finance/journal-ledger | 🔁 Needs Re-audit | 7 RED→15 GREEN. Archive/restore produced a phantom header claiming ₱100 with 0 lines; a raw writer could archive-then-promote past the trigger; `numeric` amounts let `1.999`→`2.00` and `1e17`→500. Decision #12 fully characterised, 4 options, nothing applied. |
| M032 | commercial/customer-product-pricing | 🔁 Needs Re-audit | Date **strings** compared, so `12/01/2026` vs `2026-03-31` persisted an impossible window that permanently unprices a customer/product — fixed. Below-lowest-tier price contradicts the UI by 8x — open question. |
| M020 | people/loans-cash-advances | 🔁 Needs Re-audit | No company loan can EVER be disbursed (workflow step 2 role holds no `loans.*`). A borrower can never be separated. 4 contained fixes. |
| M028 | finance/accounts-receivable | 🔁 Needs Re-audit | Statement reported ₱800/₱800/₱500 for the SAME rows; two credit notes drove GL AR to −₱1,000; 12% VAT charged on a VAT-exempt invoice. 3 fixed (all 500s), 7 of 9 new tests red at HEAD. No AR payment void exists at all. |
| M023 | people/separation-final-pay | 🔁 Needs Re-audit | **Prior 4 sessions never measured anything** — their verification came from a shared DB reporting "34 failures / 0 assertions". All 13 findings reproduced, +5 new. 13th month and last salary each paid TWICE; leave conversion uncapped and never debited. 6 contained fixes, 10 of 12 tests red at HEAD. |
| M021 | people/payroll-period-processing | 🔁 Needs Re-audit | Loan over-deduction mechanism identified: an as-of `reconcileAggregates` cut drops ledger rows dated after `payroll_date`, taking the ledger to ₱14,000 on ₱12,000 owed. Anomaly gate **fails OPEN** — a bad setting yields zero flags and approve+finalize both succeed. 4 fixed, 8 of 14 tests red at HEAD. |
| M056 | quality/inspections-certificates | 🔁 Needs Re-audit | P0: a CoC could be issued with **zero measurement rows**, with 45/50 units unresolved, after readings were rewritten to fail, and after **all evidence was deleted** — re-issuing the same number with a blank critical-dimension table. Fixed, 6 of 7 tests red at HEAD. Found the compose-containers-down root cause. IC-16: the in-process QC gate does not exist. |
| M037 | procurement/purchase-orders | 🔁 Needs Re-audit | A blocked three-way match rendered as a green "Matched". Fix + 204-line test + all three audit docs committed in `dd120ef0` before the quota kill; only the release step was missed. |
| M001 | platform/auth-session | 🔁 Needs Re-audit | Idle session timeout was opt-out via a client-supplied `Authorization` header — fixed. Login + reset timing oracles and an ip\|email-keyed limiter deferred (locking out 200+ employees is the failure mode). 59-row control checklist in audit-report.md. |


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
