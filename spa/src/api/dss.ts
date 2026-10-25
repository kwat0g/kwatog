/**
 * DSS Wave 0-4 — Decision Center API client.
 * Endpoints exposed by RecommendationController (api/app/Modules/DSS).
 */
import { client } from './client';
import type {
  PaginatedRecommendations,
  Recommendation,
  RecommendationAction,
  RecommendationEventPage,
  RecommendationListParams,
  RecommendationOptions,
  RecommendationSummary,
  MasterHygieneAffectedRecordPage,
} from '@/types/dss';

export interface DssDetectorState {
  id: string;
  domain: string;
  version: string;
  owner_permissions: string[];
  enabled: boolean;
  mode: 'shadow' | 'advisory';
  active_count: number;
  /** @deprecated Remove after one compatibility release. */
  open_count: number;
  last_run: {
    status: string;
    started_at: string;
    finished_at: string | null;
    duration_ms: number | null;
    rows_scanned: number;
    emitted: number;
    error_summary: string | null;
    run_reference: string;
    stale: boolean;
  } | null;
}

export interface DssRunResult {
  engine: string;
  detectors: Record<string, {
    status: 'queued' | 'completed' | 'partial' | 'failed' | 'skipped';
    emitted: number;
    updated: number;
    rows_scanned: number;
    complete: boolean;
    error_summary: string | null;
  }>;
  failed: string[];
}

export interface DssEngineState {
  enabled: boolean;
  default_mode: 'shadow';
  legacy_global_advisory_ignored: boolean;
}

export interface DssActivitySummary {
  period_days: 30;
  recommendation_opens: number;
  follow_ups_started: number;
  source_verified_resolutions: number;
  recurring_recommendations: number;
}

export interface DssCoverage {
  engine_enabled: boolean;
  available_detectors: number;
  shadow_detectors: number;
  advisory_detectors: number;
  disabled_detectors: number;
  failed_detectors: number;
  partial_detectors: number;
  checks: Array<{
    id: string;
    domain: string;
    enabled: boolean;
    mode: 'shadow' | 'advisory';
    last_run: { status: string; finished_at: string | null } | null;
  }>;
}

export const dssApi = {
  coverage: () => client.get<{ data: DssCoverage }>('/dss/coverage').then((r) => r.data.data),

  list: (params?: RecommendationListParams) =>
    client.get<PaginatedRecommendations>('/dss/recommendations', { params }).then((r) => r.data),

  summary: (params?: RecommendationListParams) =>
    client
      .get<{ data: RecommendationSummary }>('/dss/recommendations/summary', { params })
      .then((r) => r.data.data),

  options: () =>
    client
      .get<{ data: RecommendationOptions }>('/dss/recommendations/options')
      .then((r) => r.data.data),

  show: (id: string) =>
    client.get<{ data: Recommendation }>(`/dss/recommendations/${id}`).then((r) => r.data.data),

  affectedRecords: (id: string, params: { cursor?: string; search?: string }) =>
    client
      .get<MasterHygieneAffectedRecordPage>(`/dss/recommendations/${id}/affected-records`, { params })
      .then((r) => r.data),

  events: (id: string, cursor?: string) =>
    client
      .get<RecommendationEventPage>(`/dss/recommendations/${id}/events`, { params: cursor ? { cursor } : undefined })
      .then((r) => r.data),

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

  activitySummary: () =>
    client
      .get<{ data: DssActivitySummary }>('/dss/admin/activity-summary')
      .then((r) => r.data.data),

  updateDetector: (id: string, data: { enabled?: boolean; mode?: DssDetectorState['mode'] }) =>
    client
      .patch<{ data: DssDetectorState }>(`/dss/admin/detectors/${id}`, data)
      .then((r) => r.data.data),

  runDetector: (id: string) =>
    client.post<{ data: DssRunResult }>(`/dss/admin/detectors/${id}/run`).then((r) => r.data.data),

  engine: () => client.get<{ data: DssEngineState }>('/dss/admin/engine').then((r) => r.data.data),

  updateEngine: (data: { enabled?: boolean; default_mode?: 'shadow' }) =>
    client.patch<{ data: DssEngineState }>('/dss/admin/engine', data).then((r) => r.data.data),
};
