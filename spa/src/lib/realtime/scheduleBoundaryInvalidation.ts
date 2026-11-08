/** Maximum delay accepted by browser setTimeout implementations. */
export const MAX_BOUNDARY_TIMEOUT_MS = 2_147_000_000;

/** Schedule one invalidation at a server-provided time boundary. */
export function scheduleBoundaryInvalidation(
  boundary: string,
  invalidate: () => void,
): () => void {
  const timestamp = Date.parse(boundary);
  const delay = timestamp - Date.now();
  if (!Number.isFinite(timestamp) || delay <= 0) return () => undefined;

  let timer: number | undefined;
  let cancelled = false;

  const scheduleNextChunk = (): void => {
    if (cancelled) return;

    const remaining = timestamp - Date.now();
    if (remaining <= 0) {
      invalidate();
      return;
    }

    timer = window.setTimeout(
      scheduleNextChunk,
      Math.min(remaining + 25, MAX_BOUNDARY_TIMEOUT_MS),
    );
  };

  scheduleNextChunk();

  return () => {
    cancelled = true;
    if (timer !== undefined) window.clearTimeout(timer);
  };
}
