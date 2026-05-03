import { client } from './client';
import type { ApiSuccess } from '@/types';

export interface DashboardKpi { label: string; value: string; unit: string; }
export interface DashboardEnvelope {
  kpis: DashboardKpi[];
  panels: Record<string, any>;
}

export const dashboardsApi = {
  plantManager: () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/plant-manager').then(r => r.data.data),
  hr:           () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/hr').then(r => r.data.data),
  ppc:          () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/ppc').then(r => r.data.data),
  accounting:   () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/accounting').then(r => r.data.data),
  employee:     () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/employee').then(r => r.data.data),
};
