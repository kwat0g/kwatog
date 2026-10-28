/** Stable, deterministic index for tint assignment; labels remain the identity. */
export function stableIndex(value: string, count: number): number {
  if (count < 1) return 0;
  const normalized = value.normalize('NFKC').trim().toLowerCase();
  let hash = 2166136261;
  for (let i = 0; i < normalized.length; i += 1) {
    hash = Math.imul(hash ^ normalized.charCodeAt(i), 16777619);
  }
  return (hash >>> 0) % count;
}
