import { client } from './client';

/**
 * Sprint P4 — typed notifications API.
 *
 * Mirrors Laravel's default `notifications` table shape. The `data` column
 * is an opaque JSON blob; we widen the typed shape to surface the common
 * fields shipped by NotificationService::notify(): `title`, `message`,
 * `link_to`. Callers should treat anything else as untrusted.
 */

export interface NotificationRow {
  id: string;
  type: string;
  data: {
    title?: string;
    message?: string;
    link_to?: string;
    /** Backend may attach further per-type fields (e.g. po_number). */
    [key: string]: unknown;
  };
  read_at: string | null;
  created_at: string;
}

export interface NotificationListResponse {
  data: NotificationRow[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    unread_count: number;
  };
}

export interface ListNotificationsParams {
  page?: number;
  per_page?: number;
  unread_only?: boolean;
  type?: string;
}

export const notificationsApi = {
  list: (params?: ListNotificationsParams) =>
    client
      .get<NotificationListResponse>('/notifications', {
        params: {
          page: params?.page,
          per_page: params?.per_page ?? 25,
          unread_only: params?.unread_only ? 1 : 0,
          type: params?.type,
        },
      })
      .then((r) => r.data),

  markRead: (id: string) =>
    client.patch<{ data: { id: string; read_at: string } }>(`/notifications/${id}/read`).then((r) => r.data.data),

  markAllRead: () =>
    client.patch<{ data: { marked_read: number } }>('/notifications/read-all').then((r) => r.data.data),
};
