import { client } from '../client';
import type { PaginatedResponse, ListParams, ApiSuccess } from '@/types';

/**
 * Series R — Task R1.
 *
 * Extended `Role` shape includes `is_system` so the UI can render the
 * System / Custom badge and disable destructive actions on seeded roles.
 */
export interface Role {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  /** Series R/R1 — true when seeded by RolePermissionSeeder; immutable via UI. */
  is_system: boolean;
  /** Display label derived from `is_system`: 'System' | 'Custom'. */
  type: 'System' | 'Custom';
  users_count?: number;
  permissions_count?: number;
  permissions?: { id: string; slug: string; name: string; module: string }[];
  created_at: string;
  updated_at: string;
}

export interface CreateRoleData {
  name: string;
  slug: string;
  description?: string | null;
}

export interface UpdateRoleData {
  name?: string;
  slug?: string;
  description?: string | null;
}

export interface CloneRoleData {
  name: string;
  slug: string;
  description?: string | null;
}

export interface RoleListParams extends ListParams {
  is_system?: boolean | '';
}

export const rolesApi = {
  list: (params?: RoleListParams) =>
    client.get<PaginatedResponse<Role>>('/admin/roles', { params }).then((r) => r.data),

  show: (id: string) =>
    client.get<ApiSuccess<Role>>(`/admin/roles/${id}`).then((r) => r.data.data),

  create: (data: CreateRoleData) =>
    client.post<ApiSuccess<Role>>('/admin/roles', data).then((r) => r.data.data),

  update: (id: string, data: UpdateRoleData) =>
    client.put<ApiSuccess<Role>>(`/admin/roles/${id}`, data).then((r) => r.data.data),

  delete: (id: string) =>
    client.delete(`/admin/roles/${id}`),

  syncPermissions: (id: string, permission_slugs: string[]) =>
    client
      .put<ApiSuccess<Role>>(`/admin/roles/${id}/permissions`, { permission_slugs })
      .then((r) => r.data.data),

  /** Series R/R1 — duplicate a role into a new custom role. */
  clone: (sourceId: string, data: CloneRoleData) =>
    client
      .post<ApiSuccess<Role>>(`/admin/roles/${sourceId}/clone`, data)
      .then((r) => r.data.data),
};
