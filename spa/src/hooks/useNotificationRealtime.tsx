import { useEffect } from 'react';
import { LuBell } from '@/lib/icons';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { getEcho } from '@/lib/echo';
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

    // Echo loads on demand (see `@/lib/echo`); subscribe once it resolves and
    // skip entirely if we unmounted while the import was in flight.
    let disposed = false;
    let teardown: (() => void) | undefined;

    void getEcho().then((echo) => {
      if (disposed) return;

      const channel = echo.private(`user.${user.id}`);

      channel.listen('.notification.created', (payload: NotificationPayload) => {
        qc.invalidateQueries({ queryKey: ['notifications'] });

        const title = payload.data?.title ?? 'New notification';
        toast(title, { icon: <LuBell size={16} className="text-muted" />, duration: 4000 });
      });

      teardown = () => {
        try {
          channel.stopListening('.notification.created');
        } catch {
          // ignore HMR teardown
        }
        echo.leave(`user.${user.id}`);
      };
    });

    return () => {
      disposed = true;
      teardown?.();
    };
  }, [user?.id, qc]);
}
