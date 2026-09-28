/**
 * Pure function to compute pass/fail verdict for lot checklist inspections.
 * Server is authoritative; this is for UI preview only.
 *
 * `pending` is a first-class third state: a row with neither a reading nor an
 * explicit claim is unanswered, and an unanswered row must never preview as a
 * pass — the server refuses to complete such an inspection, and a client that
 * showed "will pass" over it is what shipped the 30-keystroke bug this panel
 * exists to delete.
 */
export type LotChecklistVerdict = 'pass' | 'fail' | 'pending';

export function computeLotChecklistVerdict(
  checklistItems: Array<{ is_critical: boolean; is_pass: boolean }>,
  numericItems: Array<{
    is_critical: boolean;
    sample_index: number;
    measured_value: string | null;
    tolerance_min: number | null;
    tolerance_max: number | null;
    /**
     * The row's claim, when it carries no reading: `true` from a ticked
     * dimension ("inspected, conforming"), `false` from an explicit NG mark.
     *
     * A claim never decides against a reading: wherever a value exists, the
     * tolerance evaluation is the result, exactly as the server computes it.
     * Absent (or null) means the row makes no claim.
     */
    is_pass?: boolean | null;
  }>,
  sampleDefectCount: number,
  acceptCount: number,
): { verdict: LotChecklistVerdict; reason?: string } {
  // Fail if any critical checklist item is NG
  for (const item of checklistItems) {
    if (item.is_critical && !item.is_pass) {
      return { verdict: 'fail', reason: 'Critical checklist item NG' };
    }
  }

  // Collect DISTINCT pieces (sample_index values) with at least one defect —
  // an out-of-tolerance reading, or an explicit NG mark on a piece with none.
  // A declared-out dimension contributes only the pieces it names: the tick
  // says nothing about which part was bad, so it must not invent one defect per
  // piece of the dimension.
  const piecesWithDefects = new Set<number>();
  let unanswered = 0;

  for (const m of numericItems) {
    if (m.measured_value !== null && m.measured_value !== '') {
      const numValue = Number(m.measured_value);
      const isInTolerance =
        numValue >= (m.tolerance_min ?? -Infinity) && numValue <= (m.tolerance_max ?? Infinity);
      if (!isInTolerance) {
        if (m.is_critical)
          return { verdict: 'fail', reason: 'Critical measurement out of tolerance' };
        piecesWithDefects.add(m.sample_index);
      }
      continue;
    }

    if (m.is_pass === true) continue;
    if (m.is_pass === false) {
      if (m.is_critical)
        return { verdict: 'fail', reason: 'Critical measurement out of tolerance' };
      piecesWithDefects.add(m.sample_index);
      continue;
    }

    unanswered += 1;
  }

  // Fail if defects exceed accept count
  const defectCount = Math.max(sampleDefectCount, piecesWithDefects.size);
  if (defectCount > acceptCount) {
    return { verdict: 'fail', reason: `Defects (${defectCount}) exceed Ac (${acceptCount})` };
  }

  if (unanswered > 0) {
    return {
      verdict: 'pending',
      reason: `${unanswered} measurement${unanswered === 1 ? '' : 's'} unanswered`,
    };
  }

  return { verdict: 'pass' };
}
