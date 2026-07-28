export type ActionPriority = 'critical' | 'high' | 'medium' | 'low';
export type ActionCategory =
  | 'approval'
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
  status_label: string;
  link: string;
  created_at: string | null;
  due_at: string | null;
  age_hours: number | null;
  is_overdue: boolean;
  owner_label: string | null;
  task_state: 'open' | 'acknowledged' | 'snoozed';
  assigned_to: { id: string; name: string } | null;
  snoozed_until: string | null;
}

export interface ActionCenterData {
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
