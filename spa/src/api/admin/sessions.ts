import { client } from '../client';

export interface ActiveSession {
  id: string;
  user_id: number;
  ip_address: string | null;
  user_agent: string | null;
  last_activity: number;
  last_activity_at: string;
  user_name: string | null;
  user_email: string | null;
  is_current: boolean;
}

export const sessionsApi = {
  list: () =>
    client.get<{ data: ActiveSession[] }>('/admin/sessions').then((r) => r.data.data),

  terminate: (id: string) => client.delete(`/admin/sessions/${id}`),
};
