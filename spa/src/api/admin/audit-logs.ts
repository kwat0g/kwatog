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
  user?: { id: string; name: string; email: string };
}

export interface AuditLogParams extends ListParams {
  action?: 'created' | 'updated' | 'deleted';
  model_type?: string;
  user_id?: string;
  from?: string;
  to?: string;
}

export const auditLogsApi = {
  list: (params?: AuditLogParams) =>
    client.get<PaginatedResponse<AuditLogEntry>>('/admin/audit-logs', { params }).then((r) => r.data),
};
