import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/stores/authStore';
import { scheduleRealtimeQueryInvalidation } from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Refresh only the authenticated driver's run-sheet queries from their permission-scoped channel. */
export function useDriverDeliveryRealtime(): void {
  const queryClient = useQueryClient();
  const userId = useAuthStore((state) => state.user?.id);
  const refresh = useCallback(() => {
    scheduleRealtimeQueryInvalidation(queryClient, [['driver']]);
  }, [queryClient]);

  useRealtimeEvent(
    userId ? `driver.${userId}` : '',
    '.chain.refresh',
    refresh,
    refresh,
    refresh,
  );
}
