import { ProgressBar } from '@/components/ui/ProgressBar';
import type { WidgetBreakdownData, WidgetSegmentTone } from '@/api/dashboard-layout';

/**
 * A breakdown widget: one labelled bar per segment, share-of-total width.
 *
 * Deliberately bars rather than a donut — at widget size a donut needs a
 * legend to be readable, which costs more vertical space than the bars it
 * would replace. Hierarchy comes from the row rule and the tone fill, per the
 * design system's borders-not-shadows rule.
 */

const toneVariant: Record<WidgetSegmentTone, 'accent' | 'success' | 'info' | 'warning' | 'danger'> =
  {
    neutral: 'accent',
    success: 'success',
    info: 'info',
    warning: 'warning',
    danger: 'danger',
  };

export function WidgetBreakdown({ total, segments }: WidgetBreakdownData) {
  if (segments.length === 0) {
    return <p className="text-xs text-muted">Nothing to break down.</p>;
  }

  return (
    <div className="space-y-2">
      <div className="text-2xl font-mono tabular-nums font-medium text-primary">
        {total.toLocaleString()}
      </div>

      <ul className="divide-y divide-subtle">
        {segments.map((segment) => {
          // Guard the zero-total case: a bar of 0/0 should read as empty, not full.
          const share = total > 0 ? (segment.value / total) * 100 : 0;

          return (
            <li key={segment.label} className="space-y-1 py-1.5 first:pt-0 last:pb-0">
              <div className="flex items-baseline justify-between gap-2">
                <span className="truncate text-xs text-secondary">{segment.label}</span>
                <span className="font-mono text-xs tabular-nums text-primary">
                  {segment.value.toLocaleString()}
                </span>
              </div>
              <ProgressBar value={share} variant={toneVariant[segment.tone]} />
            </li>
          );
        })}
      </ul>
    </div>
  );
}
