import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { getEcho } from '@/lib/echo';
import type { ChainEntityType } from '@/types/chain';

/**
 * Series C — Task C4. Real-time chain progress.
 *
 * Subscribes to the per-entity private chain channel
 * (`private-chain.{entityType}.{hashId}`) and:
 *
 * 1. Invalidates the matching TanStack Query so the page refetches
 * and the `<ChainHeader>` re-renders with the new active step.
 * 2. Shows a small toast naming the actor (when broadcast included one).
 *
 * Unmount tears down the subscription. Safe to render on a page that
 * may not have a hashId yet (no-op until both args are non-empty).
 */
export function useChainProgress(
  entityType: ChainEntityType,
  entityId: string | undefined,
  /**
   * The TanStack Query key that should be invalidated when an event
   * arrives. Pass the same key the page's main query uses (e.g.
   * `['salesOrder', id]`). Memoize the array on the caller's side or
   * accept that the effect re-binds when the key reference changes.
   */
  queryKey: ReadonlyArray<unknown>,
  additionalQueryKeys: ReadonlyArray<ReadonlyArray<unknown>> = [],
): void {
  const queryClient = useQueryClient();

  // Join signatures are computed here so callers may pass inline query-key
  // arrays without rebinding the channel subscription on every render.
  const queryKeySignature = queryKey.join('|');
  const additionalQueryKeysSignature = additionalQueryKeys
    .map((key) => key.join('|'))
    .join('::');

  useEffect(() => {
    if (!entityId) return;
    const channelName = `chain.${entityType}.${entityId}`;
    const refresh = () => {
      [queryKey, ...additionalQueryKeys].forEach((key) => {
        void queryClient.invalidateQueries({ queryKey: [...key] });
      });
    };
    // A missed broadcast must not leave a visible record stuck at an old step.
    const poll = window.setInterval(() => {
      if (document.visibilityState === 'visible') refresh();
    }, 30_000);

    // Echo loads on demand (see `@/lib/echo`); subscribe once it resolves and
    // skip entirely if we unmounted while the import was in flight.
    let disposed = false;
    let teardown: (() => void) | undefined;

    void getEcho().then((echo) => {
      if (disposed) return;
      const sub = echo.private(channelName);

      const handler = () => refresh();

      sub.listen('.chain.refresh', handler as (e: unknown) => void);

      teardown = () => {
        try {
          sub.stopListening('.chain.refresh');
        } catch {
          // ignore: channel was already gone (e.g. HMR teardown)
        }
        echo.leave(channelName);
      };
    }).catch((error: unknown) => {
      console.error('Real-time chain updates are unavailable.', error);
    });

    return () => {
      disposed = true;
      window.clearInterval(poll);
      teardown?.();
    };
    // The effect body intentionally reads the raw arrays (never the joined
    // signatures) — the dep list below tracks the signatures so inline
    // arrays don't rebind the subscription, while the handler still sees
    // the freshest keys for invalidation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    entityType,
    entityId,
    queryKeySignature,
    additionalQueryKeysSignature,
    queryClient,
  ]);
}
