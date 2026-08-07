# Landing & Auth Atelier Rebuild — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retheme the landing page and auth pages onto Atelier tokens, remove copy that claims capabilities Ogami does not offer, and replace the orphaned quote-request write path with a Contact Us form that lands in a real CRM inbox.

**Architecture:** Two independent halves. The **frontend half** (Tasks 1–6) retires the parallel `--landing-*` CSS namespace in favour of the shared Atelier semantic tokens, leaving exactly three `--blueprint-*` tokens for the technical line-work that landing and auth both use. The **backend half** (Tasks 7–10) reshapes the unread `quote_requests` table into `contact_inquiries`, exposes it at `/crm/inquiries`, and adds an explicit convert-to-lead promote step so general enquiries never auto-pollute the CRM funnel. Either half ships alone.

**Tech Stack:** React 18 + TypeScript + Vite + Tailwind v3 + TanStack Query + React Hook Form + Zod + GSAP + three.js + Lenis (SPA); Laravel 11 + PHP 8.3 + PostgreSQL 16 (API); Vitest + Playwright + PHPUnit.

**Source spec:** `docs/superpowers/specs/2026-08-07-landing-auth-rebuild-design.md`

## Global Constraints

Every task's requirements implicitly include this section.

- **Never hardcode a colour.** Values live only in `spa/src/styles/tokens.css`. `npm run audit:tokens` enforces it. The one permitted exception is created in Task 5 and is a single quarantined file.
- **No `dark:` variants.** A token already differs per palette; a `dark:` override fights it and breaks the floor palette.
- **Read density from tokens** — `h-row` / `min-h-hit`, never `h-8` / `min-h-[44px]`.
- **Instrument Serif ships at weight 400 only.** Never apply a weight class to `font-display`.
- **Numbers, IDs and dates always `font-mono tabular-nums`.**
- **Every model uses `HasHashId`. Every API Resource returns `hash_id`, never the integer `id`.**
- **`status` is not mass-assignable** on hardened models. Service writes use `$m->forceFill(['status' => X])->save()`, or `$m->fill([...]); $m->status = E::Foo; $m->save();` when one audit row is wanted.
- **Every multi-write operation wrapped in `DB::transaction()`.**
- **Never use Bearer tokens.** Sanctum cookie session only; never store auth in `localStorage` / `sessionStorage`.
- **Never set `'Content-Type': 'multipart/form-data'`** on axios FormData requests.
- **Migrations are numbered `NNNN_`.** Highest existing is **0446**. This plan adds **0447**, **0448**, **0449** in that order and no others.
- **New migrations must be reversible** — `down()` restores the prior shape.
- **Literal route segments are declared before `{model}` bindings.**
- **Every list page handles 5 states:** loading skeleton, error + retry, empty, data, stale. See `docs/PATTERNS.md`.
- **Every mutation:** `toast.success` / `toast.error` + `queryClient.invalidateQueries`.
- **Every page lazy-loaded** with `React.lazy()`; every route wrapped in the guard stack.
- **Copy rule:** after Task 6, the strings `CAD` and `Catalogue` must not appear anywhere under `spa/src/pages/landing/`, including comments and docblocks.
- **Pre-existing debt is out of scope and must not be "fixed":** 38 `/portal/*` 401s, 8 `any` lint errors.
- Commit after every task. Never amend a pushed commit.

## Baseline Facts (verified, do not re-derive)

| Fact | Value |
|---|---|
| `--landing-*` class references in `spa/src` | **518** across 31 files |
| Most-used | `landing-accent` 154 · `landing-text` 69 · `landing-border` 53 · `landing-muted` 45 · `landing-canvas` 39 |
| Hex literals under `pages/landing/` | **3** — `HeroCanvas.tsx:102`, `:111`, `PartShowcase3D.tsx:84` |
| Token gate exemption | `scripts/check-token-discipline.mjs:24` — `/^src\/pages\/landing\//` |
| Landing token block | `tokens.css:94-112` (light), `:184-201` (dark) |
| Tailwind landing map | `tailwind.config.ts:87-106` |
| `hero_cta.quote_href` | already `'#contact'` (migration `0428`) — only the label changes |
| `quote_request` sequence key | already registered as `QR` in migration `0360:23` — Task 7 **adds** `contact_inquiry`, does not rename |
| Existing `contact_intro` | **not seeded** — hardcoded fallback only, fixed in TSX |
| Highest migration | 0446 |

## File Structure

**Task 1 — token layer**
- Modify: `spa/src/styles/tokens.css` — add `--blueprint-*`; `--landing-*` stays until Task 5
- Modify: `spa/tailwind.config.ts:87-106` — add `blueprint` colour group + `blueprintGridSize` spacing

**Tasks 2–3 — landing retheme (31 files)**
- Modify: `spa/src/pages/landing/styles.ts` — the layout contract; highest-leverage single file
- Modify: `spa/src/pages/landing/components/` (15 files), `sections/` (9 files), `LandingPage.tsx`, `three/PartShowcase3D.tsx`

**Task 4 — auth retheme**
- Modify: `spa/src/layouts/AuthLayout.tsx`, `spa/src/pages/auth/{login,forgot-password,reset-password,change-password}.tsx`

**Task 5 — namespace deletion + gate**
- Create: `spa/src/pages/landing/three/tokenColor.ts` — the only file permitted to hold a hex literal outside `tokens.css`
- Create: `spa/src/styles/__tests__/landing-namespace.test.ts`
- Modify: `spa/src/styles/tokens.css`, `spa/tailwind.config.ts`, `spa/src/pages/landing/components/HeroCanvas.tsx`, `spa/scripts/check-token-discipline.mjs`

**Task 6 — truth fixes**
- Create: `api/database/migrations/0448_amend_landing_part_showcase_copy.php`
- Modify: `spa/src/pages/landing/sections/PartShowcaseSection.tsx`, `sections/ContactSection.tsx`, `components/HeroCanvas.tsx`, `sections/PartShowcaseSection.tsx` docblock
- Create: `spa/src/pages/landing/__tests__/copy-claims.test.ts`

**Task 7 — backend reshape**
- Create: `api/database/migrations/0447_reshape_quote_requests_into_contact_inquiries.php`, `0449_seed_contact_inquiry_document_sequence.php`
- Create: `api/app/Modules/Landing/Enums/ContactInquiryStatus.php`, `Models/ContactInquiry.php`, `Requests/StoreContactInquiryRequest.php`, `Services/ContactInquiryService.php`, `Controllers/ContactInquiryController.php`, `Resources/ContactInquiryResource.php`, `Notifications/ContactInquiryReceivedNotification.php`
- Delete: `Enums/QuoteRequestStatus.php`, `Models/QuoteRequest.php`, `Requests/StoreQuoteRequestRequest.php`, `Services/QuoteRequestService.php`, `Controllers/QuoteRequestController.php`, `Notifications/QuoteRequestReceivedNotification.php`
- Modify: `api/app/Modules/Landing/routes.php`
- Create: `api/tests/Feature/Landing/ContactInquiryTest.php`

**Task 8 — form consolidation**
- Delete: `spa/src/pages/landing/components/QuoteModal.tsx`, `components/FloatingQuoteButton.tsx`
- Modify: `spa/src/pages/landing/sections/ContactSection.tsx`, `LandingPage.tsx`, `spa/src/api/landing.ts`

**Task 9 — CRM inbox**
- Create: `spa/src/api/inquiries.ts`, `spa/src/types/inquiries.ts`, `spa/src/pages/crm/inquiries/index.tsx`, `pages/crm/inquiries/detail.tsx`
- Create: `api/app/Modules/CRM/Controllers/ContactInquiryInboxController.php`
- Modify: `spa/src/routes/crmRoutes.tsx`, `spa/src/components/layout/Sidebar.tsx:129`, `api/database/seeders/RolePermissionSeeder.php:284`

**Task 10 — convert to lead**
- Modify: `api/app/Modules/CRM/Services/LeadService.php`, `ContactInquiryInboxController.php`, `spa/src/pages/crm/inquiries/detail.tsx`, `spa/src/api/inquiries.ts`
- Create: `api/tests/Feature/CRM/ConvertInquiryToLeadTest.php`

---

## The Mapping Table

Tasks 2–4 apply exactly this. It is mechanical; do not improvise a different target.

| Old class fragment | New class fragment | Refs |
|---|---|---|
| `landing-canvas` | `canvas` | 39 |
| `landing-surface` | `surface` | 22 |
| `landing-elevated` | `elevated` | 17 |
| `landing-subtle` | `subtle` | 0 |
| `landing-border` | `border-default` | 53 |
| `landing-border-strong` | `border-strong` | 16 |
| `landing-text` | `primary` | 69 |
| `landing-text-secondary` | `secondary` | 29 |
| `landing-muted` | `muted` | 45 |
| `landing-subtle-text` | `text-subtle` | 33 |
| `landing-accent` | `accent` | 154 |
| `landing-accent-hover` | `accent-hover` | 5 |
| `landing-accent-fg` | `accent-fg` | 11 |
| `landing-accent-soft` | `accent/10` | 0 |
| `landing-accent-glow` | `accent/20` | 3 |
| `text-landing-text-menu` | `text-primary` | 1 |

CSS variables referenced directly in `style={{}}` or `getComputedStyle`:

| Old var | New var |
|---|---|
| `--landing-ink` | `--text-primary` |
| `--landing-line` | `--blueprint-line` |
| `--landing-grid` | `--blueprint-grid` |
| `--landing-grid-size` | `--blueprint-grid-size` |

Border classes: the codebase writes **`border border-default`** (461 uses; the doubled `border-border-default` appears zero times). So `border-landing-border` → `border-default`, and `border-landing-border-strong` → `border-strong`. Same for the `divide-`/`ring-` variants.

---

### Task 1: Add the `--blueprint-*` tokens and their Tailwind map

Both namespaces coexist after this task. Nothing is deleted yet, so nothing can break.

**Files:**
- Modify: `spa/src/styles/tokens.css` (append a `--blueprint-*` block after the `--landing-*` block, ~line 112)
- Modify: `spa/tailwind.config.ts` (add a `blueprint` colour group beside `landing`, ~line 87)
- Test: `spa/src/styles/__tests__/blueprint-tokens.test.ts`

**Interfaces:**
- Produces: CSS custom properties `--blueprint-grid`, `--blueprint-line`, `--blueprint-grid-size`; Tailwind classes `bg-blueprint-grid`, `border-blueprint-line`, `text-blueprint-line`. Tasks 2–5 consume these.

- [ ] **Step 1: Write the failing test**

Create `spa/src/styles/__tests__/blueprint-tokens.test.ts`:

```ts
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, it, expect } from 'vitest';

const tokens = readFileSync(join(process.cwd(), 'src/styles/tokens.css'), 'utf8');

describe('blueprint tokens', () => {
  it('declares all three, derived from Atelier ink', () => {
    expect(tokens).toContain('--blueprint-grid:');
    expect(tokens).toContain('--blueprint-line:');
    expect(tokens).toContain('--blueprint-grid-size:');
  });

  it('derives grid and line from --text-primary, never a literal', () => {
    const grid = /--blueprint-grid:\s*([^;]+);/.exec(tokens)?.[1] ?? '';
    const line = /--blueprint-line:\s*([^;]+);/.exec(tokens)?.[1] ?? '';
    expect(grid).toContain('var(--text-primary)');
    expect(line).toContain('var(--text-primary)');
    expect(grid).not.toMatch(/#[0-9a-fA-F]{6}/);
    expect(line).not.toMatch(/#[0-9a-fA-F]{6}/);
  });

  it('declares them once, in :root only — they follow ink per theme', () => {
    expect(tokens.match(/--blueprint-grid:/g)).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd spa && npx vitest run src/styles/__tests__/blueprint-tokens.test.ts`
Expected: FAIL — `expected '…' to contain '--blueprint-grid:'`

- [ ] **Step 3: Add the tokens**

In `spa/src/styles/tokens.css`, immediately after the closing of the `--landing-*` light block (before the `}` that ends `:root`):

```css
  /* ─── Blueprint line-work — landing + auth technical register.
         Derived from ink, so they follow every palette automatically.
         These are the ONLY landing-scoped tokens permitted. ─── */
  --blueprint-grid: color-mix(in srgb, var(--text-primary) 5%, transparent);
  --blueprint-line: color-mix(in srgb, var(--text-primary) 14%, transparent);
  --blueprint-grid-size: 32px;
```

Do **not** redeclare them under `[data-theme='dark']` or `[data-theme='floor']` — `--text-primary` already differs per palette, so the `color-mix` re-resolves for free. A redeclaration would be the `dark:`-variant mistake in CSS form.

- [ ] **Step 4: Add the Tailwind map**

In `spa/tailwind.config.ts`, immediately before the `landing: {` group:

```ts
        // Blueprint line-work — survives the --landing-* retirement.
        blueprint: {
          grid: 'color-mix(in srgb, var(--blueprint-grid) calc(<alpha-value> * 100%), transparent)',
          line: 'color-mix(in srgb, var(--blueprint-line) calc(<alpha-value> * 100%), transparent)',
        },
```

And in the same file's `theme.extend`, add the grid-size spacing key so background sizing reads the token:

```ts
      backgroundSize: {
        blueprint: 'var(--blueprint-grid-size) var(--blueprint-grid-size)',
      },
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd spa && npx vitest run src/styles/__tests__/blueprint-tokens.test.ts`
Expected: PASS (3 tests)

- [ ] **Step 6: Verify nothing regressed**

Run: `cd spa && npm run typecheck && npm run build && npm run audit:tokens`
Expected: all clean. The build must succeed — a malformed `tailwind.config.ts` fails here, not in the unit test.

- [ ] **Step 7: Commit**

```bash
git add spa/src/styles/tokens.css spa/tailwind.config.ts spa/src/styles/__tests__/blueprint-tokens.test.ts
git commit -m "feat: add --blueprint-* tokens derived from Atelier ink"
```

### Task 2: Retheme the landing layout contract and shared components

`styles.ts` is the highest-leverage file — every section composes it. Two design-system violations get fixed here at the same time, because they live in the same lines.

**Files:**
- Modify: `spa/src/pages/landing/styles.ts`
- Modify: `spa/src/pages/landing/components/` — all 15 files, notably `BackToTop.tsx:32`, `LandingNav.tsx`, `LandingFooter.tsx`, `CookieBanner.tsx`, `PartBlueprint.tsx`, `ProfileSilhouette.tsx`, `AutoPartShowcase.tsx`, `CrosshairCursor.tsx`, `ScrollProgress.tsx`, `ScrambleText.tsx`, `SectionHeading.tsx`, `DatumMark.tsx`, `PlantMap.tsx`
- Modify: `spa/src/pages/landing/LandingPage.tsx` — delete the `WARM_ACCENT` remap at `:47-69`
- Test: `spa/src/pages/landing/__tests__/styles-contract.test.ts`

**Interfaces:**
- Consumes: `--blueprint-*` tokens and the `blueprint` Tailwind group from Task 1.
- Produces: `section()`, `card()`, `container`, `sectionPadX`, `sectionPadY`, `headingGap`, `cardGap`, `monoLabel` — same exported names and signatures as today. Task 3 consumes these unchanged. **Do not change any signature**; only the class strings inside change.

- [ ] **Step 1: Write the failing test**

Create `spa/src/pages/landing/__tests__/styles-contract.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { section, card, container, monoLabel } from '../styles';

describe('landing layout contract on Atelier tokens', () => {
  it('emits no --landing-* classes', () => {
    const all = [section(), section('surface'), card(), card('interactive'), container, monoLabel].join(' ');
    expect(all).not.toMatch(/landing-/);
  });

  it('uses Atelier surface roles', () => {
    expect(section('surface')).toContain('bg-surface');
    expect(section('surface')).toContain('border-default');
    expect(section()).toContain('bg-canvas');
    expect(card()).toContain('bg-surface');
  });

  it('does not lift or shadow cards on hover — DESIGN-SYSTEM.md forbids both', () => {
    const interactive = card('interactive');
    expect(interactive).not.toMatch(/-translate-y/);
    expect(interactive).not.toMatch(/shadow-2xl/);
    expect(interactive).toContain('hover:border-accent');
  });

  it('keeps the signatures Task 3 depends on', () => {
    expect(typeof section).toBe('function');
    expect(card('static', 'extra')).toContain('extra');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd spa && npx vitest run src/pages/landing/__tests__/styles-contract.test.ts`
Expected: FAIL — `expected '… border-landing-border …' not to match /landing-/`

- [ ] **Step 3: Rewrite the two class strings in `styles.ts`**

Replace the body of `section()` (currently lines 39-47):

```ts
  return cn(
    'relative',
    sectionPadX,
    sectionPadY,
    background === 'surface'
      ? 'border-y border-default bg-surface'
      : 'bg-canvas',
    className,
  );
```

Replace the body of `card()` (currently lines 65-70). The hover lift and `shadow-2xl` are removed — Atelier carries hierarchy on borders, and `docs/DESIGN-SYSTEM.md` lists "card hover lift" under **Never**:

```ts
  return cn(
    'relative rounded-lg border border-default bg-surface p-6 sm:p-8',
    variant === 'interactive' &&
      'transition-colors duration-normal hover:border-accent/50',
    className,
  );
```

Note `rounded-2xl` → `rounded-lg`: Atelier's `lg` is 14px, and the spec's form language caps radius there. Update `monoLabel`'s `text-landing-muted` → `text-muted`, and drop the `text-[11px]` literal in favour of `text-xs` (11px in the Atelier scale).

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd spa && npx vitest run src/pages/landing/__tests__/styles-contract.test.ts`
Expected: PASS (4 tests)

- [ ] **Step 5: Apply the mapping table to the 15 component files**

Work file by file, not with a blanket `sed` — three sites need judgement and a global replace will corrupt them:

1. `BackToTop.tsx:32` — `text-landing-text-menu` is a **dead class**: no `landing.text-menu` key exists in the Tailwind map, so the button currently has no text colour and inherits. Replace with `text-primary`.
2. `LandingPage.tsx:47-69` — delete the `WARM_ACCENT` constant and the inline `style={WARM_ACCENT}` that applies it. Landing now consumes the shared clay `--accent` directly. Keep the `id`, `ref`, and all `inertWhen(...)` handling exactly as-is.
3. Any `style={{ backgroundImage: ... }}` grid using `--landing-grid` / `--landing-grid-size` → `--blueprint-grid` / `--blueprint-grid-size`.

Everything else is the mechanical mapping. For each file:

```bash
cd spa && grep -n "landing-" src/pages/landing/components/<FILE>.tsx
```

then apply the table. After each file, re-run `grep -c "landing-" ` on it and expect `0`.

- [ ] **Step 6: Verify the batch**

Run: `cd spa && grep -rn "landing-" src/pages/landing/components src/pages/landing/styles.ts src/pages/landing/LandingPage.tsx`
Expected: no output except `LandingNav.tsx` lines `238` and `249`, which are the DOM id `landing-mobile-menu` (an `aria-controls` target, not a token — leave both).

Run: `cd spa && npm run typecheck && npx vitest run src/pages/landing`
Expected: clean; all landing tests pass.

- [ ] **Step 7: Commit**

```bash
git add spa/src/pages/landing/styles.ts spa/src/pages/landing/components spa/src/pages/landing/LandingPage.tsx spa/src/pages/landing/__tests__/styles-contract.test.ts
git commit -m "feat: retheme landing layout contract and components onto Atelier tokens"
```

---

### Task 3: Retheme the nine landing sections

**Files:**
- Modify: `spa/src/pages/landing/sections/` — `HeroSection.tsx`, `ProcessSection.tsx`, `StatsSection.tsx`, `QualitySection.tsx`, `PhilippinesSection.tsx`, `MarqueeSection.tsx`, `CapabilitiesSection.tsx`, `PartShowcaseSection.tsx`, `ContactSection.tsx`
- Modify: `spa/src/pages/landing/three/PartShowcase3D.tsx:84`

**Interfaces:**
- Consumes: `section()`, `card()`, `container`, `monoLabel` from Task 2 — signatures unchanged.
- Produces: nothing new. Section component names and props are untouched.

- [ ] **Step 1: Apply the mapping table, section by section**

No layout, GSAP, or choreography changes — class strings only. Section order, scroll triggers, and `useInView` thresholds stay exactly as they are. The spec's non-goals list these components by name as structurally frozen.

For each of the nine files: `grep -n "landing-" src/pages/landing/sections/<FILE>.tsx`, apply the table, confirm `0` remaining.

- [ ] **Step 2: Point the WebGL colour read at Atelier ink**

`spa/src/pages/landing/three/PartShowcase3D.tsx:84` currently reads:

```ts
      getComputedStyle(container).getPropertyValue('--landing-ink').trim() || '#1c1917';
```

Change the variable to `--text-primary`. Leave the hex fallback in place for now — Task 5 relocates it.

```ts
      getComputedStyle(container).getPropertyValue('--text-primary').trim() || '#1c1917';
```

- [ ] **Step 3: Verify no landing tokens survive in sections**

Run: `cd spa && grep -rn "landing-" src/pages/landing/sections src/pages/landing/three`
Expected: no output.

- [ ] **Step 4: Run the checks**

Run: `cd spa && npm run typecheck && npx vitest run && npm run build`
Expected: all clean.

- [ ] **Step 5: Screenshot the result before moving on**

Run: `cd spa && npm run test:defense`
Expected: completes; writes to `docs/defense-screenshots/`. Open the landing captures and confirm the clay accent reads correctly at full-page scale. This is the spec's largest visible change and its stated top risk — look at it before building on top of it.

- [ ] **Step 6: Commit**

```bash
git add spa/src/pages/landing/sections spa/src/pages/landing/three
git commit -m "feat: retheme landing sections onto Atelier tokens"
```

<!-- PLAN-CONT-1 -->
