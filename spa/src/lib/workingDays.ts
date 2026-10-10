/** Monday–Saturday: the schedule assumed when an assignment has no working days configured. */
export const DEFAULT_WORKING_DAYS: number[] = [1, 2, 3, 4, 5, 6];

/** Order-insensitive equality of two ISO-weekday lists; null/undefined count as empty. */
export function sameDays(
  a: readonly number[] | null | undefined,
  b: readonly number[] | null | undefined,
): boolean {
  const x = [...(a ?? [])].sort((p, q) => p - q);
  const y = [...(b ?? [])].sort((p, q) => p - q);

  return x.length === y.length && x.every((d, i) => d === y[i]);
}
