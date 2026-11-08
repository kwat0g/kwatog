import { LuBell } from '@/lib/icons';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { useAuthStore } from '@/stores/authStore';
import { getNotificationById } from '@/api/notifications';
import { notificationMeta } from '@/lib/notificationMeta';
import { scheduleRealtimeQueryInvalidation } from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';
import type { QueryKey } from '@tanstack/react-query';

interface NotificationPayload {
  action?: string;
  id?: string;
  type?: string;
}

export interface NotificationRealtimeOptions {
  /** Portal principals use their own guard and private per-user channel. */
  channel?: string;
  /** The notification query family to refresh for this principal. */
  queryKey?: QueryKey;
  /** Internal notices fetch a detail for the toast; portal pings stay metadata-only. */
  showToast?: boolean;
}

export function useNotificationRealtime(options: NotificationRealtimeOptions = {}): void {
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const queryKey = options.queryKey ?? ['notifications'];
  const channel = options.channel ?? (user?.id ? `user.${user.id}` : '');
  const showToast = options.showToast ?? options.channel === undefined;
  const catchUp = () => {
    scheduleRealtimeQueryInvalidation(queryClient, [queryKey]);
  };

  useRealtimeEvent<NotificationPayload>(
    channel,
    options.channel ? '.portal.notification.changed' : '.notification.created',
    (payload) => {
      scheduleRealtimeQueryInvalidation(queryClient, [queryKey]);
      if (!showToast || !payload.id || !payload.type) return;

      void getNotificationById(payload.id)
        .then((notification) => {
          const title = notification.data?.title ?? notificationMeta(payload.type).label;
          toast(title, { icon: <LuBell size={16} className="text-muted" />, duration: 4000 });
        })
        .catch(() => undefined);
    },
    catchUp,
    catchUp,
  );

  // A user's read/delete action also needs to refresh the same inbox in their
  // other tabs. Portal bells already use one portal-specific changed event.
  useRealtimeEvent<NotificationPayload>(
    options.channel ? '' : channel,
    '.notification.changed',
    catchUp,
    catchUp,
    catchUp,
  );
}
