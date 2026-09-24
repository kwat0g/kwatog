/**
 * Pure function to compute pass/fail verdict for lot checklist inspections.
 * Server is authoritative; this is for UI preview only.
 */
export function computeLotChecklistVerdict(
  checklistItems: Array<{ is_critical: boolean; is_pass: boolean }>,
  numericItems: Array<{
    is_critical: boolean;
    sample_index: number;
    measured_value: string | null;
    tolerance_min: number | null;
    tolerance_max: number | null;
  }>,
  sampleDefectCount: number,
  acceptCount: number,
): { verdict: 'pass' | 'fail'; reason?: string } {
  // Fail if any critical checklist item is NG
  for (const item of checklistItems) {
    if (item.is_critical && !item.is_pass) {
      return { verdict: 'fail', reason: 'Critical checklist item NG' };
    }
  }

  // Collect DISTINCT pieces (sample_index values) that have at least one out-of-tolerance measurement
  const piecesWithDefects = new Set<number>();
  for (const m of numericItems) {
    if (m.measured_value === null || m.measured_value === '') continue;
    const numValue = Number(m.measured_value);
    const isInTolerance =
      numValue >= (m.tolerance_min ?? -Infinity) && numValue <= (m.tolerance_max ?? Infinity);
    if (!isInTolerance) {
      if (m.is_critical)
        return { verdict: 'fail', reason: 'Critical measurement out of tolerance' };
      piecesWithDefects.add(m.sample_index);
    }
  }

  // Fail if defects exceed accept count
  const defectCount = Math.max(sampleDefectCount, piecesWithDefects.size);
  if (defectCount > acceptCount) {
    return { verdict: 'fail', reason: `Defects (${defectCount}) exceed Ac (${acceptCount})` };
  }

  return { verdict: 'pass' };
}
