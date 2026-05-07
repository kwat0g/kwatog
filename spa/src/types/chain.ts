export type ChainStepState = 'done' | 'active' | 'pending';

export interface ChainStep {
  key: string;
  label: string;
  date?: string | null;
  state: ChainStepState;
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
  | 'grn';

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
}
