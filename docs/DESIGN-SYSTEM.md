# OGAMI ERP — Design System

> Exact visual spec. Brand: **Atelier**.
> Source of truth for values is `spa/src/styles/tokens.css`. If this document and
> that file disagree, the file wins — and this document is the bug.

## PHILOSOPHY

**Warm paper, espresso ink, one clay accent.** The canvas is a warm off-white, not
pure grey, and the accent is a muted terracotta. Semantics are hued to match the
brand — moss, ochre, oxide, slate blue, plum — rather than borrowed from Tailwind's
defaults. Generic `#10b981 / #f59e0b / #ef4444 / #3b82f6` is a large part of what
makes an interface read as templated.

**Surfaces are opaque.** No translucency, no `backdrop-blur`. Hierarchy comes from
hairline borders. Shadows exist only for true overlays — menu, modal, toast — and
are warm-tinted, never neutral black.

**Information density over whitespace.** Real ERPs show more data per screen than
web apps, with enough separation for long work sessions. Body text is 14px and
data tables offer Comfortable, Compact, and Spacious density per user.

**Serif for display, sans for interface.** Instrument Serif carries page titles and
gives the product its voice. It ships at weight 400 only — never apply a weight
class to it, or the browser synthesises a bold and it looks smeared.

## COLOR TOKENS

Defined in `spa/src/styles/tokens.css`, mapped into Tailwind by
`spa/tailwind.config.ts`. Components name **roles** (`bg-accent`, `text-danger`,
`border-default`), never colours. This indirection is what let 355 pages restyle
during the Atelier rebrand without being edited.

Three palettes, not four:

| Palette | Selector | Used by |
|---|---|---|
| Light | `:root` | all office routes |
| Dark | `[data-theme="dark"]` | all office routes |
| Floor | `[data-theme="floor"]` | factory / maintenance-mobile PWAs |

Floor is **route-forced** by `components/layout/TouchShell.tsx` and is never
user-selectable. See `stores/themeStore.ts` (`pushOverride` / `popOverride`).

### Light — office

```css
:root {
  /* Canvas — warm paper */
  --bg-canvas:      #fdfcfa;  /* page background */
  --bg-surface:     #f7f4ef;  /* cards, metric tiles, right panels */
  --bg-elevated:    #f2ede4;  /* modals, dropdowns, hover states */
  --bg-subtle:      #f5f1e9;  /* inactive chips */

  /* Table zebra & header */
  --bg-zebra-odd:   transparent;
  --bg-zebra-even:  #f7f4ef;
  --bg-row-hover:   #f2ede4;
  --bg-thead:       #f7f4ef;

  /* Borders */
  --border-subtle:  #f0eae0;  /* row dividers inside tables */
  --border-default: #e8e2d8;  /* card borders, section dividers */
  --border-strong:  #d6cec1;  /* emphasized borders */

  /* Text — espresso ink */
  --text-primary:   #1f1b16;
  --text-secondary: #4a4239;
  --text-muted:     #6b6259;
  --text-subtle:    #6f665d;  /* placeholders, disabled — decorative only */

  /* Accent — clay */
  --accent:         #b4542a;
  --accent-hover:   #96461f;
  --accent-fg:      #fdfcfa;

  /* Links — Atelier permits an accent link; the old system forbade colour */
  --text-link:      #b4542a;
  --text-link-hover:#96461f;

  /* Semantic — brand-hued */
  --success: #3f6d54;  --success-bg: #e3ece7;  --success-fg: #2c4e3b;  /* moss  */
  --warning: #b07a22;  --warning-bg: #f7eedc;  --warning-fg: #7a5314;  /* ochre */
  --danger:  #a8392f;  --danger-bg:  #f6e4e1;  --danger-fg:  #7a2820;  /* oxide */
  --info:    #3d5a80;  --info-bg:    #e3e9f0;  --info-fg:    #2a4059;  /* slate */
  --purple:  #75558c;  --purple-bg:  #ede7f1;  --purple-fg:  #4c3862;  /* plum  */

  --ring: #b4542a;
  --ring-offset: #fdfcfa;

  /* Density — floor overrides all three */
  --row-height: 36px;
  --row-height-compact: 36px;
  --row-height-comfortable: 48px;
  --row-height-spacious: 56px;
  --hit-min: 28px;
  --font-size-body: 14px;
}
```

### Dark — office

Espresso, not black. Semantic `-bg` values are **opaque**, pre-composited at ~18%
of the hue over canvas. Alpha would defeat the contrast gate, which cannot resolve
`rgba()` against an unknown backdrop.

```css
[data-theme='dark'] {
  --bg-canvas:      #17140f;
  --bg-surface:     #1f1b16;
  --bg-elevated:    #2a251e;

  --border-subtle:  #241f19;
  --border-default: #332c24;
  --border-strong:  #4a4137;

  --text-primary:   #f5f1ea;
  --text-secondary: #d6cec1;
  --text-muted:     #a89f93;
  --text-subtle:    #a89f93;

  --accent:         #d97848;
  --accent-hover:   #e8926a;
  --accent-fg:      #17140f;

  --success: #6fa688;  --success-bg: #272e25;  --success-fg: #9ed2b4;
  --warning: #d9a441;  --warning-bg: #3a2e18;  --warning-fg: #f0cb86;
  --danger:  #d9645a;  --danger-bg:  #3a221d;  --danger-fg:  #f0a9a2;
  --info:    #7a9bc4;  --info-bg:    #292c30;  --info-fg:    #b6cde6;
  --purple:  #a98cc2;  --purple-bg:  #312a2f;  --purple-fg:  #d3c2e4;
}
```

Dark deliberately does **not** redeclare the density tokens — it inherits `:root`
through the cascade rather than duplicating identical values.

### Floor — shop floor

Same clay identity, contrast budget raised. A tablet under fluorescent light, held
in a glove. Clay is pushed to safety-orange and every semantic hue clears AAA.

```css
[data-theme='floor'] {
  --bg-canvas:      #0d0b08;
  --bg-surface:     #17140f;
  --bg-elevated:    #241f19;

  --border-default: #4a4137;
  --border-strong:  #6b6156;

  --text-primary:   #fffdf8;
  --text-secondary: #e8e2d8;
  --text-muted:     #b8afa2;  /* lifted from dark's #a89f93 to clear AAA */
  --text-subtle:    #9a9186;  /* lifted from dark's #a89f93 */

  --accent:         #ff8a4c;
  --accent-fg:      #0d0b08;

  --success: #4ade80;  --warning: #fbbf24;  --danger: #f87171;
  --info:    #93c5fd;  --purple:  #c4b5fd;

  /* The only palette that raises these */
  --row-height: 48px;
  --hit-min: 44px;
  --font-size-body: 15px;
}
```

`--text-muted` and `--text-subtle` are lighter here than in dark mode for a
measured reason: the dark values cleared the canvas but failed against
`--bg-elevated`, the lightest floor surface, at floor's AAA bar (6.26 and 4.23).
The contrast gate caught it.

### Categorical chart series — `--chart-1..8`

The semantic tokens answer *"is this good or bad?"*. A chart series asks a
different question — *"which one is this?"* — so it has its own eight tokens:

```
1 clay    2 ochre   3 olive   4 jade
5 teal    6 indigo  7 violet  8 wine
```

Fixed order, same hue roles in all three palettes, so series 3 stays series 3
when the user switches theme. Values differ per palette because contrast is
measured against that palette's canvas, not chosen by eye.

**Identity is not state.** Painting the third bar of a breakdown with
`--danger` tells the reader that bar failed at something; it did not — it is
simply third. Use a `--chart-*` token for a category and a semantic token only
when the colour *means* something (present/late/absent, pass/fail).

```tsx
import { chartColor } from '@/lib/chartPalette';

// One colour per series, in palette order.
{stages.map((stage, i) => <Cell key={stage.name} fill={chartColor(i)} />)}

// BarComparison and DonutBreakdown default to this when no colour is passed,
// so a new chart has no reason to name one.
<DonutBreakdown data={stages} />
```

The palette is **red-green safe by measurement, not by convention**. The gate
in `spa/src/styles/__tests__/palette-contrast.test.ts` holds every pair to all
of:

| Check | Bar |
|---|---|
| Mark contrast on canvas (WCAG 1.4.11) | 3:1 office · 4.5:1 floor |
| Pairwise difference, normal vision | ΔE ≥ 20 (CIE76) |
| Pairwise difference, simulated deuteranopia | ΔE ≥ 10 |
| Pairwise difference, simulated protanopia | ΔE ≥ 10 |
| Not a semantic token under another name | no `--chart-n` equals `--success`/`--warning`/`--danger`/`--info`/`--purple` |

That is why the eight values vary in **lightness** as much as in hue: red-green
deficiency collapses hue, so hue alone would leave a legend of near-identical
swatches. Still, colour is never the only carrier — every chart here draws a
legend or a direct label.

### Out of scope: `--landing-*`

The landing page keeps its own namespace and has **not** been re-authored in
Atelier. It is exempt from the token-discipline gate. That work is a separate
track.

## CONTRAST — enforced, not aspirational

`spa/src/styles/__tests__/palette-contrast.test.ts` reads `tokens.css` from disk
and asserts every text/background pair on every palette. It runs as part of
`npm run test:run`.

| Role | Office (light/dark) | Floor |
|---|---|---|
| `--text-primary`, `--text-secondary`, `--text-muted`, all `-fg` | AA 4.5:1 | AAA 7:1 |
| `--text-subtle` (decorative — placeholders, disabled captions) | AA-large 3:1 | AA 4.5:1 |
| Semantic marks on canvas (chips, bars, icon strokes — WCAG 1.4.11) | 3:1 | 4.5:1 |
| `--chart-1..8` on canvas, **plus** pairwise ΔE (see above) | 3:1 | 4.5:1 |

**Never lower a threshold to make the gate pass.** Darken or lighten the token.

## TYPOGRAPHY

Faces are self-hosted via `@fontsource` and imported once in `src/main.tsx` — not
from a CDN, which keeps `font-src 'self'` in the CSP.

```css
--font-sans:    'Public Sans Variable', -apple-system, system-ui, sans-serif;
--font-mono:    'Spline Sans Mono Variable', 'SF Mono', Menlo, monospace;
--font-display: 'Instrument Serif', Georgia, 'Times New Roman', serif;
```

Those family strings must match exactly what `@fontsource` registers. A typo fails
silently — the browser falls back and nothing errors.

### Type scale

| Name | Utility | Size | Line height | Usage |
|---|---|---:|---:|---|
| Page title | `text-page-title` | 28px | 36px | One `h1`, Instrument Serif |
| Section title | `text-section-title` | 20px | 28px | `h2` and page sections |
| Subsection | `text-subsection` | 16px | 24px | `h3` and panel titles |
| Body | `text-body` | 14px | 1.5 | Main copy and field values |
| Body small | `text-body-small` | 14px | 19px | Secondary descriptions |
| Caption | `text-caption` | 13px | 18px | Supporting metadata |
| Label | `text-label` | 14px | 18px | Form and control labels |
| Table header | `text-table-header` | 14px | 18px | Table headings |
| Table cell | `text-table-cell` | 14px | 20px | Table values |
| Code / ID | `text-code` | 14px | 18px | Genuine references and IDs |

The scale values and line heights come from `--font-size-*` and
`--line-height-*` tokens. Compact table cells may use the documented 13px
minimum; other body and table text stays at 14px or larger.

### Page titles — serif

`components/layout/PageHeader.tsx` renders the `h1` on essentially every page:

```tsx
<h1 className="font-display text-page-title text-primary break-words">
```

No weight class — the family is 400-only. Subtitles, breadcrumbs and actions stay
on `font-sans`; serif in small UI text reads as a mistake.

### Numbers, IDs, dates

```tsx
<span className="tabular-nums">₱ 486,500.00</span>
<span className="font-mono tabular-nums">PO-202604-0015</span>
```

Use Public Sans with tabular figures for quantities, money, and dates. Reserve
monospace for IDs, codes, and references.

## SPACING (4px base scale)

```
0.5 = 2px    1 = 4px    1.5 = 6px    2 = 8px
2.5 = 10px   3 = 12px   4 = 16px     5 = 20px
6 = 24px     8 = 32px   10 = 40px    12 = 48px
```

- Inside cells, chips, badges: 2–8px padding
- Between form fields: 12px
- Between sections: 16–20px

## PAGE LAYOUT PRIMITIVES

- `PageShell` owns a route's responsive gutter and supports `fluid`, `grid`, and
  `narrow` variants. `PageContainer` applies the same width contract to a
  content region. Data pages stay fluid; narrow prose uses the `72ch` token.
- `FormGrid` starts with one column, grows to two at 768px, three at 1440px,
  and four at 1920px when requested. Its gap comes from `--form-grid-gap`.
- `DetailLayout` keeps main content and an optional aside in one responsive
  grid; the aside moves below main content on narrow screens.
- `Toolbar` owns start/end alignment. A page composed inside `PageShell` turns
  component insets off so the shell supplies the shared responsive gutter;
  legacy pages retain their existing inset until Phase 4 migration. `FilterBar`
  and the table toolbar compose it; `PageHeader`, `Panel`, `DataTable`, and
  `TablePagination` remain the shared primitives for their jobs.
- `npm run audit:layout` rejects newly added arbitrary size/spacing values and
  centered max-width wrappers in route files. Changed inventory pages must use
  a shared shell or document a special-layout exception. Existing layout debt
  remains for the Phase 4 route migrations.
- Page gutters: 16px below 640px, 24px from 640–1439px, 32px at 1440px+
- Card padding: 16–24px by surface and viewport

## BORDER RADIUS

```css
--radius-sm:   8px;    /* chips, small elements */
--radius-md:  10px;    /* DEFAULT — buttons, inputs, cards */
--radius-lg:  14px;    /* modals, large panels */
--radius-full: 9999px; /* avatars, full-round badges */
```

Softer than the old 4/6/8 without becoming bubbly. Use `md` unless there's a
reason not to.

## BORDERS

Hairline. Real 0.5px on retina via `.border-hairline`, 1px elsewhere.

```css
border: 1px solid var(--border-default);
```

Borders carry hierarchy — this is what replaced glassmorphism. Never thicker than
1px except the focus ring.

## FOCUS RING

```css
outline: 2px solid var(--ring);   /* clay */
outline-offset: 2px;
```

Never remove focus styles.

## SHADOWS

Overlays only, and warm-tinted.

```css
--shadow-focus: 0 0 0 4px rgba(180, 84, 42, 0.18);
--shadow-menu:  0 16px 32px -8px rgba(31, 27, 22, 0.14),
                0 8px 16px -4px rgba(31, 27, 22, 0.09);
```

No shadows on cards, buttons, or panels. They sit flat with a hairline border.
Neutral-black shadows on a warm canvas read as dirt.

## DENSITY TOKENS

```
h-row        → var(--row-height)          36px office · 48px floor
h-row-compact → var(--row-height-compact)  36px office · 44px floor
h-row-comfortable → var(--row-height-comfortable) 48px
h-row-spacious → var(--row-height-spacious) 56px
min-h-hit    → var(--hit-min)      44px touch target
min-w-hit    → var(--hit-min)
```

Components read touch targets unconditionally. `DataTable` defaults to Compact
and offers Comfortable and Spacious. Density persists per authenticated account
and table key.

## ANIMATIONS

```css
--duration-fast:   150ms;
--duration-normal: 250ms;
--duration-slow:   400ms;
--ease-default: cubic-bezier(0.16, 1, 0.3, 1);
```

**Allowed:** button press `scale(0.98)`, dropdown fade + slide, modal fade +
slide-up, skeleton shimmer, progress fill, status colour transition, toast slide-in.

**Never:** card hover lift, KPI count-up, bouncy easing, page-load fade, row
entrance animations.

All animations respect `prefers-reduced-motion: reduce`.

---

## LAYOUT SYSTEM

### Global shell

```
┌──────────────────────────────────────────────────────────────┐
│ Topbar (48px, sticky, hairline bottom border)                │
├──────┬───────────────────────────────────────────────────────┤
│ Side │                                                        │
│ bar  │ Page content                                           │
│ 240  │                                                        │
│  or  │                                                        │
│  56  │                                                        │
└──────┴───────────────────────────────────────────────────────┘
```

### Topbar (48px)

- Hairline bottom border `--border-default`, opaque `--bg-canvas` (no blur)
- Padding: 0 16px
- Breadcrumbs `text-sm text-muted`, active segment `text-primary font-medium`
- Search trigger 180px with `⌘K` hint · theme toggle 30px · avatar 28px

### Sidebar

**Expanded (240px)** on ≥1280px or when the user expands it:

- Hairline right border, padding 12px 0
- Section label: 10px uppercase, tracking 0.08em, `--text-subtle`, padding 6px 16px
- Nav item: 13px, 6px/16px padding, `--text-secondary`
- Active: `--text-primary`, `--bg-elevated`, 2px left border `--accent`, weight 500
- Hover: `--bg-elevated`

**Rail (56px)** below 1280px or collapsed: 16px Tabler Icons, 36px square targets,
`rounded-md`, 2px clay indicator on the active item. Hover widens the rail in flow
after 150ms when `sidebar_peek` is enabled; keyboard focus reveals labels immediately.
No rail tooltips, grid texture, or decorative section dividers.

Modules follow the active route. Inactive modules start collapsed; opening an
unvisited module peeks for five seconds. Visiting it keeps it open until navigation
changes modules or the user collapses it. A fixed footer holds one toggle below the scrolling module list: **Expand all**
opens every visible module and cancels pending peek timers; once all modules
are open, **Collapse all** returns to only the active module. **Ctrl+.** toggles the desktop rail or mobile drawer.

### Icons

The SPA uses `@tabler/icons-react` through `spa/src/lib/icons.ts`. Tabler's
consistent 24px outline grid matches the Atelier interface and gives feature
areas precise concepts instead of generic symbols: deliveries use trucks,
receiving uses package-import, invoices use file-invoice, quality work uses
clipboard-check, and warehouse maps use map. Prefer the most specific familiar
icon for the destination or action; do not use an icon only because its shape
looks similar. Keep icon-only controls labelled and inherit colour from the
surrounding semantic text token.

### Shop-floor PWAs

Factory and maintenance-mobile render through
`components/layout/TouchShell.tsx` — one shell, two sets of props. No sidebar.
`TouchShell` forces `[data-theme="floor"]` on mount and restores the user's own
preference on unmount.

The driver surface (`/driver`) is not a shop-floor PWA: it renders inside
`AppLayout` with the standard sidebar and topbar, follows the user's light/dark
preference, and keeps 44px touch controls (`size="touch"`) because the phone is
still where most of its use happens.

---

## CORE COMPONENTS

### Button

Variants: `primary`, `secondary` (default), `danger`, `ghost`. Sizes: sm 28px,
md 32px, lg 36px.

```tsx
// Primary — clay filled
<Button variant="primary">Edit Order</Button>
// Secondary — hairline border on transparent
<Button variant="secondary">Export</Button>
```

Radius `md`, press `scale(0.98)`, focus ring always visible when tabbed.

### Input

32px tall, 0 12px padding, hairline border, clay focus ring, label above at 11px
muted, error below at 11px `text-danger`.

### Status chip

The most-used component in the system. Every variant uses its `-bg`/`-fg` **pair** —
never `-DEFAULT` on canvas, which is the pairing the contrast gate asserts.

```tsx
const chipVariants = {
  success: 'bg-success-bg text-success-fg',
  warning: 'bg-warning-bg text-warning-fg',
  danger:  'bg-danger-bg  text-danger-fg',
  info:    'bg-info-bg    text-info-fg',
  neutral: 'bg-subtle     text-muted',
};
```

**Status → variant mapping** (see `chipVariantForStatus` in `ui/Chip.tsx`):

| Status | Variant |
|---|---|
| completed, approved, active, passed, running, paid | success |
| in_production, in_progress, processing, scheduled | info |
| pending, draft, queued, idle, setup | warning |
| rejected, failed, breakdown, overdue, urgent, material_short | danger |
| cancelled, inactive, closed | neutral |

### Data table

- Row height: Compact 36px default, Comfortable 48px, Spacious 56px
- Header: 14px sentence-case Public Sans, semibold, `--bg-thead`
- Sticky header: `--bg-thead` **plus a bottom border** — there is no blur to separate it
- Cell padding: 12px horizontal; vertical padding follows density
- Row separator: hairline `--border-subtle`; hover `--bg-row-hover`
- Zebra: alternating rows use `--bg-zebra-even`
- Numbers and dates: Public Sans with `tabular-nums`; numeric values right-aligned
- Selected row outline: `outline-accent` (**not** a landing token)

Table tokens (`--bg-thead`, `--bg-zebra-*`, `--bg-row-hover`) are not mapped as
Tailwind colours — consume them as `bg-[var(--bg-thead)]`.

### Metric / KPI card

Padding 14–16px, `--bg-surface`, hairline border, label 10px uppercase muted,
value 26px mono medium, delta 11px mono in `--success` / `--danger`.

26px is the design size, not a fixed one: the figure is sized against **its own
card** (`@container` + `clamp(1rem, 12cqw, 1.625rem)`), because a two-up grid at
375px leaves 132px of content and `₱576,912.00` measures 175px there. The step
follows the card, so the same component is correct in a 4-up dashboard and a
two-up phone layout. Every digit stays visible — it wraps inside the card rather
than truncating or widening the page (F-009).

### Panel

Hairline border, header 12px/16px padding with bottom border, title 14px medium,
right-side meta 11px muted.

Both flex links in that header carry `min-w-0`, so a wide `meta` control (a status
filter with a lot of options) wraps or scrolls inside the slot instead of growing
the panel past its column and having `overflow-hidden` cut the options off
unreachably (F-010). An over-wide child that is not a control should opt into
`Panel scrollBody` rather than be clipped.

### Dialog (Modal) and the phone action surface

`Modal` is the only dialog in the system — 214 call sites, one implementation.
It chooses its own presentation:

| Viewport | Presentation | Shape |
|---|---|---|
| ≥ 640px (`sm`) | `dialog` | Centred card, `max-w-sm…4xl`, 20px body padding, rounded all corners |
| < 640px | `sheet` | Edge to edge, anchored to the bottom, top corners only, header always present, sticky footer over the safe area |

No page opts in, and no page should re-implement the branch:

```tsx
// Correct at every width — this is the whole API.
<Modal isOpen={open} onClose={close} title="Return unused part" size="sm">
  {fields}
  <ModalFooter>
    <Button variant="ghost">Cancel</Button>
    <Button>Confirm</Button>
  </ModalFooter>
</Modal>

// Escape hatches, both rare: force one presentation against the viewport.
<Modal presentation="sheet" …>
<Modal presentation="dialog" …>
```

In the sheet, `ModalFooter` is sticky and padded for `env(safe-area-inset-bottom)`,
and **the sheet surface itself** puts the 44px touch floor on every `button`/`a`
inside it (`[&_button]:min-h-11`). The floor is deliberately on the surface rather
than on the shared footer: `ConfirmDialog` — 150 call sites across 79 files, the
confirm/revoke/delete button of the system — hand-rolls its action row instead of
using `ModalFooter`, and a footer-only rule left exactly those buttons at 32px on a
phone. The dialog surface at `sm` and
above is untouched, so desktop density does not change.

The header is always rendered in sheet mode, so even a titleless dialog has a
named close button: on a phone there is no visible backdrop to tap and no Esc key.

`BottomSheet` still exists for surfaces that are phone-only by design (the
self-service and shop-floor flows). It is now a thin wrapper over
`Modal presentation="sheet"` — same focus trap, same topmost-only Esc stack, same
scroll lock. It previously had none of the focus trapping the dialog had.

### Component gallery — the live reference

`/admin/component-gallery` (Administration → Component Gallery) renders every
shared primitive with all of its states: button variants and sizes, input states,
chips, panels, KPI cards, selection controls, tables, the record patterns, and
interactive overlays. It fetches nothing and uses literal fixtures only, which is
what makes it photographable.

It is also the subject of the visual baseline gate:

```bash
cd spa
npm run test:gallery          # compare against the committed baselines
npm run test:gallery:update   # re-baseline — review the PNGs, then say why
```

16 screenshots (8 sections × 1280 and 390) live in
`spa/e2e/__screenshots__/gallery.spec.ts/`, run in CI beside the axe gate, and
contain a **negative control** that plants a visible change and requires the
comparison to catch it. A padding step of one Tailwind unit on `StatCard` moves
the panels section from 780 to 819px tall — 1% of its pixels — so "the section
looks the same" is not enough for a shared component to pass. Adding a primitive
to the kit means adding it to the gallery in the same change.

---

## CHAIN PROCESS COMPONENTS (the thesis differentiator)

### ChainHeader — horizontal process timeline

On detail pages (Sales Order, Purchase Order, Work Order). Shows the full chain and
current position.

- Done: 9px moss dot, hairline outline
- Active: 9px clay dot, label `text-primary` bold
- Pending: 9px grey dot, label `text-subtle`
- Connector: 1px, moss when done, grey when pending
- Labels 11px medium, 10px mono date beneath

### StageBreakdown — vertical stage counts

Dashboards. Label + count on one line, 4px progress bar beneath on `--bg-subtle`,
fill coloured by stage status, 10px between rows.

### LinkedRecords — related records

Right panel of detail pages, grouped by type. `--bg-surface`, hairline left border,
group label 10px uppercase muted, record ID 12px mono clickable, meta 11px muted,
chip inline, 12px between groups.

### ActivityStream — chronological events

Under LinkedRecords. 6px semantic dot + content, text 11px primary, time 10px mono
muted, 6px vertical padding per item.

---

## PAGE PATTERNS

### List page

```
Sidebar | Page Header (serif title + actions + filter bar)
        | Data Table (dense, paginated, sortable)
        | Pagination footer
```

### Detail page

```
Sidebar | Page Header — serif title + status chip + actions
        |               ChainHeader
        | ─────────────────────────────────────────
        | Main (2/3)              | Right panel (1/3)
        |   Metrics row           |   LinkedRecords
        |   Tabs + content        |   ActivityStream
```

### Dashboard

```
Rail | DashboardShell
     | PageHeader (title + subtitle + actions + time-clock action)
     | Optional KpiStrip header slot
     | Widget grid or page-specific dashboard panels
```

`DashboardShell` owns loading, error, empty, stale-data, and responsive layout states. Role dashboards
use the permission-filtered widget layout; dedicated dashboards compose their own panels. The shared
shell has no range selector or page-level export action; pages add controls only when their data
source supports them.

**Per-widget export (F-017).** Each layout widget and each `MetricPanel` carries a CSV
affordance in its header, beside `Open →`. It exports the payload the tile is
already drawing — built by `lib/widgetExport.ts`, written by `lib/csv.ts` — so the
file cannot disagree with the picture, and no request is made. Two rules the
builder enforces:

- **Raw numbers, not formatted strings.** `₱1,234.50` is for the screen;
  `1234.5` is what a spreadsheet can sum.
- **Truncation is stated.** A table widget showing 6 of 300 rows writes a
  `Showing 6 of 300` row rather than letting a partial list pass for the whole set.

When a widget has nothing available the button is absent, not disabled — a dead
control that cannot explain itself is worse than no control. There is deliberately
**no page-level export** yet: a multi-panel dashboard has no single honest table
shape, and inventing one would produce files nobody trusts.

---

## ACCESSIBILITY

- Contrast enforced by the palette gate — AA office, AAA floor. Not a manual check
- All interactive elements keyboard reachable; focus ring always visible
- Form labels linked to inputs
- Status conveyed via text **and** colour (chips carry text)
- `prefers-reduced-motion` respected
- `aria-label` on icon-only buttons
- Tables use `<thead>` / `<tbody>` / `<th scope="col">`
- Floor palette raises hit targets to 44×44 for gloved use

---

## WORKING IN THIS SYSTEM

1. **Never hardcode a colour.** Values live only in `tokens.css`.
   `npm run audit:tokens` fails the build on a hex literal or raw Tailwind palette
   class anywhere in `src/` (landing excepted). It runs in CI on every PR.
2. **Name roles, not colours.** `bg-accent`, not `bg-orange-600`. This is why the
   Atelier rebrand touched ~20 files instead of 355.
3. **No `dark:` variants.** A token already differs per palette; a `dark:` override
   fights it and breaks the floor palette, which is neither light nor dark.
4. **Read density from tokens.** `h-row` / `min-h-hit`, not `h-8` / `min-h-[44px]`.
5. **Adding a semantic colour** means adding all three of `--x`, `--x-bg`, `--x-fg`
   to **all three** palettes, plus a Tailwind mapping. The contrast gate will tell
   you if the pairing fails.
6. **Base components first.** Button, Input, Chip, DataTable, Panel, StatCard,
   ChainHeader before any module page.
7. **Chart colour is a category, not a judgement.** Use `chartColor(i)` /
   `--chart-1..8` for series identity; reserve semantic tokens for meaning.
   Adding a series means retuning all eight in three palettes and letting the
   contrast/CVD gate tell you whether it still works.
7. **If a value isn't here, use the closest one.** Don't invent tokens.
