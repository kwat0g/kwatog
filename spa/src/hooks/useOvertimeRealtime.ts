import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { scheduleRealtimeListInvalidation } from '@/lib/realtime/invalidationMap';
import { useAuthStore } from '@/stores/authStore';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Personal overtime refreshes for the employee's separately scoped API. */
export function usePersonalOvertimeRealtime(): void {
  const queryClient = useQueryClient();
  const userHashId = useAuthStore((state) => state.user?.id);
  const refresh = useCallback(() => {
    scheduleRealtimeListInvalidation(queryClient, 'overtime_request');
  }, [queryClient]);

  useRealtimeEvent(
    userHashId ? `overtime.user.${userHashId}` : '',
    '.attendance.overtime.changed',
    refresh,
    refresh,
    refresh,
  );
}
