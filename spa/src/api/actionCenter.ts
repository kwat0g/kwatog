import { client } from './client';
import type { ActionCenterData } from '@/types/actionCenter';

export const actionCenterApi = {
 get: () => client
 .get<{ data: ActionCenterData }>('/dashboards/action-center')
 .then((response) => response.data.data),
 exceptions: () => client
 .get<{ data: ActionCenterData }>('/dashboards/exceptions')
 .then((response) => response.data.data),
 updateTasks: (data: {
 item_ids: string[];
 action: 'claim' | 'unclaim' | 'acknowledge' | 'snooze' | 'resolve' | 'reopen';
 snoozed_until?: string;
 notes?: string;
 }) => client.patch('/dashboards/action-center/tasks', data).then((response) => response.data.data),
};
