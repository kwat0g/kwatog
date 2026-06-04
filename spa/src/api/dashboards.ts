import { client } from './client';
import type { ApiSuccess } from '@/types';

export interface DashboardKpi { label: string; value: string; unit: string; }
export interface DashboardEnvelope {
  kpis: DashboardKpi[];
  panels: Record<string, unknown>;
}

export interface AdminDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
    chain_stages: Array<{ key: string; label: string; color: string; count: number; percent: number }>;
    module_activity: Array<{
      key: string;
      label: string;
      href: string;
      stats: Array<{ label: string; value: string }>;
    }>;
    user_activity: {
      recent_logins: Array<{ name: string; status: string; ip: string; created_at: string }>;
      login_trend_7d: number[];
      total_users: number;
      active_today: number;
    };
    pending_approvals: Array<{ type: string; label: string; count: number; href: string }>;
    recent_audit: Array<{ user: string; action: string; entity: string; ip: string; created_at: string }>;
  };
}

export const dashboardsApi = {
  plantManager: () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/plant-manager').then(r => r.data.data),
  hr:           () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/hr').then(r => r.data.data),
  ppc:          () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/ppc').then(r => r.data.data),
  accounting:   () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/accounting').then(r => r.data.data),
  employee:     () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/employee').then(r => r.data.data),
  purchasing:   () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/purchasing').then(r => r.data.data),
  warehouse:    () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/warehouse').then(r => r.data.data),
  quality:      () => client.get<ApiSuccess<DashboardEnvelope>>('/dashboards/quality').then(r => r.data.data),
  admin:        () => client.get<ApiSuccess<AdminDashboardData>>('/dashboards/admin').then(r => r.data.data),
};
