/**
 * DSS Wave 0-4 — Decision Center types.
 * Mirrors RecommendationResource; decimals arrive as strings, id is a HashID.
 */

export type RecommendationSeverity = 'critical' | 'warning' | 'info';

export type RecommendationStatus =
  | 'open'
  | 'accepted'
  | 'dismissed'
  | 'snoozed'
  | 'expired'
  | 'executed';

export interface Recommendation {
  id: string;
  detector_id: string;
  detector_version: string;
  domain: string;
  type: string;
  severity: RecommendationSeverity;
  status: RecommendationStatus;
  priority_score: string | null;
  impact_estimate: string | null;
  impact_unit: string | null;
  impact_is_estimate: boolean;
  confidence: string | null;
  data_sufficiency: string;
  rationale: Record<string, unknown>;
  action: { route?: string; payload?: Record<string, unknown>; label?: string } | null;
  subject_type: string | null;
  expires_at: string | null;
  snoozed_until: string | null;
  dismiss_reason: string | null;
  decided_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface RecommendationListParams {
  severity?: RecommendationSeverity;
  domain?: string;
  detector_id?: string;
  page?: number;
  per_page?: number;
}

export type RecommendationAction = 'accept' | 'dismiss' | 'snooze' | 'execute';

export interface PaginatedRecommendations {
  data: Recommendation[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}
