/**
 * Series C — Task C5. Chain bottleneck API client.
 *
 * Backed by [`ChainBottleneckController`](api/app/Common/Controllers/ChainBottleneckController.php:1).
 */
import { client } from './client';
import type {
 ChainBottlenecks,
 ChainListenerReplayResult,
 ChainListenerResolutionResult,
 ChainListenerRunsData,
} from '@/types/chain';

export interface ChainListenerRunListParams {
 attention?: boolean;
 page?: number;
 per_page?: number;
 search?: string;
 status?: string;
 outcome?: string;
 resolution?: string;
}

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

 listenerRuns: (params?: ChainListenerRunListParams) =>
 client
 .get<{ data: ChainListenerRunsData }>('/chain/listener-runs', { params })
 .then((r) => r.data.data),

 replayListenerRun: (id: string) =>
 client
 .post<{ data: ChainListenerReplayResult }>('/chain/listener-runs/' + id + '/replay')
 .then((r) => r.data.data),

 resolveListenerRun: (id: string, note: string) =>
 client
 .post<{ data: ChainListenerResolutionResult }>('/chain/listener-runs/' + id + '/resolve', { note })
 .then((r) => r.data.data),
};
