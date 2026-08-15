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
web apps. Tight rows, packed columns, monospace numbers, inline status. Base body
size is 13px and office table rows are 32px; these are spec, not preference.

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
| Floor | `[data-theme="floor"]` | factory / driver / maintenance-mobile PWAs |

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
  --text-subtle:    #8a8078;  /* placeholders, disabled — decorative only */

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
  --row-height: 32px;
  --hit-min: 28px;
  --font-size-body: 13px;
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
  --text-subtle:    #7d7468;

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
  --text-subtle:    #9a9186;  /* lifted from dark's #8a8078 */

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

| Name | Size | Line height | Usage |
|---|---|---|---|
| `text-2xs` | 10px | 1.4 | Column headers (uppercase, tracked), muted labels |
| `text-xs` | 11px | 1.4 | Chip text, badges, meta |
| `text-sm` | 12px | 1.4 | Table cells, secondary text |
| `text-base` | 13px | 1.5 | Body, form inputs, navigation |
| `text-md` | 14px | 1.4 | Card titles, section headers |
| `text-lg` | 16px | 1.3 | Panel titles |
| `text-xl` | 20px | 1.25 | — |
| `text-2xl` | 26px | 1.15 | **Page titles** (`font-display`) |
| `text-3xl` | 32px | 1.1 | Large display |

`xl` and `2xl` are larger than the pre-Atelier scale (18px / 22px). Instrument
Serif has a small x-height next to Public Sans and read undersized at the old
values.

### Page titles — serif

`components/layout/PageHeader.tsx` renders the `h1` on essentially every page:

```tsx
<h1 className="font-display text-2xl text-primary truncate">
```

No weight class — the family is 400-only. Subtitles, breadcrumbs and actions stay
on `font-sans`; serif in small UI text reads as a mistake.

### Numbers, IDs, dates — always mono

```tsx
<span className="font-mono tabular-nums">₱ 486,500.00</span>
<span className="font-mono tabular-nums">PO-202604-0015</span>
```

Tabular figures align vertically across table columns.

## SPACING (8px grid with 4px increments)

```
0.5 = 2px    1 = 4px    1.5 = 6px    2 = 8px
2.5 = 10px   3 = 12px   4 = 16px     5 = 20px
6 = 24px     8 = 32px   10 = 40px    12 = 48px
```

- Inside cells, chips, badges: 2–8px padding
- Between form fields: 12px
- Between sections: 16–20px
- Page padding: 16–20px (not 24+)
- Card padding: 12–16px (not 20+)

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
h-row        → var(--row-height)   32px office · 48px floor
min-h-hit    → var(--hit-min)      28px office · 44px floor
min-w-hit    → var(--hit-min)
```

Components read these unconditionally instead of branching on theme. `DataTable`'s
`compact` and `spacious` densities stay fixed — those are explicit operator
choices the palette should not override.

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
`rounded-md`, 2px clay indicator on the active item, tooltip on hover.

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

Factory, driver and maintenance-mobile all render through
`components/layout/TouchShell.tsx` — one shell, three sets of props. No sidebar.
`TouchShell` forces `[data-theme="floor"]` on mount and restores the user's own
preference on unmount.

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

- Row height: `h-row` — **32px** office, 48px floor. `compact` 28px, `spacious` 40px stay fixed
- Header: `h-row`, 10px uppercase tracked muted, `--bg-thead`
- Sticky header: `--bg-thead` **plus a bottom border** — there is no blur to separate it
- Cell padding: 0 10px
- Row separator: hairline `--border-subtle`; hover `--bg-row-hover`
- Zebra: `--bg-zebra-even`
- Numbers: `font-mono tabular-nums`, right-aligned
- Selected row outline: `outline-accent` (**not** a landing token)

Table tokens (`--bg-thead`, `--bg-zebra-*`, `--bg-row-hover`) are not mapped as
Tailwind colours — consume them as `bg-[var(--bg-thead)]`.

### Metric / KPI card

Padding 14–16px, `--bg-surface`, hairline border, label 10px uppercase muted,
value 26px mono medium, delta 11px mono in `--success` / `--danger`.

### Panel

Hairline border, header 12px/16px padding with bottom border, title 14px medium,
right-side meta 11px muted.

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
Rail | Page Header (title + range selector + export)
     | KPI cards row
     | Two-column grid of panels
```

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
7. **If a value isn't here, use the closest one.** Don't invent tokens.
