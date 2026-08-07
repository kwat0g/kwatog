# OGAMI ERP — Scope Cut Audit (Defense Simplification)

> **Goal:** Identify low-value features safe to remove. Thesis defense in ~2 months;
> simpler surface = clearer story. Cut anything not serving: (1) the 3 business
> chains, (2) ADV1-12 requirements, (3) IATF 16949 traceability proof.

**Audit date:** 2026-08-07  
**Method:** Cross-reference demo script, defense traceability matrix, inbound coupling,
LOC weight, test coverage, seeder mentions.

---

## Protected scope (NEVER cut)

### Mandatory (ADV + 3 chains)
- **ADV1** — Payroll disbursement proof (chain 3 finale)
- **ADV3** — Batch/lot traceability (IATF flagship, chain 1 core)
- **ADV4** — Dynamic RBAC
- **ADV5** — Procurement + 3-way match (chain 2 core)
- **ADV6** — Purchase Request automation
- **ADV7** — Proof of delivery (chain 1 delivery gate)
- **ADV8** — Warehouse Management (bin-level, stock count, MRB quarantine)
- **ADV9** — **Budgeting overview + enforcement** (allocation + critical %, wired into PR/PO)
- **ADV10** — B2B portals (supplier + customer, separate guards)
- **ADV11** — **Forecasting** (demand, stock-out projection, MRP feed toggle)
- **ADV12** — Return Management (RMA, disposition → credit note)

### Supporting (woven through chains or IATF touchpoints)
- **Quality** — inspection specs, inspections, NCRs, CoC (4 IATF touchpoints)
- **Maintenance** — mold shot tracking, preventive schedules (production continuity)
- **Inventory** — GRN, material issue, stock levels, movements (chain 1+2 backbone)
- **Production** — work orders, output, routings, Gantt (chain 1 core)
- **MRP** — plans, BOMs, machines, molds (chain 1 planning)
- **CRM** — sales orders, customers, products, price agreements, complaints/8D (chain 1 start)
- **Purchasing** — PRs, POs, approved suppliers (chain 2 core)
- **Supply Chain** — shipments, deliveries, fleet (chain 1+2 logistics)
- **Accounting** — COA, journal entries, invoices (AR), bills (AP), **credit notes**, vendors, customers, financial statements (chain finale, all 3)
- **Payroll** — periods, statutory exports, de minimis (chain 3 core)
- **HR** — employees, departments, positions, separation/clearance (chain 3 core)
- **Attendance** — DTR, shifts, OT approval (chain 3 payroll input)
- **Leave** — requests, balances, approval (chain 3 payroll input)
- **Loans** — company loan + cash advance (chain 3 payroll deduction)
- **Dashboard** — live KPIs, chain stage breakdown, alerts (demo opening)
- **Admin** — users, roles, audit logs, SoD, settings, imports

---

## HIGH-CONFIDENCE CUTS (17 features, ~29.5k LOC)

### 1. Landing page (5,715 LOC) — marketing fluff
| | |
|---|---|
| Surface | `/` root, 27 route files, 3D hero (three.js `^0.184`), marquee, 48 files |
| Backend | `Modules/Landing` — public quote-request form, company-site content |
| Why cut | Zero chain/ADV/IATF relevance. 3D hero + marketing copy = demo distraction. Panel asks "who is this for?" |
| Keep | None — ERP auth redirects to `/dashboard` already |
| Risk | None (public-facing, decoupled). Removing three.js shaves bundle |

### 2. CRM sales pipeline — Leads + Opportunities (3,860 LOC)
| | |
|---|---|
| Surface | `/crm/leads/*`, `/crm/opportunities/*` (8 routes) |
| Backend | `LeadService`, `OpportunityService`, quote funnel |
| Why cut | Ogami doesn't hunt leads — a Japanese molder takes contracted orders. Sales funnel ≠ Order-to-Cash; panel can't tie it to the thesis. ~34 SPA files |
| Keep | None (SO is the chain-1 start; leads/opps are pre-sales noise) |
| Risk | Low — no inbound coupling from SO/WO/Invoice |

### 3. CRM Quotes (1,205 LOC, backend-only)
| | |
|---|---|
| Surface | No SPA page at all — API-only `Quote`/`QuoteService` |
| Why cut | Dead UI + unused endpoint weight. Quote→SO conversion never built |
| Keep | None |
| Risk | Zero (nothing imports it; SO has no `quote_id` FK) |

### 4. Sales Commissions (1,051 LOC)
| | |
|---|---|
| Surface | `/crm/commissions` (rates + earnings, 3 subpages) |
| Why cut | Commission calculation for sales reps — not a factory cost; not in any chain, not in ADV list |
| Keep | None |
| Risk | Zero (no coupling; MoldService hit was false positive — "commission a mold") |

### 5. HR Recruitment (3,278 LOC)
| | |
|---|---|
| Surface | `/hr/recruitment/*` (jobs, applicants, interviews) + `careers` public site |
| Why cut | Hiring funnel is pre-employment — Chain 3 starts at **hire**. ADP: Ogami uses direct hire + referral, no job boards. 24 files, biggest HR drag |
| Keep | None |
| Risk | Low (self-contained; dashboard headcount forecast is HR-side, not recruitment) |

### 6. HR Succession Planning (1,078 LOC)
| | |
|---|---|
| Surface | `/hr/succession-plans` (readiness, priority, status) |
| Why cut | Headcount replacement planning — strategic, not operational. Zero ADV/chain value |
| Keep | None |
| Risk | Low |

### 7. HR Performance Reviews (1,467 LOC)
| | |
|---|---|
| Surface | `/hr/performance-reviews` (reviews, goals) |
| Why cut | Semi-annual evaluation — not in Hire-to-Retire payroll flow. 11 files |
| Keep | None |
| Risk | Low |

### 8. Employee Skills module (616 LOC) — ~~CUT~~ **WITHDRAWN, KEEP**
| | |
|---|---|
| Surface | `/hr/skills` (skills catalog), Skills tab on employee detail |
| Original call | "Redundant with Training Matrix" — **wrong** |
| Why keep | Skills is not redundant with the Training Matrix, it *is* the matrix's data source. `TrainingMatrixController` cross-tabulates `Skill::query()` × `employee.skills` to produce trained/expired/gap cells — the IATF clause 7.2 competence evidence. Cutting Skills leaves `/hr/training/matrix` (live, routed, in sidebar) an empty grid. The catalog CRUD is also the only way to create skills, so it is load-bearing for the demo too |
| Verified | `api/app/Modules/HR/Controllers/TrainingMatrixController.php:10,39,52-63,92-100` |
| Separate finding | `skills` and `employee_skills` are both **0 rows** — the matrix renders blank in a demo today. Seeding gap, not a scope problem. Worth adding demo rows before defense |

### 9. SPC — PARTIAL CUT (control charts out, Cp/Cpk kept) ✅ DONE
| | |
|---|---|
| Surface | `/quality/spc`, `/quality/spc/charts/:id` (charts) · Cp/Cpk panel on inspection-spec editor + `/quality/capability-study` |
| Why cut | IATF *says* SPC is optional — traceability + inspections + NCRs are the mandatory proof. The chart half was a **second, parallel detector** for a signal the inspection path already raises: `InspectionService::recordMeasurements()` auto-evaluates every measurement against tolerance and opens an NCR on failure |
| Evidence | `spc_control_charts` = **1 row**, `spc_data_points` = **0**, `spc_alerts` = **0**. No chart ever reached the 20-point minimum its own policy required to compute limits, so no limits were ever calculated. `quality.spc.view` was **already revoked** from every role — all 3 chart pages were unreachable |
| Kept | `SpcService::compute()` / `computeForSpec()` / `computeCapabilityStudy()` / `capabilityThresholds()` — they read `inspection_measurements` directly, need no chart row, and back two live screens. New `CapabilityController` serves the 2 surviving endpoints |
| Removed | `SpcService` trimmed 436 → 217 lines; chart controller/models/resources/requests/listeners, 3 SPA pages, `spcApi`, 3 tables (migration 0460), 7 chart-only settings (0461) + their validation rules |
| Verified | `SpcServiceTest` 9/9 pass (Cp/Cpk math untouched) · quality suite 103/103 · `tsc --noEmit` clean · 903 routes register |
| Risk | Low — no inbound refs; capability seam is a pure read over kept inspection data |

### 10. COPQ (1,885 LOC, 17 files)
| | |
|---|---|
| Surface | `/quality/copq` (cost of poor quality) + dashboard widget |
| Why cut | Cost quantification of defects — nice-to-have. NCR Pareto already shows the same failures |
| Keep | None (Pareto from NCR data remains on Quality dashboard) |
| Risk | Low (dashboard coupling = one widget, removable) |

### 11. QMS Document Management (786 LOC)
| | |
|---|---|
| Surface | `/quality/documents` (documents, review/approval, acknowledgments) |
| Why cut | IATF wants *controlled documents* — but the mandatory proof is traceability + inspections, not a document DMS. 6 files |
| Keep | None |
| Risk | Low |

### 12. FX Rates + JP Parent Pack (615 LOC)
| | |
|---|---|
| Surface | `/accounting/fx-rates`, `/accounting/parent-pack` (JPY parent translation) |
| Why cut | Ogami is **₱-only** (CLAUDE.md: "Currency: Philippine Peso only"). Multi-currency + JPY parent pack is Japanese-accounting fluff the panel can't ask about |
| Keep | None (trial-balance FX variants removed with it) |
| Risk | Low (7 files, self-contained) |

### 13. Opening Balances (738 LOC)
| | |
|---|---|
| Surface | `/accounting/opening-balances` (GL + stock TB reconciliation) |
| Why cut | Go-live transition tool — the demo DB doesn't need it; statements render from live data. 6 files |
| Keep | None (TB/statements stay) |
| Risk | Low (demo runs fresh-seeded) |

### 14. Accounting Periods close/lock — ~~CUT~~ **KEEP** (verdict reversed 2026-08-07)
| | |
|---|---|
| Surface | `/accounting/periods` (close/reopen fiscal periods) |
| Original call | Cut — "audit-close ceremony, statements don't depend on it" |
| Why reversed | **Wrong.** `AccountingPeriodService::assertPostingAllowed()` is called on 6 live GL-posting paths: `JournalEntryService` (create + post), `InvoiceService`, `BillService`, `CreditNoteService`, `PayrollGlPostingService`. It is an enforced internal control that blocks backdated posting into a closed month — not ceremony |
| Cost of cutting | Would require stripping the guard out of 5 **kept** services, removing a control. Negative value, high risk |
| Defense value | Easy to explain in one line ("you cannot post to a closed month"), and a recognised accounting control a panel will respect |
| Note | CLAUDE.md's NOT BUILDING list says "❌ fiscal period locking". The feature exists, is enforced and is tested — the **spec line is stale**, not the code. Recommend updating CLAUDE.md rather than deleting the control |

### 15. Budget Transfers (472 LOC)
| | |
|---|---|
| Surface | `/budgeting/transfers` |
| Why cut | Reallocation between budget lines — a controller's chore. ADV9 needs **overview + enforcement** (kept) |
| Keep | Budget overview, budget-vs-actual, **PR/PO enforcement** (the panel demo) |
| Risk | Low |

### 16. Budget Revisions (79 LOC, 2 files)
| | |
|---|---|
| Surface | L-26 revision approval flow |
| Why cut | Tiny, adds approval-branch complexity. Budget close/reopen already exists |
| Keep | None |
| Risk | Zero |

### 17. Edge / IoT device sync (1,508 LOC, 27 files)
| | |
|---|---|
| Surface | No SPA UI. Device registration, scan, output, health — API only |
| Why cut | Factory-floor IoT ingestion. No panel demo, no chain/ADV link. The `EdgeSystemUserResolver` is reused by B2B — **keep that one class** |
| Keep | `EdgeSystemUserResolver` (B2B portal impersonation) |
| Risk | Medium if resolver moves — otherwise the whole module is disposable |

---

## REVIEW BEFORE CUTTING (7 features — real value, verify)

| # | Feature | LOC | The case for keeping |
|---|---|---|---|
| A | Budgeting overview (ADV9) | ~1k | **KEEP** — the demo's 98%-critical showpiece; PR/PO enforcement wired. Only cut transfers/revisions |
| B | Forecasting (ADV11) | ~1.5k | **KEEP** — adviser-required. But trim: `stock-out` + `accuracy` pages are bonus weight — confirm they're demo-critical before touching |
| C | Fixed Assets + Depreciation | 2,458 | Keep module; check `admin/depreciation` page + cron vs demo needs |
| D | Fleet (supply-chain) | small | Keep — ADV2 SCM separation + delivery logistics |
| E | Multi-currency | 615 | Cut (see #12) — contradicting "₱-only" |
| F | QMS Documents | 786 | Lean cut (see #11) |
| G | Dashboard variants | 3,935 | Keep scorecard + finance; others are cheap page variants |

---

## ALREADY EXPLAINED (not bloat)

- **Excess sidebar items** (`Approvals`, `Notifications`, `Action Center`, `Exceptions`, `Chains`, `KPI Scorecard`, `Sessions`, `SoD`, `Operations Health`) — all small pages (<350 LOC each) or demo props. Keep.
- **Dashboards** — one per role is surface area, but each is a lightweight query page. Keep; they *look* impressive to panel.
- **Warehouse Scanner / Warehouse Map / Stock Count / Transfer Orders / Picking** — ADV8 requirement. Keep.
- **Return Management + Credit Notes** — ADV12 + REC-13, wired into RMA. Keep.
- **Self-service + portal PWAs** — ADV10. Keep.

---

## REMOVAL ORDER (safest first)

1. **Phase 1 — dead/free:** Quotes (no UI), Budget Revisions, QMS Documents (no demo refs). ~~Employee Skills~~ withdrawn — see §8, it backs the IATF training matrix
2. **Phase 2 — zero-coupling features:** Commissions, Succession, Performance Reviews, Leads/Opportunities, Opening Balances, FX Rates/Parent Pack. ~~Accounting Periods~~ withdrawn — see §14, `assertPostingAllowed()` guards 6 live GL-posting paths
3. **Phase 3 — heavy but standalone:** SPC, COPQ (needs dashboard widget detach), Landing page (needs root redirect), Recruitment (+ careers site)
4. **Phase 4 — surgical:** Edge module (keep `EdgeSystemUserResolver`), Budget Transfers (keep overview/enforcement)

## REMOVAL METHOD (per feature)

1. Delete SPA route group + page files; remove sidebar entries
2. Delete controllers/services/models/resources/routes; remove `use` imports
3. Delete migrations ONLY if table is unused elsewhere (check FKs first — e.g. `credit_notes` stays, `fx_rates` goes)
4. Remove permissions from `RolePermissionSeeder` + menu seeders (check `feature` toggle keys)
5. Remove seeder references (ComprehensiveDemoSeeder, GoldenPathDemoSeeder, KpiDefinitionSeeder)
6. Remove tests for deleted features; re-run full suite (1,242 tests baseline)
7. Update `docs/` — DESIGN-SYSTEM, TASKS, SCHEMA, SEEDS, USER-MANUAL, DEMO-SCRIPT (keep only if needed)

**Verification:** `make test` green, `npm run build` green, demo seeder runs, no orphan `import` errors.

---

## NET RESULT

- **~29.5k LOC removed** (backend + SPA)
- **Sidebar:** ~110 → ~75 items (cleaner panel walk-through)
- **Story:** "Three chains, quality woven through, 12 adviser items" — nothing left to explain
- **Risk:** all cuts are leaf-node features; every kept feature was checked for inbound coupling first

