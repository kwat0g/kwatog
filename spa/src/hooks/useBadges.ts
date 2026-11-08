import { useQuery } from '@tanstack/react-query';
import { badgesApi, type BadgePayload } from '@/api/badges';
import { useAuthStore } from '@/stores/authStore';
import { useRealtimeSignal } from './useRealtimeSignal';

/**
 * Polish Task S2 — sidebar badge count system.
 *
 * Subscribes to the current user's private badge channel: when the server
 * broadcasts `BadgesChanged` after a badge-affecting write, the active query is refreshed.
 * The shared realtime provider handles catch-up on subscription, focus, and
 * reconnect, and visibility-aware polling only while delivery is degraded.
 * The server cache is version-busted server-side so the refetch is always fresh.
 */
export function useBadges(): {
  getBadge: (key: string | undefined) => BadgePayload | undefined;
} {
  const userId = useAuthStore((state) => state.user?.id);
  const { data } = useQuery({
    queryKey: ['sidebar', 'badges'],
    queryFn: () => badgesApi.get(),
    staleTime: 0,
  });

  useRealtimeSignal('badges_changed', userId ? `badge-user.${userId}` : '', '.BadgesChanged');

  return {
    getBadge: (key) => (key ? data?.[key] : undefined),
  };
}
