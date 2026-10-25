import { useEffect } from 'react';
import { LuBell } from '@/lib/icons';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { getEcho } from '@/lib/echo';
import { useAuthStore } from '@/stores/authStore';
import { getNotificationById } from '@/api/notifications';
import { notificationMeta } from '@/lib/notificationMeta';

interface NotificationPayload {
  id: string;
  type: string;
}

export function useNotificationRealtime(enabled = true): void {
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);

  useEffect(() => {
    if (!enabled || !user?.id) return;

    // Echo loads on demand (see `@/lib/echo`); subscribe once it resolves and
    // skip entirely if we unmounted while the import was in flight.
    let disposed = false;
    let teardown: (() => void) | undefined;

    void getEcho()
      .then((echo) => {
        if (disposed) return;

        const channel = echo.private(`user.${user.id}`);

        channel.listen('.notification.created', (payload: NotificationPayload) => {
          qc.invalidateQueries({ queryKey: ['notifications'] });
          void getNotificationById(payload.id).then((notification) => {
            const title = notification.data?.title ?? notificationMeta(notification.type).label;
            toast(title, { icon: <LuBell size={16} className="text-muted" />, duration: 4000 });
          }).catch(() => undefined);
        });

        teardown = () => {
          try {
            channel.stopListening('.notification.created');
          } catch {
            // ignore HMR teardown
          }
          echo.leave(`user.${user.id}`);
        };
      })
      .catch(() => {
        // The bell's polling query remains the delivery fallback when Reverb is unavailable.
      });

    return () => {
      disposed = true;
      teardown?.();
    };
  }, [enabled, user?.id, qc]);
}
