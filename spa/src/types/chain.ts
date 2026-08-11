export type ChainStepState = 'done' | 'active' | 'pending';

export interface ChainStep {
 key: string;
 label: string;
 date?: string | null;
 state: ChainStepState;
 href?: string;
 onClick?: (step: ChainStep) => void;
 description?: string;
 is_overdue?: boolean;
 sla_label?: string;
}

export type StageColor = 'success' | 'info' | 'warning' | 'danger' | 'neutral';

export interface StageRow {
 label: string;
 count: number;
 /** 0–100; controls the fill width of the progress bar. */
 percent: number;
 color?: StageColor;
}

export type LinkedDot = 'success' | 'info' | 'warning' | 'danger' | 'neutral';

export interface LinkedItem {
 id: string;
 href?: string;
 meta?: string;
 chip?: { variant: 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'purple'; text: string };
}

export interface LinkedGroup {
 label: string;
 items: LinkedItem[];
}

import type { ReactNode } from 'react';

export interface ActivityItem {
 dot: LinkedDot;
 text: ReactNode;
 time: string;
}

/**
 * Approval workflow step. Drives the <ApprovalTimeline> component on every
 * approvable record (Leave, Loan, PR, PO, …). Shapes from different backends
 * (ApprovalRecord rows, denormalized leave dept/hr fields) are normalized
 * client-side into this shape before render.
 */
export type ApprovalAction = 'pending' | 'approved' | 'rejected' | 'skipped';

export interface ApprovalStep {
 /** 1-based step index. */
 step_order: number;
 /** Human-readable role label (e.g. "Department head"). */
 role: string;
 /** Approver display name once acted, otherwise null. */
 approver_name: string | null;
 action: ApprovalAction;
 /** ISO 8601 timestamp when the step was acted on. */
 acted_at: string | null;
 remarks: string | null;
 /** True if the step is pending and the SLA (24h) has elapsed. */
 is_overdue?: boolean;
 /** Hours since pending was raised — populated when is_overdue=true. */
 overdue_hours?: number | null;
}

/* ──────────────────────────────────────────────────────────────────
 * Series C — Task C4. Real-time chain progress broadcast.
 * Mirrors ChainStepAdvanced::broadcastWith() on the API.
 * ────────────────────────────────────────────────────────────────── */

export type ChainEntityType =
 | 'sales_order'
 | 'work_order'
 | 'purchase_order'
 | 'delivery'
 | 'grn'
 | 'bill'
 | 'invoice';

export interface ChainStepEvent {
 entity_type: ChainEntityType;
 entity_id: string;
 doc_number: string;
 new_status: string;
 active_step: string;
 completed_steps: string[];
 actor_name: string | null;
}

/* ──────────────────────────────────────────────────────────────────
 * Series C — Task C5. Chain bottleneck dashboard widget payload.
 * Mirrors ChainBottleneckController::index() output.
 * ────────────────────────────────────────────────────────────────── */

export interface ChainBottleneckRow {
 key: string;
 label: string;
 audience: string;
 entity_type: string;
 entity_id: string;
 doc_number: string;
 status: string;
 stuck_since: string | null;
 hours_stuck: number | null;
}

export interface ChainBottleneckGroup {
 key: string;
 label: string;
 audience: string | null;
 count: number;
 rows: ChainBottleneckRow[];
}

export interface ChainBottlenecks {
 total: number;
 groups: ChainBottleneckGroup[];
 automation?: ChainAutomationSummary;
}

export type ChainAutomationStatus = 'healthy' | 'attention' | 'unavailable';

export interface ChainAutomationSource {
 available: boolean;
 total: number;
 processing: number;
 stale_processing: number;
 failed: number;
 oldest_failure_at: string | null;
}

export interface ChainOutboxAutomationSource extends ChainAutomationSource {
 pending: number;
 stale_pending: number;
 published: number;
 oldest_pending_at: string | null;
}

export interface ChainListenerAutomationSource extends ChainAutomationSource {
 retrying: number;
 completed: number;
 oldest_active_at: string | null;
 outcomes?: ChainListenerOutcomeSummary;
}

export interface ChainListenerOutcomeSummary {
 available: boolean;
 total: number;
 completed: number;
 skipped: number;
 manual_required: number;
 failed: number;
 unclassified: number;
}

export interface ChainAutomationSummary {
 status: ChainAutomationStatus;
 outbox: ChainOutboxAutomationSource;
 listeners: ChainListenerAutomationSource;
 supplier_dispatch?: ChainSupplierDispatchAutomationSource;
 failed_jobs: ChainFailedJobAutomationSource;
}

export interface ChainFailedJobAutomationSource {
 available: boolean;
 total: number;
 oldest_at: string | null;
}

export interface ChainSupplierDispatchAutomationSource {
 available: boolean;
 total: number;
 pending: number;
 portal_available: number;
 manual_required: number;
 confirmed: number;
 failed: number;
 cancelled: number;
 stale_pending: number;
  oldest_attention_at: string | null;
}

/* ──────────────────────────────────────────────────────────────────
 * Listener-level recovery surface.
 * ────────────────────────────────────────────────────────────────── */

export type ChainListenerQueueStatus = 'processing' | 'retrying' | 'completed' | 'failed';
export type ChainListenerOutcomeStatus = 'completed' | 'skipped' | 'manual_required' | 'failed' | 'unclassified';
export type ChainListenerResolutionStatus = 'open' | 'resolved' | 'not_required';

export interface ChainListenerRun {
  id: string;
  event_type: string;
  listener_class: string;
  listener_method: string;
  queue: {
    status: ChainListenerQueueStatus;
    attempts: number;
    started_at: string | null;
    last_attempt_at: string | null;
    completed_at: string | null;
    failed_at: string | null;
    last_error: string | null;
  };
  outcome: {
    status: ChainListenerOutcomeStatus;
    code: string | null;
    message: string | null;
    at: string | null;
  };
  resolution: {
    status: ChainListenerResolutionStatus;
    note: string | null;
    resolved_at: string | null;
    resolved_by: string | null;
  };
  correlation: {
    outbox_id: string;
    job_uuid: string;
    replayed_from_id: string | null;
  };
  replay: {
    count: number;
    requested_at: string | null;
    requested_by: string | null;
  };
  outbox: {
    status: string;
    attempts: number;
    available_at: string | null;
    locked_at: string | null;
    published_at: string | null;
    last_error: string | null;
  } | null;
  chain_step: {
    chain: string;
    entity_type: string;
    entity_hash_id: string | null;
    step: string;
    event_key: string;
    status: string;
  } | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ChainListenerRunsData {
  items: ChainListenerRun[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
  generated_at: string;
}

export interface ChainListenerReplayResult {
  status: 'queued';
  source_run_id: string;
  outbox_id: string;
  event_type: string;
  listener_class: string;
  listener_method: string;
  replay_count: number;
}

export interface ChainListenerResolutionResult {
  run_id: string;
  resolution_status: 'resolved';
  resolution_note: string;
  resolved_at: string | null;
  resolved_by: string | null;
  idempotent: boolean;
}
