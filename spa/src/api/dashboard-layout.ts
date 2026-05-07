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

export const dashboardLayoutApi = {
  widgets: () =>
    client
      .get<ApiSuccess<DashboardWidgetMeta[]>>('/dashboard/widgets')
      .then((r) => r.data.data),

  layout: () =>
    client
      .get<ApiSuccess<DashboardLayoutItem[]>>('/dashboard/layout')
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
