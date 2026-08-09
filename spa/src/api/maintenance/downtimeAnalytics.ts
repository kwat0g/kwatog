import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type {
 DowntimeSummary,
 DailyDowntimeTrend,
 TopMachineDowntime,
 MachineDowntimeSummary,
} from '@/types/maintenance';

export const downtimeAnalyticsApi = {
 policy: () => client.get<ApiSuccess<{ default_days: number; minimum_days: number; maximum_days: number; availability_good_pct: number; availability_warning_pct: number; total_warning_minutes: number; mtbf_good_hours: number; mttr_good_minutes: number; breakdown_warning_count: number; breakdown_critical_count: number }>>('/maintenance/downtime-analytics/policy').then(r => r.data.data),
 summary: (params?: { machine_id?: number; days?: number; search?: string }) =>
 client.get<ApiSuccess<DowntimeSummary>>('/maintenance/downtime-analytics/summary', { params }).then(r => r.data.data),

 dailyTrend: (params?: { machine_id?: number; days?: number; search?: string }) =>
 client.get<ApiSuccess<DailyDowntimeTrend[]>>('/maintenance/downtime-analytics/daily-trend', { params }).then(r => r.data.data),

 topMachines: (params?: { days?: number; limit?: number; search?: string }) =>
 client.get<ApiSuccess<TopMachineDowntime[]>>('/maintenance/downtime-analytics/top-machines', { params }).then(r => r.data.data),

 allMachines: (params?: { days?: number; search?: string }) =>
 client.get<ApiSuccess<MachineDowntimeSummary[]>>('/maintenance/downtime-analytics/all-machines', { params }).then(r => r.data.data),

 /** L-39 — Pareto of downtime by category. */
 pareto: (params?: { machine_id?: number; days?: number; search?: string }) =>
 client.get<ApiSuccess<Array<{
 category: string;
 label: string;
 minutes: number;
 count: number;
 percent: number;
 cumulative_percent: number;
 }>>>('/maintenance/downtime-analytics/pareto', { params }).then(r => r.data.data),
};
