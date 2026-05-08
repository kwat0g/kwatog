import { client } from '../client';
import type {
  DirectoryListParams,
  DirectoryListResponse,
  OrgChartResponse,
} from '@/types/directory';

/**
 * Series F — Task F5. Employee directory API.
 */
export const directoryApi = {
  list: (params?: DirectoryListParams) =>
    client
      .get<DirectoryListResponse>('/hr/directory', {
        params: {
          search: params?.search,
          department_id: params?.department_id,
          page: params?.page,
          per_page: params?.per_page,
        },
      })
      .then((r) => r.data),

  orgChart: () =>
    client.get<OrgChartResponse>('/hr/directory/org-chart').then((r) => r.data.data),
};
