/**
 * The one implementation of the 0–100 rule for progress values.
 *
 * A progress bar has two invariants that a raw API number cannot be trusted to
 * hold: the fill must not be wider than its track, and an `aria-valuenow` must
 * not exceed its own `aria-valuemax`. Both were violated in production — the QC
 * coverage panel divided inspections by GRNs and rendered a 200% fill under
 * `aria-valuemax={100}` — and the same unguarded `width: ${percent}%` shape is
 * spread across the role dashboards.
 *
 * `Number.isFinite` matters as much as the range clamp: a ratio computed as
 * `count / max` with an unguarded `max` yields `NaN`, and `width: NaN%` is an
 * invalid declaration that silently drops the fill to zero rather than failing
 * loudly.
 *
 * `ProgressBar` uses this too, so the rule has exactly one definition. Call it
 * at any site that writes a progress width or an ARIA value by hand.
 */
export function clampPercent(value: number): number {
  if (!Number.isFinite(value)) return 0;
  return Math.min(100, Math.max(0, Math.round(value)));
}
