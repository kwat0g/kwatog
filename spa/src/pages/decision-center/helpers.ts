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

export function formatConfidence(confidence: string | null): string {
  if (confidence === null) return 'n/a';
  return `${Math.round(Number(confidence) * 100)}%`;
}

/** Flatten the rationale JSON into label/value rows for the "Why this?" panel. */
export function rationaleEntries(rationale: Record<string, unknown>): Array<[string, string]> {
  return Object.entries(rationale).map(([key, value]) => [
    key.replace(/_/g, ' '),
    typeof value === 'object' ? JSON.stringify(value) : String(value ?? '—'),
  ]);
}
