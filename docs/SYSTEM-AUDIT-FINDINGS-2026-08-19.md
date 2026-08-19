# Ogami ERP — System Audit Findings Register (2026-08-19)

**Audit date:** 2026-08-19
**Scope:** the authorization surface of the dashboard layer — the eight
purpose-built dashboards, the widget registry, the permission catalogue that
gates both — and the browser-based RBAC verification tools that were supposed to
be watching it.
**Method:** discovery against seeder and service source with the role ×
permission matrix read from the running PostgreSQL `role_permissions` table
rather than inferred; then live verification through the repository's own audit
scripts (`audit:rbac`, `audit:role-permissions`, `audit:role-dashboards`,
`audit:dynamic-routes`, `audit:api-routes`) and a new one written for the gap
they left (`audit:panel-gates`). File and line references are to the current
worktree.
**Relationship to the 2026-08-16 register:** additive. F-001–F-045 are
unaffected; this register begins at F-048.

**Renumbered on merge, 2026-08-20.** This register was written as F-046–F-052
while the 08-16 register still ended at F-045. It then developed concurrently
with `chore/remaining-backlog` and `chore/backlog-t4-t5`, which appended their
own F-046 (dead acceptance-test gates) and F-047 (the discarded scheduler health
verdict) to the 08-16 register and merged first, as PRs #107 and #108. Two
registers therefore claimed the same two numbers. The already-merged pair keeps
them, because merged commits, `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`
and `api/phpunit.xml:12` all cite them; this register shifted up by two instead.

**So the commit messages on this branch are two behind the headings above.**
`d393d939`, `9188fce5`, `410552a9`, `047c8e50` and the rest say F-046–F-052 and
mean F-048–F-054. Reading the old number in a commit message and looking it up
in this file lands on the wrong finding. Mapping, for anyone doing that:

| commit message says | this register calls it |
|---|---|
| F-046 | F-048 — dashboard panels gated once at the route |
| F-047 | F-049 — HR payroll panel gated on a universal permission |
| F-048 | F-050 — deliveries had no read permission of their own |
| F-049 | F-051 — `ppc_head` could not read the production schedule |
| F-050 | F-052 — the RBAC audit demanded the information leak back |
| F-051 | F-053 — employees page fetched unconditionally |
| F-052 | F-054 — whitespace text node in the RMA table row |

No commit references F-055; it was registered after the renumber.

**Provenance, stated plainly.** Unlike the 08-13 and 08-16 registers, this one
was not produced by a standalone audit pass. It records defects found while
implementing dashboard role-tiering, and F-048–F-054 were each fixed in the same
sitting. It is written because the defects were real authorization findings and
existed nowhere but commit messages. **F-055 is the exception: it was added
2026-08-20, is outside the dashboard scope above, and is open.**

**Retracted observation — F-041 reported as an unclosed finding.** During
discovery this session, F-041 (the MRP plan badge fixture) was twice reported as
"still listed open" on the strength of its narrative remaining in the 08-16
register. That was wrong: `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json` records
F-041 `verified`, with regression proof, and the fixture at
`api/tests/Feature/Dashboard/BadgeControllerTest.php:205-226` already gives each
`mrp_plans` row its own sales order. The register narrates the finding; the
lifecycle file carries its state. That is the design, not a gap. No F-number is
assigned. Recorded so it is not re-investigated.

**Method note — the suite cannot be shared.** Three full-suite runs during this
work reported 199, 216 and 29 failures with **zero** assertion failures among
them; every one was `relation "roles" does not exist`, `column roles.deleted_at
does not exist`, or `SQLSTATE[40P01] deadlock detected`. A second agent's
`RefreshDatabase` was running `migrate:fresh` against the shared `ogami_test`
database concurrently. This is the same trap the 08-16 register recorded as a
retracted observation, reached by a different route, and it cost three runs
before being diagnosed rather than assumed. Verification here was completed on a
dedicated database (`DB_DATABASE=ogami_test_verify`), which removed all of it:
**1900 passed**. The workflow is now documented in `CLAUDE.md`.

---

### F-048 — The eight purpose-built dashboards were gated once at the route, so holding the page permission delivered every domain the page draws from — including the plant manager's cash, AR, AP and posted revenue

- **Module / feature:** Dashboard — the eight bespoke role dashboards.
- **Related modules:** Accounting (the leaked figures), Production, Quality, MRP, Inventory, Purchasing, SupplyChain, HR, Payroll, Budgeting (each contributes a panel to one of these pages).
- **Category:** Authorization / information disclosure.
- **Affected roles:** `production_manager` concretely, for finance data. Structurally, every holder of a `dashboard.*.view` permission on every one of the eight pages.
- **Current Behavior:** Each dashboard carried exactly one authorization check, the route middleware — `permission:dashboard.plant_manager.view` and its seven siblings (`api/app/Modules/Dashboard/routes.php:21-42`). Every panel inside then ran unconditionally. `PlantManagerDashboardService::plantManager` assembled `chain_stages`, `alerts`, `machine_util`, `defect_pareto` and `financial_snapshot` with no per-panel condition, and `plantFinancialSnapshot()` (`api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php:87`) reports the cash balance, AR outstanding, AP outstanding, posted revenue month-to-date and the draft journal-entry count.
- **Problem:** `production_manager` — the only seeded holder of `dashboard.plant_manager.view` — holds **no** `accounting.*` permission whatsoever. Confirmed against the live `role_permissions` table, not inferred: of `accounting.dashboard.view`, `accounting.invoices.view`, `accounting.bills.view` and `accounting.journal.view`, it holds none. The page handed it all four money figures anyway, plus a Revenue KPI tile. The widget registry never had this defect — a `dashboard_widgets` row declares its own permission and `DashboardLayoutService` strips what the caller cannot hold — so the same data was correctly withheld on the generic dashboard and disclosed on the bespoke one.
- **Real-world scenario:** The production manager opens the dashboard the system routes them to on login and reads the company's cash position, its receivables, its payables and its month-to-date revenue. Nothing in the product granted that, and the Accounting module refuses them every page those figures come from.
- **Root Cause:** Page-level authorization used as though it were data-level. A `dashboard.*.view` permission answers "may this person open this page", which is not the same question as "may this person read each domain the page aggregates", and one check cannot answer both.
- **Recommended Improvement:** Give a panel the same property a widget row already has: its permission declared next to the query that fills it, evaluated per viewer, with a refused panel **omitted**. Implemented as `App\Modules\Dashboard\Support\PanelGate` (`panels()` at `:39`, `kpis()` at `:61`). Panels are passed as closures so a refused panel's query never runs. Omitted rather than zeroed deliberately: "AR outstanding ₱0.00" reads as a settled ledger, which is a worse failure than the disclosure.
- **Ideal Process:** A dashboard panel is a read of a module. It is gated by that module's grant, on the same terms as any other read of it, and the page permission gates only arrival.
- **New Feature/Module Required:** No. One support class plus per-panel declarations in the nine existing services.
- **Cross-Module Impact:** All eight bespoke dashboards, and `FinanceDashboardService`, which needed additional care: it caches under one key shared across every caller, so per-viewer gating would have served one viewer's panel set to another. Its key now carries `PanelGate::signature` (`:92`), a fingerprint of how the caller answers the gates — two finance officers still share one cache entry, a narrower viewer gets its own.
- **Evidence:** `api/app/Modules/Dashboard/routes.php:21-42`; `api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php:51,64,74,87`; `api/app/Modules/Dashboard/Support/PanelGate.php:39,61,92`; `api/app/Modules/Dashboard/Services/DashboardLayoutService.php:268-303` for the contrasting widget behaviour. Regression proof: `DashboardPanelGateTest` — **12 tests / 46 assertions**, including the cache-isolation case that warms the shared Finance entry with the privileged payload **first** and then asserts the narrow caller is not served it. Browser proof: `npm run audit:panel-gates` — **5 checks**, asserting the same page shows no money to `production@ogami.test` and the full snapshot to `admin@ogami.test`; role-asymmetry on one URL is what makes it evidence, since a timing artifact would hide the panel from both. Full suite: **1900 passed** on an isolated database.
- **Priority:** P1.
- **Impact:** Company financial position disclosed to a role with no finance grant, on the dashboard that role lands on by default. Structurally, seven further pages had the same class of exposure latent in them.
- **Complexity:** M.
- **Status note:** Registered and fixed 2026-08-18 (`d393d939`).

### F-049 — The HR dashboard's payroll panel was gated on `payroll.view`, which every seeded role holds, so company payroll totals were effectively ungated

- **Module / feature:** Dashboard — HR dashboard payroll summary (REC-05).
- **Related modules:** Payroll (the disclosed figures), HR.
- **Category:** Authorization / information disclosure.
- **Affected roles:** any holder of `dashboard.hr.view`. The gate excluded nobody who could reach the page.
- **Current Behavior:** `HrDashboardService::hr` attached `payroll_summary` behind `$user->hasPermission('payroll.view')`, and `hrPayrollSummary()` returns the latest payroll period with its status, the employee count on that run, its **net-pay total**, and the count of pending salary adjustments.
- **Problem:** `payroll.view` is granted by `RolePermissionSeeder::selfService()` (`api/database/seeders/RolePermissionSeeder.php:735`), whose own comment reads "Payslips → /payroll (scoped to self)". It is the permission to read **your own payslip**, and every one of the thirteen seeded roles holds it. The company's aggregate net pay was therefore gated on a permission that excluded no reader of the page. The gate read as deliberate — it split the cache key by the capability — which is what made it worse than an absent check.
- **Real-world scenario:** Any role granted the HR dashboard for its headcount and leave panels also reads the total net pay of the current payroll run. A ₱-material company-wide figure, disclosed by a check that looks like it is doing work.
- **Root Cause:** A permission chosen by name rather than by what it grants. `payroll.view` and `payroll.periods.view` read as a pair where the first is the weaker of two payroll reads; in this catalogue the first is a self-service grant and the second is the payroll-run read.
- **Recommended Improvement:** Gate on `payroll.periods.view` — held by `hr_officer`, `finance_officer` and `system_admin` only, and already the permission the `payroll.upcoming` widget uses over the same table. Now at `HrDashboardService.php:47`, with the cache key split by the corrected capability.
- **Ideal Process:** When a panel is gated, the grant is checked against the seeder to confirm it excludes somebody. A gate that excludes nobody is a comment, not a control.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** None beyond the HR dashboard. The permission catalogue is unchanged.
- **Evidence:** `api/app/Modules/Dashboard/Services/HrDashboardService.php:44-47,83`; `api/database/seeders/RolePermissionSeeder.php:735` for `selfService()`; every seeded role's grant of `payroll.view` confirmed against the live `role_permissions` table. Regression proof: `DashboardPanelGateTest::test_the_payroll_panel_needs_the_payroll_run_read_not_the_own_payslip_read` and `::test_the_payroll_panel_arrives_with_the_payroll_run_read` — a viewer holding `payroll.view` and the HR reads but not `payroll.periods.view` receives no `payroll_summary`, and `hr_officer` still does.
- **Priority:** P1.
- **Impact:** Company payroll net-pay total and run status readable by every role that could open the HR dashboard.
- **Complexity:** S.
- **Status note:** Registered and fixed 2026-08-18 (`d393d939`).

### F-050 — Deliveries had `create` and `confirm` permissions but no read of their own, so the warehouse's delivery panels were stripped at render while its default layout claimed them

- **Module / feature:** SupplyChain — delivery reads; Dashboard — warehouse dashboard and widget registry.
- **Related modules:** Inventory (the warehouse role), CRM (customer names on the outgoing queue).
- **Category:** Authorization granularity / interface honesty.
- **Affected roles:** `warehouse_staff`.
- **Current Behavior:** The `supply_chain` permission bucket carried `supply_chain.deliveries.create` and `supply_chain.deliveries.confirm` but no `.view`. The only delivery read was `supply_chain.view`, which also carries shipments, fleet and import/customs documents. The `supply.delivery_schedule` and `supply.overdue_deliveries` widgets and the warehouse dashboard's `outgoing_queue` panel were all gated on it, and `warehouse_staff` does not hold it — while `DashboardRoleLayoutSeeder` listed `supply.delivery_schedule` in that role's default layout.
- **Problem:** Two failures at once. The role could not be shown which trucks leave today without being granted the whole import/export desk, so it was shown nothing: `DashboardLayoutService::hydrateVisibleLayout` strips a widget whose permission the viewer lacks, silently and by design. And the default layout asserted the tile was there, so the seeded configuration and the rendered dashboard disagreed with no error anywhere. The strip makes a leaky default *safe*, which is exactly why it was invisible.
- **Real-world scenario:** Warehouse staff stage outbound loads. Their dashboard is configured to show the day's delivery schedule and never has, and nothing in the product reports why. The operator concludes the dashboard is broken; the administrator sees a correct-looking seeder.
- **Root Cause:** A permission catalogue with write verbs and no matching read verb, forcing every reader onto a module-wide grant. The dashboard defaults were then written against the intent rather than against the grant.
- **Recommended Improvement:** Add `supply_chain.deliveries.view` as the read counterpart, grant it to `warehouse_staff`, and re-gate the two widgets and the panel onto it. Grant it **also** to `purchasing_officer` and `impex_officer`, who read deliveries today through `supply_chain.view` and would otherwise have silently lost tiles they already had. Delivery read routes accept **either** slug (`permission_any`), so a custom role holding only `supply_chain.view` keeps the list; the SPA route guard and sidebar item accept either for the same reason — a tile whose "Open →" is denied to the role the tile is for is worse than no tile.
- **Ideal Process:** Every write verb in the catalogue has a read verb at the same granularity. A role default is validated against grants, not intent, and a stripped widget is a test failure rather than a silence.
- **New Feature/Module Required:** No. One permission slug.
- **Cross-Module Impact:** `supply_chain` catalogue, three role grants, two widget rows, one dashboard panel, one role default layout, three API read routes, two SPA route guards, one sidebar item.
- **Evidence:** `api/database/seeders/RolePermissionSeeder.php:225-236` for the bucket; `api/app/Modules/Dashboard/Services/WarehouseDashboardService.php:61`; `api/app/Modules/SupplyChain/routes.php:87-99`; `api/app/Modules/Dashboard/Services/DashboardLayoutService.php:268-303` for the strip. Regression proof: `DeliveryReadPermissionTest` — **13 tests / 22 assertions**, pinning both halves: the warehouse gains the list endpoint, the widget, the default tile and the panel, and gains **nothing else** — it still holds no `supply_chain.view`, `.shipments.manage`, `.fleet.manage` or `.deliveries.create`. A separate case asserts the broad module read still opens the delivery list on its own. `WidgetSeedIntegrityTest::test_no_role_default_contains_a_widget_that_role_cannot_see` prevents the recurrence class.
- **Priority:** P3.
- **Impact:** A configured dashboard tile never rendered for the role it was configured for, with no diagnostic. Correcting it required a permission that did not exist.
- **Complexity:** S.
- **Status note:** Registered and fixed 2026-08-18 (`1e214504`). Access widened deliberately and with the operator's confirmation; the narrow slug was chosen over granting `supply_chain.view` so the floor does not receive shipments, fleet and customs documents.

### F-051 — `ppc_head` could not read the production schedule it owns, because the permission gating the scheduler lives in another module's bucket

- **Module / feature:** MRP — scheduler endpoints; Production — the `production.schedule.view` permission.
- **Related modules:** Dashboard (the PPC dashboard's Gantt widget).
- **Category:** Authorization completeness.
- **Affected roles:** `ppc_head`.
- **Current Behavior:** `GET /api/v1/mrp/scheduler/snapshot` and `/options` are gated on `permission:production.schedule.view` (`api/app/Modules/MRP/routes.php:77-78`). That slug is declared in the **`production`** bucket of the permission catalogue (`api/database/seeders/RolePermissionSeeder.php:257`), while `ppc_head`'s grants are assembled from `$this->module('mrp')` plus an explicit list of `production.*` slugs that did not include it.
- **Problem:** `ppc_head` is described in its own seeder entry as the role that "owns the schedule and BOMs", and CLAUDE.md names PPC as the owner of the production schedule. It could not read it. The `production.gantt_mini` widget is gated on the same slug, so the Gantt tile in `ppc_head`'s default dashboard layout was stripped at render — the same silent-strip mechanism as F-050, reached from the opposite direction.
- **Real-world scenario:** The PPC head opens the dashboard the system routes them to and the production schedule tile is absent. The scheduler page it links to refuses them. The role exists to author that schedule.
- **Root Cause:** A permission whose bucket does not match the module it gates. `$this->module('mrp')` cannot pick up a slug filed under `production`, so a role assembled by module inherits an incomplete set, and nothing checks that a routed permission is reachable by the role that owns the route.
- **Recommended Improvement:** Grant `production.schedule.view` to `ppc_head` explicitly (`RolePermissionSeeder.php:572`), with the bucket-boundary reason recorded inline so it is not removed as redundant. The general fix — reconciling permission buckets with the modules whose routes consume them — is not attempted here and is noted as residual.
- **Ideal Process:** A permission is declared in the bucket of the module whose routes enforce it. Where it cannot be, the role assembled by module lists it explicitly and says why.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** One role grant. The `production.gantt_mini` widget begins rendering for `ppc_head`.
- **Evidence:** `api/app/Modules/MRP/routes.php:77-78`; `api/database/seeders/RolePermissionSeeder.php:257,572`; live confirmation after re-seed that `ppc_head` holds the slug and that its default layout's Gantt tile survives the render strip. Regression proof: `WidgetSeedIntegrityTest::test_no_role_default_contains_a_widget_that_role_cannot_see`, which failed on this exact pairing before the grant landed and is what surfaced it.
- **Priority:** P2.
- **Impact:** The role that owns production scheduling could not read the scheduler, and its dashboard silently omitted the schedule.
- **Complexity:** S.
- **Status note:** Registered and fixed 2026-08-18 (`087fc1f0`). Residual, deliberately out of scope: the general bucket/module mismatch that allowed it.

### F-052 — The RBAC browser audit asserted that denied pages display the word "Forbidden", so it reported thirty correctly-denied pages as accessible and demanded the information leak the UI had removed

- **Module / feature:** Verification tooling — `scripts/role-permission-audit.js`.
- **Related modules:** every module with a permission-guarded SPA route.
- **Category:** Detection / verification integrity.
- **Affected roles:** none at runtime. All contributors relying on the gate.
- **Current Behavior:** The audit decided whether a surface was denied by testing the rendered page body against `/\bForbidden\b/i`, then failed the run when a route the role should not reach did **not** contain that word. `PermissionGuard` renders `NotFoundState` for a denied route (`spa/src/components/guards/PermissionGuard.tsx:15-20`), deliberately, so the UI never confirms that a route or record the user cannot read exists.
- **Problem:** The assertion had inverted itself. Thirty correctly-denied surfaces across ten roles reported `was accessible but should be Forbidden`, and satisfying the audit would have required restoring the disclosure the guard exists to prevent. This is the same defect commit `68c2a007` fixed across the e2e specs on 2026-08-17; this script was missed in that sweep and is not run by CI, so nothing surfaced it. Worse than noise: the thirty false positives **masked a real defect** (F-053), which appeared only once the detection was corrected.
- **Real-world scenario:** A contributor runs the RBAC audit before a permission change, sees thirty failures unrelated to their work, and stops running it. The audit is the only automated check that every sidebar link a role sees actually opens for that role.
- **Root Cause:** A test coupled to denial *copy* rather than denial *behaviour*, and never re-run after the copy deliberately changed.
- **Recommended Improvement:** Recognise denial by the not-found copy and assert it in **both** directions — the denied page must look not-found, and no page may name its own denial. Verified against the running application before changing the assertion, because the alternative reading was a genuine leak: `employee` at `/payroll/periods` and `/hr/loans`, and `qc_inspector` at `/payroll/statutory`, each render the not-found state, carry no page-specific data, and fire no data request. Denied, not accessible. Two further corrections were needed after the first corrected run: the leak matcher must **exclude** the `api/client.ts` 403 toast, which is legitimate action feedback and which react-hot-toast keeps mounted across SPA navigations — so one unsolicited 403 was being attributed to the next several routes visited, inventing eleven failures from one cause; and `/quality/documents` was expected for two roles while existing nowhere in `spa/src`, an expectation that could never be met.
- **Ideal Process:** A test asserts the behaviour, not the wording. When denial copy changes deliberately, every assertion coupled to it is found and re-pointed in the same change. Audits that are not in CI are run before and after any permission change.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** The audit now covers 383 checks across 13 roles meaningfully.
- **Evidence:** `scripts/role-permission-audit.js:23-24` (the corrected matchers, with the toast exclusion reasoned inline), `:126-127,145-152`; `spa/src/components/guards/PermissionGuard.tsx:15-20`; commit `68c2a007` for the same defect in the e2e specs. Regression proof: `npm run audit:role-permissions` — **383 checks across 13 roles, 0 failures**, from 30. Live denial behaviour probed on three role/route pairs before the change, as described above.
- **Priority:** P2.
- **Impact:** The repository's only automated per-role route-reachability gate had been failing for every contributor since the denial copy changed, and its failures concealed a real authorization defect.
- **Complexity:** S.
- **Status note:** Registered and fixed 2026-08-19 (`410552a9`).

### F-053 — The employees page fetches the employee list unconditionally, so a salary-adjustment checker is refused on arrival at its own approval queue

- **Module / feature:** HR — `/hr/employees`, which is also the salary-adjustments queue.
- **Related modules:** Dashboard (the sidebar item), Payroll (the adjustment chain).
- **Category:** Authorization / interface correctness.
- **Affected roles:** `production_manager`, and any role holding `hr.salary_adjustments.view` without `hr.employees.view`.
- **Current Behavior:** `/hr/salary-adjustments` was scope-cut on 2026-08-08 and folded into `/hr/employees` as a tab, so the sidebar item is gated on either grant (`anyPermissions: ['hr.employees.view', 'hr.salary_adjustments.view']`). The page already accounted for the asymmetry: it lands a checker on the adjustments view, and gates the department tree and the status-count tiles on `canViewEmployees`. The employee **list** query and its filter **options** query carried no such gate.
- **Problem:** `production_manager` is the step-1 checker on the salary-adjustment chain per REC-03 and holds `hr.salary_adjustments.view` without `hr.employees.view`. Arriving at its own approval queue fired `GET /api/v1/hr/employees/options` and the employee list, both refused with 403, and the axios interceptor greeted it with "You do not have permission to perform this action." The page it was sent to worked; it simply insulted the user on entry.
- **Real-world scenario:** The production manager follows the sidebar to review a salary adjustment awaiting their approval and is told they lack permission, on a page that then works. The natural conclusion is that the approval is not theirs.
- **Root Cause:** Two of four queries on a page with two audiences were written for one audience. The page's own comment names `production_manager` as the second audience, so the intent was documented and the implementation incomplete.
- **Recommended Improvement:** Give both queries the `enabled: canViewEmployees` gate their two siblings already had. The filter dropdowns they feed already fall back to `[]`.
- **Ideal Process:** On a page reachable by two grants, every request is gated by the grant that authorizes it, and the page is opened once as each audience before it ships.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** None. Four queries on one page now share one consistent gate.
- **Evidence:** `spa/src/pages/hr/employees/index.tsx:84-89` (the pre-existing comment naming the case), `:119-144` (the four queries, now uniformly gated); `spa/src/components/layout/Sidebar.tsx:533` for the either-grant nav gate; `api/database/seeders/RolePermissionSeeder.php` for `production_manager`'s REC-03 grants. Regression proof: `npm run audit:role-permissions` — the two failures at `visible sidebar route /hr/employees emitted 403 GET .../hr/employees/options` are gone, and the run is **0 failures across 383 checks**.
- **Priority:** P3.
- **Impact:** An approver was refused on arrival at the queue holding work assigned to it.
- **Complexity:** S.
- **Status note:** Registered and fixed 2026-08-19 (`410552a9`). Found only after F-052's detection defect was corrected.

### F-054 — A whitespace text node inside a table row made the RMA detail page emit an invalid-DOM warning, failing the dynamic-route audit

- **Module / feature:** ReturnManagement — `/return-management/:id`.
- **Related modules:** none.
- **Category:** Markup correctness / verification gate health.
- **Affected roles:** all roles reaching an RMA detail page.
- **Current Behavior:** Two `<Th>` elements sat on a single source line separated by three literal spaces. JSX strips whitespace containing a newline and preserves whitespace that does not, so those spaces became a text child of `<tr>`, and React emitted `validateDOMNesting(...): Whitespace text nodes cannot appear as a child of <tr>`.
- **Problem:** Invalid HTML, not merely untidy — the HTML parser relocates stray text out of a table row, so the browser's DOM does not match the authored markup. `audit:dynamic-routes` treats any browser error as a failure, so this single row was the audit's only failing route and left that gate red.
- **Real-world scenario:** A contributor runs the dynamic-route audit, sees one failure on a page they did not touch, and learns to accept a red gate.
- **Root Cause:** JSX whitespace semantics differing by whether the gap contains a newline, invisible in review.
- **Recommended Improvement:** Put each cell on its own line. Grepped for the shape (`</Th|Td>` followed by two or more spaces then another cell) across `spa/src`: no other row had adjacent cells on one line, so this was the only instance.
- **Ideal Process:** Browser-error gates stay green, so that the next real error is visible.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** None.
- **Evidence:** `spa/src/pages/return-management/detail.tsx:441`. Regression proof: `npm run audit:dynamic-routes` — **42 seeded dynamic URLs, 0 failures**, from 1.
- **Priority:** P4.
- **Impact:** One verification gate red, on a defect with no user-visible symptom beyond invalid DOM.
- **Complexity:** S.
- **Status note:** Registered and fixed 2026-08-19 (`047c8e50`).

### F-055 — Invoice, Bill and Journal Entry creation accepts no idempotency key, so a retried POST posts the amount twice

- **Module / feature:** Accounting — `POST /api/v1/invoices`, `/bills`, `/journal-entries`.
- **Related modules:** Purchasing (bills carry the three-way match), GL (every one of the three writes journal lines).
- **Category:** Financial correctness / request replay.
- **Affected roles:** `finance_officer`, `system_admin` — anyone who can create a money document.
- **Current Behavior:** All three `store()` actions call `$this->service->create($request->validated(), $request->user())` and return 201. No request-scoped key is read, stored, or compared. The system does have this protection elsewhere: `WorkOrderController` reads `X-Idempotency-Key` (`api/app/Modules/Production/Controllers/WorkOrderController.php:237`), and migrations `0466_add_work_order_output_idempotency`, `2026_08_11_140000_add_auto_payroll_idempotency_key` and `2026_08_13_212000_add_loan_payroll_payment_idempotency` back the same guard for production output, auto-created payroll periods and loan payroll deductions. Money documents are the omission, not the rule.
- **Problem:** A double-submitted form, a proxy retry, or a client that resends on timeout creates two invoices, two bills or two journal entries for one transaction. Because each posts GL lines, the ledger is wrong, not merely duplicated — and the AR/AP balance that every dashboard reads is wrong with it. Nothing downstream detects it: there is no unique constraint on `(customer_id, reference)` or equivalent to fall back on.
- **Real-world scenario:** Finance submits an invoice on a slow connection, the request times out client-side after the server has committed, the user clicks Save again. The customer is billed twice and the duplicate is found at month-end reconciliation, if at all.
- **Root Cause:** The idempotency convention was introduced per-endpoint as each replay bug was found, rather than as a shared concern. There is no `IdempotencyService` and no middleware, so a new money endpoint gets the guard only if its author remembers to hand-roll one.
- **Recommended Improvement:** One `App\Common\Services\IdempotencyService` keyed on `(user_id, route, Idempotency-Key)` storing the first response, applied to the three money-document creates — then to any future endpoint that mints a financial record. Prefer a middleware over three call sites, so the next endpoint inherits it. The `create()` path in all three services already runs inside `DB::transaction()`, so the key insert belongs in the same transaction as the document.
- **Ideal Process:** A retried financial POST returns the original 201 and its original body, and creates nothing.
- **New Feature/Module Required:** No — a common service plus a table.
- **Cross-Module Impact:** The shared service, once it exists, should absorb the three existing hand-rolled guards (WO output, auto payroll period, loan payroll payment) rather than sit beside them.
- **Evidence:** `api/app/Modules/Accounting/Controllers/InvoiceController.php:42-50`, `BillController.php:43-57`, `JournalEntryController.php:44-56` — no key read in any. Contrast `api/app/Modules/Production/Controllers/WorkOrderController.php:237`. No regression proof: **this finding is open and unfixed.** Its acceptance gate is therefore `external_evidence` with a null command, the same shape F-030 carries. That shape used to be pinned to F-030's id in `scripts/verify-audit-acceptance-manifest.mjs`; it is now keyed on the lifecycle status, so omitting a gate command requires declaring the finding `open` — a `verified` row still cannot omit one.
- **Priority:** P2.
- **Impact:** Duplicate financial documents and duplicate GL postings from a retry the client cannot know succeeded.
- **Complexity:** M.
- **Status note:** Registered 2026-08-20, **open**. Provenance: an implementation existed on branch `feat/OGAMI-104-idempotency` (`27a2dcad`, 2026-06-19) — an `IdempotencyService` plus `MoneyDocumentIdempotencyTest`, +396/-85 across 8 files — which was never merged. That branch was deleted during pre-redeploy cleanup on 2026-08-20 rather than rebased: all three services it modified were substantially rewritten in the two months since, so the diff no longer applied and re-implementing was judged cheaper than resolving it. The finding is recorded here so deleting the branch does not delete the knowledge. Recover the original with `git show 27a2dcad` while the reflog retains it.

---

## Verification state at close (2026-08-19)

| Gate | Result |
|---|---|
| `api` full suite | 1900 passed, 0 failed — on a dedicated `ogami_test_verify` database |
| `spa` vitest | 249 passed |
| `tsc --noEmit` | clean |
| `eslint` | clean on all touched files |
| `audit:rbac` | 0 referenced-but-unseeded; 6 seeded-without-reference, all pre-existing |
| `audit:role-permissions` | 383 checks / 13 roles / 0 failures |
| `audit:role-dashboards` | 18 checks / 9 roles × 2 browsers / 0 failures |
| `audit:panel-gates` | 5 checks / 0 failures |
| `audit:dynamic-routes` | 42 URLs / 0 failures |
| `audit:api-routes` | 821 SPA requests matched; 27 classified in the manifest |
| `audit:tokens` | clean, 767 files |

**Not covered by this register.** The Playwright e2e suite was under active
refactor by another agent throughout (all twelve specs plus a new
`e2e/fixtures.ts`), and was deliberately neither run nor modified here: a suite
mid-refactor reports its author's work in progress, not this register's subject.
Its eight pre-existing failures, recorded in commit `68c2a007` as "a separate
cleanup", remain that agent's.
