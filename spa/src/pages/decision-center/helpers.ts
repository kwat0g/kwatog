/**
 * DSS Wave 0-4 — pure display helpers (unit-tested, no API logic here).
 */
import type { RecommendationSeverity } from '@/types/dss';

export type SeverityVariant = 'danger' | 'warning' | 'info';

export const severityVariant = (s: RecommendationSeverity): SeverityVariant =>
  s === 'critical' ? 'danger' : s === 'warning' ? 'warning' : 'info';

export function formatImpact(
  estimate: string | null,
  unit: string | null,
  isEstimate: boolean,
): string | null {
  if (estimate === null) return null;
  const amount = unit ? `${estimate} ${unit}` : estimate;
  return isEstimate ? `~${amount}` : amount;
}
