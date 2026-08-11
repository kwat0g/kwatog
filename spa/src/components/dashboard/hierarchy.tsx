/**
 * Dashboard hierarchy primitives — what makes one thing matter more than another.
 *
 * The problem these solve: every role dashboard was a uniform vertical stack of
 * `PanelRow` pairs, and `KpiGrid` gave every tile identical weight BY
 * CONSTRUCTION. A red-alert OEE rendered exactly like a routine headcount, and
 * the PPC head met 25 equal panels with nothing saying what mattered now.
 *
 * Weight comes from four things, in this order:
 *   1. POSITION   — the lede is first, the tail is last and folded away
 *   2. SIZE       — `LeadStat` spans two tracks and steps up the type scale
 *   3. BORDER     — `border-strong`, or a 3px severity rule on the leading edge
 *   4. TYPE SCALE — serif section labels; 32px lead number vs 26px on StatCard
 *
 * Not from shadows: DESIGN-SYSTEM.md reserves those for true overlays (menu,
 * modal, toast) because neutral-black shadows on warm paper read as dirt. Not
 * from translucency either — surfaces are opaque.
 *
 * Severity may PROMOTE a tile, but colour never travels alone: every severity
 * renders an icon and the words from `SEVERITY[s].label` beside it (WCAG 1.4.1).
 *
 * Calm is a constraint, not a nicety. Atelier is "unhurried", and eight
 * shouting tiles have no lede. `StatBand` takes exactly one `lead`.
 */
import { type ReactNode } from 'react';
import { AlertTriangle, AlertCircle, CheckCircle2, ChevronRight, Info, Minus } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Chip } from '@/components/ui/Chip';
import { SEVERITY, type Severity } from './severity';

/* ───────────────────────── Severity badge ───────────────────────── */

const SEVERITY_ICON = {
  critical: AlertCircle,
  warning: AlertTriangle,
  info: Info,
  ok: CheckCircle2,
  neutral: Minus,
} as const;

/**
 * Icon + word + tint. The three redundant encodings that let this read the same
 * to a colour-blind user, a greyscale printout, and a screen reader.
 */
export function SeverityBadge({
  severity,
  children,
  className,
}: {
  severity: Severity;
  /** Overrides the default wording when a panel has something sharper to say. */
  children?: ReactNode;
  className?: string;
}) {
  const tone = SEVERITY[severity];
  const Icon = SEVERITY_ICON[severity];
  return (
    <Chip variant={tone.chip} className={cn('gap-1', className)}>
      <Icon size={11} aria-hidden="true" />
      {children ?? tone.label}
    </Chip>
  );
}

/* ───────────────────────── Lead stat ───────────────────────── */

interface LeadStatProps {
  label: string;
  value: ReactNode;
  /** One short clause on why this is the lede. */
  helper?: ReactNode;
  severity?: Severity;
  /** Replaces the default severity wording in the badge. */
  severityLabel?: string;
  /** Rendered under the number — a mini bar, sparkline, or breakdown. */
  children?: ReactNode;
  action?: ReactNode;
  className?: string;
}

/**
 * The single dominant tile. Two grid tracks wide inside `StatBand`, one step up
 * the type scale from `StatCard` (32px vs 26px), `border-strong` instead of
 * `border-default`, and a 3px severity rule on the leading edge once severity
 * rises above `ok`.
 *
 * Deliberately NOT a variant of StatCard: that component is shared with pages
 * outside this tree and owned elsewhere. This composes alongside it instead.
 */
export function LeadStat({
  label,
  value,
  helper,
  severity = 'neutral',
  severityLabel,
  children,
  action,
  className,
}: LeadStatProps) {
  const tone = SEVERITY[severity];
  const promoted = severity === 'critical' || severity === 'warning';

  return (
    <section
      className={cn(
        'bg-surface border border-strong rounded-lg p-5 flex flex-col',
        // Leading-edge rule. `border-l-[3px]` + a semantic colour, so the
        // severity reads at a glance without a shadow or a fill wash.
        promoted && cn('border-l-[3px]', tone.edge),
        className,
      )}
      aria-label={`${label} — ${tone.label}`}
    >
      <div className="flex items-start justify-between gap-3 mb-2">
        <h2 className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</h2>
        {severity !== 'neutral' && (
          <SeverityBadge severity={severity}>{severityLabel}</SeverityBadge>
        )}
      </div>

      <div className="text-3xl font-medium font-mono tabular-nums text-primary leading-none">
        {value}
      </div>

      {helper && <p className="text-xs text-muted mt-2">{helper}</p>}

      {children && <div className="mt-3">{children}</div>}

      {action && <div className="mt-3 pt-3 border-t border-default">{action}</div>}
    </section>
  );
}

/* ───────────────────────── Stat band ───────────────────────── */

/**
 * The KPI row, but weighted: one `lead` spanning two tracks, then the
 * supporting tiles. Without `lead` it is a plain 4-up grid, so a dashboard with
 * no single most-important number is not forced to invent one.
 */
export function StatBand({
  lead,
  children,
  className,
}: {
  lead?: ReactNode;
  children?: ReactNode;
  className?: string;
}) {
  if (!lead) {
    return (
      <section className={cn('grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4', className)}>
        {children}
      </section>
    );
  }
  return (
    <section className={cn('grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4', className)}>
      {/* Two tracks on wide screens, full width below — the lede never shrinks
          to a quarter-tile on a tablet. */}
      <div className="sm:col-span-2 xl:col-span-2">{lead}</div>
      {children}
    </section>
  );
}

/* ───────────────────────── Sections ───────────────────────── */

/**
 * A named band of panels. The serif label is doing real work: it is the only
 * type on these pages set in `--font-display`, so it separates "what am I
 * looking at" from "what does it say" without a rule or a shadow.
 *
 * `tone`: `lede` for the first viewport, `support` for the scroll, `tail` for
 * reference material.
 */
export function DashSection({
  title,
  description,
  meta,
  tone = 'support',
  children,
  className,
}: {
  title: string;
  description?: string;
  /** Right-aligned — the operative period, a count, a control. */
  meta?: ReactNode;
  tone?: 'lede' | 'support' | 'tail';
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={cn('space-y-2.5', tone === 'lede' ? 'pt-1' : 'pt-2', className)}>
      <div className="flex items-baseline justify-between gap-3 flex-wrap">
        <div className="flex items-baseline gap-2.5 min-w-0">
          <h2
            className={cn(
              'font-display text-primary leading-none',
              tone === 'lede' ? 'text-xl' : 'text-lg',
              tone === 'tail' && 'text-muted',
            )}
          >
            {title}
          </h2>
          {description && <p className="text-xs text-muted truncate">{description}</p>}
        </div>
        {meta && <div className="text-xs text-muted shrink-0">{meta}</div>}
      </div>
      {children}
    </section>
  );
}

/**
 * The muted tail — reference panels a role consults rather than monitors.
 * Collapsed by default so 25 panels stop competing with 4; `<details>` keeps it
 * keyboard-reachable and findable by in-page search with no JS state.
 */
export function DashTail({
  title,
  summary,
  children,
  className,
}: {
  title: string;
  /** What is inside, so the user can decide without opening it. */
  summary?: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <details className={cn('group border-t border-default pt-3 mt-1', className)}>
      <summary
        className={cn(
          'flex items-center gap-1.5 cursor-pointer list-none select-none',
          'text-sm text-muted hover:text-primary transition-colors duration-fast',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded-sm',
        )}
      >
        <ChevronRight
          size={14}
          aria-hidden="true"
          className="shrink-0 transition-transform duration-fast group-open:rotate-90"
        />
        <span className="font-display text-lg leading-none text-muted group-hover:text-primary">
          {title}
        </span>
        {summary && <span className="text-xs text-subtle truncate">· {summary}</span>}
      </summary>
      <div className="space-y-3 pt-3">{children}</div>
    </details>
  );
}

/* ───────────────────────── Promoted panel frame ───────────────────────── */

/**
 * Wraps a `Panel` whose CONTENT has gone critical — a machine down, a material
 * shortage — without touching the panel itself. The severity rule sits on the
 * wrapper's leading edge, so panel bodies (which are genuinely good and
 * role-authored) stay untouched while their frame carries the weight.
 *
 * At `ok` / `neutral` it renders nothing but its children: no wrapper element,
 * no layout shift between states.
 */
export function PanelEmphasis({
  severity,
  children,
  className,
}: {
  severity: Severity;
  children: ReactNode;
  className?: string;
}) {
  if (severity !== 'critical' && severity !== 'warning') return <>{children}</>;
  return (
    <div
      className={cn(
        'rounded-md border-l-[3px] overflow-hidden',
        SEVERITY[severity].edge,
        className,
      )}
    >
      {children}
    </div>
  );
}
