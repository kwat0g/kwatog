/* eslint-disable react-refresh/only-export-components -- colocated with the period control it describes. */
/**
 * Dashboard period scope — making the operative window visible next to the number.
 *
 * The problem: only plant-manager had a real date-range control. Seven other
 * dashboards froze their window into panel-title prose — 'Payroll pipeline
 * (last 90 days)', 'QC Chain Coverage (This Week)', 'AP due this week'. The
 * period lived only in a title the reader had to hold in working memory while
 * reading the figure two lines below.
 *
 * Two of those strings are also drift-prone. The finance payroll window is the
 * setting `dashboard.finance.payroll_pipeline_history_days` (seeded 90) and the
 * AP window is `dashboard.widgets.ap_due_horizon_days` (seeded 7). The titles
 * matched those seeds when written, but they are operator-configurable rows in
 * the `settings` table, and the API already returns the value it used on every
 * response. Retune either setting and the prose silently starts lying.
 * `PeriodNote` renders the number the server actually queried with, so the
 * label cannot drift from the query again.
 *
 * The split this file enforces:
 *   `PeriodControl` — the window is a CHOICE the backend honours.
 *   `PeriodNote`    — the window is FIXED by the query; state it plainly.
 *
 * A control that doesn't change the response is worse than no control, so
 * `PeriodControl` is only mounted where a `range` parameter genuinely reaches
 * the service.
 */
import { SegmentedControl } from '@/components/ui/SegmentedControl';

/** The four windows `PlantManagerDashboardService::rangeBounds()` implements. */
export type DashboardRange = 'today' | 'week' | 'month' | 'quarter';

/**
 * Labels use plant vocabulary only where it is literally true of the query.
 *
 * `today` is `startOfDay … endOfDay`, which on a three-shift plant is exactly
 * "all shifts today" — so that phrasing is accurate, not decorative. What is
 * deliberately NOT here is a per-shift preset (A/B/C) or a semi-monthly payroll
 * cutoff (1st–15th / 16th–EOM). Neither exists in `rangeBounds()`; the service
 * silently coerces an unknown `range` back to `week`, so offering either would
 * render a control that looks operative and quietly lies. Both are listed as
 * backend handoffs instead.
 */
const RANGE_OPTIONS: ReadonlyArray<{ value: DashboardRange; label: string; title: string }> = [
  { value: 'today', label: 'Today', title: 'Midnight to midnight — all shifts today' },
  { value: 'week', label: 'Week', title: 'Calendar week to date' },
  { value: 'month', label: 'Month', title: 'Calendar month to date — not a payroll cutoff' },
  { value: 'quarter', label: 'Quarter', title: 'Calendar quarter to date' },
];

export function PeriodControl({
  value,
  onChange,
  className,
}: {
  value: DashboardRange;
  onChange: (v: DashboardRange) => void;
  className?: string;
}) {
  return (
    <SegmentedControl
      label="Reporting period"
      value={value}
      onChange={onChange}
      size="sm"
      options={RANGE_OPTIONS.map((o) => ({ value: o.value, label: o.label }))}
      className={className}
    />
  );
}

/** Human phrasing for the active range, for a section `meta` slot. */
export function rangeLabel(range: DashboardRange): string {
  switch (range) {
    case 'today':
      return 'Today, all shifts';
    case 'week':
      return 'This calendar week';
    case 'month':
      return 'This calendar month';
    case 'quarter':
      return 'This calendar quarter';
  }
}

/**
 * States a window the reader cannot change, in the section header beside the
 * panels it governs — so one statement covers a band instead of each panel
 * title carrying its own frozen, drift-prone copy.
 *
 * `days` comes from the API response wherever the server exposes it. When it is
 * absent we say so rather than guessing a number: an honest "window not
 * reported" beats a confident wrong one.
 */
export function PeriodNote({
  days,
  fallback,
  prefix = 'Window',
  direction = 'past',
}: {
  /** Server-reported window length. */
  days?: number | null;
  /** Used when the server does not report a number, e.g. 'current calendar week'. */
  fallback?: string;
  prefix?: string;
  /**
   * Which way the window runs from today. A history window ('past', the
   * default) reads "last 90 days"; a due/horizon window reads "next 7 days".
   * Not cosmetic — labelling the AP due horizon as history would invert what
   * the figure means to someone deciding what to pay.
   */
  direction?: 'past' | 'future';
}) {
  const body =
    days != null && Number.isFinite(days)
      ? `${direction === 'future' ? 'next' : 'last'} ${days} ${days === 1 ? 'day' : 'days'}`
      : (fallback ?? 'not reported');

  return (
    <span className="text-xs text-muted">
      {prefix}: <span className="font-mono tabular-nums text-secondary">{body}</span>
    </span>
  );
}
