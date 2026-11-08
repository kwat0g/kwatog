import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/stores/authStore';
import { assignedWorkOrderInvalidationKeys, scheduleRealtimeQueryInvalidation } from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Refreshes only the active employee's assigned-work-order API query families. */
export function useAssignedWorkOrderRealtime(): void {
  const userId = useAuthStore((state) => state.user?.id);
  const queryClient = useQueryClient();
  const refresh = useCallback(() => {
    if (!userId) return;
    scheduleRealtimeQueryInvalidation(queryClient, assignedWorkOrderInvalidationKeys(userId));
  }, [queryClient, userId]);

  const channel = userId ? `production.operator.${userId}` : '';
  useRealtimeEvent(channel, '.chain.refresh', refresh, refresh, refresh);
  useRealtimeEvent(channel, '.output.recorded', refresh, refresh, refresh);
  useRealtimeEvent(channel, '.work_order.status_changed', refresh, refresh, refresh);
  useRealtimeEvent(channel, '.assigned.work_order.changed', refresh, refresh, refresh);
  useRealtimeEvent(channel, '.production.late-report.changed', refresh, refresh, refresh);
}
