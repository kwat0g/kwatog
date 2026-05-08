import { client } from '../client';
import type { ActivityFeedParams, ActivityFeedResponse } from '@/types/activity';

/**
 * Series F — Task F7. Admin activity feed API.
 */
export const activityApi = {
  list: (params?: ActivityFeedParams) =>
    client
      .get<ActivityFeedResponse>('/admin/activity', { params })
      .then((r) => r.data),
};
