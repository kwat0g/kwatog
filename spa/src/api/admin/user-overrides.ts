import { client } from '../client';
import type {
  CreateUserPermissionOverrideData,
  UserPermissionOverride,
} from '@/types/admin';
import type { ApiSuccess } from '@/types';

/**
 * Series R — Task R2.
 *
 * Per-user permission override endpoints.
 *   GET    /admin/users/{user}/overrides
 *   POST   /admin/users/{user}/overrides
 *   DELETE /admin/users/{user}/overrides/{override}
 *
 * The list endpoint already strips expired overrides server-side; the SPA
 * doesn't need to filter again.
 */
export const userOverridesApi = {
  list: (userId: string) =>
    client
      .get<ApiSuccess<UserPermissionOverride[]>>(`/admin/users/${userId}/overrides`)
      .then((r) => r.data.data),

  create: (userId: string, data: CreateUserPermissionOverrideData) =>
    client
      .post<ApiSuccess<UserPermissionOverride>>(`/admin/users/${userId}/overrides`, data)
      .then((r) => r.data.data),

  delete: (userId: string, overrideId: string) =>
    client.delete(`/admin/users/${userId}/overrides/${overrideId}`),
};
