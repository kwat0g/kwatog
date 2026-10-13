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

### F-006 — The same hand-rolled progress bar survives in five other dashboards

- **Status:** Open · **Severity:** latent (the identical defect, not yet observed to trigger) · **Next unit**
- **Evidence:** after migrating `quality.tsx` (F-003), the hand-rolled `role="progressbar"`
  track + fill shape is still present at these sites — 7 instances, none of which use the shared
  `ProgressBar`:

  | File | Instances |
  |---|---|
  | `spa/src/pages/dashboard/hr.tsx` | 1 |
  | `spa/src/pages/dashboard/plant-manager.tsx` | 2 |
  | `spa/src/pages/dashboard/ppc.tsx` | 2 |
  | `spa/src/pages/dashboard/purchasing.tsx` | 1 |
  | `spa/src/pages/dashboard/warehouse.tsx` | 1 |

- **Why not fixed in the same commit:** each needs its own check of whether its percentage can
  exceed 100. F-003's QC bar could, because its service divides two different quantities; a bar
  fed by a value the backend already clamps is not defective, and rewriting it would be churn
  with a visual-regression risk and no bug fixed. The correct outcome is per site: **migrate**
  or **prove safe**. Recording the list is what keeps that from being forgotten.
- **Not a silent omission:** this is the class the brief's risk register calls "inconsistent
  partial rollout" — one dashboard fixed and five left on a duplicate implementation.

---

## Measurement corrections (probe defects, not application defects)

Recorded because both were published in `00-discovery.md` before being disproved, and the
corrections change the numbers.

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

---

## Not findings (recorded so they are not re-filed)

- `.truncate` and `.sr-only` elements reporting `scrollWidth > clientWidth`: intentional rules.
- `overflow-x-auto` table wrappers reporting overflow: intentional responsive fallback.
- `EmptyState`'s `DatumMark` extending past its slot: intentional motif.

## Where the *open product* findings from prior work live

`docs/dashboards/FINDINGS.md` (F-001…F-067+) tracks the backend/product findings this programme
must not duplicate — board-blind approval types, notification gaps, index debt, permission
mismatches M1–M12. Those are referenced by ID from `02-plan.md`.
