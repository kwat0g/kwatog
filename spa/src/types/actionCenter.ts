export type ActionPriority = 'critical' | 'high' | 'medium' | 'low';
// Approvals left the Action Center for the Approval Queue (2026-09): the
// queue is operational exceptions only — alert, quality, maintenance,
// production, supply chain.
export type ActionCategory =
  | 'alert'
  | 'quality'
  | 'maintenance'
  | 'production'
  | 'supply_chain';

export interface ActionCenterItem {
 id: string;
 category: ActionCategory;
 kind: string;
 title: string;
 description: string;
 reference: string | null;
 priority: ActionPriority;
 priority_label?: string;
 status_label: string;
 link: string;
  created_at: string | null;
  updated_at?: string | null;
  due_at: string | null;
 age_hours: number | null;
 is_overdue: boolean;
 owner_label: string | null;
  task_state: 'open' | 'acknowledged' | 'snoozed';
  task_state_label?: string;
  assigned_to: { id: string; name: string } | null;
  updated_by: { id: string; name: string } | null;
  snoozed_until: string | null;
}

export interface ActionCenterData {
 category_options: Array<{ value: ActionCategory; label: string }>;
 items: ActionCenterItem[];
 summary: {
 total: number;
 critical: number;
 high: number;
 overdue: number;
 owned_by_me: number;
 unassigned: number;
 by_category: Partial<Record<ActionCategory, number>>;
 };
 generated_at: string;
}
