/* eslint-disable react-refresh/only-export-components -- usePausedMutationCount is colocated with the banner that reports its count. */
import { useEffect, useState } from 'react';
import { LuWifiOff, LuCloudUpload } from '@/lib/icons';
import { useMutationState } from '@tanstack/react-query';
import { cn } from '@/lib/cn';

/**
 * Where the banner sits. `cn` is plain clsx with no tailwind-merge, so these
 * cannot be overridden by appending a conflicting class — pick one.
 *
 * `below-topbar` is AppLayout's geometry: its Topbar is 48px, so the banner
 * sticks at top-12 underneath it. The touch shells sticky-position their own
 * header and render the banner inside it, where normal flow is correct — the
 * hardcoded top-12 put it 48px *below* a 0px offset header and left a gap.
 */
type Placement = 'below-topbar' | 'in-header';

const placementClasses: Record<Placement, string> = {
  'below-topbar': 'sticky top-12 z-30',
  'in-header': 'relative z-30',
};

interface OfflineBannerProps {
  placement?: Placement;
}

/**
 * Counts mutations TanStack Query has paused rather than sent.
 *
 * `queryClient` sets `networkMode: 'offlineFirst'` on mutations, so a tap made
 * without a route to the API does not fail — it parks in the mutation cache
 * with `state.isPaused === true` and the calling component sits on
 * `isPending` forever. On the floor that renders as a "Recording…" spinner
 * with no end, which reads as "the server is thinking" when it actually means
 * "nothing has left this tablet". This is the number that distinguishes them.
 *
 * `MutationFilters` has no isPaused field, so the check goes through
 * `predicate` — that is the real v5 API, verified against the installed
 * @tanstack/react-query 5.100.8 types (MutationStateOptions.filters.predicate).
 */
export function usePausedMutationCount(): number {
  return useMutationState({
    filters: { predicate: (mutation) => mutation.state.isPaused },
    select: (mutation) => mutation.mutationId,
  }).length;
}

/**
 * Sticky banner shown when the browser reports it is offline, or when any
 * mutation is sitting in the paused queue.
 *
 * The queue is the reason this renders on more than `navigator.onLine`. In a
 * steel-framed plant the usual failure is an associated access point with no
 * route to the API: `onLine` stays true, the banner used to stay hidden, and
 * the operator got no signal at all that his commit had not landed.
 */
export function OfflineBanner({ placement = 'below-topbar' }: OfflineBannerProps) {
  const [offline, setOffline] = useState(false);
  const queued = usePausedMutationCount();

  useEffect(() => {
    const onOnline = () => setOffline(false);
    const onOffline = () => setOffline(true);
    setOffline(!navigator.onLine);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    return () => {
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []);

  if (!offline && queued === 0) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      className={cn(
        'px-4 py-2 bg-warning-bg border-b border-warning text-warning-fg',
        'text-sm flex flex-wrap items-center justify-center gap-x-2 gap-y-0.5 text-center',
        placementClasses[placement],
      )}
    >
      {offline ? (
        <>
          <LuWifiOff size={14} aria-hidden />
          <span className="font-medium">You are offline.</span>
        </>
      ) : (
        <>
          <LuCloudUpload size={14} aria-hidden />
          <span className="font-medium">Waiting for the server.</span>
        </>
      )}
      {queued > 0 ? (
        <span>
          <span className="font-mono tabular-nums font-medium">{queued}</span>{' '}
          {queued === 1 ? 'action is' : 'actions are'} queued on this device and will send when the
          connection returns. Do not re-enter them.
        </span>
      ) : (
        <span className="opacity-80">
          Anything you record now is queued on this device until the connection returns.
        </span>
      )}
    </div>
  );
}
