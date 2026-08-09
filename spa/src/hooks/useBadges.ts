import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { badgesApi, type BadgePayload } from '@/api/badges';
import { getEcho } from '@/lib/echo';

const POLL_MS = 60_000;

/**
 * Polish Task S2 — sidebar badge count system.
 *
 * Polls `/dashboards/badges` every 60s as a safety net AND subscribes to the
 * private `badges` channel: when the server broadcasts `BadgesChanged` (after
 * any badge-affecting write) we invalidate the query for an instant refresh.
 * The server cache is version-busted server-side so the refetch is always
 * fresh.
 */
export function useBadges(): {
  getBadge: (key: string | undefined) => BadgePayload | undefined;
} {
  const queryClient = useQueryClient();

  const { data } = useQuery({
    queryKey: ['sidebar', 'badges'],
    queryFn: () => badgesApi.get(),
    refetchInterval: POLL_MS,
    refetchIntervalInBackground: false,
    staleTime: 15_000,
  });

  useEffect(() => {
    // Echo loads on demand (see `@/lib/echo`); subscribe once it resolves and
    // skip entirely if we unmounted while the import was in flight.
    let disposed = false;
    let teardown: (() => void) | undefined;

    void getEcho().then((echo) => {
      if (disposed) return;
      const channel = echo.private('badges');
      channel.listen('.BadgesChanged', () => {
        queryClient.invalidateQueries({ queryKey: ['sidebar', 'badges'] });
      });
      teardown = () => {
        channel.stopListening('.BadgesChanged');
        echo.leave('private-badges');
      };
    });

    return () => {
      disposed = true;
      teardown?.();
    };
  }, [queryClient]);

  return {
    getBadge: (key) => (key ? data?.[key] : undefined),
  };
}
