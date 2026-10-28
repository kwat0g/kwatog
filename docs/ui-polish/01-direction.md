# Phase 1 — direction brief

## Product direction

Refine Atelier as a compact, information-rich ERP. Keep warm paper, espresso ink, clay accent,
opaque surfaces, hairline borders, Instrument Serif display titles, Public Sans interface text, and
the existing dense layout. Improve emphasis and scanning through placement, type hierarchy, and
meaningful cues. Do not add ornamental panels, gradients, card shadows, or motion without a user
action behind it.

The current source tokens define office body text at 14px and default rows at 48px (36px compact),
not the 13px/32px values stated in the mission. `tokens.css` is authoritative. Refinement should
use existing compact density where appropriate; it should not reduce global type or row sizes.

## Three levels of emphasis

| Level | Use | Type and colour | Placement |
|---|---|---|---|
| Primary | Page purpose, record identity, key metric, or one action | Page title uses Instrument Serif at `--font-size-page-title`, normal weight, `--text-primary`. The key table column uses `--text-primary` and medium weight. The one primary action uses `bg-accent`. | Page title at upper left. One primary action at the right end of the PageHeader action group. Key table value leads each row. |
| Secondary | Status, summary, supporting metric, section heading | Existing 16–20px section scale, Public Sans, medium weight; use `--text-primary` for headings and semantic tokens only for actual state. | Immediately below the title or within a section introduced by a heading. |
| Tertiary | Labels, dates, IDs, helper text, metadata | Existing 13–14px tokens, normal weight, `--text-secondary` or `--text-muted`; use tabular numerals for aligned quantities. | Beside the data they qualify; avoid standalone metadata bands. |

Group related content with 16–24px spacing and a hairline divider. Do not wrap every section in a
Panel or nest panels. Tables emphasize one identifying column (person, document number, or item),
right-align quantities and money, and keep secondary metadata quieter. PageHeader holds exactly one
primary action; related secondary actions follow it, and destructive or rare actions go in an
overflow menu. Permission-gated actions remain permission-gated.

## Cue vocabulary

| Cue | Meaning and rule |
|---|---|
| Avatar | A person. Use photo when available, otherwise initials; tint is deterministic from stable user ID, with normalized name as fallback. Accessible name always includes the person’s name. |
| Status dot plus label / status Chip | Current state only: active, pending, overdue, approved, rejected, and similar. Text remains visible; colour never carries state alone. |
| Category Tag | Grouping only: department, item family, document type, or category. Use a square-corner treatment and a leading colour mark, visibly distinct from the rounded status Chip. Keep the category name in text. |
| InlineBar / ProgressMini | Quantity against a named target, stock against reorder point, or completion across a known stage set. Always show the quantity or stage label. |
| Delta / Sparkline | Change over a stated comparison period. Label direction and comparison in text; no gradient fill and no unlabelled sparkline. |
| Urgency | Overdue uses danger with an “Overdue” label and due date. Due soon uses warning and a date. Do not colour normal dates. |
| Icon | Tabler through `lib/icons.ts`; choose the most specific existing icon. Icons support labels/actions, not decoration. Icon-only controls have accessible names. |
| Chain stage cue | Compact StageDots in chain-document rows and dashboard exception summaries, with completed/current/blocked labels or accessible text. Never imply success from a count alone. |

Do not add a cue when data is absent or it does not change a decision. Empty states stay plain and
offer the next permitted action.

## Colour rules

- Clay accent carries the primary action, active navigation, focus, links, and at most one
  non-semantic highlight per view.
- The five existing semantic hues carry state only. Keep their foreground/background pairings.
- Existing `--chart-1..8` carry categorical identity only. Keep chart labels/direct labels, and do
  not use a chart hue to say that something passed, failed, is late, or needs attention.
- Category Tags use a leading square mark and square-corner body; status Chips keep their rounded
  status treatment. The text label, not hue, identifies either meaning.
- Avatar tint is a repeatable identity aid, not status. Initials or the accessible name remain the
  identity signal.
- No raw color values, default Tailwind palette colors, `dark:` classes, gradient, glass, neon, or
  coloured shadows. New tokens wait for approval and the contrast gate.

## Interaction and motion

Every action gives visible feedback within 100ms: focus, pressed, selected, disabled, loading,
success, or error as applicable. Hover-revealed row actions also appear on keyboard focus and have
an always-available keyboard path. Keyboard and pointer access must lead to the same action.

Use only existing permitted motion plus the approved extras: button press scale, dropdown fade and
slide, modal fade and slide-up, skeleton shimmer, progress fill, status-colour transition, toast
slide-in, row-action reveal, sliding tab/selection underline, and slide-in SidePanel. Respect
`prefers-reduced-motion: reduce`; remove nonessential movement while preserving the state change.
Card lift, KPI count-up, bouncy easing, page-load fade, and row entrance animation remain banned.

## Make process chains visible

The process chain is a product differentiator, so show a compact stage summary in Order-to-Cash,
Procure-to-Pay, and Hire-to-Retire document lists when a row represents that chain. Use StageDots
plus the current stage text; expose blocked/overdue state with its label. In dashboards, put chain
stage counts in a “Needs attention” summary only when backed by real exception data. Detail pages
keep the full ChainHeader, LinkedRecords, and ActivityStream. Do not add chain cues to unrelated
lists or floor screens where the cue competes with the task.

## Floor adaptation

Floor routes keep the forced floor palette, 44px minimum targets, high contrast, large status
labels, and minimal chrome. Do not carry office StatusTabs, dense filter chips, hover-only actions,
or compact analytics strips onto shop-floor tablet screens. Any shared change must preserve the
floor route’s keyboard, touch, and gloved-use behavior.
