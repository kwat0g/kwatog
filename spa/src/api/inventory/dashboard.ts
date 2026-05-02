import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { InventoryDashboard } from '@/types/inventory';

export const inventoryDashboardApi = {
  summary: () =>
    client.get<ApiSuccess<InventoryDashboard>>('/inventory/dashboard').then((r) => r.data.data),
};
