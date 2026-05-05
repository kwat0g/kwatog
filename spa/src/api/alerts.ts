/**
 * Task A2 — Smart Alert Engine API client.
 *
 * Endpoints exposed by [`AlertController`](api/app/Common/Controllers/AlertController.php:1).
 */
import { client } from './client';
import type { Alert, AlertListParams, AlertUnreadCount } from '@/types/alerts';

interface PaginatedAlerts {
  data: Alert[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export const alertsApi = {
  list: (params?: AlertListParams) =>
    client.get<PaginatedAlerts>('/alerts', { params }).then(r => r.data),

  unreadCount: () =>
    client.get<{ data: AlertUnreadCount }>('/alerts/unread-count').then(r => r.data.data),

  dismiss: (id: string) =>
    client.patch<{ data: Alert }>(`/alerts/${id}/dismiss`).then(r => r.data.data),

  markRead: (id: string) =>
    client.patch<{ data: Alert }>(`/alerts/${id}/read`).then(r => r.data.data),
};
