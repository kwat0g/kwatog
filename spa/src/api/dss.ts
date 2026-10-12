/**
 * DSS Wave 0-4 — Decision Center API client.
 * Endpoints exposed by RecommendationController (api/app/Modules/DSS).
 */
import { client } from './client';
import type {
  PaginatedRecommendations,
  Recommendation,
  RecommendationAction,
  RecommendationListParams,
} from '@/types/dss';

export interface DssDetectorState {
  id: string;
  domain: string;
  version: string;
  owner_permissions: string[];
  enabled: boolean;
  mode: 'shadow' | 'advisory' | 'assisted';
  open_count: number;
  last_run: {
    status: string;
    finished_at: string | null;
    duration_ms: number | null;
    emitted: number;
  } | null;
}

export interface DssEngineState {
  enabled: boolean;
  default_mode: 'shadow' | 'advisory' | 'assisted';
}

export const dssApi = {
  list: (params?: RecommendationListParams) =>
    client.get<PaginatedRecommendations>('/dss/recommendations', { params }).then((r) => r.data),

  show: (id: string) =>
    client.get<{ data: Recommendation }>(`/dss/recommendations/${id}`).then((r) => r.data.data),

  act: (
    id: string,
    action: RecommendationAction,
    data?: { reason?: string; snoozed_until?: string },
  ) =>
    client
      .post<{ data: Recommendation }>(`/dss/recommendations/${id}/actions`, { action, ...data })
      .then((r) => r.data.data),

  outcome: (id: string, outcome: Record<string, unknown>) =>
    client
      .post<{ data: Recommendation }>(`/dss/recommendations/${id}/outcome`, { outcome })
      .then((r) => r.data.data),

  detectors: () =>
    client.get<{ data: DssDetectorState[] }>('/dss/admin/detectors').then((r) => r.data.data),

  updateDetector: (id: string, data: { enabled?: boolean; mode?: DssDetectorState['mode'] }) =>
    client
      .patch<{ data: DssDetectorState }>(`/dss/admin/detectors/${id}`, data)
      .then((r) => r.data.data),

  runDetector: (id: string) =>
    client.post<{ data: unknown }>(`/dss/admin/detectors/${id}/run`).then((r) => r.data.data),

  engine: () => client.get<{ data: DssEngineState }>('/dss/admin/engine').then((r) => r.data.data),

  updateEngine: (data: { enabled?: boolean; default_mode?: DssEngineState['default_mode'] }) =>
    client.patch<{ data: DssEngineState }>('/dss/admin/engine', data).then((r) => r.data.data),
};
