import { client } from '../client';

export interface PermissionRow {
  id: string;
  slug: string;
  name: string;
  module: string;
  /** Series R/R1 — present in matrix payload to populate the editor's per-row hint. */
  description?: string | null;
}

export type PermissionMatrix = Record<string, PermissionRow[]>;

export const permissionsApi = {
  matrix: () =>
    client
      .get<{ data: PermissionMatrix }>('/admin/permissions/matrix')
      .then((r) => r.data.data),
};
