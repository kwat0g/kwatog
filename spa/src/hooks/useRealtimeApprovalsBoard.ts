import { useRealtimeSignal } from './useRealtimeSignal';
import { useAuthStore } from '@/stores/authStore';

/** Refresh the permission-scoped approval inbox after the server signals a change. */
export function useRealtimeApprovalsBoard(): void {
  const userId = useAuthStore((state) => state.user?.id);
  useRealtimeSignal('badges_changed', userId ? `badge-user.${userId}` : '', '.BadgesChanged');
}
