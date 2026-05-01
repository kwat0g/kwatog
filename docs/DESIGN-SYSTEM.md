# OGAMI ERP — Design System

> Exact visual spec. Every color, every spacing, every component pattern.
> Goal: dense, professional, SAP/Linear-inspired. Monochrome canvas + meaningful color only.

## PHILOSOPHY

**The canvas is grayscale. Color is for meaning.** Backgrounds, text, borders, cards, tables, sidebars — all pure white/gray/black. Color only appears on status chips, primary buttons, KPI deltas, alert dots, and links. This is how we avoid the AI-made look.

**Information density over whitespace.** Real ERPs show more data per screen than web apps. Tight row heights, packed columns, monospace numbers, inline status. If it feels airy, it's wrong.

**Two weights only: 400 regular, 500 medium.** Never 600 or 700 — they look heavy and make the UI feel cheap.

## COLOR TOKENS

Define as CSS variables in `spa/src/styles/tokens.css`. Every component reads from variables. Tailwind extends to use them.

### Light mode

```css
:root {
  /* Canvas — pure grayscale */
  --bg-canvas:        #FFFFFF;  /* page background */
  --bg-surface:       #FAFAFA;  /* cards, metric tiles, right panels */
  --bg-elevated:      #F4F4F5;  /* modals, dropdowns, hover states */
  --bg-subtle:        #F4F4F5;  /* alternating rows, inactive chips */

  /* Borders */
  --border-subtle:    #F4F4F5;  /* row dividers inside tables */
  --border-default:   #E4E4E7;  /* card borders, section dividers */
  --border-strong:    #D4D4D8;  /* emphasized borders */

  /* Text */
  --text-primary:     #09090B;  /* headings, primary content */
  --text-secondary:   #52525B;  /* body text, labels */
  --text-muted:       #71717A;  /* secondary info, meta */
  --text-subtle:      #A1A1AA;  /* placeholders, disabled, hints */

  /* Accent — primary (indigo) */
  --accent:           #4F46E5;  /* primary buttons, active nav indicator */
  --accent-hover:     #4338CA;
  --accent-fg:        #FFFFFF;  /* text on accent background */

  /* Semantic — status colors */
  --success:          #059669;  /* text color */
  --success-bg:       #D1FAE5;  /* chip background */
  --success-fg:       #065F46;  /* text on chip */

  --warning:          #D97706;
  --warning-bg:       #FEF3C7;
  --warning-fg:       #92400E;

  --danger:           #DC2626;
  --danger-bg:        #FEE2E2;
  --danger-fg:        #991B1B;

  --info:             #2563EB;
  --info-bg:          #DBEAFE;
  --info-fg:          #1E40AF;

  /* Optional — for charts/categories only */
  --purple:           #7C3AED;
  --purple-bg:        #EDE9FE;
  --purple-fg:        #5B21B6;

  /* Focus ring */
  --ring:             #4F46E5;
  --ring-offset:      #FFFFFF;
}
```

### Dark mode

```css
[data-theme="dark"] {
  --bg-canvas:        #0A0A0A;  /* near-black, not pure */
  --bg-surface:       #111111;
  --bg-elevated:      #1A1A1A;
  --bg-subtle:        #1A1A1A;

  --border-subtle:    #1A1A1A;
  --border-default:   #27272A;
  --border-strong:    #3F3F46;

  --text-primary:     #FAFAFA;
  --text-secondary:   #D4D4D8;
  --text-muted:       #A1A1AA;
  --text-subtle:      #71717A;

  --accent:           #6366F1;  /* slightly brighter for dark bg */
  --accent-hover:     #818CF8;
  --accent-fg:        #FFFFFF;

  --success:          #10B981;
  --success-bg:       #064E3B;
  --success-fg:       #6EE7B7;

  --warning:          #F59E0B;
  --warning-bg:       #78350F;
  --warning-fg:       #FCD34D;

  --danger:           #EF4444;
  --danger-bg:        #7F1D1D;
  --danger-fg:        #FCA5A5;

  --info:             #3B82F6;
  --info-bg:          #1E3A8A;
  --info-fg:          #93C5FD;

  --purple:           #A78BFA;
  --purple-bg:        #4C1D95;
  --purple-fg:        #DDD6FE;

  --ring:             #6366F1;
  --ring-offset:      #0A0A0A;
}
```

## TYPOGRAPHY

```css
@import url('https://fonts.googleapis.com/css2?family=Geist:wght@400;500&family=Geist+Mono:wght@400;500&display=swap');

:root {
  --font-sans: 'Geist', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
  --font-mono: 'Geist Mono', 'SF Mono', Menlo, Monaco, Consolas, monospace;
}

body {
  font-family: var(--font-sans);
  font-feature-settings: "cv11", "ss01";  /* Geist stylistic sets */
  font-size: 13px;
  line-height: 1.5;
  color: var(--text-primary);
  background: var(--bg-canvas);
}
```

### Type scale

| Name | Size | Weight | Line height | Usage |
|---|---|---|---|---|
| `text-2xs` | 10px | 500 | 1.4 | Column headers (uppercase, letter-spaced), muted labels |
| `text-xs` | 11px | 400/500 | 1.4 | Chip text, badges, meta info |
| `text-sm` | 12px | 400/500 | 1.4 | Table cells, secondary text, filter chips |
| `text-base` | 13px | 400/500 | 1.5 | Body text, form inputs, navigation |
| `text-md` | 14px | 400/500 | 1.4 | Card titles, section headers |
| `text-lg` | 16px | 500 | 1.3 | Panel titles, page subheaders |
| `text-xl` | 18px | 500 | 1.3 | Page titles |
| `text-2xl` | 22px | 500 | 1.2 | KPI values |

### Numbers, IDs, dates — always mono

```tsx
// ALL numeric content uses Geist Mono with tabular figures
<span className="font-mono tabular-nums">₱ 486,500.00</span>
<span className="font-mono tabular-nums">10,000</span>
<span className="font-mono tabular-nums">PO-202604-0015</span>
<span className="font-mono tabular-nums">Apr 20, 2026</span>
```

Tabular numbers align vertically across table columns. This single detail is the biggest "not AI-made" tell in the UI.

### Column headers — uppercase letter-spaced

```tsx
// All table column headers
<th className="text-2xs uppercase tracking-wider text-muted font-medium">
  Customer
</th>
```

Font size 10px, uppercase, letter-spacing 0.03em, `--text-muted` color, weight 500.

## SPACING (8px grid with 4px increments)

```
0.5 = 2px    1 = 4px    1.5 = 6px    2 = 8px
2.5 = 10px   3 = 12px   4 = 16px     5 = 20px
6 = 24px     8 = 32px   10 = 40px    12 = 48px
```

**Rules:**
- Inside cells, chips, badges: 2–8px padding
- Between form fields: 12px
- Between sections: 16–20px
- Page padding: 16–20px (not 24+)
- Card padding: 12–16px (not 20+)

## BORDER RADIUS

```css
--radius-sm: 4px;   /* chips, small elements */
--radius-md: 6px;   /* DEFAULT — buttons, inputs, cards */
--radius-lg: 8px;   /* modals, large panels */
--radius-full: 9999px; /* avatars, full-round badges */
```

**Use 6px everywhere unless there's a reason not to.** Consistency is what makes it feel designed.

## BORDERS

**Use 0.5px borders on most elements.** Real 0.5px on retina, falls back to 1px elsewhere.

```css
border: 0.5px solid var(--border-default);
```

Borders are for separation, not emphasis. Never use thick borders (2px+) except on the focus ring.

## FOCUS RING

```css
button:focus-visible,
input:focus-visible,
[role="button"]:focus-visible {
  outline: 2px solid var(--ring);
  outline-offset: 2px;
}
```

Primary indigo ring on all interactive elements. Never remove focus styles.

## SHADOWS

**Almost none.** Real ERPs don't use drop shadows on flat surfaces.

```css
--shadow-focus: 0 0 0 3px rgba(79, 70, 229, 0.15);
--shadow-menu:  0 4px 12px rgba(0, 0, 0, 0.08);  /* dropdowns, popovers only */
```

No shadows on cards, buttons, panels. They sit flat with 0.5px borders.

## ANIMATIONS

Minimal and purposeful. Real ERPs feel instant.

```css
--duration-fast:   100ms;  /* hover, button press */
--duration-normal: 150ms;  /* dropdown open, tab switch */
--duration-slow:   200ms;  /* modal open */

--ease-default: cubic-bezier(0.4, 0, 0.2, 1);
```

**Allowed animations:**
- Button press: `scale(0.98)` for 100ms
- Dropdown/popover open/close: 150ms fade + 4px slide
- Modal: 200ms fade + 8px slide-up
- Skeleton loading: shimmer 1.5s
- Progress bar fill: smooth width transition
- Status change: 150ms color transition
- Toast slide-in: 200ms from right

**Never:**
- Card hover lift (too web-appy)
- KPI count-up animations (instant feels faster)
- Bouncy easing (unprofessional)
- Fade-in on page load (slows perception)
- Entrance animations on table rows

All animations respect `prefers-reduced-motion: reduce`.

---

## LAYOUT SYSTEM

### Global shell

```
┌──────────────────────────────────────────────────────────────┐
│ Topbar (48px, sticky, bottom border 0.5px)                   │
├──────┬───────────────────────────────────────────────────────┤
│      │                                                        │
│ Side │ Page content                                           │
│ bar  │                                                        │
│      │                                                        │
│ 240  │                                                        │
│  or  │                                                        │
│  56  │                                                        │
│      │                                                        │
└──────┴───────────────────────────────────────────────────────┘
```

### Topbar (48px)

```
┌────────────────────────────────────────────────────────────────┐
│ [Logo] Ogami ERP  Breadcrumbs        [Search ⌘K] [☀/🌙] [👤] │
└────────────────────────────────────────────────────────────────┘
```

- Height: 48px
- Bottom border: 0.5px `--border-default`
- Padding: 0 16px
- Logo: 22px square, black square with white "O" (inverted in dark mode)
- Breadcrumbs: `text-sm text-muted`, active segment `text-primary font-medium`
- Search trigger: 180px wide, border, icon, "Search..." text, `⌘K` keyboard hint
- Theme toggle: 30px square icon button
- Avatar: 28px circle with initials

### Sidebar — Two modes

**Expanded (240px):** Used on screens ≥ 1280px wide, or when user expanded it

```
┌──────────────────────┐
│ ─── OPERATIONS ───   │  ← section label: text-2xs uppercase muted
│ ▸ Dashboard          │
│ ● Sales Orders       │  ← active: left border 2px indigo, bg subtle
│ ▸ Production Orders  │
│ ▸ MRP Plans          │
│ ▸ Inventory      (3) │  ← badge for pending items
│ ▸ Quality Control    │
│ ▸ Deliveries         │
│                      │
│ ─── FINANCE ───      │
│ ▸ Receivables        │
│ ▸ Payables           │
│ ▸ General Ledger     │
│                      │
│ ─── PEOPLE ───       │
│ ▸ Employees          │
│ ▸ Attendance         │
│ ▸ Payroll            │
│                      │
└──────────────────────┘
```

- Width: 240px
- Right border: 0.5px `--border-default`
- Padding: 12px 0
- Section label: 10px uppercase, letter-spacing 0.08em, `--text-subtle`, padding 6px 16px
- Nav item: 13px, 6px vertical padding, 16px horizontal, `--text-secondary`
- Nav item active: `--text-primary`, background `--bg-elevated`, 2px left border `--accent`, font-weight 500
- Nav item hover: background `--bg-elevated`
- Badge: 10px, indigo or amber background with matching fg text, 10px height pill

**Rail (56px):** Used on screens < 1280px or when user collapsed

```
┌────┐
│ ▪  │  ← icons only, 16px, 36px clickable area
│ ▪  │
│ ◼  │  ← active: left indicator 2px indigo bar, bg subtle
│ ▪  │
│ ▪  │
└────┘
```

- Width: 56px
- Icons: 16px Lucide, `--text-muted`
- Item: 36px square, rounded 6px, hover `--bg-elevated`
- Active: `--text-primary`, background `--bg-elevated`, 2px indigo indicator on left (absolute positioned)
- Tooltip on hover showing label

### Page content area

- Padding: 16–20px
- Max content width: none (fill available space)

### Page header pattern

```tsx
<div className="px-5 py-4 border-b border-default">
  <div className="flex items-center justify-between mb-3">
    <div className="flex items-center gap-3">
      <h1 className="text-xl font-medium">SO-202604-0003</h1>
      <StatusChip status="in_production">In Production</StatusChip>
    </div>
    <div className="flex gap-1.5">
      <Button variant="ghost" size="sm">Export</Button>
      <Button variant="ghost" size="sm">Print</Button>
      <Button variant="primary" size="sm">Edit Order</Button>
    </div>
  </div>

  {/* Optional: chain header */}
  <ChainHeader steps={chainSteps} activeStep={3} />
</div>
```

---

## CORE COMPONENTS

### Button

Three variants: `primary`, `secondary` (default, ghost), `danger`.

```tsx
// Primary — indigo filled
<button className="h-8 px-3 rounded-md bg-accent text-accent-fg text-sm font-medium hover:bg-accent-hover">
  Edit Order
</button>

// Secondary / ghost — default
<button className="h-8 px-3 rounded-md border border-default bg-transparent text-primary text-sm hover:bg-elevated">
  Export
</button>

// Danger
<button className="h-8 px-3 rounded-md bg-danger text-white text-sm font-medium hover:bg-danger-hover">
  Delete
</button>
```

**Sizes:** sm (28px), md (32px default), lg (36px). Never larger.

**Rules:**
- Font weight 500 (primary) or 400 (secondary)
- Border radius 6px
- Press animation: `scale(0.98)` for 100ms
- Focus ring always visible when tabbed

### Input

```tsx
<div className="flex flex-col gap-1">
  <label className="text-xs text-muted font-medium">Customer</label>
  <input
    className="h-8 px-3 rounded-md border border-default bg-canvas text-sm
               focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent
               placeholder:text-subtle"
    placeholder="Select customer..."
  />
  {error && <span className="text-xs text-danger">{error}</span>}
</div>
```

- Height: 32px
- Padding: 0 12px
- Border: 0.5px `--border-default`
- Focus: 2px indigo ring
- Label above input, 11px muted

### Status chip

The most important component. Appears everywhere.

```tsx
// 4px vertical, 7px horizontal padding
// 10px font, weight 500
// 4px radius
// Semantic colors — bg + fg from same ramp

const chipVariants = {
  success: 'bg-success-bg text-success-fg',
  warning: 'bg-warning-bg text-warning-fg',
  danger:  'bg-danger-bg  text-danger-fg',
  info:    'bg-info-bg    text-info-fg',
  neutral: 'bg-subtle     text-muted',
};

<span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-success-bg text-success-fg">
  Completed
</span>
```

**Status → variant mapping:**

| Status | Variant |
|---|---|
| completed, approved, active, passed, running, paid | success |
| in_production, in_progress, processing, scheduled | info |
| pending, draft, queued, idle, setup | warning |
| rejected, failed, breakdown, overdue, urgent, material_short | danger |
| cancelled, inactive, closed | neutral |

### Data table

**The most important layout in the system.** Dense, readable, SAP-like.

```tsx
<table className="w-full border-collapse text-xs">
  <thead>
    <tr className="border-b border-default">
      <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">#</th>
      <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Product</th>
      <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Qty</th>
      <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Total</th>
      <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Status</th>
    </tr>
  </thead>
  <tbody>
    <tr className="h-8 border-b border-subtle hover:bg-subtle">
      <td className="px-2.5 text-muted font-mono tabular-nums">01</td>
      <td className="px-2.5">
        <div className="font-medium">Wiper Bushing WB-001</div>
        <div className="text-xs text-muted">Standard grade</div>
      </td>
      <td className="px-2.5 text-right font-mono tabular-nums">10,000</td>
      <td className="px-2.5 text-right font-mono tabular-nums font-medium">85,000.00</td>
      <td className="px-2.5"><StatusChip variant="success">Completed</StatusChip></td>
    </tr>
  </tbody>
</table>
```

**Exact specs:**
- Row height: **32px** (default). Compact: 28px. Spacious: 40px.
- Header: 32px height, 10px uppercase font, tracking-wider, muted
- Cell padding: 0 10px (2.5 in Tailwind)
- Row separator: 0.5px `--border-subtle`
- Hover: `--bg-subtle` background
- Numbers: always `font-mono tabular-nums` right-aligned
- Product/name column: two lines — primary + muted subtitle
- Border on table: none (sits in a card if needed)

### Metric / KPI card

```tsx
<div className="p-3 bg-surface border border-default rounded-md">
  <div className="text-2xs uppercase tracking-wider text-subtle font-medium mb-1.5">
    Revenue · Week
  </div>
  <div className="text-2xl font-medium font-mono tabular-nums text-primary">
    ₱ 4.82M
  </div>
  <div className="text-xs font-mono mt-1 text-success">
    ↑ 12.4%
  </div>
</div>
```

- Padding: 14–16px
- Background: `--bg-surface`
- Border: 0.5px `--border-default`
- Label: 10px uppercase muted
- Value: 22px monospace medium
- Delta: 11px mono, `--success` or `--danger` based on direction

### Panel (card with header)

```tsx
<div className="bg-canvas border border-default rounded-md overflow-hidden">
  <div className="flex items-center justify-between px-4 py-3 border-b border-default">
    <h3 className="text-sm font-medium">Active Orders by Chain Stage</h3>
    <span className="text-xs text-muted">5 alerts</span>
  </div>
  <div className="p-4">
    {/* content */}
  </div>
</div>
```

- Border: 0.5px `--border-default`
- Header: 12px vertical padding, 16px horizontal, bottom border
- Title: 14px medium
- Meta (right side): 11px muted

---

## CHAIN PROCESS COMPONENTS (the thesis differentiator)

### ChainHeader — horizontal process timeline

Used on top of detail pages (Sales Order, Purchase Order, Work Order). Shows the full chain with current position.

```tsx
interface ChainStep {
  key: string;
  label: string;
  date?: string;
  state: 'done' | 'active' | 'pending';
}

<ChainHeader
  steps={[
    { key: 'order_entered', label: 'Order Entered', date: 'Apr 05', state: 'done' },
    { key: 'mrp_planned', label: 'MRP Planned', date: 'Apr 05', state: 'done' },
    { key: 'in_production', label: 'In Production', date: 'Apr 08', state: 'active' },
    { key: 'qc_outgoing', label: 'QC Outgoing', state: 'pending' },
    { key: 'delivered', label: 'Delivered', state: 'pending' },
    { key: 'invoiced', label: 'Invoiced', state: 'pending' },
  ]}
/>
```

**Visual spec:**
- Horizontal flex row with steps separated by thin lines
- Done steps: 9px emerald dot with 1px outline
- Active step: 9px indigo dot with 1px outline, label `text-primary` bold
- Pending step: 9px gray dot, label `text-subtle`
- Line between steps: 1px, emerald when done, gray when pending
- Labels: 11px medium + 10px mono date below
- Align step tops, line flows between centers

### StageBreakdown — vertical list of stages with counts

Used on dashboards. Shows how many records are at each step of a chain.

```tsx
<StageBreakdown
  title="Active Orders by Chain Stage"
  stages={[
    { label: 'Order Entered',     count: 12, percent: 100, color: 'success' },
    { label: 'MRP Planned',       count: 9,  percent: 75,  color: 'success' },
    { label: 'In Production',     count: 7,  percent: 58,  color: 'info' },
    { label: 'QC Pending',        count: 4,  percent: 33,  color: 'info' },
    { label: 'Ready to Ship',     count: 3,  percent: 25,  color: 'success' },
    { label: 'Delivered · Unpaid', count: 6, percent: 50,  color: 'warning' },
    { label: 'At Risk',           count: 2,  percent: 16,  color: 'danger' },
  ]}
/>
```

**Visual spec:**
- Each row: label + count on one line, 4px progress bar below
- Progress bar: full width, 4px tall, `--bg-subtle` background
- Fill: colored according to stage status (emerald/indigo/amber/red)
- Row spacing: 10px between stages

### LinkedRecords — related record sidebar

Used on right panel of detail pages. Shows ALL records related to the current one, grouped by type.

```tsx
<LinkedRecords
  groups={[
    { label: 'MRP Plan', items: [{ id: 'MRP-202604-0008', meta: 'Generated Apr 05 · 4 shortages' }] },
    { label: 'Work Orders', items: [
      { id: 'WO-202604-0006', chip: { variant: 'success', text: 'Done' } },
      { id: 'WO-202604-0007', chip: { variant: 'info', text: 'Running' } },
    ]},
    { label: 'QC Inspections', items: [{ id: 'QC-202604-0012', chip: { variant: 'success', text: 'Passed' }, meta: 'AQL 0.65 · 200 sampled · 0 rejects' }] },
  ]}
/>
```

**Visual spec:**
- Right panel background: `--bg-surface`
- Left border: 0.5px `--border-default`
- Group label: 10px uppercase muted
- Record ID: 12px monospace, primary color, clickable
- Meta: 11px muted
- Chip: inline next to ID
- 12px spacing between groups

### ActivityStream — chronological events

Used under LinkedRecords on right panels. Shows recent events on the record.

```tsx
<ActivityStream
  items={[
    { dot: 'success', text: '<b>Rosa V.</b> approved QC inspection', time: '2 hours ago' },
    { dot: 'info', text: 'WO-202604-0006 completed · 10,000 good / 45 reject', time: '4 hours ago' },
    { dot: 'warning', text: 'PR-202604-0018 flagged urgent — Resin C shortage', time: 'Yesterday' },
  ]}
/>
```

**Visual spec:**
- Each item: 6px colored dot + content block
- Text: 11px primary
- Time: 10px mono muted
- 6px vertical padding per item

---

## PAGE PATTERNS

### List page (e.g., Employees, Sales Orders)

```
Topbar
Sidebar | Page Header (title + action buttons + search/filter bar)
        | Data Table (dense, paginated, sortable)
        | Pagination footer
```

### Detail page (e.g., Sales Order SO-202604-0003)

```
Topbar
Sidebar | Page Header
        |   Title + Status Chip + Action Buttons
        |   ChainHeader (horizontal process timeline)
        | ──────────────────────────────────────
        | Main content (2/3)        | Right panel (1/3)
        |   Metrics row             |   Linked Records
        |   Tabs (Line Items,       |   Activity Stream
        |         Work Orders,      |
        |         QC, Deliveries,   |
        |         Documents,        |
        |         Activity)         |
        |   Tab content             |
```

### Dashboard

```
Topbar
Rail sb | Page Header (title + time range selector + export)
        | KPI cards row (4 cards)
        | Main grid (2 columns)
        |   Large panel (Chain Stage Breakdown) | Small panel (Alerts)
        |   Small panel (Machine Util)          | Small panel (QC Pareto)
```

---

## ACCESSIBILITY

- Color contrast 4.5:1 minimum for text (WCAG AA)
- All interactive elements keyboard reachable
- Focus ring always visible
- Form labels always linked to inputs
- Status conveyed via text AND color (chip has text, not just color)
- `prefers-reduced-motion` respected
- Screen reader labels on icon-only buttons (`aria-label`)
- Tables use proper `<thead>` / `<tbody>` / `<th scope="col">`

---

## IMPLEMENTATION NOTES FOR CLAUDE CODE

1. **Start by creating `spa/src/styles/tokens.css`** with all CSS variables from above
2. **Configure Tailwind** to read from CSS variables:
   ```js
   // tailwind.config.ts
   export default {
     theme: {
       extend: {
         colors: {
           canvas: 'var(--bg-canvas)',
           surface: 'var(--bg-surface)',
           elevated: 'var(--bg-elevated)',
           subtle: 'var(--bg-subtle)',
           primary: 'var(--text-primary)',
           secondary: 'var(--text-secondary)',
           muted: 'var(--text-muted)',
           accent: 'var(--accent)',
           'accent-fg': 'var(--accent-fg)',
           success: 'var(--success)',
           'success-bg': 'var(--success-bg)',
           'success-fg': 'var(--success-fg)',
           // ... etc
         },
         fontFamily: {
           sans: ['Geist', 'system-ui', 'sans-serif'],
           mono: ['Geist Mono', 'monospace'],
         },
         fontSize: {
           '2xs': ['10px', { lineHeight: '1.4' }],
         },
       },
     },
   };
   ```
3. **Load Geist fonts** in `index.html` via Google Fonts
4. **Set theme via** `document.documentElement.setAttribute('data-theme', 'dark')`
5. **Persist theme choice** per user in database (not localStorage — this is an ERP)
6. **Base components first** — build Button, Input, Chip, DataTable, Panel, StatCard, ChainHeader in Sprint 1 before any module pages
7. **Never deviate from these tokens.** If a value isn't in this file, use the closest one — don't invent new ones
