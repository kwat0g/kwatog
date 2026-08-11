import { client } from './client';
import type { ApiSuccess } from '@/types';

/**
 * Series R — Task R4 — dashboard layout endpoints.
 */

/** How a widget draws itself. Mirrors the backend RenderKind enum. */
export type WidgetRenderKind = 'scalar' | 'breakdown' | 'trend' | 'table' | 'gauge';

export type WidgetSegmentTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

export interface WidgetBreakdownData {
 total: number;
 segments: Array<{ label: string; value: number; tone: WidgetSegmentTone }>;
}

export interface WidgetTrendData {
 points: Array<{ label: string; value: number }>;
 delta: number | null;
 kind: DashboardWidgetSummary['kind'];
}

export interface WidgetTableData {
 columns: Array<{ key: string; label: string; numeric?: boolean }>;
 rows: Array<Record<string, string | number | null>>;
 total_count: number;
}

export interface WidgetGaugeData {
 value: number;
 target: number | null;
 min: number;
 max: number;
 kind: DashboardWidgetSummary['kind'];
}

/**
 * Discriminated by the sibling `render_kind`, not by a field on the payload —
 * the backend keys the shape off the widget row, so a widget can change how it
 * draws without its data contract moving.
 */
export type WidgetData =
 | WidgetBreakdownData
 | WidgetTrendData
 | WidgetTableData
 | WidgetGaugeData;

export interface DashboardWidgetMeta {
 key: string;
 name: string;
 description: string | null;
 module: string;
 permission: string | null;
 render_kind: WidgetRenderKind;
 default_w: number;
 default_h: number;
}

export interface DashboardLayoutItem {
 key: string;
 name: string;
 description: string | null;
 module: string;
 permission: string | null;
 render_kind: WidgetRenderKind;
 /** Only populated when the layout was fetched with `{ rich: true }`. */
 data: WidgetData | null;
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

 /**
  * `rich: true` asks the server to attach each widget's rich payload
  * (breakdown / trend / table / gauge). Without it every `data` is null and
  * tiles render from the scalar `widget-data` endpoint as before.
  */
 layout: (opts?: { rich?: boolean }) =>
 client
 .get<ApiSuccess<DashboardLayoutItem[]>>('/dashboard/layout', {
 params: opts?.rich ? { rich: 1 } : undefined,
 })
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
