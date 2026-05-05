/**
 * Task A2 — Smart Alert Engine types.
 */

export type AlertSeverity = 'critical' | 'warning' | 'info';

export type AlertType =
  | 'stock_critical'
  | 'stock_low'
  | 'no_supplier'
  | 'machine_breakdown'
  | 'mold_shot_limit'
  | 'mold_shot_critical'
  | 'wo_overdue'
  | 'oee_below_threshold'
  | 'ar_overdue_30'
  | 'ar_overdue_60'
  | 'ap_due_soon'
  | 'qc_fail_rate_high';

export interface Alert {
  id: string;            // hash_id
  type: AlertType;
  severity: AlertSeverity;
  title: string;
  message: string;
  entity_type: string | null;
  entity_id: number | null;
  entity?: { id: string; label: string; type: string } | null;
  metadata: Record<string, unknown>;
  is_read: boolean;
  is_dismissed: boolean;
  dismissed_at: string | null;
  created_at: string;
}

export interface AlertListParams {
  severity?: AlertSeverity[];
  type?: AlertType[];
  entity_type?: string;
  is_dismissed?: boolean;
  search?: string;
  page?: number;
  per_page?: number;
}

export interface AlertUnreadCount {
  count: number;
}
