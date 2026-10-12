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
};
