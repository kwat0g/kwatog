import { client } from '../client';
import type { PaginatedResponse, ListParams, ApiSuccess } from '@/types';

export interface Role {
  id: string;
  name: string;
  slug: string;
  description: string | null;
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

export const rolesApi = {
  list: (params?: ListParams) =>
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
};
