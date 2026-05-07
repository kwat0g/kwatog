import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { echo } from '@/lib/echo';
import type { ChainEntityType, ChainStepEvent } from '@/types/chain';

/**
 * Series C — Task C4. Real-time chain progress.
 *
 * Subscribes to the per-entity private chain channel
 * (`private-chain.{entityType}.{hashId}`) and:
 *
 *  1. Invalidates the matching TanStack Query so the page refetches
 *     and the `<ChainHeader>` re-renders with the new active step.
 *  2. Shows a small toast naming the actor (when broadcast included one).
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
): void {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!entityId) return;
    const channelName = `chain.${entityType}.${entityId}`;
    const sub = echo.private(channelName);

    const handler = (payload: ChainStepEvent) => {
      // Invalidate the page's main query so it refetches.
      queryClient.invalidateQueries({ queryKey: [...queryKey] });

      // Only toast when somebody else triggered the change. We don't
      // know the current user's name here so we just always show it —
      // duplicate toasts on the actor's own browser are mild noise but
      // confirm the action landed.
      const stepLabel = humanize(payload.active_step);
      const who = payload.actor_name ? ` by ${payload.actor_name}` : '';
      toast(`${stepLabel} updated${who}`, { icon: '🔁', duration: 3500 });
    };

    sub.listen('.chain.step_advanced', handler as (e: unknown) => void);

    return () => {
      try {
        sub.stopListening('.chain.step_advanced');
      } catch {
        // ignore: channel was already gone (e.g. HMR teardown)
      }
      echo.leave(channelName);
    };
    // queryKey is intentionally dereferenced into a stable join string in
    // the dep list so callers can pass an inline array.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entityType, entityId, queryKey.join('|')]);
}

function humanize(step: string): string {
  return step
    .split('_')
    .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
    .join(' ');
}
