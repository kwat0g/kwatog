import { client } from '../client';

export interface PermissionRow {
  id: string;
  slug: string;
  name: string;
  module: string;
}

export type PermissionMatrix = Record<string, PermissionRow[]>;

export const permissionsApi = {
  matrix: () =>
    client
      .get<{ data: PermissionMatrix }>('/admin/permissions/matrix')
      .then((r) => r.data.data),
};
