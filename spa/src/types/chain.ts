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
