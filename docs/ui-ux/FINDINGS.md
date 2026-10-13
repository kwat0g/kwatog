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

### F-009 — `/crm/sales-orders` is the only page in 162 captures that scrolls horizontally

- **Status:** Open · **Severity:** medium-high (the brief's "no horizontal page scroll" rule)
- **Evidence:** `baseline-roles/sales/report.json` → `/crm/sales-orders@375`,
  `horizontalPageScroll: true`. The cause is identified, not inferred:
  ```
  div.text-2xl font-medium font-mono tabular-nums text-p (175>132, overflow-x: visible)
  ```
- **Cause:** a large monospace figure — 175px of digits in a 132px slot — painting past its box
  with `overflow: visible`. The same component is present on `/accounting/invoices@375`
  (`143>132`) but does not yet widen the page there; the sales figure is simply larger. So this
  is **F-002's defect class** (a fixed slot that cannot hold its content) one level up: there it
  overlapped a neighbour, here it scrolls the whole document.
- **Why it matters:** one report of a page that can be dragged sideways on a phone; the fix
  belongs in the shared KPI/figure component, not on the sales page.

### F-010 — F-004's Panel-clipping class: three of four sites fixed, the fourth is a header

- **Status:** **Resolved at 3 of 4 sites**; the `/budgeting` header **Open** ·
  **Severity:** low-medium (silent clipping, no scrollbar)
- **Fixed** — each panel opts in to the `scrollBody` capability F-004 added (opt-in, so no
  unrelated panel changes):

  | Site | Fix | Proof, same probe + same account |
  |---|---|---|
  | `spa/src/pages/dashboard/purchasing.tsx:107` (PR Action Queue) | `scrollBody` | ✓ purchasing `/dashboard@375`: overflow elements **4 → 0** |
  | `spa/src/pages/dashboard/purchasing.tsx:233` (Upcoming Deliveries) | `scrollBody` | ✓ same capture (both were `462>333` / `426>333`) |
  | `spa/src/pages/dashboard/finance.tsx:466` (Budget vs Actual; :459 is the empty-state variant) | `scrollBody` + `noPadding` + `px-1.5 pb-2` | ✓ finance `/dashboard@375`: **3 → 1** (the remaining 1 is the recharts text below) |

- **Round-trip proof, not a before/after guess.** The `spa` service runs `vite dev` with
  `./spa` bind-mounted (`docker-compose.yml:29,55`), so the captured origin **is the working
  tree**. With this fix stashed the same routes re-measured the baseline numbers **exactly**
  (purchasing 4, finance 3, `/budgeting` 2); with it applied they drop. The only variable was
  the code — which also rules out the "the data changed" explanation for these routes.
- **Still open — and it is a different defect.** `/budgeting` (finance), panel **"Budgets"**,
  `@375`: the panel root *and* its header both measure **411px inside a 333px panel**.
  `scrollBody` cannot help; the overflow is not the body. Measured cause: the header's `meta`
  is a `SegmentedControl` whose min-content is **340px** against **301px** available.
  - A `flex-wrap` on the header lowers it (411 → 356) but does **not** resolve it, because the
    control alone does not fit. That change was also rejected for a measured second reason: it
    re-spaced **54 headers across four dashboards** (the new geometry metric caught it — e.g.
    "Payroll pipeline" was 373px of content in a 377px header and wrapped once a 12px gap was
    added). A primitive used by 212 call sites should not change layout to serve one page; that
    is the same reasoning that made F-004's fix opt-in, and `spa/src/components/ui/Panel.tsx`
    was reverted to HEAD rather than shipped with the wider change.
  - **Next unit:** a narrow-width mode for `SegmentedControl` (its options are fixed-height and
    the wrapper is `overflow-hidden`, so this is a component change, not a class tweak), or move
    that control out of the panel header.
- **Also still open:** `finance/dashboard` at all three viewports — `text.[object
  SVGAnimatedString] (64>52)`, a recharts `<text>` node wider than its box. A separate fix
  with a separate cause; not addressed here.

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
