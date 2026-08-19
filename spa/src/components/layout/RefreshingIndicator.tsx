// Series X / Task X5 — freshness for a page whose data moves under the user.
//
// Originally a "Refreshing…" pill that appeared only while a refetch was in
// flight, so between refetches — which is almost all of the time — a dashboard
// said nothing at all about how old its numbers were. On a screen that polls
// every 15 or 30 seconds and drives decisions, "as of when" is the question the
// user actually has.

import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Spinner } from '@/components/ui/Spinner';
import { useIsRefreshing } from '@/hooks/useIsRefreshing';

interface Props {
  /** queryKey prefix to watch — same shape as `useQuery({ queryKey })`. */
  queryKey: readonly unknown[];
  /** Override the in-flight label (default "Refreshing…"). */
  label?: string;
}

function formatAge(ms: number): string {
  const sec = Math.round(ms / 1000);
  if (sec < 10) return 'just now';
  if (sec < 60) return `${sec}s ago`;
  const min = Math.floor(sec / 60);
  if (min < 60) return `${min} min ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr}h ago`;
  return `${Math.floor(hr / 24)}d ago`;
}

/** Newest `dataUpdatedAt` across the matching queries, or null if none have data. */
function useLastUpdated(queryKey: readonly unknown[]): number | null {
  const queryClient = useQueryClient();
  const [, tick] = useState(0);

  // Nothing re-renders this component between refetches, so the age would
  // freeze at whatever it read on mount. Re-render on a slow interval instead;
  // 15s is finer than the smallest unit the label shows.
  useEffect(() => {
    const id = window.setInterval(() => tick((n) => n + 1), 15_000);
    return () => window.clearInterval(id);
  }, []);

  const stamps = queryClient
    .getQueryCache()
    .findAll({ queryKey })
    .map((q) => q.state.dataUpdatedAt)
    .filter((t) => t > 0);
  return stamps.length ? Math.max(...stamps) : null;
}

export function RefreshingIndicator({ queryKey, label = 'Refreshing…' }: Props) {
  const refreshing = useIsRefreshing(queryKey);
  const lastUpdated = useLastUpdated(queryKey);

  if (refreshing) {
    return (
      <span className="inline-flex items-center gap-1.5 text-2xs text-muted ml-2 align-middle">
        <Spinner size="sm" className="text-muted" />
        {label}
      </span>
    );
  }

  if (lastUpdated === null) return null;

  return (
    <span
      className="inline-flex items-center text-2xs text-muted ml-2 align-middle"
      title={new Date(lastUpdated).toLocaleString()}
    >
      Updated {formatAge(Date.now() - lastUpdated)}
    </span>
  );
}
