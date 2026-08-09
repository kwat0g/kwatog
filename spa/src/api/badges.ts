import { client } from './client';

/**
 * Polish Task S2 — Sidebar badge count system.
 *
 * Single endpoint backing every numeric badge in the sidebar. The backend
 * self-gates by permission, so the response only contains keys the current
 * user is allowed to see. Each entry also carries a static `label` +
 * `description` (sourced from the backend definitions) so the sidebar can
 * render meaningful tooltips without maintaining a second taxonomy.
 */
export type BadgeSeverity = 'warning' | 'danger' | 'neutral';

export interface BadgePayload {
 count: number;
 severity: BadgeSeverity;
 /** Human label for the nav slot this badge backs. */
 label?: string;
 /** Short explanation of what the count represents. */
 description?: string;
}

/** Map of nav-slot key → badge data. Only populated keys are present. */
export type BadgesMap = Record<string, BadgePayload>;

export const badgesApi = {
 get: () => client.get<{ data: BadgesMap }>('/dashboards/badges').then((r) => r.data.data),
};
