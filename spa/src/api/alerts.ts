/**
 * Task A2 — Smart Alert Engine API client.
 *
 * Endpoints exposed by [`AlertController`](api/app/Common/Controllers/AlertController.php:1).
 */
import { client } from './client';
import type { Alert, AlertListParams, AlertUnreadCount, AlertSeverity, AlertType } from '@/types/alerts';

interface PaginatedAlerts {
 data: Alert[];
 meta: {
 current_page: number;
 last_page: number;
 per_page: number;
 total: number;
 from: number | null;
 to: number | null;
 };
}

export interface AlertOptions {
 types: Array<{ value: AlertType; label: string }>;
 severities: Array<{ value: AlertSeverity; label: string }>;
}

export const alertsApi = {
 options: () =>
 client.get<{ data: AlertOptions }>('/alerts/options').then(r => r.data.data),
 list: (params?: AlertListParams) =>
 client.get<PaginatedAlerts>('/alerts', { params }).then(r => r.data),

 unreadCount: () =>
 client.get<{ data: AlertUnreadCount }>('/alerts/unread-count').then(r => r.data.data),

 dismiss: (id: string) =>
 client.patch<{ data: Alert }>(`/alerts/${id}/dismiss`).then(r => r.data.data),

 markRead: (id: string) =>
 client.patch<{ data: Alert }>(`/alerts/${id}/read`).then(r => r.data.data),
};
