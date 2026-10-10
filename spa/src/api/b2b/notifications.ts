import { createPortalClient, type PortalRealm } from './client';
import type { ListNotificationsParams, NotificationListResponse, notificationsApi } from '@/api/notifications';

function portalNotifications(realm: PortalRealm): typeof notificationsApi {
  const { client } = createPortalClient(realm);
  const path = `/b2b/${realm}/notifications`;
  return {
    list: (params?: ListNotificationsParams) => client.get<NotificationListResponse>(path, {
      params: { ...params, unread_only: params?.unread_only ? 1 : 0 },
    }).then((response) => response.data),
    markRead: (id: string) => client.patch<{ data: { id: string; read_at: string } }>(`${path}/${id}/read`).then((response) => response.data.data),
    markAllRead: () => client.patch<{ data: { marked_read: number } }>(`${path}/read-all`).then((response) => response.data.data),
    remove: (id: string) => client.delete<{ data: { deleted: boolean } }>(`${path}/${id}`).then((response) => response.data.data),
    clearRead: () => client.delete<{ data: { deleted: number } }>(`${path}/clear-read`).then((response) => response.data.data),
  };
}

export const portalNotificationsApi = {
  customer: portalNotifications('customer'),
  supplier: portalNotifications('supplier'),
};
