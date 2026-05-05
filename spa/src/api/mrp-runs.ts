/**
 * Task A1 — MRP run history API client.
 *
 * Endpoints exposed by [`MrpRunController`](api/app/Modules/MRP/Controllers/MrpRunController.php:1).
 */
import { client } from './client';
import type { MrpRun } from '@/types/mrp-runs';

interface PaginatedRuns {
  data: MrpRun[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export const mrpRunsApi = {
  list: (params?: { status?: string; per_page?: number; page?: number }) =>
    client.get<PaginatedRuns>('/mrp/runs', { params }).then((r) => r.data),

  latest: () =>
    client.get<{ data: MrpRun | null }>('/mrp/runs/latest').then((r) => r.data.data),

  trigger: () =>
    client.post<{ data: MrpRun }>('/mrp/runs').then((r) => r.data.data),
};
