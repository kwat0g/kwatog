# OGAMI ERP — UI/UX Programme Findings (functional bugs, kept separate)

Per the brief's separate-tracks rule: functional bugs are logged here and get their **own
PRs**, never mixed into a UI PR.

Status legend: **Open** · **Resolved** · **Won't fix (why)**

---

### F-001 — Any `127.0.0.1` origin cannot sign in (HTTP 419 CSRF mismatch)

- **Status:** Open · **Severity:** medium (dev/test infrastructure, blocks browser testing)
- **Found:** 2026-10-13, while building the Phase 0 capture harness
- **Evidence:**
  - `curl -o /dev/null -w '%{http_code}' http://127.0.0.1:8081/sign-in` → `200` (SPA serves fine)
  - Browser sign-in from that origin: `GET /sanctum/csrf-cookie` → `204`, then
    `POST /api/v1/auth/sign-in` → **`419`**, twice. Page shows *"Sign-in failed. Please try again."*
  - `api/.env`: `APP_URL=https://47.129.228.44`, `SANCTUM_STATEFUL_DOMAINS=47.129.228.44`,
    `SESSION_DOMAIN=` (empty)
  - Same flow through `https://47.129.228.44` → sign-in succeeds
- **Cause (hypothesis, not confirmed):** the origin is not in `SANCTUM_STATEFUL_DOMAINS`, so
  `EnsureFrontendRequestsAreStateful` does not apply the session/CSRF handling the SPA's POST relies on.
- **Suggested fix (separate PR):** add the dev origin to `SANCTUM_STATEFUL_DOMAINS` for
  non-production, or document the `APP_URL` origin requirement in the dev setup docs.
- **Workaround:** the capture harness defaults to `APP_URL` and ignores the internal TLS certificate.

---

### F-002 — Dashboard audit-trail label overflowed its own fixed-width slot

- **Status:** **Resolved** (`spa/src/pages/dashboard/admin.tsx`) · **Severity:** low (cosmetic, universal)
- **Cause:** `<span className="… w-12">` sized 48px holding a monospace uppercase action
  word ("CREATED"/"DELETED") that measures **78px**. With `overflow: visible` the word painted
  30px past its box, over the neighbouring entity text. Ten instances per dashboard load.
- **Fix:** `w-12` → `min-w-20`. A floor keeps the column aligned across rows; the element can
  now grow if a future action name is longer, so it cannot overlap again.
- **Proof:** `/dashboard` overflow **10 → 0** at 375/768/1440 (unfixed vs fixed, same probe;
  `screenshots/before2` vs `screenshots/after`).

### F-003 — QC coverage bar could render wider than its own track

- **Status:** **Resolved as a UI defect** · **Severity:** medium (a wrong-looking number on screen)
- **Two causes, deliberately split:**
  1. **UI:** the page hand-rolled a progress bar and wrote `width: ${pct}%` plus
     `aria-valuenow={pct}` straight from the API, so a value above 100 produced a fill twice
     the track's width (`602>301`, `1276>638`, `1080>540`) and an `aria-valuenow` that exceeded
     its own `aria-valuemax`. The design system's `ProgressBar` **already clamps**; the page
     simply bypassed it. Fixed by using the shared primitive (`height="2"`, `ariaLabel` preserved),
     and `coverageBarClass`/`defectBarClass` became `coverageVariant`/`defectVariant` returning
     variant names. The same fix was applied to the defect-Pareto bar, which had the identical
     hand-rolled shape.
  2. **Data: see F-005.** `pct` genuinely returns 200 because the service divides two different
     quantities. Clamping the UI stops the visual bleed; it does **not** make the number right.
- **Proof:** qc `/dashboard` overflow **3 → 0** (mobile), **1 → 0** (tablet/desktop).

### F-004 — Panel clipped a table with no way to scroll

- **Status:** **Resolved** (`spa/src/components/ui/Panel.tsx`) · **Severity:** low-medium (silent data loss)
- **Cause:** `Panel` sets `overflow-hidden` to keep children inside its rounded corners, so the
  "Open NCRs" table (min-content **357px** in a **333px** body at 375px) was cut off — no
  scrollbar, no indication anything was missing. The finance and PPC dashboards each worked
  around it with an `overflow-x-auto` div; the QC page did not.
- **Fix:** the capability is now part of the primitive (`scrollBody` prop, opt-in so nothing
  that relies on clipping changes) and both QC table panels opt in.
- **Proof:** the `357>333` element is gone from the 375px capture; 5 data rows still render.

### F-005 — QC coverage percentage can exceed 100 (numerator and denominator are different quantities)

- **Status:** Open · **Severity:** medium (misleading KPI) · **Separate PR**
- **Evidence:** `api/app/Modules/Dashboard/Services/QualityDashboardService.php:178`
  ```php
  $r['pct'] = (int) round(($r['inspected'] * 100) / max(1, $r['total']));
  ```
  `inspected` counts **inspection rows** (lines 169–171); `total` counts **GRNs / work orders**
  (lines 166, 174–175). One GRN can legitimately carry several incoming inspections, so
  `inspected` can be a multiple of `total` and `pct` becomes e.g. 200. The `max(1, …)` guard
  prevents a divide-by-zero but not a >100 result.
- **Impact:** the dashboard reports coverage above 100%, and the value also drives the
  colour threshold (`coverage_success_pct` …), so the bar reads "success" for a nonsense figure.
- **Not fixed here:** choosing the correct denominator is a business decision (inspections per
  GRN? GRNs inspected vs GRNs due?), not a UI call. Awaiting the owner's definition.
- **Interim state:** the UI now clamps the rendered width and the ARIA value, so the screen is
  no longer broken while the number remains wrong.

### F-006 — The same hand-rolled progress bar survived in five other dashboards

- **Status:** **Resolved** (all 7 sites given a verdict) · **Severity:** latent (never observed to trigger)
- **Original evidence:** after migrating `quality.tsx` (F-003), the hand-rolled
  `role="progressbar"` track + fill shape remained at 7 sites in 5 files.
- **Verdict per site — two different problems, so two different answers:**

  **1. At risk, and now clamped (3 sites).** Each rendered a backend-supplied `percent`
  directly into `width` and `aria-valuenow` under `aria-valuemax={100}` — the exact F-003
  shape. Warehouse zone occupancy is the clearest real-world case: a location holding more
  than its nominal capacity legitimately exceeds 100%.

  | Site | Value |
  |---|---|
  | `spa/src/pages/dashboard/plant-manager.tsx:308` | `s.percent` |
  | `spa/src/pages/dashboard/ppc.tsx:152,160` | `s.percent` (also the ARIA max violation) |
  | `spa/src/pages/dashboard/warehouse.tsx:211,219` | `z.percent` (also the ARIA max violation) |

  **2. Proven safe — left untouched (4 sites).** Each derives its own ratio with a guarded
  denominator, `Math.max(…counts, 1)`, so the result cannot exceed 100 and cannot be `NaN`:

  | Site | Guard |
  |---|---|
  | `spa/src/pages/dashboard/hr.tsx:358` | `Math.max(...departments.map((d) => d.count), 1)` |
  | `spa/src/pages/dashboard/plant-manager.tsx:389` | `Math.max(...defects.map((d) => d.count), 1)` |
  | `spa/src/pages/dashboard/ppc.tsx:452` | `Math.round((i.count / maxCount) * 100)` over a guarded max |
  | `spa/src/pages/dashboard/purchasing.tsx:163` | same |

  Rewriting the four safe sites would be churn with a real visual-regression risk (the shared
  `ProgressBar` only offers `h-1`/`h-2`, while these use `h-2.5` and `h-3`) and no bug
  fixed. "Migrate or prove safe" was the test; four proved safe.

- **Fix:** `spa/src/lib/progress.ts` → `clampPercent()` is now the single definition of the
  0–100 rule, and `ProgressBar` imports it instead of keeping its own copy. `clampPercent`
  also guards `NaN`/`Infinity` (the old `Math.min/Math.max` composition returned `NaN` for a
  `NaN` input, which renders as the invalid declaration `width: NaN%` and silently drops the
  fill to zero).
- **Proof:** production / PPC / warehouse `/dashboard` at 375/768/1440 — overflow **0 → 0**,
  no horizontal page scroll, **DOM counts byte-identical** (`screenshots/before3` vs
  `after3`). `typecheck` exit 0, `eslint` exit 0. The guard is latent by design: today's data
  is inside 0–100, so nothing visible should change — and nothing did.

### F-007 — A backend notification type renders as a generic bell

- **Status:** Open · **Severity:** low · **Separate PR** · pre-existing
- **Evidence:** `npx vitest run src/lib/notificationMeta.test.ts` fails, with or without this
  PR's changes (verified by stashing them):
  ```
  These backend types render as a generic bell. Add them to BY_TYPE in notificationMeta.ts:
  maintenance.parts_handed_over: expected [ 'maintenance.parts_handed_over' ] to deeply equal []
  ```
  The guard reads the PHP notification catalog and asserts the SPA's icon map covers it; the
  backend emitted a type the frontend never learned.
- **Impact:** that notification shows a generic bell instead of its MaintenanceWorkOrder icon.
- **Not fixed here:** functional, unrelated to layout, and the brief keeps functional fixes on
  their own track. The fix is one entry in `BY_TYPE`.
---

### F-008 — The deployed demo accounts do not match `DemoAccountSeeder`

- **Status:** Open · **Severity:** medium (silently invalidates per-role verification) · pre-existing
- **Evidence:** inside the running database —
  ```
  select u.email, r.slug from users u join roles r on r.id = u.role_id
   where r.slug like 'maintenance%';
  maintenance@ogami.test        | maintenance_supervisor
  josefa.castillo125@ogami.local| maintenance_tech
  marites.santos100@ogami.local | maintenance_tech
  rafael.castillo199@ogami.local| maintenance_tech   (must_change_password = true)
  ```
  `DemoAccountSeeder` line 43 assigns `maintenance@ogami.test` to **`maintenance_tech`** and
  line 49 creates a separate `maintenancehead@ogami.test` for the supervisor. Neither the email
  nor the role matches the deployed row.
- **Impact:** any per-role check keyed off that seeder tests the **wrong role** and reports a
  pass. My own first full-role sweep did exactly that: it labelled a
  `maintenance_supervisor` session as `maintenance` until the directory was re-checked against
  the database. This is the same failure shape as the probes in *Measurement corrections* —
  the tool said fine, the artefact was wrong.
- **Consequence for coverage:** **`maintenance_tech` is not captured** in the baseline. Its three
  holders are `@ogami.local` accounts whose password is not derivable from any seeder, and one
  is forced to change it on first login. I did not guess or brute-force it.
- **Suggested fix (separate PR, owner's call):** either re-seed the demo accounts to match the
  seeder, or update the seeder to describe the deployed reality. Meanwhile the harness maps each
  role to the account that genuinely holds it, with a comment saying why.

### F-009 — `/crm/sales-orders` is the only page in 162 captures that scrolls horizontally — **fixed**

- **Status:** **Resolved** (`spa/src/components/ui/StatCard.tsx`) · **Severity:** was medium-high
  (the brief's "no horizontal page scroll" rule)
- **Evidence (as filed):** `baseline-roles/sales/report.json` → `/crm/sales-orders@375`,
  `horizontalPageScroll: true`. The cause was identified, not inferred:
  ```
  div.text-2xl font-medium font-mono tabular-nums text-p (175>132, overflow-x: visible)
  ```
- **Cause:** a large monospace figure — 175px of digits in a 132px slot — painting past its box
  with `overflow: visible`. The same component is present on `/accounting/invoices@375`
  (`143>132`). So this is **F-002's defect class** (a fixed slot that cannot hold its content)
  one level up: there it overlapped a neighbour, here it scrolls the whole document.
- **Fix — in the shared figure, where it belongs.** `StatCard`'s value is the shared KPI figure
  (used by 60+ cards across list and detail pages), so the page was not touched. The figure now
  sizes against **its own card** and cannot paint outside it:
  - the value sits inside an `@container` wrapper and takes
    `text-[clamp(1rem,12cqw,1.625rem)]` — a 16px floor at ~132px of content, reaching the 26px
    `text-2xl` KPI spec at ~217px;
  - `overflow-wrap: anywhere` is the backstop, not the mechanism.
- **Why container units, not a `sm:`/`lg:` ladder.** `StatCard` is dropped into caller-chosen
  grids — 2-up here, 1-up, 3-up and 4-up elsewhere — so the constraint that matters is the
  card's width, not the viewport's. The same card measures 132px of content at 375px on the
  sales list and 110px at 768px on `/budgeting`, where the viewport is *wider* and the slot
  *narrower*; a viewport breakpoint gets that backwards.
- **Why not truncate.** A money figure with digits hidden is worse than one on a second line, so
  the design size is kept wherever the card has room (measured 26px/one line at 768 and 1440 on
  `/crm/sales-orders`) and the wrap only engages when even the floor cannot fit. Verified
  deliberately, since no live row triggers it: with `₱1,234,567,890.12` injected at 375px the
  figure wraps to 2 lines inside the card, `scrollWidth − clientWidth = 0` and the page scroll is
  **0**. Every digit stays on screen.
- **Proof — A/B with the fix stashed (`w2-ctl`) vs applied (`w2-after`), same probe, same
  accounts, same day (8 roles, 36 route×viewport rows):**

  | Row | control | fixed |
  |---|---|---|
  | `/crm/sales-orders@375` | `H-SCROLL`, figure `175>132` | page scroll **0**; figure `132 = 132` |
  | `/accounting/invoices@375` | figure `143>132` | overflow **0** |
  | 33 of the 36 rows | — | byte-identical; **no row gained overflow, no new page scroll** |

- **Two further sites the probe could only see as height.** The figure's fixed 26px also made
  money values *wrap* where the slot was narrow (no overflow, so no finding) and then grow the
  KPI row:
  - `/budgeting@768`: four `₱ 1.23M` figures each broke after the currency symbol — the KPI row
    measured **115px** (figure height 65px, two lines) against **70px** (one line) after; this is
    the whole of that route's 45px panel shift.
  - `qc /dashboard@375`: five figures stepped 26px → 16px, 3 grid rows × 13px = **38px**, exactly
    the measured shift of every panel on that page.
  Every remaining panel `y` change in the compared set resolves to this same step (`12cqw`),
  with all panel **heights** unchanged except the one `/budgeting` header (F-010).

### F-010 — F-004's Panel-clipping class: the fourth site was a control that could not shrink

- **Status:** **Resolved at all 4 sites** · **Severity:** was low-medium (silent clipping, no
  scrollbar)
- **Fixed at all four sites** — three opt in to the `scrollBody` capability F-004 added (opt-in,
  so no unrelated panel changes); the fourth is the header case below:

  | Site | Fix | Proof, same probe + same account |
  |---|---|---|
  | `spa/src/pages/dashboard/purchasing.tsx:107` (PR Action Queue) | `scrollBody` | ✓ purchasing `/dashboard@375`: overflow elements **4 → 0** |
  | `spa/src/pages/dashboard/purchasing.tsx:233` (Upcoming Deliveries) | `scrollBody` | ✓ same capture (both were `462>333` / `426>333`) |
  | `spa/src/pages/dashboard/finance.tsx:466` (Budget vs Actual; :459 is the empty-state variant) | `scrollBody` + `noPadding` + `px-1.5 pb-2` | ✓ finance `/dashboard@375`: **3 → 1** (the remaining 1 is the recharts text below) |
  | `spa/src/pages/budgeting/index.tsx:194` (Budgets; the header above) | `SegmentedControl` narrow mode + `Panel` header `min-w-0` — see the 4th-site block below | ✓ `/budgeting@375`: **2 → 0**, panel and header `333 = 333` |

- **Round-trip proof, not a before/after guess.** The `spa` service runs `vite dev` with
  `./spa` bind-mounted (`docker-compose.yml:29,55`), so the captured origin **is the working
  tree**. With this fix stashed the same routes re-measured the baseline numbers **exactly**
  (purchasing 4, finance 3, `/budgeting` 2); with it applied they drop. The only variable was
  the code — which also rules out the "the data changed" explanation for these routes.
- **4th site, fixed — and it was a different defect.** `/budgeting` (finance), panel **"Budgets"**,
  `@375`: the panel root *and* its header both measured **411px inside a 333px panel**.
  `scrollBody` cannot help; the overflow is not the body. Measured cause: the header's `meta`
  is a `SegmentedControl` whose min-content is **340px** against **301px** available.
  - **Fix, two halves, both in the primitives:**
    1. `SegmentedControl` gets a narrow-width mode — `max-w-full` (so the container may be
       narrower than its labels at all; a shrink-to-fit box otherwise sizes to max-content),
       `flex-wrap` (options move to a second row), `overflow-x-auto overflow-y-hidden` (second
       stage, for a single option that cannot fit even alone) and `shrink-0 whitespace-nowrap`
       (never squash an option under its label).
    2. `Panel`'s header offers `min-w-0` on **both** links of its flex chain (the title cluster
       and the meta slot). Without it a flex item's automatic minimum size is its content's, so
       no narrow mode can engage — the header still grows to 340px.
  - **Why not scroll alone:** tried first, and it fixed the page but left **2 of the 6 options
    outside the control's visible box** (measured `optionsOutsideBox: 2`) — the filed complaint
    (options unreachable, no scrollbar to say so) merely softened. A filter strip whose job is
    "pick a status" should show the statuses. Scrolling survives as the second stage.
  - **Why not `flex-wrap` on the header** (rejected at filing, still rejected): it re-spaced **54
    headers across four dashboards** (the geometry metric caught e.g. "Payroll pipeline": 373px
    of content in a 377px header, wrapped once a 12px gap was added). A primitive used by 212
    call sites does not change layout to serve one page — the same reasoning that made F-004's
    fix opt-in.
  - **Measured after:** panel and header both `333 = 333`, `overflowing: []` at every viewport.
    The control is 246px wide inside its 301px slot and wraps to two rows — option tops at
    **1017** and **1045**, control height **58px**, tallest option bottom **57px**, i.e. nothing
    clipped vertically and `optionsOutsideBox: 0`.
  - **Cost, measured and accepted:** `/budgeting@375` "Budgets" header **55 → 83px**. It is the
    one panel-height change in **264 compared panel instances**; the room is what the six
    options need to stay visible.
- **`min-w-0` is inert where it is not needed — proven two ways.** Against the stashed control,
  **33 of 36** route×viewport rows are byte-identical, and 263 of 264 panel instances keep their
  exact height (the 264th is the intended `/budgeting` header). The header's `overflow-hidden`
  stays, so a panel whose content declares no narrow mode clips exactly as before.
- **Regression guard:** `spa/src/components/ui/__tests__/narrow-width.test.ts` locks all of the
  above source decisions (8 tests). jsdom has no layout engine — this defect class is invisible
  to a render test, which is why it survived — so the test asserts the classes that fix it, in
  the style of `styles/__tests__/blueprint-tokens.test.ts`: **6 of its 8 tests fail against the
  pre-fix components** (negative control run with the three files stashed).
- **Also still open, unchanged:** `finance/dashboard` at all three viewports — `text.[object
  SVGAnimatedString] [panel: AP Aging Distribution] (64>52)`, a recharts `<text>` node wider than
  its box. Same finding, same numbers, in **both** the control and the fixed capture — a separate
  fix with a separate cause. (The earlier reading of `baseline-roles`, where that entry appeared
  to be gone, was data drift: it is present in both sides of today's A/B.)

### F-011 — `/sign-in` had two WCAG 2.2 AA failures, one of them a 6px tap target

- **Status:** **Resolved** (`spa/src/layouts/AuthLayout.tsx`, `spa/src/pages/landing/components/AutoPartShowcase.tsx`) ·
  **Severity:** medium (blocks users; "WCAG 2.2 AA" is a committed claim)
- **Found:** 2026-10-13, by the new axe gate on its **first run** — which is what the gate was built for.
- **Evidence (axe, before the fix):**
  - `/sign-in` · **`link-name` (serious)** — the brand lockup link to `/` had no accessible name.
    Its text came from `contact?.legal_name ?? ''`; an empty string is not nullish, so when the
    landing request was slow or failed the link rendered with no text and an `<img>` whose alt
    was also empty. A screen reader announces nothing; the link is unusable.
  - `/sign-in` · **`target-size` (wcag22aa** — a rule new in WCAG 2.2) — the carousel dots were
    `<button className="h-1.5 w-1.5">`: a **6×6px** touch target, the active one 20×6. WCAG
    2.5.8 requires 24×24 unless an exception applies; none did here.
- **Fix:** `contact?.legal_name || 'Ogami ERP'` (with the comment saying why `||` and not `??`);
  each dot is now a 24×24 `flex` button with the 6px pill drawn **inside** it, so the visual is
  unchanged while the hit area and the focus ring are the size a finger needs.
- **Proof:** `npm run test:a11y` — **8 passed (~28s)**, with **zero violations at any impact
  level** on all six surfaces (the first run carried these two on `/sign-in`). The spec prints
  every non-blocking violation it finds and the green log carries none; critical/serious would
  have failed the run. Playwright deletes test output for passing tests, so the console log is
  the record — the per-surface attachment is what to read on a red run. The negative control
  proves the gate can see a planted `button-name` violation, so "clean" is a measurement rather
  than a mis-tagged run.

### F-012 — All 51 Playwright specs were outside `npm run typecheck`; enabling it found 8 errors

- **Status:** **Resolved as tooling** (`spa/tsconfig.e2e.json`, `npm run typecheck:e2e`, CI step) ·
  **Severity:** medium (a whole suite's types were unchecked)
- **Cause:** `tsconfig.json` includes only `src` and `vite.config.ts`. Nothing compiled `e2e/` or
  `playwright.config.ts`. Found because the a11y negative control mapped over an `AxeResults`
  object (`violations.map(...)`) — which **throws at runtime** while the browser reports nothing —
  and `npm run typecheck` called the file fine.
- **Evidence:** `npm run typecheck:e2e` on the day it was added → **8 errors**: 5 in the new a11y
  spec (including that `.map` bug) and 3 pre-existing (`hardening.spec.ts`,
  `return-case-workflow.spec.ts`, `rma-workflow.spec.ts`). All fixed; the command now exits 0 and
  is enforced in `spa-tests.yml` beside `npm run typecheck`.
- **Why it matters:** the e2e suite is this programme's measuring instrument, and an instrument
  that is not compiled is the "the probe said fine" failure shape already recorded three times
  below (C-001…C-003).

### F-013 — Four vitest files were red outside this PR (stash-controlled) — **fixed**

- **Status:** **Resolved** (2026-10-13) · **Severity:** low · was pre-existing
- **Evidence (at the time):** identical results with this PR's changes applied and with them stashed
  (`git stash push -- spa/src`):
  | File | Result |
  |---|---|
  | `src/lib/notificationMeta.test.ts` | 1 failing test — F-007 above |
  | `src/pages/production/work-orders/CrewPanel.test.tsx` | 3 failing (`Unable to find a label with the text of: Number of workers`) |
  | `src/pages/production/work-orders/StartProductionDialog.test.tsx` | 3 failing |
  | `src/pages/crm/price-agreements/index.test.tsx` | passes **in isolation**, fails only in the full run (`Unable to find role="button" and name "Archive agreement"`) — order/parallelism-dependent |
- **Two causes, both fixed at the root:**
  1. **Six production work-order tests were time-bombs.** `CrewPanel.test.tsx` ×3 and
     `StartProductionDialog.test.tsx` ×3 hardcoded Manila days
     (`preferred_production_date: '2026-10-10'`, `{ value: '2026-10-09' }`) while the components
     accept today-or-later only (`preferredDay >= today`, `today` from the Manila clock). Once the
     calendar passed those dates the day became invalid, the suggestion query was disabled, and
     each test died at its first `getByLabelText`. One fixture also mocked
     `scheduled_end: '2026-10-12T10:00+08:00'`, which `AssignmentEditor` reads as an expired
     proposal and disables Confirm on. All six now derive their days from the Manila clock — the
     idiom the files already used elsewhere (`scheduleDateKey(new Date().toISOString(),
     'Asia/Manila')` + `offsetScheduleDate`) — and the mocked window stays ahead of the real clock.
  2. **The CRM flake was a page bug, not test noise.** The Archive action was gated on
     `r.effective_to < localIsoDate()` — the *viewer's* zone — while the Status chip three lines
     above already computed the Philippine date inline, and the backend expires agreements on the
     Manila date (`APP_TIMEZONE=Asia/Manila`). The test only held while
     `vi.stubEnv('TZ', 'Asia/Manila')` had landed; that process-wide env write is what flipped
     under full-suite load. The page now uses `manilaIsoDate()` for both, and the test freezes the
     instant (`vi.useFakeTimers({ toFake: ['Date'] })`) without touching `process.env`.
- **Proof:** `CrewPanel` + `StartProductionDialog` **43/43 pass**; the CRM file passes three
  consecutive runs and **fails with the page fix stashed** — it now genuinely discriminates the
  Manila date from a UTC one, so the fix is verified rather than asserted. Full suite afterwards:
  **177 files / 1034 tests passed**.
- **Class note:** 59 test files carry hardcoded 2026 dates, but such a date only breaks a test
  when it feeds a today-relative control. A scan of every hardcoded date falling in the next two
  weeks found no other live fixture — the rest is display data that will not fail as time passes.

### F-014 — `npm run lint` fails on committed code, and CI enforces it — **fixed**

- **Status:** **Resolved** (2026-10-13) · **Severity:** was medium-high (it reds the job the new gates share)
- **Evidence:** `npm run lint` → `✖ 5 problems (5 errors, 0 warnings)`, every one
  `no-restricted-syntax` *"toISOString() is UTC — use localIsoDate() from @/lib/formatDate for
  calendar dates (Manila is UTC+8)"*:
  ```
  src/pages/return-management/detail.tsx:1167,1168
  src/pages/return-management/dispose.tsx:205,230
  src/pages/return-management/dispose.test.tsx:32
  ```
  Reproduced with this PR's changes stashed; none of these files is touched here.
- **Provenance:** the rule entered `spa/eslint.config.js` on 2026-09-24 (`46a0e005`); and
  `git blame` puts **all five offending lines** in `943e27ff` (2026-10-10, *"chore: consolidate
  in-progress ERP work across modules"*) — i.e. written 16 days after the rule existed to catch
  exactly this. It is red now.
- **Resolution:** every site used `new Date().toISOString().slice(0, 10)` as "today" for a `date`
  input's `min` and its guard — a UTC date, which is *yesterday* in Manila before 08:00. The
  backend validates `after_or_equal:today` under `APP_TIMEZONE=Asia/Manila`
  (`DisposeReturnRequest`, `ReturnRequestController`, and `ReturnRequestService`'s own
  `now()->toDateString()` compare), so the front end now uses `manilaIsoDate()` at all five sites —
  `detail.tsx` (`min`, submit guard), `dispose.tsx` (`min`, Record-disposition guard), and the
  test's fixture. That matches `self-service/overtime.tsx`, the existing date-input precedent.
  The rule itself was not touched.
- **Proof:** `npm run lint` → **exit 0** across the SPA; return-management **52/52 tests pass**;
  `npm run typecheck` exit 0.

### F-015 — two e2e specs are red on committed code — **open**

- **Status:** Open · **Severity:** medium (they red a suite people run before a release)
- **Found:** 2026-10-17, while verifying W1.3 — not caused by it. Both were re-run with this
  unit's changes stashed and fail identically, which is the proof below.
- **Evidence:**
  ```
  cd spa
  npx playwright test e2e/maintenance-responsive.spec.ts -g "unused spare part return"
  #  wait timeout: getByRole('button', { name: 'Return Grease from lot LOT-A' })

  npx playwright test e2e/chain-leave.spec.ts -g "employee files leave"
  #  expect(getByText(/Leave request submitted for approval/i)) — element not found
  ```
- **What each one is:**
  - `maintenance-responsive`: the spec expects an **Add part** header action and a **Return
    …** control on the work-order detail page. The page now renders **Request spare parts**
    (`pages/maintenance/work-orders/detail.tsx:230`, the shared `SparePartSheet`) and no
    return control at that scroll position, so the spec is stale against an intentional UI
    change. Two API errors are also visible in the page's dev error panel, so the return path
    may not be reachable in this fixture at all.
  - `chain-leave`: the leave form submits (the POST-level assertions in the sibling steps pass)
    but the expected success toast never appears. Not yet diagnosed — could be a toast copy
    change, a date default the spec no longer satisfies, or a real submission bug.
- **Why not fixed here:** per the programme's rule for functional defects found in passing
  (Q6: log, separate PR), and because the second one deserves a diagnosis rather than a test
  edit — changing the assertion to match the toast would hide a submit failure if that is what
  it is.
- **Next step:** reproduce `chain-leave` by hand at `/self-service/leave`, capture the network
  response, then either fix the defect or update the spec with the reason in its comment.

### F-016 — chart colour was semantic, or absent, but never categorical — **fixed**

- **Status:** **Resolved** (2026-10-17) · **Severity:** medium (legibility, and a false signal)
- **Evidence (Tier A, three tokens.css, zero chart tokens):**
  ```
  grep -n 'chart' spa/src/styles/tokens.css     # no matches
  grep -rn "color: 'var(--" spa/src/pages/dashboard/*.tsx
  #  quality.tsx:318  outgoing → 'var(--danger)'
  #  hr.tsx:131-134  present/late/absent/on_leave → success/warning/danger/info
  ```
  Two different problems wore one symptom. The HR donut is *correct*: those
  slices are states, and state colour is the semantic ramp's job. The QC
  "Inspection Queue by Stage" donut is not — **outgoing inspections painted
  danger red**, which reads as a failure count when the slice only means "this
  stage". With no categorical tokens, the only options were the semantic ramp or
  a hardcoded hex, and `audit:tokens` (rightly) forbids the second.
- **Fix:** `--chart-1..8` in all three palettes (fixed hue order: clay, ochre,
  olive, jade, teal, indigo, violet, wine), `lib/chartPalette.ts` as the only
  consumer API, and `chartColor(index)` defaults in `BarComparison` /
  `DonutBreakdown`. The QC stage donut now draws from the palette.
- **Proof:** `styles/__tests__/palette-contrast.test.ts` gained **39** chart test cases
  (13 per palette: 1 existence, 8 mark contrast, 1 semantic-collision, 1 normal-vision
  pair matrix, 2 simulated pair matrices). Measured minimums: **contrast 3.28**
  (office, bar 3), **ΔE 22.3** normal (bar 20), **ΔE 18.8 / 21.5** under
  protanopia / deuteranopia (bar 10). A negative control was run by collapsing
  `--chart-8` onto `--chart-1`: the pair test fails with
  `--chart-1/--chart-8 ΔE 0.0`, and the semantic-collision test stays green (a
  duplicate series is not a semantic reuse) — the right check, on the right
  property.
- **Why eight and not five:** the Pareto and stage breakdowns are the widest
  categorical surfaces. Colour is never the only difference — every chart here
  draws a legend or direct label — so a ninth series cycles rather than
  inventing a hue that no longer separates under CVD.

### F-017 — a dashboard tile's numbers could not leave the dashboard — **fixed**

- **Status:** **Resolved** (2026-10-17) · **Severity:** medium (executives screenshot; finance re-types)
- **Evidence:** `DashboardShell` and `LiveDashboardWidget` had no export affordance;
  the only ways out of a tile were `Open →` and a screenshot. Server CSV/XLSX
  exists for *list pages*, which is a different shape: the dashboard's numbers
  come from `/dashboard/layout?rich=1` and `/dashboard/widget-data`.
- **Fix:** `lib/widgetExport.ts` (payload → rows, one function per render kind),
  `lib/csv.ts` (one RFC 4180 writer for the whole SPA), and
  `WidgetExportButton` / `CsvDownloadButton` in the tile header, wired into
  `LiveDashboardWidget` (every layout widget) and `MetricPanel` (the analytics
  panels on production/returns). No request is made: the CSV is built from the
  object the tile drew, so the file cannot disagree with the picture.
- **Two rules worth stating:** raw numbers, never `₱1,234.50` (quoting a figure
  makes Excel treat it as text and silently drop it from sums); and a table
  widget that shows 6 of 300 rows writes a `Showing 6 of 300` row instead of
  passing a truncated list off as the whole set.
- **Spreadsheet-injection guard:** a string cell starting with `=`, `+`, `-` or
  `@` is prefixed with an apostrophe — a customer/product name is data, not a
  formula. Numbers are exempt, so `-5` stays a negative number.
- **Proof:** 10 CSV tests (escaping, CRLF, null cells, four injection shapes,
  negative numbers, filename slugging), 19 builder tests (per render kind,
  truncation note, rich-over-summary preference, unavailable → no download, filename
  stamped by data date) and 3 component tests (accessible name, exact download
  arguments, absent control when there is nothing to export). Then through the
  browser: `e2e/widget-export.spec.ts` (3 passed) logs in, renders two real tiles
  against a mocked rich layout, clicks the control and reads the downloaded file —
  asserting the exact bytes (`Segment,Value,Share of total (%)` … `Total,9,`) and the
  suggested filename (`inspection-queue-2026-10-17.csv`). A download-event assertion
  alone would have passed for a button that exported the wrong widget.
- **Not claimed:** there is no page-level export. A multi-panel dashboard has no
  single honest table shape; inventing one produces files nobody trusts.

### F-018 — four dashboard e2e specs were red on committed code (a fixture gap) — **fixed**

- **Status:** **Resolved** (2026-10-17) · **Severity:** medium (they red the suite a release runs)
- **Found:** while verifying W1.5 — not caused by it. `dashboard-forecast-quality`
  (6 tests), `-finance` (6) and `-hr` (6) failed on the shared diagnostics fixture with
  ```
  Error: Browser console errors; HTTP responses: []
  +   "Query data cannot be undefined. Please make sure to return a value other than
  +    undefined from your query function. Affected query key: [\"kpi\",\"trends\",\"dppm,first_pass_yield,ncr_closure_days\"]",
  ```
  Reproduced with this unit's files stashed (path-limited `git stash push -u`), so the
  failure is pre-existing.
- **Cause:** every dashboard mounts `KpiStrip`, which batches six months of trends for all of
  its codes. `kpiApi.trends` returns `response.data.data` **verbatim**, and the shared API
  fallback answers unmocked endpoints with `{}` — so that one endpoint handed React Query
  `undefined`, which React Query v5 reports as a console error. The diagnostics fixture fails
  a test on any unexpected console error, so the spec died on a strip it was not testing.
- **Fix:** one line in `e2e/fixtures.ts` — `'/api/v1/dashboard/kpi/trends': { data: {} }` in
  `DEFAULT_READ_RESPONSES` (an empty map, which the consumer's own `trends?.[code] ?? []`
  already handles). The fallback's design contract is unchanged: a spec that needs data still
  has to mock it.
- **Proof:** the four dashboard specs went from **2 of 20 passing** to **20 of 20 passing**
  with no edit to a spec and none to application code. The `{}` fallback had been hiding the
  missing mock, not holding the line.

## Measurement corrections (probe defects, not application defects)

### C-001 — "20 interactive elements with no accessible name" was a false positive

- **Withdrawn.** The first capture probe derived an accessible name from
  `aria-label ?? title ?? innerText ?? alt ?? placeholder`. That is not how a browser computes a
  name: `innerText` excludes both `display:none` text and an `<img>` alt, so the topbar brand
  link — which Chromium names from its logo successfully — was reported as unnamed on every
  mobile capture.
- **Method now:** Chromium's own accessibility tree (`Accessibility.getFullAXTree` over CDP),
  filtered to non-ignored interactive roles with an empty name.
- **Result on the same page and viewport: 0 unnamed interactive elements.**

### C-002 — Overlay decoration was being reported as overflow

- **Withdrawn for `EmptyState` and `.sr-only`.** `scrollWidth > clientWidth` also fires for
  deliberate overlays: `EmptyState` positions a 52–72px `DatumMark` absolutely inside a 40px
  icon slot, so the wrapper's `scrollWidth` is 56 **by design**.
- **Method now:** overflow is measured against **in-flow** descendants via bounding rects
  (absolutely/fixed-positioned subtrees are skipped), plus a leaf-text rule so a fixed-width
  label overflowing its own text — the real F-002 defect — is still caught. Getting this wrong
  in either direction is how a probe either invents defects or hides them: the first version
  invented one, and the second version (element-children only) hid F-002 until the leaf rule was added.

### C-003 — The capture harness silently ignored `--flag=value` and captured the wrong role

- **Found and fixed 2026-10-13, in the harness itself.** `arg()` matched with
  `process.argv.indexOf('--role')` — an exact-token match — so `--role=purchasing` **missed**
  and fell through to the default. The harness then captured **admin** routes into the
  **`before`** label: 18 screenshots, no error, and the wrong path printed only on its last line.
- **Impact:** an untracked, uncited label (`screenshots/before/` — referenced by no document)
  was overwritten with post-fix images and is not recoverable. It was moved out of the tree rather
  than left where it could be mistaken for "before" evidence. **No cited claim depends on it:**
  the record cites `before2`, `before3`, `after`, `baseline` and `baseline-roles`, all tracked.
- **Fix:** `arg()` now throws on the `=` form and on a missing value, and `main()` prints the
  resolved role, label and output path **before** the first capture.
- **Why it is recorded with the probe defects:** same failure shape as C-001 and C-002 — a tool
  that reports success while producing a wrong artifact. A silent fallback is worse than a crash.

Recorded because both were published in `00-discovery.md` before being disproved, and the
corrections change the numbers.

---

## Not findings (recorded so they are not re-filed)

- `.truncate` and `.sr-only` elements reporting `scrollWidth > clientWidth`: intentional rules.
- `overflow-x-auto` table wrappers reporting overflow: intentional responsive fallback.
- `EmptyState`'s `DatumMark` extending past its slot: intentional motif.

## Where the *open product* findings from prior work live

`docs/dashboards/FINDINGS.md` (F-001…F-067+) tracks the backend/product findings this programme
must not duplicate — board-blind approval types, notification gaps, index debt, permission
mismatches M1–M12. Those are referenced by ID from `02-plan.md`.
