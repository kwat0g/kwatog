import type { PaginationMeta } from '@/types';

/**
 * DSS Wave 0-4 — Decision Center types.
 * Mirrors RecommendationResource; decimals arrive as strings, id is a HashID.
 */

export type RecommendationSeverity = 'critical' | 'warning' | 'info';

export type RecommendationStatus =
  | 'open'
  | 'in_progress'
  | 'accepted'
  | 'dismissed'
  | 'snoozed'
  | 'expired'
  | 'executed'
  | 'resolved';

export interface Recommendation {
  id: string;
  detector_id: string;
  detector_version: string;
  domain: string;
  type: string;
  severity: RecommendationSeverity;
  status: RecommendationStatus;
  owner_role_slugs?: string[] | null;
  priority_score: string | null;
  impact_estimate: string | null;
  impact_unit: string | null;
  impact_currency: string | null;
  impact_metric: string | null;
  impact_is_estimate: boolean;
  confidence: string | null;
  data_sufficiency: string;
  rationale: Record<string, unknown>;
  action: { route?: string; payload?: Record<string, unknown>; label?: string } | null;
  decision_code: string | null;
  policy_version: string | null;
  source_references: Array<{
    source_kind: string;
    public_id: string;
    display_reference: string;
    destination: string;
  }> | null;
  first_detected_at: string | null;
  last_evaluated_at: string | null;
  source_observed_at: string | null;
  business_due_at: string | null;
  verification_status: string | null;
  resolution_evidence: {
    condition: string;
    verification_method: string;
    proof: string;
    verified_at: string;
    run_reference: string;
  } | null;
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
  owner_role?: string;
  status?: RecommendationStatus;
  search?: string;
  detector_id?: string;
  sort?: 'priority_score' | 'created_at';
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface RecommendationSummary {
  total: number;
  by_severity: Record<RecommendationSeverity, number>;
}

export interface RecommendationOption {
  value: string;
  label: string;
}

export interface RecommendationOptions {
  domains: RecommendationOption[];
  owner_roles: RecommendationOption[];
  statuses: RecommendationOption[];
  severities: RecommendationOption[];
}

export type RecommendationAction = 'accept' | 'dismiss' | 'snooze' | 'execute' | 'start_follow_up';

export interface PaginatedRecommendations {
  data: Recommendation[];
  meta: PaginationMeta;
}
