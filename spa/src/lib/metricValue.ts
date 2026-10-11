import { formatInt, formatPeso } from '@/lib/formatNumber';
import type { MetricUnit } from '@/types/analytics';

/**
 * Format a metric figure by the unit the registry declares.
 *
 * Lives outside `MetricPanel` because a fast-refresh boundary may only export
 * components, and the formatter is worth testing on its own. Units come from
 * `MetricDefinition.unit` — the panel never guesses one from a value's shape.
 */
export function formatMetricValue(value: number, unit: MetricUnit): string {
  switch (unit) {
    case 'currency':
      return formatPeso(value);
    case 'percent':
      return `${value.toFixed(1)}%`;
    case 'hours':
      return `${value.toFixed(1)} h`;
    case 'days':
      return `${formatInt(value)} d`;
    case 'ratio':
      return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
    case 'count':
    default:
      return formatInt(value);
  }
}
