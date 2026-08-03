import { client } from '../client';
import type { ActivityFeedParams, ActivityFeedResponse } from '@/types/activity';

/**
 * Series F — Task F7. Admin activity feed API.
 */
export const activityApi = {
  options: () => client.get<{ data: {
    types: Array<{ value: string; label: string }>;
    severities: Array<{ value: string; label: string }>;
  } }>('/admin/activity/options').then((r) => r.data.data),
  list: (params?: ActivityFeedParams) =>
    client
      .get<ActivityFeedResponse>('/admin/activity', { params })
      .then((r) => r.data),
};
