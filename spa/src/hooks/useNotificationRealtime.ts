import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { echo } from '@/lib/echo';
import { useAuthStore } from '@/stores/authStore';

interface NotificationPayload {
  id: string;
  type: string;
  data: { title?: string; message?: string; link_to?: string };
  read_at: null;
  created_at: string;
}

export function useNotificationRealtime(): void {
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);

  useEffect(() => {
    if (!user?.id) return;

    const channel = echo.private(`user.${user.id}`);

    channel.listen('.notification.created', (payload: NotificationPayload) => {
      qc.invalidateQueries({ queryKey: ['notifications'] });

      const title = payload.data?.title ?? 'New notification';
      toast(title, { icon: '🔔', duration: 4000 });
    });

    return () => {
      try {
        channel.stopListening('.notification.created');
      } catch {
        // ignore HMR teardown
      }
      echo.leave(`user.${user.id}`);
    };
  }, [user?.id, qc]);
}
