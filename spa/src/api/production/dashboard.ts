import { client } from '../client';
import type { ProductionDashboardPayload } from '@/types/production';

export const productionDashboardApi = {
  payload: () =>
    client.get<{ data: ProductionDashboardPayload }>('/production/dashboard').then((r) => r.data.data),
};
