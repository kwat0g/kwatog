# OGAMI ERP — Whole-Frontend UI/UX Discovery & Baseline (Phase 0)

Read-only discovery. **No UI code was changed.** Everything below is derived from the
repository and from the running application; nothing is copied from the mission brief
without a citation, and every place the brief's assumptions disagree with the code is
recorded in [§ E, Discrepancies](#e-discrepancies--briefs-assumptions-vs-code).

- Branch at capture time: `feat/dss-w0-6-admin`
- Method: static inventory (`ls`, `grep -r`, the repo's own audit scripts) **plus** rendering
  the live app through a new login-and-screenshot harness (see § C).
- Artifacts: `docs/ui-ux/screenshots/baseline/<role>/*.png` (51 captures) and
  `docs/ui-ux/screenshots/baseline/<role>/report.json` (per-capture measurements).
- Harness: [`spa/scripts/ui-ux-baseline-capture.mjs`](../../spa/scripts/ui-ux-baseline-capture.mjs)

---

## A. UI inventory

### A.1 Scale (counted, not estimated)

| Measure | Count | How counted |
|---|---|---|
| SPA route paths (unique) | **356** | `grep -rho 'path="…"' spa/src/routes/*.tsx` |
| Route files | 24 (+4 test files) | `ls spa/src/routes` |
| Page components (`.tsx`) | **487** | `find spa/src/pages -name '*.tsx'` |
| Page module folders | 38 | `find spa/src/pages -maxdepth 1 -type d` |
| Shared component files | 182 | `find spa/src/components -name '*.tsx'` |
| Hooks | 35 | `find spa/src/hooks -name '*.ts*'` |
| Unit test files (SPA) | 177 | `find spa/src \( -name '*.test.ts' -o -name '*.test.tsx' \)` |
| Playwright e2e specs | 57 | `ls spa/e2e` |

### A.2 Routes by module

Counted with `grep -c 'path="'` per route file. Guard type is read from each file.

| Route file | Paths | Guard posture |
|---|---|---|
| `portalRoutes.tsx` | 41 | Portal sessions (supplier/customer), **outside** `AuthGuard` by design |
| `hrRoutes.tsx` | 36 | `PermissionGuard` per route |
| `accountingRoutes.tsx` | 32 | `PermissionGuard` per route |
| `inventoryRoutes.tsx` | 28 | `PermissionGuard` per route (+ `ModuleGuard`) |
| `dashboardRoutes.tsx` | 28 | `PermissionGuard` per route |
| `crmRoutes.tsx` | 23 | `PermissionGuard` per route |
| `adminRoutes.tsx` | 22 | `PermissionGuard` per route |
| `qualityRoutes.tsx` | 21 | `PermissionGuard` per route |
| `advancedRoutes.tsx` | 18 | `PermissionGuard` per route |
| `purchasingRoutes.tsx` | 17 | `PermissionGuard` per route |
| `selfServiceRoutes.tsx` | 16 | `PermissionGuard` / auth-only self-scoped |
| `mrpRoutes.tsx` | 15 | `PermissionGuard` per route |
| `productionRoutes.tsx` | 11 | `PermissionGuard` per route |
| `maintenanceRoutes.tsx` | 9 | `PermissionGuard` per route |
| `supplyChainRoutes.tsx` | 8 | `PermissionGuard` per route |
| `payrollRoutes.tsx` | 7 | `PermissionGuard` per route |
| `authRoutes.tsx` | 5 | `GuestGuard` / `AuthGuard` |
| `assetsRoutes.tsx` | 4 | `PermissionGuard` per route |
| `factoryRoutes.tsx` | 3 | `AuthGuard` + `FactoryFloorLayout` (floor palette, forced) |
| `driverRoutes.tsx` | 3 | `AuthGuard` + driver PWA |
| `careersRoutes.tsx` | 3 | Public |
| `legalRoutes.tsx` | 3 | Public |
| `maintenanceMobileRoutes.tsx` | 2 | `AuthGuard` + `MaintenanceMobileLayout` |
| `landingRoutes.tsx` | 1 | Public |

`<PermissionGuard>` appears **272** times across route files, gating on **139 distinct
permission slugs**.

### A.3 Route shells (not one shell)

Nine layouts exist, each with its own chrome: `AppLayout`, `AuthLayout`,
`SelfServiceLayout`, `FactoryFloorLayout`, `MaintenanceMobileLayout`, `PortalLayout`,
`SupplierPortalLayout`, `CustomerPortalLayout`. `TouchShell` selects the floor palette
by route (never user-selectable).

The desktop shell (`spa/src/layouts/AppLayout.tsx`) already provides: skip-to-content
link, `OfflineBanner`, `Topbar`, collapsible `Sidebar`, `RouteTransition`, a
**page-level** `ErrorBoundary` (a page crash no longer unmounts navigation), `Suspense`
skeleton, `DevErrorPanel`, lazy `KeyboardShortcutHelp`, `PageActionsProvider`,
`usePermissionSync`, `usePageFocus`, `useRecentPageTracker`, and a hard gate that holds
financial content until the functional currency loads.

### A.4 Modals, drawers, and overlays

| Pattern | Uses | Files |
|---|---|---|
| `<Modal>` | **212** | 94 |
| `<ConfirmDialog>` | **149** | — |
| `<BottomSheet>` | **3** | — |

`BottomSheet` is the mobile action pattern and is used in only three places while Modal
carries 212 uses — the sharpest structural gap between "desktop-first" and "phone-ready"
in the shared layer.

### A.5 Tables, forms, and charts

| Pattern | Count | Notes |
|---|---|---|
| `<DataTable>` | **119** | one shared table primitive |
| Files importing `react-hook-form`'s `useForm` | **94** | with `zod` resolvers |
| Files importing `recharts` | 10 | all charts go through 6 shared primitives |

### A.6 Status, feedback, and workflow surfaces

- Status → colour is centralized in `spa/src/lib/statusVariants.ts` (typed `Record<Status, ChipVariant>`,
  so adding an enum member is a compile error). It exists **because** the floor PWAs had
  drifted from the desktop tables — same record, different colour per device.
- Feedback primitives present: `EmptyState`, `ListEmptyState`, `QueryErrorState`,
  `Skeleton`, `Spinner`, `TopLoadingBar`, `ProgressBar`, `WidgetErrorBoundary`,
  `OfflineBanner`, `PendingHint`, `DraftRestoreBanner`, `FormDraftBanner`,
  `FormErrorSummary`, `StickyActionBar`, `ReasonDialog`, `RowContextMenu`, `UndoToast`.
- Workflow/chain surfaces present: `ChainHeader`, `StageBreakdown`, `LinkedRecords`,
  `ActivityStream`, `useChainProgress`.
- `CommandPalette` (⌘K) exists, shares its "Go to" list with the sidebar via
  `isNavItemVisible`, and is fed by `useRecentPageTracker`.

### A.7 Forms: the "type a raw ID" question

The brief flags foreign-key fields where users type an ID. `SearchableSelect` exists in
the shared kit and `Select` has a test. A precise, mechanical audit of *every* FK input
has **not** yet been run — the probe for this is named in § C.4 rather than guessed at here.

---

## B. Foundation inventory

### B.1 Stack and libraries (`spa/package.json`)

React 18.3.1 · Vite 7.3.6 · TypeScript 5.6 · **Tailwind CSS 4.3** (`@tailwindcss/postcss`) ·
TanStack Query 5.59 + TanStack Table 8.20 · react-hook-form 7.53 + zod 3.23 ·
react-router-dom 7.18 · zustand 5.0-rc · axios 1.18 · recharts 2.15 · `@tabler/icons-react` 3.46 ·
date-fns 4.1 · react-hot-toast 2.4 · laravel-echo + pusher-js (Reverb) · leaflet/react-leaflet ·
qrcode · three + gsap + lenis (`@gsap/react`) · 3 fontsource families · clsx · Playwright, Vitest.

**Absent, deliberately or otherwise:** no component kit (Radix / MUI / shadcn), no second
chart library, no grid library, no `dnd-kit`, **no `i18n` package at all**
(`grep -rl 'i18next|useTranslation'` → 0 files), no `jspdf`/`html2canvas`/`file-saver`.

Reverb wiring is real: `useEcho`, `useNotificationRealtime`, `usePermissionSync`.
Functional currency is resolved at runtime (`lib/runtimeCurrency.ts`) from
`businessPolicies.functional_currency_code`, not hardcoded — and `AppLayout` refuses to
render financial routes until it lands.

### B.2 Design tokens — and how well they are honoured

`spa/src/styles/tokens.css` is the single source: **3 palettes** (`:root` light,
`[data-theme="dark"]`, `[data-theme="floor"]`) covering canvas/surface/elevated,
zebra/row-hover/thead, borders, text ramp, clay accent + hover + fg, link colours,
5 semantic triads, purple, ring + offset, warm shadows, 3 font families, 4 radii,
density (`--row-height`, `--hit-min`, `--font-size-body` — floor raises all three),
motion durations/easings, and blueprint/plant-map tokens.

Measured discipline:

| Check | Result |
|---|---|
| `npm run audit:tokens` (`check-token-discipline.mjs`) | **clean — 1043 files checked, exit 0** |
| Hardcoded 6-digit hex outside `tokens.css` | **19** occurrences in **3** files |
| Arbitrary Tailwind colour classes (`bg-[#…]`) | **0** |
| Inline `style={{ … }}` props | 95 |

This is the single most important Phase 0 finding: **the token layer is real and enforced
in CI.** Phase 3 is consolidation and enforcement, not foundation-building.

Related: `lib/contrast.ts` plus `styles/__tests__/palette-contrast.test.ts` gate palette
contrast; `docs/DESIGN-SYSTEM.md` documents the same system a human can read.

### B.3 Shared vs. duplicated components

`spa/src/components/ui/` holds **60** shared `.tsx` components (plus 6 colocated tests — 70 files in the directory): Button, LinkButton, Input, Textarea, Select,
**SearchableSelect**, Checkbox, Radio, Switch, CurrencyInput, MaskedInput, NumberInput
helpers, FileInput, Chip, Badge, Avatar, UserBadge, Tooltip, Modal, ConfirmDialog,
ReasonDialog, BottomSheet, Tabs, TabNavigation, SegmentedControl, DataTable,
DataTableToolbar, DataTablePagination, FilterBar, ArchiveFilter, RowContextMenu, Panel,
Card-adjacent surfaces, StatCard, EmptyState, ListEmptyState, QueryErrorState, Skeleton,
Spinner, ProgressBar, SpecToleranceBar, StickyActionBar, FormActions, FormErrorSummary,
FormDraftBanner, DraftRestoreBanner, PendingHint, OfflineBanner, WidgetErrorBoundary,
CopyButton, CommandPalette, KeyboardShortcutHelp, ActivityStream, LinkedRecords,
ChainHeader, StageBreakdown, table-cells.

**A shared kit exists and is used.** The duplication risk is therefore *not* "9 different
buttons" (the brief's hypothesis) but the three places where a *variant* is missing so
pages inline the difference — Modal-vs-BottomSheet (§ A.4), and the two
`docs/dashboards/phase0/E-frontend.md` gap classes (per-widget export, categorical chart
palette).

### B.4 Number, date, and locale formatting

`lib/money.ts`, `lib/formatNumber.ts`, `lib/formatDate.ts`, `lib/phFormat.ts`,
`lib/runtimeCurrency.ts`, `lib/workingDays.ts`, `lib/numberInput.ts`.
`Asia/Manila` appears **58** times (16 `timeZone` references). ₱ formatting is centralized
and gated, which means a "currency is missing/mis-rendered" defect is a one-file defect.

---

## C. Quantified baseline

### C.1 How the baseline was captured (and what it proves)

New harness `spa/scripts/ui-ux-baseline-capture.mjs`:

- signs in through the **real sign-in form** using seeded demo accounts
  (`DemoAccountSeeder`, password `password`) — HTTP-only cookie session, no token smuggling;
- visits each route at **375 / 768 / 1440** and writes a full-page PNG plus a JSON row;
- records per capture: HTTP status, wall time to `networkidle`, `h1` text, counts
  (buttons/links/inputs/tables/rows/dialogs/charts), a11y probes (interactive elements with
  no accessible name, unlabelled inputs, `<img>` without `alt`, positive `tabindex`),
  unintended-overflow probes, and console/page errors.

```
cd spa
node scripts/ui-ux-baseline-capture.mjs --role admin --label baseline
# roles: admin hr finance production ppc purchasing vp employee sales warehouse qc
#        maintenance impex depthead driver customer_service
```

**Origin is load-bearing.** The API pins the session with
`APP_URL=https://47.129.228.44` and `SANCTUM_STATEFUL_DOMAINS=47.129.228.44`. Signing in
from the nginx dev port (`http://127.0.0.1:8081`, which serves the SPA fine) returns
**HTTP 419 CSRF-token mismatch** on `POST /api/v1/auth/sign-in`. The harness therefore
defaults to the `APP_URL` origin and ignores the internal TLS certificate. Recorded as
**F-001** in `FINDINGS.md` — it is an environment/config defect that blocks any
127.0.0.1-origin browser test, not a UI bug.

### C.2 Baseline results — 51 captures, 4 roles, 3 viewports

Roles captured: `admin` (18), `qc` (12), `warehouse` (12), `employee` (9).
Routes and full per-capture data: `docs/ui-ux/screenshots/baseline/<role>/report.json`.

| Probe | Result |
|---|---|
| Horizontal **page** scroll | **0 / 51** |
| Positive `tabindex` | **0 / 51** |
| Interactive elements with no accessible name | **0** (measured from Chromium's accessibility tree) |
| Captures with unintended in-flow overflow | **4 / 51** (see the corrections below) |

> **Two numbers in the first draft of this section were wrong and have been withdrawn.**
> They were probe defects, not application defects, and both are documented in
> [`FINDINGS.md`](FINDINGS.md) → *Measurement corrections*:
>
> - **"20 unnamed interactive elements"** came from a hand-rolled name heuristic
>   (`innerText` excludes an `<img>` alt and `display:none` text, so a correct topbar link read
>   as unnamed). Chromium's own accessibility tree reports **0** on the same page and viewport.
> - **The overflow count** included deliberate overlays — `EmptyState` positions a 52–72px
>   `DatumMark` absolutely inside a 40px icon slot, so `scrollWidth > clientWidth` is by design.
>   Overflow is now measured against **in-flow** descendants only.
>
> The probe was fixed before any finding above was trusted, and every before/after comparison in
> `FINDINGS.md` was re-measured with the corrected probe in **both** the unfixed and fixed states.

**Real, reproducible defects found in the baseline:**

1. **Dashboard fixed-width text clipping.** `<span class="font-mono text-2xs uppercase
   shrink-0 mt-0.5 w-12">` measures **78px of content in a 48px box** with
   `overflow-x: visible` — **10 instances per dashboard capture**, on `/dashboard` for
   `admin` at all three widths (and visible in the QC/warehouse dashboards). A `w-12`
   container cannot hold a monospace label, so the text overflows its slot.
   Reproduce: `report.json → /dashboard @375 → overflowing[0..9]`.
2. **A progress track whose fill is wider than the track.** `div.h-2.5 bg-subtle
   rounded-full overflow-hidden` reports `602>301` (mobile), `1276>638` (tablet),
   `1080>540` (desktop) — consistently **≈2× its own width**. Same defect at every
   viewport on `qc` `/dashboard`.
3. **QC dashboard card wider than its column at 375px**:
   `div.bg-canvas border border-default rounded-md overflow-hidden` = `357>333`.
All three were fixed in Wave 1 PR 1 — measured **10 → 0** and **3 → 0 / 1 → 0** overflow,
with no regressions and unchanged data rows (`screenshots/before2` vs `screenshots/after`).

Items 1–3 are the same class of defect (a fixed-size slot that cannot hold its content),
which is exactly what "fix it at the shared-pattern level" is for.

### C.3 Existing automated quality infrastructure (reuse, don't rebuild)

| Command | What it covers | Status now |
|---|---|---|
| `npm run audit:tokens` | no colour outside `tokens.css` | **passes** (1043 files) |
| `npm run audit:rbac` | static RBAC audit | present |
| `npm run audit:role-permissions` | role↔permission drift | present |
| `npm run audit:dynamic-routes` | route reachability crawl | present |
| `npm run audit:dashboard-routes` | dashboard route parity | present |
| `npm run audit:dss-routes` | DSS route parity | present |
| `npm run audit:panel-gates` | panel gate browser check | present |
| `npm run audit:api-routes` | SPA↔API route audit | present |
| `npm test` / `npm run test:e2e` | Vitest 177 files / Playwright 57 specs | present |
| `scripts/defense-smoke-walk.js` | screenshot walk | present |

Playwright **Chromium 1243 and Firefox 1543 are installed** (`~/.cache/ms-playwright`), so
real rendering is available. Per `CLAUDE.md`, Lightpanda is the wrong engine here: this
work measures layout.

### C.4 Not yet measured — named so it is not mistaken for done

- **axe / Lighthouse**: not run. `@axe-core/playwright` is **not a dependency**; adding it
  is a dependency decision (§ Plan, Q3). The current a11y numbers are a hand-rolled probe,
  not WCAG coverage.
- **Bundle size / LCP / INP / CLS**: not measured. Vite `manualChunks` splits exist; a
  before/after budget needs a baseline `vite build` + `--report` run.
- **Visual review of the 51 PNGs by a human**: the images exist and are the evidence for
  the visual ranking; the automated probes above are how they were triaged. Rankings in
  `02-plan.md` that depend on pure visual judgement are marked as such.
- **Full per-role crawl**: 4 of 17 roles captured, and only their sidebar-default routes.
  The remaining roles and the long tail (356 paths) are the Phase 5 walk order.
- **Every-FK-is-a-SearchableSelect audit** (§ A.7).

---

## D. Users and context

### D.1 The roles are real and there are 17 of them

`api/database/seeders/RolePermissionSeeder.php` defines exactly **17** roles. `docs/CLAUDE.md`
says 14 — the seeder is authoritative (already tracked as F-067 in `docs/dashboards/FINDINGS.md`).

| Role | Device & situation | What the UI owes them |
|---|---|---|
| `system_admin` | Desktop, IT | Bypasses permission gates in UI and API; must not be shown business-approval affordances it cannot legitimately act on |
| `vice_president` | Desktop **and phone** — top step of every money chain, no escalation superior | One-handed approve/reject with the consequence stated; approval detail must be readable without pinch-zoom |
| `finance_officer` | Dense desktop tables | Compact density, tabular numerals, fast filter/sort, safe confirmations on money |
| `hr_officer` | Desktop, sensitive data (`encrypted` casts, masking) | Masking that degrades honestly; no accidental disclosure of SSS/PhilHealth/Pag-IBIG/TIN/bank |
| `production_manager` | Desktop + **sole factory-PWA holder** | Glanceable status; 48px floor rows; minimal typing |
| `ppc_head` | Desktop planning + Gantt | Dense schedule legibility; explicit "what's blocking" |
| `purchasing_officer` | Desktop, RFQ/PO | Comparison legibility (delivered cost), bulk line work |
| `warehouse_staff` | Shared shop-floor terminal / tablet, gloves, scanning | `--hit-min: 44px`, high contrast, scan-first entry, no tiny tap targets |
| `qc_inspector` | Tablet at the machine; pass/fail under time pressure | Big touch targets, unmistakable pass/fail, measurements entry that survives a fat finger |
| `maintenance_tech` / `maintenance_supervisor` | Phone/tablet, mobile layout | Mobile shell, bottom-sheet actions (currently only 3 BottomSheet uses) |
| `impex_officer` | Desktop, shipments/fleet | Document-heavy forms; cannot open the driver PWA |
| `department_head` | Desktop + phone, step-1 approver on several chains, dept-scoped | Department row scope must visibly match what they can act on |
| `sales_officer` / `customer_service_officer` | Desktop, SO / complaint lifecycles | Long-horizon case state (8D, RMA) legible over weeks |
| `employee` | Phone, self-service, **15-minute session timeout** | Must not lose work on timeout; payslip/leave paths usable on a phone |
| `driver` | Phone in a cab, proof photo upload | One screen, offline tolerance, photo capture |

Cross-cutting persona implication: **`employee` and `driver` are phone-first and have the
shortest session (15 min); `vice_president` approves from a phone; `warehouse_staff` and
`qc_inspector` use tablets/terminals.** The three-floor-palette design exists for them and
the `BottomSheet` count says the phone path is the thinnest part of the shared layer.

### D.2 Permission-UI accuracy

`usePermission()` (`can` / `canApprove` / `canAny` / `canAll` / `isAdmin`) is the single
frontend authority check, and the sidebar/command-palette share `isNavItemVisible` so both
surfaces show the same destinations. `docs/dashboards/phase0/A-roles-access.md` documents 12
backend↔frontend gating mismatches, **11 of which were resolved 2026-10-12**; the surviving
open item is M1 (finance page guard vs. data guard vs. widget permission are three different
answers). This is the machine-readable starting point for the brief's "permission-UI audit"
— it should be **continued, not restarted**.

---

## E. Discrepancies — the brief's assumptions vs. the code

Recorded per the brief's own "the code wins" rule.

| # | Brief says | Code says | Impact on the plan |
|---|---|---|---|
| E1 | "built quickly and working", "not all screens manually tested", implying an inconsistent, ad-hoc UI | Token discipline is **clean under CI** (1043 files), 3 documented palettes with a contrast test, **60** shared UI components, 177 unit + 57 e2e tests, 8 static audit scripts. A documented design system (`docs/DESIGN-SYSTEM.md`) and a pattern library (`docs/PATTERNS.md`, 56 KB) already exist | **Phase 1 is consolidation and enforcement, not a redesign.** Do not fork a second design language |
| E2 | "low-RAM (4 GB + swap)" | This machine: **32 GB total, 18 GB available**; the full docker stack (api, db, nginx, redis, reverb, spa, caddy) is already up | Sequential-agent and single-test-DB rules still hold (they are about correctness, not RAM). Browser work can run in parallel with modest care |
| E3 | Discovery should "cross-check by crawling the running app per role" | The stack **is** running, but any `127.0.0.1` origin **419s** on sign-in because `SANCTUM_STATEFUL_DOMAINS` pins the session to `APP_URL` | The harness uses the `APP_URL` origin. F-001 filed |
| E4 | "list the Impeccable commands that actually exist; do not assume names" | The `impeccable` skill **is available in this session** (its own description enumerates: design, redesign, shape, critique, audit, polish, clarify, distill, harden, optimize, adapt, animate, colorize, extract). Anthropic's `frontend-design` skill is **not installed**. No `/impeccable <command>` slash commands exist in this environment | Use the skill by name; do not invent a command syntax. Report the actual surface when first invoked |
| E5 | "dashboard framework built elsewhere — consume it, don't duplicate" | Correct, and it is bigger than expected: `docs/dashboards/` already holds a **six-section Phase 0** (`phase0/A-roles-access.md` … `F-realtime-perf.md`), `FINDINGS.md`, `METRICS.md`, `PROGRESS.md`, `01-matrix.md` | `docs/ui-ux/` must consume those files as prior art; the dashboard module is **coordination + polish**, not greenfield |
| E6 | Modules 13–17, budgeting-as-subdomain, "no revisions table" | Confirmed by route inventory: `/return-management`, forecast surfaces inside dashboards, `/assets`, `/budgeting`, portal routes | Inventory matches the brief here |
| E7 | "React 18 + Vite", Reverb WebSocket | Confirmed exactly | — |
| E8 | "No raw-ID inputs" is listed as a known problem | Unverified either way. `SearchableSelect` exists and 94 files use RHF, but no mechanical FK audit has been run | Recorded as an open verification task, not as a defect |
| E9 | Roles implied from seeders | 17 roles (`RolePermissionSeeder`), not the 14 in `docs/CLAUDE.md` | Personas in § D use 17 |

---

## F. Where this leaves Phase 1

1. **There is one design language and it is documented and enforced.** Phase 1 should
   *confirm and sharpen* Atelier (plus the two secondary palettes), name the principles,
   and close the two documented token/doc bugs — not propose a rival direction.
2. **`PRODUCT.md` at the repo root is currently customer-portal-scoped** (it describes only
   the portal order-entry surface). It needs to become the whole-product statement the
   brief asks for; that is the one Phase 1 deliverable that is genuinely missing.
3. **The measurable defect classes to attack at the shared level** are already visible:
   fixed-width slots that cannot hold their content (§ C.2.1–3), the thinnest layer being
   phone/mobile actions (`BottomSheet` 3 vs `Modal` 212), and one live permission-UI
   mismatch (M1).
4. **Verification is mostly already built.** The job is to add axe + bundle budgets and a
   per-module capture walk, not to invent a test strategy.

Next: `01-direction.md` (design direction), then `02-plan.md` (top-20 issues, roadmap, risks,
open questions) — **then stop for review**, per the brief's execution order.
