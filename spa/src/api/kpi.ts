import { client } from '@/api/client';
import type { KpiScorecardItem, KpiTrendPoint } from '@/types/dashboard/kpi';

export const kpiApi = {
  scorecard: (year: number, month: number) =>
    client.get<{ data: KpiScorecardItem[] }>('/dashboard/kpi/scorecard', { params: { year, month } }),

  trend: (code: string, months?: number) =>
    client.get<{ data: KpiTrendPoint[] }>(`/dashboard/kpi/trend/${code}`, { params: { months } }),

  compute: (year: number, month: number) =>
    client.post<{ message: string }>('/dashboard/kpi/compute', { year, month }),
};
