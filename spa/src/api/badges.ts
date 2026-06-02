import { client } from './client';

/**
 * Polish Task S2 — Sidebar badge count system.
 *
 * Single endpoint backing every numeric badge in the sidebar. The backend
 * self-gates by permission, so the response only contains keys the current
 * user is allowed to see.
 */
export type BadgeSeverity = 'warning' | 'danger' | 'neutral';

export interface BadgePayload {
  count: number;
  severity: BadgeSeverity;
}

/** Map of nav-slot key → badge data. Only populated keys are present. */
export type BadgesMap = Record<string, BadgePayload>;

export const badgesApi = {
  get: () => client.get<{ data: BadgesMap }>('/dashboards/badges').then((r) => r.data.data),
};
