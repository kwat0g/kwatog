import { client } from '@/api/client';
import type { KpiScorecardItem, KpiTrendPoint } from '@/types/dashboard/kpi';

type JsonCollection<T> = T[] | Record<string, T>;

function normalizeCollection<T>(value: JsonCollection<T> | null | undefined): T[] {
 return Array.isArray(value) ? value : Object.values(value ?? {});
}

export const kpiApi = {
 scorecard: (year: number, month: number) =>
 client
 .get<{ data: JsonCollection<KpiScorecardItem> }>('/dashboard/kpi/scorecard', { params: { year, month } })
 .then((response) => normalizeCollection(response.data.data)),

 trend: (code: string, months?: number) =>
 client
 .get<{ data: JsonCollection<KpiTrendPoint> }>(`/dashboard/kpi/trend/${code}`, { params: { months } })
 .then((response) => normalizeCollection(response.data.data)),

 compute: (year: number, month: number) =>
 client.post<{ message: string }>('/dashboard/kpi/compute', { year, month }),
};
