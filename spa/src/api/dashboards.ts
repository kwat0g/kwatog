import { client } from './client';
import type { ApiSuccess } from '@/types';

export interface DashboardKpi { label: string; value: string; unit: string; }
export interface DashboardEnvelope {
  kpis: DashboardKpi[];
  panels: Record<string, unknown>;
}

export interface AdminSession {
  user: string;
  role: string;
  ip: string;
  device: string;
  last_activity: string;
}

export interface AdminLockedAccount {
  name: string;
  email: string;
  role: string;
  attempts: number;
  locked_until: string;
}

export interface AdminFailedLogin {
  email: string;
  status: string;
  reason: string;
  ip: string;
  created_at: string;
}

export interface AdminFailedJob {
  uuid: string;
  queue: string;
  error: string;
  failed_at: string;
}

export interface AdminAlert {
  id: string;
  type: string;
  severity: 'critical' | 'warning' | 'info';
  title: string;
  message: string;
  created_at: string;
}

export interface AdminAuditEvent {
  user: string;
  action: string;
  entity: string;
  ip: string;
  created_at: string;
}

export interface AdminDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
    active_sessions: {
      sessions: AdminSession[];
      total: number;
      unique_users: number;
    };
    account_security: {
      total: number;
      active: number;
      inactive: number;
      locked: number;
      at_risk: number;
      must_change_password: number;
      locked_accounts: AdminLockedAccount[];
    };
    auth_events: {
      breakdown_24h: Record<string, number>;
      success_trend_24h: number[];
      recent_failures: AdminFailedLogin[];
    };
    queue_health: {
      pending_jobs: number;
      failed_jobs: number;
      recent_failed: AdminFailedJob[];
      healthy: boolean;
    };
    recent_audit: AdminAuditEvent[];
    open_alerts: {
      total: number;
      critical: number;
      warning: number;
      items: AdminAlert[];
    };
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
