/**
 * Series C — Task C5. Chain bottleneck API client.
 *
 * Backed by [`ChainBottleneckController`](api/app/Common/Controllers/ChainBottleneckController.php:1).
 */
import { client } from './client';
import type { ChainBottlenecks } from '@/types/chain';

export const chainApi = {
  /**
   * Returns total stuck count + per-step rows. Pass `audience` to filter
   * to a single role's bottlenecks (default: every group the user can see).
   */
  bottlenecks: (audience?: string) =>
    client
      .get<{ data: ChainBottlenecks }>('/chain/bottlenecks', {
        params: audience ? { audience } : undefined,
      })
      .then((r) => r.data.data),
};
