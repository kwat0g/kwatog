export type SuccessionReadiness = 'ready_now' | 'ready_1_year' | 'ready_2_years' | 'development_needed';
export type SuccessionPriority = 'critical' | 'high' | 'medium' | 'low';
export type SuccessionStatus = 'active' | 'completed' | 'cancelled';

export interface SuccessionPlan {
 id: string;
 position: { id: string; title: string };
 incumbent: { id: string; first_name: string; last_name: string } | null;
 successor: { id: string; first_name: string; last_name: string };
 readiness: SuccessionReadiness;
 readiness_label?: string;
 priority: SuccessionPriority;
 priority_label?: string;
 status: SuccessionStatus;
 status_label?: string;
 development_notes: string | null;
 target_date: string | null;
 created_at: string;
}

export interface CreateSuccessionPlanData {
 position_id: string;
 incumbent_id?: string;
 successor_id: string;
 readiness: SuccessionReadiness;
 priority: SuccessionPriority;
 development_notes?: string;
 target_date?: string;
}
