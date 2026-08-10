import { client } from './client';
import type { ApiSuccess } from '@/types';

/**
 * Series R — Task R4 — dashboard layout endpoints.
 */

export interface DashboardWidgetMeta {
 key: string;
 name: string;
 description: string | null;
 module: string;
 permission: string | null;
 default_w: number;
 default_h: number;
}

export interface DashboardLayoutItem {
 key: string;
 name: string;
 description: string | null;
 module: string;
 permission: string | null;
 x: number;
 y: number;
 w: number;
 h: number;
 /** 'role' = inherited from role default, 'user' = saved by this user. */
 source: 'role' | 'user';
}

export interface SavedLayoutWidget {
 key: string;
 x?: number;
 y?: number;
 w?: number;
 h?: number;
}

export interface DashboardWidgetSummary {
 key: string;
 value: string | null;
 kind: 'number' | 'decimal' | 'currency' | 'percent' | 'hours' | 'date';
 helper: string | null;
 available: boolean;
 updated_at: string;
}

export interface DashboardTarget {
 /** SPA route to land on. Always set — falls back to /dashboard/default. */
 path: string;
 /** null when the fallback was used. */
 key: string | null;
 name: string | null;
 permission: string | null;
}

export interface DashboardDispatch {
 target: DashboardTarget;
 /** Every purpose-built dashboard this user qualifies for, most specific first. */
 candidates: Array<DashboardTarget & { holder_count: number }>;
}

export const dashboardLayoutApi = {
 widgets: () =>
 client
 .get<ApiSuccess<DashboardWidgetMeta[]>>('/dashboard/widgets')
 .then((r) => r.data.data),

 /**
  * Where `/dashboard` should land this user. Resolved server-side from
  * their permissions — the SPA holds no role-to-dashboard mapping.
  */
 dispatch: () =>
 client
 .get<ApiSuccess<DashboardDispatch>>('/dashboard/dispatch')
 .then((r) => r.data.data),

 layout: () =>
 client
 .get<ApiSuccess<DashboardLayoutItem[]>>('/dashboard/layout')
 .then((r) => r.data.data),

 data: (keys: string[]) =>
 client
 .get<ApiSuccess<Record<string, DashboardWidgetSummary>>>('/dashboard/widget-data', {
 params: { keys },
 })
 .then((r) => r.data.data),

 save: (widgets: SavedLayoutWidget[]) =>
 client
 .put<ApiSuccess<DashboardLayoutItem[]>>('/dashboard/layout', { widgets })
 .then((r) => r.data.data),

 reset: () =>
 client
 .post<ApiSuccess<DashboardLayoutItem[]>>('/dashboard/layout/reset')
 .then((r) => r.data.data),
};
