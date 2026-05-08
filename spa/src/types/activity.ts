/**
 * Series F — Task F7. Activity feed types.
 */

export type ActivityType = 'transaction' | 'approval' | 'automation' | 'alert' | 'auth';
export type ActivitySeverity = 'info' | 'success' | 'warning' | 'danger';

export interface ActivityActor {
  id: string;
  name: string;
  email: string | null;
}

export interface ActivityEvent {
  id: string;
  type: ActivityType | string;
  action: string;
  actor: ActivityActor | null;
  actor_type: 'user' | 'system';
  subject_type: string | null;
  subject_id: string | null;
  summary: string;
  detail: Record<string, unknown> | null;
  link: string | null;
  severity: ActivitySeverity;
  created_at: string;
}

export interface ActivityFeedParams {
  type?: string;
  severity?: ActivitySeverity;
  actor_user_id?: string;
  from?: string;
  to?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

export interface ActivityFeedResponse {
  data: ActivityEvent[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
