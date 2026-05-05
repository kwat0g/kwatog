/**
 * Task A1 — MRP run history types.
 */

export type MrpRunTrigger = 'scheduled' | 'manual';
export type MrpRunStatus  = 'running' | 'completed' | 'failed';

export interface MrpRun {
  id: string;            // hash_id
  run_at: string;
  triggered_by: MrpRunTrigger;
  triggered_by_user: { id: string; name: string } | null;
  sales_orders_evaluated: number;
  shortages_found: number;
  prs_created: number;
  prs_updated: number;
  plans_generated: number;
  duration_ms: number | null;
  status: MrpRunStatus;
  error_message: string | null;
  summary: Record<string, unknown>;
  created_at: string;
}
