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
web apps. Tight rows, packed columns, monospace numbers, inline status. The token
file currently sets 14px body text, 48px default table rows, 36px compact rows,
and 56px spacious rows in office palettes. Floor uses 15px body text, 48px rows,
and 44px minimum hit targets. These values come from `tokens.css`, not this prose.

**Serif for display, sans for interface.** Instrument Serif carries page titles and
gives the product its voice. It ships at weight 400 only — never apply a weight
class to it, or the browser synthesises a bold and it looks smeared.

## COLOR TOKENS

Defined in `spa/src/styles/tokens.css`, mapped into Tailwind by
`spa/tailwind.config.ts`. Components name **roles** (`bg-accent`, `text-danger`,
`border-default`), never colours. This indirection lets shared palette changes
reach route surfaces without editing each page.

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
  --row-height: 48px;
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

// BarComparison always uses palette order. DonutBreakdown only accepts a
// semantic colour when each slice actually means a state.
<DonutBreakdown label="Inspection queue by stage" data={stages} />
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

### Chart wrappers

`AreaTrend`, `BarComparison`, `DonutBreakdown`, and `DowntimeParetoChart` require
a descriptive `label`. `ChartFigure` exposes each visualization as a named
image and provides a screen-reader table alternative with the same values.
Visible legends and category axes remain the first scan path. `BarComparison`
assigns series colours in palette order; semantic tokens are reserved for
state-driven donut slices. Numeric chart text uses tabular figures. Recharts
animation stays off, and charts use no gradients or 3D effects. `OeeGaugeChart`
keeps its value and A × P × Q breakdown in ordinary text beside the gauge.

### Identity and category

`--avatar-1..6`, `--avatar-n-bg`, and `--avatar-n-fg` provide six deterministic
identity tints in all palettes. `Avatar` hashes a stable ID (or normalized name)
to choose one, uses a photo when available, and falls back to initials. The
initials remain the person cue; tint only helps scan a dense list. The tint mark
clears the palette's non-text contrast bar on canvas, and its foreground clears
the text bar on its paired background.

Department and category tags map to the existing `--chart-1..8` roles. `Tag`
uses a square body and a leading edge mark; `Chip` keeps rounded corners and
semantic `-bg`/`-fg` pairs for state. Every tag carries its category name, so
colour is never the only cue and chart hues cannot imply approval or failure.
The palette gate checks all category marks against canvas and keeps series
separated under simulated deuteranopia and protanopia.

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
| `--avatar-n` identity marks on canvas | 3:1 | 4.5:1 |
| `--avatar-n-fg` on paired `--avatar-n-bg` | AA 4.5:1 | AAA 7:1 |

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
| `text-2xs` | 12px | 1.4 | Compact labels and metadata |
| `text-xs` | 12px | 1.4 | Chips, badges, metadata |
| `text-sm` | 13px | 1.4 | Secondary text |
| `text-base` | 14px | 1.5 | Body text |
| `text-md` | 14px | 1.4 | Inputs and interface controls |
| `text-lg` | 16px | 1.3 | Larger interface text |
| `text-xl` | 20px | 1.25 | — |
| `text-2xl` | 26px | 1.15 | Large display text |
| `text-3xl` | 32px | 1.1 | Large display |

The token-backed roles are `text-page-title` 28px/36px,
`text-section-title` 20px/28px, `text-subsection` 16px/24px,
`text-body-small` 14px/19px, `text-caption` 13px/18px, `text-label` 14px/18px,
`text-table-header` 14px/18px, `text-table-cell` 14px/20px, and `text-code`
14px/18px. These roles are defined in `tokens.css` and mapped in
`tailwind.config.ts`.

`xl` and `2xl` are larger than the pre-Atelier scale (18px / 22px). Instrument
Serif has a small x-height next to Public Sans and read undersized at the old
values.

### Page titles — serif

`components/layout/PageHeader.tsx` renders the `h1` on essentially every page:

```tsx
<h1 className="font-display text-page-title text-primary break-words">
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
h-row        → var(--row-height)   48px office · 48px floor
min-h-hit    → var(--hit-min)      28px office · 44px floor
min-w-hit    → var(--hit-min)
```

Components read these unconditionally instead of branching on theme. The
`DataTable` default density reads `h-row`; Compact is 36px and Spacious is 56px.
Those explicit operator choices are currently expressed by `h-9` and `h-14`.

## ANIMATIONS

```css
--duration-fast:   150ms;
--duration-normal: 250ms;
--duration-slow:   400ms;
--ease-default: cubic-bezier(0.16, 1, 0.3, 1);
```

**Allowed:** button press `scale(0.98)`, dropdown fade + slide, modal fade +
slide-up, skeleton shimmer, progress fill, status colour transition, toast slide-in,
row hover-action reveal, sliding tab/selection underline, and slide-in side panels.

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
- Section label: 12px uppercase, `--text-muted`, 4px bottom and 16px horizontal padding
- Nav item: 13px, 6px/16px padding, `--text-secondary`; 36px desktop minimum height and 44px mobile minimum height
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

Variants: `primary`, `secondary` (default), `danger`, `ghost`. Sizes: xs 24px,
sm 28px, md 32px, lg 36px, touch at least 44px.

```tsx
// Primary — clay filled
<Button variant="primary">Edit Order</Button>
// Secondary — hairline border on transparent
<Button variant="secondary">Export</Button>
```

Radius `md`, press `scale(0.98)`, focus ring always visible when tabbed.

### Input

The default field is 32px tall with 12px horizontal padding, a hairline border,
and a clay focus ring. Labels use the 14px `text-label` role; helper and error
messages use the 13px `text-caption` role.

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

### Avatar and category tag

Avatars use `h-row-compact` at the standard list size and carry an accessible
name. Use a stable person ID for tint assignment; photos take precedence.
Department/category `Tag` is square with a category-colored leading edge and a
visible label. Never use semantic status colors for grouping.

### Status tabs

Use a tablist for mutually exclusive list states, with a count on every tab.
Keep zero counts visible at lower emphasis. Arrow keys, Home, and End move
selection; the clay underline marks the active state. URL state stays shareable.

Local section `Tabs` move focus and selection together on arrow keys, skipping
disabled tabs. Keep one selected tab in the tab order and connect its content
with `role="tabpanel"`.

### Filter bar

Use the shared `FilterBar` for list filters. Filter pickers are searchable
comboboxes with keyboard selection; active values stay visible as removable
`Field: Value` chips, with `Clear all`, overflow handling, and an announced
result count when the page has one. Blank choices include their field name
(`Pay type: All types`). Keep query and filter values owned by the route so
URL-backed lists remain shareable. Date ranges use native date inputs for
expected calendar and keyboard behavior.

### Status dot

`StatusDot` pairs a small semantic mark with a visible text label. Use it for a
single state summary where a full `Chip` would add too much weight. Keep the
label; never make hue the only way to identify state.

### Progress mini

Use only when a real value has a real target (for example, stock versus reorder
point). Keep text values beside the flat bar; semantic fill indicates whether
the target is met. Do not use it as decoration or as a substitute for chain
stage state.

### Side panel

Use for permission-aware quick preview from a list. It reuses Modal's focus trap,
Escape close, and scroll lock, and must expose a full-record action. The preview
ID belongs in a deep link; every action remains available to keyboard and touch
users.

### Data table

- Row height: `h-row` — **48px** in office and floor. `compact` is 36px;
  `spacious` is 56px.
- Header: sticky `--bg-thead` with a bottom border; header text uses
  `text-table-header`, muted ink, and semibold weight. It is not uppercase by
  default.
- Sticky header: `--bg-thead` **plus a bottom border** — there is no blur to separate it
- Cell padding: 12px horizontal; vertical padding is 8px compact, 12px default,
  and 16px spacious.
- Row separator: hairline `--border-subtle`; hover `--bg-row-hover`
- Zebra: `--bg-zebra-even`
- Numbers: `font-mono tabular-nums`, right-aligned
- Selected row outline: `outline-accent` (**not** a landing token)
- The page's identifying column may use primary ink and medium weight. Keep
  numeric cells tabular and right-aligned.
- Optional row actions reveal on pointer hover and keyboard focus. Touch users
  see them without hover; the controls remain in the tab order.
- Set `loading` for first-page fetches to retain the header and draw five
  skeleton rows at the active density; the table announces `aria-busy` until
  data arrives.

Table tokens (`--bg-thead`, `--bg-zebra-*`, `--bg-row-hover`) are not mapped as
Tailwind colours — consume them as `bg-[var(--bg-thead)]`.

### Metric / KPI card

Padding 16px, `--bg-surface`, hairline border, label 13px muted, value scales
from 16px to 26px with the card width, and delta/helper text uses the 13px
caption role. Delta colour communicates direction according to its declared
polarity.

26px is the design size, not a fixed one: the figure is sized against **its own
card** (`@container` + `clamp(1rem, 12cqw, 1.625rem)`), because a two-up grid at
375px leaves 132px of content and `₱576,912.00` measures 175px there. The step
follows the card, so the same component is correct in a 4-up dashboard and a
two-up phone layout. Every digit stays visible — it wraps inside the card rather
than truncating or widening the page (F-009).

### SparkLine

Use only for a real time series, beside its metric name and time context. Keep
the stroke flat and unfilled. `SparkLine` requires a label and exposes a text
alternative with point count, first and latest values, and overall direction;
the visual line has no separate legend.

### Panel

Hairline border, header 12px/16px padding with bottom border, title 16px medium,
right-side metadata follows its local component role.

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
chips, panels, KPI cards, selection controls, active filters, compact trends,
tables, the record patterns, and interactive overlays. It fetches nothing and
uses literal fixtures only, which is what makes it photographable.

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
- Labels use `text-xs` (12px); dates use `text-2xs` (12px) in mono.
- Reserve `min-h-row` below the dots so absolutely positioned labels cannot overlap the next section.

### StageDots — compact chain position

Use in a chain-document row or summary only when the data includes actual stage
states. Each dot has an accessible stage name and state; the current stage uses
`aria-current="step"`. Completed, active, pending, rejected, and skipped states
also differ by mark treatment, so meaning does not depend on color. Keep the full
`ChainHeader` on detail pages. A short visible summary names the current stage,
rejection, next pending stage, or completion state.

### StageBreakdown — vertical stage counts

Dashboards. The chain version uses a label and count above a 4px progress bar,
with a 10px gap between stages. A second `ui/StageBreakdown` implementation
exists and differs in container and text styling; consolidate only after callers
are inventoried.

### LinkedRecords — related records

Right panel of detail pages, grouped by type. Group labels use 12px uppercase
subtle ink, record IDs use 13px mono, metadata uses 12px, and status chips remain
inline.

### ActivityStream — chronological events

Under LinkedRecords. A 6px semantic dot precedes 12px event text; timestamps use
12px mono muted text, with 6px vertical padding per item.

---

## PAGE PATTERNS

Page layout recipes cover list, detail, dashboard, form, report, settings/admin, and floor/PWA
surfaces. They compose shared components; Phase 5 migrates route modules to these recipes. See
[`docs/ui-polish/03-patterns.md`](ui-polish/03-patterns.md) for composition order, component maps,
state rules, and adoption limits.

`DashboardShell` owns loading, error, empty, stale-data, and responsive layout states. Role dashboards
use the permission-filtered widget layout; dedicated dashboards compose their own panels. A range
selector appears only when the query supports it. The shell has no page-level export action because a
multi-panel dashboard has no single honest dataset by default.

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
   Atelier palette changes belong in shared tokens and components, not one-off
   page-level colour values.
3. **No `dark:` variants.** A token already differs per palette; a `dark:` override
   fights it and breaks the floor palette, which is neither light nor dark.
4. **Read density from tokens.** `h-row`, `min-h-row`, and `min-h-hit`, not `h-8` / `min-h-[44px]`.
5. **Adding a semantic colour** means adding all three of `--x`, `--x-bg`, `--x-fg`
   to **all three** palettes, plus a Tailwind mapping. The contrast gate will tell
   you if the pairing fails.
6. **Base components first.** Button, Input, Chip, DataTable, Panel, StatCard,
   ChainHeader, Avatar, AvatarGroup, Tag, StatusDot, StageDots, StatusTabs, and
   ProgressMini before module-specific styling.
7. **Chart colour is a category, not a judgement.** Use `chartColor(i)` /
   `--chart-1..8` for series identity; reserve semantic tokens for meaning.
   Adding a series means retuning all eight in three palettes and letting the
   contrast/CVD gate tell you whether it still works.
8. **If a value isn't here, use the closest one.** Don't invent tokens.
