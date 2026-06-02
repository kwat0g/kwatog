import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { badgesApi, type BadgePayload } from '@/api/badges';
import { echo } from '@/lib/echo';

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
    const channel = echo.private('badges');
    channel.listen('.BadgesChanged', () => {
      queryClient.invalidateQueries({ queryKey: ['sidebar', 'badges'] });
    });
    return () => {
      channel.stopListening('.BadgesChanged');
      echo.leave('private-badges');
    };
  }, [queryClient]);

  return {
    getBadge: (key) => (key ? data?.[key] : undefined),
  };
}
