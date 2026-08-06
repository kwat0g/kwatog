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
 severity_label?: string;
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
 active_window_minutes?: number;
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
 window_hours?: number;
 recent_failures: AdminFailedLogin[];
 status_options: Array<{ value: string; label: string }>;
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

/**
 * Each endpoint takes an optional type param so a page can name the exact
 * panel shape it renders (`dashboardsApi.warehouse<WarehouseDashboardData>()`)
 * instead of casting the query result with `as unknown as`.
 */
export const dashboardsApi = {
 plantManager: <T = DashboardEnvelope>() => client.get<ApiSuccess<T>>('/dashboards/plant-manager').then(r => r.data.data),
 hr: <T = DashboardEnvelope>() => client.get<ApiSuccess<T>>('/dashboards/hr').then(r => r.data.data),
 ppc: <T = DashboardEnvelope>() => client.get<ApiSuccess<T>>('/dashboards/ppc').then(r => r.data.data),
 accounting: <T = DashboardEnvelope>() => client.get<ApiSuccess<T>>('/dashboards/accounting').then(r => r.data.data),
 employee: <T = DashboardEnvelope>() => client.get<ApiSuccess<T>>('/dashboards/employee').then(r => r.data.data),
 purchasing: <T = DashboardEnvelope>() => client.get<ApiSuccess<T>>('/dashboards/purchasing').then(r => r.data.data),
 warehouse: <T = DashboardEnvelope>() => client.get<ApiSuccess<T>>('/dashboards/warehouse').then(r => r.data.data),
 quality: <T = DashboardEnvelope>() => client.get<ApiSuccess<T>>('/dashboards/quality').then(r => r.data.data),
 admin: <T = AdminDashboardData>() => client.get<ApiSuccess<T>>('/dashboards/admin').then(r => r.data.data),
};
