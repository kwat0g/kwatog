import { client } from '../client';
import type {
  AdminUserListFilters,
  AdminUserListResponse,
  AdminUserDetail,
  CreateAdminUserData,
  CreateAdminUserResponse,
  LoginEvent,
} from '@/types/admin';
import type { ApiSuccess } from '@/types';

/** U2 — Admin user management. */
export const adminUsersApi = {
  list: (params?: AdminUserListFilters) =>
    client.get<AdminUserListResponse>('/admin/users', { params }).then((r) => r.data),

  show: (id: string) =>
    client.get<ApiSuccess<AdminUserDetail>>(`/admin/users/${id}`).then((r) => {
      // Resource returns the detail directly under `data` (Laravel JsonResource wrap).
      const body = r.data as { data?: AdminUserDetail } & AdminUserDetail;
      return body.data ?? (r.data as unknown as AdminUserDetail);
    }),

  create: (data: CreateAdminUserData) =>
    client.post<CreateAdminUserResponse>('/admin/users', data).then((r) => r.data),

  unlock: (id: string) =>
    client.patch<{ message: string }>(`/admin/users/${id}/unlock`).then((r) => r.data),

  deactivate: (id: string) =>
    client.patch<{ message: string }>(`/admin/users/${id}/deactivate`).then((r) => r.data),

  activate: (id: string) =>
    client.patch<{ message: string }>(`/admin/users/${id}/activate`).then((r) => r.data),

  changeRole: (id: string, roleId: string) =>
    client
      .patch<ApiSuccess<AdminUserDetail>>(`/admin/users/${id}/role`, { role_id: roleId })
      .then((r) => {
        const body = r.data as { data?: AdminUserDetail } & AdminUserDetail;
        return body.data ?? (r.data as unknown as AdminUserDetail);
      }),

  resetPassword: (id: string) =>
    client
      .patch<{ message: string; sent_to: string | null }>(`/admin/users/${id}/reset-password`)
      .then((r) => r.data),

  loginHistory: (id: string) =>
    client
      .get<{ data: LoginEvent[] }>(`/admin/users/${id}/login-history`)
      .then((r) => r.data.data),

  bulkChangeRole: (userIds: string[], roleId: string, reason: string) =>
    client
      .patch<{ updated: number; invalid_ids: string[] }>(`/admin/users/bulk-role`, {
        user_ids: userIds,
        role_id: roleId,
        reason,
      })
      .then((r) => r.data),
};
