# Execution Plan: Hub Restructuring + Self-Service + Forecasting Integration

**Status**: DRAFT v2 (user feedback incorporated)  
**Effort**: ~80–100h total  
**Dependencies**: Phase 1 → 2 → 3 → 4 (strict); tasks within a phase can be parallelized

---

## Current State Summary

### ✅ Completed

| Area | What was done |
|------|---------------|
| **Sidebar restructuring** | HR section links to standalone pages; Admin section has separate entries |
| **Route redirects** | `/payroll/hub → /payroll/periods`, `/hr/attendance/hub → /hr/attendance`, `/admin/users-roles → /admin/users` |
| **~27 backTo refs** | Updated payroll, attendance, leaves, admin pages to point to standalone page URLs |
| **ProfileDropdown** | Avatar-triggered menu in Topbar with self-service links + logout |
| **Self-service conversion** | Moved into AppLayout; SelfServiceLayout.tsx and BottomNav.tsx deleted |
| **Forecasting widgets** | StockOutPanel + DemandForecastPanel components created |
| **Dashboard integration** | Purchasing, Warehouse, PPC, Plant Manager dashboards got forecasting panels |

### 🐛 Known Issues

1. **Broken `/forecasting` link** in DemandForecastPanel (empty state + "View all" link route doesn't exist)
2. **Hub pages still alive** but currently unreachable via sidebar + backed by redirects instead of being the primary entry point
3. **DemandForecastPanel lacks route guard** — missing horizonDays consistency on warehouse dashboard
4. **Self-service pages** still have mobile-first layout artifacts from the old SelfServiceLayout
5. **No sidebar entry for forecasting** — super-admin/analyst can't navigate to it

---

## Design Decisions (Confirmed)

### 1️⃣ Hub Page Strategy — Keep & Enhance, Use as Landing Pages
Hub pages are NOT being deleted. Instead they will be **enhanced as landing/overview pages** for modules where putting every sub-page in the sidebar would create too many buttons.

**Principle**: The sidebar shows only PRIMARY module entry points. Secondary/nested features (overtime, shifts, holidays, gov tables, etc.) live inside the hub pages, accessible from the parent module's landing page.

**Revised sidebar structure**:

| Section | Sidebar Items | Links To |
|---------|---------------|----------|
| Overview | Dashboard, Approvals, Calendar | Standalone pages |
| HR | Employees, **Attendance Hub**, **Payroll Hub**, Loans | Hub pages for attendance/payroll; standalone for employees/loans |
| Admin | Users, Roles, Audit Logs, Settings | Standalone pages (these are all important enough) |

Each hub page acts as a dashboard for its module — showing KPIs, recent activity, and quick links to sub-pages.

### 2️⃣ Self-Service Polish — Full (24h)
All 11 self-service pages upgraded to full-width SPA standard: proper PageHeader breadcrumbs, standard grid layouts, consistent spacing, no mobile-only classes.

### 3️⃣ Forecasting — Full Implementation, No Mock Data
Every dashboard integration uses real backend API data. If the backend endpoint doesn't exist yet, it must be built first. No placeholder/mock/stub data.

---

## Phase 0: Revert & Realign Hub Architecture (Effort: 6–10h)

**Problem**: Phase 1 previously replaced hub links in the sidebar with standalone page links and added redirects from hub URLs to standalone pages. The user wants the OPPOSITE — hubs should be the sidebar entry points, with secondary features accessed from within the hub.

**Goal**: Undo the over-aggressive de-hubbing while keeping the legitimately standalone pages.

| ID | Task | File(s) | Done Criteria | Effort |
|----|------|---------|---------------|--------|
| 0.1 | Audit all sidebar entries that should be hubs vs standalone | `Sidebar.tsx` | Decision: HR sidebar = Employees + Attendance Hub + Payroll Hub + Loans; Admin = Users, Roles, Audit, Settings (standalone) | 1h |
| 0.2 | Restructure Sidebar — Attendance Hub entry (not standalone overtime/shifts/holidays) | `Sidebar.tsx` | Sidebar shows "Attendance & Leave" linking to `/hr/attendance/hub`; remove separate Overtime entry | 0.5h |
| 0.3 | Restructure Sidebar — Payroll Hub entry (not standalone pay periods/adjustments) | `Sidebar.tsx` | Sidebar shows "Payroll" linking to `/payroll/hub`; remove separate "Pay Periods" and "Pay Adj." | 0.5h |
| 0.4 | Restructure Sidebar — Admin keep standalone | `Sidebar.tsx` | Admin stays as Users, Roles, Audit Logs, Settings (all important enough for sidebar) | 0.5h |
| 0.5 | Remove hub → standalone redirects; restore hub routes as primary | `dashboardRoutes.tsx` | Hubs work as landing pages again, not redirects | 0.5h |
| 0.6 | Enhance Attendance Hub — make it a proper landing page with KPI cards, quick stats, deep links | `attendance/hub.tsx` | Shows attendance today summary + quick links to overtime, shifts, holidays, leaves management | 2h |
| 0.7 | Enhance Payroll Hub — add period summary, pending adjustment count, gov table status | `payroll/hub.tsx` | Shows period status summary + links to create/run payroll + highlighted pending actions | 2h |
| 0.8 | Update backTo references that changed to point to hubs | ~27 files | All breadcrumbs point to correct hub URLs | 1h |
| 0.9 | Verify nothing is broken | `npm run typecheck && npm run build` | Clean | 1h |

**Total Phase 0**: ~9h  
**Risk**: High — this is reverting recently completed work. Must be thorough.

---

## Phase 1: Bug Fixes (Effort: 4–6h)

Fix known issues.

| ID | Task | File(s) | Done Criteria | Effort |
|----|------|---------|---------------|--------|
| 1.1 | Fix broken `/forecasting` link → `/forecasting/demand` | `DemandForecastPanel.tsx` (lines 79, 173) | Empty state and "View all" link navigate to valid route | 0.5h |
| 1.2 | Verify horizonDays consistency across dashboards | `warehouse.tsx` | Warehouse uses same API call shape as purchasing/PPC | 0.5h |
| 1.3 | Add sidebar entry for forecasting (super-admin/analyst) | `Sidebar.tsx` | Entry in a suitable section with permission gate `forecasting.view` | 1h |
| 1.4 | Typecheck + lint | All | Clean | 2h |
| 1.5 | Code review | All | Sign-off | 0.5h |

**Total Phase 1**: ~4.5h

---

## Phase 2: Full Self-Service Polish (Effort: 18–24h)

The self-service pages were built for the old mobile-first SelfServiceLayout and now render inside the full AppLayout. They need layout upgrades.

**Design goals**:  
- Every page has a `<PageHeader title="…" backTo="/self-service" backLabel="Dashboard" />`  
- No `max-w-xs`, no `mx-auto`, no mobile-first container constraints  
- Use standard `<Panel>`, `<StatCard>`, `<Table>` components  
- Consistent breadcrumbs `< HR · Self-Service · Page Name >`  
- Responsive grid layouts using `grid-cols-1 lg:grid-cols-2 xl:grid-cols-3`

| ID | Task | File(s) | Effort |
|----|------|---------|--------|
| 2.1 | Self-service homepage — redesign as dashboard overview | `self-service/index.tsx` | 3h |
| 2.2 | DTR page — widen, PageHeader, full-width table | `self-service/dtr.tsx` | 2h |
| 2.3 | Leave request form — widen, PageHeader, full-width form | `self-service/leave.tsx` | 1.5h |
| 2.4 | Leave list — widen, PageHeader | `self-service/leaves.tsx` | 1.5h |
| 2.5 | Profile — clean up layout, PageHeader | `self-service/profile.tsx` | 3h |
| 2.6 | Loans — PageHeader, standardize | `self-service/loans.tsx` | 2h |
| 2.7 | Overtime — PageHeader, widen | `self-service/overtime.tsx` | 1.5h |
| 2.8 | Documents — PageHeader, widen table | `self-service/documents.tsx` | 1.5h |
| 2.9 | Payslips — PageHeader, standardize | `self-service/payslips.tsx` | 2h |
| 2.10 | Notif prefs — PageHeader | `self-service/notification-preferences.tsx` | 1h |
| 2.11 | Validate all self-service routes + ProfileDropdown links | All | 1h |
| 2.12 | Typecheck + lint | All | 2h |
| 2.13 | Code review | All | 1h |

**Total Phase 2**: ~23h

---

## Phase 3: Forecasting Enhancements (Effort: 20–26h)

Extend the existing forecasting integration with real data only.

| ID | Task | File(s) | Backend Needed? | Effort |
|----|------|---------|-----------------|--------|
| 3.1 | Backend audit — verify all current forecasting API endpoints work | API endpoints | Yes — verify | 2h |
| 3.2 | Create ForecastAccuracyPanel (real data only) | New component | No (reuses existing API) | 4h |
| 3.3 | Add ForecastAccuracyPanel to PPC dashboard | `dashboard/ppc.tsx` | No | 0.5h |
| 3.4 | Add ForecastAccuracyPanel to Plant Manager dashboard | `dashboard/plant-manager.tsx` | No | 0.5h |
| 3.5 | Enhance StockOutPanel with trend indicator | `StockOutPanel.tsx` | Maybe — needs historical stock data | 3h |
| 3.6 | Add "Configure forecasts" button linking to forecasting pages | All dashboard panels | No | 1h |
| 3.7 | Build headcount forecasting endpoint (HR) | API + HR dashboard | Yes — build endpoint | 4h |
| 3.8 | Integrate headcount forecasting into HR dashboard | `dashboard/hr.tsx` | Yes (3.7) | 1.5h |
| 3.9 | Build revenue forecasting endpoint (Finance) | API + Finance dashboard | Yes — build endpoint | 4h |
| 3.10 | Integrate revenue forecasting into Finance dashboard | `dashboard/finance.tsx` | Yes (3.9) | 1.5h |
| 3.11 | Build defect rate forecasting endpoint (Quality) | API + Quality dashboard | Yes — build endpoint | 4h |
| 3.12 | Integrate defect rate forecasting into Quality dashboard | `dashboard/quality.tsx` | Yes (3.11) | 1.5h |
| 3.13 | Performance audit — TanStack Query cache TTLs | All dashboard components | No | 1.5h |
| 3.14 | Typecheck + build | All | No | 3h |
| 3.15 | Code review | All | No | 1.5h |

**Total Phase 3**: ~27h  
**Note**: Tasks 3.7–3.12 have backend dependencies. Must be done together or deferred as a unit.

---

## Phase 4: Forecasting Sidebar Entry (Effort: 2–4h)

| ID | Task | File(s) | Done Criteria | Effort |
|----|------|---------|---------------|--------|
| 4.1 | Add `/forecasting` to Sidebar under an appropriate section | `Sidebar.tsx` | Entry with permission gate, proper icon | 0.5h |
| 4.2 | Verify route guards match sidebar permission | `advancedRoutes.tsx` | Consistency check | 1h |
| 4.3 | Typecheck + code review | All | Clean | 1h |

**Total Phase 4**: ~2.5h

---

## Dependency Graph

```
Phase 0 (Revert hub changes) ─────────────────────────────┐
                                                           │
Phase 1 (Bug fixes) ──────────────────────────────────────┤
                                                           ├──► Phase 2 (Self-service) ──► Phase 4 (Sidebar)
                                                           │
Phase 3 (Forecasting — includes backend work) ────────────┘
```

**Critical path**: Phase 0 → Phase 1 → Phase 2 → Phase 4 = ~39h  
Phase 3 runs in parallel with Phase 2 (different file sets)  
Backend tasks (3.7, 3.9, 3.11) must be done first within Phase 3

---

## Resource Allocation

| Role | Effort | Notes |
|------|--------|-------|
| Frontend developer | 60–80h | Primary — all phases |
| Backend developer | 14h | Tasks 3.1, 3.7, 3.9, 3.11 |
| Code reviewer | ~10h | After each phase |

---

## Execution Order

1. **Phase 0** — Revert hub structure, restore hubs as primary sidebar entry points  
2. **Phase 1** — Quick bug fixes  
3. **Phase 2** — Self-service polish (can parallelize with Phase 3 backend work)  
4. **Phase 3** — Forecasting enhancements (backend then frontend)  
5. **Phase 4** — Forecasting sidebar entry

After every sub-phase: `npm run typecheck && npm run build`  
After every phase: full code review
