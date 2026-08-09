import { client } from '@/api/client';

export interface RolloutHealth {
 status: 'healthy' | 'attention';
 status_label?: string;
 quality_plans: {
 eligible_items: number;
 covered_items: number;
    coverage_percent: number | null;
 missing: Array<{ id: string; code: string; name: string; is_critical: boolean }>;
 };
 qc_triggers: { pending_grns_without_inspection: number; failed_inspections_24h: number; grace_minutes: number };
 scanner: {
 scans_24h: number;
 unrecognized_24h: number;
    recognition_rate: number | null;
 top_unrecognized: Array<{ barcode: string; occurrences: number }>;
 };
 actions: { total: number; overdue: number; critical: number; unassigned: number };
 generated_at: string;
}

export const rolloutHealthApi = {
 get: () => client.get<{ data: RolloutHealth }>('/dashboards/rollout-health').then((response) => response.data.data),
};
