/**
 * ONE severity table for every dashboard.
 *
 * Why this file exists — plant-manager.tsx carried three parallel maps of the
 * same five semantic states: one to CSS custom properties for Recharts
 * (`var(--success)`), and two to Tailwind classes for progress bars and alert
 * dots (`bg-success-bg`). Three sources of truth for one mapping, in one file.
 * Charts and bars therefore drifted apart, and the bar/dot branch had picked
 * the WRONG half of the token family.
 *
 * The `-bg` tokens are pale tints meant to sit BEHIND dark `-fg` text (that is
 * what Chip does). Used as a bar fill or a 6px dot they are decoration on
 * decoration: measured against the `--bg-subtle` track they land at 1.02–1.30:1
 * across all three palettes, versus the 3:1 floor WCAG 2.1 SC 1.4.11 sets for
 * non-text graphics. The saturated tokens clear it everywhere — 3.30:1
 * (light warning, the worst case) up to 10.58:1 (floor warning). So `fill` and
 * `dot` resolve to the SATURATED token and `chart` to the same value as a CSS
 * variable: one decision, two syntaxes, no second table.
 *
 * No text is ever placed on `fill`. When a caller needs ink on a saturated
 * fill it uses `--accent-fg` (paper-on-ink, declared per palette), which
 * measures 3.62–11.77:1 on these four hues. `text-*-fg` on `bg-*-bg` stays the
 * correct pairing for chips and remains what `chip` selects.
 *
 * `label` is not optional decoration. WCAG 1.4.1 forbids colour as the only
 * carrier of meaning, so every severity ships the words that say what the
 * colour says, and the components in hierarchy.tsx render them.
 */

/** The five states every dashboard signal collapses to. */
export type Severity = 'critical' | 'warning' | 'info' | 'ok' | 'neutral';

export interface SeverityTokens {
  /** Recharts / inline `style` — CSS custom property reference. */
  chart: string;
  /** Bar + meter fill. Saturated: 3.30–10.58:1 on the track. */
  fill: string;
  /** Status dot. Same saturated value as `fill`. */
  dot: string;
  /** Left rule on a promoted tile or panel. */
  edge: string;
  /** `<Chip variant>` — the pale-tint + dark-ink pairing chips are built for. */
  chip: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
  /** Ink on the page canvas, for a number that carries the severity itself. */
  text: string;
  /** The words the colour is saying. Never render the colour without these. */
  label: string;
  /** Sort weight — higher wins when ranking panels or tiles. */
  rank: number;
}

export const SEVERITY: Record<Severity, SeverityTokens> = {
  critical: {
    chart: 'var(--danger)',
    fill: 'bg-danger',
    dot: 'bg-danger',
    edge: 'border-danger',
    chip: 'danger',
    text: 'text-danger-fg',
    label: 'Needs action',
    rank: 4,
  },
  warning: {
    chart: 'var(--warning)',
    fill: 'bg-warning',
    dot: 'bg-warning',
    edge: 'border-warning',
    chip: 'warning',
    text: 'text-warning-fg',
    label: 'Watch',
    rank: 3,
  },
  info: {
    chart: 'var(--info)',
    fill: 'bg-info',
    dot: 'bg-info',
    edge: 'border-info',
    chip: 'info',
    text: 'text-info-fg',
    label: 'In progress',
    rank: 2,
  },
  ok: {
    chart: 'var(--success)',
    fill: 'bg-success',
    dot: 'bg-success',
    edge: 'border-success',
    chip: 'success',
    text: 'text-success-fg',
    label: 'On target',
    rank: 1,
  },
  neutral: {
    chart: 'var(--text-muted)',
    fill: 'bg-strong',
    dot: 'bg-strong',
    edge: 'border-strong',
    chip: 'neutral',
    text: 'text-muted',
    label: 'No signal',
    rank: 0,
  },
};

/**
 * Backend `color` / `severity` strings → severity key.
 *
 * Services emit the Tailwind-flavoured vocabulary (`success` / `danger`), not
 * ours, so this is the one place that translation happens rather than a ternary
 * chain per panel.
 */
export function toSeverity(raw: string | null | undefined): Severity {
  switch (raw) {
    case 'danger':
    case 'critical':
    case 'error':
    case 'urgent':
      return 'critical';
    case 'warning':
    case 'warn':
    case 'high':
      return 'warning';
    case 'info':
    case 'in_progress':
      return 'info';
    case 'success':
    case 'ok':
      return 'ok';
    default:
      return 'neutral';
  }
}

/**
 * Machine lifecycle → severity. A breakdown blocks Chain 1, so it is critical
 * rather than merely notable; idle and setup are capacity not yet earning.
 *
 * Separate from `toSeverity` on purpose: this maps a DOMAIN state, that maps a
 * severity vocabulary. Collapsing them is what produced the drift — the colour
 * values still live in exactly one table either way.
 */
export function machineSeverity(status: string): Severity {
  switch (status) {
    case 'breakdown':
    case 'down':
      return 'critical';
    case 'stopped':
    case 'maintenance':
      return 'warning';
    case 'idle':
    case 'setup':
      return 'warning';
    case 'running':
      return 'ok';
    default:
      return 'neutral';
  }
}

/** Highest severity in a set, or `neutral` when the set is empty. */
export function worstSeverity(items: readonly Severity[]): Severity {
  return items.reduce<Severity>(
    (worst, s) => (SEVERITY[s].rank > SEVERITY[worst].rank ? s : worst),
    'neutral',
  );
}

/**
 * Utilisation / coverage percentage → severity, given the thresholds the
 * backend already sends in `display_policy`. Thresholds stay server-side; only
 * the colour decision is ours.
 */
export function thresholdSeverity(
  pct: number,
  { critical, warning }: { critical?: number; warning?: number },
): Severity {
  if (critical != null && pct >= critical) return 'critical';
  if (warning != null && pct >= warning) return 'warning';
  return 'ok';
}
