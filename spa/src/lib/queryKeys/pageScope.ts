/** Compare a paginated query key while ignoring only the page field in its filters object. */
export function sameQueryExceptPage(
  previousKey: readonly unknown[] | undefined,
  currentKey: readonly unknown[],
  filterIndex: number,
): boolean {
  if (!previousKey || previousKey.length !== currentKey.length) return false;

  return currentKey.every((currentPart, index) => {
    const previousPart = previousKey[index];
    if (index !== filterIndex) return stableSerialize(previousPart) === stableSerialize(currentPart);
    return stableSerialize(withoutPage(previousPart)) === stableSerialize(withoutPage(currentPart));
  });
}

function withoutPage(value: unknown): unknown {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return value;
  const copy = { ...(value as Record<string, unknown>) };
  delete copy.page;
  return copy;
}

function stableSerialize(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(stableSerialize).join(',')}]`;
  if (typeof value === 'object' && value !== null) {
    if (value instanceof Date) return JSON.stringify(value.toJSON());
    const record = value as Record<string, unknown>;
    if (Object.getPrototypeOf(value) !== Object.prototype && Object.getPrototypeOf(value) !== null) {
      return JSON.stringify(value) ?? String(value);
    }
    return `{${Object.keys(record).sort().map((key) => `${JSON.stringify(key)}:${stableSerialize(record[key])}`).join(',')}}`;
  }
  return JSON.stringify(value) ?? 'undefined';
}
