import { client } from '../client';
import type { PaginatedResponse, ListParams } from '@/types';

export interface AuditLogEntry {
  id: number;
  action: 'created' | 'updated' | 'deleted';
  model_type: string;
  model_id: number | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
  user?: { id: string; name: string; email: string; role: { name: string; slug: string } | null };
}

export interface AuditLogParams extends ListParams {
  action?: 'created' | 'updated' | 'deleted';
  model_type?: string;
  model_id?: string;
  user_id?: string;
  from?: string;
  to?: string;
}

export const auditLogsApi = {
  list: (params?: AuditLogParams) =>
    client.get<PaginatedResponse<AuditLogEntry>>('/admin/audit-logs', { params }).then((r) => r.data),

  /**
   * Entity-scoped audit trail — all changes to a specific record.
   * IATF compliance: "show me all changes to PO-202604-0015".
   */
  entityTrail: (modelType: string, modelId: string, params?: { page?: number }) =>
    client
      .get<PaginatedResponse<AuditLogEntry>>('/admin/audit-logs/entity', {
        params: { model_type: modelType, model_id: modelId, ...params },
      })
      .then((r) => r.data),

  /**
   * Sprint P7 — download a CSV of the same filtered query.
   *
   * Triggers a browser download via a transient `<a download>` so the auth
   * cookie travels with the request (no need to handle the blob in JS).
   * Filters mirror `list()` exactly so what you see is what you export.
   */
  exportUrl: (params?: AuditLogParams): string => {
    const search = new URLSearchParams();
    if (params) {
      for (const [key, value] of Object.entries(params)) {
        if (value === undefined || value === null || value === '') continue;
        if (key === 'page' || key === 'per_page') continue; // export ignores pagination
        search.set(key, String(value));
      }
    }
    const qs = search.toString();
    return `/api/v1/admin/audit-logs/export${qs ? `?${qs}` : ''}`;
  },

  /**
   * PDF export URL — same filter set as the list page, rendered as landscape PDF.
   */
  exportPdfUrl: (params?: AuditLogParams): string => {
    const search = new URLSearchParams();
    if (params) {
      for (const [key, value] of Object.entries(params)) {
        if (value === undefined || value === null || value === '') continue;
        if (key === 'page' || key === 'per_page') continue;
        search.set(key, String(value));
      }
    }
    const qs = search.toString();
    return `/api/v1/admin/audit-logs/export/pdf${qs ? `?${qs}` : ''}`;
  },
};
